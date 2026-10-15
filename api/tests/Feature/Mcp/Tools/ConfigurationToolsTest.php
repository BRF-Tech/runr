<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * env_list, env_set, env_unset, secret_reveal — plain variables through the project config,
 * secret variables write-only (docs/security.md): their values never leave the API except
 * through secret_reveal, which needs secrets.view_values and is audited.
 */
class ConfigurationToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        $this->project = $this->containerProject('shop', ['MODE' => 'prod', 'LOG_LEVEL' => 'info']);
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN', 'value' => 'tok-do-not-leak']);
    }

    private function plain(): array
    {
        return (array) $this->project->fresh()->containerConfig->env_vars;
    }

    // ── env_list ───────────────────────────────────────────────────────────────

    public function test_names_are_listed_and_secret_values_never(): void
    {
        $result = $this->callTool('env_list', ['project' => 'shop']);
        $data = $this->structured($result);

        $this->assertSame(['LOG_LEVEL', 'MODE'], $data['plain']);
        $this->assertSame(['API_TOKEN'], $data['secret']);
        $this->assertStringNotContainsString('tok-do-not-leak', json_encode($result));
        $this->assertStringNotContainsString('prod', json_encode($result), 'plain values only on request');

        $values = $this->structured($this->callTool('env_list', ['project' => 'shop', 'include_values' => true]));
        $this->assertSame(['MODE' => 'prod', 'LOG_LEVEL' => 'info'], $values['plain']);
    }

    public function test_env_list_needs_env_view(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->assertToolHidden('env_list', ['*'], ['project' => 'shop'], $viewer);
        $this->assertSchemaRejects('env_list', ['project' => 'shop', 'include_values' => 'yes'], '/include_values');
    }

    // ── env_set ────────────────────────────────────────────────────────────────

    public function test_plain_variables_are_merged_into_the_config(): void
    {
        $result = $this->structured($this->callTool('env_set', ['project' => 'shop', 'vars' => ['MODE' => 'staging', 'REGION' => 'eu']]));

        $this->assertTrue($result['requires_deploy']);
        $this->assertSame(['MODE' => 'staging', 'LOG_LEVEL' => 'info', 'REGION' => 'eu'], $this->plain());
        $this->assertSame('tok-do-not-leak', ProjectSecret::where('name', 'API_TOKEN')->value('value'), 'secrets untouched');

        $event = AuditEvent::where('action', 'project.update')->latest('occurred_at')->firstOrFail();
        $this->assertStringNotContainsString('staging', json_encode($event->metadata));
    }

    public function test_secret_variables_are_written_and_names_move_between_kinds(): void
    {
        $result = $this->callTool('env_set', ['project' => 'shop', 'vars' => ['DB_PASSWORD' => 'pw-do-not-leak', 'MODE' => 'm'], 'secret' => true]);

        $this->structured($result);
        $this->assertStringNotContainsString('pw-do-not-leak', json_encode($result));
        $this->assertSame('pw-do-not-leak', ProjectSecret::where('name', 'DB_PASSWORD')->value('value'));
        $this->assertSame('m', ProjectSecret::where('name', 'MODE')->value('value'));
        $this->assertSame(['LOG_LEVEL' => 'info'], $this->plain(), 'MODE moved from plain to secret');

        // and back: plain again, the secret is removed
        $this->structured($this->callTool('env_set', ['project' => 'shop', 'vars' => ['MODE' => 'plain-again']]));
        $this->assertNull(ProjectSecret::where('name', 'MODE')->first());
        $this->assertSame('plain-again', $this->plain()['MODE']);
    }

    public function test_setting_the_same_values_changes_nothing(): void
    {
        $result = $this->structured($this->callTool('env_set', ['project' => 'shop', 'vars' => ['MODE' => 'prod']]));

        $this->assertSame(['MODE'], $result['unchanged']);
    }

    public function test_env_set_validation_and_permissions(): void
    {
        $this->assertSchemaRejects('env_set', ['project' => 'shop', 'vars' => ['1BAD' => 'x']]);
        $this->assertSchemaRejects('env_set', ['project' => 'shop', 'vars' => []], '/vars');

        // Reserved names are the route's 422
        $error = $this->mcpToolError($this->callTool('env_set', ['project' => 'shop', 'vars' => ['PORT' => '9000']]));
        $this->assertSame(422, $error['status']);

        $this->assertToolHidden('env_set', ['projects.view', 'env.view']);
    }

    // ── env_unset ──────────────────────────────────────────────────────────────

    public function test_plain_and_secret_variables_are_removed(): void
    {
        $result = $this->structured($this->callTool('env_unset', ['project' => 'shop', 'names' => ['MODE', 'API_TOKEN', 'NEVER_SET']]));

        $this->assertEqualsCanonicalizing(['MODE', 'API_TOKEN'], $result['removed']);
        $this->assertSame(['NEVER_SET'], $result['not_set']);
        $this->assertSame(['LOG_LEVEL' => 'info'], $this->plain());
        $this->assertSame(0, ProjectSecret::count());
    }

    public function test_env_unset_errors_and_permissions(): void
    {
        $this->mcpToolError($this->callTool('env_unset', ['project' => 'shop', 'names' => ['NEVER_SET']]));
        $this->assertSchemaRejects('env_unset', ['project' => 'shop', 'names' => []], '/names');
        $this->assertToolHidden('env_unset', ['env.view']);
    }

    // ── secret_reveal ──────────────────────────────────────────────────────────

    public function test_a_secret_is_revealed_with_the_permission_and_audited(): void
    {
        $result = $this->structured($this->callTool('secret_reveal', ['project' => 'shop', 'name' => 'API_TOKEN']));

        $this->assertSame('tok-do-not-leak', $result['value']);
        $event = AuditEvent::where('action', 'project.secret.reveal')->firstOrFail();
        $this->assertStringStartsWith('mcp:', (string) $event->auth_method);
        $this->assertStringNotContainsString('tok-do-not-leak', json_encode($event->toPublicArray()));
    }

    public function test_developers_cannot_reveal_secrets(): void
    {
        $dev = $this->userWithRole('developer');

        $this->assertToolHidden('secret_reveal', ['*'], ['project' => 'shop', 'name' => 'API_TOKEN'], $dev);
        $this->mcpToolError($this->callTool('secret_reveal', ['project' => 'shop', 'name' => 'NOT_THERE']));
        $this->assertSchemaRejects('secret_reveal', ['project' => 'shop', 'name' => 'bad name'], '/name');
    }
}
