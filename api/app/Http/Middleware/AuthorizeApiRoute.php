<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Support\Rbac\Rbac;
use App\Support\Rbac\RoutePermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes every authenticated API route against config/route_permissions.php (api group;
 * runs after authentication, tenant identification and route model binding). A route behind
 * `auth:sanctum` that is neither mapped nor guarded by `can:` is refused — fail closed.
 */
class AuthorizeApiRoute
{
    public function __construct(private readonly Rbac $rbac) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! $route instanceof Route || ! RoutePermissions::requiresAuthentication($route)) {
            return $next($request);
        }

        // Fresh permissions for every request (long-running workers, role changes)
        $this->rbac->flush();

        $user = $request->user();
        if (! $user) {
            return $next($request); // the auth middleware answers 401
        }

        $requirement = RoutePermissions::requirement($route);
        if ($requirement === null) {
            if (RoutePermissions::hasCanMiddleware($route)) {
                return $next($request);
            }
            Log::warning('API route without a permission mapping (config/route_permissions.php)', [
                'route' => RoutePermissions::keys($route),
            ]);

            return $this->deny(__('rbac.route_not_mapped'), []);
        }

        [$mode, $permissions] = RoutePermissions::parse($requirement);
        $project = $request->route('project');

        $allowed = match ($mode) {
            RoutePermissions::AUTHENTICATED, RoutePermissions::POLICY => true,
            RoutePermissions::PLATFORM => $this->rbac->isPlatformAdmin($user) && $this->rbac->tokenAbilities($user) === null,
            RoutePermissions::PLATFORM_SCOPED => $this->rbac->isPlatformAdmin($user)
                && collect($permissions)->contains(fn ($p) => $this->rbac->tokenAllows($user, $p)),
            'anywhere' => $this->rbac->allowsAnywhere($user, $permissions[0]),
            'all' => $this->rbac->holdsAll($user, $permissions, $this->projectId($project)),
            default => collect($permissions)->contains(fn ($p) => $this->rbac->allows($user, $p, $project)),
        };

        if (! $allowed) {
            return $mode === RoutePermissions::PLATFORM || ($mode === RoutePermissions::PLATFORM_SCOPED && ! $this->rbac->isPlatformAdmin($user))
                ? $this->deny(__('rbac.platform_admin_required'), [])
                : $this->deny(__('rbac.forbidden', ['permission' => implode(' | ', $permissions)]), $permissions);
        }

        return $next($request);
    }

    private function projectId(mixed $project): ?string
    {
        if ($project instanceof Project) {
            return (string) $project->getKey();
        }

        return is_string($project) && $project !== '' ? $project : null;
    }

    private function deny(string $message, array $permissions): Response
    {
        return response()->json(array_filter([
            'message' => $message,
            'permissions' => $permissions ?: null,
        ]), 403);
    }
}
