<?php

namespace App\Services\Concerns;

use App\Services\HelmService;
use App\Services\Helpers\IngressTrust;
use App\Services\Helpers\RequestTimeout;
use App\Services\Helpers\TraefikImage;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

trait KnativeTraefikTrait
{
    /**
     * Install Traefik as Knative ingress controller via Helm.
     *
     * @param  list<string>  $trustedIps  region setting ingress_trusted_ips (IngressTrust): who may
     *                                    send PROXY protocol / X-Forwarded-*; empty = nobody
     * @param  int  $requestTimeout  region setting request_timeout (RequestTimeout): the entry
     *                               points wait that long for a request and its response
     */
    private function installTraefik(KubernetesClient $k8s, HelmService $helm, string $namespace, string $kubeconfig, array $trustedIps = [], int $requestTimeout = RequestTimeout::DEFAULT): void
    {
        $helm->repoAdd(self::TRAEFIK_REPO_NAME, self::TRAEFIK_REPO_URL);
        $helm->repoUpdate();

        $values = array_replace_recursive(
            $this->traefikHelmValues($namespace, $trustedIps, $requestTimeout),
            $this->traefikIngressClassValues($k8s, $namespace),
            // the chart's own image from the reqs mirror instead of Docker Hub (TraefikImage)
            ['image' => TraefikImage::helmValues(TraefikImage::ref())],
        );

        $result = $helm->install(
            release: self::TRAEFIK_RELEASE,
            chart: self::TRAEFIK_CHART,
            namespace: $namespace,
            setValues: [],
            kubeconfigYaml: $kubeconfig,
            version: self::TRAEFIK_CHART_VERSION,
            valuesArray: $values,
            setJsonValues: ['providers.knative.namespaces' => '[]'],
        );

        if (! $result->success) {
            throw new RuntimeException('Traefik install failed: '.$result->error);
        }

        $this->applyKnativeRbac($k8s, self::TRAEFIK_RELEASE, $namespace);
    }

    /**
     * Apply Knative-specific ClusterRole and ClusterRoleBinding for Traefik.
     *
     * @see https://doc.traefik.io/traefik/reference/install-configuration/providers/kubernetes/knative/
     */
    private function applyKnativeRbac(KubernetesClient $k8s, string $serviceAccount, string $namespace): void
    {
        $k8s->applyClusterResource([
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRole',
            'metadata' => ['name' => 'knative-networking-role'],
            'rules' => [
                [
                    'apiGroups' => [''],
                    'resources' => ['services'],
                    'verbs' => ['get', 'list', 'watch'],
                ],
                [
                    'apiGroups' => [''],
                    'resources' => ['secrets'],
                    'verbs' => ['get', 'list', 'watch'],
                ],
                [
                    'apiGroups' => ['networking.internal.knative.dev'],
                    'resources' => ['ingresses'],
                    'verbs' => ['get', 'list', 'watch'],
                ],
                [
                    'apiGroups' => ['networking.internal.knative.dev'],
                    'resources' => ['ingresses/status'],
                    'verbs' => ['update'],
                ],
            ],
        ]);

        $k8s->applyClusterResource([
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRoleBinding',
            'metadata' => ['name' => 'traefik-knative-networking'],
            'roleRef' => [
                'apiGroup' => 'rbac.authorization.k8s.io',
                'kind' => 'ClusterRole',
                'name' => 'knative-networking-role',
            ],
            'subjects' => [[
                'kind' => 'ServiceAccount',
                'name' => $serviceAccount,
                'namespace' => $namespace,
            ]],
        ]);
    }

    /**
     * Remove Knative RBAC resources (ClusterRole + ClusterRoleBinding).
     */
    private function removeKnativeRbac(KubernetesClient $k8s): void
    {
        $resources = [
            ['kind' => 'ClusterRoleBinding', 'name' => 'traefik-knative-networking'],
            ['kind' => 'ClusterRole', 'name' => 'knative-networking-role'],
        ];

        foreach ($resources as $r) {
            try {
                $k8s->deleteClusterResource('rbac.authorization.k8s.io/v1', $r['kind'], $r['name']);
            } catch (Throwable) {
                Log::debug("Cleanup: {$r['kind']} {$r['name']} removal skipped");
            }
        }
    }

