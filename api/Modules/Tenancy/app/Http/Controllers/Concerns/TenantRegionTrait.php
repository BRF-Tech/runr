<?php

namespace Modules\Tenancy\Http\Controllers\Concerns;

use App\Models\KnativeRegion;
use App\Services\CacheVersionService;
use App\Services\Helpers\OrganizationCeiling;
use App\Services\Helpers\ResourceReservation;
use App\Services\KnativeNodeService;
use App\Services\ResourceThresholdService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Tenancy\Jobs\DetachRegionCleanupJob;
use Modules\Tenancy\Models\CentralSyncableRegion;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantQuotaService;

trait TenantRegionTrait
{
    public function regions(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $quotaService = app(TenantQuotaService::class);
        $assigned = $tenant->regions;

        // What holds the quotas lives in the tenant's own schema (its projects, model services,
        // previews): counted there, whichever organization the administrator's request runs in
        $usage = $tenant->run(fn () => $assigned->mapWithKeys(fn ($r) => [$r->id => $quotaService->currentDemand($r)])->all());

        $regions = $assigned->map(function ($r) use ($quotaService, $usage) {
            $demand = $usage[$r->id] ?? ResourceReservation::noDemand();
            $used = ResourceReservation::total($demand);
            $managed = ($r->region_mode ?? 'managed') !== 'infinite';

            return [
                'id' => $r->id,
                'name' => $r->name,
                'region_id' => $r->id,
                'region_mode' => $r->region_mode ?? 'managed',
                'cpu_quota_m' => $r->pivot->cpu_quota_m,
                'memory_quota_mb' => $r->pivot->memory_quota_mb,
                'used_cpu_m' => $used['cpu_m'],
                'used_memory_mb' => $used['memory_mb'],
                // the quota's always-on and FaaS overcommit budgets (TenantQuotaService); an
                // infinite region has no quota
                'capacity' => $managed ? $quotaService->budgets($r, [
                    'cpu_m' => (int) $r->pivot->cpu_quota_m,
                    'memory_mb' => (int) $r->pivot->memory_quota_mb,
                ], $demand) : null,
                // GPU quota (null = no GPU limit), set with PUT /tenants/{tenant}/regions/{region}/gpu-quota
                'gpu_quota' => $r->pivot->gpu_quota,
            ];
        });

        return response()->json($regions);
    }

    public function assignRegion(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        // With an organization ceiling a quota may be left out: it defaults to
        // the ceiling, bounded by what is still free of the region capacity
        $ceilingCpu = OrganizationCeiling::cpuMillicores();
        $ceilingMem = OrganizationCeiling::memoryMb();

        $data = $request->validate([
            'region_id' => 'required|exists:knative_regions,id',
            'cpu_quota_m' => [$ceilingCpu ? 'nullable' : 'required', 'integer', 'min:0'],
            'memory_quota_mb' => [$ceilingMem ? 'nullable' : 'required', 'integer', 'min:0'],
        ]);

        // Always query central for platform regions
        $region = CentralSyncableRegion::findOrFail($data['region_id']);
        if ($region->owner_tenant_id) {
            return response()->json(['message' => __('api.tenant.region_not_assignable')], 422);
        }

        $managed = ($region->region_mode ?? 'managed') !== 'infinite';

        if (! isset($data['cpu_quota_m']) || ! isset($data['memory_quota_mb'])) {
            // Re-assigning keeps the tenant's own share out of "allocated"
            $remaining = $managed ? $this->remainingQuotaCapacity($region, $tenant->id) : null;

            foreach (['cpu_quota_m' => [$ceilingCpu, 'cpu'], 'memory_quota_mb' => [$ceilingMem, 'memory']] as $field => [$ceiling, $key]) {
                if (isset($data[$field])) {
                    continue;
                }

                $default = $remaining ? min($ceiling, $remaining[$key]) : $ceiling;

                // 0 would mean "unlimited" — nothing is left to default to
                if ($default <= 0) {
                    return response()->json([
                        'message' => __("api.tenant.{$key}_quota_exceeded", ['remaining' => max(0, $default)]),
                    ], 422);
                }

                $data[$field] = $default;
            }
        }

        // Validate quota does not exceed remaining capacity (managed regions only)
        if ($managed) {
            $error = $this->validateQuotaCapacity($region, $data['cpu_quota_m'], $data['memory_quota_mb'], $tenant->id);
            if ($error) {
                return $error;
            }
        }

        $tenant->regions()->syncWithoutDetaching([
            $data['region_id'] => [
                'cpu_quota_m' => $data['cpu_quota_m'],
                'memory_quota_mb' => $data['memory_quota_mb'],
            ],
        ]);

        // Sync region data to the tenant's schema (kubeconfig excluded)
        $region->triggerSyncEvent();

        app(CacheVersionService::class)->bump('settings');
        $this->dispatchThresholdCheck($region);

        return response()->json($tenant->regions()->get());
    }

