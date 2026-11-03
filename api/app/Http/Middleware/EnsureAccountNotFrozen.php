<?php

namespace App\Http\Middleware;

use App\Support\Auth\FrozenAccounts;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `account.frozen`: guards the routes through which signed-in users change their
 * own account — profile, avatar, password, two-factor authentication, sessions, disabling and
 * deleting it, notification preferences, personal API tokens, the tokens of connected MCP clients,
 * linked sign-in identities. A frozen account (App\Support\Auth\FrozenAccounts, a shared demo
 * login) gets 403 with `frozen: true` on a write; reads pass.
 *
 * On the members' routes ({user}: /users/{user}/…, /members/{user}/role, a group's members, the
 * Tenancy module's /tenants/{tenant}/users/{user}/… and /users/{user}/toggle-admin) it refuses
 * the frozen account's writes on its own account only: an administrator still manages the frozen
 * account, and a frozen administrator still manages the others.
 *
 * Not on signing in and out (POST /auth/logout deletes only the caller's current token).
 */
class EnsureAccountNotFrozen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($request->isMethodSafe() || ! FrozenAccounts::includes($user)) {
            return $next($request);
        }

        $route = $request->route();
        if ($route instanceof Route && $route->hasParameter('user') && ! $this->isCaller($route->parameter('user'), $user->getAuthIdentifier())) {
            return $next($request);
        }

        return response()->json([
            'message' => __('api.account.frozen'),
            'frozen' => true,
        ], 403);
    }

    /** The {user} of the route (an ID, or a bound model) names the caller's own account. */
    private function isCaller(mixed $target, mixed $callerId): bool
    {
        $targetId = $target instanceof Model ? $target->getKey() : $target;

        return is_scalar($targetId) && (string) $targetId === (string) $callerId;
    }
}
