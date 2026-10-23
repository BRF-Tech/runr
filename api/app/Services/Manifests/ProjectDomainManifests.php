<?php

namespace App\Services\Manifests;

use App\Services\PublicHostRewrites;

/**
 * Pure manifest builders for project custom domains.
 *
 * Routing goes through the region's own Traefik, with its Kubernetes CRD provider
 * (enabled with allowCrossNamespace, see KnativeTraefikTrait::traefikHelmValues):
 *
 *   IngressRoute  <name>      entryPoint web       Host(`<domain>`)  → Middleware → Service traefik:80
 *   IngressRoute  <name>-tls  entryPoint websecure Host(`<domain>`)  → Middleware → Service traefik:80
 *   Middleware    <name>      headers: Host = <ksvc>.<function domain>, K-Original-Host = <domain>
 *
 * The request re-enters the same Traefik on web with the Knative Service's public host, which
 * the Knative provider's public route sends to the current revision (activator on
 * scale-to-zero). Only a public service has that route: a cluster-local one (a queue trigger)
 * is never reached this way (PrivateIngress). The public host follows the service's URL
 * (PublicHostRewrites: the Middleware names its Knative Service). This is the Host rewrite a
 * Knative DomainMapping asks its ingress for; Traefik's Knative provider does not apply
 * DomainMapping KIngresses (it has no RewriteHost support and needs a ClusterIP backend, while
 * DomainMapping points at the Service's ExternalName placeholder), so RunR builds the
 * equivalent route itself. The route references the Knative Service by name, so redeploys
 * keep it.
 *
 * TLS: a kubernetes.io/tls Secret <name> in the project namespace — uploaded by the
 * customer, or written by a cert-manager Certificate <name>. Without one the websecure
 * route answers with the region's default certificate.
 *
 * Names are derived from project id + domain, so two projects (e.g. two tenants sharing a
 * region namespace) never share an object name.
 */
class ProjectDomainManifests
{
    public const TRAEFIK_API = 'traefik.io/v1alpha1';

    public const CERT_MANAGER_API = 'cert-manager.io/v1';

    public const LABEL_PROJECT = 'runr/project-id';

    public const LABEL_DOMAIN_HASH = 'runr/domain-hash';

    public const ANNOTATION_DOMAIN = 'runr.dev/domain';

    /** Base name of every Kubernetes object RunR creates for one project domain. */
    public static function resourceName(string $projectId, string $domain): string
    {
        return 'runr-domain-'.substr(sha1($projectId.'|'.$domain), 0, 20);
    }

    public static function secureRouteName(string $name): string
    {
        return $name.'-tls';
    }

    /** Label value identifying a domain (a host name can be longer than a label value). */
    public static function domainHash(string $domain): string
    {
        return sha1($domain);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(string $projectId, string $domain): array
    {
        return [
            'app.kubernetes.io/managed-by' => 'runr',
            self::LABEL_PROJECT => $projectId,
            self::LABEL_DOMAIN_HASH => self::domainHash($domain),
        ];
    }

    private static function metadata(string $name, string $namespace, string $projectId, string $domain): array
    {
        return [
            'name' => $name,
            'namespace' => $namespace,
            'labels' => self::labels($projectId, $domain),
            'annotations' => [self::ANNOTATION_DOMAIN => $domain],
        ];
    }

    /**
     * Traefik headers Middleware: rewrites Host to Knative Service $serviceName's public host
     * and keeps the original host in K-Original-Host (the header DomainMapping sets too).
     */
    public static function hostRewriteMiddleware(
        string $name,
        string $namespace,
        string $projectId,
        string $domain,
        string $serviceName,
        string $publicHost,
    ): array {
        return [
            'apiVersion' => self::TRAEFIK_API,
            'kind' => 'Middleware',
            'metadata' => array_merge_recursive(self::metadata($name, $namespace, $projectId, $domain), PublicHostRewrites::metadata($serviceName)),
            'spec' => [
                'headers' => [
                    'customRequestHeaders' => [
                        'Host' => $publicHost,
                        'K-Original-Host' => $domain,
                    ],
                ],
            ],
        ];
    }

    /**
     * IngressRoute for one entrypoint. $secure=false → entrypoint web (plain HTTP);
     * $secure=true → entrypoint websecure with the domain's TLS Secret, or an empty tls
     * block (region default certificate) when $tlsSecretName is null.
     */
    public static function ingressRoute(
        string $name,
        string $namespace,
        string $projectId,
        string $domain,
        bool $secure,
        ?string $tlsSecretName,
        string $traefikService,
        string $traefikNamespace,
    ): array {
        $spec = [
            'entryPoints' => [$secure ? 'websecure' : 'web'],
            'routes' => [[
                'match' => "Host(`{$domain}`)",
                'kind' => 'Rule',
                'middlewares' => [['name' => $name, 'namespace' => $namespace]],
                'services' => [[
                    'name' => $traefikService,
                    'namespace' => $traefikNamespace,
                    'port' => 80,
                ]],
            ]],
        ];

        if ($secure) {
            $spec['tls'] = $tlsSecretName ? ['secretName' => $tlsSecretName] : new \stdClass;
        }

        return [
            'apiVersion' => self::TRAEFIK_API,
            'kind' => 'IngressRoute',
            'metadata' => self::metadata($secure ? self::secureRouteName($name) : $name, $namespace, $projectId, $domain),
            'spec' => $spec,
        ];
    }

    /**
     * kubernetes.io/tls Secret with an uploaded certificate (PEMs already normalized).
     */
    public static function tlsSecret(
        string $name,
        string $namespace,
        string $projectId,
        string $domain,
        string $certificatePem,
        string $privateKeyPem,
    ): array {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Secret',
            'metadata' => self::metadata($name, $namespace, $projectId, $domain),
            'type' => 'kubernetes.io/tls',
            'data' => [
                'tls.crt' => base64_encode($certificatePem),
                'tls.key' => base64_encode($privateKeyPem),
            ],
        ];
    }

    /**
     * cert-manager Certificate for exactly this host, issued by a ClusterIssuer into Secret $name.
     */
    public static function certificate(
        string $name,
        string $namespace,
        string $projectId,
        string $domain,
        string $clusterIssuer,
    ): array {
        return [
            'apiVersion' => self::CERT_MANAGER_API,
            'kind' => 'Certificate',
            'metadata' => self::metadata($name, $namespace, $projectId, $domain),
            'spec' => [
                'secretName' => $name,
                'dnsNames' => [$domain],
                'issuerRef' => [
                    'name' => $clusterIssuer,
                    'kind' => 'ClusterIssuer',
                    'group' => 'cert-manager.io',
                ],
                'secretTemplate' => [
                    'labels' => self::labels($projectId, $domain),
                ],
            ],
        ];
    }
}
