<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;

trait KnativeAdoptTrait
{
    /**
     * Detect existing Knative components on the cluster and adopt them.
     * Used when adding a second region on the same cluster.
     */
    public function detectAndAdoptComponents(KnativeRegion $region, ?string $only = null): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $result = ['operator' => null, 'serving' => null];

        // Detect operator
        if ((! $only || $only === 'operator') && ! in_array($region->knative_operator_status, ['ready', 'installing'])) {
            $opNs = 'knative-operator';
            if ($k8s->getClusterResource('v1', 'Namespace', $opNs)) {
                $pods = $k8s->listResources('v1', 'Pod', $opNs, []);
                if ($this->evaluatePodHealth($pods) === 'ready') {
                    $region->update([
                        'knative_operator_status' => 'ready',
                        'knative_operator_namespace' => $opNs,
                        'knative_operator_status_message' => 'Adopted from existing cluster installation',
                    ]);
                    $result['operator'] = ['adopted' => true, 'namespace' => $opNs];
                }
            }
        }

        // Detect serving — only a Ready KnativeServing CR counts. A Traefik in knative-serving alone
        // (e.g. pre-installed for the console Ingress) doesn't mean Knative Serving is installed.
        if ((! $only || $only === 'serving') && ! in_array($region->knative_serving_status, ['ready', 'installing'])) {
            $srvNs = 'knative-serving';
            if ($k8s->getClusterResource('v1', 'Namespace', $srvNs)
                && $this->isCrReady($k8s, 'KnativeServing', 'knative-serving', $srvNs)) {
                $this->adoptServing($region, $k8s, $srvNs);
                $result['serving'] = ['adopted' => true, 'namespace' => $srvNs];
            }
        }

        return $result;
    }

    /**
     * Adopt an existing KnativeServing installation for a region.
     */
    private function adoptServing(KnativeRegion $region, KubernetesClient $k8s, string $namespace): void
    {
        $sibling = KnativeRegion::where('id', '!=', $region->id)
            ->where('knative_serving_status', 'ready')
            ->get()
            ->first(fn ($r) => $r->server_url === $region->server_url);

        $updates = [
            'knative_serving_status' => 'ready',
            'knative_serving_namespace' => $namespace,
            'knative_serving_ingress_class' => 'traefik',
            'knative_serving_status_message' => 'Adopted from existing cluster installation',
        ];

        $updates['knative_serving_version'] = $this->servingCrVersion($k8s, $namespace)
            ?? $sibling?->knative_serving_version;

        if ($sibling) {
            $updates['prometheus_url'] = $sibling->prometheus_url;
            $updates['prometheus_managed'] = $sibling->isPrometheusManaged();
            $updates['loki_url'] = $sibling->loki_url;
            $updates['loki_status'] = $sibling->loki_status;
            $updates['loki_managed'] = $sibling->isLokiManaged();
            $updates['loki_org_id'] = $sibling->loki_org_id;
            $updates['external_ip'] = $sibling->external_ip;
        } else {
            // RunR's own in-cluster Services — only for a component RunR manages; an
            // operator-supplied URL (managed flag false) is kept as entered.
            if ($region->isPrometheusManaged()) {
                $updates['prometheus_url'] = 'http://prometheus-operated.observability:9090';
            }
            if ($region->isLokiManaged()) {
                $updates['loki_url'] = 'http://loki-stack.observability.svc.cluster.local:3100';
            }
        }

        $region->update($updates);
        $this->discoverExternalIp($k8s, $region);
    }

    /**
     * Knative Serving version of the running KnativeServing CR (status.version, else spec.version).
     */
    private function servingCrVersion(KubernetesClient $k8s, string $namespace): ?string
    {
        $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $namespace);

        return self::versionFromServingCr($cr);
    }

    public static function versionFromServingCr(?array $cr): ?string
    {
        $version = $cr['status']['version'] ?? $cr['spec']['version'] ?? null;

        return $version ? ltrim((string) $version, 'v') : null;
    }
}