    /**
     * IngressClass values for the region Traefik.
     *
     * An IngressClass "traefik" that belongs to another controller (e.g. the Traefik a managed
     * platform or k3s ships) can't be adopted by this release — Helm aborts on its ownership
     * metadata. In that case this Traefik creates no IngressClass and only watches Ingresses of
     * class "traefik-knative"; Knative routes come from the knative provider either way.
     * Otherwise the release keeps owning "traefik" (single-cluster installs: it also serves the
     * RunR console Ingress) and marks it default unless another class already is.
     */
    private function traefikIngressClassValues(KubernetesClient $k8s, string $namespace): array
    {
        $classes = $k8s->listClusterResources('networking.k8s.io/v1', 'IngressClass')['items'] ?? [];

        return self::resolveTraefikIngressClassValues($classes, $namespace);
    }

    /**
     * @param  array  $ingressClasses  IngressClass objects of the cluster
     */
    public static function resolveTraefikIngressClassValues(array $ingressClasses, string $namespace): array
    {
        $otherDefault = false;

        foreach ($ingressClasses as $class) {
            $owned = self::isOwnedByTraefikRelease($class, $namespace);

            if (($class['metadata']['name'] ?? '') === self::TRAEFIK_INGRESS_CLASS && ! $owned) {
                return [
                    'ingressClass' => ['enabled' => false, 'isDefaultClass' => false],
                    'providers' => ['kubernetesIngress' => ['ingressClass' => self::TRAEFIK_KNATIVE_INGRESS_CLASS]],
                ];
            }

            $annotations = $class['metadata']['annotations'] ?? [];
            if (! $owned && ($annotations['ingressclass.kubernetes.io/is-default-class'] ?? '') === 'true') {
                $otherDefault = true;
            }
        }

        return ['ingressClass' => ['enabled' => true, 'isDefaultClass' => ! $otherDefault]];
    }

    /**
     * Helm ownership metadata of this Traefik release (release "traefik" in the serving namespace).
     */
    private static function isOwnedByTraefikRelease(array $resource, string $namespace): bool
    {
        $annotations = $resource['metadata']['annotations'] ?? [];

        return ($annotations['meta.helm.sh/release-name'] ?? '') === self::TRAEFIK_RELEASE
            && ($annotations['meta.helm.sh/release-namespace'] ?? '') === $namespace;
    }

    /**
     * Ingresses outside Knative that this Traefik serves (e.g. the RunR console on a single-cluster
     * install). Knative routes are KIngress objects and never show up here.
     *
     * @return string[] "namespace/name" of each Ingress
     */
    private function ingressesServedByTraefik(KubernetesClient $k8s, string $namespace): array
    {
        $classes = $k8s->listClusterResources('networking.k8s.io/v1', 'IngressClass');
        $ingresses = $k8s->listClusterResources('networking.k8s.io/v1', 'Ingress');

        if ($classes === null || $ingresses === null) {
            // Can't tell: err on the safe side and keep Traefik
            return ['(Ingress list unavailable)'];
        }

        return self::resolveIngressesServedByTraefik($classes['items'] ?? [], $ingresses['items'] ?? [], $namespace);
    }

    /**
     * @param  array  $ingressClasses  IngressClass objects of the cluster
     * @param  array  $ingresses  Ingress objects of all namespaces
     * @return string[]
     */
    public static function resolveIngressesServedByTraefik(array $ingressClasses, array $ingresses, string $namespace): array
    {
        $ownClasses = [];
        $ownIsDefault = false;

        foreach ($ingressClasses as $class) {
            if (! self::isOwnedByTraefikRelease($class, $namespace)) {
                continue;
            }
            $ownClasses[] = $class['metadata']['name'] ?? '';
            if (($class['metadata']['annotations']['ingressclass.kubernetes.io/is-default-class'] ?? '') === 'true') {
                $ownIsDefault = true;
            }
        }
        // Installed without an IngressClass: the provider only watches class traefik-knative
        $ownClasses[] = self::TRAEFIK_KNATIVE_INGRESS_CLASS;

        $served = [];
        foreach ($ingresses as $ingress) {
            $class = $ingress['spec']['ingressClassName']
                ?? $ingress['metadata']['annotations']['kubernetes.io/ingress.class']
                ?? null;

            if (($class === null && $ownIsDefault) || in_array($class, $ownClasses, true)) {
                $served[] = ($ingress['metadata']['namespace'] ?? '').'/'.($ingress['metadata']['name'] ?? '');
            }
        }

        return $served;
    }

