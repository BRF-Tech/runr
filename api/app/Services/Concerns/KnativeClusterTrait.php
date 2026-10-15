<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use Throwable;

trait KnativeClusterTrait
{
    /**
     * Ensure a namespace exists.
     */
    private function ensureNamespace(KubernetesClient $k8s, string $name): void
    {
        static $ensured = [];

        if (isset($ensured[$name])) {
            return;
        }

        $k8s->applyClusterResource([
            'apiVersion' => 'v1',
            'kind' => 'Namespace',
            'metadata' => [
                'name' => $name,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
        ]);

        $ensured[$name] = true;
    }

    /**
     * Delete a namespace only if it carries the runr managed-by label.
     */
    private function deleteNamespaceIfManaged(KubernetesClient $k8s, string $namespace): void
    {
        try {
            $ns = $k8s->getClusterResource('v1', 'Namespace', $namespace);
            if (! $ns) {
                return;
            }

            $labels = $ns['metadata']['labels'] ?? [];
            if (($labels['app.kubernetes.io/managed-by'] ?? '') !== 'runr') {
                Log::debug('Cleanup: Skipping namespace not managed by runr', ['namespace' => $namespace]);

                return;
            }

            $k8s->deleteClusterResource('v1', 'Namespace', $namespace);
        } catch (Throwable $e) {
            Log::debug('Cleanup: Namespace removal skipped', ['namespace' => $namespace, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Check if a Knative CR (KnativeServing) has Ready condition True.
     */
    private function isCrReady(KubernetesClient $k8s, string $kind, string $name, string $namespace): bool
    {
        $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', $kind, $name, $namespace);
        if (! $cr) {
            return false;
        }

        foreach ($cr['status']['conditions'] ?? [] as $c) {
            if ($c['type'] === 'Ready') {
                return $c['status'] === 'True';
            }
        }

        return false;
    }

    /**
     * Poll until a condition is met or timeout is reached.
     */
    private function waitFor(callable $condition, int $timeoutSeconds, int $intervalSeconds = 3): bool
    {
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            try {
                if ($condition()) {
                    return true;
                }
            } catch (Throwable) {
                // Condition check failed, keep trying
            }
            sleep($intervalSeconds);
        }

        return false;
    }

    /**
     * Discover and store external IP from Traefik LoadBalancer service.
     */
    private function discoverExternalIp(KubernetesClient $k8s, KnativeRegion $region): void
    {
        $ns = $region->knative_serving_namespace ?? 'knative-serving';

        try {
            $svc = $k8s->getResourceStatus('v1', 'Service', 'traefik', $ns);
            $ip = $this->extractLoadBalancerIp($svc);

            if ($ip) {
                $region->update(['external_ip' => $ip]);
                $this->appendLog($region, 'Discovered external IP: '.$ip);
            } else {
                $this->appendLog($region, 'Warning: Could not discover external IP from Traefik service.');
            }
        } catch (Throwable $e) {
            Log::warning('Failed to discover external IP', ['error' => $e->getMessage()]);
            $this->appendLog($region, 'Warning: External IP discovery failed: '.$e->getMessage());
        }
    }

    /**
     * Extract IP or hostname from a LoadBalancer Service resource.
     */
    private function extractLoadBalancerIp(?array $svc): ?string
    {
        if (! $svc) {
            return null;
        }
        $ingress = $svc['status']['loadBalancer']['ingress'][0] ?? null;

        return $ingress['ip'] ?? $ingress['hostname'] ?? null;
    }

    /**
     * Build a status result array.
     */
    private function statusResult(
        string $status,
        string $message,
        array $details = [],
        string $clusterStatus = 'online',
        ?string $clusterMessage = null,
        array $components = [],
    ): array {
        return compact('status', 'message', 'details', 'clusterStatus', 'clusterMessage', 'components');
    }
}
