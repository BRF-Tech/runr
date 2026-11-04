<?php

namespace Modules\Tenancy\Http\Controllers\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use App\Services\Captcha\CaptchaSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Http\Controllers\WhiteLabel\Concerns\ResolvesTenantContext;

/**
 * An organization's own bot protection keys (multi-tenant; settings.manage in the organization —
 * config/route_permissions.php), docs/security.md#bot-protection. Its sign-in page
 * (?tenant=<slug>), its invitation links and its members' tenant sign-ins then use its provider
 * and keys — for its own host names; the forms, the fallback and the timeout stay the platform's,
 * and an organization cannot switch the protection off.
 *
 *  GET /api/tenant/captcha   {provider|null, site_key, secret_set, effective, platform, providers}
 *  PUT /api/tenant/captcha   {provider (null = the platform's), site_key, secret, secret_clear}
 */
class TenantCaptchaController extends Controller
{
    use ResolvesTenantContext;

    public function __construct(private readonly CaptchaSettings $settings) {}

    /** Get the organization's own bot protection keys */
    public function show(Request $request): JsonResponse
    {
        return response()->json($this->settings->tenantPayload((string) $this->tenantOf($request)->id));
    }

    /** Set the organization's own bot protection keys */
    public function update(Request $request): JsonResponse
    {
        $tenantId = (string) $this->tenantOf($request)->id;
        $data = $request->validate(CaptchaSettings::tenantRules());

        $this->settings->updateTenant($tenantId, $data);

        return response()->json($this->settings->tenantPayload($tenantId));
    }
}
