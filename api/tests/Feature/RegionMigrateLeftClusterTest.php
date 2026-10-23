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
use App\Models\User;
use App\Models\Version;
use App\Services\RegionMigrateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRegionMigrationClusters;
use Tests\TestCase;

/**
 * A rollback leaves the cluster the move went to, where the move deployed the region's projects
 * again: their functions would keep answering on that cluster's own address, metered by nothing.
 * The rollback removes them from there (RemoveLeftClusterWorkloadsJob, synchronous in the tests):
 * the Knative Services of the projects and their previews, CronJobs, queue consumers and what
 * they read or built — found by the labels RunR sets, for the region's projects only. The
 * cluster's installs and the data services with their credentials and volumes stay, so does what
 * runs on the restored cluster. Best effort: a cluster that does not answer leaves the rollback
 * done, and the operator who rolled back is told either way.
 */
class RegionMigrateLeftClusterTest extends TestCase
{
    use ActsWithRoles, FakesRegionMigrationClusters, RefreshDatabase;

    private const B = 'new.cluster.test';

    private const A = 'old.cluster.test';

    private const KSVC = '/apis/serving.knative.dev/v1/namespaces/fn/services';

    private const CRONJOBS = '/apis/batch/v1/namespaces/fn/cronjobs';

    private const DEPLOYMENTS = '/apis/apps/v1/namespaces/fn/deployments';

    private const JOBS = '/apis/batch/v1/namespaces/fn/jobs';

    private const CONFIGMAPS = '/api/v1/namespaces/fn/configmaps';

    private const SECRETS = '/api/v1/namespaces/fn/secrets';

    private const PVCS = '/api/v1/namespaces/fn/persistentvolumeclaims';

    private KnativeRegion $region;

