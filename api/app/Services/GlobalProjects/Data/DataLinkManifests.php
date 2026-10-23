<?php

namespace App\Services\GlobalProjects\Data;

use App\Services\Bindings\BindingManifests;

/**
 * Kubernetes objects of a binding's data link (docs/global-projects.md, "Data"), pure builders.
 *
 * A managed binding lives in one region's cluster (its home). The copies of a global project in
 * the other regions reach it through the link:
 *
 *   other region (data namespace)                    home region
 *   ┌───────────────────────────────┐                ┌──────────────────────────────────────────┐
 *   │ Service  runr-link-<binding>  │  TLS 443, SNI  │ Traefik websecure: IngressRouteTCP       │
 *   │ nginx (stream) ── client cert ├───────────────▶│ HostSNI(<sni>), TLSOption: client certs  │
 *   │ the copy connects here (plain │  mutual TLS    │ of the link's CA only ──▶ <service>:port │
 *   │ protocol, as to a local one)  │                │ (Postgres -rw, runr-kv, runr-objects)     │
 *   └───────────────────────────────┘                └──────────────────────────────────────────┘
 *
 * What flows inside is the data service's own protocol: Postgres negotiates its own TLS inside the
 * link, Redis and S3 authenticate with the binding's credentials as before. A dedicated Postgres
 * binding's replica clusters stream from the home through the same link.
 */
final class DataLinkManifests
{
    public const TRAEFIK_API = 'traefik.io/v1alpha1';

    public const LABEL = 'runr.dev/data-link';

    /** Name of every object of the link of a binding (resource name: name + 8 characters of its id). */
    public static function name(string $resourceName): string
    {
        return 'runr-link-'.$resourceName;
    }

    /** The SNI name the forwarders send and the home's Traefik routes by (never resolved in DNS). */
    public static function sni(string $resourceName): string
    {
        return $resourceName.'.data-link.runr.internal';
    }

    /** The host the copies (and replica clusters) of a region connect to. */
    public static function host(string $resourceName, string $dataNamespace): string
    {
        return self::name($resourceName).'.'.$dataNamespace.'.svc.cluster.local';
    }

    private static function labels(string $resourceName): array
    {
        return BindingManifests::labels([self::LABEL => $resourceName]);
    }

