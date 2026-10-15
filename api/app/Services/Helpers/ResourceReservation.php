<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Contracts\KnativeConstants;

/**
 * The CPU / memory a project reserves — the one formula used by tenant quota admission
 * (TenantQuotaService, CheckTenantQuota), region capacity validation (WithinResourceLimit) and
 * usage reporting (ResourceComputationTrait, ResourceUsageController). Quota model option C
 * (Limits Decisions §4): the configured size × max(1, min_scale) is admitted;
 * max_scale is not — runtime use above that is bounded by the namespace ResourceQuota of the
 * organization ceiling (RegionNamespaceQuotaService).
 *
 * Per trigger type (what KnativeManifestTrait::buildManifests() deploys):
 *  - http (and every other trigger that runs as a Knative Service):
 *        (size + queue-proxy) × max(1, min_scale)
 *  - queue: the same Knative Service reservation + one queue consumer pod
 *  - schedule: size × 1 — a CronJob runs one pod at a time (concurrencyPolicy Forbid) and
 *        has no queue-proxy
 *
 * A config without a size uses the service type's default (InstanceSizes), the same value the
 * manifests fall back to.
 *
 * Pods of a service type the region maps to a Kata RuntimeClass (sandbox_*_runtime = kata-*)
 * also carry that RuntimeClass's overhead.podFixed (KataRuntime::OVERHEAD) — Kubernetes adds it
 * to the pod's requests / limits, the scheduler and the namespace ResourceQuota count it, so
 * RunR reserves it per pod too (Knative Service and CronJob pods; the queue consumer runs runc).
 *
 * Everything here is requests. The queue-proxy's CPU limit above its request
 * (KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M, on every Knative Service pod since 0.4.2) is not
 * reserved: the scheduler and the organization ceiling (requests.cpu) count requests only.
 */
final class ResourceReservation
{
    /** @return array{cpu_m: int, memory_mb: int} */
    public static function zero(): array
    {
        return ['cpu_m' => 0, 'memory_mb' => 0];
    }

    /** Kubernetes CPU quantity ("250m", "0.5", "1") → millicores. */
    public static function parseCpu(mixed $value): int
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return 0;
        }

        $s = trim((string) $value);
        if (str_ends_with($s, 'm')) {
            return (int) rtrim($s, 'm');
        }

        return (int) round((float) $s * 1000);
    }

    /** Instances admitted for a project: max(1, min_scale). */
    public static function instances(mixed $minScale): int
    {
        return max(1, (int) $minScale);
    }

    /** Whether the trigger runs as a Knative Service (every pod carries a queue-proxy). */
    public static function runsKnativeService(?string $triggerType): bool
    {
        return ($triggerType ?: 'http') !== 'schedule';
    }

    /**
     * One pod of a project: the user container, plus the queue-proxy sidecar on Knative
     * Service pods (its requests, KnativeConstants::QUEUE_PROXY_*), plus the
     * pod overhead of its RuntimeClass (podOverhead()).
     *
     * @param  array{cpu_m?: int, memory_mb?: int}  $podOverhead
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function perPod(int $cpuM, int $memoryMb, ?string $triggerType = 'http', array $podOverhead = []): array
    {
        $cpuM += (int) ($podOverhead['cpu_m'] ?? 0);
        $memoryMb += (int) ($podOverhead['memory_mb'] ?? 0);

        if (! self::runsKnativeService($triggerType)) {
            return ['cpu_m' => $cpuM, 'memory_mb' => $memoryMb];
        }

        return [
            'cpu_m' => $cpuM + KnativeConstants::QUEUE_PROXY_CPU_M,
            'memory_mb' => $memoryMb + KnativeConstants::QUEUE_PROXY_MEMORY_MB,
        ];
    }

    /**
     * Per-pod overhead of the RuntimeClass the region runs this service type with (zero for
     * runc and gVisor).
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function podOverhead(?KnativeRegion $region, ?string $serviceType): array
    {
        if (! $region || ! $serviceType) {
            return self::zero();
        }

        return KataRuntime::podOverhead($region->resolveRuntimeClass($serviceType));
    }

    /**
     * Admitted reservation of one project with the given instance size.
     *
     * @param  array{cpu_m?: int, memory_mb?: int}  $podOverhead  RuntimeClass overhead per pod (podOverhead())
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function forSize(int $cpuM, int $memoryMb, ?string $triggerType = 'http', mixed $minScale = 0, array $podOverhead = []): array
    {
        $trigger = $triggerType ?: 'http';
        $pod = self::perPod($cpuM, $memoryMb, $trigger, $podOverhead);
        $instances = $trigger === 'schedule' ? 1 : self::instances($minScale);

        $cpu = $pod['cpu_m'] * $instances;
        $memory = $pod['memory_mb'] * $instances;

        if ($trigger === 'queue') {
            $cpu += KnativeConstants::QUEUE_CONSUMER_CPU_M;
            $memory += KnativeConstants::QUEUE_CONSUMER_MEMORY_MB;
        }

        return ['cpu_m' => $cpu, 'memory_mb' => $memory];
    }

    /**
     * Reservation of a project config — a config model (XaasConfig / FaasConfig /
     * ContainerConfig) or an array / object with cpu, memory_mb, trigger_type, min_scale.
     * $region: the region the project runs on (its sandbox mapping decides the pod overhead).
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function forConfig(object|array|null $config, ?string $serviceType, ?KnativeRegion $region = null): array
    {
        if ($config === null) {
            return self::zero();
        }

        $read = fn (string $key) => is_array($config) ? ($config[$key] ?? null) : ($config->{$key} ?? null);

        $defaults = InstanceSizes::defaultFor($serviceType);
        $cpu = $read('cpu');
        $memory = $read('memory_mb');

        return self::forSize(
            self::parseCpu(($cpu === null || $cpu === '') ? $defaults['cpu'] : $cpu),
            ($memory === null || $memory === '') ? $defaults['memory_mb'] : (int) $memory,
            $read('trigger_type') ?: 'http',
            $read('min_scale') ?? 0,
            self::podOverhead($region, $serviceType),
        );
    }

    /**
     * The config that sizes a project's workload; null for types that deploy nothing
     * (legacy AI types) or a project without config.
     */
    public static function configOf(Project $project): ?object
    {
        return match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'caas' => $project->containerConfig,
            default => null,
        };
    }

    /** @return array{cpu_m: int, memory_mb: int} */
    public static function forProject(Project $project, ?KnativeRegion $region = null): array
    {
        return self::forConfig(self::configOf($project), $project->service_type, $region ?? $project->knativeRegion);
    }

    /**
     * @param  iterable<Project>  $projects
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function sum(iterable $projects): array
    {
        $total = self::zero();
        $regions = [];

        foreach ($projects as $project) {
            // one region lookup per region, not per project
            $regionId = (string) $project->knative_region_id;
            if (! array_key_exists($regionId, $regions)) {
                $regions[$regionId] = $project->knative_region_id ? $project->knativeRegion : null;
            }

            $reservation = self::forProject($project, $regions[$regionId]);
            $total['cpu_m'] += $reservation['cpu_m'];
            $total['memory_mb'] += $reservation['memory_mb'];
        }

        return $total;
    }
}
