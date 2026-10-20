<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Events\RegionDomainsConfigured;
use App\Jobs\MigrateRegionJob;
use App\Models\BindingRegionService;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Models\Project;
use App\Models\RegionSupplyChainSetting;
use App\Models\Version;
use App\Services\Bindings\DataServiceManager;
use App\Services\RegionMigrateService;
use App\Services\SupplyChain\AdmissionPolicyService;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\Concerns\FakesRegionMigrationClusters;
use Tests\TestCase;

/**
 * Rolling a region migration back gives the region its old cluster again, with what its columns
 * held for that cluster before the move — the registry address of the old cluster's Zot, which
 * every redeploy and build of the region's projects uses, and the rest of
 * RegionMigrateService::CLUSTER_STATE — and the statuses of its data services, admission policy
 * and tracing backend. A move that failed and is started again keeps what the first attempt
 * saved. Every project that ran before the move still has its Knative Service on the old
 * cluster. A project the move did not move (its
 * redeploy refused, its deployments shut down) must not serve there unmetered: the rollback
 * deploys it again like every other project (metered from then on) or, when it cannot, removes it
 * from the restored cluster; so does a project stopped while the region was on the new cluster.
 * A version built on the new cluster has its image in the new cluster's registry only: the
 * rollback deploys what ran before the move in its place.
 */
