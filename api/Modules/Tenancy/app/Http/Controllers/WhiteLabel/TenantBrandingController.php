<?php

namespace Modules\Tenancy\Http\Controllers\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\WhiteLabel\BrandingController;
use App\Services\WhiteLabel\BrandingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Http\Controllers\WhiteLabel\Concerns\ResolvesTenantContext;

/**
 * A tenant's own branding override (multi-tenant; branding.manage in the tenant — config/route_permissions.php).
 * Allowed only while the platform enables tenant overrides (Settings → Branding).
 *
 *  GET    /api/tenant/branding                 {enabled, branding, stored, defaults}
 *  PUT    /api/tenant/branding                 partial update, null = platform value
 *  POST   /api/tenant/branding/assets/{kind}   multipart "file"
 *  DELETE /api/tenant/branding/assets/{kind}
 */
class TenantBrandingController extends Controller
{
    use ResolvesTenantContext;

    public function __construct(private readonly BrandingService $branding) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $this->tenantOf($request);

        return response()->json($this->payload((string) $tenant->id));
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = $this->authorizeTenant($request);
        $data = $request->validate(BrandingController::rules(false));

        $this->branding->update($data, $tenantId);

        return response()->json($this->payload($tenantId));
    }

    public function uploadAsset(Request $request, string $kind): JsonResponse
    {
        $tenantId = $this->authorizeTenant($request);
        $request->validate(BrandingController::assetRules());

        $this->branding->storeAsset($kind, $request->file('file'), $tenantId);

        return response()->json($this->payload($tenantId));
    }

    public function deleteAsset(Request $request, string $kind): JsonResponse
    {
        $tenantId = $this->authorizeTenant($request);

        $this->branding->deleteAsset($kind, $tenantId);

        return response()->json($this->payload($tenantId));
    }

    private function authorizeTenant(Request $request): string
    {
        $tenantId = (string) $this->tenantOf($request)->id;

        // branding.manage is checked on the route (config/route_permissions.php)
        abort_unless($this->branding->tenantOverridesEnabled(), 403, __('whitelabel.branding.tenant_overrides_disabled'));

        return $tenantId;
    }

    private function payload(string $tenantId): array
    {
        return ['enabled' => $this->branding->tenantOverridesEnabled()] + $this->branding->adminPayload($tenantId);
    }
}
