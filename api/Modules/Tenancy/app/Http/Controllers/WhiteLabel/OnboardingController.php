<?php

namespace Modules\Tenancy\Http\Controllers\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Http\Controllers\WhiteLabel\Concerns\ResolvesTenantContext;
use Modules\Tenancy\Services\Onboarding\SelfServiceOnboarding;

/**
 * Onboarding wizard of a self-service tenant (multi-tenant):
 *
 *  GET  /api/onboarding   status (pending | completed | none), offered regions, default quota
 *  POST /api/onboarding   {org_name, region_id?} → {status, tenant} (settings.manage)
 */
class OnboardingController extends Controller
{
    use ResolvesTenantContext;

    public function __construct(private readonly SelfServiceOnboarding $onboarding) {}

    public function show(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant) {
            return response()->json(['status' => 'none', 'can_complete' => false, 'tenant' => null, 'regions' => [], 'region_assigned' => false,
                'default_quota' => null]);
        }

        return response()->json($this->onboarding->payload($tenant, $request->user()));
    }

    public function complete(Request $request): JsonResponse
    {
        $tenant = $this->tenantOf($request);

        abort_unless($this->onboarding->canComplete($tenant, $request->user()), 403, __('whitelabel.onboarding.not_allowed'));

        $data = $request->validate([
            'org_name' => ['required', 'string', 'min:2', 'max:255'],
            'region_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        return response()->json($this->onboarding->complete($tenant, $data, $request->user()));
    }
}
