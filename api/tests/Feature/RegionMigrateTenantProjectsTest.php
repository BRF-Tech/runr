<?php

namespace Tests\Feature;

use App\Events\DeploymentCreated;
use App\Events\DeploymentStatusChanged;
use App\Jobs\BuildVersionJob;
use App\Jobs\GitDeploy\BuildPreviewJob;
use App\Jobs\GitDeploy\DeployPreviewJob;
use App\Jobs\GitDeploy\ReportGitStatusJob;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\Git\PreviewEnvironment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\QueuedDeploy;
use App\Models\Version;
use App\Services\GitDeploy\PreviewVersionScope;
use App\Services\Projects\QueuedDeploys;
use App\Services\Projects\VersionRebuilds;
use App\Services\RegionMigrateService;
use App\Support\Projects\DeployIntent;
use App\Support\Projects\ProjectAdmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantAwareKnativeRegion;
use Modules\Tenancy\Providers\TenancyServiceProvider;
use Modules\Tenancy\Services\TenantSchema;
use Tests\Concerns\FakesRegionMigrationClusters;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Multi-tenant installs: the organizations a platform region is assigned to keep their projects
 * on it in their own schemas. Moving the region deploys those projects on the new cluster too,
 * each in its organization's context through the organization's copy of the region — with the
 * same stop and admission checks as the platform's projects — and a rollback deploys them on the
 * old cluster again or removes what they run there. A tenant's own region moves inside the
 * tenant's schema. Runs on SQLite and on PostgreSQL (DB_CONNECTION=pgsql: tenant schemas next to
 * "public").
 */
class RegionMigrateTenantProjectsTest extends TestCase
{
    use FakesRegionMigrationClusters, RefreshDatabase, WiresTenancy;

    private KnativeRegion $region;

    private Tenant $acme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        // What TenancyServiceProvider wires with TENANCY_ENABLED: the region sync to the copies and
        // the kubeconfig resolver of a copy (read from the central row)
        (fn () => $this->registerSyncEvents())->call(new TenancyServiceProvider($this->app));
        Event::fake([DeploymentStatusChanged::class, DeploymentCreated::class]);
        $this->fakeClusters();

