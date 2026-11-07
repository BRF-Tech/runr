<?php

namespace App\Support;

use App\Models\User;
use App\Support\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Access to the Horizon dashboard (/horizon): the queues of the whole installation, so only for the
 * people who operate it (Rbac::operatesPlatform()) — platform administrators (users.is_admin) and,
 * in the single-tenant edition, the holders of platform.manage: its Owners, and custom roles that
 * include it.
 *
 * The console signs in with API tokens, which a browser does not send when it opens /horizon. Such
 * an administrator therefore gets a single-use sign-in link instead — from
 * `php artisan runr:horizon-link` (cluster access) or `POST /api/horizon/link` (an unscoped API
 * token of the administrator):
 *
 *   https://<console>/horizon?access=<token>
 *
 * The token is 64 random characters, valid for a few minutes, stored only as its SHA-256 in the
 * cache and consumed by its first use. Opening the link signs the browser in to Horizon: the web
 * session (new session id) remembers the administrator, and every later Horizon request checks
 * again that the account still qualifies and is not disabled — taking the flag, the role or the
 * permission away ends the access at once. With APP_ENV=local everyone gets in (Horizon's own development rule).
 */
class HorizonAccess
{
    public const QUERY_PARAMETER = 'access';

    public const SESSION_KEY = 'runr_horizon_user';

    public const DEFAULT_MINUTES = 5;

    public const MAX_MINUTES = 60;

    private const CACHE_PREFIX = 'runr:horizon:access:';

    /**
     * A single-use sign-in link for $user.
     *
     * @return array{url: string, expires_at: Carbon}
     */
    public function issue(User $user, int $minutes = self::DEFAULT_MINUTES): array
    {
        if (! $this->isAdministrator($user)) {
            throw new \InvalidArgumentException("{$user->username} may not open the Horizon dashboard");
        }

        $minutes = max(1, min(self::MAX_MINUTES, $minutes));
        $token = Str::random(64);
        $expiresAt = now()->addMinutes($minutes);
        Cache::put(self::CACHE_PREFIX.hash('sha256', $token), $user->getKey(), $expiresAt);

        $url = rtrim(url((string) config('horizon.path', 'horizon')), '/')
            .'?'.http_build_query([self::QUERY_PARAMETER => $token]);

        return ['url' => $url, 'expires_at' => $expiresAt];
    }

    /** Horizon::auth(): may this request see the dashboard? */
    public function allows(Request $request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }
        $session = $request->session();

        $token = $request->query(self::QUERY_PARAMETER);
        if (is_string($token) && $token !== '') {
            $user = $this->user(Cache::pull(self::CACHE_PREFIX.hash('sha256', $token)));
            if ($this->isAdministrator($user)) {
                $session->regenerate();
                $session->put(self::SESSION_KEY, $user->getKey());

                return true;
            }
        }

        if ($this->isAdministrator($this->user($session->get(self::SESSION_KEY)))) {
            return true;
        }

        $session->forget(self::SESSION_KEY);

        return false;
    }

    /**
     * An enabled account of a platform administrator or, in the single-tenant edition, of a holder
     * of platform.manage (users.is_admin comes with the Tenancy module; a single-tenant install has
     * the permission instead, and its Owners hold it). Tenant Owners of a multi-tenant install do
     * not qualify: the dashboard shows every tenant's jobs. The roles are read afresh: a Horizon
     * session is checked again on every request.
     */
    public function isAdministrator(?User $user): bool
    {
        if ($user === null || $user->getAttribute('is_disabled')) {
            return false;
        }

        $rbac = app(Rbac::class);
        $rbac->flush();

        return $rbac->operatesPlatform($user);
    }

    private function user(mixed $id): ?User
    {
        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        // the platform's users: the central connection with tenancy, the default one otherwise
        return User::on(PlatformConnection::name())->find($id);
    }
}
