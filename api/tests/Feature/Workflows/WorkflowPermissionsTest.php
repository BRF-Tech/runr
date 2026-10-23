<?php

namespace Tests\Feature\Workflows;

use App\Providers\WorkflowServiceProvider;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\RoutePermissions;
use App\Workflows\Models\WorkflowRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

/**
 * Workflow permissions in the RBAC catalog (config/permissions.php), the built-in role matrix,
 * the route rules (config/route_permissions.php) and enforcement per project.
 */
class WorkflowPermissionsTest extends TestCase
{
    use ActsWithRoles;
    use RefreshDatabase;
    use RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userWithRole('owner'); // the first user of the install
        $this->setUpWorkflows();
    }

    public function test_catalog_entries_scopes_and_implications(): void
    {
        $catalog = config('permissions.permissions');

        foreach (['workflows.view', 'workflows.run', 'workflows.manage'] as $p) {
            $this->assertSame('workflows', $catalog[$p]['group']);
            $this->assertSame('project', $catalog[$p]['scope'], $p);
        }
        $this->assertSame('org', $catalog['events.send']['scope']);
        $this->assertSame(['projects.view', 'workflows.view', 'workflows.run'], PermissionCatalog::expand(['workflows.run']));
        $this->assertContains('workflows.view', PermissionCatalog::expand(['workflows.manage']));
    }

    public function test_built_in_role_matrix(): void
    {
        $roles = PermissionCatalog::builtinRoles();

        foreach (WorkflowServiceProvider::PERMISSIONS as $p) {
            $this->assertContains($p, $roles['owner']['permissions']);
            $this->assertContains($p, $roles['admin']['permissions']);
            $this->assertContains($p, $roles['developer']['permissions']);
            $this->assertContains($p, config('permissions.legacy_member_role.permissions'));
        }
        $this->assertContains('workflows.view', $roles['viewer']['permissions']);
        foreach (['workflows.run', 'workflows.manage', 'events.send'] as $p) {
            $this->assertNotContains($p, $roles['viewer']['permissions'], "viewer must not hold {$p}");
        }
    }

    public function test_every_workflow_route_has_a_rule(): void
    {
        $count = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->getActionName(), 'App\\Workflows\\Http\\Controllers\\')) {
                continue;
            }
            if (! RoutePermissions::requiresAuthentication($route)) {
                $this->assertStringStartsWith('api/internal/workflows/queue/', $route->uri(), 'only the signed queue bridge is unauthenticated');

                continue;
            }
            $count++;
            $this->assertNotNull(RoutePermissions::requirement($route), implode(' / ', RoutePermissions::keys($route)).' has no rule');
        }

        $this->assertSame(23, $count);
    }

    public function test_a_viewer_sees_runs_but_cannot_start_cancel_or_manage(): void
    {
        $workflow = $this->defineWorkflow('guarded', fn () => 'ok');
        $run = $this->startRun($workflow);
        $viewer = $this->userWithRole('viewer');
        $base = "/api/projects/{$this->project->id}";

        $this->signedIn($viewer)->getJson("{$base}/workflows")->assertOk();
        $this->signedIn($viewer)->getJson("{$base}/workflow-runs/{$run->id}")->assertOk();
        $this->signedIn($viewer)->getJson('/api/events')->assertOk();

        $this->signedIn($viewer)->postJson("{$base}/workflows/guarded/runs", [])->assertForbidden();
        $this->signedIn($viewer)->postJson("{$base}/workflow-runs/{$run->id}/cancel")->assertForbidden();
        $this->signedIn($viewer)->postJson("{$base}/workflows/sync")->assertForbidden();
        $this->signedIn($viewer)->putJson("{$base}/workflows/guarded", ['triggers' => []])->assertForbidden();
        $this->signedIn($viewer)->deleteJson("{$base}/workflows/guarded")->assertForbidden();
        $this->signedIn($viewer)->getJson("{$base}/workflows/app/signing-key")->assertForbidden();
        $this->signedIn($viewer)->postJson('/api/events', ['name' => 'a.b', 'data' => []])->assertForbidden();

        $this->assertSame(WorkflowRun::QUEUED, $run->fresh()->status);
    }

    public function test_a_developer_runs_and_manages_workflows(): void
    {
        $this->defineWorkflow('dev-flow', fn () => 'ok');
        $developer = $this->userWithRole('developer');
        $base = "/api/projects/{$this->project->id}";

        $this->signedIn($developer)->postJson("{$base}/workflows/dev-flow/runs", ['data' => ['x' => 1]])->assertCreated();
        $this->signedIn($developer)->patchJson("{$base}/workflows/dev-flow", ['is_active' => false])->assertOk();
        $this->signedIn($developer)->postJson('/api/events', ['name' => 'dev.event', 'data' => []])->assertOk();
    }

    public function test_project_access_grants_workflows_on_that_project_only(): void
    {
        $this->defineWorkflow('scoped', fn () => 'ok');
        $other = $this->makeWorkflowProject('other-wf');
        $member = $this->userWithRole('viewer');
        $this->grantProject($member, $this->project, 'developer');

        $this->signedIn($member)->postJson("/api/projects/{$this->project->id}/workflows/scoped/runs", [])->assertCreated();
        $this->signedIn($member)->postJson("/api/projects/{$other->id}/workflows/scoped/runs", [])->assertForbidden();
    }
}
