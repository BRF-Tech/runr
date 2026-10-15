<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeAdoptTrait;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Concerns\KnativeLogTrait;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\KnativeVersionHelper;
use Illuminate\Support\Facades\Log;
use Throwable;

class KnativeStatusService implements KnativeConstants
{
    use KnativeAdoptTrait, KnativeClusterTrait, KnativeLogTrait;

    /**
     * Check cluster connectivity and Knative installation status.
     */
    public function checkStatus(KnativeRegion $region): array
    {
        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            // 1. Connectivity check — getServerVersion() fails if cluster unreachable
            $k8s->getServerVersion();
        } catch (Throwable $e) {
            Log::warning('Cluster connectivity failed', [
                'region' => $region->name,
                'error' => $e->getMessage(),
            ]);

            return $this->statusResult(
                'error',
                'Cannot connect to cluster: '.$e->getMessage(),
                clusterStatus: 'unreachable',
                clusterMessage: $e->getMessage(),
            );
        }

        return $this->checkKnativeComponents($k8s, $region);
    }

    /**
     * Check individual Knative components once cluster is confirmed online.
     */
    private function checkKnativeComponents(KubernetesClient $k8s, KnativeRegion $region): array
    {
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
        $components = $this->buildComponentStatuses($k8s, $region, $servingNs);

        // Serving is ready only with a Ready KnativeServing CR — a healthy Traefik alone
        // (e.g. pre-installed for the console Ingress) is not Knative Serving.
        $servingReady = $this->isCrReady($k8s, 'KnativeServing', 'knative-serving', $servingNs);
        [$traefikHealthy, $unhealthyPods] = $this->checkTraefikPods($k8s, $servingNs);

        // No CR and no Traefik → not installed
        if (! $servingReady && ! $traefikHealthy) {
            return $this->statusResult(
                'not_installed', 'Knative Serving not found',
                clusterStatus: 'online', components: $components,
            );
        }

        if (! $servingReady || ! empty($unhealthyPods)) {
            $reasons = [];
            if (! $servingReady) {
                $reasons[] = 'KnativeServing CR not ready';
            }
            if (! empty($unhealthyPods)) {
                $reasons[] = 'Unhealthy pods: '.implode(', ', $unhealthyPods);
            }

            return $this->statusResult('degraded', implode('; ', $reasons), [
                'serving_ready' => $servingReady, 'traefik_healthy' => $traefikHealthy,
            ], clusterStatus: 'online', components: $components);
        }

        // Refresh external IP
        $this->discoverExternalIp($k8s, $region);

        // Manual / adopted installs leave the version empty — take it from the running CR
        if (! $region->knative_serving_version) {
            $version = $this->servingCrVersion($k8s, $servingNs);
            if ($version) {
                $region->update(['knative_serving_version' => $version]);
            }
        }

        // Region capacity follows its nodes (schedulable ones, or the dedicated workers) minus
        // what the platform pods on them request
        try {
            app(KnativeNodeService::class)->refreshSchedulableCapacity($region, $k8s);
        } catch (Throwable $e) {
            Log::debug('Region capacity refresh failed', ['region' => $region->name, 'error' => $e->getMessage()]);
        }

        // Promote loki_status to 'ready' once its pods are Running
        if ($region->loki_url && $region->loki_status !== 'ready') {
            $lokiPods = $k8s->listResources('v1', 'Pod', 'observability', ['app' => 'loki']);
            if ($lokiPods && ! empty($lokiPods['items']) && $this->evaluatePodHealth($lokiPods) === 'ready') {
                $region->update(['loki_status' => 'ready']);
            }
        }

        return $this->statusResult('ready', 'Knative is running', [
            'serving_ready' => true,
        ], clusterStatus: 'online', components: $components);
    }

    /**
     * Check traefik pod health in serving namespace.
     */
    private function checkTraefikPods(KubernetesClient $k8s, string $servingNs): array
    {
        $traefikPods = $k8s->listResources('v1', 'Pod', $servingNs, [
            'app.kubernetes.io/name' => 'traefik',
        ]);
        $unhealthyPods = [];
        if (! $traefikPods || empty($traefikPods['items'])) {
            return [false, []];
        }
        foreach ($traefikPods['items'] as $pod) {
            $phase = $pod['status']['phase'] ?? 'Unknown';
            if (! in_array($phase, ['Running', 'Succeeded'])) {
                $unhealthyPods[] = 'traefik: '.($pod['metadata']['name'] ?? 'unknown')." ({$phase})";
            }
        }

        return [empty($unhealthyPods), $unhealthyPods];
    }

    /**
     * Build live component statuses from cluster. Skip installing/uninstalling.
     */
    private function buildComponentStatuses(
        KubernetesClient $k8s,
        KnativeRegion $region,
        string $servingNs,
    ): array {
        $components = [];
        $skipStatuses = ['installing', 'uninstalling', 'not_installed'];

        // Operator
        $opStatus = $region->knative_operator_status ?? 'not_installed';
        if (! in_array($opStatus, $skipStatuses)) {
            $opNs = $region->knative_operator_namespace ?? 'knative-operator';
            $pods = $k8s->listResources('v1', 'Pod', $opNs, []);
            $components['operator'] = $this->evaluatePodHealth($pods);
        }

        // Serving: KnativeServing CR Ready (Traefik pods alone don't count)
        $srvStatus = $region->knative_serving_status ?? 'not_installed';
        if (! in_array($srvStatus, $skipStatuses)) {
            $ready = $this->isCrReady($k8s, 'KnativeServing', 'knative-serving', $servingNs);
            $components['serving'] = $ready ? 'ready' : 'error';
        }

        // Isolation: detect RuntimeClass resources on cluster
        $this->detectIsolationRuntimeClasses($k8s, $region);

        return $components;
    }

    /**
     * Detect kata/gvisor RuntimeClass on the cluster and update region status.
     * Only updates if current status is 'not_installed' (won't override installing/ready/error).
     */
    private function detectIsolationRuntimeClasses(KubernetesClient $k8s, KnativeRegion $region): void
    {
        $runtimeClasses = [
            'kata-clh' => 'sandbox_kata_status',
            'gvisor' => 'sandbox_gvisor_status',
        ];

        $updates = [];

        foreach ($runtimeClasses as $rcName => $dbField) {
            $currentStatus = $region->{$dbField} ?? 'not_installed';
            if ($currentStatus !== 'not_installed') {
                continue;
            }

            try {
                $rc = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', $rcName);
                if ($rc) {
                    $updates[$dbField] = 'ready';
                }
            } catch (Throwable) {
                // Skip — can't check RuntimeClass (RBAC etc.)
            }
        }

        if (! empty($updates)) {
            $region->update($updates);
            Log::info('Isolation RuntimeClass detected', [
                'region' => $region->name,
                'detected' => array_keys($updates),
            ]);
        }
    }

    /**
     * Evaluate pod health: all Running/Succeeded → ready, else error.
     */
    private function evaluatePodHealth(?array $podList): string
    {
        if (! $podList || empty($podList['items'])) {
            return 'error';
        }
        foreach ($podList['items'] as $pod) {
            $phase = $pod['status']['phase'] ?? 'Unknown';
            if (! in_array($phase, ['Running', 'Succeeded'])) {
                return 'error';
            }
        }

        return 'ready';
    }

    /**
     * Get component statuses for a region.
     */
    public function getComponentStatus(KnativeRegion $region): array
    {
        return [
            'operator' => [
                'status' => $region->knative_operator_status ?? 'not_installed',
                'message' => $region->knative_operator_status_message,
                'namespace' => $region->knative_operator_namespace ?? 'knative-operator',
            ],
            'serving' => [
                'status' => $region->knative_serving_status ?? 'not_installed',
                'message' => $region->knative_serving_status_message,
                'namespace' => $region->knative_serving_namespace ?? 'knative-serving',
                'ingress_class' => $region->knative_serving_ingress_class ?? 'traefik',
                'version' => $region->knative_serving_version,
            ],
            'sandbox' => [
                'kata' => ['status' => $region->sandbox_kata_status ?? 'not_installed', 'message' => $region->sandbox_kata_status_message, 'hypervisor' => $region->sandbox_kata_hypervisor],
                'gvisor' => ['status' => $region->sandbox_gvisor_status ?? 'not_installed', 'message' => $region->sandbox_gvisor_status_message],
                'mapping' => [
                    'xaas' => $region->sandbox_xaas_runtime ?? 'runc',
                    'faas' => $region->sandbox_faas_runtime ?? 'runc',
                    'caas' => $region->sandbox_caas_runtime ?? 'runc',
                    'build' => $region->sandbox_build_runtime ?? 'runc',
                ],
            ],
        ];
    }

    /**
     * List compatible Knative minor versions for a cluster (last 5).
     */
    public function listCompatibleVersions(KnativeRegion $region): array
    {
        return KnativeVersionHelper::listForRegion($region);
    }

    /**
     * Check if a namespace exists on the cluster.
     */
    public function checkNamespaceExists(KnativeRegion $region, string $name): bool
    {
        $k8s = new KubernetesClient($region->kubeconfig);

        return $k8s->getClusterResource('v1', 'Namespace', $name) !== null;
    }

    /**
     * Check if a namespace exists using raw kubeconfig (no region required).
     */
    public function checkNamespaceExistsByKubeconfig(string $kubeconfig, string $name): bool
    {
        $k8s = new KubernetesClient($kubeconfig);

        return $k8s->getClusterResource('v1', 'Namespace', $name) !== null;
    }

    /**
     * Create the project namespace on the cluster.
     */
    public function createProjectNamespace(KnativeRegion $region, string $namespace): void
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $this->ensureNamespace($k8s, $namespace);
    }

    /**
     * Refresh external IP for a region (public entry point for scheduled use).
     */
    public function refreshExternalIp(KnativeRegion $region): void
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $ns = $region->knative_serving_namespace ?? 'knative-serving';

        try {
            $svc = $k8s->getResourceStatus('v1', 'Service', 'traefik', $ns);
            $ip = $this->extractLoadBalancerIp($svc);

            if ($ip && $ip !== $region->external_ip) {
                $region->update(['external_ip' => $ip]);
                Log::info('External IP updated', ['region' => $region->name, 'ip' => $ip]);
            }
        } catch (Throwable $e) {
            Log::debug('External IP refresh failed', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }
}
