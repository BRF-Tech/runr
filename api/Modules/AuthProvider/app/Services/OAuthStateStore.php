<?php

namespace Modules\AuthProvider\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * The `state` of an OAuth / OIDC authorization request. What the callback needs — the provider
 * row, sign-in or link (and for whom), the PKCE verifier and the OIDC nonce — stays on the server
 * under a random ID; the state the browser carries is that ID, the tenant and the expiry,
 * encrypted. The server-side entry is used once: a replayed callback finds nothing.
 *
 * The tenant travels in the state because the entry is stored in the tenant's context (the cache
 * is scoped per tenant): the callback enters the tenant first, then takes the entry.
 */
class OAuthStateStore
{
    private const PREFIX = 'authprovider:oauth:state:';

    /** @param array<string, mixed> $flow */
    public function issue(array $flow, ?string $tenantSlug): string
    {
        $sid = Str::random(40);
        $expires = now()->addMinutes(max(1, (int) config('authprovider.state_ttl_minutes', 5)));

        Cache::put(self::PREFIX.$sid, ['tenant' => $tenantSlug] + $flow, $expires);

        return Crypt::encryptString((string) json_encode([
            'sid' => $sid,
            'tenant' => $tenantSlug,
            'exp' => $expires->timestamp,
        ]));
    }

    /** @return array{sid: string, tenant: ?string}|null null: not ours, malformed or expired */
    public function decode(string $state): ?array
    {
        if ($state === '' || strlen($state) > 4096) {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || ! is_string($payload['sid'] ?? null) || ! is_int($payload['exp'] ?? null)) {
            return null;
        }

        if (now()->timestamp > $payload['exp']) {
            return null;
        }

        $tenant = $payload['tenant'] ?? null;

        return ['sid' => $payload['sid'], 'tenant' => is_string($tenant) && $tenant !== '' ? $tenant : null];
    }

    /**
     * The flow of a decoded state, once.
     *
     * @return array<string, mixed>|null
     */
    public function pull(string $sid): ?array
    {
        $flow = Cache::pull(self::PREFIX.$sid);

        return is_array($flow) ? $flow : null;
    }
}
