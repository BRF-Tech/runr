<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\BillingRefusal;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\Regions;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\DefaultPrices;
use Modules\Billing\Support\Versions;

/**
 * Price versions of the organization (docs/billing.md#prices):
 *
 *  GET    /api/billing/prices          default prices: current, scheduled and past versions, and what
 *                                      applies without a default of its own
 *  POST   /api/billing/prices          a version {region_id?, effective_from (today or later), every price}
 *  DELETE /api/billing/prices/{price}  a version not yet in effect
 *
 * A version takes effect on its date; the previous one holds until then. A version with the same
 * scope and date is replaced. A tenant prices its own regions and its defaults; shared platform
 * regions carry the platform's prices (plus the tenant's markup).
 */
class PriceController extends Controller
{
    public function __construct(
        private readonly PriceBook $prices,
        private readonly Regions $regions,
    ) {}

    public function index(): JsonResponse
    {
        $today = Carbon::today()->toDateString();
        $versions = $this->prices->versions(null);
        $current = $this->prices->versionOn(null, $today);
        $effective = $this->prices->resolve(null, $today);
        $inherited = $this->prices->inheritedDefault();

        return response()->json([
            'currency' => BillingContext::currency(),
            'context' => BillingContext::kind(),
            'fields' => DefaultPrices::fields(),
            'defaults' => [
                'current' => $current?->toApiArray(),
                'scheduled' => $versions->filter(fn (Price $p) => $p->effectiveDate() > $today)->map(fn (Price $p) => $p->toApiArray())->values(),
                'history' => $versions->filter(fn (Price $p) => $p->effectiveDate() <= $today)->reverse()->map(fn (Price $p) => $p->toApiArray())->values(),
            ],
            'effective_defaults' => $effective->toArray(),
            'inherited_defaults' => $inherited->toArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rules = [
            'region_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'effective_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
        foreach (DefaultPrices::fields() as $field) {
            $rules[$field] = ['required', 'numeric', 'min:0', 'max:1000000000'];
        }
        $data = $request->validate($rules);

        $regionId = $data['region_id'] ?? null;
        if ($regionId !== null) {
            if (! $this->regions->find($regionId) || (BillingContext::kind() === BillingContext::PLATFORM && ! $this->regions->isPlatformRegion($regionId))) {
                return response()->json(['message' => __('billing.region_not_found'), 'errors' => ['region_id' => [__('billing.region_not_found')]]], 422);
            }
            if ($this->regions->isShared($regionId)) {
                throw new BillingRefusal('region_prices_owned_by_platform', __('billing.region_prices_owned_by_platform'), 422);
            }
        }

        $values = [];
        foreach (DefaultPrices::fields() as $field) {
            $values[$field] = round((float) $data[$field], 10);
        }

        // The version of the same scope and date is replaced (an upsert on the unique index)
        $price = Versions::put(Price::class, $regionId, $data['effective_from'], $values + ['note' => $data['note'] ?? null, 'created_by' => (string) $request->user()->getAuthIdentifier()]);
        $this->prices->flush();

        return response()->json(['data' => $price->fresh()?->toApiArray()], 201);
    }

    public function destroy(string $price): Response
    {
        $model = Price::query()->findOrFail($price);
        if ($model->effectiveDate() <= Carbon::today()->toDateString()) {
            throw new BillingRefusal('price_in_effect', __('billing.price_in_effect'), 409);
        }

        $model->delete();
        $this->prices->flush();

        return response()->noContent();
    }
}
