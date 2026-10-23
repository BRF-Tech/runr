<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * whoami, projects_list, project_get, project_create, project_update, project_delete — through
 * POST /mcp, so the API routes behind them authorize, validate and audit.
 */
class ProjectToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    // ── whoami ─────────────────────────────────────────────────────────────────

    public function test_whoami_names_the_user_and_the_scope_of_the_connection(): void
    {
        $dev = $this->userWithRole('developer', ['name' => 'Dana']);

        $me = $this->structured($this->callTool('whoami', [], $dev, ['projects.view', 'logs.view']));

        $this->assertSame('Dana', $me['user']['name']);
        $this->assertSame('developer', $me['role']);
        $this->assertTrue($me['token_scoped']);
        $this->assertEqualsCanonicalizing(['projects.view', 'logs.view'], $me['permissions']);
    }

    public function test_whoami_takes_no_arguments(): void
    {
        $this->assertSchemaRejects('whoami', ['verbose' => true], '/verbose');
    }

    // ── projects_list / project_get ───────────────────────────────────────────

    public function test_projects_are_listed_concisely(): void
    {
        $this->containerProject('shop');
        $this->faasProject('orders');

        $result = $this->structured($this->callTool('projects_list'));

        $this->assertSame(2, $result['count']);
        $names = array_column($result['projects'], 'name');
        $this->assertEqualsCanonicalizing(['shop', 'orders'], $names);
        $this->assertArrayNotHasKey('config', $result['projects'][0], 'the list stays concise');

        $filtered = $this->structured($this->callTool('projects_list', ['service_type' => 'faas']));
        $this->assertSame(['orders'], array_column($filtered['projects'], 'name'));
    }

    public function test_project_access_limits_the_list(): void
    {
        $shop = $this->containerProject('shop');
        $this->containerProject('billing');
        $this->customRole('outsider', ['tokens.manage']);
        $outsider = $this->userWithRole('outsider');
        $this->grantProject($outsider, $shop, 'viewer');

        $result = $this->structured($this->callTool('projects_list', [], $outsider));

        $this->assertSame(['shop'], array_column($result['projects'], 'name'));
    }

    public function test_projects_list_needs_projects_view(): void
    {
        $this->assertToolHidden('projects_list', ['regions.view']);
    }

    public function test_projects_list_validates_its_arguments(): void
    {
        $this->assertSchemaRejects('projects_list', ['service_type' => 'maas'], '/service_type');
        $this->assertSchemaRejects('projects_list', ['limit' => 0], '/limit');
    }

    public function test_a_project_is_shown_by_id_slug_or_name_with_env_names_only(): void
    {
        $project = $this->containerProject('shop', ['API_KEY' => 'value-do-not-leak']);

        foreach ([$project->id, $project->slug, 'shop'] as $reference) {
            $result = $this->structured($this->callTool('project_get', ['project' => $reference]));
            $this->assertSame($project->id, $result['id']);
            $this->assertSame(['API_KEY'], $result['env_names']);
            $this->assertSame('nginx:1.27-alpine', $result['config']['image']);
        }

        $this->assertStringNotContainsString('value-do-not-leak', json_encode($this->callTool('project_get', ['project' => 'shop'])));
    }

    public function test_an_unknown_or_invisible_project_is_a_tool_error(): void
    {
        $this->containerProject('shop');
        $other = $this->containerProject('other');

        $error = $this->mcpToolError($this->callTool('project_get', ['project' => 'nope']));
        $this->assertStringContainsString('projects_list', $error['message']);

        // Access to another project only: "shop" is not found for this caller
        $this->customRole('outsider', ['tokens.manage']);
        $outsider = $this->userWithRole('outsider');
        $this->grantProject($outsider, $other, 'viewer');

        $this->mcpToolError($this->callTool('project_get', ['project' => 'shop'], $outsider));
    }

    public function test_project_get_requires_the_project_argument(): void
    {
        $this->assertSchemaRejects('project_get', [], '/project');
        $this->assertToolHidden('project_get', ['regions.view']);
    }

    // ── project_create ─────────────────────────────────────────────────────────

    public function test_a_caas_project_is_created_in_the_only_region(): void
    {
        $result = $this->structured($this->callTool('project_create', [
            'name' => 'api-gateway',
            'service_type' => 'caas',
            'image' => 'ghcr.io/acme/api:1.2',
            'port' => 9000,
            'env' => ['MODE' => 'prod'],
        ]));

        $this->assertSame('api-gateway', $result['project']['name']);
        $this->assertSame($this->region->id, $result['project']['region_id']);

        $project = Project::where('name', 'api-gateway')->sole();
        $this->assertSame('ghcr.io/acme/api:1.2', $project->containerConfig->image);
        $this->assertSame([9000], $project->containerConfig->ports);
        $this->assertSame(['MODE' => 'prod'], $project->containerConfig->env_vars);
        $this->assertSame((string) $this->owner->id, (string) $project->created_by);

        // The route's own audit event, marked as coming through MCP
        $event = AuditEvent::where('action', 'project.create')->latest('occurred_at')->firstOrFail();
        $this->assertStringStartsWith('mcp:', (string) $event->auth_method);
        $this->assertSame('project_create', $event->metadata['mcp_tool'] ?? null);

        $call = AuditEvent::where('action', 'mcp.tool.call')->latest('occurred_at')->firstOrFail();
        $this->assertSame('project_create', $call->metadata['tool'] ?? null);
        $this->assertContains('image', $call->metadata['arguments'] ?? []);
        $this->assertStringNotContainsString('prod', json_encode($call->metadata), 'argument values are not audited');
    }

    public function test_a_faas_project_is_created_on_a_runtime(): void
    {
        $this->executorImages();

        $result = $this->structured($this->callTool('project_create', [
            'name' => 'orders', 'service_type' => 'faas', 'runtime' => 'python', 'region' => 'op', 'trigger' => 'http',
        ]));

        $this->assertSame('python', $result['project']['runtime']);
        $this->assertSame('faas', Project::where('name', 'orders')->sole()->service_type);
    }

    public function test_project_create_needs_projects_create(): void
    {
        $this->assertToolHidden('project_create', ['projects.view', 'projects.update']);
    }

    public function test_project_create_validation(): void
    {
        $this->assertSchemaRejects('project_create', ['name' => 'x'], '/service_type');
        $this->assertSchemaRejects('project_create', ['name' => 'x', 'service_type' => 'maas'], '/service_type');

        // The API's own rules: a caas project needs its image (checked by the tool first) …
        $this->mcpToolError($this->callTool('project_create', ['name' => 'img-less', 'service_type' => 'caas']));

        // … and a taken name is the route's 422
        $this->containerProject('shop');
        $error = $this->mcpToolError($this->callTool('project_create', ['name' => 'shop', 'service_type' => 'caas', 'image' => 'nginx']));
        $this->assertSame(422, $error['status']);
        $this->assertArrayHasKey('name', $error['errors']);
    }

    public function test_an_unknown_region_name_is_explained(): void
    {
        $error = $this->mcpToolError($this->callTool('project_create', ['name' => 'x', 'service_type' => 'caas', 'image' => 'nginx', 'region' => 'mars']));

        $this->assertStringContainsString('regions_list', $error['message']);
    }

    // ── project_update ─────────────────────────────────────────────────────────

    public function test_a_project_is_resized_and_described(): void
    {
        $project = $this->containerProject('shop');

        $result = $this->structured($this->callTool('project_update', [
            'project' => 'shop', 'description' => 'storefront', 'memory_mb' => 512, 'max_scale' => 4,
        ]));

        $this->assertEqualsCanonicalizing(['description', 'memory_mb', 'max_scale'], $result['changed']);
        $project->refresh();
        $this->assertSame('storefront', $project->description);
        $this->assertSame(512, $project->containerConfig->memory_mb);
        $this->assertSame(4, $project->containerConfig->max_scale);
    }

    public function test_project_update_needs_a_change_and_valid_values(): void
    {
        $this->containerProject('shop');

        $this->mcpToolError($this->callTool('project_update', ['project' => 'shop']));
        $this->assertSchemaRejects('project_update', ['project' => 'shop', 'memory_mb' => 1], '/memory_mb');
        $this->assertSchemaRejects('project_update', ['project' => 'shop', 'env' => ['A' => 'b']], '/env');
    }

    // ── Timed warmer (config.scale_schedule) ───────────────────────────────────

    public function test_a_project_is_created_with_a_timed_warmer(): void
    {
        $this->structured($this->callTool('project_create', [
            'name' => 'office-api',
            'service_type' => 'caas',
            'image' => 'ghcr.io/acme/api:1.2',
            'max_scale' => 5,
            'scale_schedule' => [
                ['cron' => '0 9 * * 1-5', 'min_scale' => 3],
                ['cron' => ' 0 18 * * 1-5 ', 'min_scale' => 0],
            ],
        ]));

        $config = Project::where('name', 'office-api')->sole()->containerConfig;
        $this->assertSame([
            ['cron' => '0 9 * * 1-5', 'min_scale' => 3],
            ['cron' => '0 18 * * 1-5', 'min_scale' => 0],
        ], $config->scale_schedule);
        $this->assertSame(5, $config->max_scale);
    }

    public function test_the_timed_warmer_is_set_shown_and_turned_off(): void
    {
        $project = $this->containerProject('shop');
        $project->containerConfig->update(['max_scale' => 4]);

        $rows = [['cron' => '30 8 * * 1-5', 'min_scale' => 2], ['cron' => '0 20 * * *', 'min_scale' => 0]];
        $result = $this->callTool('project_update', ['project' => 'shop', 'scale_schedule' => $rows]);
        $changed = $this->structured($result);

        $this->assertSame(['scale_schedule'], $changed['changed']);
        $this->assertStringContainsString('within a minute', json_encode($result), 'a schedule alone needs no deploy');
        $this->assertSame($rows, $project->fresh()->containerConfig->scale_schedule);

        $shown = $this->structured($this->callTool('project_get', ['project' => 'shop']));
        $this->assertSame($rows, $shown['config']['scale_schedule']);

        // [] and null both turn it off
        $this->structured($this->callTool('project_update', ['project' => 'shop', 'scale_schedule' => []]));
        $this->assertNull($project->fresh()->containerConfig->scale_schedule);

        $this->structured($this->callTool('project_update', ['project' => 'shop', 'scale_schedule' => $rows]));
        $this->structured($this->callTool('project_update', ['project' => 'shop', 'scale_schedule' => null]));
        $this->assertNull($project->fresh()->containerConfig->scale_schedule);
        $this->assertArrayNotHasKey('scale_schedule', $this->structured($this->callTool('project_get', ['project' => 'shop']))['config']);
    }

    public function test_timed_warmer_rows_are_checked_by_the_schema_and_the_api(): void
    {
        $project = $this->containerProject('shop');
        $project->containerConfig->update(['max_scale' => 2]);

        // The tool's schema: both fields, a count of 0-10, at most 10 rows
        $this->assertSchemaRejects('project_update', ['project' => 'shop', 'scale_schedule' => [['cron' => '0 9 * * *']]], '/scale_schedule/0/min_scale');
        $this->assertSchemaRejects('project_update', ['project' => 'shop', 'scale_schedule' => [['cron' => '0 9 * * *', 'min_scale' => 11]]], '/scale_schedule/0/min_scale');
        $this->assertSchemaRejects('project_update', ['project' => 'shop', 'scale_schedule' => array_fill(0, 11, ['cron' => '0 9 * * *', 'min_scale' => 1])], '/scale_schedule');

        // The API's rules: a five-field cron expression, a count within max_scale
        foreach ([
            [['cron' => 'every morning', 'min_scale' => 1]],
            [['cron' => '0 0 9 * * 1-5', 'min_scale' => 1]],   // six fields (seconds)
            [['cron' => '@midnight', 'min_scale' => 1]],       // macros are not schedule rows (a shorter one fails the schema's minLength)
            [['cron' => '0 9 * * 1-5', 'min_scale' => 3]],     // above max_scale 2
        ] as $rows) {
            $error = $this->mcpToolError($this->callTool('project_update', ['project' => 'shop', 'scale_schedule' => $rows]));
            $this->assertSame(422, $error['status'], json_encode($rows));
            $this->assertNotEmpty(array_filter(array_keys($error['errors'] ?? []), fn ($k) => str_starts_with((string) $k, 'config.scale_schedule')), json_encode($error));
        }
        $this->assertNull($project->fresh()->containerConfig->scale_schedule);
    }

    public function test_project_update_is_refused_without_projects_update_on_the_project(): void
    {
        $this->containerProject('shop');
        $viewer = $this->userWithRole('viewer');

        $this->assertToolHidden('project_update', ['projects.view'], ['project' => 'shop'], $viewer);
    }

    // ── project_delete ─────────────────────────────────────────────────────────

    public function test_a_project_is_deleted_only_with_its_name_confirmed(): void
    {
        $project = $this->containerProject('shop');

        $error = $this->mcpToolError($this->callTool('project_delete', ['project' => 'shop', 'confirm_name' => 'shop-typo']));
        $this->assertStringContainsString('Nothing was deleted', $error['message']);
        $this->assertNotNull(Project::find($project->id));

        $result = $this->structured($this->callTool('project_delete', ['project' => $project->id, 'confirm_name' => 'shop']));
        $this->assertSame($project->id, $result['deleted']['id']);
        $this->assertNull(Project::find($project->id));
        $this->assertTrue(AuditEvent::where('action', 'project.delete')->where('target_id', $project->id)->exists());
    }

    public function test_project_delete_requires_confirmation_and_the_permission(): void
    {
        $this->containerProject('shop');

        $this->assertSchemaRejects('project_delete', ['project' => 'shop'], '/confirm_name');
        $this->assertToolHidden('project_delete', ['projects.view', 'projects.update'], ['project' => 'shop', 'confirm_name' => 'shop']);
    }

    public function test_a_project_role_without_delete_is_refused_by_the_route(): void
    {
        $project = $this->containerProject('shop');
        $other = $this->containerProject('other');
        $this->customRole('outsider', ['tokens.manage']);
        $outsider = $this->userWithRole('outsider');
        $this->grantProject($outsider, $other, 'admin');   // projects.delete on another project only
        $this->grantProject($outsider, $project, 'viewer');

        $error = $this->mcpToolError($this->callTool('project_delete', ['project' => 'shop', 'confirm_name' => 'shop'], $outsider));

        $this->assertSame(403, $error['status']);
        $this->assertContains('projects.delete', $error['permissions'] ?? []);
        $this->assertNotNull(Project::find($project->id));
    }
}
