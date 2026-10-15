<?php

namespace Tests\Feature\Workflows;

use App\Models\ContainerConfig;
use App\Models\Project;
use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Engine\EventIngestor;
use App\Workflows\Engine\WorkflowRegistry;
use App\Workflows\Models\WorkflowApp;
use App\Workflows\Models\WorkflowEvent;
use App\Workflows\Models\WorkflowRun;
use App\Workflows\Support\WorkflowContexts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Tenancy\Models\Tenant;
use Tests\Concerns\WiresTenancy;
use Tests\Support\Workflows\RecordingDispatcher;
use Tests\TestCase;

/**
 * Per-tenant scoping: workflow tables live in every tenant schema; events of one tenant never
 * reach another tenant's workflows; engine housekeeping visits central + every tenant; the
 * queue bridge resolves the tenant from its URL.
 */
class WorkflowTenancyTest extends TestCase
{
    use RefreshDatabase;
    use WiresTenancy;

    private const MIGRATIONS = [
        'Modules/Tenancy/Database/TenantMigrations/0001_create_tenant_tables.php',
        'Modules/Tenancy/Database/TenantMigrations/0040_create_workflow_tables.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenancy();
        config(['tenancy.enabled' => true]);
        $this->app->instance(WorkflowDispatcher::class, new RecordingDispatcher);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenancy();
        parent::tearDown();
    }

    private function tenantWithWorkflow(string $slug): array
    {
        $tenant = $this->createTenantWithSchema($slug, self::MIGRATIONS);

        $projectId = $tenant->run(function () {
            $project = Project::create(['name' => 'wf', 'service_type' => 'caas']);
            ContainerConfig::create([
                'project_id' => $project->id, 'image' => 'x:1', 'trigger_type' => 'workflow', 'queue_stream' => 'jobs',
            ]);
            WorkflowApp::forProject($project);
            app(WorkflowRegistry::class)->declare($project, [['name' => 'on-signup', 'triggers' => [['event' => 'user.signup'], ['queue' => 'jobs']]]]);

            return $project->id;
        });

        return [$tenant, $projectId];
    }

    public function test_events_only_reach_workflows_of_their_tenant(): void
    {
        [$acme] = $this->tenantWithWorkflow('acme');
        [$globex] = $this->tenantWithWorkflow('globex');

        $acme->run(fn () => app(EventIngestor::class)->ingest([['name' => 'user.signup', 'data' => ['id' => 1]]]));

        $this->assertSame(1, $acme->run(fn () => WorkflowRun::count()));
        $this->assertSame(0, $globex->run(fn () => WorkflowRun::count()));
        $this->assertSame(0, $globex->run(fn () => WorkflowEvent::count()));
    }

    public function test_contexts_visit_central_and_every_active_tenant(): void
    {
        [$acme] = $this->tenantWithWorkflow('acme');
        [$globex] = $this->tenantWithWorkflow('globex');

        $seen = [];
        WorkflowContexts::each(function (string $key) use (&$seen) {
            $seen[] = $key;
        });

        $this->assertSame([WorkflowContexts::CENTRAL, (string) $acme->getTenantKey(), (string) $globex->getTenantKey()], $seen);
        $this->assertSame((string) $acme->getTenantKey(), $acme->run(fn () => WorkflowContexts::current()));
        $this->assertSame(WorkflowContexts::CENTRAL, WorkflowContexts::current());
    }

    public function test_queue_bridge_url_carries_the_tenant(): void
    {
        [$acme, $projectId] = $this->tenantWithWorkflow('acme');
        [$globex] = $this->tenantWithWorkflow('globex');

        $token = $acme->run(fn () => WorkflowApp::where('project_id', $projectId)->firstOrFail()->queueHookToken());
        $key = (string) $acme->getTenantKey();

        $this->postJson("/api/internal/workflows/queue/{$key}/{$projectId}/{$token}", ['job' => 'resize'], ['ce-id' => '1-0'])->assertOk();

        $this->assertSame('queue/jobs', $acme->run(fn () => WorkflowEvent::sole()->name));
        $this->assertSame(1, $acme->run(fn () => WorkflowRun::count()));

        // The same project id under another tenant does not exist there
        $this->postJson('/api/internal/workflows/queue/'.$globex->getTenantKey()."/{$projectId}/{$token}", ['job' => 'x'])->assertNotFound();
        $this->assertInstanceOf(Tenant::class, $globex);
    }
}
