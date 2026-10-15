<?php

namespace Modules\Tenancy\Services;

use App\Models\BindingUsageRecord;
use App\Models\Deployment;
use App\Models\Project;
use App\Services\Sandboxes\SandboxUsageAggregator;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Models\CostConfig;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\UsageRecord;

class TenantBillingService
{
    public function __construct(
        protected TenantQuotaService $quotaService
    ) {}

    /**
     * Get billing/usage summary for a tenant across all assigned regions.
     */
    public function getSummary(Tenant $tenant): array
    {
        $regions = $tenant->regions()->get();

        $regionSummaries = $regions->map(function ($region) {
            $usage = $this->quotaService->currentUsage($region);

            return [
                'region_id' => $region->id,
                'region_name' => $region->name,
                'cpu_quota_m' => $region->pivot->cpu_quota_m,
                'memory_quota_mb' => $region->pivot->memory_quota_mb,
                'used_cpu_m' => $usage['cpu_m'],
                'used_memory_mb' => $usage['memory_mb'],
                'active_deployments' => $this->countActiveDeployments($region),
            ];
        });

        $totalCpu = $regionSummaries->sum('used_cpu_m');
        $totalMemory = $regionSummaries->sum('used_memory_mb');
        $totalDeploy = $regionSummaries->sum('active_deployments');

        // Monthly cost from allocated quotas × monthly pricing
        $monthlyCost = 0;
        $currency = 'USD';

        foreach ($regions as $region) {
            $config = CostConfig::forRegion($region->id);
            $currency = $config->currency ?? $currency;
            $cpuCores = ($region->pivot->cpu_quota_m ?? 0) / 1000;
            $memoryGb = ($region->pivot->memory_quota_mb ?? 0) / 1024;
            $monthlyCost += $cpuCores * (float) $config->cpu_price_per_core_month;
            $monthlyCost += $memoryGb * (float) $config->memory_price_per_gb_month;
        }

        // Add usage-based cost if any
        $from = now()->subDays(30)->toDateString();
        $usageCost = (float) UsageRecord::where('record_date', '>=', $from)->sum('final_cost');
        // Sandbox API usage (central sandbox_usage_records)
        $usageCost += SandboxUsageAggregator::costSince((string) $tenant->id, $from);
        // Bindings (docs/bindings.md): priced storage GB-hours / instance hours
        $bindingCost = Schema::hasTable('binding_usage_records')
            ? (float) BindingUsageRecord::query()->whereDate('record_date', '>=', $from)->sum('final_cost')
            : 0.0;
        $usageCost += $bindingCost;

        return [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
            'total_cost' => round($monthlyCost, 4),
            'monthly_cost' => round($monthlyCost, 4),
            'usage_cost' => round($usageCost, 6),
            'binding_usage_cost' => round($bindingCost, 6),
            'currency' => $currency,
            'cpu_m' => $totalCpu,
            'memory_mb' => $totalMemory,
            'cpu_quota_m' => $regionSummaries->sum('cpu_quota_m'),
            'memory_quota_mb' => $regionSummaries->sum('memory_quota_mb'),
            'active_deployments' => $totalDeploy,
            'regions' => $regionSummaries->values()->all(),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get usage history for a tenant within a date range.
     */
    public function getUsageHistory(Tenant $tenant, int $days = 30): array
    {
        $from = now()->subDays($days)->toDateString();

        $records = UsageRecord::where('record_date', '>=', $from)
            ->orderByDesc('record_date')
            ->get();

        $projectNames = Project::whereIn('id', $records->pluck('project_id')->unique())
            ->pluck('slug', 'id');

        return $records->map(fn ($r) => array_merge($r->toArray(), [
            'project_name' => $projectNames[$r->project_id] ?? $r->project_id,
        ]))->all();
    }

    /**
     * Count active deployments in a region within the current tenant schema.
     */
    protected function countActiveDeployments($region): int
    {
        return Deployment::whereIn('status', ['running', 'active'])
            ->whereHas('project', fn ($q) => $q->where('knative_region_id', $region->id))
            ->count();
    }
}