    /**
     * The home's side: the server certificate, the CA its clients must be signed by, the TLS
     * options that require them, the TCP route of the SNI name to the data service and the
     * NetworkPolicy that lets Traefik's pods reach the service's pods.
     *
     * @param  array{sni: string, ca_cert: string, server_cert: string, server_key: string}  $link
     * @param  array{namespace: string, service: string, port: int, labels: array<string, string>|null}  $target
     * @return list<array>
     */
    public static function server(string $resourceName, string $dataNamespace, array $link, array $target, string $traefikNamespace, string $entryPoint = 'websecure'): array
    {
        $name = self::name($resourceName);
        $labels = self::labels($resourceName);

        $objects = [
            [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => $name.'-server', 'namespace' => $dataNamespace, 'labels' => $labels],
                'type' => 'kubernetes.io/tls',
                'data' => ['tls.crt' => base64_encode($link['server_cert']), 'tls.key' => base64_encode($link['server_key'])],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => $name.'-ca', 'namespace' => $dataNamespace, 'labels' => $labels],
                'type' => 'Opaque',
                'data' => ['ca.crt' => base64_encode($link['ca_cert'])],
            ],
            [
                'apiVersion' => self::TRAEFIK_API,
                'kind' => 'TLSOption',
                'metadata' => ['name' => $name, 'namespace' => $dataNamespace, 'labels' => $labels],
                'spec' => [
                    'minVersion' => 'VersionTLS12',
                    'sniStrict' => true,
                    'clientAuth' => [
                        'secretNames' => [$name.'-ca'],
                        'clientAuthType' => 'RequireAndVerifyClientCert',
                    ],
                ],
            ],
            [
                'apiVersion' => self::TRAEFIK_API,
                'kind' => 'IngressRouteTCP',
                'metadata' => ['name' => $name, 'namespace' => $dataNamespace, 'labels' => $labels],
                'spec' => [
                    'entryPoints' => [$entryPoint],
                    'routes' => [[
                        'match' => 'HostSNI(`'.$link['sni'].'`)',
                        'services' => [[
                            'name' => $target['service'],
                            'namespace' => $target['namespace'],
                            'port' => (int) $target['port'],
                        ]],
                    ]],
                    'tls' => [
                        'secretName' => $name.'-server',
                        'options' => ['name' => $name, 'namespace' => $dataNamespace],
                    ],
                ],
            ],
        ];

        // The data namespace denies what it does not allow: Traefik's pods may reach the service
        if (($target['labels'] ?? null) !== null && $target['namespace'] === $dataNamespace) {
            $objects[] = [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'NetworkPolicy',
                'metadata' => ['name' => $name.'-traefik', 'namespace' => $dataNamespace, 'labels' => $labels],
                'spec' => [
                    'podSelector' => ['matchLabels' => $target['labels']],
                    'policyTypes' => ['Ingress'],
                    'ingress' => [[
                        'from' => [['namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => $traefikNamespace]]]],
                        'ports' => [['protocol' => 'TCP', 'port' => (int) $target['port']]],
                    ]],
                ],
            ];
        }

        return $objects;
    }

    /**
     * A region's side: the client certificate, the forwarder's configuration, the forwarder and its
     * Service, and the NetworkPolicy that admits the project's pods (the data namespace's own pods
     * — replica clusters — are admitted by its base policy).
     *
     * @param  array{sni: string, ca_cert: string, client_cert: string, client_key: string}  $link
     * @param  list<string>  $projectIds
     * @return list<array>
     */
    public static function client(string $resourceName, string $dataNamespace, array $link, string $homeAddress, int $homePort, int $port, string $image, string $projectNamespace, array $projectIds): array
    {
        $name = self::name($resourceName);
        $labels = self::labels($resourceName);
        $config = self::nginxConfig($link['sni'], $homeAddress, $homePort, $port);
        sort($projectIds);

        return [
            [
                'apiVersion' => 'v1',
                'kind' => 'Secret',
                'metadata' => ['name' => $name.'-client', 'namespace' => $dataNamespace, 'labels' => $labels],
                'type' => 'Opaque',
                'data' => [
                    'tls.crt' => base64_encode($link['client_cert']),
                    'tls.key' => base64_encode($link['client_key']),
                    'ca.crt' => base64_encode($link['ca_cert']),
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => ['name' => $name, 'namespace' => $dataNamespace, 'labels' => $labels],
                'data' => ['nginx.conf' => $config],
            ],
            [
                'apiVersion' => 'apps/v1',
                'kind' => 'Deployment',
                'metadata' => ['name' => $name, 'namespace' => $dataNamespace, 'labels' => $labels],
                'spec' => [
                    'replicas' => 2,
                    'selector' => ['matchLabels' => [self::LABEL => $resourceName]],
                    'template' => [
                        // a changed home (failover, switchover) rolls the forwarders
                        'metadata' => ['labels' => $labels, 'annotations' => ['runr.dev/config-hash' => substr(sha1($config.$link['client_cert']), 0, 16)]],
                        'spec' => [
                            'containers' => [[
                                'name' => 'forwarder',
                                'image' => $image,
                                'command' => ['nginx', '-c', '/etc/runr-link/nginx.conf', '-g', 'daemon off;'],
                                'ports' => [['name' => 'data', 'containerPort' => $port, 'protocol' => 'TCP']],
                                'readinessProbe' => ['tcpSocket' => ['port' => $port], 'periodSeconds' => 5],
                                'volumeMounts' => [
                                    ['name' => 'config', 'mountPath' => '/etc/runr-link/nginx.conf', 'subPath' => 'nginx.conf', 'readOnly' => true],
                                    ['name' => 'tls', 'mountPath' => '/etc/runr-link/tls', 'readOnly' => true],
                                ],
                                'resources' => [
                                    'requests' => ['cpu' => '20m', 'memory' => '24Mi'],
                                    'limits' => ['cpu' => '500m', 'memory' => '128Mi'],
                                ],
                            ]],
                            'volumes' => [
                                ['name' => 'config', 'configMap' => ['name' => $name]],
                                ['name' => 'tls', 'secret' => ['secretName' => $name.'-client', 'defaultMode' => 0400]],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'apiVersion' => 'v1',
                'kind' => 'Service',
                'metadata' => ['name' => $name, 'namespace' => $dataNamespace, 'labels' => $labels],
                'spec' => [
                    'type' => 'ClusterIP',
                    'selector' => [self::LABEL => $resourceName],
                    'ports' => [['name' => 'data', 'port' => $port, 'targetPort' => $port, 'protocol' => 'TCP']],
                ],
            ],
            [
                'apiVersion' => 'networking.k8s.io/v1',
                'kind' => 'NetworkPolicy',
                'metadata' => ['name' => $name.'-clients', 'namespace' => $dataNamespace, 'labels' => $labels],
                'spec' => [
                    'podSelector' => ['matchLabels' => [self::LABEL => $resourceName]],
                    'policyTypes' => ['Ingress'],
                    'ingress' => [[
                        'from' => $projectIds === [] ? [] : [[
                            'namespaceSelector' => ['matchLabels' => ['kubernetes.io/metadata.name' => $projectNamespace]],
                            'podSelector' => ['matchExpressions' => [['key' => 'runr/project-id', 'operator' => 'In', 'values' => array_values(array_unique($projectIds))]]],
                        ]],
                        'ports' => [['protocol' => 'TCP', 'port' => $port]],
                    ]],
                ],
            ],
        ];
    }

    /**
     * The forwarder: plain TCP in, mutual TLS out to the home's Traefik with the link's SNI name;
     * the home's certificate must be the link CA's for exactly that name.
     */
    public static function nginxConfig(string $sni, string $homeAddress, int $homePort, int $port): string
    {
        $upstream = str_contains($homeAddress, ':') && ! str_starts_with($homeAddress, '[') ? "[{$homeAddress}]" : $homeAddress;

        return implode("\n", [
            'worker_processes 1;',
            'pid /tmp/nginx.pid;',
            'error_log /dev/stderr notice;',
            'events { worker_connections 1024; }',
            'stream {',
            '  server {',
            "    listen {$port};",
            "    proxy_pass {$upstream}:{$homePort};",
            '    proxy_ssl on;',
            '    proxy_ssl_server_name on;',
            "    proxy_ssl_name {$sni};",
            '    proxy_ssl_protocols TLSv1.2 TLSv1.3;',
            '    proxy_ssl_certificate /etc/runr-link/tls/tls.crt;',
            '    proxy_ssl_certificate_key /etc/runr-link/tls/tls.key;',
            '    proxy_ssl_trusted_certificate /etc/runr-link/tls/ca.crt;',
            '    proxy_ssl_verify on;',
            '    proxy_ssl_verify_depth 2;',
            '    proxy_connect_timeout 10s;',
            '    proxy_timeout 24h;',
            '  }',
            '}',
            '',
        ]);
    }

    /**
     * Every object of a link in a region, for removal: [apiVersion, kind, name].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function targets(string $resourceName): array
    {
        $name = self::name($resourceName);

        return [
            ['apps/v1', 'Deployment', $name],
            ['v1', 'Service', $name],
            ['v1', 'ConfigMap', $name],
            ['v1', 'Secret', $name.'-client'],
            ['networking.k8s.io/v1', 'NetworkPolicy', $name.'-clients'],
            [self::TRAEFIK_API, 'IngressRouteTCP', $name],
            [self::TRAEFIK_API, 'TLSOption', $name],
            ['v1', 'Secret', $name.'-server'],
            ['v1', 'Secret', $name.'-ca'],
            ['networking.k8s.io/v1', 'NetworkPolicy', $name.'-traefik'],
        ];
    }

    /**
     * The service a binding's env reaches, parsed from its in-cluster host
     * (<service>.<namespace>.svc[.cluster.local]); null for a host outside the cluster (an external
     * S3 endpoint, an external database): every region reaches it as it is.
     *
     * @return array{namespace: string, service: string}|null
     */
    public static function inClusterService(string $host): ?array
    {
        $host = strtolower(rtrim($host, '.'));
        if (! preg_match('/^([a-z0-9]([-a-z0-9]*[a-z0-9])?)\.([a-z0-9]([-a-z0-9]*[a-z0-9])?)\.svc(\.cluster\.local)?$/', $host, $m)) {
            return null;
        }

        return ['service' => $m[1], 'namespace' => $m[3]];
    }
}
