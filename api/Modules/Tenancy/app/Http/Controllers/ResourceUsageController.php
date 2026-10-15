<?php

namespace Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Helpers\ResourceReservation;
use Illuminate\Http\JsonResponse;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantQuotaService;

class ResourceUsageController extends Controller
{
    public function __construct(
        protected TenantQuotaService $quotaService
    ) {}

    public function index(): JsonResponse
    {
        $regions = KnativeRegion::on('central')
            ->where('is_demo', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $tenants = Tenant::orderBy('name')->get();

        $data = $regions->map(function ($region) use ($tenants) {
            $mode = $region->region_mode ?? 'managed';
            $tenantUsage = $this->buildTenantUsage($region, $tenants);

            // Sum per-tenant usages (central schema has no deployments)
            $usedCpu = array_sum(array_column($tenantUsage, 'used_cpu_m'));
            $usedMem = array_sum(array_column($tenantUsage, 'used_memory_mb'));

            $row = [
                'id' => $region->id,
                'name' => $region->name,
                'region_mode' => $mode,
                'total_cpu_m' => $region->total_cpu_m,
                'total_memory_mb' => $region->total_memory_mb,
                'used_cpu_m' => $usedCpu,
                'used_memory_mb' => $usedMem,
                'tenants' => $tenantUsage,
            ];

            // For managed regions, compute total allocated quota across tenants
            if ($mode === 'managed') {
                $row['allocated_cpu_m'] = array_sum(array_column($tenantUsage, 'cpu_quota_m'));
                $row['allocated_memory_mb'] = array_sum(array_column($tenantUsage, 'memory_quota_mb'));
            }

            return $row;
        });

        return response()->json($data);
    }

    protected function buildTenantUsage(KnativeRegion $region, $tenants): array
    {
        $result = [];

        foreach ($tenants as $tenant) {
            $pivot = $tenant->regions()
                ->where('knative_region_id', $region->id)
                ->first();

            // Sum project configs — resources are claimed at project creation
            $usage = $tenant->run(function () use ($region) {
                return $this->projectUsage($region);
            });

            $hasQuota = $pivot !== null;
            $hasUsage = $usage['cpu_m'] > 0 || $usage['memory_mb'] > 0;

            if (! $hasQuota && ! $hasUsage) {
                continue;
            }

            $result[] = [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'tenant_slug' => $tenant->slug,
                'used_cpu_m' => $usage['cpu_m'],
                'used_memory_mb' => $usage['memory_mb'],
                'cpu_quota_m' => $pivot?->pivot->cpu_quota_m ?? 0,
                'memory_quota_mb' => $pivot?->pivot->memory_quota_mb ?? 0,
            ];
        }

        return $result;
    }

    /**
     * Admitted reservation of the region's projects in the current tenant schema — the same
     * formula as tenant quota admission (ResourceReservation).
     */
    protected function projectUsage(KnativeRegion $region): array
    {
        $projects = Project::where('knative_region_id', $region->id)
            ->with('xaasConfig', 'faasConfig', 'containerConfig')
            ->get();

        $usage = ResourceReservation::sum($projects);
        // the tenant's running model services (docs/huggingface.md)
        $models = ModelServiceReservations::sum($region);

        return ['cpu_m' => $usage['cpu_m'] + $models['cpu_m'], 'memory_mb' => $usage['memory_mb'] + $models['memory_mb']] + $usage;
    }
}
