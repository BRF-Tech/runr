<?php

namespace App\Services\Concerns;

use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\KubernetesClient;

/**
 * Deploys a standalone cAdvisor DaemonSet with 1s housekeeping interval.
 * Works on any Kubernetes platform (EKS, AKS, GKE, OpenStack, k3s, etc.)
 * because it runs as a privileged pod — no kubelet configuration required.
 *
 * Produces the same Prometheus metric names as the built-in kubelet cAdvisor
 * (container_cpu_usage_seconds_total, container_memory_working_set_bytes, etc.)
 * so no query changes are needed.
 */
trait KnativeCAdvisorTrait
{
    /**
     * Deploy cAdvisor DaemonSet + RBAC into the observability namespace.
     * Called after Prometheus is installed so the scrape target is ready.
     */
    private function deployCAdvisor(KubernetesClient $k8s, string $ns): void
    {
        $clusterKinds = ['ClusterRole', 'ClusterRoleBinding'];

        // containerd socket differs per distro: /run/containerd (kubeadm, managed k8s) vs
        // /run/k3s/containerd (k3s, rke2) — detected from the nodes' containerRuntimeVersion
        $nodes = $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
        $socket = ContainerRuntimeHelper::resolveCluster($nodes)['socket'];

        foreach ($this->cAdvisorManifests($ns, $socket) as $manifest) {
            if (in_array($manifest['kind'], $clusterKinds)) {
                $k8s->applyClusterResource($manifest);
            } else {
                $k8s->applyResource($manifest, $ns);
            }
        }
    }

    // ── Kubernetes manifests ─────────────────────────────────────────────────

    private function cAdvisorManifests(string $ns, string $socket = ContainerRuntimeHelper::CONTAINERD_SOCKET): array
    {
        return [
            $this->cAdvisorServiceAccount($ns),
            $this->cAdvisorClusterRole(),
            $this->cAdvisorClusterRoleBinding($ns),
            $this->cAdvisorDaemonSet($ns, $socket),
            $this->cAdvisorPodMonitor($ns),
            $this->cAdvisorRecordingRules($ns),
        ];
    }

