<?php

namespace Tests\Feature\Mcp\Tools;

use App\Support\RequestTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A long-running tool waits up to mcp.tools.max_wait_seconds; the API worker's request limit
 * (octane.max_execution_time, 30 s) ended mcp_server_create after 30 s while its client waited.
 * A tools/call request gets the tools' wait (plus RequestTime's margin) — other requests keep the limit.
 */
class ToolTimeLimitTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private string $limitBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->limitBefore = (string) ini_get('max_execution_time');
        set_time_limit(30);
    }

    protected function tearDown(): void
    {
        set_time_limit((int) $this->limitBefore);
        parent::tearDown();
    }

    public function test_a_tool_call_may_run_as_long_as_the_tools_wait(): void
    {
        config(['mcp.tools.max_wait_seconds' => 600]);
        $this->containerProject('shop');

        $this->callTool('projects_list');

        $this->assertSame((string) (600 + RequestTime::MARGIN_SECONDS), ini_get('max_execution_time'));
    }

    public function test_other_requests_keep_the_request_limit(): void
    {
        [$token, $session] = $this->connect();

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 'l-1', 'method' => 'tools/list'], $session)->assertOk();

        $this->assertSame('30', ini_get('max_execution_time'));
    }
}
