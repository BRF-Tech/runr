<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\DeploymentSession;
use App\Models\FaasConfig;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\Version;
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
}
