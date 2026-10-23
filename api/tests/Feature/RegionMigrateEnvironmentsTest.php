<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Notification;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\Version;
use App\Models\VersionRegionImage;
use App\Services\Environments\EnvironmentManager;
use App\Services\Environments\RegionImages;
use App\Services\RegionMigrate\LeftClusterCleanup;
use App\Services\RegionMigrateService;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesRegionMigrationClusters;
use Tests\TestCase;

/**
 * A region move (and its rollback) handles the environments that run on the region the way it
 * handles the projects (docs/environments.md, docs/regions.md#moving-a-region-to-another-cluster):
 *
 *  - each environment with a live deployment is deployed again on the new cluster — the version,
 *    overrides and config it ran — also an environment of a project in another region; a refused
 *    one is not moved (its deployments end with the reason);
 *  - a built version whose image the new cluster's registry does not hold is built there first:
 *    the environment keeps running on the old cluster until it is deployed on the new one, and is
 *    not moved when the build fails;
 *  - the rollback deploys the environments again on the restored cluster and removes what the
 *    move deployed for them from the cluster it left — only their objects for a project whose own
 *    workload runs elsewhere;
 *  - custom domains are routed on the cluster the region has now; the move's report lists the
 *    environments as rows of their own.
 */
class RegionMigrateEnvironmentsTest extends TestCase
{
    use FakesRegionMigrationClusters, RefreshDatabase;

    private const B = 'new.cluster.test';

    private const A = 'old.cluster.test';

    private const KSVC = '/apis/serving.knative.dev/v1/namespaces/fn/services';

    private const CONFIGMAPS = '/api/v1/namespaces/fn/configmaps';

    private const SECRETS = '/api/v1/namespaces/fn/secrets';

    private const INGRESSROUTES = '/apis/traefik.io/v1alpha1/namespaces/fn/ingressroutes';

    private const OLD_ZOT = '10.96.1.1:5000';

    private const NEW_ZOT = '10.96.2.2:5000';

    private KnativeRegion $region;

