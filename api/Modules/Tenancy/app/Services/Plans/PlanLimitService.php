<?php

namespace Modules\Tenancy\Services\Plans;

use App\Models\AiModelService;
use App\Models\Project;
use App\Services\Ai\Gpu\GpuQuota;
use Illuminate\Support\Facades\Cache;
use Modules\Tenancy\Models\Plan;
use Modules\Tenancy\Models\Purchase;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Models\TenantSubscription;
use Modules\Tenancy\Models\UsageRecord;
use Modules\Tenancy\Services\Billing\BillingSettings;

/**
 * Plan limits beyond CPU / memory (those are tenant region quotas, PlanQuotaService):
 *
 *   max_projects           projects in the tenant
 *   gpu_quota              GPUs the tenant's workloads reserve over all regions: projects
 *                          (config.gpu, or a GPU runtime version) and model services on
 *                          device gpu that are not stopped, each count × max(1, min_scale)
 *                          (GpuQuota's rule)
 *   invocations_per_month  sessions metered this calendar month (usage_records.session_count);
 *                          over the limit no new deploys are accepted
 *   storage_quota_mb       the tenant's git repository (code + versions) on the API volume
 *
 * and the plan's feature flags. Add-ons bought in an external system (purchases of kind addon,
 * docs/billing.md) raise a limit while they run: effective limit = plan limit + Σ add-on limits
 * (an unlimited plan limit stays unlimited). Every check is a no-op while plans are off or for
 * tenants without a plan. null = unlimited.
 */
class PlanLimitService
{
    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * The plan whose limits apply to the tenant (plans on, tenant subscribed, subscription not ended).
     */
    public function planFor(Tenant|string $tenant): ?Plan
    {
        if (! $this->settings->plansEnabled()) {
            return null;
        }

        $tenantId = $tenant instanceof Tenant ? (string) $tenant->id : $tenant;
        $subscription = TenantSubscription::query()->with('plan')->where('tenant_id', $tenantId)->first();

        if (! $subscription?->plan || $subscription->status === TenantSubscription::STATUS_CANCELED) {
            return null;
        }

        return $subscription->plan;
    }

    /**
     * @return array{projects: int, gpu: int, invocations_this_month: int, storage_mb: int}
     */
    public function usage(Tenant $tenant): array
    {
        return $this->inTenant($tenant, fn () => [
            'projects' => $this->projectCount(),
            'gpu' => $this->gpuCount(),
            'invocations_this_month' => $this->invocationsThisMonth(),
            'storage_mb' => $this->storageMb($tenant),
        ]);
    }

    /**
     * null when allowed, otherwise the limit that would be exceeded. $exclude leaves the workload
     * being changed out of the GPU count (['project' => id] / ['service' => id]).
     *
     * @param  array{project?: string|null, service?: string|null}  $exclude
     * @return array{limit: string, max: int, used: int}|null
     */
    public function exceeds(Tenant $tenant, Plan $plan, string $limit, int $adding = 1, array $exclude = []): ?array
    {
        $max = $this->effectiveLimit($tenant, $plan, $limit);
        if ($max === null) {
            return null;
        }

        $used = $this->inTenant($tenant, fn () => match ($limit) {
            'max_projects' => $this->projectCount(),
            'gpu_quota' => $this->gpuCount($exclude['project'] ?? null, $exclude['service'] ?? null),
            'invocations_per_month' => $this->invocationsThisMonth(),
            'storage_quota_mb' => $this->storageMb($tenant),
            default => 0,
        });

        // Invocations / storage are consumption: "full" blocks; projects / GPUs are additions
        $over = in_array($limit, ['invocations_per_month', 'storage_quota_mb'], true)
            ? $used >= $max
            : $used + $adding > $max;

        return $over ? ['limit' => $limit, 'max' => $max, 'used' => $used] : null;
    }

