<?php

namespace App\Services\Concerns;

use App\Services\HelmService;
use App\Services\Helpers\DedicatedWorkers;
use App\Services\Helpers\ManagedLoki;

/**
 * Installs Loki Stack (Loki + Promtail) for centralized log aggregation.
 * Promtail runs as a DaemonSet and ships all container logs to Loki.
 * Logs are queryable via LogQL, replacing kubectl pod log polling.
 */
trait KnativeLokiTrait
{
    /**
     * Install Loki Stack into the observability namespace.
     * Uses Helm with wait=false so the installation runs in the background.
     * Returns the in-cluster Loki URL.
     */
    private function installLoki(HelmService $helm, string $kubeconfig, string $storageClass = ''): string
    {
        $ns = ManagedLoki::NAMESPACE;

        $helm->repoAdd(ManagedLoki::REPO_NAME, ManagedLoki::REPO_URL);
        $helm->repoUpdate();

        $helm->install(
            release: ManagedLoki::RELEASE,
            chart: ManagedLoki::CHART,
            namespace: $ns,
            setValues: [],
            kubeconfigYaml: $kubeconfig,
            valuesArray: self::lokiHelmValues($storageClass),
            wait: false,
        );

        return 'http://loki-stack.'.$ns.'.svc.cluster.local:3100';
    }

    /**
     * loki-stack values. loki.config: the delete API and allowed deletes that log retention
     * (LogRetentionService) uses, with Loki's own retention effectively unlimited (ManagedLoki).
     * Loki tolerates the dedicated-worker taint and prefers the other nodes
     * (DedicatedWorkers::systemPlacement()): its volume may be bound to a node selected as a
     * dedicated worker later.
     */
    public static function lokiHelmValues(string $storageClass = ''): array
    {
        return [
            'loki' => [
                'enabled' => true,
                'config' => ManagedLoki::deletionConfig(),
                'securityContext' => [
                    'fsGroup' => 10001,
                    'runAsNonRoot' => true,
                    'runAsUser' => 10001,
                    'runAsGroup' => 10001,
                ],
                'initContainers' => [[
                    'name' => 'fix-permissions',
                    'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
                    'command' => ['sh', '-c', 'chown -R 10001:10001 /data'],
                    'volumeMounts' => [['name' => 'storage', 'mountPath' => '/data']],
                    'securityContext' => ['runAsUser' => 0, 'runAsNonRoot' => false],
                    'resources' => [
                        'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                        'limits' => ['memory' => '32Mi'],
                    ],
                ]],
                // Filesystem storage — no S3 required for single-node.
                // For multi-node HA switch to s3/gcs storage backend.
                'persistence' => array_filter([
                    'enabled' => true,
                    'size' => '20Gi',
                    'storageClass' => $storageClass ?: null,
                ]),
                'resources' => [
                    'requests' => ['cpu' => '100m', 'memory' => '128Mi'],
                    'limits' => ['cpu' => '500m', 'memory' => '512Mi'],
                ],
            ] + DedicatedWorkers::systemPlacement(),
            'promtail' => [
                'enabled' => true,
                'resources' => [
                    'requests' => ['cpu' => '50m',  'memory' => '64Mi'],
                    'limits' => ['cpu' => '200m', 'memory' => '128Mi'],
                ],
                // Tolerate RunR worker node taints so Promtail runs on every node,
                // including dedicated project worker nodes (runr.dev/dedicated).
                'tolerations' => [
                    ['operator' => 'Exists'],
                ],
            ],
            // Disable bundled Grafana/Prometheus — managed separately
            'grafana' => ['enabled' => false],
            'prometheus' => ['enabled' => false],
        ];
    }
}
