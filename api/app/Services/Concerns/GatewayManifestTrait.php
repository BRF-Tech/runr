<?php

namespace App\Services\Concerns;

use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Project;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\DomainName;
use App\Services\InternalNetwork\InternalNetworkManifests;
use App\Services\Manifests\GatewayAuthManifests;
use App\Services\ProjectDomainService;
use App\Services\PublicHostRewrites;

/**
 * Traefik objects of a gateway (one per user and region) in the region's project namespace:
 *
 *   IngressRoute  gw-<id>         Host(<subdomain>.<function domain>) — the region's default
 *                                 certificate on websecure
 *   IngressRoute  gw-<id>-domain  Host(<custom_domain>) with the same routes, while the gateway has
 *                                 a custom domain; TLS as a project custom domain gets it
 *                                 (ProjectDomainManifests): a cert-manager Certificate gw-<id>-domain
 *                                 from the region's ClusterIssuer when one is configured, else the
 *                                 region's default certificate
 *   IngressRoute  gw-<id>-upstream  on web: the metered direct routes' way to their wrr service
 *   Middleware    gw-strip-<route>, gw-host-<route>, gw-ratelimit-<id>, gw-up-<route>,
 *                 gw-<id>-upstream
 *
 * The host is built from the region's function domain (ProjectDomainService::functionDomain:
 * knative_domain, else <region>.<base_domain>) — the domain ProjectResource builds the project's
 * endpoint URL from, so both agree when a region has its own function domain.
 *
 * A rule per route (path prefix, methods) hands the request on to the route's project — or to the
 * environment of the project the route leads to (gateway_routes.environment_id, its workload
 * <slug>-<name>, docs/environments.md) — the way a custom domain does (ProjectDomainManifests):
 * its Host rewritten to that workload's public host
 * (Middleware gw-host-<route>, PublicHostRewrites), into the region Traefik on web (Service
 * traefik:80 of the Serving namespace), where Knative's public route answers. A key / JWT route
 * passes the region's gateway-auth metering proxy on the way (see "Metering" below).
 *
 * The routes are rebuilt by every sync of the gateway and by region:reconcile when what RunR
 * would apply differs from what the region has (routingRevision(), GatewayRoutesStep).
 */
trait GatewayManifestTrait
{
    /** The gateway's default host, <subdomain>.<function domain>; null without a domain. */
    public function gatewayHost(Gateway $gateway): ?string
    {
        $domain = app(ProjectDomainService::class)->functionDomain($gateway->knativeRegion);

        return $domain ? "{$gateway->subdomain}.{$domain}" : null;
    }

    /** The gateway's custom domain, normalized; null without one. */
    public function customDomainHost(Gateway $gateway): ?string
    {
        $domain = trim((string) $gateway->custom_domain);

        return $domain !== '' ? DomainName::normalize($domain) : null;
    }

    /** The host the gateway's routes are published on: its custom domain, else its default host. */
    public function publicHost(Gateway $gateway): ?string
    {
        return $this->customDomainHost($gateway) ?? $this->gatewayHost($gateway);
    }

    public function customDomainRouteName(Gateway $gateway): string
    {
        return "gw-{$gateway->id}-domain";
    }

    /**
     * @param  array<string, string>  $publicHosts  route id → public host of its project
     *                                              (hostMiddlewareName()); a route without one is left out
     * @param  bool  $metered  key / JWT routes go through gateway-auth's metering proxy
     */
    public function buildIngressRoute(Gateway $gateway, array $publicHosts = [], bool $metered = false): array
    {
        $region = $gateway->knativeRegion;
        $namespace = $region->project_namespace ?? $region->name;

        return $this->ingressRouteFor($gateway, "gw-{$gateway->id}", (string) $this->gatewayHost($gateway), $namespace, $publicHosts, $metered);
    }

    /**
     * The IngressRoute of the gateway's custom domain (null without one): the same routes as the
     * default host, on websecure with the domain's certificate ($tlsSecretName, written by
     * cert-manager) or the region's default certificate.
     *
     * @param  array<string, string>  $publicHosts  as buildIngressRoute()
     */
    public function buildCustomDomainIngressRoute(Gateway $gateway, ?string $tlsSecretName, array $publicHosts = [], bool $metered = false): ?array
    {
        $host = $this->customDomainHost($gateway);
        if ($host === null) {
            return null;
        }

        $region = $gateway->knativeRegion;
        $namespace = $region->project_namespace ?? $region->name;

        $route = $this->ingressRouteFor($gateway, $this->customDomainRouteName($gateway), $host, $namespace, $publicHosts, $metered);
        $route['metadata']['annotations'] = ['runr.dev/domain' => $host];
        $route['spec']['tls'] = $tlsSecretName ? ['secretName' => $tlsSecretName] : new \stdClass;

        return $route;
    }

