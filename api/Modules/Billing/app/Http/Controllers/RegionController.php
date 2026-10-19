<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\KnativeRegion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\BillingRefusal;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypeLocked;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\BillingPolicy;

/**
 * Regions of the organization with their billing type and prices (docs/billing.md#regions):
 *
 *  GET /api/billing/regions           type, current prices (and where they come from), scheduled
 *                                     versions, active allocations; a tenant's shared regions with
 *                                     the platform's type and prices and the tenant's markup
 *  PUT /api/billing/regions/{region}  {billing_type: payg|fixed} — refused while fixed allocations
 *                                     are active there; a shared region's type is the platform's
 */
class RegionController extends Controller
{
    public function __construct(
        private readonly Regions $regions,
        private readonly RegionTypes $types,
        private readonly PriceBook $prices,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'currency' => BillingContext::currency(),
            'data' => $this->regions->forContext()->map(fn (KnativeRegion $r) => $this->row($r))->values(),
        ]);
    }

    public function update(Request $request, string $region): JsonResponse
    {
        $data = $request->validate(['billing_type' => ['required', Rule::in(BillingPolicy::TYPES)]]);

        $model = $this->regions->forContext()->firstWhere('id', $region);
        if (! $model) {
            abort(404, __('billing.region_not_found'));
        }
        if ($this->regions->isShared($region)) {
            throw new BillingRefusal('region_type_owned_by_platform', __('billing.region_type_owned_by_platform'), 422);
        }

        try {
            $this->types->set($region, $data['billing_type'], (string) $request->user()->getAuthIdentifier());
        } catch (RegionTypeLocked $e) {
            throw new BillingRefusal('active_fixed_allocations', $e->getMessage(), 409, ['active_allocations' => $e->activeAllocations]);
        }

        return response()->json(['data' => $this->row($model)]);
    }

    private function row(KnativeRegion $region): array
    {
        $id = (string) $region->id;
        $shared = $this->regions->isShared($id);
        $today = Carbon::today()->toDateString();
        $current = $this->prices->current($id);
        $active = $this->types->activeAllocations($id);

        $scheduled = $shared
            ? $this->prices->versions($id, true)->merge($this->prices->versions(null, true))
            : $this->prices->versions($id);

        return [
            'id' => $id,
            'name' => $region->name,
            'own' => ! $shared,
            'billing_type' => $this->types->typeOf($id),
            'type_locked' => ! $shared && BillingPolicy::typeChangeBlockedByActiveAllocations() && $active > 0,
            'active_allocations' => $active,
            'pending_requests' => $this->types->pendingRequests($id),
            'meters_gpu' => $this->regions->metersGpu($id),
            'prices' => $current->toArray(),
            'scheduled_prices' => $scheduled->filter(fn (Price $p) => $p->effectiveDate() > $today)
                ->sortBy(fn (Price $p) => $p->effectiveDate())->map(fn (Price $p) => $p->toApiArray())->values(),
            'markup_percent' => $shared ? round($current->markupPercent, 3) : null,
        ];
    }
}
