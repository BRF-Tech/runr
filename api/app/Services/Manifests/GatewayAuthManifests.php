<?php

namespace App\Services\Manifests;

use App\Models\GatewayRoute;
use App\Services\Contracts\KnativeConstants;

/**
 * Kubernetes objects of the API product in a region's project namespace (docs/api-product.md):
 *
 *   Secret      runr-gateway-auth   RunR's URL, the region id, the service's token, Redis URL
 *   Deployment  runr-gateway-auth   runtime/gateway-auth: checks keys / JWTs, limits, counts
 *   Service     runr-gateway-auth   :80 → 8080 (the checks)
 *   Service     runr-gateway-auth-proxy  :80 → 8081, the metering proxy (ready pods only)
 *   Middleware  gw-auth-<route>     Traefik forwardAuth of one key / JWT route → /auth/<route>
 *
 * Metering (docs/api-product.md#usage): a key / JWT route whose rule hands its calls to
 * runr-gateway-auth-proxy (GatewayManifestTrait) asks /auth/<route>?meter=1; the 200 then carries
 * X-Runr-Gateway-Ticket — what the check decided, signed — which Traefik copies to the call (it is
 * in authResponseHeaders, so a caller cannot send one). The proxy serves only calls with a valid
 * ticket, hands them on to the region Traefik (UPSTREAM_URL: Service traefik:80 of the Knative
 * Serving namespace, where the route's own upstream answers) and reports what the function
 * answered under the ticket's consumer, key and route: status class, latency, bytes.
 *
 * Traefik asks /auth/<route> before every call of the route (X-Forwarded-Method / -Uri / -Host);
 * a 2xx lets the call through, with the headers named in authResponseHeaders copied from the
 * answer to the upstream request — and removed from it when the answer has none, so a caller can
 * neither forge X-Runr-Consumer nor, on key routes, hand its API key to the function (X-Api-Key and
 * Authorization are listed there). Any other answer (401 / 403 / 429 with RateLimit-* headers)
 * goes back to the caller as it is.
 *
 * Fail closed: while gateway-auth is not installed or not ready, gw-auth-<route> asks the region's
 * status responder instead (refuseMiddleware(), /auth-unavailable: 503 auth_unavailable) — the
 * IngressRoute references the same Middleware in both states, so a key / JWT route never serves
 * a call nobody checked. The Service publishes pods that are not ready yet: a pod still waiting
 * for its configuration answers 503 not_ready itself instead of Traefik's bare 500.
 */
class GatewayAuthManifests
{
    public const NAME = 'runr-gateway-auth';

    public const TRAEFIK_API = 'traefik.io/v1alpha1';

    public const PORT = 8080;

    /** The metering proxy: its Service and container port */
    public const PROXY_NAME = 'runr-gateway-auth-proxy';

    public const PROXY_PORT = 8081;

    /** What the check decided on an allowed call of a metered route, signed for the proxy */
    public const TICKET_HEADER = 'X-Runr-Gateway-Ticket';

    /** Headers the service sets on a call it lets through. */
    public const UPSTREAM_HEADERS = ['X-Runr-Consumer', 'X-Runr-Consumer-External', 'X-Runr-Key', 'X-Runr-Subject', self::TICKET_HEADER];

    /** Where the proxy hands the calls on: the region Traefik's web entry point. */
    public static function upstreamUrl(string $servingNamespace): string
    {
        return 'http://'.KnativeConstants::TRAEFIK_RELEASE.'.'.$servingNamespace.'.svc.cluster.local';
    }

