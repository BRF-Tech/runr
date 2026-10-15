<?php

namespace Modules\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enabled')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $this->withVary($next($request));
        }

        // Platform admins stay in central mode by default.
        // They can optionally scope to a tenant via X-Tenant-ID header.
        if ($user->is_admin) {
            return $this->handleAdmin($request, $next, $user);
        }

        $tenant = $this->resolveTenant($request, $user);

        if ($tenant) {
            $this->refuseWritesWhileDeleting($request, $tenant);
            tenancy()->initialize($tenant);
            $request->attributes->set('tenant', $tenant);
            $request->headers->set('X-Context-ID', $tenant->id);
        }

        return $this->withVary($next($request));
    }

    protected function handleAdmin(Request $request, Closure $next, $user): Response
    {
        $tenantId = $this->tokenTenantId($user) ?? $request->header('X-Tenant-ID');

        if ($tenantId) {
            $tenant = Tenant::find($tenantId);
            if ($tenant) {
                $this->refuseWritesWhileDeleting($request, $tenant);
                tenancy()->initialize($tenant);
                $request->attributes->set('tenant', $tenant);
                $request->headers->set('X-Context-ID', $tenant->id);
            }
        }

        return $this->withVary($next($request));
    }

    /**
     * A tenant being deleted (or whose deletion failed and awaits a retry) accepts no writes:
     * no new projects, code saves (builds) or deploys behind the teardown. Reads keep working.
     */
    protected function refuseWritesWhileDeleting(Request $request, Tenant $tenant): void
    {
        if ($tenant->isActive() || $request->isMethodSafe()) {
            return;
        }

        // User-level actions stay possible: switch to another tenant, log out, own account
        if ($request->is('api/tenant/switch', 'api/auth/logout', 'api/account', 'api/account/*')) {
            return;
        }

        abort(409, __('api.tenant.deleting'));
    }

    protected function withVary(Response $response): Response
    {
        $response->headers->set('Vary', 'X-Tenant-ID');

        return $response;
    }

    protected function resolveTenant(Request $request, $user): ?Tenant
    {
        // An API token created in a tenant works in that tenant only (its permissions are that
        // tenant's — a token never carries more than its owner holds where it is used)
        if ($pinned = $this->tokenTenantId($user)) {
            $header = $request->header('X-Tenant-ID');
            if ($header && $header !== $pinned) {
                abort(403, __('api.tenant.unauthorized'));
            }

            return $this->resolveFromHeader($pinned, $user);
        }

        $tenantId = $request->header('X-Tenant-ID');

        if ($tenantId) {
            return $this->resolveFromHeader($tenantId, $user);
        }

        return $this->resolveDefault($user);
    }

    protected function tokenTenantId($user): ?string
    {
        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        return $token instanceof PersonalAccessToken && $token->tenant_id
            ? (string) $token->tenant_id
            : null;
    }

    protected function resolveFromHeader(string $tenantId, $user): ?Tenant
    {
        $hasAccess = TenantUser::where('user_id', $user->id)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $hasAccess) {
            abort(403, __('api.tenant.unauthorized'));
        }

        return Tenant::find($tenantId);
    }

    protected function resolveDefault($user): ?Tenant
    {
        $tenantUser = TenantUser::where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->first();

        return $tenantUser ? Tenant::find($tenantUser->tenant_id) : null;
    }
}
