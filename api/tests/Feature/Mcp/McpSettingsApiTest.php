<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Tools\ToolRegistry;
use App\Services\Audit\AuditCatalog;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\SingleTenantOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Mcp\Tools\McpToolFixtures;
use Tests\TestCase;

/**
 * Settings → AI agents (MCP): connection details, the tool catalog per caller, which tools a
 * token sees, the new permissions and their place in the built-in roles, the audit actions.
 */
class McpSettingsApiTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    public function test_connection_details(): void
    {
        $this->signedIn($this->userWithRole('viewer'))->getJson('/api/mcp')
            ->assertOk()
            ->assertJsonPath('endpoint', 'http://localhost/mcp')
            ->assertJsonPath('server.name', 'runr')
            ->assertJsonPath('protocol_versions.0', '2025-11-25')
            ->assertJsonPath('oauth.enabled', true)
            ->assertJsonPath('oauth.resource_metadata', 'http://localhost/.well-known/oauth-protected-resource/mcp')
            ->assertJsonPath('oauth.registration_endpoint', 'http://localhost/oauth2/register')
            ->assertJsonPath('default_token_lifetime', 30);
    }

    public function test_the_tool_catalog_marks_what_the_caller_may_use(): void
    {
        $tools = collect($this->signedIn($this->userWithRole('viewer'))->getJson('/api/mcp/tools')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(count(app(ToolRegistry::class)->all()), $tools->count(), 'every tool is listed');
        $this->assertTrue($tools['projects_list']['allowed']);
        $this->assertTrue($tools['logs_tail']['allowed']);
        $this->assertFalse($tools['deploy']['allowed']);
        $this->assertSame(['projects.deploy'], $tools['deploy']['permissions']);
        $this->assertTrue($tools['deploy']['streams']);
        $this->assertTrue($tools['projects_list']['annotations']['readOnlyHint']);
        $this->assertSame('object', $tools['deploy']['input_schema']['type']);
    }

    public function test_tools_follow_the_role_and_the_token(): void
    {
        $viewer = $this->userWithRole('viewer');
        $developer = $this->userWithRole('developer');

        [$token, $session] = $this->connect($viewer);
        $viewerTools = $this->mcpToolNames($token, $session);
        $this->assertContains('projects_list', $viewerTools);
        $this->assertContains('logs_tail', $viewerTools);
        $this->assertNotContains('deploy', $viewerTools);
        $this->assertNotContains('project_create', $viewerTools);
        $this->assertNotContains('secret_reveal', $viewerTools);

        [$token, $session] = $this->connect($developer);
        $developerTools = $this->mcpToolNames($token, $session);
        $this->assertContains('deploy', $developerTools);
        $this->assertContains('mcp_server_create', $developerTools);
        $this->assertNotContains('secret_reveal', $developerTools, 'secrets.view_values is Owner / Admin only');
        $this->assertNotContains('audit_search', $developerTools);

        [$token, $session] = $this->connect($developer, ['projects.view']);
        $scoped = $this->mcpToolNames($token, $session);
        $this->assertContains('project_get', $scoped);
        $this->assertNotContains('deploy', $scoped);
        $this->assertNotContains('logs_tail', $scoped);

        [$token, $session] = $this->connect($this->owner);
        $this->assertSame(array_keys(app(ToolRegistry::class)->all()), $this->mcpToolNames($token, $session), 'an owner with a full token sees every tool');
    }

    public function test_a_role_change_limits_the_token_at_once(): void
    {
        $user = $this->userWithRole('developer');
        [$token, $session] = $this->connect($user);
        $this->assertContains('deploy', $this->mcpToolNames($token, $session));

        (new SingleTenantOrganization)->setMemberRole($user->id, 'viewer');
        $this->flushRbac();

        $this->assertNotContains('deploy', $this->mcpToolNames($token, $session));
    }

    public function test_project_access_shows_project_tools_and_the_route_checks_the_project(): void
    {
        $mine = $this->containerProject('mine');
        $other = $this->containerProject('other');
        $this->version($mine, 1);
        $this->version($other, 1);
        $this->customRole('outsider', ['regions.view']);
        $user = $this->userWithRole('outsider');
        $this->grantProject($user, $mine, 'developer');

        [$token, $session] = $this->connect($user);
        $this->assertContains('deploy', $this->mcpToolNames($token, $session), 'held on one project');

        $result = $this->mcpTool($token, $session, 'deploy', ['project' => $other->id, 'wait' => false]);
        $this->assertTrue($result['isError']);
    }

    public function test_the_new_permissions_and_their_roles(): void
    {
        $catalog = config('permissions.permissions');
        foreach (['mcp.servers.manage', 'mcp.servers.call'] as $permission) {
            $this->assertSame('mcp', $catalog[$permission]['group']);
            $this->assertSame('project', $catalog[$permission]['scope']);
            $this->assertTrue(PermissionCatalog::isProjectScoped($permission));
        }

        $roles = PermissionCatalog::builtinRoles();
        $this->assertContains('mcp.servers.manage', $roles['developer']['permissions']);
        $this->assertContains('mcp.servers.call', $roles['developer']['permissions']);
        $this->assertContains('mcp.servers.call', $roles['viewer']['permissions']);
        $this->assertNotContains('mcp.servers.manage', $roles['viewer']['permissions']);
        $this->assertContains('mcp.servers.manage', $roles['owner']['permissions']);
    }

    public function test_the_audit_actions_are_cataloged(): void
    {
        $actions = AuditCatalog::actions();
        foreach ([
            'mcp.session.start', 'mcp.session.end', 'mcp.tool.call', 'mcp.server.call', 'mcp.client.revoke',
            'oauth.client.register', 'oauth.token.issue', 'oauth.token.revoke', 'oauth.consent.approve', 'oauth.consent.deny',
            'mcp_server.create', 'mcp_server.update', 'mcp_server.delete', 'mcp_server.key.rotate', 'mcp_server.inspect',
        ] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_the_mcp_routes_carry_permission_rules(): void
    {
        $rules = config('route_permissions');
        foreach (['POST mcp', 'GET mcp', 'DELETE mcp', 'GET mcp/tools', 'GET mcp/clients', 'DELETE mcp/clients/{client}',
            'GET oauth2/requests/{id}', 'POST oauth2/requests/{id}/approve', 'POST oauth2/requests/{id}/deny',
            'GET mcp-servers/templates', 'GET mcp-servers', 'GET projects/{project}/mcp-server', 'PUT projects/{project}/mcp-server',
            'DELETE projects/{project}/mcp-server', 'POST projects/{project}/mcp-server/rotate-key',
            'GET projects/{project}/mcp-server/calls', 'POST projects/{project}/mcp-server/inspector'] as $key) {
            $this->assertArrayHasKey($key, $rules, $key);
        }
        $this->assertSame('tokens.manage', $rules['POST oauth2/requests/{id}/approve']);
        $this->assertSame('mcp.servers.manage', $rules['PUT projects/{project}/mcp-server']);
        $this->assertSame('mcp.servers.call', $rules['POST projects/{project}/mcp-server/inspector']);
    }
}
