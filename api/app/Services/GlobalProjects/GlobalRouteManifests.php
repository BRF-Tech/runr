<?php

namespace App\Services\GlobalProjects;

use App\Services\GlobalDns\GlobalTlsManifests;
use App\Services\InternalNetwork\InternalNetworkManifests;
use App\Services\Manifests\ProjectDomainManifests;

/**
 * The routes of a global project in one of its regions (docs/global-projects.md), in the region's
 * project namespace: <slug>.<zone> is routed like a custom domain (ProjectDomainManifests) —
 *
 *   Middleware    runr-global-<hash>      Host → the public host, K-Original-Host = <slug>.<zone>
 *   IngressRoute  runr-global-<hash>      web        Host(`<slug>.<zone>`) → Middleware → Traefik
 *   IngressRoute  runr-global-<hash>-tls  websecure  the same, with the zone's wildcard Secret
 *
 * — and, once per region, the health route PowerDNS' ifurlup() checks every region through:
 *
 *   IngressRoute  runr-global-health      web  Host(`runr-health.<zone>`) && PathPrefix(`/global-health/`)
 *                                         → Service runr-agent-health of the region agent
 *
 * The request re-enters the region Traefik with the Knative Service's public host (as a custom
 * domain does: PublicHostRewrites; cluster-local hosts are served on the private entry point
 * only). In access mode allowlist the HTTP and HTTPS routes check the client's address first and
 * go straight to the Knative Service (InternalNetworkManifests::restrictRoute); a project in
 * access mode internal has no global routes (GlobalRouteService).
 */
final class GlobalRouteManifests
{
    public const LABEL_GLOBAL = 'runr/global-project';

    public const HEALTH_ROUTE = 'runr-global-health';

    /** Service of the region agent's global-health listener (KnativeAgentTrait). */
    public const AGENT_HEALTH_SERVICE = 'runr-agent-health';

    public static function resourceName(string $projectId): string
    {
        return 'runr-global-'.substr(sha1($projectId), 0, 20);
    }

    /** @return array<string, string> */
    public static function labels(string $projectId, string $host): array
    {
        return ProjectDomainManifests::labels($projectId, $host) + [self::LABEL_GLOBAL => 'true'];
    }

    /**
     * Middleware + HTTP IngressRoute + HTTPS IngressRoute of one project in one region.
     *
     * @return list<array>
     */
    public static function projectRoutes(
        string $projectId,
        string $slug,
        string $host,
        string $namespace,
        string $traefikService,
        string $traefikNamespace,
        bool $withCertificate,
        string $publicHost,
        bool $allowList = false,
    ): array {
        $name = self::resourceName($projectId);
        $labels = self::labels($projectId, $host);

        $manifests = [
            ProjectDomainManifests::hostRewriteMiddleware($name, $namespace, $projectId, $host, $slug, $publicHost),
            ProjectDomainManifests::ingressRoute($name, $namespace, $projectId, $host, false, null, $traefikService, $traefikNamespace),
            ProjectDomainManifests::ingressRoute($name, $namespace, $projectId, $host, true, $withCertificate ? GlobalTlsManifests::CERTIFICATE : null, $traefikService, $traefikNamespace),
        ];

        foreach ($manifests as &$manifest) {
            $manifest['metadata']['labels'] = $labels + ($manifest['metadata']['labels'] ?? []);
        }
        unset($manifest);

        // Access mode allowlist: the client's address is checked on this first hop
        if ($allowList) {
            foreach ([1, 2] as $i) {
                $manifests[$i] = InternalNetworkManifests::restrictRoute($manifests[$i], $namespace, $slug);
            }
        }

        return $manifests;
    }

    /**
     * The region's health route: http://<health host>/global-health/<slug> to the region agent,
     * which answers for the Knative Services of its project namespace only.
     */
    public static function healthRoute(string $healthHost, string $namespace, string $agentService, string $agentNamespace, int $agentPort): array
    {
        return [
            'apiVersion' => ProjectDomainManifests::TRAEFIK_API,
            'kind' => 'IngressRoute',
            'metadata' => [
                'name' => self::HEALTH_ROUTE,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', self::LABEL_GLOBAL => 'health'],
            ],
            'spec' => [
                'entryPoints' => ['web'],
                'routes' => [[
                    'match' => "Host(`{$healthHost}`) && PathPrefix(`/global-health/`)",
                    'kind' => 'Rule',
                    'services' => [[
                        'name' => $agentService,
                        'namespace' => $agentNamespace,
                        'port' => $agentPort,
                    ]],
                ]],
            ],
        ];
    }
}
