<?php

namespace App\Services\Concerns;

use App\Services\Helpers\KataRuntime;
use Illuminate\Http\Client\Response;
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
    /** Why the last applyResource() / createResource() failed (the API server's message), null after a success. */
    private ?string $lastApplyError = null;

    /**
     * The reason the last applyResource() or createResource() returned false: "HTTP <status>:
     * <message>" of the API server's Status (an admission webhook's refusal, a validation error),
     * null when it succeeded.
     */
    public function lastApplyError(): ?string
    {
        return $this->lastApplyError;
    }

    /** "HTTP 422: <message>" of a failed write (the Status message, else the body's start). */
    private static function applyFailure(Response $response): string
    {
        $message = $response->json('message');
        $message = is_string($message) && $message !== '' ? $message : trim(mb_substr($response->body(), 0, 500));

        return 'HTTP '.$response->status().($message !== '' ? ': '.$message : '');
    }

    /**
     * Apply (create or update) a Kubernetes resource.
     * Uses server-side apply (PATCH with application/apply-patch+yaml).
     */
    public function applyResource(array $manifest, string $namespace): bool
    {
        $this->lastApplyError = null;
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

        $this->lastApplyError = self::applyFailure($response);
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
        $this->lastApplyError = null;
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
            $this->lastApplyError = self::applyFailure($putResponse);

            Log::error('K8s update failed after conflict', [
                'kind' => $kind,
                'name' => $manifest['metadata']['name'],
                'status' => $putResponse->status(),
                'response' => $putResponse->body(),
            ]);

            return false;
        }

        $this->lastApplyError = self::applyFailure($response);
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
     * A namespaced resource; null when it does not exist (404). Any other failure throws, so a
     * caller that must not lose data can tell "none" from "unknown" (project secret values,
     * App\Services\Secrets\RegionSecrets). The exception carries the HTTP status and the API's
     * message, never the object.
     */
    public function getResourceOrFail(string $apiVersion, string $kind, string $name, string $namespace): ?array
    {
        $response = $this->request()->get($this->resourceUrl($apiVersion, $kind, $namespace, $name));

        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new \RuntimeException("Reading {$kind} {$namespace}/{$name} failed (HTTP {$response->status()}): ".self::statusMessage($response->json()));
        }

        return (array) $response->json();
    }

    /**
     * Create a namespaced resource: true when created, false when it already exists (409). Any
     * other failure throws with the status and the API's message — never the manifest, which may
     * hold secret values.
     */
    public function createResourceOrFail(array $manifest, string $namespace): bool
    {
        $response = $this->request()
            ->withBody(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')
            ->post($this->collectionUrl($manifest['apiVersion'], $manifest['kind'], $namespace));

        if ($response->status() === 409) {
            return false;
        }
        if (! $response->successful()) {
            throw new \RuntimeException("Creating {$manifest['kind']} {$namespace}/{$manifest['metadata']['name']} failed (HTTP {$response->status()}): ".self::statusMessage($response->json()));
        }

        return true;
    }

    /**
     * JSON merge patch (RFC 7386) that throws on failure: true when patched, false when the object
     * does not exist (404). The exception carries the status and the API's message, never the patch.
     */
    public function mergePatchOrFail(string $apiVersion, string $kind, string $name, string $namespace, array $patch): bool
    {
        $response = $this->request()
            ->withBody(json_encode($patch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/merge-patch+json')
            ->patch($this->resourceUrl($apiVersion, $kind, $namespace, $name));

        if ($response->status() === 404) {
            return false;
        }
        if (! $response->successful()) {
            throw new \RuntimeException("Patching {$kind} {$namespace}/{$name} failed (HTTP {$response->status()}): ".self::statusMessage($response->json()));
        }

        return true;
    }

    /**
     * A short-lived token of a service account (TokenRequest API), for a login with the account's
     * identity — never stored. Throws when the cluster refuses.
     *
     * @param  list<string>  $audiences  empty: the API server's own audience
     */
    public function requestServiceAccountToken(string $namespace, string $serviceAccount, int $ttl = 600, array $audiences = []): string
    {
        $spec = ['expirationSeconds' => max(600, $ttl)];
        if ($audiences !== []) {
            $spec['audiences'] = $audiences;
        }

        $response = $this->request()
            ->withBody(json_encode(['apiVersion' => 'authentication.k8s.io/v1', 'kind' => 'TokenRequest', 'spec' => $spec]), 'application/json')
            ->post($this->resourceUrl('v1', 'ServiceAccount', $namespace, $serviceAccount).'/token');

        $token = $response->json('status.token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new \RuntimeException("A token of service account {$namespace}/{$serviceAccount} was refused (HTTP {$response->status()}): ".self::statusMessage($response->json()));
        }

        return $token;
    }

    /** The message of a Kubernetes Status answer, shortened. */
    private static function statusMessage(mixed $json): string
    {
        $message = is_array($json) ? (string) ($json['message'] ?? $json['reason'] ?? '') : '';

        return mb_substr($message !== '' ? $message : 'no message', 0, 300);
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
     * Wake up a scaled-to-zero Knative service: a request to its cluster-local host through the
     * region Traefik's private entry point (knativePrivateRequest), which the activator holds
     * until a pod is up.
     */
    public function wakeUpKnativeService(string $servingNs, string $targetNs, string $serviceName, int $timeout = 15, ?string $token = null): bool
    {
        if (! $this->resolveLatestRevision($targetNs, $serviceName)) {
            return false;
        }

        try {
            return $this->knativePrivateRequest('GET', $servingNs, $targetNs, $serviceName, '', [], null, $timeout, $token)->successful();
        } catch (\Throwable) {
            return true; // timeout = activation was triggered, pod starting
        }
    }

    /**
     * JSON merge patch of a namespaced resource's status subresource (…/<name>/status).
     */
    public function mergePatchStatus(string $apiVersion, string $kind, string $name, string $namespace, array $patch): bool
    {
        $url = $this->resourceUrl($apiVersion, $kind, $namespace, $name).'/status';

        $response = $this->request()
            ->withHeaders(['Content-Type' => 'application/merge-patch+json'])
            ->patch($url, $patch);

        if ($response->successful()) {
            return true;
        }

        Log::error('K8s status merge patch failed', [
            'kind' => $kind,
            'name' => $name,
            'ns' => $namespace,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        return false;
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
