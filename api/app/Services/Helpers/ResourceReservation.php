<?php

namespace App\Services\Helpers;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\Contracts\KnativeConstants;

/**
 * The CPU / memory a project reserves — the one formula used by tenant quota admission
 * (TenantQuotaService, CheckTenantQuota), region capacity admission (RegionCapacity,
 * WithinResourceLimit) and usage reporting (ResourceComputationTrait, ResourceUsageController).
 * Quota model option C (Limits Decisions §4): the configured size × max(1, min_scale) is admitted;
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
 * Pods that run under a Kata RuntimeClass also carry that RuntimeClass's overhead.podFixed
 * (KataRuntime::OVERHEAD) — Kubernetes adds it to the pod's requests / limits, the scheduler and
 * the namespace ResourceQuota count it, so RunR reserves it per pod too (Knative Service and
 * CronJob pods; the queue consumer runs runc). The RuntimeClass is the one the project was last
 * deployed with (projects.deployed_runtime_class, written by KnativeDeployService); a project
 * that has not been deployed since that column exists counts with the region's mapping for its
 * service type (sandbox_*_runtime), the class its next deploy gets.
 *
 * Region admission and organization quota admission split the same reservation by budget
 * (demandForSize(), RegionCapacity, TenantQuotaService): what keeps running — always-on pods
 * (scale_to_zero off or min_scale >= 1), the Kata overhead of a scale-to-zero pod, the queue
 * consumer — is "hard" and must fit the region's real capacity (the organization's quota); one pod
 * of a scale-to-zero workload is "burst" and counts against the FaaS overcommit budget of the
 * region (of the quota). hard + burst = forSize().
 *
 * Everything here is requests. The queue-proxy's CPU limit above its request
 * (KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M, on every Knative Service pod since 0.4.2) is not
 * reserved: the scheduler and the organization ceiling (requests.cpu) count requests only.
 */
