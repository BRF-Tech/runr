<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantUser;

/**
 * A platform administrator opens an organization as its administrator (docs/tenancy.md): the
 * console sends the organization's X-Tenant-ID with every request (IdentifyTenant::handleAdmin
 * scopes platform administrators to any tenant), these two calls mark where that starts and
 * ends. Both are audit-logged (impersonation.start / impersonation.stop, AuditCatalog); no state
 * is kept on the server — the console shows the impersonation bar while it is in the tenant.
 * Routes: platform administrators only (tenancy.admin).
 */
class ImpersonateController extends Controller
{
    /** Start: the tenant as the console stores it while it is open as administrator. */
    public function start(Request $request, string $tenantId): JsonResponse
    {
        $tenant = Tenant::findOrFail($tenantId);
        $member = self::isMember($tenant, $request->user()->getKey());

        return response()->json([
            'message' => __('api.tenant.impersonation_started'),
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'role' => 'admin',
                'member' => $member,
                // A member's own organization is no impersonation
                'impersonating' => ! $member,
            ],
        ]);
    }

    /**
     * Stop: the tenant being left — the request's tenant context (X-Tenant-ID) or tenant_id.
     * tenant_id of the answer is the audit event's subject.
     */
    public function stop(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant instanceof Tenant && is_string($id = $request->input('tenant_id')) && $id !== '') {
            $tenant = Tenant::find($id);
        }

        if (! $tenant instanceof Tenant) {
            return response()->json(['message' => __('api.tenant.no_impersonation')], 422);
        }

        return response()->json([
            'message' => __('api.tenant.impersonation_ended'),
            'tenant_id' => $tenant->id,
        ]);
    }

    public static function isMember(Tenant $tenant, int|string $userId): bool
    {
        return TenantUser::where('tenant_id', $tenant->id)->where('user_id', $userId)->exists();
    }
}