    /**
     * Limits of running add-ons (purchases of kind addon), summed per limit.
     *
     * @return array<string, int>
     */
    public function addonLimits(Tenant|string $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? (string) $tenant->id : $tenant;
        $sums = [];

        try {
            $addons = Purchase::query()->where('tenant_id', $tenantId)->activeAddons()->get(['limits']);
        } catch (\Throwable) {
            return [];   // billing_purchases not migrated yet
        }

        foreach ($addons as $addon) {
            foreach ((array) ($addon->limits ?? []) as $limit => $value) {
                if (in_array($limit, Plan::LIMITS, true) && (int) $value > 0) {
                    $sums[$limit] = ($sums[$limit] ?? 0) + (int) $value;
                }
            }
        }

        return $sums;
    }

    /** Plan limit + running add-ons; null = unlimited. */
    public function effectiveLimit(Tenant|string $tenant, Plan $plan, string $limit, ?array $addons = null): ?int
    {
        $max = $plan->limit($limit);
        if ($max === null) {
            return null;
        }

        return $max + (int) (($addons ?? $this->addonLimits($tenant))[$limit] ?? 0);
    }

    /**
     * Every limit of the plan with the running add-ons.
     *
     * @return array<string, int|null>
     */
    public function effectiveLimits(Tenant|string $tenant, Plan $plan): array
    {
        $addons = $this->addonLimits($tenant);

        return array_combine(Plan::LIMITS, array_map(fn ($l) => $this->effectiveLimit($tenant, $plan, $l, $addons), Plan::LIMITS));
    }

    public function allowsFeature(Plan $plan, string $feature): bool
    {
        return $plan->allowsFeature($feature);
    }

    // ── Measurements (tenant context) ───────────────────────────────────────

    private function projectCount(): int
    {
        return Project::query()->count();
    }

    private function gpuCount(?string $excludeProjectId = null, ?string $excludeServiceId = null): int
    {
        $total = 0;

        try {
            Project::query()
                ->when($excludeProjectId, fn ($q) => $q->whereKeyNot($excludeProjectId))
                ->with('xaasConfig', 'faasConfig', 'containerConfig')
                ->get()
                ->each(function (Project $project) use (&$total) {
                    $total += GpuQuota::projectReservation($project);
                });
        } catch (\Throwable) {
            // GPU columns missing on a tenant schema that has not been migrated yet
        }

        try {
            // Stopped model services hold no GPUs (GpuQuota::used)
            AiModelService::query()
                ->where('device', 'gpu')
                ->where('status', '!=', AiModelService::STATUS_STOPPED)
                ->when($excludeServiceId, fn ($q) => $q->whereKeyNot($excludeServiceId))
                ->get()
                ->each(function (AiModelService $service) use (&$total) {
                    $total += $service->reservedGpus();
                });
        } catch (\Throwable) {
            // model service tables missing on a tenant schema that has not been migrated yet
        }

        return $total;
    }

    private function invocationsThisMonth(): int
    {
        try {
            return (int) UsageRecord::query()
                ->whereDate('record_date', '>=', now()->startOfMonth()->toDateString())
                ->sum('session_count');
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Size of storage/git/<tenant slug> in MB, measured at most every 10 minutes.
     */
    private function storageMb(Tenant $tenant): int
    {
        return (int) Cache::remember('plan-storage-mb:'.$tenant->id, 600, function () use ($tenant) {
            $slug = (string) $tenant->slug;
            if (preg_match('/^[A-Za-z0-9_-]+$/', $slug) !== 1) {
                return 0;
            }

            $path = storage_path('git/'.$slug);
            if (! is_dir($path)) {
                return 0;
            }

            $bytes = 0;
            try {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile()) {
                        $bytes += $file->getSize();
                    }
                }
            } catch (\Throwable) {
                // unreadable entries are not counted
            }

            return (int) ceil($bytes / 1048576);
        });
    }

    /**
     * Run inside the tenant's database: as is when the request already initialized it, through
     * $tenant->run() otherwise; on the current connection when tenancy is off (tests).
     */
    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        if (! config('tenancy.enabled')) {
            return $callback();
        }

        if (tenancy()->initialized && (string) tenancy()->tenant?->getTenantKey() === (string) $tenant->id) {
            return $callback();
        }

        return $tenant->run($callback);
    }
}
