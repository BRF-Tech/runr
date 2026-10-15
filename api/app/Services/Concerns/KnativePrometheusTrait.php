<?php

namespace App\Services\Concerns;

use App\Services\HelmService;
use App\Services\KubernetesClient;

trait KnativePrometheusTrait
{
    use KnativeCAdvisorTrait;

    /**
     * Install kube-prometheus-stack + standalone cAdvisor with 1s housekeeping.
     * cAdvisor runs as a DaemonSet — works on EKS, AKS, GKE, OpenStack, k3s.
     * No kubelet configuration changes required.
     * Returns the in-cluster Prometheus URL.
     *
     * @see https://knative.dev/docs/serving/observability/metrics/collecting-metrics/
     */
    private function installPrometheus(HelmService $helm, KubernetesClient $k8s, string $kubeconfig, string $servingNs, string $storageClass = ''): string
    {
        $ns = 'observability';
        $release = 'knative';
        $volumeName = "prometheus-{$release}-kube-prometheus-st-prometheus-db";

        $helm->repoAdd('prometheus-community', 'https://prometheus-community.github.io/helm-charts');
        $helm->repoUpdate();

        $storageSpec = [
            'volumeClaimTemplate' => [
                'spec' => [
                    'accessModes' => ['ReadWriteOnce'],
                    'resources' => ['requests' => ['storage' => '50Gi']],
                ],
            ],
        ];
        if ($storageClass) {
            $storageSpec['volumeClaimTemplate']['spec']['storageClassName'] = $storageClass;
        }

        $values = [
            'alertmanager' => ['enabled' => false],
            'grafana' => ['enabled' => false],
            // Disable kubelet cAdvisor scraping — replaced by our 1s standalone DaemonSet
            'kubelet' => ['serviceMonitor' => ['cAdvisor' => false]],
            'prometheus' => [
                'prometheusSpec' => [
                    'securityContext' => [
                        'fsGroup' => 1000,
                        'runAsNonRoot' => true,
                        'runAsUser' => 1000,
                        'runAsGroup' => 1000,
                    ],
                    'initContainers' => [[
                        'name' => 'fix-permissions',
                        'image' => 'ghcr.io/runrsh/reqs/busybox:latest',
                        'command' => ['sh', '-c', 'mkdir -p /prometheus/prometheus-db && chown -R 1000:1000 /prometheus'],
                        'volumeMounts' => [['name' => $volumeName, 'mountPath' => '/prometheus']],
                        'securityContext' => ['runAsUser' => 0, 'runAsNonRoot' => false],
                        'resources' => [
                            'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                            'limits' => ['memory' => '32Mi'],
                        ],
                    ]],
                    'retention' => '15d',
                    'storageSpec' => $storageSpec,
                    'podMonitorSelectorNilUsesHelmValues' => false,
                    'serviceMonitorSelectorNilUsesHelmValues' => false,
                    'ruleSelectorNilUsesHelmValues' => false,
                    'additionalScrapeConfigs' => [
                        // Knative Serving pod metrics (RPS, latency) — 1s
                        [
                            'job_name' => 'knative-serving',
                            'scrape_interval' => '15s',
                            'kubernetes_sd_configs' => [['role' => 'pod', 'namespaces' => ['names' => [$servingNs]]]],
                            'relabel_configs' => [[
                                'source_labels' => ['__meta_kubernetes_pod_container_port_name'],
                                'action' => 'keep',
                                'regex' => 'metrics',
                            ]],
                        ],
                        // cAdvisor scraped via PodMonitor CRD (operator auto-discovers, survives reconciles)
                    ],
                ],
            ],
        ];

        $helm->install(
            release: $release,
            chart: 'prometheus-community/kube-prometheus-stack',
            namespace: $ns,
            setValues: [],
            kubeconfigYaml: $kubeconfig,
            valuesArray: $values,
            wait: false,
        );

        // Deploy cAdvisor DaemonSet with 1s housekeeping
        $this->deployCAdvisor($k8s, $ns);

        // In-cluster ClusterIP URL. Reads go through this directly when the RunR pod
        // network can route to it, and fall back to the K8s API service proxy otherwise
        // (KnativeMetricsService::runParallel) — no unauthenticated external exposure.
        return $this->resolvePrometheusUrl($k8s, $ns);
    }

