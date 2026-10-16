<?php

namespace App\Services\Concerns;

use App\Models\KnativeRegion;
use App\Services\HelmService;
use App\Services\Helpers\KnativeVersionHelper;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

trait KnativeHelmTrait
{
    /**
     * Resolve the best Knative Operator versions for the cluster.
     * Returns ['chart' => helmChartVersion, 'app' => knativeAppVersion].
     * Use 'chart' for Helm install, 'app' for GitHub asset URLs (CRDs etc.).
     *
     * @return array{chart: string, app: string}
     */
    private function resolveOperatorVersion(KubernetesClient $k8s, HelmService $helm, KnativeRegion $region): array
    {
        $k8sMinor = KnativeVersionHelper::detectK8sMinor($k8s);
        $versions = KnativeVersionHelper::resolveVersionPair($k8sMinor, $helm);

        if ($k8sMinor) {
            $this->appendLog($region, "Detected K8s 1.{$k8sMinor}, selected Knative Operator v{$versions['chart']} (serving: v{$versions['app']})");
        } else {
            $this->appendLog($region, "Could not detect K8s version, using fallback v{$versions['chart']}");
        }

        return $versions;
    }

    /**
     * Adopt orphaned Helm cluster-scoped resources by re-annotating release-namespace.
     */
    private function adoptOrphanedHelmResources(KubernetesClient $k8s, string $releaseName, string $targetNamespace): void
    {
        $kinds = [
            ['apiVersion' => 'rbac.authorization.k8s.io/v1', 'kind' => 'ClusterRole'],
            ['apiVersion' => 'rbac.authorization.k8s.io/v1', 'kind' => 'ClusterRoleBinding'],
        ];

        $adopted = 0;

        foreach ($kinds as $def) {
            try {
                $list = $k8s->listClusterResources($def['apiVersion'], $def['kind'], [
                    'app.kubernetes.io/managed-by' => 'Helm',
                ]);

                if (! $list || empty($list['items'])) {
                    continue;
                }

                foreach ($list['items'] as $item) {
                    $annotations = $item['metadata']['annotations'] ?? [];
                    $name = $item['metadata']['name'] ?? '';

                    if (($annotations['meta.helm.sh/release-name'] ?? '') !== $releaseName) {
                        continue;
                    }

                    if (($annotations['meta.helm.sh/release-namespace'] ?? '') === $targetNamespace) {
                        continue;
                    }

                    $k8s->strategicMergePatchCluster($def['apiVersion'], $def['kind'], $name, [
                        'metadata' => [
                            'annotations' => [
                                'meta.helm.sh/release-namespace' => $targetNamespace,
                            ],
                        ],
                    ]);

                    $adopted++;
                    Log::debug('Adopted orphaned Helm resource', [
                        'kind' => $def['kind'],
                        'name' => $name,
                        'oldNamespace' => $annotations['meta.helm.sh/release-namespace'] ?? '(none)',
                        'newNamespace' => $targetNamespace,
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Failed to adopt orphaned Helm resources', [
                    'kind' => $def['kind'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($adopted > 0) {
            Log::info("Adopted {$adopted} orphaned Helm cluster resource(s) for namespace {$targetNamespace}");
        }
    }

    /**
     * Cleanup operator: Helm uninstall + namespace delete.
     */
    private function cleanupOperator(KnativeRegion $region): void
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $helm = app(HelmService::class);

        $ns = $region->knative_operator_namespace ?? self::HELM_NAMESPACE;

        try {
            $helm->uninstall(self::HELM_RELEASE, $ns, $region->kubeconfig);
        } catch (Throwable $e) {
            Log::debug('Cleanup: Helm uninstall skipped', ['error' => $e->getMessage()]);
        }

        $this->removeKnativeCrds($k8s);
        $this->deleteNamespaceIfManaged($k8s, $ns);
    }

    /**
     * Wait for Knative Operator CRD registration + deployment readiness.
     */
    private function waitForOperatorReady(KubernetesClient $k8s, KnativeRegion $region, int $timeout = 60): void
    {
        $this->appendLog($region, 'Verifying operator CRD registration...');

        $ready = $this->waitFor(function () use ($k8s) {
            return $k8s->getClusterResource(
                'apiextensions.k8s.io/v1',
                'CustomResourceDefinition',
                'knativeservings.operator.knative.dev'
            ) !== null;
        }, $timeout);

        if (! $ready) {
            throw new RuntimeException('Timeout: Knative Operator CRD not registered after '.$timeout.'s');
        }

        $this->appendLog($region, 'Operator CRD is registered.');
    }

    /**
     * Full cleanup: delete CRs, Helm releases, and managed namespaces.
     * Traefik (and its namespace) stays while it serves non-Knative Ingresses, unless $removeTraefik.
     */
    private function cleanup(KnativeRegion $region, bool $removeTraefik = false): void
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $helm = new HelmService;

        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
        $operatorNs = $region->knative_operator_namespace ?? self::HELM_NAMESPACE;

        // 1. Delete KnativeServing CR
        try {
            $k8s->deleteResource(
                'operator.knative.dev/v1beta1', 'KnativeServing',
                'knative-serving', $servingNs
            );
            $this->waitFor(fn () => $k8s->getResourceStatus(
                'operator.knative.dev/v1beta1', 'KnativeServing',
                'knative-serving', $servingNs
            ) === null, 60);
        } catch (Throwable $e) {
            Log::debug('Cleanup: KnativeServing CR removal skipped', ['error' => $e->getMessage()]);
        }

        // 2. Uninstall Traefik + RBAC
        $traefikKeptFor = $this->uninstallTraefikIfUnused($k8s, $helm, $servingNs, $region->kubeconfig, $removeTraefik);
        if ($traefikKeptFor) {
            $this->appendLog($region, $this->traefikKeptMessage($traefikKeptFor));
        }

        // 3. Helm uninstall operator
        try {
            $helm->uninstall(self::HELM_RELEASE, $operatorNs, $region->kubeconfig);
        } catch (Throwable $e) {
            Log::debug('Cleanup: Helm uninstall skipped', ['error' => $e->getMessage()]);
        }

        // 4. Clean up namespaces (only if managed by runr; the serving namespace stays with a kept Traefik)
        $namespacesToClean = $traefikKeptFor ? [$operatorNs] : array_unique([$servingNs, $operatorNs]);
        foreach ($namespacesToClean as $ns) {
            $this->deleteNamespaceIfManaged($k8s, $ns);
        }

        // 4. Wait for namespaces to be deleted (best-effort)
        $this->waitFor(function () use ($k8s, $namespacesToClean) {
            foreach ($namespacesToClean as $ns) {
                if ($k8s->getClusterResource('v1', 'Namespace', $ns) !== null) {
                    return false;
                }
            }

            return true;
        }, 90);
    }
}
