<?php

namespace Modules\AuthProvider\Services\Concerns;

use App\Models\User;
use App\Support\Auth\LoginTokens;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as SocialiteUser;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Services\SsoGroupSync;

trait OAuthCallbackTrait
{
    /**
     * The provider's user for the callback request, or why it is refused (a message for the user;
     * the reason is logged).
     *
     * @param  array<string, mixed>  $flow
     */
    protected function fetchExternalUser(AuthProvider $provider, array $flow): SocialiteUser|string
    {
        try {
            $user = $this->drivers->make($provider, $flow)->user();
            if (! $user instanceof SocialiteUser) {
                throw new \UnexpectedValueException('the driver returned no OAuth 2 user');
            }
        } catch (\Throwable $e) {
            Log::warning('OAuth sign-in failed', [
                'provider' => $provider->getKey(),
                'driver' => $provider->driver,
                'reason' => $e->getMessage(),
            ]);

            return __('api.oauth.provider_error');
        }

        // Google: `hd` in the authorization URL only narrows the account picker — the account
        // that signed in must belong to the hosted domain
        $domain = trim((string) $provider->google_hosted_domain);
        if ($provider->driver === 'google' && $domain !== '') {
            $hd = $user->getRaw()['hd'] ?? null;
            if (! is_string($hd) || strcasecmp($hd, $domain) !== 0) {
                Log::warning('OAuth sign-in outside the hosted domain', [
                    'provider' => $provider->getKey(),
                    'hosted_domain' => $domain,
                    'hd' => is_string($hd) ? $hd : null,
                ]);

                return __('api.oauth.hosted_domain_mismatch', ['domain' => $domain]);
            }
        }

        return $user;
    }

    protected function resolveUser(AuthProvider $provider, string $driver, $socialiteUser): array
    {
        $rawId = (string) $socialiteUser->getId();
        if ($rawId === '') {
            return ['error' => __('api.oauth.provider_error')];
        }
        $subject = $this->configService->subjectKey($provider, $driver, $rawId);

        // 1. Check social_accounts for existing link
        $socialAccount = $this->findSocialAccount($provider, $driver, $subject, $rawId);

        if ($socialAccount && $socialAccount->user) {
            $this->updateSocialTokens($socialAccount, $socialiteUser);

            return $this->signIn($provider, $socialAccount->user, $socialiteUser, false);
        }

        // 2. Check users table by email — only an address the provider vouches for
        $email = $socialiteUser->getEmail();
        if (! $email) {
            return ['error' => __('api.oauth.no_email')];
        }
        if ($this->emailUnverified($socialiteUser)) {
            return ['error' => __('api.oauth.email_unverified')];
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $this->createSocialAccount($user, $driver, $subject, $socialiteUser);

            return $this->signIn($provider, $user, $socialiteUser, false);
        }

        // 3. Auto-register if enabled
        if (! $provider->auto_register) {
            return ['error' => __('api.oauth.account_not_found')];
        }

        $user = $this->provisionUser($provider, $socialiteUser);
        $this->createSocialAccount($user, $driver, $subject, $socialiteUser);

        return $this->signIn($provider, $user, $socialiteUser, true);
    }

    /**
     * The social account of an external subject. Accounts of a self-managed GitLab instance
     * linked before GitLab subjects carried their instance hold the bare user ID; they can only
     * belong to that instance while it is the organization's only GitLab instance — then they
     * move to the scoped key on their next sign-in.
     */
    protected function findSocialAccount(AuthProvider $provider, string $driver, string $subject, string $rawId): ?SocialAccount
    {
        $account = SocialAccount::where('provider', $driver)->where('provider_user_id', $subject)->first();
        if ($account || $driver !== 'gitlab' || $subject === $rawId || ! $this->configService->isOnlyGitlabInstance($provider)) {
            return $account;
        }

        $legacy = SocialAccount::where('provider', $driver)->where('provider_user_id', $rawId)->first();
        $legacy?->update(['provider_user_id' => $subject]);

        return $legacy;
    }

