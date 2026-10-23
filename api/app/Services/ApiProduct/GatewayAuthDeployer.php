<?php

namespace App\Services\ApiProduct;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use App\Services\Manifests\GatewayAuthManifests;
use App\Services\Observability\IngestUrl;
use RuntimeException;

/**
 * Installs the region's gateway-auth service (GatewayAuthManifests) in the project namespace: once
 * a gateway of the region has a key or JWT route, again on every sync of such a gateway (applied
 * objects that did not change cause no rollout) and on `region:reconcile` when the image changed
 * (GatewayAuthStep). One service per region serves every organization's gateways there.
 *
 * With the region's Redis its counters are shared and it runs config('api_product.replicas')
 * pods; without Redis one pod with in-memory counters. Its metering proxy (Service
 * runr-gateway-auth-proxy) records what the functions answer (docs/api-product.md#usage).
 */
class GatewayAuthDeployer
{
    public function __construct(
        private readonly GatewayAuthToken $tokens,
        private readonly IngestUrl $ingest,
    ) {}

    public function image(KnativeRegion $region): string
    {
        return $region->registryImageRef((string) config('api_product.gateway_auth_image'));
    }

    /** RunR's base URL as the region reaches it, null when unknown. */
    public function apiUrl(KnativeRegion $region): ?string
    {
        $configured = trim((string) config('api_product.callback_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        return $this->ingest->forRegion($region);
    }

    /** @return list<array<string, mixed>> */
    public function manifests(KnativeRegion $region, string $namespace): array
    {
        $url = $this->apiUrl($region);
        if ($url === null) {
            throw new RuntimeException(__('api_product.auth_service.no_callback_url'));
        }

        $data = [
            'RUNR_API_URL' => $url,
            'RUNR_REGION_ID' => (string) $region->id,
            'RUNR_TOKEN' => $this->tokens->token((string) $region->id),
        ];
        if ($region->has_redis) {
            $data['REDIS_URL'] = $this->redisUrl($region);
        }
        ksort($data);

        $replicas = $region->has_redis ? max(1, (int) config('api_product.replicas', 2)) : 1;

        return [
            GatewayAuthManifests::secret($namespace, $data),
            GatewayAuthManifests::deployment(
                $namespace,
                $this->image($region),
                $replicas,
                substr(hash('sha256', json_encode($data)), 0, 16),
                max(2, (int) config('api_product.sync_seconds', 10)),
                max(5, (int) config('api_product.flush_seconds', 30)),
                // the metering proxy hands the calls on to the region Traefik's web entry point
                GatewayAuthManifests::upstreamUrl($region->knative_serving_namespace ?: 'knative-serving'),
            ),
            GatewayAuthManifests::service($namespace),
            GatewayAuthManifests::proxyService($namespace),
        ];
    }

    /**
     * Whether the metering proxy takes the routes' calls: a pod of it is ready (it has its
     * configuration), and every pod that answers the checks has the proxy — a pod of an earlier
     * image (a rollout under way) would let calls through without the ticket the proxy needs.
     * Until then a gateway's key / JWT routes hand their calls on as before, unmetered
     * (GatewayService); GatewayAuthHealthJob syncs them once it is.
     */
    public function proxyReady(KubernetesClient $k8s, string $namespace): bool
    {
        $proxy = $this->endpoints($k8s, $namespace, GatewayAuthManifests::PROXY_NAME);
        if (! in_array(true, $proxy, true)) {
            return false;
        }

        // the checks' Service publishes pods that are not ready yet: all of them count
        $checks = $this->endpoints($k8s, $namespace, GatewayAuthManifests::NAME);

        return array_diff(array_keys($checks), array_keys($proxy)) === [];
    }

    /**
     * Addresses of a Service's endpoints that are not terminating → whether each is ready.
     *
     * @return array<string, bool>
     */
    private function endpoints(KubernetesClient $k8s, string $namespace, string $service): array
    {
        $out = [];
        $slices = $k8s->listResources('discovery.k8s.io/v1', 'EndpointSlice', $namespace, ['kubernetes.io/service-name' => $service]);
        foreach ($slices['items'] ?? [] as $slice) {
            foreach ($slice['endpoints'] ?? [] as $endpoint) {
                if (($endpoint['conditions']['terminating'] ?? false) === true) {
                    continue;
                }
                foreach ($endpoint['addresses'] ?? [] as $address) {
                    // conditions.ready unset counts as ready
                    $out[(string) $address] = ($out[(string) $address] ?? false) || ($endpoint['conditions']['ready'] ?? true) === true;
                }
            }
        }

        return $out;
    }

    public function apply(KubernetesClient $k8s, KnativeRegion $region, string $namespace): void
    {
        foreach ($this->manifests($region, $namespace) as $manifest) {
            if (! $k8s->applyResource($manifest, $namespace)) {
                throw new RuntimeException("gateway-auth {$manifest['kind']} could not be applied in namespace {$namespace}");
            }
        }
    }

    /**
     * Whether gateway-auth can check calls: a pod of the current Deployment is ready (it has its
     * configuration from RunR — /readyz). age: seconds since the Deployment was created.
     *
     * @return array{ready: bool, age: int|null}
     */
    public function readiness(KubernetesClient $k8s, string $namespace): array
    {
        $deployment = $k8s->getResourceStatus('apps/v1', 'Deployment', GatewayAuthManifests::NAME, $namespace);
        if (! is_array($deployment) || ! isset($deployment['metadata'])) {
            return ['ready' => false, 'age' => null];
        }

        $status = $deployment['status'] ?? [];
        $generation = (int) ($deployment['metadata']['generation'] ?? 0);
        $observed = (int) ($status['observedGeneration'] ?? 0);
        $ready = (int) ($status['readyReplicas'] ?? 0) > 0
            && (int) ($status['updatedReplicas'] ?? 0) > 0
            && $observed >= $generation;

        $created = $deployment['metadata']['creationTimestamp'] ?? null;
        $age = $created ? max(0, now()->getTimestamp() - strtotime((string) $created)) : null;

        return ['ready' => $ready, 'age' => $age];
    }

    public function remove(KubernetesClient $k8s, string $namespace): void
    {
        $k8s->deleteResource('apps/v1', 'Deployment', GatewayAuthManifests::NAME, $namespace);
        $k8s->deleteResource('v1', 'Service', GatewayAuthManifests::NAME, $namespace);
        $k8s->deleteResource('v1', 'Service', GatewayAuthManifests::PROXY_NAME, $namespace);
        $k8s->deleteResource('v1', 'Secret', GatewayAuthManifests::NAME, $namespace);
    }

    /** redis[s]://[:password@]host:port — the region's Redis (the queue triggers' one). */
    private function redisUrl(KnativeRegion $region): string
    {
        $address = (string) ($region->redis_address ?: 'localhost:6379');
        $scheme = str_starts_with($address, 'rediss://') ? 'rediss' : 'redis';
        $host = preg_replace('#^rediss?://#', '', $address);
        $password = (string) ($region->redis_password ?? '');

        return $password !== '' ? "{$scheme}://:".rawurlencode($password).'@'.$host : "{$scheme}://".$host;
    }
}