    public static function middlewareName(GatewayRoute|string $route): string
    {
        return 'gw-auth-'.(is_string($route) ? $route : $route->id);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'runr',
            'app.kubernetes.io/name' => self::NAME,
            'runr/component' => 'gateway-auth',
        ];
    }

    /**
     * @param  array{RUNR_API_URL: string, RUNR_REGION_ID: string, RUNR_TOKEN: string, REDIS_URL?: string}  $data
     */
    public static function secret(string $namespace, array $data): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => self::labels()],
            'type' => 'Opaque',
            'data' => array_map(fn ($v) => base64_encode((string) $v), $data),
        ];
    }

    /**
     * @param  string  $revision  changes with the Secret's data: the pods restart and read it again
     * @param  string|null  $upstreamUrl  where the metering proxy hands calls on (upstreamUrl())
     */
    public static function deployment(string $namespace, string $image, int $replicas, string $revision, int $syncSeconds, int $flushSeconds, ?string $upstreamUrl = null): array
    {
        $env = [
            ['name' => 'LISTEN', 'value' => ':'.self::PORT],
            ['name' => 'SYNC_SECONDS', 'value' => (string) $syncSeconds],
            ['name' => 'FLUSH_SECONDS', 'value' => (string) $flushSeconds],
            ['name' => 'POD_NAME', 'valueFrom' => ['fieldRef' => ['fieldPath' => 'metadata.name']]],
        ];
        $ports = [['name' => 'http', 'containerPort' => self::PORT]];
        if ($upstreamUrl !== null) {
            $env[] = ['name' => 'PROXY_LISTEN', 'value' => ':'.self::PROXY_PORT];
            $env[] = ['name' => 'UPSTREAM_URL', 'value' => $upstreamUrl];
            // named: the proxy Service reaches only pods that have it (never those of an earlier image)
            $ports[] = ['name' => 'proxy', 'containerPort' => self::PROXY_PORT];
        }

        $deployment = [
            'apiVersion' => 'apps/v1',
            'kind' => 'Deployment',
            'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => [
                'replicas' => $replicas,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => self::NAME]],
                'template' => [
                    'metadata' => [
                        'labels' => self::labels(),
                        'annotations' => ['runr.sh/config-revision' => $revision],
                    ],
                    'spec' => [
                        'automountServiceAccountToken' => false,
                        // calls still going through the proxy get the time to end (it waits up to 25 s)
                        'terminationGracePeriodSeconds' => 30,
                        'securityContext' => [
                            'runAsNonRoot' => true,
                            'runAsUser' => 65532,
                            'seccompProfile' => ['type' => 'RuntimeDefault'],
                        ],
                        'containers' => [[
                            'name' => 'gateway-auth',
                            'image' => $image,
                            'ports' => $ports,
                            'envFrom' => [['secretRef' => ['name' => self::NAME]]],
                            'env' => $env,
                            'readinessProbe' => [
                                'httpGet' => ['path' => '/readyz', 'port' => 'http'],
                                'periodSeconds' => 5,
                            ],
                            'livenessProbe' => [
                                'httpGet' => ['path' => '/healthz', 'port' => 'http'],
                                'periodSeconds' => 20,
                            ],
                            // the metering proxy streams the routes' calls through the pod
                            'resources' => [
                                'requests' => ['cpu' => '50m', 'memory' => '64Mi'],
                                'limits' => ['cpu' => '1', 'memory' => '256Mi'],
                            ],
                            'securityContext' => [
                                'allowPrivilegeEscalation' => false,
                                'readOnlyRootFilesystem' => true,
                                'capabilities' => ['drop' => ['ALL']],
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        // what region:reconcile compares (GatewayAuthStep): a release that changes the spec — not
        // only the image — applies it again
        $deployment['metadata']['annotations'] = [self::SPEC_ANNOTATION => self::specRevision($deployment['spec'])];

        return $deployment;
    }

    /** Annotation of the Deployment: the hash of the spec RunR applied. */
    public const SPEC_ANNOTATION = 'runr.sh/spec-revision';

    public static function specRevision(array $spec): string
    {
        return substr(hash('sha256', (string) json_encode($spec, JSON_UNESCAPED_SLASHES)), 0, 16);
    }

    /**
     * The metering proxy's Service: ready pods only (a starting pod has no configuration yet), and
     * only pods with the named port "proxy" — never those of an earlier image without it.
     */
    public static function proxyService(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => self::PROXY_NAME, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => [
                'selector' => ['app.kubernetes.io/name' => self::NAME],
                'ports' => [['name' => 'http', 'port' => 80, 'targetPort' => 'proxy']],
            ],
        ];
    }

    /** The metering proxy as a route rule's service (GatewayManifestTrait). */
    public static function proxyServiceRef(string $namespace): array
    {
        return ['name' => self::PROXY_NAME, 'namespace' => $namespace, 'port' => 80];
    }

    public static function service(string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Service',
            'metadata' => ['name' => self::NAME, 'namespace' => $namespace, 'labels' => self::labels()],
            'spec' => [
                'selector' => ['app.kubernetes.io/name' => self::NAME],
                'ports' => [['name' => 'http', 'port' => 80, 'targetPort' => self::PORT]],
                // a starting pod answers 503 not_ready (no endpoints: Traefik's empty 500)
                'publishNotReadyAddresses' => true,
            ],
        ];
    }

    /**
     * The forwardAuth Middleware of a key / JWT route while gateway-auth cannot check its calls:
     * it asks $responderUrl (the region's status responder, /auth-unavailable), whose 503 goes
     * back to the caller. Same name as forwardAuthMiddleware(): the IngressRoute does not change.
     */
    public static function refuseMiddleware(GatewayRoute $route, string $namespace, string $responderUrl): array
    {
        $middleware = self::forwardAuthMiddleware($route, $namespace);
        $middleware['metadata']['labels']['runr/gateway-auth-state'] = 'refusing';
        $middleware['spec']['forwardAuth'] = [
            'address' => $responderUrl,
            'trustForwardHeader' => false,
            // not empty (= every header): the caller's key or token never reaches the responder
            'authRequestHeaders' => ['X-Forwarded-Uri'],
            'authResponseHeaders' => $middleware['spec']['forwardAuth']['authResponseHeaders'],
        ];

        return $middleware;
    }

    /**
     * The forwardAuth Middleware of a key / JWT route. $metered: the route's calls go through the
     * metering proxy, so the check hands each allowed one a ticket (?meter=1).
     */
    public static function forwardAuthMiddleware(GatewayRoute $route, string $namespace, bool $metered = false): array
    {
        $responseHeaders = self::UPSTREAM_HEADERS;
        if ($route->auth_mode === GatewayRoute::AUTH_KEY) {
            // the key stays with the gateway: removed from what the function receives
            $responseHeaders[] = 'X-Api-Key';
            $responseHeaders[] = 'Authorization';
        }

        return [
            'apiVersion' => self::TRAEFIK_API,
            'kind' => 'Middleware',
            'metadata' => [
                'name' => self::middlewareName($route),
                'namespace' => $namespace,
                'labels' => [
                    'app.kubernetes.io/managed-by' => 'runr',
                    'runr/gateway-id' => $route->gateway_id,
                    'runr/component' => 'gateway-auth',
                ],
            ],
            'spec' => [
                'forwardAuth' => [
                    'address' => 'http://'.self::NAME.'.'.$namespace.'.svc.cluster.local/auth/'.$route->id.($metered ? '?meter=1' : ''),
                    'trustForwardHeader' => false,
                    'authRequestHeaders' => ['Authorization', 'X-Api-Key'],
                    'authResponseHeaders' => $responseHeaders,
                ],
            ],
        ];
    }
}
