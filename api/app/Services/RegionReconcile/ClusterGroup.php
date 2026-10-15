<?php

namespace App\Services\RegionReconcile;

use App\Services\KubernetesClient;

/**
 * The regions that live on one Kubernetes cluster. Sibling regions on the same cluster share the
 * cluster-wide objects (KnativeServing CR, observability namespace, runr-agent), so cluster
 * steps run once per group, through the first region's kubeconfig.
 */
final class ClusterGroup
{
    /**
     * @param  list<ReconcileTarget>  $targets
     */
    public function __construct(
        public readonly string $key,
        public readonly array $targets,
        public readonly KubernetesClient $k8s,
    ) {}

    public function primary(): ReconcileTarget
    {
        return $this->targets[0];
    }

    public function find(string $regionId): ?ReconcileTarget
    {
        foreach ($this->targets as $target) {
            if ($target->region->id === $regionId) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Distinct Knative Serving namespaces of the regions in this group (normally one).
     *
     * @return list<string>
     */
    public function servingNamespaces(): array
    {
        $namespaces = array_map(
            fn (ReconcileTarget $t) => $t->region->knative_serving_namespace ?: 'knative-serving',
            $this->targets,
        );

        return array_values(array_unique($namespaces));
    }

    public function label(): string
    {
        return implode(', ', array_map(fn (ReconcileTarget $t) => $t->label(), $this->targets));
    }
}
