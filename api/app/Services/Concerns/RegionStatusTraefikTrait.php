<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\Helpers\RegionDomains;
use App\Services\KubernetesClient;
use App\Services\Manifests\ProjectDomainManifests;
use App\Services\RegionDomainAliasService;
use Illuminate\Support\Facades\Log;

/**
 * Manages Traefik IngressRoute + Middleware + status-responder for region blocking.
 *
 * When a region is inactive or in maintenance, a catch-all IngressRoute routes every request to
 * its hosts to a status-responder pod returning 404 or 503. Its hosts: every host under the
 * region's function domain (and, during a base-domain migration, under the domain it moves to),
 * the project custom domains routed on the region (RunR's custom-domain IngressRoutes in the
 * project namespace) and the domains of the region's Knative Services (a tenant's own base
 * domain, through Knative's config-domain, and its alias while that domain moves). When the
 * region is active, the IngressRoute and its Middleware are removed.
 *
 * The objects are per region (runr-region-block-<region>): regions on one cluster share its
 * Knative Serving namespace, and one region's status must not block or unblock another.
 * Traefik v3 rule syntax (HostRegexp takes a regular expression).
 */
trait RegionStatusTraefikTrait
{
    private const STATUS_RESPONDER_NAME = 'runr-status-responder';

    /** Name of the single, unscoped block route of earlier releases (Traefik v2 rule syntax) */
    private const LEGACY_BLOCK_ROUTE_NAME = 'runr-region-block';

    private const RESPONDER_PORT = 8080;

    private const TRAEFIK_API = 'traefik.io/v1alpha1';

    /** Name of the region's block IngressRoute and Middleware */
    public static function blockRouteName(KnativeRegion $region): string
    {
        return self::LEGACY_BLOCK_ROUTE_NAME.'-'.$region->name;
    }

    /**
     * Sync Traefik blocking resources based on region status.
     *
     * @return list<string> what changed (for region:reconcile)
     */
    protected function syncRegionBlockRoute(KnativeRegion $region, bool $dryRun = false): array
    {
        try {
            return $this->syncBlockOn($region, new KubernetesClient($region->kubeconfig), $dryRun);
        } catch (\Throwable $e) {
            Log::warning('RegionStatus: failed to sync Traefik blocking', [
                'region' => $region->name,
                'error' => $e->getMessage(),
            ]);

            return ["failed: {$e->getMessage()}"];
        }
    }

    /**
     * @return list<string>
     */
    private function syncBlockOn(KnativeRegion $region, KubernetesClient $k8s, bool $dryRun): array
    {
        $ns = $region->knative_serving_namespace ?: 'knative-serving';
        $name = self::blockRouteName($region);
        $lines = [];

        // The unscoped route of earlier releases never matched on Traefik v3 (v2 rule syntax)
        if ($k8s->getResourceStatus(self::TRAEFIK_API, 'IngressRoute', self::LEGACY_BLOCK_ROUTE_NAME, $ns)) {
            if (! $dryRun) {
                $k8s->deleteResource(self::TRAEFIK_API, 'IngressRoute', self::LEGACY_BLOCK_ROUTE_NAME, $ns);
                $k8s->deleteResource(self::TRAEFIK_API, 'Middleware', self::LEGACY_BLOCK_ROUTE_NAME, $ns);
            }
            $lines[] = ($dryRun ? 'would remove' : 'removed')." the earlier block route {$ns}/".self::LEGACY_BLOCK_ROUTE_NAME;
        }

        $live = $k8s->getResourceStatus(self::TRAEFIK_API, 'IngressRoute', $name, $ns);

        if ($region->is_active) {
            if ($live) {
                if (! $dryRun) {
                    $this->removeBlockingResources($k8s, $ns, $name);
                }
                $lines[] = ($dryRun ? 'would remove' : 'removed')." the status block {$ns}/{$name} of active region {$region->name}";
            }

            return $lines;
        }

        $match = self::blockMatch(self::blockedHostRules($region, $k8s));
        if ($match === null) {
            return array_merge($lines, ["skipped: region {$region->name} has no function domain to block"]);
        }

        $path = $region->is_maintenance ? '/maintenance' : '/inactive';
        $current = $live['spec']['routes'][0]['match'] ?? null;
        $currentPath = $k8s->getResourceStatus(self::TRAEFIK_API, 'Middleware', $name, $ns)['spec']['replacePath']['path'] ?? null;
        $responder = $k8s->getResourceStatus('apps/v1', 'Deployment', self::STATUS_RESPONDER_NAME, $ns);

        if ($current === $match && $currentPath === $path && $responder) {
            return $lines;
        }

        if (! $dryRun) {
            if (! $responder) {
                $this->ensureStatusResponder($k8s, $ns, $region);
            }
            $this->applyBlockingResources($k8s, $ns, $name, $path, $match);
        }

        $lines[] = ($dryRun ? 'would apply' : 'applied')." the status block {$ns}/{$name} ("
            .($region->is_maintenance ? '503 maintenance' : '404 inactive')."): {$match}";

        return $lines;
    }