class RegionMigrateRollbackTest extends TestCase
{
    use FakesRegionMigrationClusters, RefreshDatabase;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class]);
        $this->fakeClusters();

        $this->region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true,
            'kubeconfig' => $this->kubeconfig(self::OLD),
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
        ]);
    }

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_project_the_move_did_not_move_is_deployed_again_and_metered_from_the_rollback(): void
    {
        [$project, $before] = $this->runningProject('fixed');
        $this->refuseDeploys();
        $this->move();

        $before->refresh();
        $this->assertSame('shut_down', $before->status);
        $this->assertStringStartsWith(RegionMigrateService::NOT_MOVED, (string) $before->knative_message);
        $this->assertSame('2026-10-19 10:00:00', $before->ended_at?->toDateTimeString());

        // The allocation was approved meanwhile; the move is rolled back an hour later
        ProjectAdmission::reset();
        Carbon::setTestNow('2026-10-19 11:00:00');
        $this->rollback();

        $restored = $project->deployments()->whereKeyNot($before->id)->sole();
        $this->assertSame('active', $restored->status, 'it serves again as a live deployment');
        $this->assertSame($before->version_id, $restored->version_id);
        $this->assertSame(['min_scale' => 2], $restored->deploy_config, 'with the overrides it ran with');
        $this->assertSame('2026-10-19 11:00:00', $restored->ready_at?->toDateTimeString(), 'metered from the rollback');
        $this->assertSame('2026-10-19 10:00:00', $before->fresh()->ended_at?->toDateTimeString(), 'the move window stays unmetered');
        $this->assertContains('PATCH old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/fixed', $this->calls, 'applied on the restored cluster');
    }

    public function test_a_project_still_refused_is_removed_from_the_restored_cluster(): void
    {
        [$project, $before] = $this->runningProject('fixed');
        $this->refuseDeploys();
        $this->move();

        $this->rollback();

        $this->assertSame(1, $project->deployments()->count(), 'nothing is deployed');
        $this->assertSame('shut_down', $before->fresh()->status);
        $this->assertContains('DELETE old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/fixed', $this->calls);
        $this->assertNotContains('fixed', $this->services['old.cluster.test'], 'it no longer serves unmetered');
    }

    public function test_a_project_refused_by_the_rollback_itself_is_removed_from_the_restored_cluster(): void
    {
        [$project] = $this->runningProject('orders');
        $this->move();
        $moved = $project->deployments()->where('status', 'active')->sole();
        $this->assertContains('orders', $this->services['new.cluster.test']);

        $this->refuseDeploys();
        $this->rollback();

        $moved->refresh();
        $this->assertSame('shut_down', $moved->status);
        $this->assertStringStartsWith(RegionMigrateService::NOT_RESTORED, (string) $moved->knative_message);
        $this->assertSame(0, $project->deployments()->whereIn('status', Deployment::LIVE)->count());
        $this->assertNotContains('orders', $this->services['old.cluster.test'], 'its Knative Service from before the move is gone');
    }

    public function test_a_project_stopped_on_the_new_cluster_is_removed_from_the_restored_cluster(): void
    {
        [$project] = $this->runningProject('orders');
        $this->move();

        // Stopped while the region ran on the new cluster: its deployment ended there
        $project->deployments()->where('status', 'active')->sole()->transition(Deployment::LIVE, ['status' => 'shut_down']);
        $project->forceFill(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill'])->saveQuietly();

        $this->rollback();

        $this->assertSame(0, $project->deployments()->whereIn('status', Deployment::LIVE)->count());
        $this->assertContains('DELETE old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/orders', $this->calls);
    }

    public function test_the_other_projects_are_deployed_again_and_one_that_runs_nothing_there_is_left_alone(): void
    {
        [$moved] = $this->runningProject('orders');
        // Not moved by an earlier, completed migration: nothing of it runs on this region's old cluster
        [$earlier] = $this->runningProject('archive');
        $earlier->deployments()->update(['status' => 'shut_down', 'knative_message' => RegionMigrateService::NOT_MOVED.'Over the allocation.']);
        $this->services['old.cluster.test'] = ['orders'];

        $this->move();
        $this->rollback();

        $this->assertSame(1, $moved->deployments()->where('status', 'active')->count());
        $this->assertSame(0, $earlier->deployments()->whereIn('status', Deployment::LIVE)->count(), 'not deployed by this rollback');
        $this->assertSame([], array_values(array_filter($this->calls, fn ($c) => str_contains($c, '/services/archive') && ! str_starts_with($c, 'GET '))));
    }

    public function test_the_rollback_points_the_region_at_the_old_clusters_registry_again(): void
    {
        $this->region->update([
            'registry_mode' => 'internal', 'registry_status' => 'ready',
            'registry_address' => '10.96.1.1:5000', 'registry_namespace' => 'runr-registry',
        ]);

        $this->migrateTo('10.96.2.2:5000');
        $moved = $this->region->fresh();
        $this->assertSame('10.96.2.2:5000', $moved->registry_address, 'the move points the region at the new cluster\'s Zot');
        $this->assertSame('10.96.1.1:5000', $moved->migration_old_state['registry_address'] ?? null, 'and keeps the old one');

        $this->rollback();

        $region = $this->region->fresh();
        $this->assertSame('10.96.1.1:5000', $region->registry_address);
        $this->assertSame('10.96.1.1:5000/runr/fn:1', $region->registryImageRef('runr/fn:1'), 'redeploys and builds pull from the old cluster\'s registry');
        $this->assertNull($region->migration_old_state);
    }

    public function test_the_rollback_restores_every_column_the_move_set_from_the_new_cluster(): void
    {
        $before = [
            'registry_address' => '10.96.1.1:5000',
            'selected_workers' => ['w1', 'w2'], 'total_cpu_m' => 8000, 'total_memory_mb' => 16384,
            'knative_operator_status' => 'ready', 'knative_operator_status_message' => 'old operator log',
            'knative_operator_namespace' => 'knative-operator',
            'knative_serving_status' => 'ready', 'knative_serving_status_message' => 'old serving log',
            'knative_serving_namespace' => 'knative-serving', 'knative_serving_ingress_class' => 'traefik',
            'knative_serving_version' => '1.18.0',
            'prometheus_url' => 'http://prometheus-operated.observability:9090',
            'loki_url' => 'http://loki-stack.observability.svc.cluster.local:3100', 'loki_status' => 'ready',
            'agent_token_hash' => 'old-agent-token-hash',
            'knative_redis_status' => 'ready', 'knative_redis_status_message' => 'Redis configured successfully.',
            'sandbox_kata_status' => 'ready', 'sandbox_kata_status_message' => 'old kata log', 'sandbox_kata_hypervisor' => ['qemu', 'fc'],
            'sandbox_kata_devmapper_pool_gb' => 64,
            'sandbox_gvisor_status' => 'ready', 'sandbox_gvisor_status_message' => 'old gvisor log',
            'sandbox_wasm_status' => 'ready', 'sandbox_wasm_status_message' => 'old wasm log',
            'sandbox_wasm_shims' => ['spin'], 'sandbox_wasm_nodes' => ['w1', 'w2'],
            'gpu_mode' => 'device-plugin', 'gpu_status' => 'ready', 'gpu_status_message' => 'old gpu log',
            'gpu_settings' => ['time_slicing_replicas' => 4, 'gpu_nodes' => ['w1']],
            'gpu_available' => ['nvidia.com/gpu' => 2], 'gpu_allocated' => ['nvidia.com/gpu' => 1],
            'function_tls_mode' => 'custom', 'function_tls_secret_name' => 'runr-function-tls',
            'function_tls_expires_at' => '2027-01-31 12:00:00',
        ];
        // What the installs and checks on the new cluster leave: its Zot, none of the selected nodes,
        // a Serving installed at another version, a new agent token, the default Kata hypervisor, …
        $after = [
            'registry_address' => '10.96.2.2:5000',
            'selected_workers' => [], 'total_cpu_m' => 4000, 'total_memory_mb' => 8192,
            'knative_operator_status' => 'error', 'knative_operator_status_message' => 'new operator log',
            'knative_operator_namespace' => 'knative-operator-new',
            'knative_serving_status' => 'installing', 'knative_serving_status_message' => 'new serving log',
            'knative_serving_namespace' => 'knative-serving-new', 'knative_serving_ingress_class' => 'traefik-new',
            'knative_serving_version' => '1.19.0',
            'prometheus_url' => 'http://prometheus-operated.new:9090',
            'loki_url' => 'http://loki-stack.new.svc.cluster.local:3100', 'loki_status' => 'installing',
            'agent_token_hash' => 'new-agent-token-hash',
            'knative_redis_status' => 'error', 'knative_redis_status_message' => 'timed out',
            'sandbox_kata_status' => 'error', 'sandbox_kata_status_message' => 'new kata log', 'sandbox_kata_hypervisor' => ['clh'],
            'sandbox_kata_devmapper_pool_gb' => null,
            'sandbox_gvisor_status' => 'error', 'sandbox_gvisor_status_message' => 'new gvisor log',
            'sandbox_wasm_status' => 'error', 'sandbox_wasm_status_message' => 'new wasm log',
            'sandbox_wasm_shims' => ['wasmtime'], 'sandbox_wasm_nodes' => [],
            'gpu_mode' => 'operator', 'gpu_status' => 'error', 'gpu_status_message' => 'new gpu log',
            'gpu_settings' => ['time_slicing_replicas' => 4, 'gpu_nodes' => []],
            'gpu_available' => [], 'gpu_allocated' => [],
            'function_tls_mode' => 'console', 'function_tls_secret_name' => 'runr-function-tls-new',
            'function_tls_expires_at' => '2027-06-30 12:00:00',
        ];
        $this->assertEqualsCanonicalizing(RegionMigrateService::CLUSTER_STATE, array_keys($before), 'a value before the move for every column the rollback restores');
        $this->assertEqualsCanonicalizing(RegionMigrateService::CLUSTER_STATE, array_keys($after));

        $this->region->forceFill($before + ['external_ip' => '198.51.100.1'])->save();

        $this->migrateTo('10.96.2.2:5000');
        $this->region->fresh()->forceFill($after + ['external_ip' => '203.0.113.9'])->save();

        $this->rollback();

        $region = $this->region->fresh();
        foreach ($before as $column => $value) {
            $restored = $region->getAttribute($column);
            $this->assertSame($value, $restored instanceof Carbon ? $restored->toDateTimeString() : $restored, "{$column} as before the move");
        }
        $this->assertSame('198.51.100.1', $region->external_ip);
    }

    public function test_a_version_built_on_the_new_cluster_is_not_deployed_again_and_what_ran_before_takes_its_place(): void
    {
        $this->withRegistry();
        [$project, $before] = $this->builtProject('orders');
        $this->migrateTo('10.96.2.2:5000');
        // The move built v1 again into the new cluster's Zot and deployed it there
        $this->builtOnTheNewCluster(Version::query()->findOrFail($before->version_id), '10.96.2.2:5000/runr-builds/orders:v1');

        // Built into the new cluster's Zot and deployed there, with three pods
        Carbon::setTestNow('2026-10-19 10:30:00');
        $v2 = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready',
            'build_image' => '10.96.2.2:5000/runr-builds/orders:v2']);
        $project->deployments()->whereIn('status', Deployment::LIVE)->update(['status' => 'shut_down']);
        $window = $project->deployments()->create(['version_id' => $v2->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => 'run-orders-v2', 'deployed_by' => 'dev', 'deploy_config' => ['min_scale' => 3]]);

        Carbon::setTestNow('2026-10-19 11:00:00');
        $this->rollback();

        $window->refresh();
        $this->assertSame('shut_down', $window->status);
        $this->assertSame(RegionMigrateService::NOT_RESTORED.'v2 was built into the registry of the cluster the rollback left; v1 runs instead', $window->knative_message);
        $restored = $project->deployments()->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame('active', $restored->status);
        $this->assertSame($before->version_id, $restored->version_id, 'the version that ran before the move');
        $this->assertSame(['min_scale' => 2], $restored->deploy_config, 'with the overrides it ran with');
        $this->assertSame('region-migration', $restored->deployed_by);
        $this->assertSame('2026-10-19 11:00:00', $restored->ready_at?->toDateTimeString(), 'metered from the rollback');
        $this->assertSame('10.96.1.1:5000/runr-builds/orders:v1', $this->appliedImage('old.cluster.test', 'orders'), 'pulled from the restored registry');
    }

    public function test_a_project_with_no_version_in_the_restored_registry_is_removed_from_the_restored_cluster(): void
    {
        $this->withRegistry();
        $this->migrateTo('10.96.2.2:5000');

        // Created while the region was on the new cluster: every version of it was built there
        [$project, $window] = $this->builtProject('fresh', '10.96.2.2:5000');
        $this->services['old.cluster.test'] = [];

        $this->rollback();

        $window->refresh();
        $this->assertSame('shut_down', $window->status);
        $this->assertStringStartsWith(RegionMigrateService::NOT_RESTORED.'v1 was built into the registry of the cluster the rollback left, and no earlier version', (string) $window->knative_message);
        $this->assertSame(1, $project->deployments()->count(), 'nothing is deployed');
        $this->assertContains('DELETE old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/fresh', $this->calls);
    }

    public function test_a_failed_move_started_again_keeps_what_the_region_held_for_the_old_cluster(): void
    {
        $this->withRegistry();
        $this->region->update(['external_ip' => '198.51.100.1']);

        // The first attempt fails once the region runs on the new cluster and its Zot
        $fail = true;
        Event::listen(RegionDomainsConfigured::class, function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('The new cluster stopped answering.');
            }
        });
        try {
            $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');
            $this->fail('the first attempt fails');
        } catch (\RuntimeException $e) {
            (new MigrateRegionJob($this->region->fresh(), $this->kubeconfig(self::NEW)))->failed($e);
        }
        $failed = $this->region->fresh();
        $this->assertSame('failed', $failed->migration_status);
        $this->assertStringContainsString('new.cluster.test', (string) $failed->kubeconfig, 'half moved');
        $this->assertSame('10.96.2.2:5000', $failed->registry_address);

        $fail = false;
        $this->migrateTo('10.96.2.2:5000');

        $moved = $this->region->fresh();
        $this->assertStringContainsString('old.cluster.test', (string) $moved->migration_old_kubeconfig, 'the cluster before the first attempt');
        $this->assertSame('198.51.100.1', $moved->migration_old_ip);
        $this->assertSame('10.96.1.1:5000', $moved->migration_old_state['registry_address'] ?? null);

        $this->rollback();

        $this->assertSame('10.96.1.1:5000', $this->region->fresh()->registry_address);
        $this->assertSame('198.51.100.1', $this->region->fresh()->external_ip);
    }

    public function test_the_rollback_gives_back_the_statuses_of_the_data_services_the_admission_policy_and_tracing(): void
    {
        $kv = BindingRegionService::create(['knative_region_id' => $this->region->id, 'service' => 'kv', 'mode' => 'managed',
            'status' => 'ready', 'status_message' => 'KV ready.']);
        RegionSupplyChainSetting::firstOrCreateFor($this->region)->forceFill(['admission_status' => 'ready', 'admission_message' => 'Policy applied.'])->save();
        $tracing = RegionTracingConfig::create(['region_id' => $this->region->id, 'mode' => 'managed', 'status' => 'ready',
            'installed_at' => '2026-10-01 08:00:00']);

        // On the new cluster the KV store and the admission policy fail to install
        $data = Mockery::mock(DataServiceManager::class);
        $data->shouldReceive('install')->once()->andReturnUsing(fn (KnativeRegion $region, string $service) => BindingRegionService::forRegion($region, $service)
            ?->update(['status' => 'error', 'status_message' => 'No StorageClass.']));
        $this->app->instance(DataServiceManager::class, $data);
        $admission = Mockery::mock(AdmissionPolicyService::class);
        $admission->shouldReceive('install')->once()->andReturnUsing(fn (KnativeRegion $region) => RegionSupplyChainSetting::forRegion($region)
            ->forceFill(['admission_status' => 'error', 'admission_message' => 'Kyverno does not answer.'])->save());
        $this->app->instance(AdmissionPolicyService::class, $admission);

        $this->migrateTo('10.96.2.2:5000');
        // A Serving install there brought its own tracing backend
        $tracing->update(['status' => 'installing', 'status_message' => 'new cluster', 'installed_at' => '2026-10-19 10:00:00']);
        $this->assertSame('error', $kv->fresh()->status);

        $this->rollback();

        $kv->refresh();
        $this->assertSame(['ready', 'KV ready.'], [$kv->status, $kv->status_message]);
        $settings = RegionSupplyChainSetting::forRegion($this->region);
        $this->assertSame(['ready', 'Policy applied.'], [$settings->admission_status, $settings->admission_message]);
        $tracing->refresh();
        $this->assertSame(['ready', null, '2026-10-01 08:00:00'], [$tracing->status, $tracing->status_message, $tracing->installed_at?->toDateTimeString()]);
    }

    public function test_what_was_installed_on_the_new_cluster_only_is_not_installed_after_the_rollback(): void
    {
        $this->migrateTo('10.96.2.2:5000');
        // Installed while the region was on the new cluster: a data service and the tracing backend
        $postgres = BindingRegionService::create(['knative_region_id' => $this->region->id, 'service' => 'postgres', 'mode' => 'managed', 'status' => 'ready']);
        $tracing = RegionTracingConfig::create(['region_id' => $this->region->id, 'mode' => 'managed', 'status' => 'ready', 'installed_at' => now()]);

        $this->rollback();

        $this->assertSame(BindingRegionService::STATUS_NOT_INSTALLED, $postgres->fresh()->status);
        $tracing->refresh();
        $this->assertNull($tracing->status);
        $this->assertNull($tracing->installed_at);
        $this->assertSame('managed', $tracing->mode, 'its settings stay');
    }

    public function test_completing_the_move_forgets_the_old_clusters_state(): void
    {
        $this->region->update(['registry_mode' => 'internal', 'registry_address' => '10.96.1.1:5000', 'registry_namespace' => 'runr-registry']);
        $this->migrateTo('10.96.2.2:5000');

        app(RegionMigrateService::class)->completeMigration($this->region->fresh());

        $region = $this->region->fresh();
        $this->assertSame('completed', $region->migration_status);
        $this->assertSame('10.96.2.2:5000', $region->registry_address);
        $this->assertNull($region->migration_old_state);
        $this->assertNull($region->migration_old_kubeconfig);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** The whole move to the new cluster, whose Zot serves at $newRegistry (migrateRegion). */
    private function migrateTo(string $newRegistry): void
    {
        $this->migrateRegion($this->region->fresh(), $newRegistry);
        $this->assertSame('dns_check', $this->region->fresh()->migration_status);
    }

    /**
     * A project running since 09:00 on the old cluster (two pods) — its Knative Service is there.
     *
     * @return array{Project, Deployment}
     */
    private function runningProject(string $name): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 2]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);

        Carbon::setTestNow('2026-10-19 09:00:00');
        $deployment = $project->deployments()->create([
            'version_id' => $version->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => "run-{$project->slug}-v1", 'deployed_by' => 'dev', 'deploy_config' => ['min_scale' => 2],
        ]);
        $this->services['old.cluster.test'][] = $project->slug;

        return [$project->fresh(), $deployment];
    }

    /** The region's internal registry: the old cluster's Zot. */
    private function withRegistry(): void
    {
        $this->region->update([
            'registry_mode' => 'internal', 'registry_status' => 'ready',
            'registry_address' => '10.96.1.1:5000', 'registry_namespace' => 'runr-registry',
        ]);
    }

    /**
     * A FaaS project running since 09:00 (two pods) whose v1 was built into the registry at
     * $registry — its Knative Service is on the old cluster.
     *
     * @return array{Project, Deployment}
     */
    private function builtProject(string $name, string $registry = '10.96.1.1:5000'): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev',
            'build_status' => 'ready', 'build_image' => "{$registry}/runr-builds/{$project->slug}:v1"]);

        Carbon::setTestNow('2026-10-19 09:00:00');
        $deployment = $project->deployments()->create([
            'version_id' => $version->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => "run-{$project->slug}-v1", 'deployed_by' => 'dev', 'deploy_config' => ['min_scale' => 2],
        ]);
        $this->services['old.cluster.test'][] = $project->slug;

        return [$project->fresh(), $deployment];
    }

    /** The move at 10:00: the region on the new cluster, its projects deployed there (RegionMigrateService::migrate, step 6). */
    private function move(): void
    {
        Carbon::setTestNow('2026-10-19 10:00:00');
        $this->region->update([
            'migration_old_kubeconfig' => $this->kubeconfig(self::OLD),
            'kubeconfig' => $this->kubeconfig(self::NEW),
            'migration_status' => 'dns_check',
            'migration_started_at' => now(),
        ]);

        app(RegionMigrateService::class)->redeployProjects($this->region->fresh());
    }

    private function rollback(): void
    {
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh());

        $region = $this->region->fresh();
        $this->assertNull($region->migration_status);
        $this->assertStringContainsString('old.cluster.test', (string) $region->kubeconfig);
    }

    private function refuseDeploys(): void
    {
        ProjectAdmission::guardDeploys(fn () => throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422)));
    }
}
