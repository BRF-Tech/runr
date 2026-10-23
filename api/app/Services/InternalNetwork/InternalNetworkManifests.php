<?php

namespace App\Services\InternalNetwork;

use App\Models\Project;
use App\Services\Helpers\PrivateIngress;
use App\Services\Manifests\ProjectDomainManifests;

/**
 * Pure manifest builders of the internal network (docs/internal-network.md).
 *
 * Per Knative Service (project namespace):
 *   labels / annotations       runr.dev/internal-network, -name, -org, -callers, -kind: its policy,
 *                              which the authz reads (no new revision: metadata only)
 *   IngressRoute runr-internal-<ksvc>   entry point internal, Host(<project>.<org>.runr.internal)
 *                              → the Knative provider's TraefikService <ns>-<ksvc>-rule-0-path-0-wrr
 *                              (the provider applies no middleware annotation of its own, and the
 *                              split headers the activator routes by are part of the wrr service)
 *   IngressRoutes runr-access-<ksvc>(-tls), Middleware runr-access-<ksvc>   access mode allowlist:
 *                              the public host on web and websecure with an ipAllowList, above
 *                              Knative's route, to the same wrr service
 *
 * Per Serving namespace: the authz (the region agent in authz mode: Deployment, Service, PDB,
 * ServiceAccount and RBAC runr-internal-auth), its ConfigMap and Secret runr-internal-network, and
 * the two entry-point Middlewares of the private entry point — the ForwardAuth and the removal of
 * the token header.
 */
final class InternalNetworkManifests
{
    public const LABEL_MANAGED = 'runr.dev/internal-network';

    public const ANNOTATION_NAME = 'runr.dev/internal-name';

    public const ANNOTATION_ORG = 'runr.dev/internal-org';

    public const ANNOTATION_CALLERS = 'runr.dev/internal-callers';

    public const ANNOTATION_KIND = 'runr.dev/internal-kind';

    public const ANNOTATION_ACCESS = 'runr.dev/access-mode';

    public const VISIBILITY_LABEL = 'networking.knative.dev/visibility';

    public const AUTHZ = 'runr-internal-auth';

    public const STRIP = 'runr-internal-strip';

    /** ConfigMap (mode.<namespace>, cross_tenant) and Secret (key.<region id>) of the authz */
    public const CONFIG = 'runr-internal-network';

    public const AUTHZ_PORT = 8090;

    /** Above Knative's public route, below the status block (999999) */
    public const ACCESS_ROUTE_PRIORITY = 100000;

    // ── Per Knative Service ─────────────────────────────────────────────────

    /**
     * Metadata of a project's Knative Service on the internal network.
     *
     * @param  list<string>  $callers
     * @return array{labels: array<string, string>, annotations: array<string, string>}
     */
    public static function projectMetadata(string $name, array $callers, string $accessMode): array
    {
        return [
            'labels' => [self::LABEL_MANAGED => 'managed'],
            'annotations' => [
                self::ANNOTATION_NAME => $name,
                self::ANNOTATION_ORG => InternalNetwork::orgOf($name),
                self::ANNOTATION_CALLERS => implode(',', $callers),
                self::ANNOTATION_KIND => 'project',
                self::ANNOTATION_ACCESS => $accessMode,
            ],
        ];
    }

    /**
     * Metadata of a model service's Knative Service: its callers are the projects bound to it.
     *
     * @param  list<string>  $callers
     */
    public static function modelMetadata(string $name, array $callers): array
    {
        return [
            'labels' => [self::LABEL_MANAGED => 'managed'],
            'annotations' => [
                self::ANNOTATION_NAME => $name,
                self::ANNOTATION_ORG => InternalNetwork::orgOf($name),
                self::ANNOTATION_CALLERS => implode(',', $callers),
                self::ANNOTATION_KIND => 'model',
            ],
        ];
    }

    /** The Knative provider's weighted service of a Knative Service's route */
    public static function wrrService(string $namespace, string $service): string
    {
        return "{$namespace}-{$service}-rule-0-path-0-wrr@knative";
    }

    public static function internalRouteName(string $service): string
    {
        return 'runr-internal-'.$service;
    }

    public static function accessName(string $service): string
    {
        return 'runr-access-'.$service;
    }