    private ?User $owner = null;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class]);
        $this->fakeClusters();

        $this->region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true,
            'kubeconfig' => $this->kubeconfig(self::OLD),
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
            'registry_mode' => 'internal', 'registry_status' => 'ready',
            'registry_address' => self::OLD_ZOT, 'registry_namespace' => 'runr-registry',
        ]);
    }

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_move_deploys_the_environments_again_and_the_rollback_takes_them_back(): void
    {
        [$project, $production] = $this->caasProject('orders');
        [$staging, $before] = $this->runningEnvironment($project, 'staging');

        $this->move();

        // Production and staging, each its own deployment on the new cluster
        $this->assertSame('region-migration', $project->deployments()->whereIn('status', Deployment::LIVE)->sole()->deployed_by);
        $moved = Deployment::query()->where('environment_id', $staging->id)->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame(['region-migration', $before->version_id, ['min_scale' => 1]], [$moved->deployed_by, $moved->version_id, $moved->deploy_config]);
        $this->assertSame('shut_down', $before->fresh()->status, 'what ran on the old cluster stays with it');
        $this->assertContains('orders-staging', $this->services[self::B]);
        $this->assertSame('shut_down', $production->fresh()->status);
        $rows = collect($this->migrationStatus()['projects']);
        $this->assertSame(['moved', 1], [$rows->firstWhere('environment', 'staging')['outcome'] ?? null, $rows->firstWhere('environment', 'staging')['version'] ?? null]);
        $this->assertArrayNotHasKey('environment', $rows->first(fn ($row) => ! isset($row['environment'])) ?? [], 'production\'s row has no environment');
        $this->assertCount(2, $rows);

        Carbon::setTestNow('2026-10-19 11:00:00');
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh(), (string) $this->owner()->id);

        $restored = Deployment::query()->where('environment_id', $staging->id)->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame(['region-migration', '2026-10-19 11:00:00'], [$restored->deployed_by, $restored->created_at?->toDateTimeString()]);
        $this->assertContains('orders-staging', $this->services[self::A], 'it serves on the restored cluster');
        $this->assertFalse($this->hasObject(self::B, self::KSVC, 'orders-staging'), 'removed from the cluster the rollback left');
        $this->assertStringContainsString('orders-staging', (string) Notification::query()->where('type', 'region.left_cluster_cleaned')->sole()->body);
    }

    public function test_an_environment_of_a_project_in_another_region_moves_with_the_region_it_runs_in(): void
    {
        $paris = KnativeRegion::create(['name' => 'paris', 'project_namespace' => 'fn-paris', 'is_active' => true,
            'kubeconfig' => $this->kubeconfig('https://paris.cluster.test:6443')]);
        [$project, $production] = $this->caasProject('orders', $paris);
        [$eu] = $this->runningEnvironment($project, 'eu', $this->region);
        // What the project's own workload has on that cluster stays (another region's namespace of the same name)
        $this->seedObject(self::B, self::CONFIGMAPS, 'orders-v1', ['runr/project-id' => $project->id, 'runr/version' => '1']);

        $this->move();

        $this->assertSame('active', $production->fresh()->status, 'the project itself runs elsewhere and is left alone');
        $this->assertSame(0, Deployment::query()->where('project_id', $project->id)->whereNull('environment_id')->where('deployed_by', 'region-migration')->count());
        $this->assertSame('region-migration', Deployment::query()->where('environment_id', $eu->id)->whereIn('status', Deployment::LIVE)->sole()->deployed_by);
        $this->assertContains('orders-eu', $this->services[self::B]);
        $this->assertSame(['orders', 'eu', 'moved'], array_values(array_intersect_key($this->migrationStatus()['projects'][0], array_flip(['project', 'environment', 'outcome']))));

        // The environment's objects there; the rollback removes only those
        $this->seedObject(self::B, self::CONFIGMAPS, 'orders-eu-v1', ['runr/project-id' => $project->id, 'runr/environment-id' => $eu->id, 'runr/version' => '1']);
        $this->seedObject(self::B, self::SECRETS, 'orders-eu-secret-env', ['runr/project-id' => $project->id]);
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh(), (string) $this->owner()->id);

        $this->assertFalse($this->hasObject(self::B, self::KSVC, 'orders-eu'));
        $this->assertFalse($this->hasObject(self::B, self::CONFIGMAPS, 'orders-eu-v1'));
        $this->assertFalse($this->hasObject(self::B, self::SECRETS, 'orders-eu-secret-env'));
        $this->assertTrue($this->hasObject(self::B, self::CONFIGMAPS, 'orders-v1'), 'not an object of the environment');
    }

    public function test_a_refused_environment_is_not_moved_and_the_rollback_runs_it_again(): void
    {
        [$project] = $this->caasProject('orders');
        [$staging, $before] = $this->runningEnvironment($project, 'staging');
        ProjectAdmission::guardDeploys(function (Project $project, DeployIntent $intent) {
            if ($intent->environmentId !== null) {
                throw new HttpResponseException(response()->json(['message' => 'staging is over the allocation of the region', 'code' => 'allocation_exceeded'], 422));
            }
        });

        $this->move();

        $before->refresh();
        $this->assertSame('shut_down', $before->status);
        $this->assertSame(RegionMigrateService::NOT_MOVED.'staging is over the allocation of the region', $before->knative_message);
        $this->assertSame(0, Deployment::query()->where('environment_id', $staging->id)->whereIn('status', Deployment::LIVE)->count());
        $row = collect($this->migrationStatus()['projects'])->firstWhere('environment', 'staging');
        $this->assertSame(['not_moved', 'staging is over the allocation of the region'], [$row['outcome'], $row['message']]);

        // Back on the old cluster, where it still runs: deployed again there (metered from then on)
        ProjectAdmission::reset();
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh());
        $this->assertSame('region-migration', Deployment::query()->where('environment_id', $staging->id)->whereIn('status', Deployment::LIVE)->sole()->deployed_by);
    }

    public function test_a_built_version_of_an_environment_is_built_on_the_new_cluster_first(): void
    {
        [$project, $version] = $this->builtProject('orders');
        [$staging, $before] = $this->runningEnvironment($project, 'staging', null, $version);

        $this->move();

        // Production's deploy builds v1 again into the new registry; staging waits for that build
        $waiting = Deployment::query()->where('environment_id', $staging->id)->where('status', 'pending')->sole();
        $this->assertStringStartsWith(RegionImages::WAITING.'the build of v1', (string) $waiting->knative_message);
        $before->refresh();
        $this->assertSame(['active', RegionMigrateService::MOVING.'v1 is built into the registry of the new cluster of the region'], [$before->status, $before->knative_message]);
        $this->assertNotContains('orders-staging', $this->services[self::B], 'nothing it cannot pull');
        $this->assertSame('building', collect($this->migrationStatus()['projects'])->firstWhere('environment', 'staging')['outcome']);

        $this->builtOnTheNewCluster($version->fresh(), self::NEW_ZOT.'/runr-builds/orders:v1');

        $this->assertSame('active', $waiting->fresh()->status);
        $this->assertSame(self::NEW_ZOT.'/runr-builds/orders:v1', $this->appliedImage(self::B, 'orders-staging'));
        $this->assertSame('shutting_down', $before->fresh()->status, 'replaced once the new one is ready');
    }

    public function test_an_environment_whose_build_fails_on_the_new_cluster_is_not_moved(): void
    {
        [$project, $version] = $this->builtProject('orders');
        [$staging, $before] = $this->runningEnvironment($project, 'staging', null, $version);
        $this->move();

        $this->builtOnTheNewCluster($version->fresh(), self::NEW_ZOT.'/runr-builds/orders:v1', 'pip: No matching distribution');

        $new = Deployment::query()->where('environment_id', $staging->id)->where('deployed_by', 'region-migration')->sole();
        $this->assertSame('failed', $new->status);
        $before->refresh();
        $this->assertSame('shut_down', $before->status);
        $this->assertStringStartsWith(RegionMigrateService::NOT_MOVED.'v1 cannot run in eu: its build is failed', (string) $before->knative_message);
    }

    public function test_builds_of_the_move_window_on_the_cluster_the_rollback_left_are_cancelled(): void
    {
        [$project, $version] = $this->builtProject('orders');
        $this->move();
        $row = VersionRegionImage::query()->create(['project_id' => $project->id, 'version_id' => $version->id, 'knative_region_id' => $this->region->id,
            'registry' => self::NEW_ZOT, 'status' => VersionRegionImage::BUILDING, 'image' => self::NEW_ZOT.'/runr-builds/orders:v1',
            'job_name' => 'rbuild-orders-v1', 'token' => 'abc']);

        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh());

        $this->assertSame(VersionRegionImage::FAILED, $row->fresh()->status);
        $this->assertContains('DELETE '.self::B.' /apis/batch/v1/namespaces/fn/jobs/rbuild-orders-v1', $this->calls);
        $this->assertNull(app(RegionImages::class)->check($row->id, 'abc'), 'its checks stop');
    }

    public function test_custom_domains_of_projects_and_environments_are_routed_on_the_new_cluster(): void
    {
        [$project] = $this->caasProject('orders');
        [$staging] = $this->runningEnvironment($project, 'staging');
        ProjectDomain::query()->create(['project_id' => $project->id, 'domain' => 'orders.example.com', 'status' => ProjectDomain::STATUS_ACTIVE,
            'verification_token' => 'a', 'tls_mode' => ProjectDomain::TLS_NONE]);
        ProjectDomain::query()->create(['project_id' => $project->id, 'environment_id' => $staging->id, 'domain' => 'staging.example.com',
            'status' => ProjectDomain::STATUS_ACTIVE, 'verification_token' => 'b', 'tls_mode' => ProjectDomain::TLS_NONE]);

        $this->move();

        $routed = array_map(fn (array $route) => $route['spec']['routes'][0]['match'] ?? '', array_values($this->objects[self::B][self::INGRESSROUTES] ?? []));
        $this->assertNotEmpty(array_filter($routed, fn ($m) => str_contains($m, 'orders.example.com')), 'production\'s domain is routed on the new cluster');
        $this->assertNotEmpty(array_filter($routed, fn ($m) => str_contains($m, 'staging.example.com')), 'and the environment\'s');
        $this->assertSame([ProjectDomain::STATUS_ACTIVE, ProjectDomain::STATUS_ACTIVE], ProjectDomain::query()->orderBy('domain')->pluck('status')->all());
    }

    public function test_left_cluster_cleanup_takes_only_the_environment_objects_of_a_project_running_elsewhere(): void
    {
        $result = (new \ReflectionMethod(LeftClusterCleanup::class, 'match'))->invoke(null, 'ConfigMap',
            ['metadata' => ['name' => 'orders-v1', 'labels' => ['runr/project-id' => 'p1', 'runr/version' => '1']]],
            ['p1' => ['slug' => 'orders', 'secrets' => ['orders-eu-secret-env'], 'environments_only' => true]]);
        $this->assertNull($result, 'the project\'s own code ConfigMap is not the environment\'s');

        foreach ([
            ['ConfigMap', ['name' => 'orders-eu-v1', 'labels' => ['runr/project-id' => 'p1', 'runr/environment-id' => 'e1', 'runr/version' => '1']]],
            ['Secret', ['name' => 'orders-eu-secret-env', 'labels' => ['runr/project-id' => 'p1']]],
            ['Job', ['name' => 'rbuild-orders-v1', 'labels' => ['runr/project-id' => 'p1', 'runr/build' => 'true']]],
            ['Service', ['name' => 'orders-eu', 'labels' => ['runr/environment-of' => 'p1', 'runr/environment-id' => 'e1']]],
        ] as [$kind, $metadata]) {
            $this->assertNotNull((new \ReflectionMethod(LeftClusterCleanup::class, 'match'))->invoke(null, $kind, ['metadata' => $metadata],
                ['p1' => ['slug' => 'orders', 'secrets' => ['orders-eu-secret-env'], 'environments_only' => true]]), "{$kind} {$metadata['name']}");
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function move(): void
    {
        $this->migrateRegion($this->region->fresh(), self::NEW_ZOT);
        $this->assertSame('dns_check', $this->region->fresh()->migration_status);
    }

    private function owner(): User
    {
        return $this->owner ??= User::factory()->create();
    }

    /** @return array<string, mixed> GET migration-status of the region */
    private function migrationStatus(): array
    {
        return $this->actingAs($this->owner(), 'sanctum')
            ->getJson("/api/knative-regions/{$this->region->id}/migration-status")
            ->assertOk()
            ->json();
    }

    /** @return array{Project, Deployment} a CaaS project running since 09:00 (in $region, default the moved one) */
    private function caasProject(string $name, ?KnativeRegion $region = null): array
    {
        $region ??= $this->region;
        $project = Project::create(['name' => $name, 'service_type' => 'caas']);
        $project->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
            'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 2]);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready',
            'config_snapshot' => ['image' => 'nginx:1.27-alpine']]);

        return [$project->fresh(), $this->running($project, $version)];
    }

    /** @return array{Project, Version} a FaaS project whose v1 was built into the old cluster's registry, running since 09:00 */
    private function builtProject(string $name): array
    {
        $project = Project::create(['name' => $name, 'service_type' => 'faas', 'runtime' => 'python']);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev',
            'build_status' => 'ready', 'build_image' => self::OLD_ZOT."/runr-builds/{$project->slug}:v1"]);
        $this->running($project, $version);

        return [$project->fresh(), $version];
    }

    private function running(Project $project, Version $version): Deployment
    {
        Carbon::setTestNow('2026-10-19 09:00:00');
        $deployment = $project->deployments()->create([
            'version_id' => $version->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => "run-{$project->slug}-v1", 'deployed_by' => 'dev', 'deploy_config' => ['min_scale' => 2],
        ]);
        $this->services[self::A][] = $project->slug;

        return $deployment;
    }

    /**
     * An environment of $project (in $region, default its project's) running $version (default
     * the project's newest) since 09:00 on the old cluster.
     *
     * @return array{ProjectEnvironment, Deployment}
     */
    private function runningEnvironment(Project $project, string $name, ?KnativeRegion $region = null, ?Version $version = null): array
    {
        $environment = app(EnvironmentManager::class)->create($project, ['name' => $name] + ($region ? ['knative_region_id' => $region->id] : []));
        $version ??= Version::query()->where('project_id', $project->id)->latest('number')->firstOrFail();
        Carbon::setTestNow('2026-10-19 09:00:00');
        $deployment = Deployment::query()->create([
            'project_id' => $project->id, 'environment_id' => $environment->id, 'version_id' => $version->id, 'status' => 'active',
            'ready_at' => now(), 'knative_ready' => true, 'k8s_job_name' => "run-{$environment->workload_name}-v1", 'deployed_by' => 'dev',
            'deploy_config' => ['min_scale' => 1],
        ]);
        $this->services[self::A][] = $environment->workload_name;

        return [$environment, $deployment];
    }
}
