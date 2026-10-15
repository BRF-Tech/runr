<?php

namespace Modules\Tenancy\Http\Controllers\WhiteLabel;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Tenancy\Services\Billing\BillingSettings;

/**
 * Plans & billing settings (platform administrators — @platform in config/route_permissions.php,
 * docs/billing.md):
 *
 *  GET /api/admin/billing-settings   plans on/off, billing webhook URL + whether its secret is set
 *                                    (never the secret), usage thresholds, store URL
 *  PUT /api/admin/billing-settings   write-only secret: omitted / null keeps, clear_secrets[] removes
 */
class BillingSettingsController extends Controller
{
    public function show(BillingSettings $settings): JsonResponse
    {
        return response()->json($settings->adminPayload());
    }

    public function update(Request $request, BillingSettings $settings): JsonResponse
    {
        $data = $request->validate(BillingSettings::rules());

        $settings->update($data);

        return response()->json($settings->adminPayload());
    }
}