    /**
     * Traefik rule parts of the hosts a blocked region answers for: HostRegexp per domain (every
     * host under it), Host per custom domain.
     *
     * @return list<string>
     */
    public static function blockedHostRules(KnativeRegion $region, ?KubernetesClient $k8s = null): array
    {
        $domains = RegionDomains::served($region);
        $hosts = [];

        if ($k8s) {
            $projectNs = $region->project_namespace ?: $region->name;

            // Knative Services: the domain of each public URL (a tenant's own base domain)
            foreach ($k8s->listResources('serving.knative.dev/v1', 'Service', $projectNs)['items'] ?? [] as $ksvc) {
                $host = strtolower((string) parse_url((string) ($ksvc['status']['url'] ?? ''), PHP_URL_HOST));
                if ($host !== '' && ! str_ends_with($host, '.svc.cluster.local') && str_contains($host, '.')) {
                    $domains[] = substr($host, strpos($host, '.') + 1);
                }
            }

            // Project custom domains RunR routes on the region, and the alias domains a base-domain
            // move serves next to the current one (an organization's own, RegionDomainAliasService)
            foreach ($k8s->listResources(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', $projectNs)['items'] ?? [] as $route) {
                $domain = $route['metadata']['annotations'][ProjectDomainManifests::ANNOTATION_DOMAIN] ?? null;
                if (is_string($domain) && $domain !== '') {
                    $hosts[] = strtolower($domain);
                }
                $alias = $route['metadata']['annotations'][RegionDomainAliasService::ANNOTATION_ALIAS] ?? null;
                if (is_string($alias) && $alias !== '') {
                    $domains[] = strtolower($alias);
                }
            }
        }

        $valid = fn (string $name) => preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $name) === 1;
        $domains = array_values(array_unique(array_filter($domains, $valid)));
        sort($domains);
        // a host under a blocked domain needs no rule of its own
        $hosts = array_values(array_unique(array_filter($hosts, function (string $host) use ($valid, $domains) {
            if (! $valid($host)) {
                return false;
            }
            foreach ($domains as $domain) {
                if (str_ends_with($host, '.'.$domain)) {
                    return false;
                }
            }

            return true;
        })));
        sort($hosts);

        $rules = array_map(fn (string $d) => 'HostRegexp(`^.+\\.'.str_replace('.', '\\.', $d).'$`)', $domains);
        foreach ($hosts as $host) {
            $rules[] = "Host(`{$host}`)";
        }

        return $rules;
    }

    /** @param list<string> $rules */
    private static function blockMatch(array $rules): ?string
    {
        return $rules === [] ? null : implode(' || ', $rules);
    }

    private function ensureStatusResponder(KubernetesClient $k8s, string $ns, KnativeRegion $region): void
    {
        // ConfigMap with status responder server.js
        $k8s->applyResource($this->buildResponderConfigMap($ns), $ns);

        // Deployment
        $k8s->applyResource($this->buildResponderDeployment($ns, $region), $ns);

        // Service
        $k8s->applyResource($this->buildResponderService($ns), $ns);
    }

    private function applyBlockingResources(KubernetesClient $k8s, string $ns, string $name, string $path, string $match): void
    {
        $labels = ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/part-of' => 'runr'];

        // Middleware: replace path
        $k8s->applyResource([
            'apiVersion' => self::TRAEFIK_API,
            'kind' => 'Middleware',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => $labels],
            'spec' => ['replacePath' => ['path' => $path]],
        ], $ns);

        // IngressRoute: catch-all for the region's hosts, ahead of every other route
        $k8s->applyResource([
            'apiVersion' => self::TRAEFIK_API,
            'kind' => 'IngressRoute',
            'metadata' => ['name' => $name, 'namespace' => $ns, 'labels' => $labels],
            'spec' => [
                'entryPoints' => ['web', 'websecure'],
                'routes' => [[
                    'match' => $match,
                    'kind' => 'Rule',
                    'priority' => 999999,
                    'services' => [['name' => self::STATUS_RESPONDER_NAME, 'port' => self::RESPONDER_PORT]],
                    'middlewares' => [['name' => $name]],
                ]],
            ],
        ], $ns);
    }

    private function removeBlockingResources(KubernetesClient $k8s, string $ns, string $name): void
    {
        $k8s->deleteResource(self::TRAEFIK_API, 'IngressRoute', $name, $ns);
        $k8s->deleteResource(self::TRAEFIK_API, 'Middleware', $name, $ns);
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
                    ] + DedicatedWorkers::systemPlacement(),
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
