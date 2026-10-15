<?php

namespace App\Services\Concerns;

use App\Services\KubernetesClient;

/**
 * High-availability setup for the observability stack.
 *
 * Goals:
 *  - Observability pods are NOT evicted under node memory pressure
 *    (PriorityClass with preemptionPolicy=Never).
 *  - Prometheus and Loki each maintain at least 1 running pod during
 *    cluster maintenance / rolling node upgrades (PodDisruptionBudget).
 *
 * This does NOT make observability multi-replica — that requires distributed
 * storage backends (S3/GCS for Loki, Thanos for Prometheus). These manifests
 * protect single-replica setups from disruption.
 */
trait KnativeObservabilityHATrait
{
    private function applyObservabilityHA(KubernetesClient $k8s, string $ns): void
    {
        // PriorityClass — applied cluster-wide (no namespace)
        $k8s->applyClusterResource($this->observabilityPriorityClass());

        // PodDisruptionBudgets — per namespace
        foreach ($this->observabilityPdbs($ns) as $pdb) {
            $k8s->applyResource($pdb, $ns);
        }
    }

    private function observabilityPriorityClass(): array
    {
        return [
            'apiVersion' => 'scheduling.k8s.io/v1',
            'kind' => 'PriorityClass',
            'metadata' => ['name' => 'observability-critical'],
            // Higher than default user workloads (0), lower than system-critical (2000000000).
            // preemptionPolicy=Never: these pods wait in queue rather than evicting user pods.
            // They are simply not evicted themselves under node pressure.
            'value' => 1_000_000,
            'preemptionPolicy' => 'Never',
            'globalDefault' => false,
            'description' => 'Prevents observability pods from being evicted under node pressure.',
        ];
    }

    private function observabilityPdbs(string $ns): array
    {
        return [
            // Prometheus — operator manages the StatefulSet; PDB ensures
            // at least 1 replica survives node drain / voluntary disruption.
            [
                'apiVersion' => 'policy/v1',
                'kind' => 'PodDisruptionBudget',
                'metadata' => ['name' => 'prometheus-pdb', 'namespace' => $ns],
                'spec' => [
                    'minAvailable' => 1,
                    'selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'prometheus']],
                ],
            ],
            // Loki
            [
                'apiVersion' => 'policy/v1',
                'kind' => 'PodDisruptionBudget',
                'metadata' => ['name' => 'loki-pdb', 'namespace' => $ns],
                'spec' => [
                    'minAvailable' => 1,
                    'selector' => ['matchLabels' => ['app' => 'loki']],
                ],
            ],
        ];
    }
}
