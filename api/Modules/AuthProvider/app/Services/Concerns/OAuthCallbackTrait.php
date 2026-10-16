<?php

namespace Modules\AuthProvider\Services\Concerns;

use App\Models\User;
use App\Support\Auth\LoginTokens;
use App\Support\Rbac\AccessManager;
use App\Support\Rbac\PermissionCatalog;
use App\Support\Rbac\Rbac;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Modules\AuthProvider\Models\AuthProvider;
use Modules\AuthProvider\Models\SocialAccount;
use Modules\AuthProvider\Services\SsoGroupSync;

trait OAuthCallbackTrait
{
    protected function handleCallback(string $driver, array $state): array
    {
        $socialiteUser = Socialite::driver($driver)->stateless()->user();

        $provider = $this->configService->getProvider($driver);
        if (! $provider) {
            return ['error' => __('api.oauth.provider_not_found')];
        }

        return $this->resolveUser($provider, $driver, $socialiteUser);
    }

    protected function resolveUser(AuthProvider $provider, string $driver, $socialiteUser): array
    {
        // 1. Check social_accounts for existing link
        $socialAccount = SocialAccount::where('provider', $driver)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if ($socialAccount) {
            $this->updateSocialTokens($socialAccount, $socialiteUser);
            $user = $socialAccount->user;

            return $this->signIn($provider, $user, $socialiteUser, false);
        }

        // 2. Check users table by email
        $email = $socialiteUser->getEmail();
        if (! $email) {
            return ['error' => __('api.oauth.no_email')];
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $this->createSocialAccount($user, $driver, $socialiteUser);

            return $this->signIn($provider, $user, $socialiteUser, false);
        }

        // 3. Auto-register if enabled
        if (! $provider->auto_register) {
            return ['error' => __('api.oauth.account_not_found')];
        }

        $user = $this->provisionUser($provider, $socialiteUser);
        $this->createSocialAccount($user, $driver, $socialiteUser);

        return $this->signIn($provider, $user, $socialiteUser, true);
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

    protected function createSocialAccount(User $user, string $driver, $socialiteUser): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $driver,
            'provider_user_id' => $socialiteUser->getId(),
            'email' => $socialiteUser->getEmail(),
            'name' => $socialiteUser->getName(),
            'avatar_url' => $socialiteUser->getAvatar(),
            'access_token' => $socialiteUser->token ?? '',
            'refresh_token' => $socialiteUser->refreshToken,
            'token_expires_at' => $socialiteUser->expiresIn
                ? now()->addSeconds($socialiteUser->expiresIn)
                : null,
        ]);
    }

    protected function updateSocialTokens(SocialAccount $account, $socialiteUser): void
    {
        $account->update([
            'access_token' => $socialiteUser->token ?? $account->access_token,
            'refresh_token' => $socialiteUser->refreshToken ?? $account->refresh_token,
            'token_expires_at' => $socialiteUser->expiresIn
                ? now()->addSeconds($socialiteUser->expiresIn)
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