    /** cert-manager Certificate of the gateway's custom domain, into Secret gw-<id>-domain. */
    public function buildCustomDomainCertificate(Gateway $gateway, string $namespace, string $clusterIssuer): ?array
    {
        $host = $this->customDomainHost($gateway);
        if ($host === null) {
            return null;
        }

        $name = $this->customDomainRouteName($gateway);
        $labels = ['app.kubernetes.io/managed-by' => 'runr', 'runr/gateway-id' => $gateway->id];

        return [
            'apiVersion' => 'cert-manager.io/v1',
            'kind' => 'Certificate',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => $labels,
                'annotations' => ['runr.dev/domain' => $host],
            ],
            'spec' => [
                'secretName' => $name,
                'dnsNames' => [$host],
                'issuerRef' => ['name' => $clusterIssuer, 'kind' => 'ClusterIssuer', 'group' => 'cert-manager.io'],
                'secretTemplate' => ['labels' => $labels],
            ],
        ];
    }

    public function buildStripPrefixMiddleware(GatewayRoute $route, string $namespace): array
    {
        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'name' => "gw-strip-{$route->id}",
                'namespace' => $namespace,
            ],
            'spec' => [
                'stripPrefix' => [
                    'prefixes' => [$route->path],
                ],
            ],
        ];
    }

    public function hostMiddlewareName(GatewayRoute $route): string
    {
        return "gw-host-{$route->id}";
    }

    /**
     * The route's Host rewrite to its project's public host; the gateway host stays in
     * K-Original-Host.
     */
    public function buildHostMiddleware(GatewayRoute $route, string $namespace, string $publicHost): array
    {
        // the route's workload: the project's own, or an environment's (routeWorkload())
        $rewrite = PublicHostRewrites::metadata(($this->routeWorkload($route) ?? $route->project)->slug);

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'name' => $this->hostMiddlewareName($route),
                'namespace' => $namespace,
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'runr',
                    'runr/gateway-id' => (string) $route->gateway_id,
                ] + $rewrite['labels'],
                'annotations' => $rewrite['annotations'],
            ],
            'spec' => [
                'headers' => [
                    'customRequestHeaders' => [
                        'Host' => $publicHost,
                        'K-Original-Host' => (string) $this->gatewayHost($route->gateway),
                    ],
                ],
            ],
        ];
    }

    public function rateLimitMiddlewareName(Gateway $gateway): string
    {
        return "gw-ratelimit-{$gateway->id}";
    }

    /**
     * Optional Traefik rateLimit Middleware in front of every route of the gateway.
     * `average` requests per second with `burst` headroom (defaults to `average`); Traefik's
     * default source criterion applies (limit per client IP). Null when the gateway has no limit.
     */
    public function buildRateLimitMiddleware(Gateway $gateway, string $namespace): ?array
    {
        if (! $gateway->rate_limit_average) {
            return null;
        }

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'name' => $this->rateLimitMiddlewareName($gateway),
                'namespace' => $namespace,
                'labels' => [
                    'runr/gateway-id' => $gateway->id,
                ],
            ],
            'spec' => [
                'rateLimit' => [
                    'average' => (int) $gateway->rate_limit_average,
                    'burst' => (int) ($gateway->rate_limit_burst ?: $gateway->rate_limit_average),
                    'period' => '1s',
                ],
            ],
        ];
    }

    /**
     * An IngressRoute named $name with the gateway's routes on $host (noop without routes); a route
     * whose project has no public host ($publicHosts) is left out.
     *
     * @param  array<string, string>  $publicHosts
     */
    private function ingressRouteFor(Gateway $gateway, string $name, string $host, string $namespace, array $publicHosts, bool $metered = false): array
    {
        $rateLimit = $gateway->rate_limit_average ? $this->rateLimitMiddlewareName($gateway) : null;
        $servingNamespace = $gateway->knativeRegion->knative_serving_namespace ?: 'knative-serving';

        $routes = [];
        foreach ($gateway->routes()->with('project')->get() as $route) {
            if (isset($publicHosts[(string) $route->getKey()])) {
                $routes[] = $this->buildRouteRule($route, $host, $namespace, $servingNamespace, $rateLimit, $metered);
            }
        }

        if (empty($routes)) {
            $routes[] = [
                'match' => "Host(`{$host}`) && PathPrefix(`/`)",
                'kind' => 'Rule',
                'services' => [['name' => 'noop@internal', 'kind' => 'TraefikService']],
            ];
        }

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'IngressRoute',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => [
                    'runr/gateway-id' => $gateway->id,
                    'runr/user-id' => (string) $gateway->user_id,
                ],
            ],
            'spec' => [
                'entryPoints' => ['websecure'],
                'routes' => $routes,
            ],
        ];
    }

    /**
     * The forwardAuth Middleware of a key / JWT route (GatewayAuthManifests), none for a public one.
     *
     * @return list<array{name: string, namespace: string}>
     */
    public function authMiddlewareRefs(GatewayRoute $route, string $namespace): array
    {
        return $route->requiresAuth()
            ? [['name' => GatewayAuthManifests::middlewareName($route), 'namespace' => $namespace]]
            : [];
    }

    /**
     * The workload a route leads to: the project's own, or the environment's view of it when the
     * route leads to an environment (GatewayRoute::target(), docs/environments.md). Null when that
     * environment is gone: the route is left out.
     */
    public function routeWorkload(GatewayRoute $route): ?Project
    {
        return $route->target();
    }

    /**
     * Whether a route hands its calls straight to its Knative Service's own wrr service instead of
     * Knative's public route on web: access mode allowlist (the client's address is checked on the
     * first hop) or "only through the gateway" (cluster-local: no public route).
     */
    public function routeIsDirect(GatewayRoute $route): bool
    {
        return $this->routeWorkload($route)?->accessMode() === Project::ACCESS_ALLOWLIST || (bool) $route->gateway_only;
    }

    /** Traefik v3 rule of a route's methods: one Method() per method. */
    public static function methodMatch(array $methods): string
    {
        $methods = array_values(array_unique(array_map(fn ($m) => strtoupper((string) $m), $methods)));
        $parts = array_map(fn (string $m) => "Method(`{$m}`)", $methods);

        return count($parts) === 1 ? $parts[0] : '('.implode(' || ', $parts).')';
    }

    // ── Metering (docs/api-product.md#usage) ───────────────────────────────────
    //
    // A key / JWT route's rule hands its calls to gateway-auth's metering proxy, which hands them on
    // to the region Traefik on web unchanged. A route that goes to Knative's public route gets there
    // by its Host. A direct route (routeIsDirect()) has no public route there: its rule adds
    // X-Runr-Gateway-Upstream: <route>.<token> (Middleware gw-up-<route>), and the gateway's upstream
    // IngressRoute gw-<id>-upstream on web matches exactly that header — a value nobody outside RunR
    // knows (an HMAC of the route with the app key) — removes it and hands the call to the Service's
    // wrr service, as the route itself did before.

    public const UPSTREAM_HEADER = 'X-Runr-Gateway-Upstream';

    /** Above the access routes of access mode allowlist (100000), below the status block (999999) */
    public const UPSTREAM_ROUTE_PRIORITY = 200000;

    public function upstreamMiddlewareName(GatewayRoute $route): string
    {
        return "gw-up-{$route->id}";
    }

    public function upstreamRouteName(Gateway $gateway): string
    {
        return "gw-{$gateway->id}-upstream";
    }

    /** The upstream header's value of a route: <route id>.<HMAC of the route with the app key> */
    public function upstreamToken(GatewayRoute $route): string
    {
        return $route->id.'.'.substr(hash_hmac('sha256', 'gateway-upstream|'.$route->id, (string) config('app.key')), 0, 40);
    }

    /** The direct route's header, set after its check (the proxy passes it on). */
    public function buildUpstreamMiddleware(GatewayRoute $route, string $namespace): array
    {
        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'name' => $this->upstreamMiddlewareName($route),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr/gateway-id' => (string) $route->gateway_id, 'runr/component' => 'gateway-upstream'],
            ],
            'spec' => ['headers' => ['customRequestHeaders' => [self::UPSTREAM_HEADER => $this->upstreamToken($route)]]],
        ];
    }

    /** The upstream IngressRoute's Middleware: the header goes before the function sees it. */
    public function buildUpstreamCleanMiddleware(Gateway $gateway, string $namespace): array
    {
        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'Middleware',
            'metadata' => [
                'name' => $this->upstreamRouteName($gateway),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr/gateway-id' => (string) $gateway->id, 'runr/component' => 'gateway-upstream'],
            ],
            // an empty value removes the header
            'spec' => ['headers' => ['customRequestHeaders' => [self::UPSTREAM_HEADER => '']]],
        ];
    }

    /**
     * The direct routes the metering proxy hands on (metered key / JWT routes with a public host).
     *
     * @param  array<string, string>  $publicHosts
     * @return list<GatewayRoute>
     */
    public function meteredDirectRoutes(Gateway $gateway, array $publicHosts, bool $metered): array
    {
        if (! $metered) {
            return [];
        }

        $direct = [];
        foreach ($gateway->routes()->with('project')->get() as $route) {
            /** @var GatewayRoute $route */
            if (isset($publicHosts[(string) $route->getKey()]) && $route->requiresAuth() && $this->routeIsDirect($route)) {
                $direct[] = $route;
            }
        }

        return $direct;
    }

    /**
     * The gateway's upstream IngressRoute on web (null without a metered direct route): one rule per
     * such route, matched by its header only.
     *
     * @param  array<string, string>  $publicHosts
     */
    public function buildUpstreamIngressRoute(Gateway $gateway, array $publicHosts, bool $metered): ?array
    {
        $routes = $this->meteredDirectRoutes($gateway, $publicHosts, $metered);
        if ($routes === []) {
            return null;
        }

        $region = $gateway->knativeRegion;
        $namespace = $region->project_namespace ?? $region->name;
        $rules = [];
        foreach ($routes as $route) {
            $rules[] = [
                'match' => 'Header(`'.self::UPSTREAM_HEADER.'`, `'.$this->upstreamToken($route).'`)',
                'kind' => 'Rule',
                'priority' => self::UPSTREAM_ROUTE_PRIORITY,
                'middlewares' => [['name' => $this->upstreamRouteName($gateway), 'namespace' => $namespace]],
                'services' => [['name' => InternalNetworkManifests::wrrService($namespace, ($this->routeWorkload($route) ?? $route->project)->slug), 'kind' => 'TraefikService']],
            ];
        }

        return [
            'apiVersion' => 'traefik.io/v1alpha1',
            'kind' => 'IngressRoute',
            'metadata' => [
                'name' => $this->upstreamRouteName($gateway),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr/gateway-id' => (string) $gateway->id, 'runr/component' => 'gateway-upstream'],
            ],
            'spec' => [
                'entryPoints' => ['web'],
                'routes' => $rules,
            ],
        ];
    }

    private function buildRouteRule(GatewayRoute $route, string $host, string $namespace, string $servingNamespace, ?string $rateLimitMiddleware = null, bool $metered = false): array
    {
        $match = "Host(`{$host}`) && PathPrefix(`{$route->path}`)";

        if (! empty($route->methods)) {
            $match .= ' && '.self::methodMatch($route->methods);
        }

        // the project's own workload, or the environment's the route leads to (docs/environments.md)
        $workload = $this->routeWorkload($route) ?? $route->project;

        $rule = [
            'match' => $match,
            'kind' => 'Rule',
            // the project's public route, on web
            'services' => [[
                'name' => KnativeConstants::TRAEFIK_RELEASE,
                'namespace' => $servingNamespace,
                'port' => 80,
            ]],
        ];

        $middlewares = [];
        if ($rateLimitMiddleware) {
            $middlewares[] = ['name' => $rateLimitMiddleware, 'namespace' => $namespace];
        }
        // API product: key / JWT routes ask the region's gateway-auth first (before the prefix is stripped)
        array_push($middlewares, ...$this->authMiddlewareRefs($route, $namespace));
        if ($route->strip_prefix) {
            $middlewares[] = ['name' => "gw-strip-{$route->id}", 'namespace' => $namespace];
        }
        $middlewares[] = ['name' => $this->hostMiddlewareName($route), 'namespace' => $namespace];
        $rule['middlewares'] = $middlewares;

        // Access mode allowlist: the client's address is checked on this hop (InternalNetworkManifests)
        if ($workload->accessMode() === Project::ACCESS_ALLOWLIST) {
            $restricted = InternalNetworkManifests::restrictRoute(['spec' => ['routes' => [$rule]]], $namespace, $workload->slug);
            $rule = $restricted['spec']['routes'][0];
        } elseif ($route->gateway_only) {
            // "Only through the gateway" (docs/api-product.md): the Knative Service is cluster-local,
            // Knative's public route on web does not serve it — the route goes straight to the
            // Service's own wrr service, as access mode allowlist does
            $rule['services'] = [['name' => InternalNetworkManifests::wrrService($namespace, $workload->slug), 'kind' => 'TraefikService']];
        }

        // API product: a key / JWT route's calls pass the metering proxy, after every middleware
        // (a direct route names its wrr service in a header the upstream IngressRoute matches)
        if ($metered && $route->requiresAuth()) {
            if ($this->routeIsDirect($route)) {
                $rule['middlewares'][] = ['name' => $this->upstreamMiddlewareName($route), 'namespace' => $namespace];
            }
            $rule['services'] = [GatewayAuthManifests::proxyServiceRef($namespace)];
        }

        return $rule;
    }
}
