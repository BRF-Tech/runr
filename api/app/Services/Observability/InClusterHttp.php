<?php

namespace App\Services\Observability;

use App\Models\KnativeRegion;
use App\Services\Helpers\MonitoringEndpoint;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * GET against an in-cluster Service of a region (Tempo, Loki, Prometheus) the way RunR reads its
 * monitoring stack (docs/observability.md#api-access): a direct call to the canonical in-cluster
 * name only when the region is RunR's own cluster, otherwise — and as the fallback — through the
 * region's Kubernetes API service proxy. Never an arbitrary URL.
 */
class InClusterHttp
{
    public function __construct(private ?RunrClusterIdentity $identity = null)
    {
        $this->identity ??= app(RunrClusterIdentity::class);
    }

    /**
     * The response whatever its status; null when neither path reached the Service.
     */
    public function get(KnativeRegion $region, MonitoringEndpoint $endpoint, string $path, array $query = [], array $headers = [], int $timeout = 10): ?Response
    {
        $headers += ['Accept' => 'application/json'];

        if ($this->isRunrCluster($region)) {
            try {
                $response = Http::timeout($timeout)->withHeaders($headers)->get($endpoint->directUrl($path), $query);
                if ($response->status() < 500) {
                    return $response;
                }
            } catch (\Throwable $e) {
                Log::debug('In-cluster direct call failed, trying the K8s API proxy', ['service' => $endpoint->service, 'error' => $e->getMessage()]);
            }
        }

        if (! $region->kubeconfig) {
            return null;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            return $k8s->serviceProxyRequest('GET', $endpoint->namespace, $endpoint->service, $endpoint->port, $endpoint->proxyPath($path), $headers, $query, $endpoint->scheme);
        } catch (\Throwable $e) {
            Log::warning('In-cluster service proxy call failed', ['service' => $endpoint->service, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function isRunrCluster(KnativeRegion $region): bool
    {
        try {
            return $this->identity->isRunrCluster($region);
        } catch (\Throwable) {
            return false;
        }
    }
}
