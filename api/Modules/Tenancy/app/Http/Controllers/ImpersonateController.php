<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

class ImpersonateController extends Controller
{
    /**
     * Start impersonating a tenant. Only owners/admins can impersonate.
     */
    public function start(Request $request, string $tenantId): JsonResponse
    {
        $user = $request->user();
        $this->ensureCanImpersonate($request, $user);

        $target = Tenant::findOrFail($tenantId);
        $currentTenant = $request->attributes->get('tenant');

        // Save original tenant so we can return later
        $cacheKey = "impersonate:{$user->id}";

        if (Cache::has($cacheKey)) {
            return response()->json([
                'message' => __('api.tenant.already_impersonating'),
            ], 422);
        }

        Cache::put($cacheKey, [
            'original_tenant_id' => $currentTenant?->id,
            'started_at' => now()->toISOString(),
        ], now()->addHours(4));

        // Initialize target tenant context
        tenancy()->initialize($target);
        $request->attributes->set('tenant', $target);

        return response()->json([
            'message' => __('api.tenant.impersonation_started'),
            'tenant' => $target,
            'original_tenant' => $currentTenant?->id,
        ]);
    }

    /**
     * Stop impersonation and return to the original tenant.
     */
    public function stop(Request $request): JsonResponse
    {
        $user = $request->user();
        $cacheKey = "impersonate:{$user->id}";

        $data = Cache::get($cacheKey);

        if (! $data) {
            return response()->json([
                'message' => __('api.tenant.no_impersonation'),
            ], 422);
        }

        Cache::forget($cacheKey);

        $originalId = $data['original_tenant_id'] ?? null;

        if ($originalId) {
            $original = Tenant::find($originalId);
            if ($original) {
                tenancy()->initialize($original);
                $request->attributes->set('tenant', $original);
            }
        } else {
            tenancy()->end();
            $request->attributes->remove('tenant');
        }

        return response()->json([
            'message' => __('api.tenant.impersonation_ended'),
            'tenant_id' => $originalId,
        ]);
    }

    /**
     * Ensure the user has owner or admin role in their current tenant.
     */
    protected function ensureCanImpersonate(Request $request, $user): void
    {
        $currentTenant = $request->attributes->get('tenant');

        if (! $currentTenant) {
            abort(403, __('api.tenant.no_context'));
        }

        $role = TenantUser::where('tenant_id', $currentTenant->id)
            ->where('user_id', $user->id)
            ->value('role');

        if (! in_array($role, ['owner', 'admin'])) {
            abort(403, __('api.tenant.only_owners_admins'));
        }
    }
}
