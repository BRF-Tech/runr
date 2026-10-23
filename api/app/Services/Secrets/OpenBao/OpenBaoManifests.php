<?php

namespace App\Services\Secrets\OpenBao;

use App\Support\Images\ImageReference;

/**
 * The region's OpenBao in namespace runr-openbao (OpenBaoInstallService):
 *
 *  - ServiceAccount openbao — reviews the tokens of Kubernetes auth logins (TokenReview, through
 *    the ClusterRoleBinding runr-openbao-tokenreview to system:auth-delegator);
 *  - ServiceAccount runr-control — RunR's identity in OpenBao (OpenBaoClient::login());
 *  - ConfigMap openbao-config — TLS listener, file storage on the volume, no UI;
 *  - StatefulSet openbao, one replica: the server, and an unsealer next to it that unseals with
 *    the key in the Secret runr-openbao-unseal (written by the install, read by nothing else) after
 *    every restart;
 *  - Service openbao (https 8200), reached by the SecretStores of the region and, through the API
 *    server, by RunR.
 *
 * The TLS key pair (Secret runr-openbao-tls) and the unseal key are written by the install, never
 * part of these manifests.
 */
final class OpenBaoManifests
{
    public const TLS_SECRET = 'runr-openbao-tls';

    public const UNSEAL_SECRET = 'runr-openbao-unseal';

    public const TOKEN_REVIEW_BINDING = 'runr-openbao-tokenreview';

    public static function labels(array $extra = []): array
    {
        return array_merge([
            'app.kubernetes.io/name' => 'openbao',
            'app.kubernetes.io/managed-by' => 'runr',
            'app.kubernetes.io/part-of' => 'runr-secrets',
        ], $extra);
    }

    /** @return list<array> namespaced objects, in apply order */
    public static function namespaced(string $image, string $storageSize, string $storageClass): array
    {
        $ns = OpenBaoClient::NAMESPACE;

        return [
            ['apiVersion' => 'v1', 'kind' => 'ServiceAccount', 'metadata' => ['name' => 'openbao', 'namespace' => $ns, 'labels' => self::labels()]],
            ['apiVersion' => 'v1', 'kind' => 'ServiceAccount', 'metadata' => ['name' => OpenBaoClient::CONTROL, 'namespace' => $ns, 'labels' => self::labels()],
                'automountServiceAccountToken' => false],
            self::config(),
            self::statefulSet($image, $storageSize, $storageClass),
            [
                'apiVersion' => 'v1', 'kind' => 'Service',
                'metadata' => ['name' => OpenBaoClient::SERVICE, 'namespace' => $ns, 'labels' => self::labels()],
                'spec' => [
                    'selector' => ['app.kubernetes.io/name' => 'openbao'],
                    'ports' => [['name' => 'https', 'port' => OpenBaoClient::PORT, 'targetPort' => OpenBaoClient::PORT]],
                ],
            ],
        ];
    }

    public static function namespace(): array
    {
        return ['apiVersion' => 'v1', 'kind' => 'Namespace', 'metadata' => ['name' => OpenBaoClient::NAMESPACE, 'labels' => self::labels()]];
    }

    public static function tokenReviewBinding(): array
    {
        return [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRoleBinding',
            'metadata' => ['name' => self::TOKEN_REVIEW_BINDING, 'labels' => self::labels()],
            'roleRef' => ['apiGroup' => 'rbac.authorization.k8s.io', 'kind' => 'ClusterRole', 'name' => 'system:auth-delegator'],
            'subjects' => [['kind' => 'ServiceAccount', 'name' => 'openbao', 'namespace' => OpenBaoClient::NAMESPACE]],
        ];
    }

    public static function tlsSecret(array $tls): array
    {
        return [
            'apiVersion' => 'v1', 'kind' => 'Secret', 'type' => 'kubernetes.io/tls',
            'metadata' => ['name' => self::TLS_SECRET, 'namespace' => OpenBaoClient::NAMESPACE, 'labels' => self::labels()],
            'data' => [
                'tls.crt' => base64_encode($tls['cert']),
                'tls.key' => base64_encode($tls['key']),
                'ca.crt' => base64_encode($tls['ca']),
            ],
        ];
    }

    public static function unsealSecret(string $key): array
    {
        return [
            'apiVersion' => 'v1', 'kind' => 'Secret', 'type' => 'Opaque',
            'metadata' => ['name' => self::UNSEAL_SECRET, 'namespace' => OpenBaoClient::NAMESPACE, 'labels' => self::labels()],
            'data' => ['key' => base64_encode($key)],
        ];
    }

    public static function config(): array
    {
        $hcl = <<<'HCL'
ui            = false
disable_mlock = true
api_addr      = "https://openbao.runr-openbao.svc:8200"

listener "tcp" {
  address       = "0.0.0.0:8200"
  tls_cert_file = "/openbao/tls/tls.crt"
  tls_key_file  = "/openbao/tls/tls.key"
}

storage "file" {
  path = "/openbao/data"
}

# The audit log to the container's output (request and response fields HMACed, values never in
# it). Declared here: OpenBao 2.4 refuses to enable audit devices through its API
audit "file" "stdout" {
  description = "RunR: every request to the region's OpenBao"
  options {
    file_path = "stdout"
  }
}
HCL;

        return [
            'apiVersion' => 'v1', 'kind' => 'ConfigMap',
            'metadata' => ['name' => 'openbao-config', 'namespace' => OpenBaoClient::NAMESPACE, 'labels' => self::labels()],
            'data' => ['config.hcl' => $hcl],
        ];
    }

