<?php

namespace App\Services\Sandboxes;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Cache;

/**
 * The sandbox namespace of an organization in a region: created on first use with its
 * Pod Security Admission labels, the default-deny NetworkPolicy and — when the organization's
 * concurrency is limited — the pod-count ResourceQuota. Re-applied at most every ten minutes per
 * region × namespace × quota (cached), so a deleted policy comes back.
 */
final class SandboxNamespaces
{
    private const CACHE_SECONDS = 600;

    public function __construct(private SandboxQuotaService $quotas) {}

    public function nameFor(?string $tenantId): string
    {
        return SandboxManifests::namespaceName(SandboxContext::tenantSlug($tenantId));
    }

    /** @throws SandboxException cluster_error */
    public function ensure(KubernetesClient $k8s, KnativeRegion $region, string $namespace, ?string $tenantId, bool $force = false): void
    {
        $limits = $this->quotas->limits($tenantId);
        $pods = $limits['max_concurrent'] > 0 ? $limits['max_concurrent'] + max(0, $limits['max_pool_size']) : 0;
        $key = 'sandbox-ns:'.$region->id.':'.$namespace.':'.$pods;

        if (! $force && Cache::get($key)) {
            return;
        }

        try {
            $k8s->applyClusterResource(SandboxManifests::namespace($namespace, $tenantId));
        } catch (\Throwable $e) {
            throw SandboxException::cluster(__('sandboxes.namespace_failed', ['namespace' => $namespace]).' '.$e->getMessage());
        }
        if (! $k8s->applyResource(SandboxManifests::defaultDenyPolicy($namespace), $namespace)) {
            throw SandboxException::cluster(__('sandboxes.policy_failed', ['namespace' => $namespace]));
        }

        if ($pods > 0) {
            $k8s->applyResource(SandboxManifests::resourceQuota($namespace, $pods), $namespace);
        } else {
            $k8s->deleteResource('v1', 'ResourceQuota', SandboxManifests::RESOURCE_QUOTA, $namespace);
        }

        Cache::put($key, true, self::CACHE_SECONDS);
    }
}
