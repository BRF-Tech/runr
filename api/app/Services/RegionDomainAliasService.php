<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\Manifests\ProjectDomainManifests;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * A second function domain for a region during a base-domain migration (DomainMigrateService):
 * the region's Knative Services keep their URL on the current function domain, and every host
 * <service>.<alias domain> is answered by the same service.
 *
 * Knative gives a Knative Service one public domain, so the alias is routed the way project
 * custom domains are (ProjectDomainManifests): the request re-enters the region Traefik with the
 * Knative Service's cluster-local host <service>.<namespace>.svc on its private Knative
 * entrypoint. A custom domain names one service; the alias covers every service of the region,
 * new ones included, so a small proxy in the project namespace (runr-domain-alias) rewrites the
 * host instead of one Middleware per service:
 *
 *   IngressRoute runr-domain-alias      web       HostRegexp(^[^.]+\.<alias>$) → Service runr-domain-alias:8080
 *   IngressRoute runr-domain-alias-tls  websecure (the region's default certificate)
 *   Deployment   runr-domain-alias      Host <service>.<alias> → <service>.<namespace>.svc, K-Original-Host kept,
 *                                       → Service traefik:80 of the Knative Serving namespace
 *
 * An organization's own base domain moves the same way on the platform regions it uses
 * (TenantDomainService): an alias of its own ($name, one per organization next to the platform's)
 * that answers only for the organization's services — their names end in -<organization slug>
 * (Project::slugFor), preview services in -<organization slug>-pr-<n> — labelled runr/tenant.
 */
class RegionDomainAliasService
{
    public const NAME = 'runr-domain-alias';

    /** The alias domain on each object of an alias (the status block of the region covers it) */
    public const ANNOTATION_ALIAS = ProjectDomainManifests::ANNOTATION_DOMAIN.'-alias';

    private const PORT = 8080;

    /**
     * @param  string|null  $tenantSlug  an organization's alias: only its services, labelled runr/tenant
     */
    public function apply(KnativeRegion $region, string $aliasDomain, string $name = self::NAME, ?string $tenantSlug = null): void
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $ns = self::namespace($region);

        foreach (self::manifests($region, $aliasDomain, $name, $tenantSlug) as $manifest) {
            if (! $k8s->applyResource($manifest, $ns)) {
                throw new RuntimeException("{$manifest['kind']}/{$manifest['metadata']['name']} was rejected in {$ns}");
            }
        }
    }

    public function remove(KnativeRegion $region, string $name = self::NAME): void
    {
        if (! $region->kubeconfig) {
            return;
        }

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $ns = self::namespace($region);
            $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', $name, $ns);
            $k8s->deleteResource(ProjectDomainManifests::TRAEFIK_API, 'IngressRoute', $name.'-tls', $ns);
            $k8s->deleteResource('v1', 'Service', $name, $ns);
            $k8s->deleteResource('apps/v1', 'Deployment', $name, $ns);
            $k8s->deleteResource('v1', 'ConfigMap', $name, $ns);
        } catch (\Throwable $e) {
            Log::warning("Domain alias of region {$region->name} not removed", ['error' => $e->getMessage()]);
        }
    }

    public static function namespace(KnativeRegion $region): string
    {
        return $region->project_namespace ?: $region->name;
    }

    /**
     * ConfigMap, Deployment, Service and the two IngressRoutes of the alias.
     *
     * @param  string|null  $tenantSlug  an organization's alias: only its services, labelled runr/tenant
     * @return list<array<string, mixed>>
     */
    public static function manifests(KnativeRegion $region, string $aliasDomain, string $name = self::NAME, ?string $tenantSlug = null): array
    {
        $ns = self::namespace($region);
        $servingNs = $region->knative_serving_namespace ?: 'knative-serving';
        $labels = ['app' => $name, 'app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/part-of' => 'runr']
            + ($tenantSlug !== null ? ['runr/tenant' => $tenantSlug] : []);
        $meta = fn (string $name) => ['name' => $name, 'namespace' => $ns, 'labels' => $labels, 'annotations' => [self::ANNOTATION_ALIAS => $aliasDomain]];
        $match = 'HostRegexp(`^[^.]+\\.'.str_replace('.', '\\.', $aliasDomain).'$`)';
        $route = fn (bool $secure) => [
            'apiVersion' => ProjectDomainManifests::TRAEFIK_API,
            'kind' => 'IngressRoute',
            'metadata' => $meta($secure ? $name.'-tls' : $name),
            'spec' => array_filter([
                'entryPoints' => [$secure ? 'websecure' : 'web'],
                'routes' => [[
                    'match' => $match,
                    'kind' => 'Rule',
                    'services' => [['name' => $name, 'port' => self::PORT]],
                ]],
                'tls' => $secure ? new \stdClass : null,
            ], fn ($value) => $value !== null),
        ];

        return [
            [
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => $meta($name),
                'data' => ['proxy.js' => self::proxyScript()],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => $meta($name),
                'spec' => [
                    'replicas' => 2,
                    'selector' => ['matchLabels' => ['app' => $name]],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'containers' => [[
                                'name' => 'proxy',
                                'image' => $region->registryImageRef('ghcr.io/brf-tech/runr/reqs/node:22-alpine'),
                                'command' => ['node', '/app/proxy.js'],
                                'env' => array_merge([
                                    ['name' => 'ALIAS_DOMAIN', 'value' => $aliasDomain],
                                    ['name' => 'SERVICE_SUFFIX', 'value' => ".{$ns}.svc"],
                                    ['name' => 'TARGET_HOST', 'value' => KnativeConstants::TRAEFIK_RELEASE.".{$servingNs}.svc.cluster.local"],
                                    ['name' => 'TARGET_PORT', 'value' => '80'],
                                ], $tenantSlug !== null ? [['name' => 'SERVICE_NAME_SUFFIX', 'value' => '-'.$tenantSlug]] : []),
                                'ports' => [['containerPort' => self::PORT]],
                                'readinessProbe' => ['tcpSocket' => ['port' => self::PORT], 'periodSeconds' => 5],
                                'resources' => [
                                    'requests' => ['cpu' => '10m', 'memory' => '32Mi'],
                                    'limits' => ['cpu' => '200m', 'memory' => '96Mi'],
                                ],
                                'volumeMounts' => [['name' => 'app', 'mountPath' => '/app']],
                            ]],
                            'volumes' => [['name' => 'app', 'configMap' => ['name' => $name]]],
                        ] + DedicatedWorkers::systemPlacement(),
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => $meta($name),
                'spec' => [
                    'selector' => ['app' => $name],
                    'ports' => [['port' => self::PORT, 'targetPort' => self::PORT]],
                ],
            ],
            $route(false),
            $route(true),
        ];
    }

    /**
     * Node.js proxy: <service>.<alias> → <service>.<namespace>.svc on the region Traefik, the
     * request and response streamed (long requests and WebSockets included). With
     * SERVICE_NAME_SUFFIX (an organization's alias) only services named <…><suffix> or
     * <…><suffix>-pr-<n> are answered.
     */
    public static function proxyScript(): string
    {
        return <<<'JS'
const http = require('http');
const net = require('net');
const alias = '.' + process.env.ALIAS_DOMAIN.toLowerCase();
const suffix = process.env.SERVICE_SUFFIX;
const owner = process.env.SERVICE_NAME_SUFFIX || '';
const target = { host: process.env.TARGET_HOST, port: Number(process.env.TARGET_PORT || 80) };

function serviceHost(host) {
  const name = String(host || '').toLowerCase().split(':')[0];
  if (!name.endsWith(alias)) return null;
  const service = name.slice(0, -alias.length);
  if (!service || service.includes('.')) return null;
  if (owner && !service.replace(/-pr-[0-9]+$/, '').endsWith(owner)) return null;
  return service + suffix;
}

const server = http.createServer((req, res) => {
  const host = serviceHost(req.headers.host);
  if (!host) {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'not_found' }));
    return;
  }
  const headers = { ...req.headers, host, 'k-original-host': req.headers.host };
  const upstream = http.request({ ...target, method: req.method, path: req.url, headers }, (answer) => {
    res.writeHead(answer.statusCode, answer.rawHeaders);
    answer.pipe(res);
  });
  upstream.on('error', () => {
    if (!res.headersSent) res.writeHead(502);
    res.end();
  });
  req.pipe(upstream);
});

server.on('upgrade', (req, socket, head) => {
  const host = serviceHost(req.headers.host);
  if (!host) return socket.destroy();
  const upstream = net.connect(target.port, target.host, () => {
    const lines = [`${req.method} ${req.url} HTTP/${req.httpVersion}`];
    for (let i = 0; i < req.rawHeaders.length; i += 2) {
      if (req.rawHeaders[i].toLowerCase() !== 'host') lines.push(`${req.rawHeaders[i]}: ${req.rawHeaders[i + 1]}`);
    }
    lines.push(`Host: ${host}`, `K-Original-Host: ${req.headers.host}`);
    upstream.write(lines.join('\r\n') + '\r\n\r\n');
    if (head && head.length) upstream.write(head);
    upstream.pipe(socket);
    socket.pipe(upstream);
  });
  upstream.on('error', () => socket.destroy());
  socket.on('error', () => upstream.destroy());
});

server.requestTimeout = 0;
server.keepAliveTimeout = 65000;
server.headersTimeout = 66000;
server.listen(8080, () => console.log('runr-domain-alias: *' + alias + ' -> *' + suffix));
JS;
    }
}