    private User $operator;

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
        $this->operator = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_rollback_removes_the_projects_workloads_from_the_cluster_it_left(): void
    {
        [$orders] = $this->runningProject('orders');
        [$reports] = $this->runningProject('reports');
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');
        $this->assertTrue($this->hasObject(self::B, self::KSVC, 'orders'), 'the move deployed it on the new cluster');

        // What else the projects run and read there: a preview, a CronJob, a queue consumer, the
        // Secrets of their environment, code ConfigMaps and a build
        $this->seedObject(self::B, self::KSVC, 'orders-pr-7', ['runr/preview-of' => $orders->id, 'runr.dev/preview' => 'orders-pr-7', 'runr/pr' => '7']);
        $this->seedObject(self::B, self::CONFIGMAPS, 'orders-pr-7-v1000000001', ['runr/project-id' => $orders->id, 'runr/version' => '1000000001', 'runr.dev/preview' => 'orders-pr-7']);
        $this->seedObject(self::B, self::SECRETS, 'orders-pr-7-secret-env', ['runr/project-id' => $orders->id, 'runr.dev/preview' => 'orders-pr-7', 'runr/preview-of' => $orders->id]);
        $this->seedObject(self::B, self::CRONJOBS, 'reports-trigger', ['runr/project-id' => $reports->id, 'runr/version' => '1']);
        $this->seedObject(self::B, self::CONFIGMAPS, 'reports-v1', ['runr/project-id' => $reports->id, 'runr/version' => '1']);
        $this->seedObject(self::B, self::DEPLOYMENTS, 'orders-qc', ['runr/project-id' => $orders->id, 'runr/component' => 'queue-consumer']);
        $this->seedObject(self::B, self::SECRETS, 'orders-qc-redis', ['runr/project-id' => $orders->id, 'runr/component' => 'queue-consumer']);
        $this->seedObject(self::B, self::SECRETS, 'orders-secret-env', ['runr/project-id' => $orders->id]);
        $this->seedObject(self::B, self::JOBS, 'build-reports-v2', ['runr/build' => 'true', 'runr/project-id' => $reports->id]);
        $this->seedObject(self::B, self::CONFIGMAPS, 'build-reports-v2', ['runr/build' => 'true', 'runr/project-id' => $reports->id]);

        // What stays: the credentials of the data services, a custom domain's certificate, a volume,
        // a data service of the region, and a project of another region in a namespace of that name
        $this->seedObject(self::B, self::SECRETS, 'orders-bindings', ['runr/project-id' => $orders->id, 'runr.dev/bindings' => 'true']);
        $this->seedObject(self::B, self::SECRETS, 'runr-domain-0123456789abcdef0123', ['runr/project-id' => $orders->id]);
        $this->seedObject(self::B, self::PVCS, 'orders-data', ['runr/project-id' => $orders->id]);
        $this->seedObject(self::B, self::DEPLOYMENTS, 'runr-kv', ['app.kubernetes.io/managed-by' => 'runr']);
        $this->seedObject(self::B, self::KSVC, 'elsewhere', ['runr/project-id' => '01JOTHERREGIONPROJECT0000']);
        $this->seedObject(self::B, self::DEPLOYMENTS, 'elsewhere-qc', ['runr/project-id' => '01JOTHERREGIONPROJECT0000', 'runr/component' => 'queue-consumer']);

        Carbon::setTestNow('2026-10-19 11:00:00');
        $queued = app(RegionMigrateService::class)->rollbackMigration($this->region->fresh(), (string) $this->operator->id);

        $this->assertTrue($queued);
        foreach ([[self::KSVC, 'orders'], [self::KSVC, 'reports'], [self::KSVC, 'orders-pr-7'], [self::CRONJOBS, 'reports-trigger'],
            [self::DEPLOYMENTS, 'orders-qc'], [self::JOBS, 'build-reports-v2'], [self::CONFIGMAPS, 'build-reports-v2'],
            [self::CONFIGMAPS, 'reports-v1'], [self::CONFIGMAPS, 'orders-pr-7-v1000000001'],
            [self::SECRETS, 'orders-secret-env'], [self::SECRETS, 'orders-qc-redis'], [self::SECRETS, 'orders-pr-7-secret-env']] as [$collection, $name]) {
            $this->assertFalse($this->hasObject(self::B, $collection, $name), "{$collection}/{$name} is removed from the cluster the rollback left");
        }
        foreach ([[self::SECRETS, 'orders-bindings'], [self::SECRETS, 'runr-domain-0123456789abcdef0123'], [self::PVCS, 'orders-data'],
            [self::DEPLOYMENTS, 'runr-kv'], [self::KSVC, 'elsewhere'], [self::DEPLOYMENTS, 'elsewhere-qc']] as [$collection, $name]) {
            $this->assertTrue($this->hasObject(self::B, $collection, $name), "{$collection}/{$name} stays");
        }
        $this->assertNotContains('DELETE '.self::B.' /api/v1/namespaces/fn', $this->calls, 'the namespace stays');

        // The restored cluster runs the projects again: nothing of the cleanup goes there
        foreach (['orders', 'reports'] as $slug) {
            $this->assertContains($slug, $this->services[self::A], "{$slug} serves on the restored cluster");
            $this->assertNotContains('DELETE '.self::A.' '.self::KSVC."/{$slug}", $this->calls);
        }

        $notice = Notification::query()->where('user_id', $this->operator->id)->sole();
        $this->assertSame(['region.left_cluster_cleaned', 'info', 'Left cluster cleanup of eu: removed 5 project workloads'],
            [$notice->type, $notice->level, $notice->title]);
        foreach (['Knative Services orders, reports, orders-pr-7', 'CronJob reports-trigger', 'queue consumer orders-qc',
            '7 objects they read or built (build Jobs, ConfigMaps, Secrets)', 'Its installs and data services stay.'] as $part) {
            $this->assertStringContainsString($part, (string) $notice->body);
        }
        $this->assertSame(5, $notice->data['removed'] ?? null);
    }

    public function test_a_project_created_on_the_new_cluster_leaves_both_clusters_and_its_data_services_stay(): void
    {
        $this->region->update(['registry_mode' => 'internal', 'registry_status' => 'ready',
            'registry_address' => '10.96.1.1:5000', 'registry_namespace' => 'runr-registry']);
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        // Created while the region ran on the new cluster, with a database: built and deployed there
        $fresh = Project::create(['name' => 'fresh', 'service_type' => 'faas', 'runtime' => 'python']);
        $fresh->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $fresh->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        $v1 = Version::create(['project_id' => $fresh->id, 'number' => 1, 'commit_id' => 'abc', 'created_by' => 'dev',
            'build_status' => 'ready', 'build_image' => '10.96.2.2:5000/runr-builds/fresh:v1']);
        $window = $fresh->deployments()->create(['version_id' => $v1->id, 'status' => 'active', 'ready_at' => now(), 'knative_ready' => true,
            'k8s_job_name' => 'run-fresh-v1', 'deployed_by' => 'dev']);
        $this->seedObject(self::B, self::KSVC, 'fresh', ['runr/project-id' => $fresh->id, 'runr/version' => '1']);
        $this->seedObject(self::B, self::SECRETS, 'fresh-bindings', ['runr/project-id' => $fresh->id, 'runr.dev/bindings' => 'true']);
        $this->seedObject(self::B, self::PVCS, 'fresh-files', ['runr/project-id' => $fresh->id]);

        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh(), (string) $this->operator->id);