    /** <project>.<org>.runr.internal on the private entry point */
    public static function internalRoute(string $namespace, string $service, string $name, array $labels = []): array
    {
        return [
            'apiVersion' => ProjectDomainManifests::TRAEFIK_API,
            'kind' => 'IngressRoute',
            'metadata' => [
                'name' => self::internalRouteName($service),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', self::LABEL_MANAGED => 'managed'] + $labels,
                'annotations' => [self::ANNOTATION_NAME => $name],
            ],
            'spec' => [
                'entryPoints' => [PrivateIngress::ENTRYPOINT],
                'routes' => [[
                    'match' => 'Host(`'.InternalNetwork::hostFor($name).'`)',
                    'kind' => 'Rule',
                    'services' => [['name' => self::wrrService($namespace, $service), 'kind' => 'TraefikService']],
                ]],
            ],
        ];
    }

    /**
     * The ipAllowList of access mode allowlist. Behind a load balancer the region trusts with
     * X-Forwarded-For (ingress_trusted_ips) the client is the first address from the right that
     * is not one of them; otherwise the connection's own address.
     *
     * @param  list<string>  $cidrs
     * @param  list<string>  $trustedIps
     */
    public static function allowListMiddleware(string $namespace, string $service, array $cidrs, array $trustedIps = [], array $labels = []): array
    {
        $allow = ['sourceRange' => $cidrs];
        if ($trustedIps !== []) {
            $allow['ipStrategy'] = ['excludedIPs' => $trustedIps];
        }

        return [
            'apiVersion' => ProjectDomainManifests::TRAEFIK_API,
            'kind' => 'Middleware',
            'metadata' => [
                'name' => self::accessName($service),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'] + $labels,
            ],
            'spec' => ['ipAllowList' => $allow],
        ];
    }

    /**
     * The public host through the ipAllowList, above Knative's public route: one IngressRoute on
     * web and one with TLS on websecure (a router with TLS answers HTTPS only — on web the
     * request would reach Knative's own route).
     *
     * @return list<array>
     */
    public static function accessRoutes(string $namespace, string $service, string $publicHost, array $labels = []): array
    {
        $route = fn (bool $secure) => [
            'apiVersion' => ProjectDomainManifests::TRAEFIK_API,
            'kind' => 'IngressRoute',
            'metadata' => [
                'name' => $secure ? ProjectDomainManifests::secureRouteName(self::accessName($service)) : self::accessName($service),
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'] + $labels,
                'annotations' => [ProjectDomainManifests::ANNOTATION_DOMAIN => $publicHost],
            ],
            'spec' => [
                'entryPoints' => [$secure ? 'websecure' : 'web'],
                'routes' => [[
                    'match' => "Host(`{$publicHost}`)",
                    'kind' => 'Rule',
                    'priority' => self::ACCESS_ROUTE_PRIORITY,
                    'middlewares' => [['name' => self::accessName($service), 'namespace' => $namespace]],
                    'services' => [['name' => self::wrrService($namespace, $service), 'kind' => 'TraefikService']],
                ]],
            ] + ($secure ? ['tls' => new \stdClass] : []),
        ];

        return [$route(false), $route(true)];
    }

    /**
     * A custom-domain or gateway route of a project in access mode allowlist: its ipAllowList
     * (runr-access-<ksvc>) first, and the Knative Service's own wrr service instead of a second
     * pass through web, where the client's address would be Traefik's.
     */
    public static function restrictRoute(array $route, string $namespace, string $service): array
    {
        foreach ($route['spec']['routes'] as $i => $rule) {
            $rule['middlewares'] = array_merge(
                [['name' => self::accessName($service), 'namespace' => $namespace]],
                $rule['middlewares'] ?? [],
            );
            $rule['services'] = [['name' => self::wrrService($namespace, $service), 'kind' => 'TraefikService']];
            $route['spec']['routes'][$i] = $rule;
        }

        return $route;
    }

    /** Labels of the per-project objects (the teardown and the tenant sweep find them) */
    public static function projectLabels(Project $project): array
    {
        return ['runr/project-id' => (string) $project->id];
    }

    // ── Per Serving namespace ───────────────────────────────────────────────

