<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Hosting\GatewayIdentity;
use App\Mcp\Models\HostedServer;
use App\Mcp\Models\HostedServerCall;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Services\KnativeLogService;
use App\Workflows\Engine\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Mcp\Tools\McpToolFixtures;
use Tests\TestCase;

/**
 * The gateway in front of hosted MCP servers (POST/GET/DELETE /mcp/servers/{handle}): access
 * modes, identity and signature headers, the Authorization header never forwarded, SSE pass
 * through, the call log (and its place in the deployment logs), audit, rate limit, inspector.
 */
class McpGatewayTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private const BODY = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"forecast","arguments":{"city":"Ankara"}}}';

    private Project $project;

    /** @var list<ClientRequest> */
    private array $forwarded = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
        $this->region->forceFill(['knative_settings' => ['knative_domain' => 'op.fn.test']])->save();
        $this->project = $this->containerProject('weather');
    }

    private function serve(string $mode, array $attributes = []): HostedServer
    {
        $server = HostedServer::newFor($this->project, ['access_mode' => $mode] + $attributes);
        $server->save();

        return $server;
    }

    /** The hosted server answers $body (JSON unless $contentType says otherwise). */
    private function upstream(string $body = '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"sunny"}]}}', int $status = 200, string $contentType = 'application/json', array $headers = []): void
    {
        Http::fake(['weather.op.fn.test/*' => function (ClientRequest $request) use ($body, $status, $contentType, $headers) {
            $this->forwarded[] = $request;

            return Http::response($body, $status, ['Content-Type' => $contentType] + $headers);
        }]);
    }

    private function gateway(?string $token = null, string $body = self::BODY, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $server = $this->transformHeadersToServerVars(array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ], $token ? ['Authorization' => 'Bearer '.$token] : [], $headers));

        return $this->call('POST', '/mcp/servers/weather', [], [], [], $server, $body);
    }

    private function identityOf(ClientRequest $request): array
    {
        return json_decode(base64_decode(strtr($request->header('X-RunR-Identity')[0], '-_', '+/')), true);
    }

    // ── public ────────────────────────────────────────────────────────────────

    public function test_a_public_server_is_called_anonymously_with_a_signed_request(): void
    {
        $server = $this->serve(HostedServer::PUBLIC);
        $this->upstream('{"jsonrpc":"2.0","id":1,"result":{}}', 200, 'application/json', ['Mcp-Session-Id' => 'up-1']);

        $this->gateway(null, self::BODY, ['Mcp-Session-Id' => 'up-1', 'MCP-Protocol-Version' => '2025-11-25'])
            ->assertOk()
            ->assertHeader('Mcp-Session-Id', 'up-1')
            ->assertExactJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->assertCount(1, $this->forwarded);
        $request = $this->forwarded[0];
        $this->assertSame('https://weather.op.fn.test/mcp', $request->url());
        $this->assertSame(self::BODY, $request->body());
        $this->assertSame('up-1', $request->header('Mcp-Session-Id')[0]);
        $this->assertSame('2025-11-25', $request->header('MCP-Protocol-Version')[0]);
        $this->assertSame('public', $this->identityOf($request)['auth']);
        $this->assertNull($this->identityOf($request)['user']);

        // What the server checks with RUNR_MCP_GATEWAY_KEY
        $canonical = GatewayIdentity::canonical('POST', '/mcp', self::BODY, $request->header('X-RunR-Identity')[0]);
        $this->assertTrue(Signature::verify($canonical, $request->header('X-RunR-Signature')[0], [$server->signing_key]));

        $call = HostedServerCall::sole();
        $this->assertSame('tools/call', $call->method);
        $this->assertSame('forecast', $call->name);
        $this->assertSame('ok', $call->outcome);
        $this->assertSame('anonymous', $call->actor_type);
        $this->assertSame('public', $call->auth);
        $this->assertStringNotContainsString('Ankara', json_encode($call->getAttributes()), 'arguments are never logged');
    }

    public function test_the_authorization_header_is_never_forwarded(): void
    {
        $this->serve(HostedServer::PUBLIC);
        $this->upstream();
        $token = $this->mcpToken($this->owner);

        $this->gateway($token)->assertOk();

        $this->assertSame([], $this->forwarded[0]->header('Authorization'));
        $this->assertStringNotContainsString($token, json_encode($this->forwarded[0]->headers()));
    }

    public function test_unknown_servers_answer_404(): void
    {
        $this->gateway()->assertNotFound();
    }

    // ── token & oauth ─────────────────────────────────────────────────────────

    public function test_token_mode_requires_a_runr_token(): void
    {
        $this->serve(HostedServer::TOKEN);
        $this->upstream();

        $response = $this->gateway();
        $response->assertUnauthorized();
        $challenge = (string) $response->headers->get('WWW-Authenticate');
        $this->assertStringStartsWith('Bearer ', $challenge);
        $this->assertStringNotContainsString('resource_metadata', $challenge, 'token mode does not advertise OAuth');

        $this->gateway('1|not-a-token')->assertUnauthorized();
        $this->assertSame([], $this->forwarded);
        $this->assertSame(2, HostedServerCall::where('outcome', 'denied')->count());
    }

    public function test_oauth_mode_advertises_the_resource_metadata(): void
    {
        $this->serve(HostedServer::OAUTH);

        $response = $this->gateway();

        $response->assertUnauthorized();
        $this->assertStringContainsString(
            'resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp/servers/weather"',
            (string) $response->headers->get('WWW-Authenticate'),
        );
        $this->assertStringContainsString('scope="mcp.servers.call"', (string) $response->headers->get('WWW-Authenticate'));
    }

    public function test_a_member_with_the_call_permission_is_forwarded_with_its_identity(): void
    {
        $this->serve(HostedServer::TOKEN);
        $this->upstream();
        $viewer = $this->userWithRole('viewer');
        $plain = $this->mcpToken($viewer, ['mcp.servers.call']);

        $this->gateway($plain)->assertOk();

        $identity = $this->identityOf($this->forwarded[0]);
        $this->assertSame('token', $identity['auth']);
        $this->assertSame((string) $viewer->id, $identity['user']['id']);
        $this->assertSame((string) PersonalAccessToken::findToken($plain)->id, $identity['token_id']);
        $this->assertContains('mcp.servers.call', $identity['scopes']);
        $this->assertNotNull(PersonalAccessToken::findToken($plain)->last_used_at);

        $call = HostedServerCall::sole();
        $this->assertSame('api_token', $call->actor_type);
        $this->assertSame((string) $viewer->id, $call->actor_id);
        $this->assertSame('token', $call->auth);
    }

    public function test_a_token_without_the_call_permission_is_refused(): void
    {
        $this->serve(HostedServer::TOKEN);
        $this->upstream();

        $response = $this->gateway($this->mcpToken($this->owner, ['projects.view']));

        $response->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
        $this->assertStringContainsString('scope="mcp.servers.call"', (string) $response->headers->get('WWW-Authenticate'));
        $this->assertSame([], $this->forwarded);
    }

    public function test_expired_tokens_and_tokens_for_another_resource_are_refused(): void
    {
        $this->serve(HostedServer::OAUTH);
        $this->upstream();

        $expired = $this->mcpToken($this->owner);
        PersonalAccessToken::findToken($expired)->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->gateway($expired)->assertUnauthorized();

        // A sign-in session past its lifetime, from before sessions carried an expiry
        $session = $this->mcpToken($this->owner, ['*'], 'api');
        PersonalAccessToken::findToken($session)->forceFill(['created_at' => now()->subDays(31)])->save();
        $this->gateway($session)->assertUnauthorized();

        $forMcp = $this->mcpToken($this->owner);
        PersonalAccessToken::findToken($forMcp)->forceFill(['oauth_resource' => 'http://localhost/mcp', 'oauth_client_id' => 'rmc_x'])->save();
        $this->gateway($forMcp)->assertUnauthorized();

        $forThis = $this->mcpToken($this->owner);
        PersonalAccessToken::findToken($forThis)->forceFill(['oauth_resource' => 'http://localhost/mcp/servers/weather', 'oauth_client_id' => 'rmc_x'])->save();
        $this->gateway($forThis)->assertOk();
        $this->assertSame('oauth', $this->identityOf($this->forwarded[0])['auth']);
        $this->assertSame('rmc_x', $this->identityOf($this->forwarded[0])['client_id']);
    }

    // ── answers ───────────────────────────────────────────────────────────────

    public function test_sse_answers_pass_through_and_are_logged_when_they_end(): void
    {
        $this->serve(HostedServer::PUBLIC);
        $sse = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\",\"params\":{\"progressToken\":1,\"progress\":1}}\n\n"
             ."event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"isError\":true,\"content\":[{\"type\":\"text\",\"text\":\"city unknown\"}]}}\n\n";
        $this->upstream($sse, 200, 'text/event-stream');

        $response = $this->gateway();

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        $this->assertSame($sse, $response->streamedContent());

        $call = HostedServerCall::sole();
        $this->assertSame('error', $call->outcome);
        $this->assertSame('city unknown', $call->error);
        $this->assertSame(strlen($sse), $call->response_bytes);
    }

    public function test_an_unreachable_server_answers_502_and_is_logged(): void
    {
        $this->serve(HostedServer::PUBLIC);
        Http::fake(['weather.op.fn.test/*' => fn () => throw new ConnectionException('Connection refused')]);

        $this->gateway()->assertStatus(502)->assertJsonPath('error.code', -32603);

        $this->assertSame('unreachable', HostedServerCall::sole()->outcome);
    }

    public function test_calls_are_audited_when_the_server_asks_for_it(): void
    {
        $this->serve(HostedServer::TOKEN, ['audit_calls' => true]);
        $this->upstream();

        $this->gateway($this->mcpToken($this->owner))->assertOk();

        $event = AuditEvent::where('action', 'mcp.server.call')->sole();
        $this->assertSame($this->project->id, $event->target_id);
        $this->assertSame('tools/call', $event->metadata['method']);
        $this->assertSame('forecast', $event->metadata['name']);
        $this->assertStringNotContainsString('Ankara', json_encode($event->getAttributes()));
    }

    public function test_calls_are_not_audited_by_default(): void
    {
        $this->serve(HostedServer::PUBLIC);
        $this->upstream();

        $this->gateway()->assertOk();

        $this->assertFalse(AuditEvent::where('action', 'mcp.server.call')->exists());
    }

    public function test_the_gateway_rate_limits_per_server_and_caller(): void
    {
        config(['mcp.gateway.per_minute' => 2]);
        $this->serve(HostedServer::PUBLIC);
        $this->upstream();

        $this->gateway()->assertOk();
        $this->gateway()->assertOk();
        $this->gateway()->assertStatus(429)->assertHeader('Retry-After');
        $this->assertCount(2, $this->forwarded);
    }

    public function test_large_bodies_are_refused(): void
    {
        config(['mcp.max_request_bytes' => 10]);
        $this->serve(HostedServer::PUBLIC);

        $this->gateway()->assertStatus(413);
    }

    // ── the console: logs and inspector ───────────────────────────────────────

    public function test_calls_appear_in_the_deployment_logs(): void
    {
        $this->serve(HostedServer::PUBLIC);
        $this->upstream();
        $this->gateway()->assertOk();
        $deployment = $this->deployment($this->project, $this->version($this->project, 1), 'active');

        $this->mock(KnativeLogService::class, fn ($mock) => $mock->shouldReceive('getDeploymentLogs')->andReturn([
            'pods' => [['name' => 'weather-00001', 'status' => 'Running', 'ready' => true]],
            'logs' => "=== weather-00001 ===\n".(now()->subMinute()->getTimestampMs())."\tlistening on 8080\n",
            'last_ts_ms' => now()->subMinute()->getTimestampMs(),
            'source' => 'loki',
        ]));

        $logs = $this->signedIn($this->owner)->getJson("/api/projects/{$this->project->id}/deployments/{$deployment->id}/logs")->assertOk();

        $text = $logs->json('logs');
        $this->assertStringContainsString("=== weather-00001 ===\n", $text);
        $this->assertStringContainsString("=== mcp-gateway ===\n", $text);
        $this->assertMatchesRegularExpression('/\d{13}\t\[mcp\] tools\/call forecast → 200 ok \d+ms anonymous \(public\)/u', $text);
        $this->assertLessThan(strpos($text, 'mcp-gateway'), strpos($text, 'listening on 8080'), 'merged by time');
        $this->assertSame('loki', $logs->json('source'));
    }

    public function test_the_inspector_talks_to_the_server_as_the_console_user(): void
    {
        $this->serve(HostedServer::TOKEN);
        $sse = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"protocolVersion\":\"2025-11-25\",\"capabilities\":{},\"serverInfo\":{\"name\":\"weather\",\"version\":\"1\"}}}\n\n";
        $this->upstream($sse, 200, 'text/event-stream', ['Mcp-Session-Id' => 'up-9']);

        $response = $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/mcp-server/inspector", [
            'message' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'RunR inspector', 'version' => '1']]],
        ])->assertOk();

        $this->assertSame('up-9', $response->json('session_id'));
        $this->assertSame('weather', $response->json('messages.0.result.serverInfo.name'));
        $this->assertSame($sse, $response->json('raw'));
        $this->assertSame('console', $this->identityOf($this->forwarded[0])['auth']);
        $this->assertSame((string) $this->owner->id, $this->identityOf($this->forwarded[0])['user']['id']);

        $this->assertSame('console', HostedServerCall::sole()->auth);
        $this->assertSame('initialize', AuditEvent::where('action', 'mcp_server.inspect')->sole()->metadata['method']);

        // The session id goes along on the next message
        $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/mcp-server/inspector", [
            'message' => ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], 'session_id' => 'up-9',
        ])->assertOk();
        $this->assertSame('up-9', $this->forwarded[1]->header('Mcp-Session-Id')[0]);
    }

    public function test_the_inspector_needs_the_call_permission_and_a_json_rpc_message(): void
    {
        $this->serve(HostedServer::TOKEN);
        $this->customRole('looker', ['projects.view']);

        $this->signedIn($this->userWithRole('looker'))->postJson("/api/projects/{$this->project->id}/mcp-server/inspector", [
            'message' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
        ])->assertForbidden()->assertJsonPath('permissions', ['mcp.servers.call']);

        $this->signedIn($this->owner)->postJson("/api/projects/{$this->project->id}/mcp-server/inspector", [
            'message' => ['hello' => 'world'],
        ])->assertUnprocessable();
    }
}
