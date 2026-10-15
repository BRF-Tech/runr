<?php

namespace Modules\Tenancy\Services;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Ai\Models\ModelServiceReservations;
use App\Services\Helpers\ResourceReservation;
use Modules\Tenancy\Models\Tenant;

/**
 * Tenant quota admission (quota model option C, Limits Decisions §4): a
 * project's admitted reservation is its instance size × max(1, min_scale), plus the fixed
 * per-pod queue-proxy overhead on Knative Service pods and one queue consumer for queue
 * projects (App\Services\Helpers\ResourceReservation). max_scale is not admitted; runtime use
 * above the admitted reservation is bounded by the namespace ResourceQuota of the
 * organization ceiling.
 */
class TenantQuotaService
{
    /**
     * Other workloads that hold an organization's region capacity next to its projects
     * (e.g. git deploy previews): fn (KnativeRegion $region, ?string $excludeProjectId) → {cpu_m, memory_mb}.
     *
     * @var list<\Closure>
     */
    protected static array $usageContributors = [];

    public static function addUsageContributor(\Closure $contributor): void
    {
        static::$usageContributors[] = $contributor;
    }

    /**
     * One pod of a project: its own configured CPU/memory plus, on Knative Service pods
     * (trigger http / queue), the queue-proxy sidecar Knative injects — its requests,
     * KnativeConstants::QUEUE_PROXY_CPU_M / QUEUE_PROXY_MEMORY_MB; its CPU limit
     * above the request (QUEUE_PROXY_CPU_LIMIT_M) is not reserved. Schedule (CronJob) pods have no
     * queue-proxy.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function podReservation(int $cpuM, int $memoryMb, ?string $triggerType = 'http'): array
    {
        return ResourceReservation::perPod($cpuM, $memoryMb, $triggerType);
    }

    /**
     * Admitted reservation of a whole project (see class doc).
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function projectReservation(int $cpuM, int $memoryMb, ?string $triggerType = 'http', int $minScale = 0): array
    {
        return ResourceReservation::forSize($cpuM, $memoryMb, $triggerType, $minScale);
    }

    /**
     * Check an already computed project reservation against the tenant's remaining quota.
     *
     * @param  array{cpu_m: int, memory_mb: int}  $reservation
     * @return array{allowed: bool, message?: string, remaining_cpu_m?: int, remaining_memory_mb?: int}
     */
    public function checkReservation(Tenant $tenant, KnativeRegion $region, array $reservation, ?string $excludeProjectId = null): array
    {
        $pivot = $tenant->regions()
            ->where('knative_region_id', $region->id)
            ->first();

        if (! $pivot) {
            return [
                'allowed' => false,
                'message' => __('api.tenant.no_region_access'),
            ];
        }

        $quotaCpu = (int) $pivot->pivot->cpu_quota_m;
        $quotaMemory = (int) $pivot->pivot->memory_quota_mb;

        // 0 means unlimited
        if ($quotaCpu === 0 && $quotaMemory === 0) {
            return ['allowed' => true];
        }

        $cpuM = (int) $reservation['cpu_m'];
        $memoryMb = (int) $reservation['memory_mb'];

        $usage = $this->currentUsage($region, $excludeProjectId);

        $remainingCpu = $quotaCpu > 0 ? $quotaCpu - $usage['cpu_m'] : PHP_INT_MAX;
        $remainingMemory = $quotaMemory > 0 ? $quotaMemory - $usage['memory_mb'] : PHP_INT_MAX;

        if ($cpuM > $remainingCpu || $memoryMb > $remainingMemory) {
            return [
                'allowed' => false,
                'message' => __('api.tenant.quota_exceeded'),
                'remaining_cpu_m' => max(0, $remainingCpu),
                'remaining_memory_mb' => max(0, $remainingMemory),
            ];
        }

        return [
            'allowed' => true,
            'remaining_cpu_m' => $remainingCpu - $cpuM,
            'remaining_memory_mb' => $remainingMemory - $memoryMb,
        ];
    }

    /**
     * Sum the admitted reservations of all projects in the current tenant schema for a region.
     * Resources are claimed at project creation, not deployment.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public function currentUsage(KnativeRegion $region, ?string $excludeProjectId = null): array
    {
        $query = Project::where('knative_region_id', $region->id)
            ->with('xaasConfig', 'faasConfig', 'containerConfig');

        if ($excludeProjectId) {
            $query->where('id', '!=', $excludeProjectId);
        }

        $usage = ResourceReservation::sum($query->get());

        // Model services of the tenant (docs/huggingface.md) reserve CPU / memory too
        $models = ModelServiceReservations::sum($region);
        $usage = [
            'cpu_m' => $usage['cpu_m'] + $models['cpu_m'],
            'memory_mb' => $usage['memory_mb'] + $models['memory_mb'],
        ] + $usage;

        // Other contributors (git deploy previews, addUsageContributor)
        foreach (static::$usageContributors as $contributor) {
            $extra = $contributor($region, $excludeProjectId);
            $usage['cpu_m'] += (int) ($extra['cpu_m'] ?? 0);
            $usage['memory_mb'] += (int) ($extra['memory_mb'] ?? 0);
        }

        return $usage;
    }
}
