<?php

namespace Modules\AuthProvider\Services;

use Illuminate\Support\Collection;
use Modules\AuthProvider\Models\AuthProvider;

/**
 * Which provider row an OAuth / OIDC flow uses, and the keys derived from it.
 *
 * An organization may enable several providers of one driver (two OIDC identity providers, a
 * gitlab.com and a self-managed GitLab). A flow names its provider by ID; without one it takes
 * the driver's first enabled provider in sign-in order (display order, name, ID) — the same row
 * every time.
 */
class ProviderConfigService
{
    public const GITLAB_DEFAULT_INSTANCE = 'https://gitlab.com/';

    /** Redirect (callback) URL to register with the OAuth client — one per driver, for every provider and tenant. */
    public function callbackUrl(string $driver): string
    {
        return url("/oauth/{$driver}/callback");
    }

    /** Enabled OAuth / OIDC provider of $driver: the one with $id, else the driver's first in sign-in order. */
    public function findProvider(string $driver, ?string $id = null): ?AuthProvider
    {
        if ($driver === AuthProvider::DRIVER_SAML) {
            return null;
        }

        try {
            $query = AuthProvider::query()->enabled()->where('driver', $driver);

            if ($id !== null && $id !== '') {
                return $query->whereKey($id)->first();
            }

            return $query->ordered()->orderBy('id')->first();
        } catch (\Throwable) {
            return null; // module tables not migrated yet
        }
    }

    /**
     * Providers a signed-in user can link to their account: the enabled OAuth / OIDC providers
     * (a SAML identity is linked by signing in with it).
     *
     * @return Collection<int, AuthProvider>
     */
    public function linkable(): Collection
    {
        return AuthProvider::query()->enabled()
            ->where('driver', '!=', AuthProvider::DRIVER_SAML)
            ->ordered()->orderBy('id')
            ->get(['id', 'name', 'driver', 'display_order']);
    }

    /**
     * `social_accounts.provider_user_id` of an external subject. Google, GitHub and Microsoft IDs
     * are unique across the provider; an OIDC `sub` only within its identity provider and a GitLab
     * user ID only within its instance, so those carry the provider ID / the instance (gitlab.com
     * keeps the bare ID). SAML subjects arrive scoped already (SamlService::subjectId).
     */
    public function subjectKey(AuthProvider $provider, string $driver, string $id): string
    {
        if ($driver === 'oidc') {
            return self::scoped((string) $provider->getKey(), $id);
        }

        if ($driver === 'gitlab' && ($instance = $this->gitlabInstanceKey($provider)) !== null) {
            return self::scoped($instance, $id);
        }

        return $id;
    }

    /** "<scope>:<id>", hashed when it would not fit the 255-character column. */
    public static function scoped(string $scope, string $id): string
    {
        $key = $scope.':'.$id;

        return strlen($key) <= 255 ? $key : $scope.':sha256:'.hash('sha256', $id);
    }

    /** Base URL of the provider's GitLab instance, with a trailing slash (default gitlab.com). */
    public function gitlabInstanceUri(AuthProvider $provider): string
    {
        $uri = trim((string) $provider->gitlab_instance_uri);

        return $uri === '' ? self::GITLAB_DEFAULT_INSTANCE : rtrim($uri, '/').'/';
    }

    /** host[:port][/path] of a self-managed GitLab instance; null for gitlab.com. */
    public function gitlabInstanceKey(AuthProvider $provider): ?string
    {
        $uri = $this->gitlabInstanceUri($provider);
        if ($uri === self::GITLAB_DEFAULT_INSTANCE) {
            return null;
        }

        $parts = parse_url($uri) ?: [];
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'gitlab.com') {
            return null;
        }

        return $host.(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim((string) ($parts['path'] ?? ''), '/');
    }

    /**
     * Whether $provider's self-managed instance is the only GitLab instance the organization
     * configures — then accounts linked before GitLab subjects carried their instance can only
     * belong to it.
     */
    public function isOnlyGitlabInstance(AuthProvider $provider): bool
    {
        $instance = $this->gitlabInstanceKey($provider);
        if ($instance === null) {
            return false;
        }

        return AuthProvider::query()->where('driver', 'gitlab')->get()
            ->every(fn (AuthProvider $p) => $this->gitlabInstanceKey($p) === $instance);
    }
}
