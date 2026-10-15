<?php

namespace App\Services\Manifests;

class ZotTestManifests
{
    public static function testPvc(string $storageClass, string $size, string $namespace): array
    {
        $name = 'runr-pvc-test-'.substr(md5(uniqid()), 0, 8);

        return [
            'apiVersion' => 'v1',
            'kind' => 'PersistentVolumeClaim',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'pvc-test'],
            ],
            'spec' => [
                'accessModes' => ['ReadWriteOnce'],
                'storageClassName' => $storageClass,
                'resources' => ['requests' => ['storage' => $size]],
            ],
        ];
    }

    public static function pvcConsumerPod(string $pvcName, string $namespace): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $pvcName.'-consumer',
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'pvc-test'],
            ],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 'pvc-consumer',
                    'image' => 'ghcr.io/brf-tech/reqs/busybox:latest',
                    'command' => ['sh', '-c', 'exit 0'],
                    'volumeMounts' => [['name' => 'test-vol', 'mountPath' => '/mnt/test']],
                    'resources' => [
                        'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                        'limits' => ['cpu' => '10m', 'memory' => '16Mi'],
                    ],
                ]],
                'volumes' => [['name' => 'test-vol', 'persistentVolumeClaim' => ['claimName' => $pvcName]]],
            ],
        ];
    }

    public static function s3TestPod(array $s3, string $namespace): array
    {
        $name = 'runr-s3-test-'.substr(md5(uniqid()), 0, 8);

        $endpoint = rtrim($s3['endpoint'], '/');
        $bucket = $s3['bucket'];
        $script = "curl -sf -o /dev/null -w '%{http_code}' --max-time 10 '{$endpoint}/{$bucket}/' && echo 'S3_OK' || echo 'S3_FAIL'";

        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $name,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 's3-test'],
            ],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 's3-test',
                    'image' => 'ghcr.io/brf-tech/reqs/curl:latest',
                    'command' => ['sh', '-c', $script],
                    'resources' => [
                        'requests' => ['cpu' => '50m', 'memory' => '32Mi'],
                        'limits' => ['cpu' => '50m', 'memory' => '32Mi'],
                    ],
                ]],
            ],
        ];
    }

    public static function registryTestPod(string $url, ?string $user, ?string $pass, string $namespace): array
    {
        $name = 'runr-registry-test-'.substr(md5(uniqid()), 0, 8);
        $registryUrl = rtrim($url, '/').'/v2/';

        $curlCmd = "curl -sf -o /dev/null -w '%{http_code}' --max-time 10";
        if ($user && $pass) {
            $curlCmd .= " -u '{$user}:{$pass}'";
        }
        $curlCmd .= " '{$registryUrl}' && echo 'REGISTRY_OK' || echo 'REGISTRY_FAIL'";

        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $name,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/purpose' => 'registry-test'],
            ],
            'spec' => [
                'restartPolicy' => 'Never',
                'containers' => [[
                    'name' => 'registry-test',
                    'image' => 'ghcr.io/brf-tech/reqs/curl:latest',
                    'command' => ['sh', '-c', $curlCmd],
                    'resources' => [
                        'requests' => ['cpu' => '50m', 'memory' => '32Mi'],
                        'limits' => ['cpu' => '50m', 'memory' => '32Mi'],
                    ],
                ]],
            ],
        ];
    }
}
