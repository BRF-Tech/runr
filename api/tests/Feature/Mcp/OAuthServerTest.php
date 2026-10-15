<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Models\HostedServer;
use App\Mcp\Models\OAuthClient;
use App\Mcp\Models\OAuthRequest;
use App\Mcp\OAuth\Pkce;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Mcp\Tools\McpToolFixtures;
use Tests\TestCase;

/**
 * The OAuth 2.1 authorization server of the MCP surface (docs/mcp.md, "OAuth"): metadata,
 * dynamic client registration, authorization code + PKCE with the console's consent, the token
 * as a RunR API token (scopes, lifetime, audience), code reuse, revocation, connected clients.
 */
class OAuthServerTest extends TestCase
{
    use McpToolFixtures, RefreshDatabase;

    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private const REDIRECT = 'http://127.0.0.1:33418/callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMcpTools();
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function register(array $metadata = []): array
    {
        $response = $this->postJson('/oauth2/register', $metadata + [
            'client_name' => 'Claude Code',
            'redirect_uris' => [self::REDIRECT],
        ]);
        $response->assertCreated();

        return $response->json();
    }

    /** GET /oauth2/authorize; returns the Location the browser is sent to. */
    private function authorizeUrl(string $clientId, array $params = []): string
    {
        $query = http_build_query($params + [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => Pkce::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state' => 'st-123',
            'scope' => 'projects.view logs.view',
            'resource' => 'http://localhost/mcp',
        ]);

        $response = $this->get('/oauth2/authorize?'.$query);
        $response->assertRedirect();

        return (string) $response->headers->get('Location');
    }

    private function requestIdFrom(string $location): string
    {
        $this->assertStringStartsWith('http://localhost/oauth-consent?request=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $q);

        return $q['request'];
    }

    private function approve(string $requestId, ?User $user = null, array $abilities = ['projects.view'], ?int $days = 30): TestResponse
    {
        return $this->signedIn($user ?? $this->owner)->postJson("/api/oauth2/requests/{$requestId}/approve", [
            'abilities' => $abilities, 'expires_in_days' => $days,
        ]);
    }

    private function codeFrom(string $redirect): string
    {
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $q);
        $this->assertSame('st-123', $q['state']);
        $this->assertSame('http://localhost', $q['iss']);

        return $q['code'];
    }

