<?php

namespace App\Services\Concerns;

use App\Models\ContainerConfig;
use App\Models\FaasConfig;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Services\Ai\Gpu\GpuValidation;
use App\Services\Helpers\InstanceSizes;
use App\Services\Runtimes\ProjectEntrypoint;
use App\Support\Projects\ScaleSchedule;

trait ProjectConfigTrait
{
    private function createCaaSConfig(Project $project, array $config): void
    {
        $size = InstanceSizes::defaultFor('caas');

        ContainerConfig::create([
            'project_id' => $project->id,
            'image' => $config['image'],
            'command' => $config['command'] ?? null,
            'args' => $config['args'] ?? null,
            'env_vars' => $config['env_vars'] ?? null,
            'ports' => $config['ports'] ?? null,
            'memory_mb' => $config['memory_mb'] ?? $size['memory_mb'],
            'cpu' => $config['cpu'] ?? $size['cpu'],
            'timeout_seconds' => $config['timeout_seconds'] ?? self::defaultTimeout($config, $project),
            'image_pull_policy' => $config['image_pull_policy'] ?? 'IfNotPresent',
            'image_pull_secret' => $config['image_pull_secret'] ?? null,
            'trigger_type' => $config['trigger_type'] ?? 'http',
            'schedule' => $config['schedule'] ?? null,
            'queue_stream' => $config['queue_stream'] ?? null,
            'scale_to_zero' => $config['scale_to_zero'] ?? true,
            'min_scale' => $config['min_scale'] ?? 0,
            'max_scale' => $config['max_scale'] ?? 1,
            'gpu' => GpuValidation::normalize($config['gpu'] ?? null),
            'scale_schedule' => ScaleSchedule::normalize($config['scale_schedule'] ?? null),
        ]);
    }

    private function createXaaSConfig(Project $project, array $config): void
    {
        $size = InstanceSizes::defaultFor('xaas');

        XaasConfig::create([
            'project_id' => $project->id,
            'filename' => $this->runtimeManifest($project->runtime)?->defaultFilename() ?? 'main.txt',
            'handler' => $config['handler'] ?? $this->runtimeManifest($project->runtime)?->handlerFunction() ?? 'handler',
            'env_vars' => $config['env_vars'] ?? null,
            'memory_mb' => $config['memory_mb'] ?? $size['memory_mb'],
            'cpu' => $config['cpu'] ?? $size['cpu'],
            'timeout_seconds' => $config['timeout_seconds'] ?? self::defaultTimeout($config, $project),
            'trigger_type' => $config['trigger_type'] ?? 'http',
            'schedule' => $config['schedule'] ?? null,
            'queue_stream' => $config['queue_stream'] ?? null,
            'scale_to_zero' => $config['scale_to_zero'] ?? true,
            'min_scale' => $config['min_scale'] ?? 0,
            'max_scale' => $config['max_scale'] ?? 1,
            'gpu' => GpuValidation::normalize($config['gpu'] ?? null),
            'scale_schedule' => ScaleSchedule::normalize($config['scale_schedule'] ?? null),
        ]);
    }

    private function createFaaSConfig(Project $project, array $config): void
    {
        $size = InstanceSizes::defaultFor('faas');

        FaasConfig::create([
            'project_id' => $project->id,
            'handler' => $config['handler'] ?? $this->defaultFaasHandler($project),
            'env_vars' => $config['env_vars'] ?? null,
            'memory_mb' => $config['memory_mb'] ?? $size['memory_mb'],
            'cpu' => $config['cpu'] ?? $size['cpu'],
            'timeout_seconds' => $config['timeout_seconds'] ?? self::defaultTimeout($config, $project),
            'trigger_type' => $config['trigger_type'] ?? 'http',
            'schedule' => $config['schedule'] ?? null,
            'queue_stream' => $config['queue_stream'] ?? null,
            'scale_to_zero' => $config['scale_to_zero'] ?? true,
            'min_scale' => $config['min_scale'] ?? 0,
            'max_scale' => $config['max_scale'] ?? 1,
            'gpu' => GpuValidation::normalize($config['gpu'] ?? null),
            'scale_schedule' => ScaleSchedule::normalize($config['scale_schedule'] ?? null),
        ]);
    }

    /**
     * Default timeout of a new project: 30 s, GPU projects ai.gpu.default_timeout (300 s) — CUDA
     * images and model loading take longer than a function cold start.
     */
    private static function defaultTimeout(array $config, Project $project): int
    {
        if (GpuValidation::normalize($config['gpu'] ?? null) === null) {
            return 30;
        }

        // never above the region's request timeout (WithinRegionTimeout)
        $max = (int) ($project->knativeRegion?->effective_settings['request_timeout'] ?? 0);
        $gpu = (int) config('ai.gpu.default_timeout', 300);

        return $max > 0 ? min($gpu, $max) : $gpu;
    }

    /**
     * config.gpu and config.scale_schedule of an update in their stored shape ({count: 0} removes
     * the GPU, null or [] turns the timed warmer off).
     */
    private static function withNormalizedGpu(array $config): array
    {
        if (array_key_exists('gpu', $config)) {
            $config['gpu'] = GpuValidation::normalize($config['gpu']);
        }
        if (array_key_exists('scale_schedule', $config)) {
            $config['scale_schedule'] = ScaleSchedule::normalize($config['scale_schedule']);
        }

        return $config;
    }

    /** FaaS handler of a new project: <handler.file>.<handler.function> of its runtime. */
    private function defaultFaasHandler(Project $project): string
    {
        $manifest = $this->runtimeManifest($project->runtime);

        return $manifest ? ProjectEntrypoint::defaultHandlerString($manifest) : 'main.handler';
    }
}
