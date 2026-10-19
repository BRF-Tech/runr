<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Billing\Http\Controllers\Concerns\BillingRequests;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\RegionOffers;

/**
 * Platform regions shared with the tenant (docs/billing.md#sharing-a-region-with-a-tenant):
 *
 *  GET  /api/billing/region-offers                    pending and accepted shares with the region's
 *                                                     type, platform prices and the tenant's markup
 *  POST /api/billing/region-offers/{region}/accept    {markup_percent?} — default: the default markup
 *
 * Until accepted a shared region is not usable by the tenant's users.
 */
class RegionOfferController extends Controller
{
    use BillingRequests;

    public function __construct(
        private readonly RegionOffers $offers,
        private readonly PriceBook $prices,
    ) {}

    public function index(): JsonResponse
    {
        $tenant = $this->tenantOrFail();

        return response()->json([
            'default_markup_percent' => round($this->prices->defaultMarkupPercent(), 3),
            'data' => $this->offers->forTenant($tenant),
        ]);
    }

    public function accept(Request $request, string $region): JsonResponse
    {
        $tenant = $this->tenantOrFail();
        $data = $request->validate(['markup_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000']]);

        $offer = $this->offers->accept($tenant, $region, isset($data['markup_percent']) ? (float) $data['markup_percent'] : null, $request->user());

        return response()->json(['data' => $offer]);
    }
}
