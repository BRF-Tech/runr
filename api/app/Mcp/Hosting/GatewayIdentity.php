<?php

namespace App\Mcp\Hosting;

use App\Workflows\Engine\Signature;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who calls a hosted MCP server, as the gateway tells the server (docs/mcp.md, "Gateway headers"):
 *
 *   X-RunR-Identity   base64url(JSON {auth, user: {id, name, email}|null, tenant, token_id, client_id, scopes})
 *   X-RunR-Signature  t=<unix>,v1=<hex>[,v1=<hex>]
 *                     hex = HMAC-SHA256(RUNR_MCP_GATEWAY_KEY, "<t>." + METHOD + "\n" + PATH + "\n"
 *                           + hex(SHA-256(body)) + "\n" + X-RunR-Identity)
 *
 * PATH is the URL path the server receives (no query). Two v1 during a key rotation (current and
 * previous key). The same HMAC construction as the durable workflows protocol (Signature).
 */
final class GatewayIdentity
{
    public const AUTH_PUBLIC = 'public';

    public const AUTH_TOKEN = 'token';

    public const AUTH_OAUTH = 'oauth';

    public const AUTH_CONSOLE = 'console';

    public function __construct(
        public readonly string $auth,
        public readonly ?Authenticatable $user = null,
        public readonly ?string $tenantId = null,
        public readonly ?PersonalAccessToken $token = null,
    ) {}

    public static function anonymous(?string $tenantId): self
    {
        return new self(self::AUTH_PUBLIC, null, $tenantId);
    }

    public function toArray(): array
    {
        $abilities = $this->token ? array_values((array) ($this->token->abilities ?? [])) : [];

        return [
            'auth' => $this->auth,
            'user' => $this->user ? [
                'id' => (string) $this->user->getAuthIdentifier(),
                'name' => $this->user->name ?? $this->user->username ?? null,
                'email' => $this->user->email ?? null,
            ] : null,
            'tenant' => $this->tenantId,
            'token_id' => $this->token ? (string) $this->token->getKey() : null,
            'client_id' => $this->token?->getAttribute('oauth_client_id'),
            'scopes' => $abilities,
        ];
    }

    /** The X-RunR-Identity header value. */
    public function header(): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** The X-RunR-Signature header value for one forwarded request. */
    public static function sign(array $keys, string $method, string $path, string $body, string $identity, ?int $timestamp = null): string
    {
        return Signature::sign(self::canonical($method, $path, $body, $identity), $keys, $timestamp);
    }

    public static function canonical(string $method, string $path, string $body, string $identity): string
    {
        return strtoupper($method)."\n".$path."\n".hash('sha256', $body)."\n".$identity;
    }

    /** Actor fields of the call log. */
    public function actor(): array
    {
        if (! $this->user) {
            return ['type' => 'anonymous', 'id' => null, 'name' => null];
        }

        return [
            'type' => $this->token && $this->token->name !== 'api' ? 'api_token' : 'user',
            'id' => (string) $this->user->getAuthIdentifier(),
            'name' => $this->user->name ?? $this->user->username ?? null,
        ];
    }
}