    public function updateRegionQuota(Request $request, string $id, string $regionId): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $data = $request->validate([
            'cpu_quota_m' => 'sometimes|integer|min:0',
            'memory_quota_mb' => 'sometimes|integer|min:0',
        ]);

        // Always query central for platform regions
        $region = CentralSyncableRegion::findOrFail($regionId);
        if (($region->region_mode ?? 'managed') !== 'infinite') {
            $error = $this->validateQuotaCapacity(
                $region,
                $data['cpu_quota_m'] ?? 0,
                $data['memory_quota_mb'] ?? 0,
                $id,
            );
            if ($error) {
                return $error;
            }
        }

        $tenant->regions()->updateExistingPivot($regionId, $data);

        app(CacheVersionService::class)->bump('settings');
        $this->dispatchThresholdCheck($region);

        return response()->json($tenant->regions()->get());
    }

    public function removeRegion(string $id, string $region): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->regions()->detach($region);

        DetachRegionCleanupJob::dispatch($tenant, $region, auth()->id());

        app(CacheVersionService::class)->bump('settings');

        return response()->json(['message' => __('api.region.region_removed')]);
    }

    protected function dispatchThresholdCheck(KnativeRegion $region): void
    {
        dispatch(function () use ($region) {
            app(ResourceThresholdService::class)->checkRegion($region);
        })->afterResponse();
    }

    /**
     * Quotas are shares of the region capacity: the tenants' quotas on a region add up to at
     * most the region capacity and, when configured, the organization ceiling. Admission inside
     * a quota overcommits scale-to-zero workloads by the region's factors (TenantQuotaService),
     * so the quotas × factor add up to at most the region capacity × factor.
     * A quota of 0 means "unlimited" and is not part of the sum.
     */
    protected function validateQuotaCapacity(KnativeRegion $region, int $cpuM, int $memMb, ?string $excludeTenantId = null): ?JsonResponse
    {
        $remaining = $this->remainingQuotaCapacity($region, $excludeTenantId);
        $remainingCpu = $remaining['cpu'];
        $remainingMem = $remaining['memory'];

        if ($cpuM > $remainingCpu) {
            return response()->json([
                'message' => __('api.tenant.cpu_quota_exceeded', ['remaining' => $remainingCpu]),
            ], 422);
        }

        if ($memMb > $remainingMem) {
            return response()->json([
                'message' => __('api.tenant.memory_quota_exceeded', ['remaining' => $remainingMem]),
            ], 422);
        }

        return null;
    }

    /**
     * What is still free for tenant quotas on a region: min(region capacity, organization
     * ceiling) minus the quotas of the other tenants.
     *
     * @return array{cpu: int, memory: int}
     */
    protected function remainingQuotaCapacity(KnativeRegion $region, ?string $excludeTenantId = null): array
    {
        // No capacity yet (status check not run since): measure it on the region's nodes
        // (allocatable minus platform requests) instead of allowing only 0/0 quotas
        if ($region->total_cpu_m <= 0 || $region->total_memory_mb <= 0) {
            try {
                app(KnativeNodeService::class)->refreshSchedulableCapacity($region);
            } catch (\Throwable) {
                // cluster unreachable — validate against the stored capacity
            }
        }

        $query = DB::connection('central')
            ->table('tenant_regions')
            ->where('knative_region_id', $region->id);

        if ($excludeTenantId) {
            $query->where('tenant_id', '!=', $excludeTenantId);
        }

        $allocated = $query->selectRaw('COALESCE(SUM(cpu_quota_m), 0) as cpu, COALESCE(SUM(memory_quota_mb), 0) as mem')->first();

        $limitCpu = (int) $region->total_cpu_m;
        $limitMem = (int) $region->total_memory_mb;

        if ($ceilingCpu = OrganizationCeiling::cpuMillicores()) {
            $limitCpu = min($limitCpu, $ceilingCpu);
        }
        if ($ceilingMem = OrganizationCeiling::memoryMb()) {
            $limitMem = min($limitMem, $ceilingMem);
        }

        return [
            'cpu' => $limitCpu - (int) $allocated->cpu,
            'memory' => $limitMem - (int) $allocated->mem,
        ];
    }
}
