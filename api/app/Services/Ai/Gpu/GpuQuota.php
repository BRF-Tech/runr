<?php

namespace App\Services\Ai\Gpu;

use App\Models\AiModelService;
use App\Models\KnativeRegion;
use App\Models\Project;

/**
 * GPU admission of projects and model services. A workload reserves count × max(1, min_scale)
 * GPUs from the moment it is created (like CPU / memory quotas, docs/ai.md#quotas-and-billing).
 *
 * This base implementation has no quota (single organization): every request is allowed and the
 * reservation is only reported. The Tenancy module binds Modules\Tenancy\Services\TenantGpuQuota,
 * which checks the tenant's GPU quota on the region (tenant_regions.gpu_quota).
 */
class GpuQuota
{
    /**
     * GPUs reserved in the current organization on a region, without the given project / service.
     */
    public function used(KnativeRegion $region, ?string $excludeProjectId = null, ?string $excludeServiceId = null): int
    {
        $total = 0;

        $projects = Project::where('knative_region_id', $region->id)
            ->when($excludeProjectId, fn ($q) => $q->whereKeyNot($excludeProjectId))
            ->with('xaasConfig', 'faasConfig', 'containerConfig')
            ->get();

        foreach ($projects as $project) {
            $total += self::projectReservation($project);
        }

        // Stopped model services hold no GPUs; starting one is admitted again (deploy)
        $services = AiModelService::where('knative_region_id', $region->id)
            ->where('device', 'gpu')
            ->where('status', '!=', AiModelService::STATUS_STOPPED)
            ->when($excludeServiceId, fn ($q) => $q->whereKeyNot($excludeServiceId))
            ->get();

        foreach ($services as $service) {
            $total += $service->reservedGpus();
        }

        return $total;
    }

    /** GPUs a project reserves: its GPU request × max(1, min_scale). */
    public static function projectReservation(Project $project, ?object $config = null): int
    {
        if (! $project->isSupportedType()) {
            return 0;
        }

        $config ??= match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
        };

        $request = GpuRequest::forProject($project, $config);
        if (! $request) {
            return 0;
        }

        return $request->count * max(1, (int) ($config->min_scale ?? 0));
    }

    /** The organization's GPU quota on the region; null = no limit. */
    public function quota(KnativeRegion $region): ?int
    {
        return null;
    }

    /**
     * Can the organization reserve $gpus more GPUs on the region?
     *
     * @return array{allowed: bool, message?: string, quota: ?int, used: int, remaining: ?int}
     */
    public function check(KnativeRegion $region, int $gpus, ?string $excludeProjectId = null, ?string $excludeServiceId = null): array
    {
        $quota = $this->quota($region);
        $used = $gpus > 0 || $quota !== null ? $this->used($region, $excludeProjectId, $excludeServiceId) : 0;

        if ($quota === null || $gpus === 0) {
            return ['allowed' => true, 'quota' => $quota, 'used' => $used, 'remaining' => $quota === null ? null : max(0, $quota - $used)];
        }

        $remaining = max(0, $quota - $used);
        if ($gpus > $remaining) {
            return [
                'allowed' => false,
                'message' => __('ai.gpu.quota_exceeded', ['requested' => $gpus, 'remaining' => $remaining, 'quota' => $quota]),
                'quota' => $quota,
                'used' => $used,
                'remaining' => $remaining,
            ];
        }

        return ['allowed' => true, 'quota' => $quota, 'used' => $used, 'remaining' => $remaining - $gpus];
    }

    /**
     * CPU / memory admission of a model service against the organization quota (projects are
     * admitted by the Tenancy module's CheckTenantQuota middleware). No quota here.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand  ModelServiceReservations::demandFor()
     * @return array{allowed: bool, message?: string, budget?: string, problems?: array<string, string>, remaining_cpu_m?: int, remaining_memory_mb?: int}
     */
    public function checkResources(KnativeRegion $region, array $demand, ?string $excludeServiceId = null): array
    {
        return ['allowed' => true];
    }

    /** Whether the current user is exempt from quotas (Tenancy: platform admins). */
    public function exempt(): bool
    {
        return false;
    }
}
