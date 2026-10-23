<?php

namespace App\Services\Environments;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\GitDeploy\TenantContext;
use App\Services\Helpers\RegionCapacity;
use App\Services\Helpers\ResourceReservation;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * What environments hold of a region (docs/environments.md#how-many-environments): there is no
 * fixed number of environments — each one other than the project's own workload reserves its size
 * (the project's config with its overrides) in the region it runs in, like a project does: from its
 * creation on, deployed or not. It counts in the region capacity (RegionCapacity::usage) and in the
 * organization quota (a TenantQuotaService usage contributor, EnvironmentServiceProvider), so the
 * quotas decide how many fit. Preview environments of pull requests count on their own
 * (PreviewService) and are not limited by this.
 */
final class EnvironmentReservations
{
    /**
     * Demand of one environment: its effective config in its region, with the RuntimeClass it was
     * deployed with.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demand(Project $project, ProjectEnvironment $environment): array
    {
        if ($environment->is_primary) {
            return ResourceReservation::noDemand();
        }

        return ResourceReservation::demandForProject(DeployTarget::environment($project, $environment));
    }

    /**
     * Demand of the environments that run in a region (every region when null) of the current
     * schema, without $excludeEnvironmentId.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demandIn(?string $regionId, ?string $excludeEnvironmentId = null): array
    {
        $total = ResourceReservation::noDemand();
        if (! ProjectEnvironment::tablesReady()) {
            return $total;
        }

        $environments = ProjectEnvironment::query()
            ->where('is_primary', false)
            ->when($excludeEnvironmentId !== null, fn ($q) => $q->whereKeyNot($excludeEnvironmentId))
            ->when($regionId !== null, fn ($q) => $q->where(fn ($w) => $w->where('knative_region_id', $regionId)
                ->orWhere(fn ($own) => $own->where(fn ($none) => $none->whereNull('knative_region_id')->orWhere('knative_region_id', ''))
                    ->whereHas('project', fn ($p) => $p->where('knative_region_id', $regionId)))))
            ->with(['project.xaasConfig', 'project.faasConfig', 'project.containerConfig', 'project.knativeRegion', 'knativeRegion'])
            ->get();

        foreach ($environments as $environment) {
            $total = ResourceReservation::addDemand($total, self::demand($environment->project, $environment));
        }

        return $total;
    }

    /**
     * Why an environment of this size does not fit its region now (capacity, then the
     * organization quota), null when it does. $current: the environment being changed (its
     * reservation now is not counted).
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     */
    public static function refusal(?KnativeRegion $region, array $demand, ?ProjectEnvironment $current = null): ?string
    {
        if ($region === null || $region->is_demo) {
            return null;
        }

        $usage = RegionCapacity::usage((string) $region->getKey());
        if ($current !== null && $current->exists && ! $current->is_primary) {
            $usage = self::subtract($usage, self::demand($current->project, $current));
        }
        if ($problems = RegionCapacity::problems($region, $demand, $usage)) {
            return (string) __('environments.capacity', ['problems' => implode(' ', $problems)]);
        }

        return self::quotaRefusal($region, $demand, $current);
    }

    /**
     * The organization quota on the region (multi-tenant installs; a region of the organization's
     * own and an infinite region have none).
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     */
    private static function quotaRefusal(KnativeRegion $region, array $demand, ?ProjectEnvironment $current): ?string
    {
        if (! TenantContext::enabled() || ! class_exists(TenantQuotaService::class) || ! ($tenant = TenantContext::find(TenantContext::currentId()))) {
            return null;
        }
        if ($region->region_mode === 'infinite') {
            return null;
        }

        // The environment being changed holds its place: only what it adds is checked
        if ($current !== null && $current->exists && ! $current->is_primary) {
            $demand = self::positive(self::subtract($demand, self::demand($current->project, $current)));
        }

        $result = app(TenantQuotaService::class)->checkReservation($tenant, $region, $demand);

        return $result['allowed'] ? null : (string) __('environments.quota', ['message' => $result['message'] ?? '']);
    }

    /**
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $a
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $b
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    private static function subtract(array $a, array $b): array
    {
        $minus = fn (array $x, array $y) => ['cpu_m' => max(0, $x['cpu_m'] - $y['cpu_m']), 'memory_mb' => max(0, $x['memory_mb'] - $y['memory_mb'])];

        return ['hard' => $minus($a['hard'], $b['hard']), 'burst' => $minus($a['burst'], $b['burst'])];
    }

    /**
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    private static function positive(array $demand): array
    {
        return self::subtract($demand, ResourceReservation::noDemand());
    }
}