        $this->region = KnativeRegion::create([
            'name' => 'eu', 'project_namespace' => 'fn', 'is_active' => true,
            'kubeconfig' => $this->kubeconfig(self::OLD),
            'knative_settings' => ['knative_domain' => 'eu.runr.test'],
            'registry_mode' => 'internal', 'registry_status' => 'ready',
            'registry_address' => '10.96.1.1:5000', 'registry_namespace' => 'runr-registry',
        ]);
        $this->acme = $this->tenantWithCopy('acme');
    }

    protected function tearDown(): void
    {
        ProjectAdmission::reset();
        Carbon::setTestNow();
        $this->tearDownTenancy();
        parent::tearDown();
    }

    public function test_the_organizations_projects_on_a_moved_platform_region_are_deployed_on_the_new_cluster(): void
    {
        [$shop] = $this->runningProject('shop');
        [$orders, $before] = $this->acme->run(fn () => $this->runningProject('acme-orders'));
        [$idle] = $this->acme->run(fn () => $this->runningProject('acme-idle', live: false));
        $ops = $this->tenantWithCopy('ops');
        [$billing] = $ops->run(fn () => $this->runningProject('ops-billing'));

        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        foreach (['shop', 'acme-orders', 'ops-billing'] as $slug) {
            $this->assertContains("PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/{$slug}", $this->calls, "{$slug} runs on the new cluster");
        }
        $this->assertSame('region-migration', $shop->deployments()->where('status', 'active')->sole()->deployed_by);
        $this->acme->run(function () use ($orders, $before, $idle) {
            $this->assertSame('shut_down', Deployment::query()->findOrFail($before->id)->status);
            $moved = Deployment::query()->where('project_id', $orders->id)->where('status', 'active')->sole();
            $this->assertSame('region-migration', $moved->deployed_by);
            $this->assertSame(['min_scale' => 2], $moved->deploy_config, 'with the overrides it ran with');
            $this->assertSame(0, Deployment::query()->where('project_id', $idle->id)->count(), 'a project that runs nothing is not deployed');
        });
        $this->assertSame(1, $ops->run(fn () => Deployment::query()->where('project_id', $billing->id)->where('status', 'active')->where('deployed_by', 'region-migration')->count()));
        $this->assertNotContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/acme-idle', $this->calls);
    }

    public function test_the_stop_and_admission_checks_apply_to_the_organizations_projects(): void
    {
        [$fixed, $refused] = $this->acme->run(fn () => $this->runningProject('acme-fixed'));
        [$stopped] = $this->acme->run(function () {
            [$project, $deployment] = $this->runningProject('acme-stopped');
            $deployment->transition(Deployment::LIVE, ['status' => 'shut_down']);
            $project->forceFill(['stopped_at' => now(), 'stop_reason' => 'Unpaid bill'])->saveQuietly();

            return [$project];
        });
        ProjectAdmission::guardDeploys(function (Project $project, DeployIntent $intent) {
            if ($project->slug === 'acme-fixed') {
                throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422));
            }
        });

        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        $this->acme->run(function () use ($fixed, $refused, $stopped) {
            $old = Deployment::query()->findOrFail($refused->id);
            $this->assertSame('shut_down', $old->status);
            $this->assertSame(RegionMigrateService::NOT_MOVED.'Over the allocation.', $old->knative_message);
            $this->assertSame(1, Deployment::query()->where('project_id', $fixed->id)->count(), 'nothing is deployed');
            $this->assertSame(0, Deployment::query()->where('project_id', $stopped->id)->whereIn('status', Deployment::LIVE)->count());
            $this->assertTrue(Project::query()->findOrFail($stopped->id)->isStopped());
        });
        $this->assertNotContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/acme-fixed', $this->calls);
        $this->assertNotContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/acme-stopped', $this->calls);
    }

    public function test_a_rollback_deploys_the_organizations_projects_on_the_old_cluster_again(): void
    {
        [$orders] = $this->acme->run(fn () => $this->runningProject('acme-orders'));
        [$fixed, $notMoved] = $this->acme->run(fn () => $this->runningProject('acme-fixed'));
        $refuse = true;
        ProjectAdmission::guardDeploys(function (Project $project) use (&$refuse) {
            if ($refuse && $project->slug === 'acme-fixed') {
                throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422));
            }
        });
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');
        $this->assertSame('10.96.2.2:5000', $this->acme->run(fn () => KnativeRegion::query()->findOrFail($this->region->id)->registry_address), 'the copy follows the move');

        // The allocation was approved meanwhile; the move is rolled back an hour later
        $refuse = false;
        Carbon::setTestNow('2026-10-19 11:00:00');
        app(RegionMigrateService::class)->rollbackMigration(TenantAwareKnativeRegion::findOrFail($this->region->id));

        $this->acme->run(function () use ($orders, $fixed, $notMoved) {
            $this->assertSame('10.96.1.1:5000', KnativeRegion::query()->findOrFail($this->region->id)->registry_address);
            $live = Deployment::query()->where('project_id', $orders->id)->whereIn('status', Deployment::LIVE)->sole();
            $this->assertSame('active', $live->status);
            $this->assertSame('2026-10-19 11:00:00', $live->ready_at?->toDateTimeString());
            $restored = Deployment::query()->where('project_id', $fixed->id)->whereKeyNot($notMoved->id)->sole();
            $this->assertSame('active', $restored->status, 'the project the move did not move serves metered again');
            $this->assertSame(['min_scale' => 2], $restored->deploy_config);
        });
        $this->assertContains('PATCH old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/acme-orders', $this->calls);
        $this->assertContains('PATCH old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/acme-fixed', $this->calls);
    }

    public function test_a_rollback_removes_the_organizations_projects_from_the_cluster_it_left(): void
    {
        $this->runningProject('shop');
        [$orders] = $this->acme->run(fn () => $this->runningProject('acme-orders'));
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');
        // The organization's database credentials on the new cluster
        $this->seedObject('new.cluster.test', '/api/v1/namespaces/fn/secrets', 'acme-orders-bindings',
            ['runr/project-id' => $orders->id, 'runr.dev/bindings' => 'true', 'runr/tenant' => 'acme']);

        app(RegionMigrateService::class)->rollbackMigration(TenantAwareKnativeRegion::findOrFail($this->region->id));

        foreach (['shop', 'acme-orders'] as $slug) {
            $this->assertContains("DELETE new.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/{$slug}", $this->calls, "{$slug} leaves the new cluster");
            $this->assertNotContains("DELETE old.cluster.test /apis/serving.knative.dev/v1/namespaces/fn/services/{$slug}", $this->calls, "{$slug} runs on the old one");
        }
        $this->assertTrue($this->hasObject('new.cluster.test', '/api/v1/namespaces/fn/secrets', 'acme-orders-bindings'), 'its data service credentials stay');
        $this->assertFalse(tenancy()->initialized, 'the central context comes back');
    }

    public function test_a_tenants_own_region_moves_its_projects_inside_the_tenant(): void
    {
        $own = $this->acme->run(fn () => KnativeRegion::create([
            'name' => 'own', 'project_namespace' => 'own', 'is_active' => true, 'kubeconfig' => $this->kubeconfig(self::OLD),
        ]));
        [$orders, $before] = $this->acme->run(fn () => $this->runningProject('acme-own', $own));

        $this->acme->run(fn () => $this->migrateRegion(KnativeRegion::query()->findOrFail($own->id), '10.96.2.2:5000'));

        $this->assertContains('PATCH new.cluster.test /apis/serving.knative.dev/v1/namespaces/own/services/acme-own', $this->calls);
        $this->acme->run(function () use ($orders, $before) {
            $this->assertSame('shut_down', Deployment::query()->findOrFail($before->id)->status);
            $this->assertSame(1, Deployment::query()->where('project_id', $orders->id)->where('status', 'active')->where('deployed_by', 'region-migration')->count());
        });
        $this->assertFalse(tenancy()->initialized, 'the central context comes back');
    }

    public function test_an_organizations_built_version_is_built_again_on_the_new_cluster_and_the_rollback_settles_its_builds(): void
    {
        // A code project deploys with the runtime catalog, a central table a tenant schema reaches
        // through the search path — PostgreSQL only (a SQLite tenant database is a file of its own)
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL: run with DB_CONNECTION=pgsql');
        }

        [$orders, $before, $v1] = $this->acme->run(function () {
            [$project, $deployment] = $this->runningProject('acme-orders', type: 'faas');

            return [$project, $deployment, Version::query()->findOrFail($deployment->version_id)];
        });

        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        $this->acme->run(function () use ($before, $v1) {
            $this->assertSame('pending', Version::query()->findOrFail($v1->id)->build_status, 'built again on the new cluster');
            $queued = QueuedDeploy::query()->sole();
            $this->assertSame([QueuedDeploy::WAITING, $before->id, '10.96.1.1:5000/runr-builds/acme-orders:v1'],
                [$queued->status, $queued->replaces_deployment_id, $queued->previous_build_image]);
            $this->assertSame('active', Deployment::query()->findOrFail($before->id)->status, 'it runs on the old cluster until then');
        });
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->version->id === $v1->id);
        $report = app(RegionMigrateService::class)->migrationReport($this->region->fresh());
        $this->assertSame([['acme', 'acme-orders', 'building']], array_map(fn ($row) => [$row['organization'], $row['project'], $row['outcome']], $report));

        // The rollback comes while v1 builds on the new cluster, next to a build saved there
        [$fresh] = $this->acme->run(function () use ($v1) {
            Version::query()->findOrFail($v1->id)->forceFill(['build_status' => 'building', 'build_image' => '10.96.2.2:5000/runr-builds/acme-orders:v1'])->save();
            $this->travel(5)->minutes();
            $project = Project::create(['name' => 'acme-fresh', 'service_type' => 'faas', 'runtime' => 'python']);
            $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();

            return [Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'building',
                'build_image' => '10.96.2.2:5000/runr-builds/acme-fresh:v1'])];
        });
        app(RegionMigrateService::class)->rollbackMigration(TenantAwareKnativeRegion::findOrFail($this->region->id));

        $this->acme->run(function () use ($orders, $v1, $fresh) {
            $this->assertSame(QueuedDeploy::CANCELLED, QueuedDeploy::query()->sole()->status);
            $restored = Version::query()->findOrFail($v1->id);
            $this->assertSame(['ready', '10.96.1.1:5000/runr-builds/acme-orders:v1'], [$restored->build_status, $restored->build_image]);
            $this->assertSame(1, Deployment::query()->where('project_id', $orders->id)->where('status', 'active')->count(), 'deployed again on the old cluster');
            $this->assertSame([Version::STATUS_CANCELLED, RegionMigrateService::BUILD_CANCELLED],
                [Version::query()->findOrFail($fresh->id)->build_status, Version::query()->findOrFail($fresh->id)->build_error]);
        });
        $this->assertSame('10.96.1.1:5000/runr-builds/acme-orders:v1', $this->appliedImage('old.cluster.test', 'acme-orders'));
        $this->assertContains('DELETE new.cluster.test /apis/batch/v1/namespaces/fn/jobs/build-acme-fresh-v1', $this->calls);
    }

    public function test_an_organizations_live_previews_move_with_the_platform_region_and_back_with_the_rollback(): void
    {
        Bus::fake([BuildVersionJob::class, BuildPreviewJob::class, DeployPreviewJob::class, ReportGitStatusJob::class]);
        [$built, $interpreted] = $this->acme->run(function () {
            [$project] = $this->runningProject('acme-orders', type: 'faas', live: false);

            return [
                $this->preview($project, 7, '10.96.1.1:5000/runr-builds/acme-orders:v1000000001'),
                $this->preview($project, 8, null),
            ];
        });

        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        $this->acme->run(function () use ($built, $interpreted) {
            $this->assertSame(PreviewEnvironment::BUILDING, PreviewEnvironment::query()->findOrFail($built->id)->status);
            $this->assertSame('pending', PreviewVersionScope::query()->findOrFail($built->version_id)->build_status, 'built again on the new cluster');
            $this->assertSame(PreviewEnvironment::DEPLOYING, PreviewEnvironment::query()->findOrFail($interpreted->id)->status);
        });
        Bus::assertDispatched(BuildPreviewJob::class, fn (BuildPreviewJob $job) => $job->previewId === $built->id);
        Bus::assertDispatched(DeployPreviewJob::class, fn (DeployPreviewJob $job) => $job->previewId === $interpreted->id);

        // Built into the new cluster's registry; the move is rolled back
        $this->acme->run(fn () => PreviewVersionScope::query()->findOrFail($built->version_id)
            ->forceFill(['build_status' => 'ready', 'build_image' => '10.96.2.2:5000/runr-builds/acme-orders:v1000000001'])->save());
        Carbon::setTestNow('2026-10-19 11:00:00');
        app(RegionMigrateService::class)->rollbackMigration(TenantAwareKnativeRegion::findOrFail($this->region->id));

        $this->acme->run(function () use ($built) {
            $preview = PreviewEnvironment::query()->findOrFail($built->id);
            $this->assertSame(PreviewEnvironment::BUILDING, $preview->status);
            $this->assertStringStartsWith('Built again on the cluster the migration rollback restored: ', (string) $preview->status_message);
        });
        Bus::assertDispatchedTimes(BuildPreviewJob::class, 2);
        Bus::assertDispatchedTimes(DeployPreviewJob::class, 2);
    }

    public function test_an_organizations_older_version_is_built_again_after_the_move_and_a_rollback_cancels_its_unstarted_deploy(): void
    {
        [$orders, $v1] = $this->acme->run(function () {
            [$project] = $this->runningProject('acme-orders', type: 'faas', live: false);

            return [$project, Version::query()->where('project_id', $project->id)->sole()];
        });
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        $queued = $this->acme->run(function () use ($orders, $v1) {
            $project = Project::query()->findOrFail($orders->id);
            $this->assertTrue(VersionRebuilds::needed($project, $v1->fresh()), 'the copy has the new cluster\'s registry');

            return app(VersionRebuilds::class)->queue($project, $v1->fresh(), 'dev');
        });
        Bus::assertDispatched(BuildVersionJob::class, fn (BuildVersionJob $job) => $job->version->id === $v1->id);
        // A step claimed it and has not created its deployment when the rollback comes
        $this->acme->run(fn () => QueuedDeploy::query()->whereKey($queued->id)->update(['status' => QueuedDeploy::STARTING]));

        Carbon::setTestNow('2026-10-19 11:00:00');
        app(RegionMigrateService::class)->rollbackMigration(TenantAwareKnativeRegion::findOrFail($this->region->id));

        $this->acme->run(function () use ($queued, $v1) {
            $this->assertSame(QueuedDeploy::CANCELLED, QueuedDeploy::query()->findOrFail($queued->id)->status);
            $this->assertSame(['ready', '10.96.1.1:5000/runr-builds/acme-orders:v1'],
                [Version::query()->findOrFail($v1->id)->build_status, Version::query()->findOrFail($v1->id)->build_image], 'its old image is in the restored registry');
        });
    }

    public function test_an_organizations_refused_deploy_of_another_version_during_the_move_leaves_its_project_not_moved(): void
    {
        [$orders, $before, $v2] = $this->acme->run(function () {
            [$project, $deployment] = $this->runningProject('acme-orders', type: 'faas');
            $other = Version::create(['project_id' => $project->id, 'number' => 2, 'created_by' => 'dev', 'build_status' => 'ready',
                'build_image' => '10.96.1.1:5000/runr-builds/acme-orders:v2']);

            return [$project, $deployment, $other];
        });
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        // v2 is deployed while the move's deploy of v1 waits for its build on the new cluster
        Carbon::setTestNow('2026-10-19 10:05:00');
        $queued = $this->acme->run(fn () => app(VersionRebuilds::class)->queue(Project::query()->findOrFail($orders->id), $v2->fresh(), 'dev'));
        $this->assertSame([QueuedDeploys::REASON_REGION_MIGRATION, $before->id], [$queued->replaces_reason, $queued->replaces_deployment_id], 'it takes the move\'s place');
        ProjectAdmission::guardDeploys(function (Project $project) {
            if ($project->slug === 'acme-orders') {
                throw new HttpResponseException(response()->json(['message' => 'Over the allocation.'], 422));
            }
        });

        $this->acme->run(fn () => $this->builtOnTheNewCluster(Version::query()->findOrFail($v2->id), '10.96.2.2:5000/runr-builds/acme-orders:v2'));

        $this->acme->run(function () use ($queued, $before) {
            $this->assertSame([QueuedDeploy::FAILED, 'Over the allocation.'], [QueuedDeploy::query()->findOrFail($queued->id)->status, QueuedDeploy::query()->findOrFail($queued->id)->message]);
            $old = Deployment::query()->findOrFail($before->id);
            $this->assertSame(['shut_down', RegionMigrateService::NOT_MOVED.'Over the allocation.'], [$old->status, $old->knative_message]);
        });
        $report = app(RegionMigrateService::class)->migrationReport($this->region->fresh());
        $this->assertSame([['acme', 'acme-orders', 'not_moved', 2, 'Over the allocation.']],
            array_map(fn ($row) => [$row['organization'], $row['project'], $row['outcome'], $row['version'], $row['message']], $report));
    }

    public function test_a_tenants_own_region_gets_its_firecracker_thin_pool_size_back_with_the_rollback(): void
    {
        $own = $this->acme->run(fn () => KnativeRegion::create([
            'name' => 'own', 'project_namespace' => 'own', 'is_active' => true, 'kubeconfig' => $this->kubeconfig(self::OLD),
            'sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => ['fc'], 'sandbox_kata_devmapper_pool_gb' => 40,
        ]));

        $this->acme->run(fn () => $this->migrateRegion(KnativeRegion::query()->findOrFail($own->id), '10.96.2.2:5000'));

        $this->acme->run(function () use ($own) {
            $moved = KnativeRegion::query()->findOrFail($own->id);
            $this->assertSame(40, $moved->migration_old_state['sandbox_kata_devmapper_pool_gb'] ?? null, 'kept in the tenant schema');
            // Kata failed on the new cluster before its thin-pool was set up
            $moved->forceFill(['sandbox_kata_status' => 'error', 'sandbox_kata_devmapper_pool_gb' => null])->save();

            app(RegionMigrateService::class)->rollbackMigration($moved->fresh());

            $back = KnativeRegion::query()->findOrFail($own->id);
            $this->assertSame(['ready', 40], [$back->sandbox_kata_status, $back->sandbox_kata_devmapper_pool_gb]);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Live preview #$number of $project in the current schema, its version built to $image (null: none of its own). */
    private function preview(Project $project, int $number, ?string $image): PreviewEnvironment
    {
        $version = Version::create(['project_id' => $project->id, 'number' => PreviewVersionScope::nextNumber($project), 'commit_id' => "p{$number}",
            'created_by' => 'git', 'build_status' => 'ready', 'build_image' => $image]);

        return PreviewEnvironment::query()->create([
            'project_id' => $project->id, 'number' => $number, 'provider' => 'github', 'head_sha' => str_repeat('a', 40),
            'status' => PreviewEnvironment::READY, 'service_name' => "{$project->slug}-pr-{$number}", 'version_id' => $version->id,
        ]);
    }

    /** A tenant with every tenant table, the platform region assigned to it (its copy synced). */
    private function tenantWithCopy(string $slug): Tenant
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
        $this->wiredTenants[] = $tenant;
        TenantSchema::create($tenant);

        $tenant->regions()->attach($this->region->id, ['cpu_quota_m' => 8000, 'memory_quota_mb' => 16384]);
        CentralSyncableRegion::findOrFail($this->region->id)->triggerSyncEvent();

        return $tenant;
    }

    /**
     * A CaaS project in the current schema running since 09:00 on $region (the platform region by
     * default) with two pods — its Knative Service is on the old cluster. $live false: never
     * deployed.
     *
     * @return array{Project, Deployment|null}
     */
    private function runningProject(string $name, ?KnativeRegion $region = null, bool $live = true, string $type = 'caas'): array
    {
        $project = Project::create(['name' => $name, 'service_type' => $type, 'runtime' => $type === 'faas' ? 'python' : null]);
        $project->forceFill(['knative_region_id' => ($region ?? $this->region)->id])->saveQuietly();
        if ($type === 'faas') {
            // v1 built into the old cluster's registry
            FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        } else {
            ContainerConfig::create(['project_id' => $project->id, 'image' => 'nginx:1.27-alpine', 'cpu' => '250m', 'memory_mb' => 128,
                'trigger_type' => 'http', 'timeout_seconds' => 30, 'scale_to_zero' => false, 'min_scale' => 1, 'max_scale' => 2]);
        }
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready',
            'build_image' => $type === 'faas' ? "10.96.1.1:5000/runr-builds/{$project->slug}:v1" : null]);
        if (! $live) {
            return [$project->fresh(), null];
        }

        Carbon::setTestNow('2026-10-19 09:00:00');
        $deployment = $project->deployments()->create([
            'version_id' => $version->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => "run-{$project->slug}-v1", 'deployed_by' => 'dev', 'deploy_config' => ['min_scale' => 2],
        ]);
        $this->services['old.cluster.test'][] = $project->slug;

        return [$project->fresh(), $deployment];
    }
}
