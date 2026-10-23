<?php

namespace App\Services\Ai\Gpu;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\GlobalProjects\GlobalProjectRegions;
use App\Support\Projects\ScaleSchedule;
use Illuminate\Validation\Validator;

/**
 * GPU admission of projects (create, update, deploy): the request's shape (rules()), the
 * runtime version (xaas / faas only on GPU versions), the region (GPU nodes, product, memory,
 * sandbox — GpuPolicy) and the organization's GPU quota (GpuQuota).
 */
final class GpuValidation
{
    /** Validation rules of config.gpu (prefix "config" for projects). */
    public static function rules(string $prefix = 'config'): array
    {
        $max = (int) config('ai.gpu.max_count', 8);

        return [
            "{$prefix}.gpu" => 'nullable|array',
            "{$prefix}.gpu.count" => "required_with:{$prefix}.gpu|integer|min:0|max:{$max}",
            "{$prefix}.gpu.type" => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._()-]*$/'],
            "{$prefix}.gpu.memory_min" => 'nullable|integer|min:1|max:1048576',
        ];
    }

    /**
     * A config.gpu with count 0 removes the GPU; anything else is stored as {count, type, memory_min}.
     */
    public static function normalize(mixed $gpu): ?array
    {
        return GpuRequest::fromArray($gpu)?->toArray();
    }

    /**
     * Check a project's GPU request after validation of a create / update.
     *
     * @param  array  $config  the config of the request merged over the stored one (update)
     */
    public static function check(Validator $validator, ?KnativeRegion $region, string $serviceType, ?string $runtime, ?string $runtimeVersion, array $config, ?string $excludeProjectId = null, string $attribute = 'config.gpu'): void
    {
        if ($validator->errors()->isNotEmpty() || ! $region || ! in_array($serviceType, Project::SERVICE_TYPES, true)) {
            return;
        }

        $requested = GpuRequest::fromArray($config['gpu'] ?? null);
        $runtimeGpu = $serviceType === 'caas' ? null : GpuRequest::runtimeGpu($runtime, $runtimeVersion);

        if ($requested && $serviceType !== 'caas' && ! $runtimeGpu) {
            $validator->errors()->add($attribute, __('ai.gpu.runtime_not_gpu', ['runtime' => (string) $runtime, 'version' => (string) ($runtimeVersion ?: 'default')]));

            return;
        }

        foreach (self::regionProblems($region, $serviceType, $runtime, $runtimeVersion, $config, $excludeProjectId) as $problem) {
            $validator->errors()->add($attribute, $problem);
        }
    }

    /**
     * Whether $region admits the GPUs of a project (GPU nodes, product, memory, sandbox — GpuPolicy —
     * and the organization's GPU quota there): the problems, empty when it does or when the project
     * asks for none. A request on a runtime version without GPUs is check()'s error, not one here.
     * A global project asks every one of its regions (docs/global-projects.md).
     *
     * @return list<string>
     */
    public static function regionProblems(KnativeRegion $region, string $serviceType, ?string $runtime, ?string $runtimeVersion, array $config, ?string $excludeProjectId = null): array
    {
        if (! in_array($serviceType, Project::SERVICE_TYPES, true)) {
            return [];
        }

        $requested = GpuRequest::fromArray($config['gpu'] ?? null);
        $runtimeGpu = $serviceType === 'caas' ? null : GpuRequest::runtimeGpu($runtime, $runtimeVersion);
        if ($requested && $serviceType !== 'caas' && ! $runtimeGpu) {
            return [];
        }

        $request = $requested ?? ($runtimeGpu ? new GpuRequest($runtimeGpu['count'], null, null, $runtimeGpu['resource']) : null);
        if (! $request) {
            return [];
        }

        $problems = app(GpuPolicy::class)->problems($region, $serviceType, $request);
        if ($problems !== []) {
            return $problems;
        }

        // A timed warmer holds GPUs for its peak (ScaleSchedule::reservedMinScale)
        $gpus = $request->count * max(1, ScaleSchedule::reservedMinScale($config));
        $quota = app(GpuQuota::class)->check($region, $gpus, $excludeProjectId);

        return $quota['allowed'] ? [] : [(string) $quota['message']];
    }

    /**
     * Deploy-time check of a stored project (quota changes and lost GPU nodes since the
     * project was created). Returns the problems, empty when it may deploy.
     *
     * @return list<string>
     */
    public static function deployProblems(Project $project, array $deployConfig = []): array
    {
        // A global project runs a copy in every region: each one admits its GPUs
        $problems = [];
        foreach (GlobalProjectRegions::of($project, false) as $region) {
            array_push($problems, ...self::deployProblemsIn($project, $region, $deployConfig));
        }

        return array_values(array_unique($problems));
    }

    /**
     * deployProblems() of one region.
     *
     * @return list<string>
     */
    private static function deployProblemsIn(Project $project, ?KnativeRegion $region, array $deployConfig): array
    {
        $config = match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
            default => null,
        };

        if (! $region || ! $config || ! ($request = GpuRequest::forProject($project, $config))) {
            return [];
        }

        $problems = app(GpuPolicy::class)->problems($region, $project->service_type, $request);
        if ($problems !== []) {
            return $problems;
        }

        $minScale = ScaleSchedule::rows($config) !== null
            ? ScaleSchedule::reservedMinScale($config)
            : (int) ($deployConfig['min_scale'] ?? $config->min_scale ?? 0);
        $quota = app(GpuQuota::class)->check($region, $request->count * max(1, $minScale), $project->id);

        return $quota['allowed'] ? [] : [$quota['message']];
    }
}