    /**
     * Operator-supplied Prometheus (prometheus_managed = false): RunR installs no Prometheus, but
     * when the cluster runs the Prometheus Operator (monitoring.coreos.com CRDs) it still ships
     * what its metrics queries need, for the operator's Prometheus to pick up:
     *  - the standalone cAdvisor DaemonSet (+ RBAC) and its PodMonitor,
     *  - the runr:* recording rules (PrometheusRule),
     *  - a PodMonitor for the Knative Serving metrics (RunR's own Prometheus gets these as an
     *    additionalScrapeConfig).
     * All carry the label app.kubernetes.io/part-of=runr in the observability namespace; the
     * operator's Prometheus must select them (podMonitorSelector/ruleSelector + namespace selectors).
     * Returns false — nothing applied — when the CRDs are missing.
     */
    private function applyScrapeResourcesForExternalPrometheus(KubernetesClient $k8s, string $servingNs, string $ns = 'observability'): bool
    {
        foreach (['podmonitors.monitoring.coreos.com', 'prometheusrules.monitoring.coreos.com'] as $crd) {
            if (! $k8s->getClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', $crd)) {
                return false;
            }
        }

        if (! $k8s->getClusterResource('v1', 'Namespace', $ns)) {
            $k8s->createClusterResource([
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => ['name' => $ns, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            ]);
        }

        $this->deployCAdvisor($k8s, $ns);
        $k8s->applyResource($this->knativeServingPodMonitor($ns, $servingNs), $ns);

        return true;
    }

    /**
     * PodMonitor equivalent of the "knative-serving" additionalScrapeConfig: every pod in the
     * Serving namespace, on its container port named "metrics".
     */
    private function knativeServingPodMonitor(string $ns, string $servingNs): array
    {
        return [
            'apiVersion' => 'monitoring.coreos.com/v1',
            'kind' => 'PodMonitor',
            'metadata' => [
                'name' => 'knative-serving-runr',
                'namespace' => $ns,
                'labels' => ['app.kubernetes.io/part-of' => 'runr'],
            ],
            'spec' => [
                'selector' => new \stdClass,
                'namespaceSelector' => ['matchNames' => [$servingNs]],
                'podMetricsEndpoints' => [['port' => 'metrics', 'interval' => '15s']],
            ],
        ];
    }

    /**
     * Remove a previously created insecure NodePort Service (regions patched from
     * before this fix) and return the in-cluster ClusterIP URL for Prometheus.
     */
    private function resolvePrometheusUrl(KubernetesClient $k8s, string $ns): string
    {
        $k8s->deleteResource('v1', 'Service', 'prometheus-nodeport', $ns);

        return self::prometheusClusterIpUrl($ns);
    }

    /**
     * ClusterIP URL of the kube-prometheus-stack "operated" Service in the given namespace.
     */
    public static function prometheusClusterIpUrl(string $ns): string
    {
        return 'http://prometheus-operated.'.$ns.':9090';
    }

    /**
     * Configure Knative Serving to export metrics in Prometheus format.
     * Sets metrics-protocol in config-observability ConfigMap per official Knative docs.
     *
     * @see https://knative.dev/docs/serving/observability/metrics/collecting-metrics/
     */
    private function configureKnativeMetrics(KubernetesClient $k8s, string $namespace): void
    {
        $k8s->applyResource([
            'apiVersion' => 'v1',
            'kind' => 'ConfigMap',
            'metadata' => ['name' => 'config-observability', 'namespace' => $namespace],
            'data' => [
                'metrics-protocol' => 'prometheus',
            ],
        ], $namespace);
    }
}