    /** The entry-point middlewares of the private entry point (ports.internal.http.middlewares) */
    public static function entryPointMiddlewares(string $servingNamespace): array
    {
        return [
            "{$servingNamespace}-".self::AUTHZ.'@kubernetescrd',
            "{$servingNamespace}-".self::STRIP.'@kubernetescrd',
        ];
    }

    /** @return list<array> ServiceAccount, ClusterRole + binding, Role + binding, Deployment, Service, PDB */
    public static function authz(string $namespace, string $image): array
    {
        $labels = ['app.kubernetes.io/name' => self::AUTHZ, 'app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/part-of' => 'runr'];
        $meta = fn (string $kind) => ['name' => self::AUTHZ, 'namespace' => $namespace, 'labels' => $labels];

        return [
            ['apiVersion' => 'v1', 'kind' => 'ServiceAccount', 'metadata' => $meta('ServiceAccount')],
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'ClusterRole',
                'metadata' => ['name' => self::AUTHZ, 'labels' => $labels],
                'rules' => [
                    ['apiGroups' => ['serving.knative.dev'], 'resources' => ['services'], 'verbs' => ['get', 'list', 'watch']],
                    // a caller without a token is known by its pod (runr/project-id, its address)
                    ['apiGroups' => [''], 'resources' => ['pods'], 'verbs' => ['get', 'list', 'watch']],
                ],
            ],
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'ClusterRoleBinding',
                'metadata' => ['name' => self::AUTHZ, 'labels' => $labels],
                'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'ClusterRole', 'name' => self::AUTHZ],
                'subjects' => [['kind' => 'ServiceAccount', 'name' => self::AUTHZ, 'namespace' => $namespace]],
            ],
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'Role',
                'metadata' => $meta('Role'),
                'rules' => [
                    ['apiGroups' => [''], 'resources' => ['configmaps', 'secrets'], 'resourceNames' => [self::CONFIG], 'verbs' => ['get']],
                ],
            ],
            [
                'apiVersion' => 'rbac.authorization.k8s.io/v1',
                'kind' => 'RoleBinding',
                'metadata' => $meta('RoleBinding'),
                'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'Role', 'name' => self::AUTHZ],
                'subjects' => [['kind' => 'ServiceAccount', 'name' => self::AUTHZ, 'namespace' => $namespace]],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => $meta('Deployment'),
                'spec' => [
                    // every internal call waits for it: two, never both down for a rollout
                    'replicas' => 2,
                    'selector' => ['matchLabels' => ['app.kubernetes.io/name' => self::AUTHZ]],
                    'strategy' => ['type' => 'RollingUpdate', 'rollingUpdate' => ['maxUnavailable' => 0, 'maxSurge' => 1]],
                    'template' => [
                        'metadata' => ['labels' => $labels],
                        'spec' => [
                            'serviceAccountName' => self::AUTHZ,
                            'affinity' => ['podAntiAffinity' => ['preferredDuringSchedulingIgnoredDuringExecution' => [[
                                'weight' => 100,
                                'podAffinityTerm' => [
                                    'labelSelector' => ['matchLabels' => ['app.kubernetes.io/name' => self::AUTHZ]],
                                    'topologyKey' => 'kubernetes.io/hostname',
                                ],
                            ]]]],
                            'containers' => [[
                                'name' => 'authz',
                                'image' => $image,
                                'env' => [
                                    ['name' => 'RUNR_AGENT_MODE', 'value' => 'authz'],
                                    ['name' => 'RUNR_INTERNAL_NAMESPACE', 'value' => $namespace],
                                    ['name' => 'RUNR_AUTHZ_ADDR', 'value' => ':'.self::AUTHZ_PORT],
                                ],
                                'ports' => [['name' => 'http', 'containerPort' => self::AUTHZ_PORT]],
                                'readinessProbe' => ['httpGet' => ['path' => '/healthz', 'port' => self::AUTHZ_PORT], 'periodSeconds' => 5],
                                'livenessProbe' => ['httpGet' => ['path' => '/healthz', 'port' => self::AUTHZ_PORT], 'periodSeconds' => 20, 'failureThreshold' => 6],
                                'resources' => [
                                    'requests' => ['cpu' => '50m', 'memory' => '64Mi'],
                                    'limits' => ['cpu' => '500m', 'memory' => '128Mi'],
                                ],
                            ]],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => $meta('Service'),
                'spec' => [
                    'selector' => ['app.kubernetes.io/name' => self::AUTHZ],
                    'ports' => [['name' => 'http', 'port' => self::AUTHZ_PORT, 'targetPort' => self::AUTHZ_PORT]],
                ],
            ],
            [
                'apiVersion' => 'policy/v1',
                'kind' => 'PodDisruptionBudget',
                'metadata' => $meta('PodDisruptionBudget'),
                'spec' => ['minAvailable' => 1, 'selector' => ['matchLabels' => ['app.kubernetes.io/name' => self::AUTHZ]]],
            ],
        ];
    }

    /**
     * The ForwardAuth and the removal of the token header — the callee never sees the caller's
     * token (it could replay it), it gets the verified caller in X-Runr-Caller.
     *
     * @return list<array>
     */
    public static function entryPointMiddlewareManifests(string $namespace): array
    {
        $labels = ['app.kubernetes.io/managed-by' => 'runr', 'app.kubernetes.io/part-of' => 'runr'];

        return [
            [
                'apiVersion' => ProjectDomainManifests::TRAEFIK_API,
                'kind' => 'Middleware',
                'metadata' => ['name' => self::AUTHZ, 'namespace' => $namespace, 'labels' => $labels],
                'spec' => ['forwardAuth' => [
                    'address' => 'http://'.self::AUTHZ.".{$namespace}.svc.cluster.local:".self::AUTHZ_PORT.'/authz',
                    'authResponseHeaders' => [InternalNetwork::CALLER_HEADER],
                    'trustForwardHeader' => false,
                ]],
            ],
            [
                'apiVersion' => ProjectDomainManifests::TRAEFIK_API,
                'kind' => 'Middleware',
                'metadata' => ['name' => self::STRIP, 'namespace' => $namespace, 'labels' => $labels],
                'spec' => ['headers' => ['customRequestHeaders' => [InternalNetwork::HEADER => '']]],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $modes  project namespace → enforce | audit
     */
    public static function configMap(string $namespace, array $modes, bool $crossTenant): array
    {
        $data = ['cross_tenant' => $crossTenant ? 'true' : 'false'];
        foreach ($modes as $ns => $mode) {
            $data["mode.{$ns}"] = $mode;
        }

        return [
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => ['name' => self::CONFIG, 'namespace' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            'data' => $data,
        ];
    }

    // ── CoreDNS ─────────────────────────────────────────────────────────────

    public const COREDNS_BEGIN = '# runr-internal-network begin';

    public const COREDNS_END = '# runr-internal-network end';

    /** Rewrite rule: every <project>.<org>.runr.internal is the private entry point's Service */
    public static function corednsRewrite(string $servingNamespace, string $indent = '    '): string
    {
        $target = PrivateIngress::SERVICE.".{$servingNamespace}.svc.cluster.local.";

        return $indent.self::COREDNS_BEGIN."\n"
            .$indent."rewrite stop {\n"
            .$indent.'    name regex ^[a-z0-9-]+\.[a-z0-9-]+\.runr\.internal\.$ '.$target."\n"
            .$indent."    answer auto\n"
            .$indent."}\n"
            .$indent.self::COREDNS_END."\n";
    }

    /**
     * The Corefile with the rewrite in its first server block (before the kubernetes plugin),
     * replacing an earlier one; null when the Corefile has no kubernetes plugin to put it before.
     */
    public static function corefileWith(string $corefile, string $servingNamespace): ?string
    {
        $corefile = self::corefileWithout($corefile);
        if (! preg_match('/^([ \t]*)kubernetes\s/m', $corefile, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $at = $m[0][1];

        return substr($corefile, 0, $at).self::corednsRewrite($servingNamespace, $m[1][0]).substr($corefile, $at);
    }

    /** The Corefile without RunR's rewrite */
    public static function corefileWithout(string $corefile): string
    {
        return (string) preg_replace('/^[ \t]*'.preg_quote(self::COREDNS_BEGIN, '/').'\n.*?^[ \t]*'.preg_quote(self::COREDNS_END, '/').'\n/ms', '', $corefile);
    }
}
