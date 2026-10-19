<?php

namespace Modules\AuthProvider\Services\Concerns;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Services\OAuthFlowException;

/**
 * Linking an external identity to the signed-in user (Account settings → Linked accounts).
 *
 * The callback of a link flow does not attach the identity itself: the browser that completed the
 * provider's sign-in may not be the one of the user who started the link (a link URL sent to
 * someone else). It stages the identity under a one-time code and redirects to the console, whose
 * signed-in user confirms it (POST /api/oauth/link/complete) — only the user who started the link,
 * in the same organization, within link_ttl_minutes.
 */
trait OAuthLinkTrait
{
    /**
     * @param  array<string, mixed>  $flow
     * @return array{link_code: string}|array{error: string}
     */
    protected function stageLink(AuthProvider $provider, string $driver, SocialiteUser $externalUser, array $flow): array
    {
        $userId = (string) ($flow['user_id'] ?? '');
        $rawId = (string) $externalUser->getId();
        if ($userId === '' || $rawId === '') {
            return ['error' => __('api.oauth.link_invalid')];
        }

        $subject = $this->configService->subjectKey($provider, $driver, $rawId);
        $existing = $this->findSocialAccount($provider, $driver, $subject, $rawId);
        if ($existing && (string) $existing->user_id !== $userId) {
            return ['error' => __('api.oauth.identity_taken')];
        }

        $code = Str::random(64);
        Cache::put($this->linkCacheKey($code), [
            'user_id' => $userId,
            'tenant' => $flow['tenant'] ?? null,
            'provider_id' => (string) $provider->getKey(),
            'driver' => $driver,
            'subject' => $subject,
            'email' => (string) ($externalUser->getEmail() ?? ''),
            'name' => $externalUser->getName(),
            'avatar' => $externalUser->getAvatar(),
            // The provider's tokens stay encrypted, as in the social_accounts columns
            'tokens' => Crypt::encryptString((string) json_encode([
                'access_token' => (string) $externalUser->token,
                'refresh_token' => $externalUser->refreshToken,
                'expires_in' => $externalUser->expiresIn,
            ])),
        ], now()->addMinutes(max(1, (int) config('authprovider.link_ttl_minutes', 5))));

        return ['link_code' => $code];
    }

    /**
     * The signed-in user confirms a staged link.
     *
     * @return array{account: SocialAccount, created: bool}
     *
     * @throws OAuthFlowException
     */
    public function completeLink(User $user, string $code): array
    {
        $pending = $code !== '' && strlen($code) <= 128 ? Cache::pull($this->linkCacheKey($code)) : null;

        if (! is_array($pending)
            || ($pending['user_id'] ?? null) !== (string) $user->getKey()
            || ($pending['tenant'] ?? null) !== $this->tenantContext->currentSlug()) {
            throw new OAuthFlowException(__('api.oauth.link_invalid'), 422);
        }

        $provider = $this->configService->findProvider((string) $pending['driver'], (string) $pending['provider_id']);
        if (! $provider) {
            throw new OAuthFlowException(__('api.oauth.provider_not_found'), 404);
        }

        $existing = SocialAccount::query()
            ->where('provider', $pending['driver'])
            ->where('provider_user_id', $pending['subject'])
            ->first();
        if ($existing) {
            if ((string) $existing->user_id !== (string) $user->getKey()) {
                throw new OAuthFlowException(__('api.oauth.identity_taken'), 409);
            }

            return ['account' => $existing, 'created' => false];
        }

        $tokens = json_decode(Crypt::decryptString((string) $pending['tokens']), true);
        $tokens = is_array($tokens) ? $tokens : [];
        $expiresIn = is_numeric($tokens['expires_in'] ?? null) ? (int) $tokens['expires_in'] : null;

        try {
            $account = SocialAccount::query()->create([
                'user_id' => $user->getKey(),
                'provider' => $pending['driver'],
                'provider_user_id' => $pending['subject'],
                'email' => (string) $pending['email'],
                'name' => $pending['name'] ?? null,
                'avatar_url' => $pending['avatar'] ?? null,
                'access_token' => (string) ($tokens['access_token'] ?? ''),
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new OAuthFlowException(__('api.oauth.identity_taken'), 409);
        }

        return ['account' => $account, 'created' => true];
    }

    private function linkCacheKey(string $code): string
    {
        return 'authprovider:oauth:link:'.hash('sha256', $code);
    }
}