    private function cAdvisorServiceAccount(string $ns): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'ServiceAccount',
            'metadata' => ['name' => 'cadvisor', 'namespace' => $ns],
        ];
    }

    private function cAdvisorClusterRole(): array
    {
        return [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRole',
            'metadata' => ['name' => 'cadvisor-runr'],
            'rules' => [[
                'apiGroups' => [''],
                'resources' => ['nodes', 'nodes/metrics', 'pods'],
                'verbs' => ['get', 'list', 'watch'],
            ]],
        ];
    }

    private function cAdvisorClusterRoleBinding(string $ns): array
    {
        return [
            'apiVersion' => 'rbac.authorization.k8s.io/v1',
            'kind' => 'ClusterRoleBinding',
            'metadata' => ['name' => 'cadvisor-runr'],
            'roleRef' => [
                'apiGroup' => 'rbac.authorization.k8s.io',
                'kind' => 'ClusterRole',
                'name' => 'cadvisor-runr',
            ],
            'subjects' => [[
                'kind' => 'ServiceAccount',
                'name' => 'cadvisor',
                'namespace' => $ns,
            ]],
        ];
    }

    private function cAdvisorDaemonSet(string $ns, string $socket = ContainerRuntimeHelper::CONTAINERD_SOCKET): array
    {
        $labels = ['app.kubernetes.io/name' => 'cadvisor'];
        $socketDir = dirname($socket);

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => ['name' => 'cadvisor', 'namespace' => $ns, 'labels' => $labels],
            'spec' => [
                'selector' => ['matchLabels' => $labels],
                'template' => [
                    'metadata' => ['labels' => $labels],
                    'spec' => [
                        'serviceAccountName' => 'cadvisor',
                        'terminationGracePeriodSeconds' => 30,
                        'hostPID' => true,
                        'tolerations' => [
                            // Tolerate any taint so cAdvisor runs on every node,
                            // including nodes with custom taints (e.g. Knative serving workers).
                            ['operator' => 'Exists'],
                        ],
                        'containers' => [[
                            'name' => 'cadvisor',
                            'image' => 'gcr.io/cadvisor/cadvisor:v0.49.2',
                            'args' => [
                                '--housekeeping_interval=1s',
                                '--store_container_labels=false',
                                '--whitelisted_container_labels=io.kubernetes.container.name,io.kubernetes.pod.name,io.kubernetes.pod.namespace,io.kubernetes.pod.uid',
                                '--enable_metrics=cpu,cpuLoad,memory,oom_event',
                                // Explicitly point to the containerd socket.
                                // On distros where /var/run is NOT a symlink to /run,
                                // cAdvisor cannot discover containers via the socket and
                                // therefore cannot attach K8s labels (namespace/pod/container)
                                // to metrics — which makes all Prometheus queries return nothing.
                                // k3s/rke2: /run/k3s/containerd/containerd.sock
                                '--containerd='.$socket,
                                '--containerd-namespace=k8s.io',
                            ],
                            'ports' => [['name' => 'http', 'containerPort' => 8080, 'protocol' => 'TCP']],
                            'resources' => [
                                'requests' => ['cpu' => '150m', 'memory' => '200Mi'],
                                'limits' => ['cpu' => '300m', 'memory' => '400Mi'],
                            ],
                            'securityContext' => [
                                'privileged' => false,
                                'capabilities' => ['drop' => ['ALL'], 'add' => ['SYS_PTRACE']],
                            ],
                            'volumeMounts' => [
                                ['name' => 'rootfs',     'mountPath' => '/rootfs',        'readOnly' => true],
                                ['name' => 'var-run',    'mountPath' => '/var/run',        'readOnly' => false],
                                ['name' => 'sys',        'mountPath' => '/sys',            'readOnly' => true],
                                ['name' => 'dev-disk',   'mountPath' => '/dev/disk',       'readOnly' => true],
                                // Covers distros where /var/run → /run symlink is absent
                                ['name' => 'containerd', 'mountPath' => $socketDir, 'readOnly' => true],
                            ],
                        ]],
                        'volumes' => [
                            ['name' => 'rootfs',     'hostPath' => ['path' => '/']],
                            ['name' => 'var-run',    'hostPath' => ['path' => '/var/run']],
                            ['name' => 'sys',        'hostPath' => ['path' => '/sys']],
                            ['name' => 'dev-disk',   'hostPath' => ['path' => '/dev/disk']],
                            ['name' => 'containerd', 'hostPath' => ['path' => $socketDir]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * PrometheusRule — pre-computes CPU and memory metrics every 2s.
     * Queries against runr:* metrics are instant (label lookup only),
     * compared to computing rate() over raw counters at query time.
     */
    private function cAdvisorRecordingRules(string $ns): array
    {
        return [
            'apiVersion' => 'monitoring.coreos.com/v1',
            'kind' => 'PrometheusRule',
            // part-of=runr: what an operator-managed Prometheus selects (applyScrapeResourcesForExternalPrometheus)
            'metadata' => ['name' => 'runr-metrics', 'namespace' => $ns, 'labels' => ['release' => 'knative', 'app.kubernetes.io/part-of' => 'runr']],
            'spec' => [
                'groups' => [
                    [
                        // Instant metrics — evaluated every 2s, matches cAdvisor 1s scrape interval.
                        // irate() uses only the last 2 samples (~1-2s lag vs rate([2m])'s 2min lag).
                        // sum by(...) aggregates duplicates: cAdvisor sometimes reports the same
                        // container via multiple label sets (different id/image labels). Without
                        // aggregation, Prometheus drops N-1 with "different value but same timestamp".
                        // namespace!="" filters out non-Kubernetes system containers.
                        'name' => 'runr.instant',
                        'interval' => '2s',
                        'rules' => [
                            [
                                'record' => 'runr:cpu_cores:rate2m',
                                'expr' => 'sum by (namespace, pod, container) (irate(container_cpu_usage_seconds_total{namespace!=""}[30s]))',
                            ],
                            [
                                'record' => 'runr:memory_mb:current',
                                'expr' => 'sum by (namespace, pod, container) (container_memory_working_set_bytes{namespace!=""}) / 1048576',
                            ],
                        ],
                    ],
                    [
                        // Averages — evaluated every 1m is sufficient for 1h window.
                        // avg by(...) collapses any residual multi-series data from old TSDB
                        // (transition period after the sum by fix in runr.instant).
                        'name' => 'runr.averages',
                        'interval' => '1m',
                        'rules' => [
                            [
                                'record' => 'runr:cpu_cores:avg1h',
                                'expr' => 'avg by (namespace, pod, container) (avg_over_time(runr:cpu_cores:rate2m[1h]))',
                            ],
                            [
                                'record' => 'runr:memory_mb:avg1h',
                                'expr' => 'avg by (namespace, pod, container) (avg_over_time(runr:memory_mb:current[1h]))',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * PodMonitor — discovered automatically by Prometheus Operator.
     * Survives helm upgrades and operator reconciliations (unlike additionalScrapeConfigs).
     */
    private function cAdvisorPodMonitor(string $ns): array
    {
        return [
            'apiVersion' => 'monitoring.coreos.com/v1',
            'kind' => 'PodMonitor',
            'metadata' => ['name' => 'cadvisor-runr', 'namespace' => $ns, 'labels' => ['app.kubernetes.io/part-of' => 'runr']],
            'spec' => [
                'selector' => ['matchLabels' => ['app.kubernetes.io/name' => 'cadvisor']],
                'namespaceSelector' => ['matchNames' => [$ns]],
                'podMetricsEndpoints' => [[
                    'port' => 'http',
                    'interval' => '1s',
                    'scrapeTimeout' => '1s',
                    'scheme' => 'http',
                    'honorLabels' => true,
                    'relabelings' => [[
                        'sourceLabels' => ['__meta_kubernetes_pod_node_name'],
                        'targetLabel' => 'node',
                    ]],
                    // Standalone cAdvisor uses container_label_io_kubernetes_* labels.
                    // Remap them to the standard namespace/pod/container labels so our
                    // Prometheus queries work identically to kubelet-integrated cAdvisor.
                    'metricRelabelings' => [
                        ['sourceLabels' => ['container_label_io_kubernetes_pod_namespace'], 'targetLabel' => 'namespace'],
                        ['sourceLabels' => ['container_label_io_kubernetes_pod_name'],      'targetLabel' => 'pod'],
                        ['sourceLabels' => ['container_label_io_kubernetes_container_name'], 'targetLabel' => 'container'],
                        // Kata Containers: cAdvisor can't introspect VM-isolated containers,
                        // so container_name is empty. Fall back to "kata" when pod_name is set
                        // but container_name is empty (regex: first capture empty, second non-empty).
                        ['sourceLabels' => ['container_label_io_kubernetes_container_name', 'container_label_io_kubernetes_pod_name'],
                            'regex' => ';(.+)', 'replacement' => 'kata', 'targetLabel' => 'container'],
                        // Drop non-Kubernetes system cgroups: keep anything with a pod name.
                        ['sourceLabels' => ['container_label_io_kubernetes_pod_name'], 'regex' => '.+', 'action' => 'keep'],
                    ],
                ]],
            ],
        ];
    }
}
