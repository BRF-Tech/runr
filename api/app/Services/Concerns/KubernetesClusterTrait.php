<?php

namespace App\Services\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Cluster-scoped Kubernetes resource operations.
 *
 * Expects the consuming class to provide:
 *   - request(): PendingRequest
 *   - clusterResourceUrl(apiVersion, kind, name): string
 *   - clusterCollectionUrl(apiVersion, kind): string
 */
trait KubernetesClusterTrait
{
    /**
     * List cluster-scoped resources (Node, Namespace, CRD, etc.).
     */
    public function listClusterResources(string $apiVersion, string $kind, array $labelSelector = []): ?array
    {
        $url = $this->clusterCollectionUrl($apiVersion, $kind);

        if (! empty($labelSelector)) {
            $parts = [];
            foreach ($labelSelector as $k => $v) {
                $parts[] = "{$k}={$v}";
            }
            $url .= '?labelSelector='.urlencode(implode(',', $parts));
        }

        $response = $this->request()->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        Log::error('K8s list cluster resources failed', [
            'kind' => $kind,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return null;
    }

    /**
     * Pods of every namespace that still hold node resources: phase neither Succeeded nor
     * Failed (the scheduler's view of what is requested on a node). Null when the list fails.
     *
     * @return list<array>|null
     */
    public function listActivePods(): ?array
    {
        $url = $this->clusterCollectionUrl('v1', 'Pod')
            .'?fieldSelector='.urlencode('status.phase!=Succeeded,status.phase!=Failed');

        $response = $this->request()->get($url);

        if ($response->successful()) {
            return $response->json('items') ?? [];
        }

        Log::warning('K8s list pods failed', [
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 500),
        ]);

        return null;
    }

    /**
     * Get a single cluster-scoped resource.
     */
    public function getClusterResource(string $apiVersion, string $kind, string $name): ?array
    {
        $url = $this->clusterResourceUrl($apiVersion, $kind, $name);

        $response = $this->request()->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Apply a cluster-scoped resource (server-side apply).
     */
    public function applyClusterResource(array $manifest): bool
    {
        $kind = $manifest['kind'];
        $apiVersion = $manifest['apiVersion'];
        $name = $manifest['metadata']['name'];

        $url = $this->clusterResourceUrl($apiVersion, $kind, $name);

        // Use JSON_UNESCAPED_SLASHES to avoid \/ which breaks K8s YAML parser
        $jsonBody = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->request()
            ->withBody($jsonBody, 'application/apply-patch+yaml')
            ->patch($url.'?fieldManager=runr&force=true');

        if ($response->successful()) {
            return true;
        }

        if ($response->status() === 404) {
            return $this->createClusterResource($manifest);
        }

        $body = mb_substr($response->body(), 0, 300);
        Log::error('K8s apply cluster resource failed', [
            'kind' => $kind,
            'name' => $name,
            'status' => $response->status(),
            'body' => $body,
        ]);

        throw new \RuntimeException("HTTP {$response->status()} on {$kind}/{$name}: {$body}");
    }

    /**
     * Create a cluster-scoped resource.
     */
    public function createClusterResource(array $manifest): bool
    {
        $kind = $manifest['kind'];
        $apiVersion = $manifest['apiVersion'];
        $name = $manifest['metadata']['name'] ?? 'unknown';

        $url = $this->clusterCollectionUrl($apiVersion, $kind);

        $response = $this->request()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $manifest);

        if ($response->successful() || $response->status() === 409) {
            return true;
        }

        $body = mb_substr($response->body(), 0, 300);
        Log::error('K8s create cluster resource failed', [
            'kind' => $kind,
            'name' => $name,
            'status' => $response->status(),
            'body' => $body,
        ]);

        throw new \RuntimeException("HTTP {$response->status()} on {$kind}/{$name}: {$body}");
    }

    /**
     * Delete a cluster-scoped resource.
     */
    public function deleteClusterResource(string $apiVersion, string $kind, string $name): bool
    {
        $url = $this->clusterResourceUrl($apiVersion, $kind, $name);

        $response = $this->request()->delete($url);

        if ($response->successful() || $response->status() === 404) {
            return true;
        }

        Log::error('K8s delete cluster resource failed', [
            'kind' => $kind,
            'name' => $name,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }

    /**
     * Cordon a node (mark unschedulable).
     */
    public function cordonNode(string $name): bool
    {
        return $this->strategicMergePatchCluster('v1', 'Node', $name, [
            'spec' => ['unschedulable' => true],
        ]);
    }

    /**
     * Uncordon a node (mark schedulable).
     */
    public function uncordonNode(string $name): bool
    {
        return $this->strategicMergePatchCluster('v1', 'Node', $name, [
            'spec' => ['unschedulable' => false],
        ]);
    }

    /**
     * Drain a node by evicting all non-DaemonSet pods.
     */
    public function drainNode(string $name): void
    {
        $url = "{$this->server}/api/v1/pods?fieldSelector=".urlencode("spec.nodeName={$name}");
        $response = $this->request()->get($url);

        if (! $response->successful()) {
            return;
        }

        foreach (($response->json()['items'] ?? []) as $pod) {
            $ns = $pod['metadata']['namespace'];
            $podName = $pod['metadata']['name'];

            // Skip DaemonSet-managed pods and mirror pods
            $isDaemonSet = false;
            foreach ($pod['metadata']['ownerReferences'] ?? [] as $ref) {
                if ($ref['kind'] === 'DaemonSet') {
                    $isDaemonSet = true;
                    break;
                }
            }
            if ($isDaemonSet || isset($pod['metadata']['annotations']['kubernetes.io/config.mirror'])) {
                continue;
            }

            try {
                $evictionUrl = "{$this->server}/api/v1/namespaces/{$ns}/pods/{$podName}/eviction";
                $this->request()->post($evictionUrl, [
                    'apiVersion' => 'policy/v1',
                    'kind' => 'Eviction',
                    'metadata' => ['name' => $podName, 'namespace' => $ns],
                ]);
            } catch (\Throwable) {
                // Best-effort
            }
        }
    }

    /**
     * Strategic merge patch for a cluster-scoped resource (Node taint/label etc.).
     */
    public function strategicMergePatchCluster(string $apiVersion, string $kind, string $name, array $patch): bool
    {
        $url = $this->clusterResourceUrl($apiVersion, $kind, $name);

        $response = $this->request()
            ->withHeaders(['Content-Type' => 'application/strategic-merge-patch+json'])
            ->patch($url, $patch);

        if ($response->successful()) {
            return true;
        }

        Log::error('K8s strategic merge patch cluster failed', [
            'kind' => $kind,
            'name' => $name,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }
}