    private function exchange(string $clientId, string $code, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/oauth2/token', $extra + [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::REDIRECT,
            'client_id' => $clientId,
            'code_verifier' => self::VERIFIER,
            'resource' => 'http://localhost/mcp',
        ]);
    }

    /** register → authorize → approve; returns [client id, code]. */
    private function approvedCode(array $abilities = ['projects.view'], ?int $days = 30): array
    {
        $client = $this->register()['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client));
        $url = $this->approve($id, null, $abilities, $days)->assertOk()->json('redirect_url');

        return [$client, $this->codeFrom($url)];
    }

    // ── metadata ──────────────────────────────────────────────────────────────

    public function test_metadata_documents_describe_the_server_and_the_resource(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', 'http://localhost')
            ->assertJsonPath('authorization_endpoint', 'http://localhost/oauth2/authorize')
            ->assertJsonPath('token_endpoint', 'http://localhost/oauth2/token')
            ->assertJsonPath('registration_endpoint', 'http://localhost/oauth2/register')
            ->assertJsonPath('revocation_endpoint', 'http://localhost/oauth2/revoke')
            ->assertJsonPath('code_challenge_methods_supported', ['S256'])
            ->assertJsonPath('grant_types_supported', ['authorization_code'])
            ->assertJsonPath('response_types_supported', ['code']);

        foreach (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'] as $path) {
            $this->getJson($path)
                ->assertOk()
                ->assertJsonPath('resource', 'http://localhost/mcp')
                ->assertJsonPath('authorization_servers', ['http://localhost'])
                ->assertJsonPath('bearer_methods_supported', ['header']);
        }
        $this->assertContains('projects.deploy', $this->getJson('/.well-known/oauth-protected-resource/mcp')->json('scopes_supported'));

        config(['mcp.oauth.open_registration' => false]);
        $this->assertNull($this->getJson('/.well-known/oauth-authorization-server')->json('registration_endpoint'));
        $this->postJson('/oauth2/register', ['redirect_uris' => [self::REDIRECT]])->assertForbidden()->assertJsonPath('error', 'access_denied');
    }

    public function test_hosted_servers_have_resource_metadata_unless_public(): void
    {
        $project = $this->containerProject('weather');
        $server = HostedServer::newFor($project, ['access_mode' => HostedServer::OAUTH]);
        $server->save();

        $this->getJson('/.well-known/oauth-protected-resource/mcp/servers/weather')
            ->assertOk()
            ->assertJsonPath('resource', 'http://localhost/mcp/servers/weather')
            ->assertJsonPath('scopes_supported', ['mcp.servers.call']);

        $server->forceFill(['access_mode' => HostedServer::PUBLIC])->save();
        $this->getJson('/.well-known/oauth-protected-resource/mcp/servers/weather')->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource/mcp/servers/nope')->assertNotFound();
    }

    // ── registration ──────────────────────────────────────────────────────────

    public function test_public_and_confidential_clients_register(): void
    {
        $public = $this->register();
        $this->assertStringStartsWith('rmc_', $public['client_id']);
        $this->assertSame('none', $public['token_endpoint_auth_method']);
        $this->assertSame(['authorization_code'], $public['grant_types']);
        $this->assertArrayNotHasKey('client_secret', $public);

        $confidential = $this->register(['token_endpoint_auth_method' => 'client_secret_basic', 'client_name' => 'CI agent']);
        $this->assertStringStartsWith('rms_', $confidential['client_secret']);
        $this->assertSame(0, $confidential['client_secret_expires_at']);
        $stored = OAuthClient::find($confidential['client_id']);
        $this->assertSame(hash('sha256', $confidential['client_secret']), $stored->secret_hash, 'only the hash of the secret is stored');

        $event = AuditEvent::where('action', 'oauth.client.register')->latest('occurred_at')->first();
        $this->assertSame(['127.0.0.1'], $event->metadata['redirect_hosts']);
        $this->assertStringNotContainsString($confidential['client_secret'], json_encode(AuditEvent::all()->toArray()));
    }

    public function test_registration_validates_the_metadata(): void
    {
        foreach ([
            [['redirect_uris' => ['http://client.example.com/cb']], 'invalid_redirect_uri'],
            [['redirect_uris' => ['javascript:alert(1)']], 'invalid_redirect_uri'],
            [['redirect_uris' => []], 'invalid_redirect_uri'],
            [['redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'private_key_jwt'], 'invalid_client_metadata'],
            [['redirect_uris' => [self::REDIRECT], 'grant_types' => ['client_credentials']], 'invalid_client_metadata'],
            [['redirect_uris' => [self::REDIRECT], 'logo_uri' => 'http://insecure.example/logo.png'], 'invalid_client_metadata'],
        ] as [$metadata, $error]) {
            $this->postJson('/oauth2/register', $metadata)->assertStatus(400)->assertJsonPath('error', $error);
        }
    }

    // ── authorization ─────────────────────────────────────────────────────────

    public function test_authorization_parks_the_request_for_the_consent_page(): void
    {
        $client = $this->register()['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client));

        $pending = OAuthRequest::findOrFail($id);
        $this->assertSame(OAuthRequest::PENDING, $pending->status);
        $this->assertSame(['projects.view', 'logs.view'], $pending->scopes);
        $this->assertSame('http://localhost/mcp', $pending->resource);
        $this->assertTrue($pending->expires_at->isFuture());
    }

    public function test_untrusted_clients_and_redirects_end_on_the_consoles_error_page(): void
    {
        $this->assertStringStartsWith('http://localhost/oauth-consent?error=invalid_client', $this->authorizeUrl('rmc_unknown'));

        $client = $this->register()['client_id'];
        $this->assertStringStartsWith('http://localhost/oauth-consent?error=invalid_request', $this->authorizeUrl($client, ['redirect_uri' => 'https://attacker.example/cb']));
    }

    public function test_invalid_requests_return_to_the_client_with_an_error(): void
    {
        $client = $this->register()['client_id'];

        foreach ([
            [['code_challenge_method' => 'plain'], 'invalid_request'],
            [['code_challenge' => 'too-short'], 'invalid_request'],
            [['response_type' => 'token'], 'unsupported_response_type'],
            [['resource' => 'https://other.example/mcp'], 'invalid_target'],
            [['resource' => 'http://localhost/mcp/servers/unknown'], 'invalid_target'],
        ] as [$params, $error]) {
            $location = $this->authorizeUrl($client, $params);
            $this->assertStringStartsWith(self::REDIRECT.'?', $location);
            parse_str((string) parse_url($location, PHP_URL_QUERY), $q);
            $this->assertSame($error, $q['error'], json_encode($params));
            $this->assertSame('st-123', $q['state']);
        }
        $this->assertSame(0, OAuthRequest::count());
    }

    // ── consent ───────────────────────────────────────────────────────────────

    public function test_the_consent_page_shows_the_request_in_the_callers_organization(): void
    {
        $client = $this->register()['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client));

        $data = $this->signedIn($this->owner)->getJson("/api/oauth2/requests/{$id}")->assertOk()->json('data');

        $this->assertSame('Claude Code', $data['client']['name']);
        $this->assertSame('127.0.0.1', $data['client']['redirect_host']);
        $this->assertSame('runr', $data['resource_type']);
        $this->assertSame(['projects.view', 'logs.view'], $data['requested_scopes']);
        $this->assertContains('projects.deploy', array_column($data['available_abilities'], 'key'));
        $this->assertContains(30, $data['token_lifetimes']);
        $this->assertTrue($data['can_approve']);

        // Approving creates an API token: tokens.manage
        $this->customRole('auditor', ['audit.view']);
        $noTokens = $this->userWithRole('auditor');
        $this->signedIn($noTokens)->getJson("/api/oauth2/requests/{$id}")->assertForbidden();
    }

    public function test_approval_is_limited_to_what_the_user_may_grant(): void
    {
        $client = $this->register()['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client));
        $developer = $this->userWithRole('developer');

        $this->approve($id, $developer, ['secrets.view_values'])->assertUnprocessable()->assertJsonValidationErrors('abilities');
        $this->approve($id, $developer, ['projects.view'], 45)->assertUnprocessable()->assertJsonValidationErrors('expires_in_days');
        $this->assertSame(OAuthRequest::PENDING, OAuthRequest::find($id)->status);

        $this->approve($id, $developer, ['projects.deploy'])->assertOk();
        $this->assertSame(['projects.view', 'projects.deploy'], OAuthRequest::find($id)->abilities, 'implied permissions are added');
        $this->assertTrue(AuditEvent::where('action', 'oauth.consent.approve')->where('target_id', $client)->exists());
    }

    public function test_an_answered_or_expired_request_cannot_be_approved_again(): void
    {
        $client = $this->register()['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client));

        $this->approve($id)->assertOk();
        $this->approve($id)->assertStatus(410);

        $other = $this->requestIdFrom($this->authorizeUrl($client));
        $this->travel(11)->minutes();
        $this->signedIn($this->owner)->getJson("/api/oauth2/requests/{$other}")->assertStatus(410);
        $this->signedIn($this->owner)->getJson('/api/oauth2/requests/01JAAAAAAAAAAAAAAAAAAAAAAA')->assertNotFound();
    }

    public function test_deny_sends_the_browser_back_with_access_denied(): void
    {
        $client = $this->register()['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client));

        $url = $this->signedIn($this->owner)->postJson("/api/oauth2/requests/{$id}/deny")->assertOk()->json('redirect_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('access_denied', $q['error']);
        $this->assertSame('st-123', $q['state']);
        $this->assertSame(OAuthRequest::DENIED, OAuthRequest::find($id)->status);
        $this->assertTrue(AuditEvent::where('action', 'oauth.consent.deny')->exists());
    }

    // ── token ─────────────────────────────────────────────────────────────────

    public function test_the_code_becomes_a_scoped_api_token_for_mcp(): void
    {
        [$client, $code] = $this->approvedCode(['projects.view'], 30);

        $response = $this->exchange($client, $code)->assertOk();
        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertSame('projects.view', $response->json('scope'));
        $this->assertEqualsWithDelta(30 * 86400, $response->json('expires_in'), 5);
        // RFC 6749 §5.1: no-store (Symfony adds "private" to a Cache-Control without public/private)
        $this->assertContains('no-store', array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));

        $token = PersonalAccessToken::findToken($response->json('access_token'));
        $this->assertSame('Claude Code (MCP)', $token->name);
        $this->assertSame(['projects.view'], $token->abilities);
        $this->assertSame($client, $token->oauth_client_id);
        $this->assertSame('http://localhost/mcp', $token->oauth_resource);
        $this->assertSame((string) $this->owner->id, (string) $token->tokenable_id);

        // The token opens /mcp with exactly its scope
        $plain = $response->json('access_token');
        $session = $this->mcpInitialize($plain);
        $tools = $this->mcpToolNames($plain, $session);
        $this->assertContains('projects_list', $tools);
        $this->assertNotContains('deploy', $tools);

        $issued = AuditEvent::where('action', 'oauth.token.issue')->where('result', AuditEvent::RESULT_SUCCESS)->sole();
        $this->assertSame((string) $this->owner->id, $issued->actor_id);
        $this->assertSame(['projects.view'], $issued->metadata['abilities']);
        $this->assertStringNotContainsString($plain, json_encode(AuditEvent::all()->toArray()));
    }

    public function test_a_token_without_lifetime_lasts_until_revoked(): void
    {
        [$client, $code] = $this->approvedCode(['*'], null);

        $response = $this->exchange($client, $code)->assertOk();
        $this->assertNull($response->json('expires_in'));
        $this->assertSame('*', $response->json('scope'));
        $this->assertNull(PersonalAccessToken::findToken($response->json('access_token'))->expires_at);
    }

    public function test_pkce_redirect_and_resource_must_match(): void
    {
        [$client, $code] = $this->approvedCode();

        $this->exchange($client, $code, ['code_verifier' => str_repeat('a', 43)])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->exchange($client, $code, ['redirect_uri' => 'http://127.0.0.1:1/other'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->exchange($client, $code, ['resource' => 'http://localhost/mcp/servers/x'])->assertStatus(400)->assertJsonPath('error', 'invalid_target');
        $this->exchange($client, $code, ['grant_type' => 'refresh_token'])->assertStatus(400)->assertJsonPath('error', 'unsupported_grant_type');
        $this->exchange('rmc_other', $code)->assertStatus(401)->assertJsonPath('error', 'invalid_client');

        $this->exchange($client, $code)->assertOk();
    }

    public function test_codes_expire(): void
    {
        [$client, $code] = $this->approvedCode();

        $this->travel(3)->minutes();

        $this->exchange($client, $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    public function test_a_reused_code_revokes_the_token_it_produced(): void
    {
        [$client, $code] = $this->approvedCode();

        $plain = $this->exchange($client, $code)->assertOk()->json('access_token');
        $this->assertNotNull(PersonalAccessToken::findToken($plain));

        $this->exchange($client, $code)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
        $this->assertNull(PersonalAccessToken::findToken($plain), 'the token of a replayed code is revoked');
        $this->assertTrue(AuditEvent::where('action', 'oauth.token.issue')->where('result', AuditEvent::RESULT_FAILURE)->get()->contains(fn ($e) => ($e->metadata['reason'] ?? null) === 'code_reused'));
    }

    public function test_confidential_clients_authenticate_at_the_token_endpoint(): void
    {
        $registered = $this->register(['token_endpoint_auth_method' => 'client_secret_basic']);
        $client = $registered['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client));
        $code = $this->codeFrom($this->approve($id)->json('redirect_url'));

        $this->exchange($client, $code, ['client_secret' => 'wrong'])->assertStatus(401)->assertJsonPath('error', 'invalid_client');

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Basic '.base64_encode($client.':'.$registered['client_secret']))
            ->post('/oauth2/token', [
                'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER,
            ])->assertOk();
    }

    // ── revocation & connected clients ────────────────────────────────────────

    public function test_the_client_revokes_its_token(): void
    {
        [$client, $code] = $this->approvedCode();
        $plain = $this->exchange($client, $code)->json('access_token');

        $this->post('/oauth2/revoke', ['token' => $plain, 'client_id' => $client])->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($plain));
        $this->assertTrue(AuditEvent::where('action', 'oauth.token.revoke')->exists());

        // Unknown tokens are not an error (RFC 7009)
        $this->post('/oauth2/revoke', ['token' => 'nope', 'client_id' => $client])->assertOk();
    }

    public function test_connected_clients_are_listed_and_revoked_by_the_user(): void
    {
        [$client, $code] = $this->approvedCode(['projects.view', 'logs.view']);
        $plain = $this->exchange($client, $code)->json('access_token');

        $list = $this->signedIn($this->owner)->getJson('/api/mcp/clients')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($client, $list[0]['client_id']);
        $this->assertSame('Claude Code', $list[0]['name']);
        $this->assertSame(['127.0.0.1'], $list[0]['redirect_hosts']);
        $this->assertSame(['projects.view', 'logs.view'], $list[0]['tokens'][0]['abilities']);
        $this->assertSame('http://localhost/mcp', $list[0]['tokens'][0]['resource']);

        // The token is an ordinary API token as well (Settings → API tokens)
        $this->assertContains('Claude Code (MCP)', array_column($this->signedIn($this->owner)->getJson('/api/tokens')->json('tokens'), 'name'));

        $this->signedIn($this->owner)->deleteJson("/api/mcp/clients/{$client}")->assertOk()->assertJsonPath('revoked', 1);
        $this->assertNull(PersonalAccessToken::findToken($plain));
        $this->assertSame('mcp.client.revoke', AuditEvent::where('target_id', $client)->latest('occurred_at')->first()->action);

        $this->signedIn($this->owner)->deleteJson("/api/mcp/clients/{$client}")->assertNotFound();
    }

    public function test_hosted_server_tokens_carry_only_the_call_permission_and_audience(): void
    {
        $project = $this->containerProject('weather');
        HostedServer::newFor($project, ['access_mode' => HostedServer::OAUTH])->save();
        $client = $this->register()['client_id'];

        $id = $this->requestIdFrom($this->authorizeUrl($client, ['resource' => 'http://localhost/mcp/servers/weather', 'scope' => 'projects.deploy']));
        $data = $this->signedIn($this->owner)->getJson("/api/oauth2/requests/{$id}")->json('data');
        $this->assertSame('hosted', $data['resource_type']);
        $this->assertSame(['mcp.servers.call'], $data['fixed_scopes']);
        $this->assertSame('weather', $data['hosted_server']['handle']);
        $this->assertSame('weather', $data['hosted_server']['project_name']);

        $code = $this->codeFrom($this->approve($id, null, ['*'])->assertOk()->json('redirect_url'));
        $plain = $this->exchange($client, $code, ['resource' => 'http://localhost/mcp/servers/weather'])->assertOk()->json('access_token');
        $token = PersonalAccessToken::findToken($plain);
        $this->assertSame(['mcp.servers.call'], $token->abilities);
        $this->assertSame('http://localhost/mcp/servers/weather', $token->oauth_resource);

        // Its audience is the hosted server, not the RunR MCP server
        $this->mcpPost($plain, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25']])
            ->assertUnauthorized();
    }

    public function test_a_viewer_without_the_call_permission_cannot_approve_for_a_hosted_server(): void
    {
        $project = $this->containerProject('weather');
        HostedServer::newFor($project, ['access_mode' => HostedServer::TOKEN])->save();
        $client = $this->register()['client_id'];
        $id = $this->requestIdFrom($this->authorizeUrl($client, ['resource' => 'http://localhost/mcp/servers/weather']));

        $this->customRole('token-only', ['tokens.manage']);
        $user = $this->userWithRole('token-only');

        $this->assertFalse($this->signedIn($user)->getJson("/api/oauth2/requests/{$id}")->json('data.can_approve'));
        $this->approve($id, $user, ['mcp.servers.call'])->assertForbidden();
    }
}
