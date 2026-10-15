<?php

namespace App\Services\Concerns;

/**
 * Pure builders for the organization ceiling in a Region's project namespace:
 *
 *  - ResourceQuota "runr-organization-ceiling": requests.cpu = CPU ceiling, requests.memory /
 *    limits.memory = memory ceiling — the hard runtime cap of everything RunR runs in that
 *    namespace (project pods, queue consumers, build Jobs, Redis). CPU is capped on what is
 *    reserved (requests), not on limits: every project pod's queue-proxy may burst to
 *    KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M above its 100m request (0.4.2), which a limits.cpu
 *    ceiling would count as if it were reserved — 1 CPU per running instance, so instances RunR
 *    admitted by their requests would stay pending (RunR 0.4.1 and earlier listed limits.cpu too;
 *    region:reconcile drops it). A burst only uses CPU the node has idle; under contention every
 *    pod gets its share by request. Memory keeps requests == limits.
 *  - LimitRange "runr-container-defaults": default requests / limits for containers that set
 *    none. Once a ResourceQuota covers a resource, Kubernetes rejects pods whose containers
 *    lack a request (CPU) / a request and limit (memory) for it. Every workload RunR creates
 *    there sets its requests and limits itself (user container, queue consumer, build Jobs,
 *    Redis: request == limit; queue-proxy: CPU request 100m, limit 1 CPU, memory request ==
 *    limit) — the LimitRange only fills in containers that set none, and it has no max, so the
 *    queue-proxy's CPU limit is accepted even above a small ceiling. Its defaults are clamped to
 *    the ceiling.
 *
 * Only the resources that have a ceiling are listed. Both objects carry
 * app.kubernetes.io/managed-by: runr, which is how RunR recognises the ones it may remove.
 */
trait NamespaceQuotaTrait
{
    public const RESOURCE_QUOTA_NAME = 'runr-organization-ceiling';

    public const LIMIT_RANGE_NAME = 'runr-container-defaults';

    /** LimitRange defaults (before clamping to the ceiling). */
    public const LIMIT_RANGE_DEFAULT_REQUEST_CPU_M = 100;

    public const LIMIT_RANGE_DEFAULT_REQUEST_MEMORY_MB = 128;

    public const LIMIT_RANGE_DEFAULT_LIMIT_CPU_M = 500;

    public const LIMIT_RANGE_DEFAULT_LIMIT_MEMORY_MB = 512;

    /**
     * Manifests for a ceiling; [] when neither resource has one.
     *
     * @return list<array>
     */
    public static function namespaceQuotaManifests(string $namespace, ?int $cpuM, ?int $memoryMb): array
    {
        $cpuM = ($cpuM ?? 0) > 0 ? $cpuM : null;
        $memoryMb = ($memoryMb ?? 0) > 0 ? $memoryMb : null;

        if ($cpuM === null && $memoryMb === null) {
            return [];
        }

        return [
            self::resourceQuotaManifest($namespace, $cpuM, $memoryMb),
            self::limitRangeManifest($namespace, $cpuM, $memoryMb),
        ];
    }

    public static function resourceQuotaManifest(string $namespace, ?int $cpuM, ?int $memoryMb): array
    {
        $hard = [];

        if ($cpuM !== null) {
            $hard['requests.cpu'] = $cpuM.'m';
        }

        if ($memoryMb !== null) {
            $hard['requests.memory'] = $memoryMb.'Mi';
            $hard['limits.memory'] = $memoryMb.'Mi';
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'ResourceQuota',
            'metadata' => self::namespaceQuotaMetadata(self::RESOURCE_QUOTA_NAME, $namespace),
            'spec' => ['hard' => $hard],
        ];
    }

    public static function limitRangeManifest(string $namespace, ?int $cpuM, ?int $memoryMb): array
    {
        $defaultRequest = [];
        $defaultLimit = [];

        if ($cpuM !== null) {
            $defaultRequest['cpu'] = min(self::LIMIT_RANGE_DEFAULT_REQUEST_CPU_M, $cpuM).'m';
            $defaultLimit['cpu'] = min(self::LIMIT_RANGE_DEFAULT_LIMIT_CPU_M, $cpuM).'m';
        }

        if ($memoryMb !== null) {
            $defaultRequest['memory'] = min(self::LIMIT_RANGE_DEFAULT_REQUEST_MEMORY_MB, $memoryMb).'Mi';
            $defaultLimit['memory'] = min(self::LIMIT_RANGE_DEFAULT_LIMIT_MEMORY_MB, $memoryMb).'Mi';
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'LimitRange',
            'metadata' => self::namespaceQuotaMetadata(self::LIMIT_RANGE_NAME, $namespace),
            'spec' => [
                'limits' => [[
                    'type' => 'Container',
                    'defaultRequest' => $defaultRequest,
                    'default' => $defaultLimit,
                ]],
            ],
        ];
    }

    private static function namespaceQuotaMetadata(string $name, string $namespace): array
    {
        return [
            'name' => $name,
            'namespace' => $namespace,
            'labels' => [
                'app.kubernetes.io/managed-by' => 'runr',
                'app.kubernetes.io/component' => 'organization-ceiling',
            ],
        ];
    }
}
