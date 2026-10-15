<?php

namespace Modules\Tenancy\Services;

use App\Models\User;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

class TenancyLoginService
{
    /**
     * Return tenant data to append to the login response.
     *
     * @param  string|null  $tenantSlug  When set (tenant user login), force active_tenant to this tenant.
     */
    public function loginData(User $user, ?string $tenantSlug = null): array
    {
        $roles = TenantUser::where('user_id', $user->id)
            ->pluck('role', 'tenant_id')
            ->all();

        $query = Tenant::orderBy('name');
        if (! $user->is_admin) {
            $query->whereIn('id', array_keys($roles));
        }

        $tenants = $query->get()->map(fn ($t) => [
            'id' => $t->id,
            'name' => $t->name,
            'slug' => $t->slug,
            'role' => $user->is_admin ? 'admin' : ($roles[$t->id] ?? null),
        ]);

        // Determine active tenant
        if ($tenantSlug) {
            // Tenant user login: active tenant is the one the user specified
            $activeTenant = $tenants->first(fn ($t) => $t['slug'] === $tenantSlug);
        } elseif ($user->is_admin) {
            // Admin root login → central mode
            $activeTenant = null;
        } else {
            // Regular root login → first assigned tenant
            $activeTenant = $tenants->first(fn ($t) => $t['role'] !== null);
        }

        return [
            'tenants' => $tenants,
            'active_tenant' => $activeTenant,
        ];
    }
}
