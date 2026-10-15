<?php

namespace App\Services\RegionReconcile\Steps;

use App\Services\Helpers\OrganizationCeiling;
use App\Services\RegionNamespaceQuotaService;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;

/**
 * Keeps the organization ceiling (chart values quota.cpu / quota.memoryGiB) in
 * the project namespace of every existing region: applies the ResourceQuota + LimitRange when a
 * ceiling is configured, removes RunR's own ones when it was cleared. Per region, since each
 * region of a cluster has its own project namespace. Tenant-owned regions are skipped by
 * RegionNamespaceQuotaService itself.
 */
final class NamespaceQuotaStep implements ReconcileStep
{
    public function name(): string
    {
        return 'namespace-quota';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $service = app(RegionNamespaceQuotaService::class);
        $cpuM = OrganizationCeiling::cpuMillicores();
        $memMb = OrganizationCeiling::memoryMb();
        $seen = [];

        foreach ($cluster->targets as $target) {
            $region = $target->region;
            if (empty($region->kubeconfig) || ! empty($region->owner_tenant_id)) {
                continue;
            }

            $namespace = $region->project_namespace ?: $region->name;
            if (isset($seen[$namespace])) {
                continue;
            }
            $seen[$namespace] = true;

            $desired = RegionNamespaceQuotaService::namespaceQuotaManifests($namespace, $cpuM, $memMb);
            if (self::upToDate($cluster, $namespace, $desired)) {
                continue;
            }

            if ($dryRun) {
                $lines[] = $desired
                    ? "would apply the organization ceiling in namespace {$namespace}"
                    : "would remove RunR's organization ceiling from namespace {$namespace}";

                continue;
            }

            $result = $service->applyNamespaceQuota($region, $cluster->k8s);
            if ($result !== RegionNamespaceQuotaService::RESULT_SKIPPED) {
                $lines[] = "{$result} the organization ceiling in namespace {$namespace}";
            }
        }

        return $lines;
    }

    /**
     * True when the namespace already matches: with a ceiling, both objects exist with the same
     * quantities; without one, no RunR-managed object is left.
     *
     * @param  list<array>  $desired
     */
    private static function upToDate(ClusterGroup $cluster, string $namespace, array $desired): bool
    {
        $quota = $cluster->k8s->getResourceStatus('v1', 'ResourceQuota', RegionNamespaceQuotaService::RESOURCE_QUOTA_NAME, $namespace);
        $range = $cluster->k8s->getResourceStatus('v1', 'LimitRange', RegionNamespaceQuotaService::LIMIT_RANGE_NAME, $namespace);

        if ($desired === []) {
            $managed = fn (?array $obj) => ($obj['metadata']['labels']['app.kubernetes.io/managed-by'] ?? null) === 'runr';

            return ! $managed($quota) && ! $managed($range);
        }

        if (! $quota || ! $range) {
            return false;
        }

        [$wantQuota, $wantRange] = $desired;

        return self::sameQuantities($quota['spec']['hard'] ?? [], $wantQuota['spec']['hard'])
            && self::sameQuantities($range['spec']['limits'][0]['default'] ?? [], $wantRange['spec']['limits'][0]['default'])
            && self::sameQuantities($range['spec']['limits'][0]['defaultRequest'] ?? [], $wantRange['spec']['limits'][0]['defaultRequest']);
    }

    /**
     * Compares Kubernetes quantity maps by value (the API server may store "8000m" as "8").
     *
     * @param  array<string, string>  $have
     * @param  array<string, string>  $want
     */
    public static function sameQuantities(array $have, array $want): bool
    {
        if (count($have) !== count($want)) {
            return false;
        }

        foreach ($want as $key => $value) {
            if (! array_key_exists($key, $have) || self::quantity((string) $have[$key]) !== self::quantity((string) $value)) {
                return false;
            }
        }

        return true;
    }

    /** Quantity in milli-units (CPU) or bytes (memory), as a comparable integer. */
    public static function quantity(string $q): int
    {
        $q = trim($q);
        $units = [
            'Ki' => 1024, 'Mi' => 1024 ** 2, 'Gi' => 1024 ** 3, 'Ti' => 1024 ** 4,
            'k' => 1000, 'K' => 1000, 'M' => 1000 ** 2, 'G' => 1000 ** 3, 'T' => 1000 ** 4,
        ];

        foreach ($units as $suffix => $factor) {
            if (str_ends_with($q, $suffix)) {
                return (int) round((float) substr($q, 0, -strlen($suffix)) * $factor);
            }
        }

        if (str_ends_with($q, 'm')) {
            return (int) round((float) substr($q, 0, -1));
        }

        // Plain number: CPU cores or bytes — scale by 1000 so "8" equals "8000m".
        return (int) round((float) $q * 1000);
    }
}
