<?php

namespace App\Services\Auth;

use App\Models\SignInHandoff;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;

/**
 * SSO sign-in handoff (docs/security.md): instead of putting the API token into the redirect
 * URL (browser history, proxy and access logs, Referer), an OAuth / OIDC / SAML callback
 * redirects with a random one-time code. The console exchanges it once, within TTL_SECONDS,
 * for the token (POST /api/oauth/exchange). The code is stored as its SHA-256 only and the token
 * encrypted; a code is gone after its first exchange, whether it succeeded or not.
 */
class SignInHandoffService
{
    public const TTL_SECONDS = 60;

    public function issue(Authenticatable $user, string $token): string
    {
        $this->pruneExpired();

        $code = Str::random(64);

        SignInHandoff::query()->create([
            'code_hash' => self::hash($code),
            'user_id' => (string) $user->getAuthIdentifier(),
            'token' => $token,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return $code;
    }

    /** @return array{token: string, user_id: string}|null null: unknown, used or expired code */
    public function redeem(string $code): ?array
    {
        if ($code === '' || strlen($code) > 128) {
            return null;
        }

        $hash = self::hash($code);
        $handoff = SignInHandoff::query()->where('code_hash', $hash)->first();
        if (! $handoff || ! hash_equals($handoff->code_hash, $hash)) {
            return null;
        }

        // One-time: whoever deletes the row owns the exchange (a concurrent second try gets 0)
        if (SignInHandoff::query()->whereKey($handoff->getKey())->delete() !== 1) {
            return null;
        }

        if ($handoff->expires_at->isPast()) {
            return null;
        }

        return ['token' => $handoff->token, 'user_id' => $handoff->user_id];
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    private function pruneExpired(): void
    {
        SignInHandoff::query()->where('expires_at', '<', now())->delete();
    }
}
