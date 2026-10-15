<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Concerns\NamespaceQuotaTrait;
use App\Services\Helpers\OrganizationCeiling;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Enforces the organization CPU / RAM ceiling (config/quota.php, chart values quota.cpu /
 * quota.memoryGiB) in a Region's project namespace, on the Region's own
 * cluster through its kubeconfig.
 *
 * applyNamespaceQuota() is idempotent and safe to call at any time: KnativeInstallService
 * calls it when a Region's Serving is installed; an upgrade-time reconcile can call it for
 * every Region to follow a changed ceiling.
 */
class RegionNamespaceQuotaService
{
    use NamespaceQuotaTrait;

    public const RESULT_APPLIED = 'applied';

    public const RESULT_REMOVED = 'removed';

    public const RESULT_SKIPPED = 'skipped';

    /**
     * With a ceiling: server-side applies the ResourceQuota + LimitRange (NamespaceQuotaTrait).
     * Without one: deletes a RunR-managed ResourceQuota / LimitRange of that name if present.
     * Regions without a kubeconfig (demo) and tenant-owned Regions (a tenant's own cluster,
     * outside this installation's ceiling) are skipped.
     *
     * @return string applied | removed | skipped
     *
     * @throws RuntimeException when the cluster refuses to apply the ceiling
     */
    public function applyNamespaceQuota(KnativeRegion $region, ?KubernetesClient $k8s = null): string
    {
        if (empty($region->kubeconfig) || ! empty($region->owner_tenant_id)) {
            return self::RESULT_SKIPPED;
        }

        $namespace = $region->project_namespace ?: $region->name;
        $k8s ??= new KubernetesClient($region->kubeconfig);

        $manifests = self::namespaceQuotaManifests(
            $namespace,
            OrganizationCeiling::cpuMillicores(),
            OrganizationCeiling::memoryMb(),
        );

        if ($manifests === []) {
            return $this->removeManaged($k8s, $namespace) ? self::RESULT_REMOVED : self::RESULT_SKIPPED;
        }

        foreach ($manifests as $manifest) {
            if (! $k8s->applyResource($manifest, $namespace)) {
                throw new RuntimeException("Failed to apply {$manifest['kind']} {$manifest['metadata']['name']} in namespace {$namespace}");
            }
        }

        Log::info('Organization ceiling applied', [
            'region' => $region->name,
            'namespace' => $namespace,
            'hard' => $manifests[0]['spec']['hard'],
        ]);

        return self::RESULT_APPLIED;
    }

    /** Deletes the RunR-managed objects; true when at least one was removed. */
    private function removeManaged(KubernetesClient $k8s, string $namespace): bool
    {
        $removed = false;

        foreach (['ResourceQuota' => self::RESOURCE_QUOTA_NAME, 'LimitRange' => self::LIMIT_RANGE_NAME] as $kind => $name) {
            $existing = $k8s->getResourceStatus('v1', $kind, $name, $namespace);
            if (($existing['metadata']['labels']['app.kubernetes.io/managed-by'] ?? null) !== 'runr') {
                continue;
            }

            if ($k8s->deleteResource('v1', $kind, $name, $namespace)) {
                $removed = true;
            }
        }

        return $removed;
    }
}
