<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\KnativeRegion;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Billing\Models\Price;
use Modules\Billing\Services\BillingRefusal;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Services\QueueConsumerCharging;
use Modules\Billing\Services\Regions;
use Modules\Billing\Services\RegionTypeLocked;
use Modules\Billing\Services\RegionTypes;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\BillingPolicy;

/**
 * Regions of the organization with their billing type and prices (docs/billing.md#regions):
 *
 *  GET /api/billing/regions           type, current prices (and where they come from), scheduled
 *                                     versions, active allocations, queue consumer charging; a
 *                                     tenant's shared regions with the platform's type, prices and
 *                                     setting and the tenant's markup
 *  PUT /api/billing/regions/{region}  {billing_type?: payg|fixed, charge_queue_consumers?: bool} —
 *                                     the region owner's settings: a type change is refused while
 *                                     fixed allocations are active there; a shared region's are the
 *                                     platform's
 *  PUT /api/billing/regions/{region}/queue-consumers
 *                                     {charge: false|null} — the organization's own choice for a
 *                                     region, its own or a shared one (docs/billing.md#queue-consumers)
 *  GET /api/billing/regions/{region}/organizations
 *                                     platform: the tenants a platform region is shared with and
 *                                     the platform's choice for each
 *  PUT /api/billing/regions/{region}/organizations/{tenant}
 *                                     platform: {charge: false|null} — the platform's choice for
 *                                     that tenant, which its bill from the platform and its own
 *                                     bills follow
 */
class RegionController extends Controller
{
    public function __construct(
        private readonly Regions $regions,
        private readonly RegionTypes $types,
        private readonly PriceBook $prices,
        private readonly QueueConsumerCharging $consumers,
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
        $data = $request->validate([
            'billing_type' => ['required_without:charge_queue_consumers', Rule::in(BillingPolicy::TYPES)],
            'charge_queue_consumers' => ['required_without:billing_type', 'boolean'],
        ]);

        $model = $this->regions->forContext()->firstWhere('id', $region);
        if (! $model) {
            abort(404, __('billing.region_not_found'));
        }
        if ($this->regions->isShared($region)) {
            throw new BillingRefusal('region_type_owned_by_platform', __('billing.region_type_owned_by_platform'), 422);
        }

        $actor = (string) $request->user()->getAuthIdentifier();
        if (isset($data['billing_type'])) {
            try {
                $this->types->set($region, $data['billing_type'], $actor);
            } catch (RegionTypeLocked $e) {
                throw new BillingRefusal('active_fixed_allocations', $e->getMessage(), 409, ['active_allocations' => $e->activeAllocations]);
            }
        }
        if (array_key_exists('charge_queue_consumers', $data)) {
            $this->consumers->setRegion($region, (bool) $data['charge_queue_consumers'], $actor);
        }

        return response()->json(['data' => $this->row($model)]);
    }

    /**
     * The organization's own choice for a region (its own or a shared one): charge: false — its
     * projects there are not charged for their queue consumers; null — as the region. It cannot
     * switch on what the region leaves off (true is kept as "as the region").
     */
    public function queueConsumers(Request $request, string $region): JsonResponse
    {
        $request->validate(['charge' => ['present', 'nullable', 'boolean']]);

        $model = $this->regions->forContext()->firstWhere('id', $region);
        if (! $model) {
            abort(404, __('billing.region_not_found'));
        }

        $charge = $request->input('charge');
        $this->consumers->setOverride($region, $charge === null ? null : filter_var($charge, FILTER_VALIDATE_BOOLEAN), (string) $request->user()->getAuthIdentifier());

        return response()->json(['data' => $this->row($model)]);
    }

    /**
     * The organizations (tenants) a platform region is shared with, each with the platform's
     * choice for it (docs/billing.md#queue-consumers): queue_consumers_override false — the region's
     * queue consumers are not charged to that tenant, on the platform's bill to it nor on its own
     * bills; null — as the region. Platform administrators only, in the platform's context.
     */
    #[OpenApiResponse(200, 'Whether the region charges its queue consumers, and the organizations it is shared with', type: 'array{charge_queue_consumers: bool, data: list<array{tenant: array{id: string, name: string, slug: string}, share_status: string, queue_consumers_override: bool|null, queue_consumers_charged: bool}>}')]
    public function organizations(string $region): JsonResponse
    {
        $this->platformRegion($region);

        try {
            $shares = DB::connection(BillingContext::central())->table('tenant_regions')
                ->join('tenants', 'tenants.id', '=', 'tenant_regions.tenant_id')
                ->where('tenant_regions.knative_region_id', $region)
                ->orderBy('tenants.name')
                ->get(['tenants.id', 'tenants.name', 'tenants.slug', 'tenant_regions.status']);
        } catch (QueryException) {
            $shares = collect(); // without tenancy: no organization shares the region
        }

        return response()->json([
            'charge_queue_consumers' => $this->consumers->regionCharges($region),
            'data' => $shares->map(fn (object $s) => $this->organizationRow($region, $s))->values(),
        ]);
    }

    /**
     * The platform's choice for one tenant in a platform region: {charge: false} — not charged to
     * the tenant; {charge: null} — as the region. It cannot switch on what the region leaves off.
     */
    public function organization(Request $request, string $region, string $tenant): JsonResponse
    {
        $request->validate(['charge' => ['present', 'nullable', 'boolean']]);
        $this->platformRegion($region);

        try {
            $row = DB::connection(BillingContext::central())->table('tenants')
                ->where('id', $tenant)->orWhere('slug', $tenant)->first(['id', 'name', 'slug']);
        } catch (QueryException) {
            $row = null;
        }
        if (! $row) {
            abort(404, __('billing.tenant_not_found'));
        }

        $charge = $request->input('charge');
        $this->consumers->setOwnerOverride((string) $row->id, $region, $charge === null ? null : filter_var($charge, FILTER_VALIDATE_BOOLEAN), (string) $request->user()->getAuthIdentifier());

        $share = (object) ['id' => $row->id, 'name' => $row->name, 'slug' => $row->slug, 'status' => null];

        return response()->json(['data' => $this->organizationRow($region, $share)]);
    }

    /** A platform region, asked for in the platform's context; 404 otherwise (a tenant's own region, or inside a tenant). */
    private function platformRegion(string $region): void
    {
        if (BillingContext::inTenant() || ! BillingContext::multiTenant() || ! $this->regions->isPlatformRegion($region)) {
            abort(404, __('billing.region_not_found'));
        }
    }

    /** @return array<string, mixed> */
    private function organizationRow(string $region, object $share): array
    {
        $tenantId = (string) $share->id;

        return [
            'tenant' => ['id' => $tenantId, 'name' => $share->name, 'slug' => $share->slug],
            'share_status' => $share->status,
            'queue_consumers_override' => $this->consumers->ownerOverride($tenantId, $region),
            'queue_consumers_charged' => $this->consumers->chargedForTenant($tenantId, $region),
        ];
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
            // docs/billing.md#queue-consumers: the region's setting (a shared region's is the
            // platform's), the organization's own choice, and what applies to its projects
            'charge_queue_consumers' => $this->consumers->regionCharges($id),
            // a tenant's shared region: the platform's choice for the tenant (false: off for it)
            'queue_consumers_owner_override' => $shared ? $this->consumers->ownerOverride(BillingContext::tenantKey(), $id) : null,
            'queue_consumers_override' => $this->consumers->override($id),
            'queue_consumers_charged' => $this->consumers->charged($id),
        ];
    }
}
