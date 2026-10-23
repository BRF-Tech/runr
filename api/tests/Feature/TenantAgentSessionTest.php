<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\FaasConfig;
use App\Models\Git\PreviewEnvironment;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitDeploy\PreviewManifests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantAwareAgentMessageHandler;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Tests\Concerns\WiresTenancy;
use Tests\TestCase;

/**
 * Multi-tenant installs: the region agent reports pods of tenant projects ({name}-{tenant slug}).
 * TenantAwareAgentMessageHandler finds the project in the tenant's schema and handles the
 * message there — the deployment sessions land in the tenant's deployment_sessions (where
 * UsageAggregationJob meters them), never in the central schema.
 */
class TenantAgentSessionTest extends TestCase
{
    use RefreshDatabase, WiresTenancy;

    private const TENANT_MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0002_create_knative_regions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0005_add_migration_fields_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0006_add_sandbox_and_kata_hypervisor_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0008_add_region_status_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0014_create_deployment_sessions_table.php',
        'Modules/Tenancy/Database/TenantMigrations/0020_add_created_by_to_projects.php',
        'Modules/Tenancy/Database/TenantMigrations/0022_add_agent_token_hash_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0023_add_observability_managed_flags_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0024_add_function_tls_to_knative_regions.php',
        'Modules/Tenancy/Database/TenantMigrations/0035_create_git_deploy_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0074_add_last_seen_at_to_deployment_sessions.php',
        'Modules/Tenancy/Database/TenantMigrations/0075_add_preview_sessions_to_deployment_sessions.php',
    ];

    private KnativeRegion $region;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);

        $this->region = KnativeRegion::create(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);

        // a tenant slug with a dash: the project slug "orders-acme-eu" ends with it
        $this->tenant = $this->createTenantWithSchema('acme-eu', self::TENANT_MIGRATIONS);
        $this->tenant->run(function () {
            $copy = new KnativeRegion(['name' => 'op', 'project_namespace' => 'op', 'is_active' => true]);
            $copy->forceFill(['id' => $this->region->id, 'is_synced' => true])->save();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function handler(): TenantAwareAgentMessageHandler
    {
        return app(TenantAwareAgentMessageHandler::class);
    }

    /** A FaaS project with an active deployment, created in the current schema. */
    private function project(string $slug): Project
    {
        $project = Project::create(['name' => 'orders', 'service_type' => 'faas']);
        $project->forceFill(['slug' => $slug, 'knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $project->id, 'handler' => 'main.handler']);
        $version = Version::create(['project_id' => $project->id, 'number' => 1, 'created_by' => 'dev', 'build_status' => 'ready']);
        Deployment::create(['project_id' => $project->id, 'version_id' => $version->id, 'status' => 'active']);

        return $project;
    }

    private function send(string $type, string $slug, string $pod, array $extra = []): void
    {
        $this->handler()->handle(array_merge([
            'type' => $type,
            'project_slug' => $slug,
            'entry_point' => 'http',
            'pod' => $pod,
            'cold_start' => true,
        ], $extra), $this->region->id);
    }

    /**
     * The tenant schema's sessions, read inside the tenant context (a model's dates need its
     * connection, which ends with the context).
     *
     * @return list<array{project_id: string, ended_at: ?string, duration_ms: ?int}>
     */
    private function tenantSessions(): array
    {
        return $this->tenant->run(fn () => DeploymentSession::query()->orderBy('started_at')->get()
            ->map(fn (DeploymentSession $s) => ['project_id' => $s->project_id, 'ended_at' => $s->ended_at?->toIso8601String(), 'duration_ms' => $s->duration_ms])
            ->all());
    }

    public function test_sessions_of_a_tenant_project_are_written_to_the_tenant_schema(): void
    {
        $projectId = $this->tenant->run(fn () => $this->project('orders-acme-eu')->id);

        $this->send('session_start', 'orders-acme-eu', 'orders-acme-eu-00001-deployment-abc');

        $sessions = $this->tenantSessions();
        $this->assertCount(1, $sessions);
        $this->assertSame($projectId, $sessions[0]['project_id']);
        $this->assertNull($sessions[0]['ended_at']);
        $this->assertSame(0, DeploymentSession::query()->count(), 'nothing in the central schema');

        // the same pod again (agent restart): the open session continues
        $this->send('session_start', 'orders-acme-eu', 'orders-acme-eu-00001-deployment-abc');
        $this->assertCount(1, $this->tenantSessions());

        // the end — also when the cache entry is gone — ends it in the tenant schema
        Cache::flush();
        $this->send('session_end', 'orders-acme-eu', 'orders-acme-eu-00001-deployment-abc');
        $sessions = $this->tenantSessions();
        $this->assertNotNull($sessions[0]['ended_at']);
        $this->assertNotNull($sessions[0]['duration_ms']);
        $this->assertSame(0, DeploymentSession::query()->count());
    }

    public function test_a_heartbeat_marks_the_session_in_the_tenant_schema(): void
    {
        $this->tenant->run(fn () => $this->project('orders-acme-eu'));
        $this->send('session_start', 'orders-acme-eu', 'orders-acme-eu-00001-deployment-abc');

        $this->travel(1)->minutes();
        $this->handler()->handle(['type' => 'session_heartbeat', 'sessions' => [
            ['project_slug' => 'orders-acme-eu', 'pod' => 'orders-acme-eu-00001-deployment-abc', 'entry_point' => 'http'],
        ]], $this->region->id);

        $seen = $this->tenant->run(fn () => DeploymentSession::query()->sole()->last_seen_at?->toDateTimeString());
        $this->assertSame(now()->toDateTimeString(), $seen);
        $this->assertSame(0, DeploymentSession::query()->count(), 'nothing in the central schema');
    }

    public function test_metrics_of_a_tenant_project_are_found_in_the_tenant_schema(): void
    {
        // Without the cache bootstrapper: every tenancy session would get a fresh array store
        // here (Redis in an install keeps one store, tagged per tenant)
        config(['tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class]]);
        $projectId = $this->tenant->run(fn () => $this->project('orders-acme-eu')->id);

        $this->send('metrics', 'orders-acme-eu', 'p', ['data' => ['cpu' => 120, 'memory' => 64, 'pod_count' => 2]]);

        $this->assertSame(2, cache()->get("agent_metrics_{$projectId}")['pod_count'] ?? null);
    }

    public function test_central_projects_keep_their_sessions_in_the_central_schema(): void
    {
        $this->project('orders');

        $this->send('session_start', 'orders', 'orders-00001-deployment-abc');

        $this->assertSame(1, DeploymentSession::query()->count());
        $this->assertSame([], $this->tenantSessions());
    }

    public function test_unknown_projects_and_tenants_are_ignored(): void
    {
        $this->tenant->run(fn () => $this->project('orders-acme-eu'));

        $this->send('session_start', 'orders-globex', 'orders-globex-00001-deployment-abc');   // no such tenant
        $this->send('session_start', 'billing-acme-eu', 'billing-acme-eu-00001-deployment-abc'); // no such project
        $this->send('session_start', 'orders', 'orders-00001-deployment-abc');                 // no such central project

        $this->assertSame([], $this->tenantSessions());
        $this->assertSame(0, DeploymentSession::query()->count());
    }

    /**
     * A preview of a project with a slug of an earlier release, longer than <slug>-pr-<number>
     * has room for: its Service name lost the end of the slug, the tenant's slug with it.
     *
     * @return array{0: string, 1: string, 2: string} project id, preview id, the preview's Service
     */
    private function longSlugPreview(): array
    {
        return $this->tenant->run(function () {
            $project = $this->project('orders-of-the-very-long-department-of-reconciliations-acme-eu');
            $service = PreviewManifests::serviceName($project->slug, 7);
            $preview = PreviewEnvironment::query()->create(['project_id' => $project->id, 'number' => 7, 'provider' => 'github',
                'status' => PreviewEnvironment::READY, 'service_name' => $service]);

            return [(string) $project->id, (string) $preview->id, $service];
        });
    }

    public function test_a_preview_that_lost_the_tenants_slug_is_found_by_its_labels(): void
    {
        [$projectId, $previewId, $service] = $this->longSlugPreview();
        $this->assertStringEndsWith('-acme-pr-7', $service, 'the tenant slug "acme-eu" was cut');
        $shortened = substr($service, 0, -strlen('-pr-7'));

        // runr/tenant and runr/preview-of on the pod: the agent names the tenant and the project
        $this->send('session_start', $shortened, "{$service}-00001-deployment-abc", ['project_id' => $projectId, 'preview' => $service, 'tenant' => 'acme-eu']);
        $sessions = $this->tenant->run(fn () => DeploymentSession::query()->get()
            ->map(fn (DeploymentSession $s) => [(string) $s->project_id, (string) $s->preview_environment_id])->all());
        $this->assertSame([[$projectId, $previewId]], $sessions);
        $this->assertSame(0, DeploymentSession::query()->count(), 'nothing in the central schema');

        // A pod of a preview deployed before its pods carried runr/tenant: found by the project id,
        // the tenant remembered for the next events
        $this->send('session_start', $shortened, "{$service}-00002-deployment-def", ['project_id' => $projectId, 'preview' => $service]);
        $this->assertSame(2, $this->tenant->run(fn () => DeploymentSession::query()->where('preview_environment_id', $previewId)->count()));
        $this->assertSame((string) $this->tenant->id, Cache::get("agent-project-tenant:{$projectId}"));
        Cache::forget("agent_session_{$service}-00002-deployment-def");
        $this->send('session_end', $shortened, "{$service}-00002-deployment-def", ['project_id' => $projectId]);
        $this->assertSame(1, $this->tenant->run(fn () => DeploymentSession::query()->whereNotNull('ended_at')->count()));

        // A preview created now keeps the organization's end of the long slug: its tenant is found
        // by the name again, the project by its id
        $named = $this->tenant->run(function () use ($projectId) {
            $service = PreviewManifests::serviceName(Project::query()->findOrFail($projectId)->slug, 8, 'acme-eu');
            PreviewEnvironment::query()->create(['project_id' => $projectId, 'number' => 8, 'provider' => 'github',
                'status' => PreviewEnvironment::READY, 'service_name' => $service]);

            return $service;
        });
        $this->assertStringEndsWith('-acme-eu-pr-8', $named);
        Cache::flush();
        $this->send('session_start', substr($named, 0, -strlen('-pr-8')), "{$named}-00001-deployment-ghi", ['project_id' => $projectId, 'preview' => $named]);
        $this->assertSame(1, $this->tenant->run(fn () => DeploymentSession::query()->where('metadata->pod', "{$named}-00001-deployment-ghi")->count()));
        $this->assertNull(Cache::get("agent-project-tenant:{$projectId}"), 'found by the slug\'s end, no search');

        // A project id no tenant has is remembered as unknown
        $this->send('session_start', 'nobody', 'nobody-pr-1-00001-deployment-x', ['project_id' => '01JNOSUCHPROJECT0000000000', 'preview' => 'nobody-pr-1']);
        $this->assertSame('', Cache::get('agent-project-tenant:01JNOSUCHPROJECT0000000000'));
        $this->assertSame(3, $this->tenant->run(fn () => DeploymentSession::query()->count()));
    }
}