    /**
     * Uninstall the region Traefik unless it still serves non-Knative Ingresses.
     * $force removes it anyway (e.g. the console moved to another ingress controller).
     *
     * @return string[] Ingresses that kept Traefik installed (empty = Traefik removed)
     */
    private function uninstallTraefikIfUnused(KubernetesClient $k8s, HelmService $helm, string $namespace, string $kubeconfig, bool $force = false): array
    {
        $served = $force ? [] : $this->ingressesServedByTraefik($k8s, $namespace);
        if (! empty($served)) {
            return $served;
        }

        try {
            $helm->uninstall(self::TRAEFIK_RELEASE, $namespace, $kubeconfig);
        } catch (Throwable $e) {
            Log::debug('Cleanup: Traefik uninstall skipped', ['error' => $e->getMessage()]);
        }
        $this->removeKnativeRbac($k8s);

        return [];
    }

    private function traefikKeptMessage(array $servedIngresses): string
    {
        return 'Traefik kept: it still serves '.implode(', ', $servedIngresses)
            .' (e.g. the RunR console). Uninstall with remove_traefik=true to remove it anyway.';
    }

    /**
     * Traefik Helm chart values for production Knative ingress.
     *
     * @param  list<string>  $trustedIps  see installTraefik()
     * @param  int  $requestTimeout  see installTraefik()
     */
    private function traefikHelmValues(string $namespace, array $trustedIps = [], int $requestTimeout = RequestTimeout::DEFAULT): array
    {
        return [
            'deployment' => [
                'enabled' => true, 'kind' => 'Deployment', 'replicas' => 3,
                'revisionHistoryLimit' => 5, 'terminationGracePeriodSeconds' => 60, 'minReadySeconds' => 10,
                'livenessProbe' => [
                    'httpGet' => ['path' => '/ping', 'port' => 9000],
                    'initialDelaySeconds' => 20, 'periodSeconds' => 10, 'timeoutSeconds' => 5, 'failureThreshold' => 5,
                ],
                'readinessProbe' => [
                    'httpGet' => ['path' => '/ping', 'port' => 9000],
                    'initialDelaySeconds' => 10, 'periodSeconds' => 10, 'timeoutSeconds' => 5,
                    'failureThreshold' => 3, 'successThreshold' => 2,
                ],
            ],
            'podDisruptionBudget' => ['enabled' => true, 'maxUnavailable' => 1],
            'ingressClass' => ['enabled' => true, 'isDefaultClass' => true],
            'gateway' => ['enabled' => false],
            'experimental' => ['knative' => true],
            'providers' => [
                'kubernetesCRD' => ['enabled' => true, 'allowCrossNamespace' => true],
                'kubernetesIngress' => ['enabled' => true, 'allowExternalNameServices' => true],
                'knative' => ['enabled' => true],
            ],
            'ports' => [
                'traefik' => [
                    'port' => 9000, 'expose' => ['default' => false], 'exposedPort' => 9000, 'protocol' => 'TCP',
                ],
                'web' => $this->traefikPortConfig(8000, 80, true, [
                    'respondingTimeouts' => RequestTimeout::traefikTransport($requestTimeout),
                    'lifeCycle' => ['requestAcceptGraceTimeout' => '10s', 'graceTimeOut' => '10s'],
                ], $trustedIps),
                'websecure' => array_merge(
                    $this->traefikPortConfig(8443, 443, true, [
                        'respondingTimeouts' => RequestTimeout::traefikTransport($requestTimeout),
                        'lifeCycle' => ['requestAcceptGraceTimeout' => '10s', 'graceTimeOut' => '10s'],
                    ], $trustedIps),
                    [
                        'http' => [
                            'tls' => ['enabled' => true],
                            'sanitizePath' => true,
                            'encodedCharacters' => [
                                'allowEncodedSlash' => false, 'allowEncodedBackSlash' => false,
                                'allowEncodedNullCharacter' => false, 'allowEncodedSemicolon' => false,
                                'allowEncodedPercent' => false, 'allowEncodedQuestionMark' => false,
                                'allowEncodedHash' => false,
                            ],
                        ],
                        'http3' => ['enabled' => false],
                    ],
                ),
                'metrics' => [
                    'port' => 9100, 'expose' => ['default' => false], 'exposedPort' => 9100, 'protocol' => 'TCP',
                ],
            ],
            'logs' => [
                'general' => ['level' => 'INFO', 'format' => 'json'],
                'access' => [
                    'enabled' => true, 'format' => 'json', 'filters' => [],
                    'fields' => [
                        'general' => ['defaultMode' => 'keep'],
                        'headers' => [
                            'defaultMode' => 'drop',
                            'names' => ['User-Agent' => 'keep', 'X-Real-Ip' => 'keep', 'X-Request-Id' => 'keep'],
                        ],
                    ],
                ],
            ],
            'metrics' => [
                'prometheus' => [
                    'entryPoint' => 'metrics', 'addEntryPointsLabels' => true,
                    'addRoutersLabels' => true, 'addServicesLabels' => true, 'buckets' => '0.1,0.3,1.2,5.0',
                ],
            ],
            'service' => ['enabled' => true, 'single' => true, 'type' => 'LoadBalancer'],
            'autoscaling' => [
                'enabled' => true, 'minReplicas' => 3, 'maxReplicas' => 10,
                'metrics' => [[
                    'type' => 'Resource',
                    'resource' => ['name' => 'memory', 'target' => ['type' => 'Utilization', 'averageUtilization' => 80]],
                ]],
                'behavior' => [
                    'scaleDown' => [
                        'stabilizationWindowSeconds' => 300,
                        'policies' => [['type' => 'Percent', 'value' => 50, 'periodSeconds' => 60]],
                    ],
                    'scaleUp' => [
                        'stabilizationWindowSeconds' => 0,
                        'policies' => [
                            ['type' => 'Percent', 'value' => 100, 'periodSeconds' => 30],
                            ['type' => 'Pods', 'value' => 2, 'periodSeconds' => 30],
                        ],
                        'selectPolicy' => 'Max',
                    ],
                ],
            ],
            'resources' => [
                'requests' => ['cpu' => '100m', 'memory' => '512Mi'],
                'limits' => ['memory' => '512Mi'],
            ],
            'affinity' => [
                'podAntiAffinity' => [
                    'preferredDuringSchedulingIgnoredDuringExecution' => [[
                        'weight' => 100,
                        'podAffinityTerm' => [
                            'labelSelector' => [
                                'matchExpressions' => [[
                                    'key' => 'app.kubernetes.io/name', 'operator' => 'In', 'values' => ['traefik'],
                                ]],
                            ],
                            'topologyKey' => 'kubernetes.io/hostname',
                        ],
                    ]],
                ],
            ],
            'additionalArguments' => [
                '--ping=true', '--ping.entrypoint=traefik',
                '--serversTransport.insecureSkipVerify=false',
                '--entrypoints.websecure.http.tls.options=default',
                // Cluster-local Knative ingress support (queue services etc.)
                '--providers.knative.privateservice.name='.self::TRAEFIK_RELEASE,
                '--providers.knative.privateservice.namespace='.$namespace,
                '--providers.knative.privateentrypoints=web',
            ],
            'tlsOptions' => [
                'default' => [
                    'minVersion' => 'VersionTLS12', 'maxVersion' => 'VersionTLS13',
                    'curvePreferences' => ['X25519', 'CurveP256', 'CurveP384'],
                    'cipherSuites' => [
                        'TLS_ECDHE_ECDSA_WITH_AES_128_GCM_SHA256', 'TLS_ECDHE_RSA_WITH_AES_128_GCM_SHA256',
                        'TLS_ECDHE_ECDSA_WITH_AES_256_GCM_SHA384', 'TLS_ECDHE_RSA_WITH_AES_256_GCM_SHA384',
                        'TLS_ECDHE_ECDSA_WITH_CHACHA20_POLY1305', 'TLS_ECDHE_RSA_WITH_CHACHA20_POLY1305',
                    ],
                    'sniStrict' => false, 'preferServerCipherSuites' => true,
                ],
            ],
            'global' => ['checkNewVersion' => false, 'sendAnonymousUsage' => false],
        ];
    }

    /**
     * PROXY protocol and X-Forwarded-* are trusted only from $trustedIps (a load balancer / proxy
     * in front that sends them, region setting ingress_trusted_ips) — by default from nobody, so
     * Traefik records the TCP peer as the client and a client cannot choose its own address.
     */
    private function traefikPortConfig(int $port, int $exposed, bool $expose, array $transport, array $trustedIps = []): array
    {
        return array_merge([
            'port' => $port, 'expose' => ['default' => $expose], 'exposedPort' => $exposed,
        ], IngressTrust::portValues($trustedIps), [
            'transport' => $transport,
        ]);
    }
}
