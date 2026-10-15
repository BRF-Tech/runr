<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** audit_search — the audit log route, and the audit trail MCP calls leave in it. */
class AuditToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    public function test_events_are_searched_by_action(): void
    {
        app(AuditLogger::class)->record('project.update', ['actor' => $this->owner, 'target' => ['type' => 'project', 'id' => 'p1', 'name' => 'shop']]);
        app(AuditLogger::class)->record('region.create', ['actor' => $this->owner]);

        $result = $this->structured($this->callTool('audit_search', ['action' => 'project.*']));

        $this->assertSame(['project.update'], array_values(array_unique(array_column($result['events'], 'action'))));
        $this->assertSame('shop', $result['events'][0]['target']['name']);
    }

    public function test_every_tool_call_is_in_the_log_with_the_mcp_auth_method(): void
    {
        $this->containerProject('shop');
        [$token, $session] = $this->connect();

        $this->mcpTool($token, $session, 'project_get', ['project' => 'shop']);
        $this->mcpTool($token, $session, 'project_get', ['project' => 'missing']);

        $calls = AuditEvent::where('action', 'mcp.tool.call')->orderBy('occurred_at')->get();
        $this->assertCount(2, $calls);
        $this->assertSame(['success', 'failure'], $calls->pluck('result')->all());
        $this->assertSame('project_get', $calls[0]->metadata['tool']);
        $this->assertSame(['project'], $calls[0]->metadata['arguments']);
        $this->assertSame('api_token', $calls[0]->actor_type);
        $this->assertStringStartsWith('mcp:', (string) $calls[0]->auth_method);

        $found = $this->structured($this->mcpTool($token, $session, 'audit_search', ['action' => 'mcp.tool.call']));
        $this->assertGreaterThanOrEqual(2, count($found['events']));
    }

    public function test_audit_search_validation_and_permissions(): void
    {
        $this->assertSchemaRejects('audit_search', ['result' => 'maybe'], '/result');
        $this->assertSchemaRejects('audit_search', ['limit' => 1000], '/limit');

        $dev = $this->userWithRole('developer');
        $this->assertToolHidden('audit_search', ['*'], [], $dev);
    }
}