    public static function statefulSet(string $image, string $storageSize, string $storageClass): array
    {
        $env = [
            ['name' => 'BAO_ADDR', 'value' => 'https://127.0.0.1:8200'],
            ['name' => 'BAO_CACERT', 'value' => '/openbao/tls/ca.crt'],
        ];
        $security = ['allowPrivilegeEscalation' => false, 'capabilities' => ['drop' => ['ALL']], 'readOnlyRootFilesystem' => true];

        // After every restart: unseal with the region's key once the server answers sealed (bao
        // status exits 2 while sealed), then keep watching
        $unseal = 'while true; do '
            .'if [ -s /openbao/unseal/key ]; then bao status >/dev/null 2>&1; rc=$?; '
            .'if [ "$rc" = 2 ]; then bao operator unseal "$(cat /openbao/unseal/key)" >/dev/null && echo "unsealed"; fi; fi; '
            .'sleep 10; done';

        $claim = [
            'metadata' => ['name' => 'data', 'labels' => self::labels()],
            'spec' => [
                'accessModes' => ['ReadWriteOnce'],
                'resources' => ['requests' => ['storage' => $storageSize]],
            ],
        ];
        if ($storageClass !== '') {
            $claim['spec']['storageClassName'] = $storageClass;
        }

        return [
            'apiVersion' => 'apps/v1', 'kind' => 'StatefulSet',
            'metadata' => ['name' => 'openbao', 'namespace' => OpenBaoClient::NAMESPACE, 'labels' => self::labels()],
            'spec' => [
                'serviceName' => OpenBaoClient::SERVICE,
                'replicas' => 1,
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'openbao']],
                'template' => [
                    'metadata' => [
                        'labels' => self::labels(),
                        // a new configuration restarts the pod (the unsealer unseals it again)
                        'annotations' => ['runr.dev/config-hash' => substr(hash('sha256', json_encode(self::config())), 0, 16)],
                    ],
                    'spec' => [
                        'serviceAccountName' => 'openbao',
                        'securityContext' => ['runAsNonRoot' => true, 'runAsUser' => 100, 'runAsGroup' => 1000, 'fsGroup' => 1000],
                        'containers' => [
                            [
                                'name' => 'openbao',
                                'image' => $image,
                                // Not through the image's entrypoint, which adds its own -config
                                'command' => ['bao', 'server', '-config=/openbao/config/config.hcl'],
                                'env' => $env,
                                'ports' => [['name' => 'https', 'containerPort' => OpenBaoClient::PORT]],
                                // Ready once it answers, sealed or not: the unsealer and the install reach it
                                'readinessProbe' => [
                                    'httpGet' => ['path' => '/v1/sys/health?standbyok=true&sealedcode=204&uninitcode=204', 'port' => OpenBaoClient::PORT, 'scheme' => 'HTTPS'],
                                    'periodSeconds' => 10,
                                ],
                                'resources' => ['requests' => ['cpu' => '50m', 'memory' => '128Mi'], 'limits' => ['memory' => '512Mi']],
                                'securityContext' => $security,
                                'volumeMounts' => [
                                    ['name' => 'config', 'mountPath' => '/openbao/config', 'readOnly' => true],
                                    ['name' => 'tls', 'mountPath' => '/openbao/tls', 'readOnly' => true],
                                    ['name' => 'data', 'mountPath' => '/openbao/data'],
                                    ['name' => 'tmp', 'mountPath' => '/tmp'],
                                ],
                            ],
                            [
                                'name' => 'unsealer',
                                'image' => $image,
                                'command' => ['sh', '-c', $unseal],
                                'env' => $env,
                                'resources' => ['requests' => ['cpu' => '5m', 'memory' => '16Mi'], 'limits' => ['memory' => '64Mi']],
                                'securityContext' => $security,
                                'volumeMounts' => [
                                    ['name' => 'tls', 'mountPath' => '/openbao/tls', 'readOnly' => true],
                                    ['name' => 'unseal', 'mountPath' => '/openbao/unseal', 'readOnly' => true],
                                ],
                            ],
                        ],
                        'volumes' => [
                            ['name' => 'config', 'configMap' => ['name' => 'openbao-config']],
                            ['name' => 'tls', 'secret' => ['secretName' => self::TLS_SECRET]],
                            // optional: the pod starts before the first initialization writes it
                            ['name' => 'unseal', 'secret' => ['secretName' => self::UNSEAL_SECRET, 'optional' => true]],
                            // a JSON object: an empty PHP array would be a list, which Kubernetes refuses
                            ['name' => 'tmp', 'emptyDir' => new \stdClass],
                        ],
                    ],
                ],
                'volumeClaimTemplates' => [$claim],
            ],
        ];
    }

    /** The image's tag (OpenBao's version as RunR runs it). */
    public static function version(string $image): string
    {
        return (string) (ImageReference::split($image)['tag'] ?? 'latest');
    }
}