    /** The provider states that it has not verified the address (OIDC `email_verified: false`). */
    protected function emailUnverified($externalUser): bool
    {
        $raw = method_exists($externalUser, 'getRaw') ? (array) $externalUser->getRaw() : [];
        $verified = $raw['email_verified'] ?? null;

        return $verified === false || $verified === 'false' || $verified === 0;
    }

    /**
     * Just-in-time provisioning: the account (central in multi-tenant installs — an existing
     * account joins the tenant) becomes a member with the provider's default role, else the
     * organization's default role. Group mappings may change it right after (signIn).
     */
    protected function provisionUser(AuthProvider $provider, $socialiteUser): User
    {
        $org = app(Rbac::class)->organization();
        $connection = $org->userConnection();
        $role = $provider->default_role && $provider->default_role !== PermissionCatalog::OWNER && app(Rbac::class)->roleExists($provider->default_role)
            ? $provider->default_role
            : app(AccessManager::class)->defaultRole();

        $user = $connection
            ? (User::on($connection)->where('email', $socialiteUser->getEmail())->first() ?? $this->createUserFromSocialite($socialiteUser, $connection))
            : $this->createUserFromSocialite($socialiteUser);

        if ($org->exists()) {
            $current = $org->memberRole($user->getKey());
            if ($current === null) {
                $org->addMember($user->getKey(), $role);
            } elseif (! $connection && $current !== PermissionCatalog::OWNER) {
                // Single-tenant: the account got the default role when it was created; the
                // provider's default wins (the first account of an install stays Owner)
                $org->setMemberRole($user->getKey(), $role);
            }
            app(AccessManager::class)->changed();
        }

        return $user;
    }

    /** Group memberships and role from the IdP's group claim, then the console token. */
    protected function signIn(AuthProvider $provider, User $user, $socialiteUser, bool $isNew): array
    {
        try {
            app(SsoGroupSync::class)->sync($provider, $user, $socialiteUser, $isNew);
        } catch (\Throwable $e) {
            report($e); // sign-in works with the roles the member already has
        }

        return $this->issueToken($user);
    }

    protected function createUserFromSocialite($socialiteUser, ?string $connection = null): User
    {
        $email = $socialiteUser->getEmail();
        $name = $socialiteUser->getName() ?? Str::before($email, '@');
        $query = fn () => $connection ? User::on($connection) : User::query();

        // Generate unique username from email prefix
        $baseUsername = Str::slug(Str::before($email, '@'), '_');
        $username = $baseUsername;
        $counter = 1;
        while ($query()->where('username', $username)->exists()) {
            $username = $baseUsername.'_'.$counter++;
        }

        return $query()->create([
            'name' => $name,
            'username' => $username,
            'email' => $email,
            'password' => Str::random(32), // Random password, user logs in via OAuth
        ]);
    }

    /** @param string $subject social_accounts.provider_user_id (ProviderConfigService::subjectKey) */
    protected function createSocialAccount(User $user, string $driver, string $subject, $socialiteUser): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $driver,
            'provider_user_id' => $subject,
            'email' => (string) ($socialiteUser->getEmail() ?? ''),
            'name' => $socialiteUser->getName(),
            'avatar_url' => $socialiteUser->getAvatar(),
            'access_token' => $socialiteUser->token ?? '',
            'refresh_token' => $socialiteUser->refreshToken,
            'token_expires_at' => $socialiteUser->expiresIn
                ? now()->addSeconds((int) $socialiteUser->expiresIn)
                : null,
        ]);
    }

    protected function updateSocialTokens(SocialAccount $account, $socialiteUser): void
    {
        $account->update([
            'access_token' => $socialiteUser->token ?? $account->access_token,
            'refresh_token' => $socialiteUser->refreshToken ?? $account->refresh_token,
            'token_expires_at' => $socialiteUser->expiresIn
                ? now()->addSeconds((int) $socialiteUser->expiresIn)
                : $account->token_expires_at,
            'email' => $socialiteUser->getEmail() ?? $account->email,
            'name' => $socialiteUser->getName() ?? $account->name,
            'avatar_url' => $socialiteUser->getAvatar() ?? $account->avatar_url,
        ]);
    }

    protected function issueToken(User $user): array
    {
        $token = LoginTokens::issue($user)->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
