<?php

namespace App\Http\Controllers\Api;

use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Models\TenantSecurityPolicy;
use App\Services\Residency\RegionPolicy;
use App\Services\SupplyChain\SupplyChainDb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Data residency (docs/supply-chain.md):
 *  - GET  /api/residency                      the current organization's policy, for region pickers
 *  - GET  /api/tenants/{tenant}/security-policy   tenant admin: residency + tenant policy gate
 *  - PUT  /api/tenants/{tenant}/security-policy   residency fields: tenants.residency.manage,
 *                                                 gate fields: regions.supply_chain.manage
 */
class ResidencyController extends Controller
{
    private const RESIDENCY_FIELDS = ['residency_enforced', 'allowed_region_ids', 'allowed_locations', 'default_region_id'];

    private const GATE_FIELDS = ['gate_mode', 'gate_max_critical', 'gate_max_high', 'gate_require_scan'];

    public function __construct(private RegionPolicy $residency) {}

    /** GET /api/residency */
    public function current(): JsonResponse
    {
        $tenantId = $this->residency->tenantId();
        $policy = $this->residency->policy($tenantId);
        $regions = KnativeRegion::query()->orderBy('name')->get();

        return response()->json(['data' => [
            'tenant_id' => $tenantId,
            'restricted' => (bool) $policy?->restrictsRegions(),
            'residency_enforced' => (bool) $policy?->residency_enforced,
            'allowed_region_ids' => $this->residency->allowedRegionIds($regions, $tenantId),
            'allowed_locations' => $policy?->allowed_locations ?? [],
            'default_region_id' => $policy?->default_region_id,
            'regions' => $regions->map(fn (KnativeRegion $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'data_location' => RegionSupplyChainSetting::forRegion($r)->data_location,
                'allowed' => $this->residency->allows($r, RegionPolicy::PROJECT_CREATE, $tenantId),
            ])->values(),
        ]]);
    }

    /** GET /api/tenants/{tenant}/security-policy */
    public function show(string $tenant): JsonResponse
    {
        $this->authorizeAny();
        $this->findTenant($tenant);

        return response()->json(['data' => $this->payload($tenant)]);
    }

    /** PUT /api/tenants/{tenant}/security-policy */
    public function update(Request $request, string $tenant): JsonResponse
    {
        $this->findTenant($tenant);

        $touchesResidency = $request->hasAny(self::RESIDENCY_FIELDS);
        $touchesGate = $request->hasAny(self::GATE_FIELDS);
        abort_unless($touchesResidency || $touchesGate, 422, __('supply_chain.residency.nothing_to_update'));

        if ($touchesResidency) {
            Gate::authorize('tenants.residency.manage');
        }
        if ($touchesGate) {
            Gate::authorize('regions.supply_chain.manage');
        }

        $data = $request->validate([
            'residency_enforced' => 'sometimes|boolean',
            'allowed_region_ids' => 'sometimes|nullable|array|max:200',
            // duplicates are dropped below, like repeated locations
            'allowed_region_ids.*' => ['string', 'size:26'],
            'allowed_locations' => 'sometimes|nullable|array|max:50',
            'allowed_locations.*' => ['string', 'max:64', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _.-]*$/'],
            'default_region_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'gate_mode' => ['sometimes', 'nullable', Rule::in(RegionSupplyChainSetting::GATE_MODES)],
            'gate_max_critical' => 'sometimes|nullable|integer|min:0|max:100000',
            'gate_max_high' => 'sometimes|nullable|integer|min:0|max:100000',
            'gate_require_scan' => 'sometimes|nullable|boolean',
        ]);

        $policy = TenantSecurityPolicy::query()->find($tenant) ?? new TenantSecurityPolicy(['tenant_id' => $tenant]);

        foreach (['allowed_region_ids', 'allowed_locations'] as $list) {
            if (array_key_exists($list, $data)) {
                $data[$list] = array_values(array_unique(array_filter(array_map('trim', $data[$list] ?? []))));
            }
        }

        $policy->fill($data);
        $policy->updated_by = $request->user()?->username;

        // The default region must be one the tenant may use (checked against the unsaved policy)
        if ($policy->default_region_id && ! $this->residency->policyAllows($policy, $policy->default_region_id)) {
            throw ValidationException::withMessages(['default_region_id' => [__('supply_chain.residency.default_not_allowed')]]);
        }

        $policy->save();   // audited by the route (tenant.residency.update) with the model change

        return response()->json(['data' => $this->payload($tenant)]);
    }

    private function payload(string $tenant): array
    {
        $policy = TenantSecurityPolicy::query()->find($tenant);

        $regionQuery = ($connection = SupplyChainDb::central()) ? KnativeRegion::on($connection) : KnativeRegion::query();
        $regions = $regionQuery->orderBy('name')->get();

        return [
            'tenant_id' => $tenant,
            'residency_enforced' => $policy?->residency_enforced ?? true,
            'allowed_region_ids' => $policy?->allowed_region_ids ?? [],
            'allowed_locations' => $policy?->allowed_locations ?? [],
            'default_region_id' => $policy?->default_region_id,
            'restricted' => (bool) $policy?->restrictsRegions(),
            'gate_mode' => $policy?->gate_mode,
            'gate_max_critical' => $policy?->gate_max_critical,
            'gate_max_high' => $policy?->gate_max_high,
            'gate_require_scan' => $policy?->gate_require_scan,
            'updated_by' => $policy?->updated_by,
            'updated_at' => $policy?->updated_at?->toISOString(),
            'regions' => $regions->map(fn (KnativeRegion $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'data_location' => RegionSupplyChainSetting::forRegion($r)->data_location,
                'region_mode' => $r->region_mode ?? 'managed',
            ])->values(),
            'locations' => RegionSupplyChainSetting::query()->whereNotNull('data_location')->distinct()->orderBy('data_location')->pluck('data_location')->values(),
            'abilities' => [
                'residency' => Gate::allows('tenants.residency.manage'),
                'gate' => Gate::allows('regions.supply_chain.manage'),
            ],
        ];
    }

    private function authorizeAny(): void
    {
        abort_unless(Gate::any(['tenants.residency.manage', 'regions.supply_chain.manage']), 403);
    }

    /**
     * The tenant must exist (Tenancy module; without the module there are no tenants). Outside
     * the platform administration only the current organization's own policy is reachable.
     */
    private function findTenant(string $tenant): void
    {
        $tenantClass = '\Modules\Tenancy\Models\Tenant';
        abort_unless(config('tenancy.enabled') && class_exists($tenantClass) && $tenantClass::query()->whereKey($tenant)->exists(), 404);

        $user = request()->user();
        if (! ($user?->is_admin ?? false)) {
            abort_unless($this->residency->tenantId() === $tenant, 403);
        }
    }
}
