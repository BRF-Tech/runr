<?php

namespace Tests\Feature\Mcp\Tools;

use App\Jobs\BuildVersionJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Runtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * mcp_servers_list, mcp_server_create, mcp_server_configure, mcp_server_calls — hosting MCP
 * servers as RunR projects through the hosted-server routes (/api/mcp-servers…,
 * /api/projects/{project}/mcp-server…).
 */
class McpServerToolsTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        Bus::fake([BuildVersionJob::class]);
    }

    public function test_an_image_server_is_created_deployed_and_listed(): void
    {
        $this->onPoll = fn () => Deployment::query()->where('status', 'pending')->update(['status' => 'active']);

        $result = $this->structured($this->callTool('mcp_server_create', [
            'name' => 'weather-mcp', 'template' => 'image', 'image' => 'ghcr.io/acme/weather-mcp:1.0', 'port' => 3000, 'access_mode' => 'oauth',
        ]));

        $project = Project::where('name', 'weather-mcp')->sole();
        $this->assertSame('caas', $project->service_type);
        $this->assertSame([3000], $project->containerConfig->ports);
        $this->assertSame('oauth', $result['mcp_server']['access_mode']);
        $this->assertStringEndsWith('/mcp/servers/'.$result['mcp_server']['handle'], $result['mcp_server']['url']);
        $this->assertSame('active', $result['deployment']['status']);

        $list = $this->structured($this->callTool('mcp_servers_list'));
        $this->assertSame([$project->id], array_column($list['servers'], 'project_id'));
    }

    public function test_a_python_server_registers_its_runtime_and_saves_the_starter(): void
    {
        $this->executorImages();

        $result = $this->structured($this->callTool('mcp_server_create', [
            'name' => 'notes-mcp', 'template' => 'python', 'deploy' => false,
        ]));

        $project = Project::where('name', 'notes-mcp')->sole();
        $this->assertSame('faas', $project->service_type);
        $this->assertNotNull($project->runtime);
        $this->assertNotNull(Runtime::find($project->runtime), 'the MCP runtime was registered');
        $this->assertSame(1, $project->versions()->count(), 'the starter code is version 1');
        $this->assertSame('token', $result['mcp_server']['access_mode']);
    }

    public function test_a_server_is_reconfigured_and_its_key_rotated(): void
    {
        $this->callTool('mcp_server_create', ['name' => 'weather-mcp', 'template' => 'image', 'image' => 'ghcr.io/acme/weather-mcp:1.0', 'deploy' => false]);

        $changed = $this->structured($this->callTool('mcp_server_configure', ['project' => 'weather-mcp', 'access_mode' => 'public', 'audit_calls' => true]));
        $this->assertSame('public', $changed['mcp_server']['access_mode']);
        $this->assertTrue($changed['mcp_server']['audit_calls']);

        $rotated = $this->structured($this->callTool('mcp_server_configure', ['project' => 'weather-mcp', 'rotate_key' => true]));
        $this->assertNotEmpty($rotated['mcp_server']['key_rotated_at']);
    }

    public function test_a_project_becomes_an_mcp_server(): void
    {
        $this->containerProject('legacy-tools');

        $result = $this->structured($this->callTool('mcp_server_configure', ['project' => 'legacy-tools', 'access_mode' => 'token', 'path' => '/rpc']));

        $this->assertSame('/rpc', $result['mcp_server']['path']);
    }

    public function test_the_call_log_is_read(): void
    {
        $this->callTool('mcp_server_create', ['name' => 'weather-mcp', 'template' => 'image', 'image' => 'ghcr.io/acme/weather-mcp:1.0', 'deploy' => false]);

        $result = $this->structured($this->callTool('mcp_server_calls', ['project' => 'weather-mcp', 'outcome' => 'error']));

        $this->assertSame([], $result['calls']);
    }

    public function test_mcp_server_validation(): void
    {
        $this->assertSchemaRejects('mcp_server_create', ['name' => 'x', 'template' => 'ruby'], '/template');
        $this->assertSchemaRejects('mcp_server_create', ['name' => 'x', 'template' => 'image', 'access_mode' => 'everyone'], '/access_mode');
        $this->mcpToolError($this->callTool('mcp_server_create', ['name' => 'x', 'template' => 'image']));

        $this->containerProject('shop');
        $this->mcpToolError($this->callTool('mcp_server_configure', ['project' => 'shop']));
        $this->assertSchemaRejects('mcp_server_configure', ['project' => 'shop', 'path' => 'no-slash'], '/path');
        $this->assertSchemaRejects('mcp_server_calls', ['project' => 'shop', 'outcome' => 'meh'], '/outcome');
    }

    public function test_mcp_server_permissions(): void
    {
        $viewer = $this->userWithRole('viewer');

        $this->structured($this->callTool('mcp_servers_list', [], $viewer));
        $this->assertToolHidden('mcp_server_create', ['*'], ['name' => 'x', 'template' => 'image', 'image' => 'i'], $viewer);
        $this->assertToolHidden('mcp_server_configure', ['*'], ['project' => 'x', 'access_mode' => 'public'], $viewer);
        // creating needs projects.create and mcp.servers.manage
        $this->assertToolHidden('mcp_server_create', ['projects.create'], ['name' => 'x', 'template' => 'image', 'image' => 'i']);
        $this->assertToolHidden('mcp_server_calls', ['projects.view']);
    }
}
