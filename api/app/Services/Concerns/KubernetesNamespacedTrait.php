<?php

namespace App\Services\Concerns;

use App\Services\Helpers\KataRuntime;
use Illuminate\Support\Facades\Log;

/**
 * Namespaced Kubernetes resource operations.
 *
 * Every workload written here gets the guest sizing annotations a Kata runtime-rs hypervisor needs
 * (KataRuntime::withGuestSizing(); a no-op for anything else).
 *
 * Expects the consuming class to provide:
 *   - request(): PendingRequest
 *   - resourceUrl(apiVersion, kind, namespace, name): string
 *   - collectionUrl(apiVersion, kind, namespace): string
 */
trait KubernetesNamespacedTrait
{
    /**
     * Apply (create or update) a Kubernetes resource.
     * Uses server-side apply (PATCH with application/apply-patch+yaml).
     */
    public function applyResource(array $manifest, string $namespace): bool
    {
        $manifest = KataRuntime::withGuestSizing($manifest);
        $kind = $manifest['kind'];
        $apiVersion = $manifest['apiVersion'];
        $name = $manifest['metadata']['name'];

        $url = $this->resourceUrl($apiVersion, $kind, $namespace, $name);

        // Server-side apply: PATCH with fieldManager
        // Use JSON_UNESCAPED_SLASHES to avoid \/ which breaks K8s YAML parser
        $jsonBody = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $this->request()
            ->withBody($jsonBody, 'application/apply-patch+yaml')
            ->patch($url.'?fieldManager=runr&force=true');

        if ($response->successful()) {
            return true;
        }

        // 404 = resource type might not exist yet or wrong URL, try CREATE
        if ($response->status() === 404) {
            return $this->createResource($manifest, $namespace);
        }

        Log::error('K8s apply failed', [
            'kind' => $kind,
            'name' => $name,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return false;
    }

    /**
     * Create a Kubernetes resource.
     */
    public function createResource(array $manifest, string $namespace): bool
    {
        $manifest = KataRuntime::withGuestSizing($manifest);
        $kind = $manifest['kind'];
        $apiVersion = $manifest['apiVersion'];

        $url = $this->collectionUrl($apiVersion, $kind, $namespace);

        $response = $this->request()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $manifest);

        if ($response->successful()) {
            return true;
        }

        // 409 Conflict = already exists → try update
        if ($response->status() === 409) {
            $name = $manifest['metadata']['name'];
            $putUrl = $this->resourceUrl($apiVersion, $kind, $namespace, $name);
            $putResponse = $this->request()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put($putUrl, $manifest);

            if ($putResponse->successful()) {
                return true;
            }

            Log::error('K8s update failed after conflict', [
                'kind' => $kind,
                'name' => $manifest['metadata']['name'],
                'status' => $putResponse->status(),
                'response' => $putResponse->body(),
            ]);

            return false;
        }

        Log::error('K8s create failed', [
            'kind' => $kind,
            'name' => $manifest['metadata']['name'] ?? 'unknown',
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return false;
    }

    /**
     * Delete a Kubernetes resource. $propagationPolicy "Background" also removes dependents
     * (a plain DELETE of a batch/v1 Job or CronJob orphans its pods/Jobs).
     */
    public function deleteResource(string $apiVersion, string $kind, string $name, string $namespace, ?string $propagationPolicy = null): bool
    {
        $url = $this->resourceUrl($apiVersion, $kind, $namespace, $name);

        $response = $propagationPolicy
            ? $this->request()->delete($url, ['kind' => 'DeleteOptions', 'apiVersion' => 'v1', 'propagationPolicy' => $propagationPolicy])
            : $this->request()->delete($url);

        if ($response->successful() || $response->status() === 404) {
            return true;
        }

        Log::error('K8s delete failed', [
            'kind' => $kind,
            'name' => $name,
            'status' => $response->status(),
            'response' => $response->body(),
        ]);

        return false;
    }

    /**
     * Get the status of a Kubernetes resource.
     */
    public function getResourceStatus(string $apiVersion, string $kind, string $name, string $namespace): ?array
    {
        $url = $this->resourceUrl($apiVersion, $kind, $namespace, $name);

        $response = $this->request()->get($url);

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * List namespaced resources with optional label selector.
     */
    public function listResources(string $apiVersion, string $kind, string $namespace, array $labelSelector = []): ?array
    {
        $response = $this->request()->get($this->selectedCollectionUrl($apiVersion, $kind, $namespace, $labelSelector));

        if ($response->successful()) {
            return $response->json();
        }

        return null;
    }

    /**
     * Events of one object (fieldSelector on involvedObject), in the API's order. Events are
     * diagnostics only: a failed request is [].
     *
     * @return list<array>
     */
    public function listEvents(string $namespace, string $kind, string $name): array
    {
        $url = $this->collectionUrl('v1', 'Event', $namespace)
            .'?fieldSelector='.urlencode("involvedObject.kind={$kind},involvedObject.name={$name}");

        $response = $this->request()->get($url);

        return $response->successful() ? array_values((array) ($response->json('items') ?? [])) : [];
    }

    /**
     * Items of a namespaced collection matching the label selector. A 404 (resource type not
     * installed, e.g. no Knative CRDs, or namespace gone) means there is nothing → []; any other
     * failure throws, so a caller that must be sure nothing is left can tell "none" from "unknown".
     *
     * @return list<array>
     */
    public function listResourcesOrFail(string $apiVersion, string $kind, string $namespace, array $labelSelector = []): array
    {
        $response = $this->request()->get($this->selectedCollectionUrl($apiVersion, $kind, $namespace, $labelSelector));

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Listing {$kind} in {$namespace} failed (HTTP {$response->status()})");
        }

        return $response->json('items') ?? [];
    }

    /** Namespaced collection URL with an optional ?labelSelector=k=v,… */
    private function selectedCollectionUrl(string $apiVersion, string $kind, string $namespace, array $labelSelector): string
    {
        $url = $this->collectionUrl($apiVersion, $kind, $namespace);

        if (! empty($labelSelector)) {
            $parts = [];
            foreach ($labelSelector as $k => $v) {
                $parts[] = "{$k}={$v}";
            }
            $url .= '?labelSelector='.urlencode(implode(',', $parts));
        }

        return $url;
    }

    public function getPodLogs(string $name, string $namespace, ?string $container = null, int $tailLines = 500): ?string
    {
        $url = $this->resourceUrl('v1', 'Pod', $namespace, $name).'/log';
        $params = ['tailLines' => $tailLines];
        if ($container) {
            $params['container'] = $container;
        }

        $response = $this->request()->get($url.'?'.http_build_query($params));

        return $response->successful() ? $response->body() : null;
    }

    /**
     * Strategic merge patch for a namespaced resource.
     */
    public function strategicMergePatch(string $apiVersion, string $kind, string $name, string $namespace, array $patch): bool
    {
        $url = $this->resourceUrl($apiVersion, $kind, $namespace, $name);

        $response = $this->request()
            ->withHeaders(['Content-Type' => 'application/strategic-merge-patch+json'])
            ->patch($url, $patch);

        if ($response->successful()) {
            return true;
        }

        Log::error('K8s strategic merge patch failed', [
            'kind' => $kind,
            'name' => $name,
            'ns' => $namespace,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }

    /**
     * JSON Patch (RFC 6902) for a namespaced resource — e.g. replacing one container's env
     * without touching the rest of a custom resource's spec.
     */
    public function jsonPatch(string $apiVersion, string $kind, string $name, string $namespace, array $operations): bool
    {
        $url = $this->resourceUrl($apiVersion, $kind, $namespace, $name);

        $response = $this->request()
            ->withBody(json_encode($operations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json-patch+json')
            ->patch($url);

        if ($response->successful()) {
            return true;
        }

        Log::error('K8s JSON patch failed', [
            'kind' => $kind,
            'name' => $name,
            'ns' => $namespace,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }

    /**
     * Wake up a scaled-to-zero Knative service via the activator proxy.
     */
    public function wakeUpKnativeService(string $servingNs, string $targetNs, string $serviceName, int $timeout = 15): bool
    {
        $revision = $this->resolveLatestRevision($targetNs, $serviceName);
        if (! $revision) {
            return false;
        }

        $url = "{$this->server}/api/v1/namespaces/{$servingNs}/services/http:activator-service:80/proxy/";

        try {
            $response = $this->request()
                ->timeout($timeout)
                ->withHeaders([
                    'Host' => "{$serviceName}.{$targetNs}.svc.cluster.local",
                    'Knative-Serving-Namespace' => $targetNs,
                    'Knative-Serving-Revision' => $revision,
                ])
                ->get($url);

            return $response->successful();
        } catch (\Throwable) {
            return true; // timeout = activation was triggered, pod starting
        }
    }

    private function resolveLatestRevision(string $namespace, string $serviceName): ?string
    {
        try {
            $result = $this->listResources(
                'serving.knative.dev/v1', 'Configuration', $namespace,
                ['serving.knative.dev/service' => $serviceName]
            );

            return $result['items'][0]['status']['latestReadyRevisionName'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * JSON merge patch (RFC 7386) — works with CRDs unlike strategic merge patch.
     */
    public function mergePatch(string $apiVersion, string $kind, string $name, string $namespace, array $patch): bool
    {
        $url = $this->resourceUrl($apiVersion, $kind, $namespace, $name);

        $response = $this->request()
            ->withHeaders(['Content-Type' => 'application/merge-patch+json'])
            ->patch($url, $patch);

        if ($response->successful()) {
            return true;
        }

        Log::error('K8s merge patch failed', [
            'kind' => $kind,
            'name' => $name,
            'ns' => $namespace,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
    }
}
