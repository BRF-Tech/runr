<?php

namespace App\Mcp\OAuth;

use App\Mcp\Models\HostedServer;
use App\Mcp\Models\OAuthClient;
use App\Mcp\Models\OAuthRequest;
use App\Mcp\Support\McpAudit;
use App\Mcp\Support\McpUrls;
use App\Models\User;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use App\Support\Rbac\TokenAbilities;
use App\Support\TenantAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The OAuth 2.1 authorization server MCP clients connect through (MCP authorization, docs/mcp.md):
 * dynamic client registration (RFC 7591), authorization code + PKCE S256 with resource
 * indicators (RFC 8707), consent in the console, token revocation (RFC 7009). It issues no tokens
 * of its own kind: an approved request becomes a RunR API token (Sanctum) with the permissions
 * and lifetime the user picked, pinned to the organization it was approved in and bound to the
 * resource (/mcp or one hosted MCP server). No refresh tokens — when the token expires the
 * client runs the one-click flow again.
 */
final class AuthorizationServer
{
    public function __construct(private readonly McpAudit $audit) {}

    // ── Metadata (RFC 8414) ─────────────────────────────────────────────────

    public function metadata(Request $request): array
    {
        $metadata = [
            'issuer' => McpUrls::issuer($request),
            'authorization_endpoint' => McpUrls::authorizationEndpoint($request),
            'token_endpoint' => McpUrls::tokenEndpoint($request),
            'revocation_endpoint' => McpUrls::revocationEndpoint($request),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => OAuthClient::AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported' => OAuthClient::AUTH_METHODS,
            'scopes_supported' => PermissionCatalog::keys(),
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => false,
        ];
        if (config('mcp.oauth.open_registration', true)) {
            $metadata['registration_endpoint'] = McpUrls::registrationEndpoint($request);
        }

        return $metadata;
    }

    /** Protected resource metadata (RFC 9728) of /mcp ('') or a hosted server. */
    public function resourceMetadata(Request $request, string $handle = ''): array
    {
        return [
            'resource' => $handle === '' ? McpUrls::endpoint($request) : McpUrls::hostedServer($handle, $request),
            'authorization_servers' => [McpUrls::issuer($request)],
            'scopes_supported' => $handle === '' ? PermissionCatalog::keys() : ['mcp.servers.call'],
            'bearer_methods_supported' => ['header'],
            'resource_name' => $handle === '' ? 'RunR' : $handle,
        ];
    }

    // ── Dynamic client registration (RFC 7591) ──────────────────────────────

    /** @throws OAuthException */
    public function register(Request $request): array
    {
        if (! config('mcp.oauth.enabled', true) || ! config('mcp.oauth.open_registration', true)) {
            throw new OAuthException('access_denied', 'Client registration is turned off on this server.', 403);
        }

        $data = $request->isJson() ? (array) $request->json()->all() : $request->all();

        $redirects = $data['redirect_uris'] ?? null;
        if (! is_array($redirects) || $redirects === [] || count($redirects) > 10 || ! array_is_list($redirects)) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris: one to ten redirect URIs are required.');
        }
        foreach ($redirects as $uri) {
            if (! RedirectUris::valid($uri)) {
                throw new OAuthException('invalid_redirect_uri', 'Not an allowed redirect URI: '.mb_substr((string) (is_string($uri) ? $uri : json_encode($uri)), 0, 200).' (https, http on a loopback host, or a private-use scheme; no fragment).');
            }
        }

        $method = $data['token_endpoint_auth_method'] ?? OAuthClient::AUTH_NONE;
        if (! is_string($method) || ! in_array($method, OAuthClient::AUTH_METHODS, true)) {
            throw new OAuthException('invalid_client_metadata', 'token_endpoint_auth_method must be one of: '.implode(', ', OAuthClient::AUTH_METHODS).'.');
        }

        $grants = $data['grant_types'] ?? ['authorization_code'];
        if (! is_array($grants) || ! in_array('authorization_code', $grants, true)) {
            throw new OAuthException('invalid_client_metadata', 'grant_types must include authorization_code.');
        }
        $responses = $data['response_types'] ?? ['code'];
        if (! is_array($responses) || ! in_array('code', $responses, true)) {
            throw new OAuthException('invalid_client_metadata', 'response_types must include code.');
        }

