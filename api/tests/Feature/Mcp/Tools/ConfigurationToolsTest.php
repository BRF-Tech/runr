<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectSecret;
use App\Models\SecretStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * env_list, env_set, env_unset, secret_reveal — plain variables through the project config,
 * secret variables write-only (docs/security.md): their values never leave the API except
 * through secret_reveal, which needs secrets.view_values and is audited.
 */
class ConfigurationToolsTest extends TestCase
{
    use FakesRegionSecrets, McpToolFixtures, RefreshDatabase;

    private Project $project;

    private KnativeRegion $cluster;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();

        // Secret values live in the region (pass-through): a region with a cluster
        $this->fakeRegionSecrets();
        $this->cluster = $this->regionWithCluster('cl');
        $this->project = $this->containerProject('shop', ['MODE' => 'prod', 'LOG_LEVEL' => 'info'], $this->cluster);
        ProjectSecret::create(['project_id' => $this->project->id, 'name' => 'API_TOKEN']);
        $this->regionSecrets->seed($this->cluster, "{$this->project->slug}-secret-env", ['API_TOKEN' => 'tok-do-not-leak']);
    }

    private function inRegion(): array
    {
        return $this->regionSecrets->values($this->cluster, "{$this->project->slug}-secret-env") ?? [];
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
        $this->assertSame('tok-do-not-leak', $this->inRegion()['API_TOKEN'] ?? null, 'secrets untouched');

        $event = AuditEvent::where('action', 'project.update')->latest('occurred_at')->firstOrFail();
        $this->assertStringNotContainsString('staging', json_encode($event->metadata));
    }

    public function test_secret_variables_are_written_and_names_move_between_kinds(): void
    {
        $result = $this->callTool('env_set', ['project' => 'shop', 'vars' => ['DB_PASSWORD' => 'pw-do-not-leak', 'MODE' => 'm'], 'secret' => true]);

        $this->structured($result);
        $this->assertStringNotContainsString('pw-do-not-leak', json_encode($result));
        // Into the region, not RunR's database
        $this->assertNull(ProjectSecret::where('name', 'DB_PASSWORD')->value('value'));
        $this->assertSame('pw-do-not-leak', $this->inRegion()['DB_PASSWORD'] ?? null);
        $this->assertSame('m', $this->inRegion()['MODE'] ?? null);
        $this->assertSame(['LOG_LEVEL' => 'info'], $this->plain(), 'MODE moved from plain to secret');

        // and back: plain again, the secret is removed
        $this->structured($this->callTool('env_set', ['project' => 'shop', 'vars' => ['MODE' => 'plain-again']]));
        $this->assertNull(ProjectSecret::where('name', 'MODE')->first());
        $this->assertArrayNotHasKey('MODE', $this->inRegion(), 'nothing runs: the value leaves the region at once');
        $this->assertSame('plain-again', $this->plain()['MODE']);
    }

    public function test_env_list_says_where_each_secret_lives(): void
    {
        $store = SecretStore::create(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => 'https://vault.test', 'role' => 'runr']]);
        ProjectSecret::create([
            'project_id' => $this->project->id, 'name' => 'DB_URL', 'source' => 'external',
            'secret_store_id' => $store->id, 'remote_path' => 'apps/shop', 'remote_key' => 'url', 'sync_status' => 'synced',
        ]);

        $data = $this->structured($this->callTool('env_list', ['project' => 'shop']));
        $details = collect($data['secret_details'])->keyBy('name');

        $this->assertSame('region', $details['API_TOKEN']['source']);
        $this->assertSame('region', $details['API_TOKEN']['stored_in']);
        $this->assertSame('external', $details['DB_URL']['source']);
        $this->assertSame('vault', $details['DB_URL']['reference']['store_name']);
        $this->assertSame('synced', $details['DB_URL']['sync']['status']);
        $this->assertStringNotContainsString('tok-do-not-leak', json_encode($data));
    }

    // ── secret stores ──────────────────────────────────────────────────────────

    public function test_a_secret_becomes_a_reference_to_a_store(): void
    {
        SecretStore::create(['name' => 'vault', 'provider' => 'vault', 'auth' => 'kubernetes', 'settings' => ['server' => 'https://vault.test', 'role' => 'runr']]);

        $stores = $this->structured($this->callTool('secret_stores_list', []));
        $this->assertSame('vault', $stores['stores'][0]['name']);

        $result = $this->structured($this->callTool('secret_ref_set', ['project' => 'shop', 'name' => 'DB_URL', 'store' => 'vault', 'path' => 'apps/shop', 'key' => 'url']));
        $this->assertTrue($result['requires_deploy']);

        $row = ProjectSecret::where('name', 'DB_URL')->sole();
        $this->assertSame('external', $row->source);
        $this->assertSame(['apps/shop', 'url'], [$row->remote_path, $row->remote_key]);

        // An unknown store is the tool's error, nothing changes
        $this->mcpToolError($this->callTool('secret_ref_set', ['project' => 'shop', 'name' => 'OTHER', 'store' => 'nope', 'path' => 'p']));
        $this->assertNull(ProjectSecret::where('name', 'OTHER')->first());
    }

    public function test_secret_store_tools_follow_the_permissions(): void
    {
        $this->assertToolHidden('secret_stores_list', ['projects.view', 'env.view']);
        $this->assertToolHidden('secret_ref_set', ['projects.view', 'env.view', 'env.edit']);
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