final class ResourceReservation
{
    /** deployed_runtime_class of a workload deployed without a RuntimeClass (the node's default runtime). */
    public const RUNC = 'runc';

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
     * Whether a workload keeps pods running: a Knative Service with scale to zero off or a
     * min_scale of at least 1. Schedule (CronJob) pods run only while a run lasts.
     */
    public static function isAlwaysOn(?string $triggerType, mixed $minScale, mixed $scaleToZero = true): bool
    {
        if (! self::runsKnativeService($triggerType)) {
            return false;
        }

        return (int) $minScale >= 1 || ! self::truthy($scaleToZero);
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
     * Per-pod overhead of a workload: the RuntimeClass it was deployed with when known
     * ($deployedClass, "runc" for none), else the region's mapping for its service type.
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function overheadFor(?KnativeRegion $region, ?string $serviceType, ?string $deployedClass = null): array
    {
        if ($deployedClass !== null && $deployedClass !== '') {
            return KataRuntime::podOverhead($deployedClass);
        }

        return self::podOverhead($region, $serviceType);
    }

    /** RuntimeClass of a pod spec as RunR records it: its runtimeClassName, "runc" without one. */
    public static function runtimeClassOfPodSpec(array $podSpec): string
    {
        $class = $podSpec['runtimeClassName'] ?? null;

        return is_string($class) && $class !== '' ? $class : self::RUNC;
    }

    /** The RuntimeClass a project was last deployed with ("runc" for none), null when unknown. */
    public static function deployedClassOf(Project $project): ?string
    {
        $class = $project->getAttribute('deployed_runtime_class');

        return is_string($class) && $class !== '' ? $class : null;
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
     * Capacity demand of one workload, split by budget (RegionCapacity):
     *  - hard: always-on pods ((size + queue-proxy + RuntimeClass overhead) × max(1, min_scale)),
     *    the RuntimeClass overhead of one pod of a scale-to-zero workload (Kata), the queue consumer
     *  - burst: one pod of a scale-to-zero workload (size + queue-proxy; CronJob: size)
     * hard + burst = forSize() of the same size, trigger, min_scale and overhead.
     *
     * @param  array{cpu_m?: int, memory_mb?: int}  $podOverhead
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demandForSize(int $cpuM, int $memoryMb, ?string $triggerType = 'http', mixed $minScale = 0, mixed $scaleToZero = true, array $podOverhead = []): array
    {
        $trigger = $triggerType ?: 'http';

        if (self::isAlwaysOn($trigger, $minScale, $scaleToZero)) {
            return ['hard' => self::forSize($cpuM, $memoryMb, $trigger, $minScale, $podOverhead), 'burst' => self::zero()];
        }

        $hard = [
            'cpu_m' => (int) ($podOverhead['cpu_m'] ?? 0),
            'memory_mb' => (int) ($podOverhead['memory_mb'] ?? 0),
        ];
        if ($trigger === 'queue') {
            $hard['cpu_m'] += KnativeConstants::QUEUE_CONSUMER_CPU_M;
            $hard['memory_mb'] += KnativeConstants::QUEUE_CONSUMER_MEMORY_MB;
        }

        return ['hard' => $hard, 'burst' => self::perPod($cpuM, $memoryMb, $trigger)];
    }

    /**
     * Demand of a project config (see forConfig() for $config), split by budget.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demandForConfig(object|array|null $config, ?string $serviceType, ?KnativeRegion $region = null, ?string $deployedClass = null): array
    {
        if ($config === null) {
            return self::noDemand();
        }

        $read = fn (string $key) => is_array($config) ? ($config[$key] ?? null) : ($config->{$key} ?? null);

        $defaults = InstanceSizes::defaultFor($serviceType);
        $cpu = $read('cpu');
        $memory = $read('memory_mb');

        return self::demandForSize(
            self::parseCpu(($cpu === null || $cpu === '') ? $defaults['cpu'] : $cpu),
            ($memory === null || $memory === '') ? $defaults['memory_mb'] : (int) $memory,
            $read('trigger_type') ?: 'http',
            $read('min_scale') ?? 0,
            $read('scale_to_zero') ?? true,
            self::overheadFor($region, $serviceType, $deployedClass),
        );
    }

    /**
     * Demand of a project: its config, with the RuntimeClass it was deployed with.
     *
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function demandForProject(Project $project, ?KnativeRegion $region = null): array
    {
        return self::demandForConfig(self::configOf($project), $project->service_type, $region ?? $project->knativeRegion, self::deployedClassOf($project));
    }

    /**
     * @param  iterable<Project>  $projects
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function sumDemand(iterable $projects): array
    {
        $total = self::noDemand();
        $regions = [];

        foreach ($projects as $project) {
            // one region lookup per region, not per project
            $regionId = (string) $project->knative_region_id;
            if (! array_key_exists($regionId, $regions)) {
                $regions[$regionId] = $project->knative_region_id ? $project->knativeRegion : null;
            }

            $total = self::addDemand($total, self::demandForProject($project, $regions[$regionId]));
        }

        return $total;
    }

    /** @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}} */
    public static function noDemand(): array
    {
        return ['hard' => self::zero(), 'burst' => self::zero()];
    }

    /**
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $a
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $b
     * @return array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}
     */
    public static function addDemand(array $a, array $b): array
    {
        return ['hard' => self::add($a['hard'], $b['hard']), 'burst' => self::add($a['burst'], $b['burst'])];
    }

    /**
     * hard + burst of a demand: the whole admitted reservation.
     *
     * @param  array{hard: array{cpu_m: int, memory_mb: int}, burst: array{cpu_m: int, memory_mb: int}}  $demand
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function total(array $demand): array
    {
        return self::add($demand['hard'], $demand['burst']);
    }

    /**
     * @param  array{cpu_m: int, memory_mb: int}  $a
     * @param  array{cpu_m: int, memory_mb: int}  $b
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function add(array $a, array $b): array
    {
        return ['cpu_m' => $a['cpu_m'] + $b['cpu_m'], 'memory_mb' => $a['memory_mb'] + $b['memory_mb']];
    }

    /**
     * Reservation of a project config — a config model (XaasConfig / FaasConfig /
     * ContainerConfig) or an array / object with cpu, memory_mb, trigger_type, min_scale,
     * scale_to_zero. $region: the region the project runs on (its sandbox mapping decides the pod
     * overhead unless $deployedClass, the RuntimeClass the project was deployed with, is known).
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function forConfig(object|array|null $config, ?string $serviceType, ?KnativeRegion $region = null, ?string $deployedClass = null): array
    {
        return self::total(self::demandForConfig($config, $serviceType, $region, $deployedClass));
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
        return self::total(self::demandForProject($project, $region));
    }

    /**
     * @param  iterable<Project>  $projects
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function sum(iterable $projects): array
    {
        return self::total(self::sumDemand($projects));
    }

    /** A boolean setting that may arrive as "0" / "false" / 0 (requests, JSON, DB); null is the column default (true). */
    private static function truthy(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
