<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Models\McpSession;
use App\Mcp\Server\SessionStore;
use App\Mcp\Tools\ToolContext;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Support\ApiRateLimits;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Mcp\Tools\McpToolFixtures;
use Tests\TestCase;

/**
 * The Streamable HTTP transport of the RunR MCP server (POST/GET/DELETE /mcp, docs/mcp.md):
 * initialize and version negotiation, sessions, JSON-RPC errors, batches, the 401 challenge,
 * limits, origins, resources, prompts, completion, logging and the audit trail of a session.
 */
class McpProtocolTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    private function initialize(string $token, array $params = []): TestResponse
    {
        return $this->mcpPost($token, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => $params + ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude-code', 'version' => '2.1.0']],
        ]);
    }

    // ── initialize & sessions ─────────────────────────────────────────────────

    public function test_initialize_negotiates_the_version_and_opens_a_session(): void
    {
        $token = $this->mcpToken($this->owner);
        $response = $this->initialize($token, ['protocolVersion' => '2025-06-18']);

        $response->assertOk()
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 1)
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'runr')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertJsonPath('result.capabilities.resources.subscribe', false)
            ->assertJsonPath('result.capabilities.prompts.listChanged', false);
        $this->assertNotEmpty($response->json('result.serverInfo.title'));
        $this->assertIsString($response->json('result.instructions'));
        // Empty capability objects stay objects on the wire
        $this->assertStringContainsString('"logging":{}', $response->getContent());
        $this->assertStringContainsString('"completions":{}', $response->getContent());

        $plain = (string) $response->headers->get('Mcp-Session-Id');
        $session = McpSession::sole();
        $this->assertSame(SessionStore::hash($plain), $session->id, 'only the hash of the session id is stored');
        $this->assertNotSame($plain, $session->id);
        $this->assertSame(PersonalAccessToken::findToken($token)->id, $session->token_id);
        $this->assertSame('claude-code', $session->client_name);
        $this->assertSame('2025-06-18', $session->protocol_version);

        $event = AuditEvent::where('action', 'mcp.session.start')->sole();
        $this->assertSame('mcp:'.$session->token_id, $event->auth_method);
        $this->assertSame('claude-code', $event->metadata['client']);
        $this->assertSame('2025-06-18', $event->metadata['protocol_version']);
    }

    public function test_an_unknown_version_gets_the_newest_one(): void
    {
        $this->initialize($this->mcpToken($this->owner), ['protocolVersion' => '2024-11-05'])
            ->assertJsonPath('result.protocolVersion', '2025-11-25');
    }

    public function test_the_initialized_notification_is_accepted(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = (string) $this->initialize($token)->headers->get('Mcp-Session-Id');

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session)
            ->assertStatus(202)
            ->assertHeader('Mcp-Session-Id', $session);

        $this->assertNotNull(McpSession::sole()->initialized_at);
    }

    public function test_requests_need_a_known_session(): void
    {
        $token = $this->mcpToken($this->owner);
        $ping = ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping'];

        $this->mcpPost($token, $ping)->assertStatus(400)->assertJsonPath('error.code', -32600)->assertJsonPath('id', 9);
        $this->mcpPost($token, $ping, 'not-a-session')->assertStatus(404)->assertJsonPath('error.code', -32600);

        $session = $this->mcpInitialize($token);
        $this->mcpPost($token, $ping, $session)->assertOk()->assertJsonPath('result', []);
    }

    public function test_a_session_belongs_to_the_token_that_opened_it(): void
    {
        $session = $this->mcpInitialize($this->mcpToken($this->owner));
        $other = $this->mcpToken($this->owner, ['*'], 'second-client');

        $this->mcpPost($other, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $session)->assertNotFound();
    }

    public function test_idle_sessions_expire(): void
    {
        config(['mcp.sessions.idle_minutes' => 30]);
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $this->travel(31)->minutes();

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $session)->assertNotFound();
        $this->assertSame(0, McpSession::count());
    }

    public function test_an_unsupported_protocol_version_header_is_refused(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $session, ['MCP-Protocol-Version' => '1999-01-01'])
            ->assertStatus(400);
        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $session, ['MCP-Protocol-Version' => '2025-11-25'])
            ->assertOk();
    }

    public function test_get_answers_405_and_delete_ends_the_session(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Mcp-Session-Id' => $session])
            ->get('/mcp')->assertStatus(405)->assertHeader('Allow', 'POST, DELETE');

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Mcp-Session-Id' => $session])
            ->delete('/mcp')->assertNoContent();

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $session)->assertNotFound();
        $this->assertTrue(AuditEvent::where('action', 'mcp.session.end')->exists());
    }

    // ── JSON-RPC errors & batches ─────────────────────────────────────────────

    public function test_malformed_messages_get_json_rpc_errors(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $this->mcpPost($token, '{not json', $session)->assertStatus(400)->assertJsonPath('error.code', -32700);
        $this->mcpPost($token, ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'], $session)->assertStatus(400)->assertJsonPath('error.code', -32600);

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'no/such/method'], $session)
            ->assertOk()->assertJsonPath('error.code', -32601)->assertJsonPath('id', 2);
        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['arguments' => []]], $session)
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => 'x'], $session)
            ->assertOk()->assertJsonPath('error.code', -32602);
    }

    public function test_batches_exist_only_in_protocol_2025_03_26(): void
    {
        $token = $this->mcpToken($this->owner);
        $batch = [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']];

        $current = $this->mcpInitialize($token, '2025-11-25');
        $this->mcpPost($token, $batch, $current)->assertStatus(400);

        $old = $this->mcpInitialize($token, '2025-03-26');
        $response = $this->mcpPost($token, $batch, $old)->assertOk();
        $this->assertSame([1, 2], array_column($response->json(), 'id'));

        // Notifications alone are accepted without a body
        $this->mcpPost($token, [['jsonrpc' => '2.0', 'method' => 'notifications/initialized']], $old)->assertStatus(202);
    }

    public function test_initialize_cannot_be_batched(): void
    {
        $this->mcpPost($this->mcpToken($this->owner), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-03-26']],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ])->assertStatus(400);
    }

    public function test_large_bodies_are_refused(): void
    {
        config(['mcp.max_request_bytes' => 64]);
        $token = $this->mcpToken($this->owner);

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['padding' => str_repeat('x', 100)]])
            ->assertStatus(413);
    }

    // ── Authentication & origins ──────────────────────────────────────────────

    public function test_unauthenticated_requests_get_the_resource_metadata_challenge(): void
    {
        $response = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);

        $response->assertUnauthorized();
        $challenge = (string) $response->headers->get('WWW-Authenticate');
        $this->assertStringStartsWith('Bearer ', $challenge);
        $this->assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"', $challenge);
        $this->assertStringContainsString('scope="', $challenge);

        $this->app['auth']->forgetGuards();
        $invalid = $this->withHeader('Authorization', 'Bearer 1|not-a-token')->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $invalid->assertUnauthorized();
        $this->assertStringContainsString('error="invalid_token"', (string) $invalid->headers->get('WWW-Authenticate'));
    }

    public function test_a_token_issued_for_a_hosted_server_does_not_open_mcp(): void
    {
        $token = $this->mcpToken($this->owner);
        PersonalAccessToken::findToken($token)->forceFill(['oauth_resource' => 'http://localhost/mcp/servers/weather'])->save();

        $response = $this->initialize($token);

        $response->assertUnauthorized();
        $this->assertStringContainsString('error="invalid_token"', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_browser_origins_outside_the_allow_list_are_refused(): void
    {
        $token = $this->mcpToken($this->owner);

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']], null, ['Origin' => 'https://evil.example'])
            ->assertForbidden();

        $allowed = $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']], null, ['Origin' => 'http://localhost:6274']);
        $allowed->assertOk()->assertHeader('Access-Control-Allow-Origin', 'http://localhost:6274');
        $this->assertStringContainsString('Mcp-Session-Id', (string) $allowed->headers->get('Access-Control-Expose-Headers'));

        $preflight = $this->call('OPTIONS', '/mcp', [], [], [], $this->transformHeadersToServerVars([
            'Origin' => 'http://localhost:6274', 'Access-Control-Request-Method' => 'POST',
        ]));
        $preflight->assertNoContent();
        $this->assertStringContainsString('Mcp-Session-Id', (string) $preflight->headers->get('Access-Control-Allow-Headers'));
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    public function test_tool_definitions_are_valid_mcp_tools(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $response = $this->mcpPost($token, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/list'], $session)->assertOk();
        $tools = $response->json('result.tools');

        $this->assertNotEmpty($tools);
        foreach ($tools as $tool) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,63}$/', $tool['name']);
            $this->assertNotEmpty($tool['description']);
            $this->assertNotEmpty($tool['title'], "{$tool['name']}: title (2025-06-18+)");
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertArrayHasKey('readOnlyHint', $tool['annotations']);
            $this->assertArrayHasKey('destructiveHint', $tool['annotations']);
        }
        // An input schema without arguments is still a JSON object
        $this->assertMatchesRegularExpression('/"name":"whoami".*?"properties":\{\}/s', $response->getContent());

        $old = $this->mcpInitialize($token, '2025-03-26');
        foreach ($this->mcpRpc($token, $old, 'tools/list')['result']['tools'] as $tool) {
            $this->assertArrayNotHasKey('title', $tool, 'Tool.title starts with 2025-06-18');
        }
    }

    public function test_structured_content_starts_with_2025_06_18(): void
    {
        $token = $this->mcpToken($this->owner);

        $old = $this->mcpTool($token, $this->mcpInitialize($token, '2025-03-26'), 'whoami');
        $this->assertArrayNotHasKey('structuredContent', $old);
        $this->assertSame('text', $old['content'][0]['type']);

        $new = $this->mcpTool($token, $this->mcpInitialize($token, '2025-11-25'), 'whoami');
        $this->assertArrayHasKey('structuredContent', $new);
    }

    public function test_arguments_that_break_the_schema_are_a_tool_error(): void
    {
        $result = $this->callTool('projects_list', ['limit' => 'many', 'unknown' => true]);

        $error = $this->mcpToolError($result);
        $paths = array_column($error['problems'], 'path');
        $this->assertContains('/limit', $paths);
        $this->assertContains('/unknown', $paths);
    }

    public function test_an_unknown_tool_is_a_protocol_error_and_audited_as_denied(): void
    {
        $answer = $this->rawToolCall('no_such_tool', ['x' => 1]);

        $this->assertSame(-32602, $answer['error']['code']);
        $event = AuditEvent::where('action', 'mcp.tool.call')->sole();
        $this->assertSame(AuditEvent::RESULT_DENIED, $event->result);
        $this->assertSame('no_such_tool', $event->metadata['tool']);
    }

    public function test_tool_calls_are_audited_with_argument_names_only(): void
    {
        $this->containerProject('shop');
        [$token] = $this->connect();
        $tokenId = PersonalAccessToken::findToken($token)->id;
        $session = $this->mcpInitialize($token);

        $this->mcpTool($token, $session, 'projects_list', ['search' => 'needle-value-not-in-the-audit']);

        $event = AuditEvent::where('action', 'mcp.tool.call')->sole();
        $this->assertSame(AuditEvent::RESULT_SUCCESS, $event->result);
        $this->assertSame('mcp:'.$tokenId, $event->auth_method);
        $this->assertSame(AuditEvent::ACTOR_API_TOKEN, $event->actor_type);
        $this->assertSame(['search'], $event->metadata['arguments']);
        $this->assertSame('projects_list', $event->metadata['tool']);
        $this->assertSame('runr-tests', $event->metadata['client']);

        foreach (AuditEvent::all() as $row) {
            $this->assertStringNotContainsString('needle-value-not-in-the-audit', json_encode($row->getAttributes()));
        }
    }

    public function test_api_actions_of_a_tool_carry_the_mcp_auth_method_and_the_tool(): void
    {
        $project = $this->containerProject('shop');
        [$token] = $this->connect();
        $tokenId = PersonalAccessToken::findToken($token)->id;

        $result = $this->mcpTool($token, $this->mcpInitialize($token), 'project_update', ['project' => 'shop', 'description' => 'Online shop']);
        $this->assertFalse($result['isError'] ?? false, json_encode($result));

        $inner = AuditEvent::where('action', 'project.update')->sole();
        $this->assertSame('mcp:'.$tokenId, $inner->auth_method);
        $this->assertSame('project_update', $inner->metadata['mcp_tool']);
        $this->assertSame($project->id, $inner->target_id);

        $outer = AuditEvent::where('action', 'mcp.tool.call')->sole();
        $this->assertSame($inner->request_id, $outer->request_id, 'one request id for the MCP call and what it did');
        $this->assertSame('project', $outer->target_type);
        $this->assertSame($project->id, $outer->target_id);
    }

    public function test_internal_api_calls_do_not_count_against_the_rate_limiter_again(): void
    {
        $internal = Request::create('/api/projects');
        $internal->attributes->set('mcp', ['tool' => 'projects_list', 'internal' => true]);
        $this->assertInstanceOf(Unlimited::class, ApiRateLimits::apiLimits($internal));

        $outer = Request::create('/mcp', 'POST');
        $outer->attributes->set('mcp', ['tool' => null, 'internal' => false]);
        $this->assertNotInstanceOf(Unlimited::class, ApiRateLimits::apiLimits($outer));
    }

    public function test_long_running_tools_answer_json_without_an_event_stream_accept(): void
    {
        $project = $this->containerProject('shop');
        $this->version($project, 1);
        $this->onPoll = fn () => Deployment::query()->where('status', 'pending')->update(['status' => 'active']);
        [$token, $session] = $this->connect();

        $response = $this->mcpPost($token, [
            'jsonrpc' => '2.0', 'id' => 'd', 'method' => 'tools/call',
            'params' => ['name' => 'deploy', 'arguments' => ['project' => 'shop'], '_meta' => ['progressToken' => 'p']],
        ], $session);

        $response->assertOk();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame('active', $response->json('result.structuredContent.deployment.status'));
    }

    public function test_cancellation_is_flagged_for_the_running_request(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $this->mcpPost($token, ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 'req-7', 'reason' => 'user']], $session)
            ->assertStatus(202);

        $this->assertTrue(Cache::has(ToolContext::cancelKey(SessionStore::hash($session), 'req-7')));
    }

    public function test_the_logging_level_is_kept_per_session(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $this->assertSame([], $this->mcpRpc($token, $session, 'logging/setLevel', ['level' => 'warning'])['result']);
        $this->assertSame('warning', McpSession::sole()->log_level);

        $this->assertSame(-32602, $this->mcpRpc($token, $session, 'logging/setLevel', ['level' => 'loud'])['error']['code']);
    }

    // ── Resources, prompts, completion ────────────────────────────────────────

    private function docsDir(): string
    {
        $dir = sys_get_temp_dir().'/runr-mcp-docs-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/mcp.md', "# AI agents (MCP)\n\nConnect clients.\n");
        file_put_contents($dir.'/monitoring.md', "# Monitoring\n");
        config(['mcp.docs_path' => $dir]);

        return $dir;
    }

    public function test_resources_list_docs_runtimes_and_projects(): void
    {
        $this->docsDir();
        $project = $this->containerProject('shop');
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $uris = array_column($this->mcpRpc($token, $session, 'resources/list')['result']['resources'], 'uri');
        $this->assertContains('runr://docs/mcp', $uris);
        $this->assertContains('runr://docs/monitoring', $uris);
        $this->assertContains('runr://projects/'.$project->id, $uris);

        $templates = array_column($this->mcpRpc($token, $session, 'resources/templates/list')['result']['resourceTemplates'], 'uriTemplate');
        $this->assertSame([
            'runr://projects/{id}', 'runr://projects/{id}/files/{path}', 'runr://projects/{id}/logs', 'runr://docs/{page}', 'runr://runtimes/{id}/manifest',
        ], $templates);
    }

    public function test_resources_are_read_as_the_caller(): void
    {
        $this->docsDir();
        $project = $this->containerProject('shop', ['DB_URL' => 'postgres://user:hunter2@db/shop']);
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $doc = $this->mcpRpc($token, $session, 'resources/read', ['uri' => 'runr://docs/mcp'])['result']['contents'][0];
        $this->assertSame('text/markdown', $doc['mimeType']);
        $this->assertStringContainsString('Connect clients.', $doc['text']);

        $json = $this->mcpRpc($token, $session, 'resources/read', ['uri' => 'runr://projects/'.$project->id])['result']['contents'][0];
        $this->assertSame('application/json', $json['mimeType']);
        $this->assertSame('shop', json_decode($json['text'], true)['name']);
        $this->assertSame(['DB_URL'], json_decode($json['text'], true)['config']['env_var_names']);
        $this->assertStringNotContainsString('hunter2', $json['text'], 'environment variable values stay out of resources');

        foreach (['runr://docs/../../etc/passwd', 'runr://docs/nope', 'https://example.com', 'runr://projects/'.$project->id.'/files/missing.py'] as $uri) {
            $answer = $this->mcpRpc($token, $session, 'resources/read', ['uri' => $uri]);
            $this->assertSame(-32002, $answer['error']['code'], $uri);
        }
    }

    public function test_a_project_the_token_cannot_see_is_not_a_resource(): void
    {
        $project = $this->containerProject('shop');
        $viewer = $this->userWithRole('viewer');
        $token = $this->mcpToken($viewer, ['regions.view']);
        $session = $this->mcpInitialize($token);

        $answer = $this->mcpRpc($token, $session, 'resources/read', ['uri' => 'runr://projects/'.$project->id]);
        $this->assertSame(-32002, $answer['error']['code']);
    }

    public function test_prompts_are_listed_and_rendered(): void
    {
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $names = array_column($this->mcpRpc($token, $session, 'prompts/list')['result']['prompts'], 'name');
        $this->assertSame(['deploy_function', 'debug_deployment', 'create_workflow'], $names);

        $prompt = $this->mcpRpc($token, $session, 'prompts/get', ['name' => 'deploy_function', 'arguments' => ['description' => 'Convert Celsius to Fahrenheit', 'runtime' => 'python']])['result'];
        $this->assertSame('user', $prompt['messages'][0]['role']);
        $text = $prompt['messages'][0]['content']['text'];
        $this->assertStringContainsString('Convert Celsius to Fahrenheit', $text);
        $this->assertStringContainsString('code_write_files', $text);
        $this->assertStringContainsString('deploy', $text);

        $this->assertSame(-32602, $this->mcpRpc($token, $session, 'prompts/get', ['name' => 'debug_deployment'])['error']['code'], 'project is required');
        $this->assertSame(-32602, $this->mcpRpc($token, $session, 'prompts/get', ['name' => 'nope'])['error']['code']);
    }

    public function test_completion_suggests_docs_and_projects(): void
    {
        $this->docsDir();
        $project = $this->containerProject('shop');
        $token = $this->mcpToken($this->owner);
        $session = $this->mcpInitialize($token);

        $docs = $this->mcpRpc($token, $session, 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'runr://docs/{page}'], 'argument' => ['name' => 'page', 'value' => 'm'],
        ])['result']['completion'];
        $this->assertSame(['mcp', 'monitoring'], $docs['values']);
        $this->assertFalse($docs['hasMore']);

        $ids = $this->mcpRpc($token, $session, 'completion/complete', [
            'ref' => ['type' => 'ref/resource', 'uri' => 'runr://projects/{id}'], 'argument' => ['name' => 'id', 'value' => 'sh'],
        ])['result']['completion']['values'];
        $this->assertSame([$project->id], $ids);

        $names = $this->mcpRpc($token, $session, 'completion/complete', [
            'ref' => ['type' => 'ref/prompt', 'name' => 'debug_deployment'], 'argument' => ['name' => 'project', 'value' => 'sh'],
        ])['result']['completion']['values'];
        $this->assertSame(['shop'], $names);

        $this->assertSame(-32602, $this->mcpRpc($token, $session, 'completion/complete', ['ref' => []])['error']['code']);
    }
}
