<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CRUD operations for Knative's config-domain ConfigMap.
 *
 * Format:
 *   data:
 *     "example.com": ""                   # default domain
 *     "tenant.tld": |                     # tenant-specific
 *       selector:
 *         runr/tenant: my-tenant
 */
trait ConfigDomainTrait
{
    protected function getConfigDomains(KubernetesClient $k8s, string $servingNs): array
    {
        try {
            $cm = $k8s->getResourceStatus('v1', 'ConfigMap', 'config-domain', $servingNs);

            return $cm['data'] ?? [];
        } catch (Throwable $e) {
            Log::warning('ConfigDomain: failed to read config-domain', ['error' => $e->getMessage()]);

            return [];
        }
    }

    protected function addConfigDomain(KubernetesClient $k8s, string $servingNs, string $domain, string $selectorYaml = ''): void
    {
        $data = $this->getConfigDomains($k8s, $servingNs);
        $data[$domain] = $selectorYaml;
        $this->applyConfigDomain($k8s, $servingNs, $data);
    }

    protected function removeConfigDomain(KubernetesClient $k8s, string $servingNs, string $domain): void
    {
        $data = $this->getConfigDomains($k8s, $servingNs);

        // Not there (or config-domain unreadable → []): applying would reset every other
        // domain of the region (platform and all tenants) to the example.com placeholder
        if (! array_key_exists($domain, $data)) {
            return;
        }

        unset($data[$domain]);
        $this->applyConfigDomain($k8s, $servingNs, $data);
    }

    protected function replaceConfigDomain(
        KubernetesClient $k8s,
        string $servingNs,
        string $oldDomain,
        string $newDomain,
        string $selectorYaml = '',
    ): void {
        $data = $this->getConfigDomains($k8s, $servingNs);
        $oldValue = $data[$oldDomain] ?? '';
        unset($data[$oldDomain]);
        $data[$newDomain] = $selectorYaml ?: $oldValue;
        $this->applyConfigDomain($k8s, $servingNs, $data);
    }

    private function applyConfigDomain(KubernetesClient $k8s, string $servingNs, array $data): void
    {
        try {
            $k8s->applyResource([
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => [
                    'name' => 'config-domain',
                    'namespace' => $servingNs,
                ],
                'data' => $data ?: ['example.com' => ''],
            ], $servingNs);
        } catch (Throwable $e) {
            Log::error('ConfigDomain: failed to apply config-domain', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