        $name = is_string($data['client_name'] ?? null) && trim($data['client_name']) !== '' ? mb_substr(trim($data['client_name']), 0, 100) : 'MCP client';
        $uri = self::optionalUrl($data['client_uri'] ?? null, 'client_uri');
        $logo = self::optionalUrl($data['logo_uri'] ?? null, 'logo_uri');

        $secret = $method === OAuthClient::AUTH_NONE ? null : 'rms_'.bin2hex(random_bytes(32));
        $client = OAuthClient::query()->create([
            'id' => OAuthClient::newId(),
            'name' => $name,
            'redirect_uris' => array_values(array_unique($redirects)),
            'grant_types' => ['authorization_code'],
            'token_endpoint_auth_method' => $method,
            'secret_hash' => $secret ? hash('sha256', $secret) : null,
            'client_uri' => $uri,
            'logo_uri' => $logo,
            'software_id' => self::clip($data['software_id'] ?? null, 255),
            'software_version' => self::clip($data['software_version'] ?? null, 100),
            'created_ip' => $request->ip(),
        ]);

        $this->audit->clientRegistered($request, $client->id, $client->name, $client->redirectHosts(), $method);

        return array_filter([
            'client_id' => $client->id,
            'client_id_issued_at' => $client->created_at?->getTimestamp(),
            'client_secret' => $secret,
            'client_secret_expires_at' => $secret ? 0 : null,
            'client_name' => $client->name,
            'client_uri' => $client->client_uri,
            'logo_uri' => $client->logo_uri,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $method,
        ], fn ($v) => $v !== null);
    }

    // ── Authorization endpoint ──────────────────────────────────────────────

    /**
     * Validates GET /oauth2/authorize and parks it for the consent page. Returns where to send the
     * browser: the consent page, the client's redirect URI with an error, or — when the client or
     * its redirect URI cannot be trusted — the console's error page.
     */
    public function authorize(Request $request): string
    {
        $q = $request->query();

        $client = is_string($q['client_id'] ?? null) ? OAuthClient::query()->find($q['client_id']) : null;
        if (! config('mcp.oauth.enabled', true) || ! $client) {
            return McpUrls::consentError('invalid_client', 'This application is not registered with this server. Connect it again from the application.', $request);
        }

        $redirect = $q['redirect_uri'] ?? null;
        if ($redirect === null && count((array) $client->redirect_uris) === 1) {
            $redirect = $client->redirect_uris[0];
        }
        if (! is_string($redirect) || ! $client->allowsRedirect($redirect)) {
            return McpUrls::consentError('invalid_request', 'The redirect URI is not registered for this application.', $request);
        }

        $state = is_string($q['state'] ?? null) ? $q['state'] : null;
        $fail = fn (string $error, string $description) => RedirectUris::with($redirect, [
            'error' => $error, 'error_description' => $description, 'state' => $state, 'iss' => McpUrls::issuer($request),
        ]);

        if (($q['response_type'] ?? null) !== 'code') {
            return $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        if (($q['code_challenge_method'] ?? null) !== 'S256' || ! is_string($q['code_challenge'] ?? null) || ! preg_match(Pkce::CHALLENGE_PATTERN, $q['code_challenge'])) {
            return $fail('invalid_request', 'PKCE is required: code_challenge with code_challenge_method=S256.');
        }
        if ($state !== null && strlen($state) > 1000) {
            return $fail('invalid_request', 'state is too long.');
        }

        $resource = is_string($q['resource'] ?? null) && $q['resource'] !== '' ? rtrim($q['resource'], '/') : McpUrls::endpoint($request);
        $handle = McpUrls::resourceHandle($resource, $request);
        $hosted = $handle !== null && $handle !== '' ? HostedServer::forHandle($handle) : null;
        if ($handle === null || ($handle !== '' && (! $hosted || ! $hosted->requiresToken()))) {
            return $fail('invalid_target', 'The resource is not served by this authorization server.');
        }

        $scopes = $hosted
            ? ['mcp.servers.call']
            : array_values(array_intersect(
                array_filter(explode(' ', is_string($q['scope'] ?? null) ? $q['scope'] : '')),
                array_merge(PermissionCatalog::keys(), [PermissionCatalog::WILDCARD]),
            ));

        $pending = OAuthRequest::query()->create([
            'client_id' => $client->id,
            'redirect_uri' => $redirect,
            'state' => $state,
            'scopes' => $scopes,
            'resource' => $resource,
            'code_challenge' => $q['code_challenge'],
            'code_challenge_method' => 'S256',
            'status' => OAuthRequest::PENDING,
            'expires_at' => now()->addMinutes((int) config('mcp.oauth.request_ttl_minutes', 10)),
        ]);

        return McpUrls::consentPage($pending->id, $request);
    }

    // ── Consent (console API) ───────────────────────────────────────────────

    /** What the consent page shows for a pending request, in the caller's current organization. */
    public function describe(OAuthRequest $pending, Request $request, Authenticatable $user): array
    {
        $this->assertPending($pending);

        $client = $pending->client;
        $hosted = $this->hostedServer($pending, $request);
        $tenant = TenantAccess::current();
        $catalog = PermissionCatalog::permissions();

        $required = null;
        if ($hosted && $hosted->tenant_id !== null && $hosted->tenant_id !== ($tenant['id'] ?? null)) {
            $required = ['id' => $hosted->tenant_id, 'name' => self::tenantName($hosted->tenant_id)];
        }

        $available = $hosted
            ? ['mcp.servers.call']
            : app(Rbac::class)->grantablePermissions($user);

        $project = $hosted && ! $required ? $hosted->project() : null;

        return [
            'id' => $pending->id,
            'status' => $pending->status,
            'expires_at' => $pending->expires_at?->toIso8601String(),
            'client' => [
                'id' => $client?->id,
                'name' => $client?->name ?? 'MCP client',
                'client_uri' => $client?->client_uri,
                'redirect_host' => self::host($pending->redirect_uri),
                'redirect_uri' => $pending->redirect_uri,
                'registered_at' => $client?->created_at?->toIso8601String(),
            ],
            'resource' => $pending->resource,
            'resource_type' => $hosted ? 'hosted' : 'runr',
            'hosted_server' => $hosted ? [
                'handle' => $hosted->handle,
                'project_id' => $hosted->project_id,
                'project_name' => $project?->name,
                'access_mode' => $hosted->access_mode,
            ] : null,
            'requested_scopes' => array_values($pending->scopes ?? []),
            'fixed_scopes' => $hosted ? ['mcp.servers.call'] : null,
            'available_abilities' => array_values(array_map(fn (string $key) => [
                'key' => $key,
                'group' => $catalog[$key]['group'] ?? 'other',
                'scope' => $catalog[$key]['scope'] ?? 'org',
            ], array_filter($available, fn ($key) => isset($catalog[$key])))),
            'tenant' => $tenant ? ['id' => $tenant['id'], 'name' => $tenant['name'] ?? null] : null,
            'required_tenant' => $required,
            'token_lifetimes' => array_values((array) config('mcp.oauth.token_lifetimes')),
            'default_token_lifetime' => config('mcp.oauth.default_token_lifetime', 30),
            'can_approve' => ! $required && (! $hosted || ($project && app(Rbac::class)->allows($user, 'mcp.servers.call', $project))),
        ];
    }

    /**
     * The user consents: a one-time code for the client. Returns the redirect URL.
     *
     * @param  list<string>  $abilities
     */
    public function approve(OAuthRequest $pending, Request $request, Authenticatable $user, array $abilities, ?int $lifetimeDays): string
    {
        $this->assertPending($pending);

        $lifetimes = (array) config('mcp.oauth.token_lifetimes', [30]);
        if (! in_array($lifetimeDays, $lifetimes, true)) {
            throw ValidationException::withMessages(['expires_in_days' => __('mcp.lifetime_invalid')]);
        }

        $hosted = $this->hostedServer($pending, $request);
        if ($hosted) {
            if ($hosted->tenant_id !== null && $hosted->tenant_id !== TenantAccess::currentId()) {
                throw new HttpException(409, __('mcp.switch_organization'));
            }
            $project = $hosted->project();
            if (! $project || ! app(Rbac::class)->allows($user, 'mcp.servers.call', $project)) {
                throw new HttpException(403, __('mcp.call_not_permitted'));
            }
            $abilities = ['mcp.servers.call'];
        } else {
            $abilities = TokenAbilities::resolve($user, $abilities);
        }

        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $pending->forceFill([
            'status' => OAuthRequest::APPROVED,
            'user_id' => (string) $user->getAuthIdentifier(),
            'tenant_id' => TenantAccess::currentId(),
            'abilities' => $abilities,
            'lifetime_days' => $lifetimeDays,
            'code_hash' => hash('sha256', $code),
            'code_expires_at' => now()->addSeconds((int) config('mcp.oauth.code_ttl_seconds', 120)),
        ])->save();

        return RedirectUris::with($pending->redirect_uri, ['code' => $code, 'state' => $pending->state, 'iss' => McpUrls::issuer($request)]);
    }

    public function deny(OAuthRequest $pending, Request $request, Authenticatable $user): string
    {
        $this->assertPending($pending);

        $pending->forceFill(['status' => OAuthRequest::DENIED, 'user_id' => (string) $user->getAuthIdentifier()])->save();

        return RedirectUris::with($pending->redirect_uri, [
            'error' => 'access_denied',
            'error_description' => 'The user denied the request.',
            'state' => $pending->state,
            'iss' => McpUrls::issuer($request),
        ]);
    }

    // ── Token endpoint ──────────────────────────────────────────────────────

    /** @throws OAuthException */
    public function token(Request $request): array
    {
        $p = $this->parameters($request);

        if (($p['grant_type'] ?? null) !== 'authorization_code') {
            throw new OAuthException('unsupported_grant_type', 'Only grant_type=authorization_code is supported.');
        }

        $client = $this->authenticateClient($request, $p);

        $code = is_string($p['code'] ?? null) ? $p['code'] : '';
        $pending = $code !== '' ? OAuthRequest::query()->where('code_hash', hash('sha256', $code))->first() : null;
        if (! $pending || $pending->client_id !== $client->id) {
            $this->audit->tokenRefused($request, $client->id, 'unknown_code');
            throw new OAuthException('invalid_grant', 'The authorization code is not valid.');
        }

        if ($pending->status === OAuthRequest::EXCHANGED) {
            // A code presented twice: the token it produced is revoked (RFC 6749 4.1.2)
            if ($pending->token_id) {
                PersonalAccessToken::query()->whereKey($pending->token_id)->delete();
            }
            $pending->forceFill(['token_id' => null])->save();
            $this->audit->tokenRefused($request, $client->id, 'code_reused');
            throw new OAuthException('invalid_grant', 'The authorization code was already used.');
        }

        if ($pending->status !== OAuthRequest::APPROVED || ! $pending->code_expires_at || $pending->code_expires_at->isPast()) {
            $this->audit->tokenRefused($request, $client->id, 'expired_code');
            throw new OAuthException('invalid_grant', 'The authorization code has expired.');
        }
        if (($p['redirect_uri'] ?? null) !== $pending->redirect_uri) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        if (! is_string($p['code_verifier'] ?? null) || ! Pkce::verify($p['code_verifier'], $pending->code_challenge)) {
            $this->audit->tokenRefused($request, $client->id, 'pkce_failed');
            throw new OAuthException('invalid_grant', 'The PKCE code_verifier does not match.');
        }
        if (isset($p['resource']) && rtrim((string) $p['resource'], '/') !== $pending->resource) {
            throw new OAuthException('invalid_target', 'resource does not match the authorization request.');
        }

        $user = $pending->user_id !== null ? $this->user($pending->user_id) : null;
        if (! $user || ($user->is_disabled ?? false)) {
            throw new OAuthException('invalid_grant', 'The account that approved the request is not available.');
        }

        $expiresAt = $pending->lifetime_days ? now()->addDays($pending->lifetime_days) : null;
        $abilities = array_values($pending->abilities ?: [PermissionCatalog::WILDCARD]);
        $issued = $user->createToken(mb_substr($client->name.' (MCP)', 0, 255), $abilities, $expiresAt);

        $attributes = ['oauth_client_id' => $client->id, 'oauth_resource' => $pending->resource];
        if (config('tenancy.enabled') && $pending->tenant_id) {
            $attributes['tenant_id'] = $pending->tenant_id; // the token works in this organization only
        }
        $issued->accessToken->forceFill($attributes)->save();

        $pending->forceFill(['status' => OAuthRequest::EXCHANGED, 'token_id' => $issued->accessToken->getKey()])->save();
        $client->forceFill(['last_used_at' => now()])->save();

        $this->audit->tokenIssued($request, $user, $client->id, $client->name, $issued->accessToken->getKey(), $abilities, $expiresAt?->toIso8601String(), $pending->resource, $pending->tenant_id);

        return array_filter([
            'access_token' => $issued->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresAt ? max(1, (int) now()->diffInSeconds($expiresAt, true)) : null,
            'scope' => implode(' ', $abilities),
        ], fn ($v) => $v !== null);
    }

    // ── Revocation (RFC 7009) ───────────────────────────────────────────────

    /** @throws OAuthException */
    public function revoke(Request $request): void
    {
        $p = $this->parameters($request);
        $client = $this->authenticateClient($request, $p);

        $token = is_string($p['token'] ?? null) ? PersonalAccessToken::findToken($p['token']) : null;
        if (! $token || $token->getAttribute('oauth_client_id') !== $client->id) {
            return; // unknown tokens are not an error (RFC 7009 2.2)
        }

        $user = $token->tokenable;
        $tenantId = $token->getAttribute('tenant_id');
        $id = $token->getKey();
        $token->delete();

        if ($user instanceof Authenticatable) {
            $this->audit->tokenRevoked($request, $user, $client->id, $id, is_string($tenantId) ? $tenantId : null);
        }
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** Form-encoded (RFC 6749) or JSON parameters. */
    private function parameters(Request $request): array
    {
        return $request->isJson() ? (array) $request->json()->all() : $request->request->all();
    }

    /** @throws OAuthException */
    private function authenticateClient(Request $request, array $p): OAuthClient
    {
        $id = $p['client_id'] ?? null;
        $secret = $p['client_secret'] ?? null;

        $basic = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($basic, 'Basic ')) {
            $decoded = base64_decode(substr($basic, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = array_map('rawurldecode', explode(':', $decoded, 2));
            }
        }

        $client = is_string($id) && $id !== '' ? OAuthClient::query()->find($id) : null;
        if (! $client) {
            throw new OAuthException('invalid_client', 'Unknown client.', 401);
        }
        if ($client->isConfidential() && ! $client->verifySecret(is_string($secret) ? $secret : null)) {
            throw new OAuthException('invalid_client', 'Client authentication failed.', 401);
        }

        return $client;
    }

    private function assertPending(OAuthRequest $pending): void
    {
        if (! $pending->isPendingConsent()) {
            throw new HttpException(410, __('mcp.request_expired'));
        }
    }

    private function hostedServer(OAuthRequest $pending, Request $request): ?HostedServer
    {
        $handle = McpUrls::resourceHandle($pending->resource, $request);

        return $handle !== null && $handle !== '' ? HostedServer::forHandle($handle) : null;
    }

    private function user(string $id): ?User
    {
        $model = new User;
        if (config('database.connections.central')) {
            $model->setConnection('central');
        }
        $user = $model->newQuery()->whereKey($id)->first();

        return $user instanceof User ? $user : null;
    }

    private static function tenantName(string $id): ?string
    {
        $class = 'Modules\\Tenancy\\Models\\Tenant';
        if (! class_exists($class)) {
            return null;
        }

        try {
            return $class::query()->find($id)?->name;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function host(string $uri): string
    {
        $parts = parse_url($uri);

        return (string) ($parts['host'] ?? (($parts['scheme'] ?? '').':'));
    }

    /** @throws OAuthException */
    private static function optionalUrl(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || strlen($value) > 500 || ! str_starts_with(strtolower($value), 'https://') || ! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new OAuthException('invalid_client_metadata', "{$field} must be an https URL.");
        }

        return $value;
    }

    private static function clip(mixed $value, int $length): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, $length) : null;
    }
}
