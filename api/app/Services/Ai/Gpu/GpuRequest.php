<?php

namespace App\Services\Ai\Gpu;

use App\Models\AiModelService;
use App\Models\ExecutorImage;
use App\Models\Project;
use App\Services\Runtimes\RuntimeRegistry;

/**
 * The GPUs a workload asks for: `count` units of the extended resource (nvidia.com/gpu), on a
 * node whose GPU is `type` (GPU Feature Discovery label nvidia.com/gpu.product) and has at least
 * `memory_min` MiB (nvidia.com/gpu.memory). Projects store it as config.gpu, model services as
 * gpu (device gpu).
 */
final class GpuRequest
{
    public function __construct(
        public readonly int $count,
        public readonly ?string $type = null,
        public readonly ?int $memoryMin = null,
        public readonly string $resource = 'nvidia.com/gpu',
    ) {}

    /** From a stored / requested {count, type, memory_min}; null without a positive count. */
    public static function fromArray(mixed $gpu, string $resource = 'nvidia.com/gpu'): ?self
    {
        if (! is_array($gpu)) {
            return null;
        }

        $count = (int) ($gpu['count'] ?? 0);
        if ($count < 1) {
            return null;
        }

        $type = isset($gpu['type']) && is_string($gpu['type']) && trim($gpu['type']) !== '' ? trim($gpu['type']) : null;
        $memory = isset($gpu['memory_min']) && is_numeric($gpu['memory_min']) && (int) $gpu['memory_min'] > 0 ? (int) $gpu['memory_min'] : null;

        return new self($count, $type, $memory, (string) ($gpu['resource'] ?? $resource));
    }

    /**
     * GPU request of a project: config.gpu; a code project on a GPU runtime version (or a runtime
     * whose manifest sets resources.gpu) without config.gpu asks for the runtime's count (1).
     */
    public static function forProject(Project $project, ?object $config = null): ?self
    {
        $config ??= match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
            default => null,
        };

        $request = self::fromArray($config->gpu ?? null);
        if ($request || $project->service_type === 'caas') {
            return $request;
        }

        $runtimeGpu = self::runtimeGpu($project->runtime, $project->runtime_version);

        return $runtimeGpu ? new self($runtimeGpu['count'], null, null, $runtimeGpu['resource']) : null;
    }

    /** GPU request of a model service on device gpu (1 GPU when none is stored). */
    public static function forService(AiModelService $service): ?self
    {
        if (! $service->usesGpu()) {
            return null;
        }

        return self::fromArray($service->gpu ?? []) ?? new self(1);
    }

    /**
     * GPU of a runtime version: the manifest's resources.gpu, else {count 1} for a version
     * flagged gpu (executor_images.gpu_required, e.g. pytorch 2.3-gpu). Null for CPU versions.
     *
     * @return array{count: int, resource: string}|null
     */
    public static function runtimeGpu(?string $runtime, ?string $version): ?array
    {
        if (! $runtime) {
            return null;
        }

        $manifest = app(RuntimeRegistry::class)->manifest($runtime);
        if ($manifest && ($gpu = $manifest->gpu())) {
            return ['count' => (int) $gpu['count'], 'resource' => (string) ($gpu['resource'] ?? 'nvidia.com/gpu')];
        }

        $executor = ExecutorImage::resolve($runtime, $version);

        return $executor && $executor->gpu_required ? ['count' => 1, 'resource' => 'nvidia.com/gpu'] : null;
    }

    /** Stored shape. */
    public function toArray(): array
    {
        return array_filter([
            'count' => $this->count,
            'type' => $this->type,
            'memory_min' => $this->memoryMin,
        ], fn ($v) => $v !== null);
    }
}
