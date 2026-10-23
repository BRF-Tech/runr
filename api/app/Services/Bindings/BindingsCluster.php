<?php

namespace App\Services\Bindings;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;

/**
 * Cluster access of bindings: one KubernetesClient per region (its own kubeconfig), the data
 * namespace with its base NetworkPolicies, polling.
 */
class BindingsCluster
{
    /** @var array<string, KubernetesClient> */
    private array $clients = [];

    /** @var array<string, true> regions whose data namespace was ensured in this instance */
    private array $ensured = [];

    public static function dataNamespace(): string
    {
        return (string) config('bindings.namespace', 'runr-data');
    }

    /** Namespace of a region's functions (same as KnativeDeployService / SkupperService). */
    public static function projectNamespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?: $region->name;
    }

    /** In-cluster DNS name of a data service. */
    public static function serviceHost(string $service): string
    {
        return $service.'.'.self::dataNamespace().'.svc.cluster.local';
    }

    public function client(KnativeRegion $region): KubernetesClient
    {
        $kubeconfig = $region->kubeconfig;
        if (! $kubeconfig) {
            throw new BindingException(__('api.bindings.region_unavailable', ['region' => $region->name]));
        }

        return $this->clients[$region->getKey()] ??= new KubernetesClient($kubeconfig);
    }

    /**
     * The data namespace and its base policies (default deny, same namespace, CNPG operator).
     */
    public function ensureDataNamespace(KnativeRegion $region): void
    {
        if (isset($this->ensured[$region->getKey()])) {
            return;
        }

        $k8s = $this->client($region);
        $ns = self::dataNamespace();

        $k8s->applyClusterResource(BindingManifests::dataNamespace($ns));
        foreach (BindingManifests::basePolicies($ns, (string) config('bindings.postgres.operator_namespace')) as $policy) {
            if (! $k8s->applyResource($policy, $ns)) {
                throw new BindingException("Could not apply NetworkPolicy {$policy['metadata']['name']} in {$ns}.");
            }
        }

        $this->ensured[$region->getKey()] = true;
    }

    /**
     * Apply manifests in the data namespace; any failure throws.
     *
     * @param  list<array>  $manifests
     */
    public function apply(KnativeRegion $region, array $manifests, ?string $namespace = null): void
    {
        $k8s = $this->client($region);
        $ns = $namespace ?? self::dataNamespace();

        foreach ($manifests as $manifest) {
            if (! $k8s->applyResource($manifest, $ns)) {
                throw new BindingException("Could not apply {$manifest['kind']} {$manifest['metadata']['name']} in {$ns}.");
            }
        }
    }

    /**
     * Poll $check until it returns true or $timeout seconds passed (bindings.poll_interval apart;
     * 0 = three immediate tries, for tests).
     */
    public function wait(callable $check, int $timeout): bool
    {
        $interval = max(0, (int) config('bindings.poll_interval', 3));

        if ($interval === 0) {
            for ($i = 0; $i < 3; $i++) {
                if ($check()) {
                    return true;
                }
            }

            return false;
        }

        $deadline = time() + $timeout;
        do {
            if ($check()) {
                return true;
            }
            sleep($interval);
        } while (time() < $deadline);

        return (bool) $check();
    }

    /**
     * Apply a CloudNativePG Cluster, retried for $timeout seconds (the operator's webhook can answer
     * a moment after its Deployment is ready). A refusal fails with the API server's reason (the
     * webhook's validation message), not only the cluster's name.
     *
     * @throws BindingException
     */
    public function applyPostgresCluster(KubernetesClient $k8s, array $manifest, string $namespace, int $timeout): void
    {
        if ($this->wait(fn () => $k8s->applyResource($manifest, $namespace), $timeout)) {
            return;
        }
        $cluster = (string) ($manifest['metadata']['name'] ?? '');
        $reason = $k8s->lastApplyError();

        throw new BindingException($reason !== null && $reason !== ''
            ? __('api.bindings.postgres_cluster_refused', ['cluster' => $cluster, 'error' => mb_substr($reason, 0, 1000)])
            : __('api.bindings.postgres_cluster_failed', ['cluster' => $cluster]));
    }

    /** Every pod of a list is Running and Ready (false for an empty / failed list). */
    public static function podsReady(?array $pods): bool
    {
        $items = $pods['items'] ?? [];
        if (! $items) {
            return false;
        }

        foreach ($items as $pod) {
            if (($pod['status']['phase'] ?? null) !== 'Running') {
                return false;
            }
            foreach ($pod['status']['conditions'] ?? [] as $condition) {
                if (($condition['type'] ?? null) === 'Ready' && ($condition['status'] ?? null) !== 'True') {
                    return false;
                }
            }
        }

        return true;
    }
}
