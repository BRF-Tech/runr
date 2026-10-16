<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

/**
 * Manages Traefik IngressRoute + Middleware + status-responder for region blocking.
 *
 * When a region is inactive or in maintenance, a catch-all IngressRoute is created
 * that routes all traffic to a status-responder pod returning 404 or 503.
 * When the region is active, the IngressRoute + Middleware are removed.
 */
trait RegionStatusTraefikTrait
{
    private const STATUS_RESPONDER_NAME = 'runr-status-responder';

    private const BLOCK_ROUTE_NAME = 'runr-region-block';

    private const RESPONDER_PORT = 8080;

    /**
     * Sync Traefik blocking resources based on region status.
     */
    protected function syncRegionBlockRoute(KnativeRegion $region): void
    {
        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $ns = $region->knative_serving_namespace ?? 'knative-serving';

            if ($region->is_active) {
                $this->removeBlockingResources($k8s, $ns);
            } else {
                $this->ensureStatusResponder($k8s, $ns, $region);
                $this->applyBlockingResources($k8s, $ns, $region);
            }
        } catch (\Throwable $e) {
            Log::warning('RegionStatus: failed to sync Traefik blocking', [
                'region' => $region->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ensureStatusResponder(KubernetesClient $k8s, string $ns, KnativeRegion $region): void
    {
        // Check if responder already exists
        $existing = $k8s->getResourceStatus('apps/v1', 'Deployment', self::STATUS_RESPONDER_NAME, $ns);
        if ($existing) {
            return;
        }

        // ConfigMap with status responder server.js
        $k8s->applyResource($this->buildResponderConfigMap($ns), $ns);

        // Deployment
        $k8s->applyResource($this->buildResponderDeployment($ns, $region), $ns);

        // Service
        $k8s->applyResource($this->buildResponderService($ns), $ns);
    }

    private function applyBlockingResources(KubernetesClient $k8s, string $ns, KnativeRegion $region): void
    {
        $path = $region->is_maintenance ? '/maintenance' : '/inactive';
        $knativeDomain = $region->effective_settings['knative_domain'] ?? null;

        if (! $knativeDomain) {
            return;
        }

        // Middleware: replace path
        $k8s->applyResource([
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => ['name' => self::BLOCK_ROUTE_NAME, 'namespace' => $ns],
            'spec' => ['replacePath' => ['path' => $path]],
        ], $ns);

        // IngressRoute: catch-all for *.knative_domain
        $k8s->applyResource([
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'IngressRoute',
            'metadata' => ['name' => self::BLOCK_ROUTE_NAME, 'namespace' => $ns],
            'spec' => [
                'entryPoints' => ['web', 'websecure'],
                'routes' => [[
                    'match' => "HostRegexp(`{subdomain:.+}.{$knativeDomain}`)",
                    'kind' => 'Rule',
                    'priority' => 999999,
                    'services' => [['name' => self::STATUS_RESPONDER_NAME, 'port' => self::RESPONDER_PORT]],
                    'middlewares' => [['name' => self::BLOCK_ROUTE_NAME]],
                ]],
            ],
        ], $ns);
    }

    private function removeBlockingResources(KubernetesClient $k8s, string $ns): void
    {
        $k8s->deleteResource('traefik.io/v1alpha1', 'IngressRoute', self::BLOCK_ROUTE_NAME, $ns);
        $k8s->deleteResource('traefik.io/v1alpha1', 'Middleware', self::BLOCK_ROUTE_NAME, $ns);
    }

    private function buildResponderConfigMap(string $ns): array
    {
        $serverJs = <<<'JS'
const http = require('http');
const server = http.createServer((req, res) => {
  res.setHeader('Content-Type', 'application/json');
  if (req.url === '/maintenance') {
    res.writeHead(503);
    res.end(JSON.stringify({ error: 'maintenance', message: 'Region is under maintenance.' }));
  } else if (req.url === '/inactive') {
    res.writeHead(404);
    res.end(JSON.stringify({ error: 'region_inactive', message: 'Region is inactive.' }));
  } else {
    res.writeHead(200);
    res.end(JSON.stringify({ status: 'ok' }));
  }
});
server.listen(8080, () => console.log('Status responder listening on :8080'));
JS;

        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => ['name' => self::STATUS_RESPONDER_NAME, 'namespace' => $ns],
            'data' => ['server.js' => $serverJs],
        ];
    }

    private function buildResponderDeployment(string $ns, KnativeRegion $region): array
    {
        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => self::STATUS_RESPONDER_NAME, 'namespace' => $ns],
            'spec' => [
                'replicas' => 1,
                'selector' => ['matchLabels' => ['app' => self::STATUS_RESPONDER_NAME]],
                'template' => [
                    'metadata' => ['labels' => ['app' => self::STATUS_RESPONDER_NAME]],
                    'spec' => [
                        'containers' => [[
                            'name' => 'responder',
                            'image' => $region->registryImageRef('ghcr.io/brf-tech/runr/reqs/node:22-alpine'),
                            'command' => ['node', '/app/server.js'],
                            'ports' => [['containerPort' => self::RESPONDER_PORT]],
                            'resources' => [
                                'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                                'limits' => ['cpu' => '50m', 'memory' => '32Mi'],
                            ],
                            'volumeMounts' => [['name' => 'app', 'mountPath' => '/app']],
                        ]],
                        'volumes' => [[
                            'name' => 'app',
                            'configMap' => ['name' => self::STATUS_RESPONDER_NAME],
                        ]],
                    ],
                ],
            ],
        ];
    }

    private function buildResponderService(string $ns): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => self::STATUS_RESPONDER_NAME, 'namespace' => $ns],
            'spec' => [
                'selector' => ['app' => self::STATUS_RESPONDER_NAME],
                'ports' => [['port' => self::RESPONDER_PORT, 'targetPort' => self::RESPONDER_PORT]],
            ],
        ];
    }
}