        $this->assertSame('shut_down', $window->fresh()->status);
        $this->assertStringStartsWith(RegionMigrateService::NOT_RESTORED, (string) $window->fresh()->knative_message);
        $this->assertContains('DELETE '.self::A.' '.self::KSVC.'/fresh', $this->calls, 'removed from the restored cluster like a refused project');
        $this->assertFalse($this->hasObject(self::B, self::KSVC, 'fresh'), 'and from the cluster the rollback left');
        $this->assertTrue($this->hasObject(self::B, self::SECRETS, 'fresh-bindings'), 'its data service credentials stay');
        $this->assertTrue($this->hasObject(self::B, self::PVCS, 'fresh-files'));
    }

    public function test_a_cluster_that_does_not_answer_leaves_the_rollback_done_and_the_operator_told(): void
    {
        [$orders] = $this->runningProject('orders');
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');
        $this->down = [self::B];

        Carbon::setTestNow('2026-10-19 11:00:00');
        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh(), (string) $this->operator->id);

        $region = $this->region->fresh();
        $this->assertNull($region->migration_status);
        $this->assertStringContainsString(self::A, (string) $region->kubeconfig);
        $this->assertNull($region->migration_old_kubeconfig);
        $live = $orders->deployments()->whereIn('status', Deployment::LIVE)->sole();
        $this->assertSame(['active', '2026-10-19 11:00:00'], [$live->status, $live->ready_at?->toDateTimeString()], 'deployed on the restored cluster again');

        $notice = Notification::query()->where('user_id', $this->operator->id)->sole();
        $this->assertSame(['region.left_cluster_cleanup_failed', 'critical', 'Left cluster cleanup of eu failed'], [$notice->type, $notice->level, $notice->title]);
        $this->assertStringStartsWith('the cluster the rollback left does not answer: cURL error 7', (string) $notice->body);
        $this->assertStringContainsString('remove them yourself: the Knative Services, CronJobs and queue consumers labelled runr/project-id, runr/preview-of or runr/environment-of'
            .' with the region\'s projects in the namespace fn of the cluster the rollback left', (string) $notice->body);
    }

    public function test_another_address_of_the_restored_cluster_keeps_what_runs_there(): void
    {
        $this->runningProject('orders');
        // Another API server address, the same cluster (its kube-system namespace)
        $this->clusterIds['alias.cluster.test'] = 'uid-'.self::A;
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000', 'https://alias.cluster.test:6443');
        $this->assertTrue($this->hasObject('alias.cluster.test', self::KSVC, 'orders'));

        app(RegionMigrateService::class)->rollbackMigration($this->region->fresh(), (string) $this->operator->id);

        $this->assertSame([], array_values(array_filter($this->calls, fn (string $call) => str_starts_with($call, 'DELETE alias.cluster.test '.self::KSVC))));
        $notice = Notification::query()->where('user_id', $this->operator->id)->sole();
        $this->assertSame(['region.left_cluster_cleaned', 'Left cluster cleanup of eu: nothing removed'], [$notice->type, $notice->title]);
        $this->assertStringContainsString('is the region\'s cluster (the same kube-system namespace)', (string) $notice->body);
    }

    public function test_a_move_that_never_left_the_old_cluster_removes_nothing(): void
    {
        $this->runningProject('orders');
        // Failed before it switched: the region is still on its old cluster
        $this->region->update(['migration_status' => 'failed', 'migration_old_kubeconfig' => $this->kubeconfig(self::OLD)]);

        $queued = app(RegionMigrateService::class)->rollbackMigration($this->region->fresh(), (string) $this->operator->id);

        $this->assertFalse($queued);
        $this->assertNotContains('GET '.self::A.' /api/v1/namespaces/kube-system', $this->calls);
        $this->assertNotContains('DELETE '.self::A.' '.self::KSVC.'/orders', $this->calls, 'it runs there');
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_the_rollback_answer_says_the_new_cluster_is_cleaned_up_in_the_background(): void
    {
        $owner = $this->userWithRole('owner');
        $this->runningProject('orders');
        $this->migrateRegion($this->region->fresh(), '10.96.2.2:5000');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/knative-regions/{$this->region->id}/rollback-migration")
            ->assertOk()
            ->assertJsonPath('message', 'Migration rolled back. The project functions the move deployed on the new cluster are removed from it in the background (its installs and data services stay); a notification tells how it went.');

        $this->assertFalse($this->hasObject(self::B, self::KSVC, 'orders'));
        $this->assertSame('Left cluster cleanup of eu: removed 1 project workload',
            Notification::query()->where('user_id', $owner->id)->where('type', 'region.left_cluster_cleaned')->sole()->title);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A CaaS project running since 09:00 on the old cluster — its Knative Service is there.
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
        $this->services[self::A][] = $project->slug;

        return [$project->fresh(), $deployment];
    }
}
