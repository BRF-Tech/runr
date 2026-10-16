<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\Concerns\KnativeAgentTrait;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Concerns\KnativeCrdTrait;
use App\Services\Concerns\KnativeCRTrait;
use App\Services\Concerns\KnativeHelmTrait;
use App\Services\Concerns\KnativeLogTrait;
use App\Services\Concerns\KnativeLokiTrait;
use App\Services\Concerns\KnativeObservabilityHATrait;
use App\Services\Concerns\KnativePrometheusTrait;
use App\Services\Concerns\KnativeTraefikTrait;
use App\Services\Contracts\KnativeConstants;
use App\Services\Helpers\IngressTrust;
use App\Services\Observability\TracingInstallService;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class KnativeInstallService implements KnativeConstants
{
    use KnativeAgentTrait, KnativeClusterTrait, KnativeCrdTrait, KnativeCRTrait, KnativeHelmTrait, KnativeLogTrait, KnativeLokiTrait, KnativeObservabilityHATrait, KnativePrometheusTrait, KnativeTraefikTrait;

    public function __construct(
        private ?KnativeNodeService $nodeService = null,
    ) {
        $this->nodeService ??= new KnativeNodeService;
    }

    // ── Install (legacy full) ────────────────────────────────────────────────

    /**
     * Install Knative via official Helm chart (full pipeline).
     */
    public function install(KnativeRegion $region): void
    {
        $region->update([
            'knative_status' => 'installing',
            'knative_status_message' => '',
        ]);

        try {
            $this->installOperator($region, $region->knative_operator_namespace ?? self::HELM_NAMESPACE);
            $this->aggregateLog($region, 'operator');

            $this->installServing($region, $region->knative_serving_namespace ?? 'knative-serving');
            $this->aggregateLog($region, 'serving');

            $statusService = new KnativeStatusService;
            $status = $statusService->checkStatus($region);
            $this->appendLog($region, 'Done — '.$status['message']);

            $region->update([
                'knative_status' => $status['status'],
                'knative_status_checked_at' => now(),
            ]);

            Log::info('Knative installation completed', [
                'region' => $region->name,
                'status' => $status['status'],
            ]);
        } catch (Throwable $e) {
            $this->appendLog($region, 'ERROR: '.$e->getMessage());
            $region->update([
                'knative_status' => 'error',
                'knative_status_checked_at' => now(),
            ]);

            Log::error('Knative installation failed', [
                'region' => $region->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    // ── Component-based Install ────────────────────────────────────────────

    /**
     * What of Knative already runs on the region's cluster, whatever the region records: the
     * operator (the KnativeServing CRD registered, its Deployments available) and a Ready
     * KnativeServing. A region moved onto a cluster that already runs Knative — another region on
     * that cluster, or the region's own cluster — keeps them (RegionMigrateService), the way a
     * region installed on such a cluster adopts them (KnativeInstallController):
     * installOperator() starts by removing the operator and the Serving CRDs, which deletes every
     * Knative Service on the cluster.
     *
     * @return array{operator: bool, serving: bool}
     */
    public function runningKnative(KnativeRegion $region): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $operatorNs = $region->knative_operator_namespace ?? self::HELM_NAMESPACE;
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';

        $operator = $k8s->getClusterResource('apiextensions.k8s.io/v1', 'CustomResourceDefinition', 'knativeservings.operator.knative.dev') !== null
            && self::deploymentsAvailable($k8s->listResources('apps/v1', 'Deployment', $operatorNs));

        return [
            'operator' => $operator,
            'serving' => $operator && $this->isCrReady($k8s, 'KnativeServing', 'knative-serving', $servingNs),
        ];
    }

    /** A Deployment list with at least one Deployment, each with all its replicas available. */
    public static function deploymentsAvailable(?array $list): bool
    {
        $items = $list['items'] ?? [];
        if ($items === []) {
            return false;
        }

        foreach ($items as $deployment) {
            $wanted = (int) ($deployment['spec']['replicas'] ?? 1);
            if ((int) ($deployment['status']['availableReplicas'] ?? 0) < max(1, $wanted)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install Knative Operator via Helm chart.
     */
    public function installOperator(KnativeRegion $region, string $namespace = 'knative-operator'): void
    {
        $region->update([
            'knative_operator_status' => 'installing',
            'knative_operator_status_message' => '',
            'knative_operator_namespace' => $namespace,
        ]);

        try {
            $kubeconfig = $region->kubeconfig;

            $this->appendComponentLog($region, 'operator', 'Connecting to cluster...');
            $k8s = new KubernetesClient($kubeconfig);
            $helm = app(HelmService::class);

            $this->appendComponentLog($region, 'operator', 'Cleaning up previous operator installation...');
            $this->cleanupOperator($region);

            $this->appendComponentLog($region, 'operator', 'Checking for orphaned Helm resources...');
            $this->adoptOrphanedHelmResources($k8s, self::HELM_RELEASE, $namespace);

            $this->appendComponentLog($region, 'operator', 'Adding Knative Operator Helm repo...');
            $result = $helm->repoAdd(self::HELM_REPO_NAME, self::HELM_REPO_URL);
            if (! $result->success) {
                throw new RuntimeException('Helm repo add failed: '.$result->error);
            }
            $helm->repoUpdate();

            $versions = $this->resolveOperatorVersion($k8s, $helm, $region);
            $chartVersion = $versions['chart'];
            $appVersion = $versions['app'];

            $this->appendComponentLog($region, 'operator', 'Installing Knative Operator via Helm ('.$chartVersion.')...');
            // A short hiccup of the API server (etcd timing out on a busy disk, a 5xx answer) is
            // tried again a few times; any other failure fails the install at once
            $result = HelmService::retryTransient(
                fn () => $helm->install(
                    release: self::HELM_RELEASE,
                    chart: self::HELM_CHART,
                    namespace: $namespace,
                    setValues: [],
                    kubeconfigYaml: $kubeconfig,
                    version: $chartVersion,
                ),
                fn (HelmResult $failed, int $next, int $wait) => $this->appendComponentLog(
                    $region,
                    'operator',
                    "Helm install hit a transient Kubernetes API error ({$failed->errorLine()}): try {$next} of ".HelmService::TRANSIENT_TRIES." in {$wait} s...",
                ),
            );

            if (! $result->success) {
                throw new RuntimeException('Helm install failed: '.$result->error);
            }
            $this->appendComponentLog($region, 'operator', 'Operator Helm release installed.');

            $this->waitForOperatorReady($k8s, $region);
            $this->appendComponentLog($region, 'operator', 'Knative Operator is ready.');

            $this->appendComponentLog($region, 'operator', 'Installing Knative Serving CRDs...');
            $this->installKnativeCrds($k8s, $appVersion);
            $this->appendComponentLog($region, 'operator', 'CRDs installed.');

            $region->update([
                'knative_operator_status' => 'ready',
                'knative_status_checked_at' => now(),
            ]);

            Log::info('Knative Operator installed', ['region' => $region->name]);

        } catch (Throwable $e) {
            $this->appendComponentLog($region, 'operator', 'ERROR: '.$e->getMessage());
            $region->update([
                'knative_operator_status' => 'error',
                'knative_status_checked_at' => now(),
            ]);
            Log::error('Knative Operator install failed', [
                'region' => $region->name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Install Knative Serving via Operator CR.
     */
    public function installServing(KnativeRegion $region, string $namespace = 'knative-serving', ?string $version = null): void
    {
        $region->update([
            'knative_serving_status' => 'installing',
            'knative_serving_status_message' => '',
            'knative_serving_namespace' => $namespace,
            'knative_serving_ingress_class' => 'traefik',
            'knative_serving_version' => $version,
        ]);

        try {
            $kubeconfig = $region->kubeconfig;
            $settings = $region->effective_settings;

            $this->appendComponentLog($region, 'serving', 'Connecting to cluster...');
            $k8s = new KubernetesClient($kubeconfig);
            $helm = new HelmService;

            // Resolve version from Helm repo if not set or not available.
            // Use appVersion (actual Knative release), not chart version — chart may have
            // patch bumps (e.g. 1.18.3) that have no corresponding GitHub/image release.
            if (! $version) {
                $versions = Helpers\KnativeVersionHelper::resolveVersionPair(
                    Helpers\KnativeVersionHelper::detectK8sMinor($k8s), $helm
                );
                $version = $versions['app'];
            }
            $version = ltrim($version, 'v');
            $region->update(['knative_serving_version' => $version]);
            $this->appendComponentLog($region, 'serving', "Target Knative Serving version: {$version}");

            $this->appendComponentLog($region, 'serving', 'Creating namespace '.$namespace.'...');
            $this->ensureNamespace($k8s, $namespace);

            $projectNs = $region->project_namespace ?? $region->name;
            $this->ensureNamespace($k8s, $projectNs);

            // Organization CPU / RAM ceiling: ResourceQuota + LimitRange in the
            // project namespace, or removal of RunR's own when no ceiling is configured
            $quotaResult = app(RegionNamespaceQuotaService::class)->applyNamespaceQuota($region, $k8s);
            if ($quotaResult !== RegionNamespaceQuotaService::RESULT_SKIPPED) {
                $this->appendComponentLog($region, 'serving', "Organization resource ceiling in {$projectNs}: {$quotaResult}.");
            }

            $this->appendComponentLog($region, 'serving', 'Installing Traefik ingress controller...');
            // PROXY protocol / X-Forwarded-* trusted only from the region's ingress_trusted_ips
            $this->installTraefik($k8s, $helm, $namespace, $kubeconfig, IngressTrust::forRegion($region));

            if (! empty($region->selected_workers)) {
                $settings['enable_podspec_features'] = true;
            }
            $this->appendComponentLog($region, 'serving', 'Applying KnativeServing configuration (ingress: traefik)...');
            $this->applyKnativeServingCR($k8s, $settings, $namespace, $version);

            $this->waitForCrReady($k8s, $region, 'KnativeServing', 'knative-serving', 'knative_serving_namespace', 'knative-serving');

            if (! empty($region->selected_workers)) {
                $this->appendComponentLog($region, 'serving', 'Enabling pod spec features...');
                $this->nodeService->enablePodSpecFeatures($k8s, $namespace);
                $this->appendComponentLog($region, 'serving', 'Applying worker tolerations...');
                $this->nodeService->applySystemTolerations($region);
            }

            // GPU support installed before (re)installing Serving: GPU / model pod spec features (docs/ai.md)
            if (($region->gpu_mode ?? 'none') !== 'none') {
                $this->appendComponentLog($region, 'serving', 'Enabling GPU and model volume pod spec features...');
                app(GpuInstallService::class)->enableKnativeFeatures($k8s, $region);
            }

            $baseDomain = Setting::get('base_domain');
            if ($baseDomain && empty($settings['knative_domain'])) {
                $autoKnativeDomain = $region->name.'.'.$baseDomain;
                $current = $region->knative_settings ?? [];
                $current['knative_domain'] = $autoKnativeDomain;
                $region->update(['knative_settings' => $current]);

                $settings['knative_domain'] = $autoKnativeDomain;
                $this->applyKnativeServingCR($k8s, $settings, $namespace, $version);
                $this->appendComponentLog($region, 'serving', 'Auto-set knative_domain: '.$autoKnativeDomain);
            }

            $this->discoverExternalIp($k8s, $region);
            $this->appendComponentLog($region, 'serving', 'Knative Serving is ready.');

            $this->appendComponentLog($region, 'serving', 'Configuring Knative metrics (Prometheus backend)...');
            $this->configureKnativeMetrics($k8s, $namespace);

            $storageClass = $region->registry_storage_config['storageClass'] ?? '';

            // prometheus_managed/loki_managed are decided at region-creation time (or default
            // true for a first-ever install) — not from URL presence, which would also be true
            // mid-migration for a region that already has its own prior installation's URL.
            $prometheusManaged = $region->isPrometheusManaged();
            if ($prometheusManaged) {
                $this->appendComponentLog($region, 'serving', 'Installing Prometheus monitoring stack (background)...');
                $prometheusUrl = $this->installPrometheus($helm, $k8s, $kubeconfig, $namespace, $storageClass);
            } else {
                $prometheusUrl = $region->prometheus_url;
                $this->appendComponentLog($region, 'serving', "Reusing existing Prometheus at {$prometheusUrl} (install skipped)...");
                try {
                    $this->appendComponentLog($region, 'serving', $this->applyScrapeResourcesForExternalPrometheus($k8s, $namespace)
                        ? 'Prometheus Operator found: applied cAdvisor, PodMonitors and runr:* recording rules (label app.kubernetes.io/part-of=runr).'
                        : 'No Prometheus Operator CRDs (monitoring.coreos.com): cAdvisor/PodMonitors/recording rules not applied.');
                } catch (Throwable $e) {
                    // Optional for an operator-supplied Prometheus — never fails the Serving install
                    $this->appendComponentLog($region, 'serving', 'Scrape resources for the existing Prometheus failed: '.$e->getMessage());
                }
            }

            $lokiManaged = $region->isLokiManaged();
            if ($lokiManaged) {
                $this->appendComponentLog($region, 'serving', 'Installing Loki log aggregation (background)...');
                $lokiUrl = $this->installLoki($helm, $kubeconfig, $storageClass);
            } else {
                $lokiUrl = $region->loki_url;
                $this->appendComponentLog($region, 'serving', "Reusing existing Loki at {$lokiUrl} (install skipped)...");
            }

            // Nothing of ours runs in 'observability' when both components are reused externally.
            if ($prometheusManaged || $lokiManaged) {
                $this->appendComponentLog($region, 'serving', 'Applying observability HA (PriorityClass + PodDisruptionBudgets)...');
                $this->applyObservabilityHA($k8s, 'observability');
            }

            $this->appendComponentLog($region, 'serving', 'Deploying RunR monitoring agent...');
            // Helm normally creates 'observability' (--create-namespace); when both Prometheus
            // and Loki are reused externally neither install ran, so make sure it exists here.
            $this->ensureNamespace($k8s, 'observability');
            $this->deployAgent($k8s, $region);

            // Tracing backend: OpenTelemetry Collector (+ Tempo), Knative tracing (docs/observability.md#tracing).
            // Never fails the Serving install.
            app(TracingInstallService::class)
                ->installWithServing($region, $k8s, $helm, fn (string $m) => $this->appendComponentLog($region, 'serving', $m));

            $region->update([
                'prometheus_url' => $prometheusUrl,
                'loki_url' => $lokiUrl,
                'loki_status' => $lokiManaged ? 'installing' : 'ready',
                'knative_serving_status' => 'ready',
                'knative_status_checked_at' => now(),
            ]);

            Log::info('Knative Serving installed', ['region' => $region->name]);

        } catch (Throwable $e) {
            $this->appendComponentLog($region, 'serving', 'ERROR: '.$e->getMessage());
            $region->update([
                'knative_serving_status' => 'error',
                'knative_status_checked_at' => now(),
            ]);
            Log::error('Knative Serving install failed', [
                'region' => $region->name,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
