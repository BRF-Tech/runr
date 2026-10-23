<?php

namespace App\Services;

use App\Models\KnativeRegion;
use App\Models\Setting;
use App\Services\Ai\Gpu\GpuInstallService;
use App\Services\CertManager\CertManagerService;
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
use App\Services\Helpers\RequestTimeout;
use App\Services\InternalNetwork\RegionInternalNetwork;
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
     * Install Knative via official Helm chart (full pipeline): the operator, then Serving. What
     * already runs on the cluster — another region's Knative on the same cluster, or a Knative
     * installed before — is adopted instead, as the component installs do: installing the
     * operator again would first remove it and the Serving CRDs (every Knative Service on the
     * cluster), applying the KnativeServing again would replace the other region's domain.
     */
    public function install(KnativeRegion $region): void
    {
        $region->update([
            'knative_status' => 'installing',
            'knative_status_message' => '',
        ]);

        try {
            $operatorNs = $region->knative_operator_namespace ?? self::HELM_NAMESPACE;
            $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
            $running = $this->runningKnative($region);

            if ($running['operator']) {
                $this->adoptOperator($region, $operatorNs);
                $this->appendLog($region, "Knative Operator already runs in {$operatorNs}: adopted.");
            } else {
                $this->installOperator($region, $operatorNs);
                $this->aggregateLog($region, 'operator');
            }

            if ($running['serving']) {
                app(KnativeStatusService::class)->adoptRunningServing($region, $servingNs);
                $this->appendLog($region, "A Ready KnativeServing already runs in {$servingNs}: adopted.");
            } else {
                $this->installServing($region, $servingNs, $region->knative_serving_version);
                $this->aggregateLog($region, 'serving');
            }

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
     * The region takes over the Knative Operator that runs on its cluster (runningKnative()).
     */
    public function adoptOperator(KnativeRegion $region, string $namespace): void
    {
        $region->update([
            'knative_operator_status' => 'ready',
            'knative_operator_namespace' => $namespace,
            'knative_operator_status_message' => 'Adopted from existing cluster installation',
            'knative_status_checked_at' => now(),
        ]);
    }

    /**
     * Install Knative Operator via Helm chart. An operator that already runs in the namespace
     * (its Deployments available, the KnativeServing CRD registered) is adopted: the install
     * starts by removing the previous installation, which must never hit a working one.
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

            if ($this->runningKnative($region)['operator']) {
                $this->appendComponentLog($region, 'operator', "Knative Operator already runs in {$namespace}: adopted, nothing installed.");
                $this->adoptOperator($region, $namespace);

                return;
            }

            $this->appendComponentLog($region, 'operator', 'Cleaning up previous operator installation...');
            $this->cleanupOperator($region, keepCrdsInUse: true);

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
     * A region's monitoring changed after its Serving install (PUT …/monitoring): RunR's own
     * Prometheus and / or Loki installed where the region uses them again ($managed: prometheus,
     * loki — as the Serving install does), the scrape resources for an existing Prometheus
     * applied ($externalPrometheus). A component switched to an existing instance keeps RunR's
     * own release running: regions on the same cluster may still use it.
     *
     * @param  list<string>  $managed
     * @return list<string> what was done
     */
    public function applyMonitoring(KnativeRegion $region, array $managed, bool $externalPrometheus): array
    {
        $k8s = new KubernetesClient($region->kubeconfig);
        $helm = app(HelmService::class);
        $servingNs = $region->knative_serving_namespace ?: 'knative-serving';
        $storageClass = $region->registry_storage_config['storageClass'] ?? '';
        $done = [];

        if (in_array('prometheus', $managed, true)) {
            $url = $this->installPrometheus($helm, $k8s, $region->kubeconfig, $servingNs, $storageClass);
            $region->update(['prometheus_url' => $url, 'prometheus_managed' => true]);
            $done[] = "RunR's Prometheus installed ({$url})";
        }

        if (in_array('loki', $managed, true)) {
            $url = $this->installLoki($helm, $region->kubeconfig, $storageClass);
            $region->update(['loki_url' => $url, 'loki_managed' => true, 'loki_status' => 'installing', 'loki_org_id' => null]);
            $done[] = "RunR's Loki installed ({$url})";
        }

        if ($managed !== []) {
            $this->applyObservabilityHA($k8s, 'observability');
        }

        if ($externalPrometheus) {
            $done[] = $this->applyScrapeResourcesForExternalPrometheus($k8s, $servingNs)
                ? 'cAdvisor, PodMonitors and runr:* recording rules applied for the existing Prometheus'
                : 'no Prometheus Operator CRDs: scrape resources not applied';
        }

        return $done;
    }

    /**
     * Upgrade Knative Serving to the next minor version ($version, "1.21"; the controller checks
     * it is one minor above the running one and supported by the cluster — Knative upgrades one
     * minor at a time). The operator Helm release moves to that minor's chart first when it is
     * older (an operator installs the Serving versions up to its own), the Serving CRDs of the
     * version are applied, then the KnativeServing resource's spec.version is set and the
     * operator rolls Knative Serving's components; the region's Knative Services keep running.
     * Regions on the same cluster share Knative Serving: it is upgraded for all of them.
     */
    public function upgradeServing(KnativeRegion $region, string $version): void
    {
        $version = ltrim($version, 'v');
        $namespace = $region->knative_serving_namespace ?: 'knative-serving';
        $operatorNs = $region->knative_operator_namespace ?: self::HELM_NAMESPACE;

        $region->update([
            'knative_serving_status' => 'installing',
            'knative_serving_status_message' => '',
        ]);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $helm = app(HelmService::class);
            $from = $region->knative_serving_version;
            $this->appendComponentLog($region, 'serving', "Upgrading Knative Serving {$from} → {$version}...");

            $helm->repoAdd(self::HELM_REPO_NAME, self::HELM_REPO_URL);
            $helm->repoUpdate();
            $chart = Helpers\KnativeVersionHelper::chartForMinor(Helpers\KnativeVersionHelper::minorOf($version) ?? $version, $helm);
            if (! $chart) {
                throw new RuntimeException("No Knative Operator chart for Knative {$version}");
            }

            $running = $helm->deployedChartVersion(self::HELM_RELEASE, $operatorNs, $region->kubeconfig, 'knative-operator');
            if ($running === null || version_compare(ltrim($running, 'v'), $chart['chart'], '<')) {
                $this->appendComponentLog($region, 'serving', 'Upgrading the Knative Operator to chart '.$chart['chart'].($running ? " (from {$running})" : '').'...');
                $result = HelmService::retryTransient(
                    fn () => $helm->install(
                        release: self::HELM_RELEASE,
                        chart: self::HELM_CHART,
                        namespace: $operatorNs,
                        setValues: [],
                        kubeconfigYaml: $region->kubeconfig,
                        version: $chart['chart'],
                    ),
                    fn (HelmResult $failed, int $next, int $wait) => $this->appendComponentLog(
                        $region,
                        'serving',
                        "Helm upgrade hit a transient Kubernetes API error ({$failed->errorLine()}): try {$next} of ".HelmService::TRANSIENT_TRIES." in {$wait} s...",
                    ),
                );
                if (! $result->success) {
                    throw new RuntimeException('Knative Operator upgrade failed: '.$result->error);
                }
                $this->waitForOperatorReady($k8s, $region);
            }

            $this->appendComponentLog($region, 'serving', 'Applying the Knative Serving CRDs of '.$chart['app'].'...');
            $this->installKnativeCrds($k8s, $chart['app']);

            $this->appendComponentLog($region, 'serving', "Setting the KnativeServing version to {$version}...");
            if (! $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $namespace, ['spec' => ['version' => $version]])) {
                throw new RuntimeException("KnativeServing {$namespace}/knative-serving did not take spec.version {$version}");
            }

            $ready = $this->waitFor(function () use ($k8s, $namespace, $version) {
                $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $namespace);
                $running = KnativeStatusService::versionFromServingCr(['status' => $cr['status'] ?? []]);

                return $running !== null
                    && Helpers\KnativeVersionHelper::minorOf($running) === Helpers\KnativeVersionHelper::minorOf($version)
                    && $this->isCrReady($k8s, 'KnativeServing', 'knative-serving', $namespace);
            }, 600, 5);

            if (! $ready) {
                throw new RuntimeException("Knative Serving did not report {$version} ready within 10 minutes (the operator keeps rolling it out)");
            }

            $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $namespace);
            $region->update([
                'knative_serving_version' => KnativeStatusService::versionFromServingCr($cr) ?? $version,
                'knative_serving_status' => 'ready',
                'knative_status_checked_at' => now(),
            ]);
            $this->appendComponentLog($region, 'serving', 'Knative Serving upgraded to '.$region->fresh()->knative_serving_version.'.');
        } catch (Throwable $e) {
            $this->appendComponentLog($region, 'serving', 'ERROR: '.$e->getMessage());
            $region->update([
                'knative_serving_status' => 'error',
                'knative_status_checked_at' => now(),
            ]);
            Log::error('Knative Serving upgrade failed', ['region' => $region->name, 'error' => $e->getMessage()]);

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
            // read / write timeouts of its entry points follow the region's request_timeout
            $this->installTraefik($k8s, $helm, $namespace, $kubeconfig, IngressTrust::forRegion($region), RequestTimeout::forRegion($region));

            // Revision pods admit only Traefik and Knative, the activator only Traefik and the
            // autoscaler (RegionNetworkPolicyManifests); region:reconcile applies them later too
            try {
                foreach (RegionNetworkPolicyService::ensure($k8s, $projectNs, $namespace) as $line) {
                    $this->appendComponentLog($region, 'serving', ucfirst($line).'.');
                }
            } catch (Throwable $e) {
                $this->appendComponentLog($region, 'serving', 'WARNING: NetworkPolicies not applied ('.$e->getMessage().'); region:reconcile applies them.');
            }

            if (! empty($region->selected_workers)) {
                $settings['enable_podspec_features'] = true;
            }
            $this->appendComponentLog($region, 'serving', 'Applying KnativeServing configuration (ingress: traefik)...');
            $this->applyKnativeServingCR($k8s, $settings, $namespace, $version);

            $this->waitForCrReady($k8s, $region, 'KnativeServing', 'knative-serving', 'knative_serving_namespace', 'knative-serving');

            // The internal network (docs/internal-network.md): the authz, its keys and CoreDNS's
            // runr.internal rewrite. The region enforces the projects' allow lists (internal_auth
            // unset: InternalNetwork::modeOf) unless an operator sets audit
            $this->appendComponentLog($region, 'serving', 'Installing the internal network (authz, runr.internal DNS)...');
            try {
                // no Knative Service calls it yet: the authz becomes ready on its own
                foreach (RegionInternalNetwork::ensure($k8s, $namespace, [$region->fresh()], false, false) as $line) {
                    $this->appendComponentLog($region, 'serving', ucfirst($line).'.');
                }
            } catch (Throwable $e) {
                $this->appendComponentLog($region, 'serving', 'WARNING: internal network not installed ('.$e->getMessage().'); region:reconcile installs it.');
            }

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

            // cert-manager and the default ClusterIssuer of custom domains (docs/regions.md#cert-manager):
            // the cluster's own cert-manager is reused, RunR installs one where there is none.
            // Never fails the Serving install; a cert-manager still starting finishes on its own
            // (CertManagerService::refresh(), region:reconcile)
            $this->appendComponentLog($region, 'serving', 'Setting up cert-manager (custom-domain certificates)...');
            app(CertManagerService::class)
                ->installWithServing($region, $k8s, fn (string $m) => $this->appendComponentLog($region, 'serving', 'cert-manager: '.$m));

            $region->update([
                'prometheus_url' => $prometheusUrl,
                'loki_url' => $lokiUrl,
                'loki_status' => $lokiManaged ? 'installing' : 'ready',
                'knative_serving_status' => 'ready',
                'knative_status_checked_at' => now(),
            ]);

            // A region created inactive (or set inactive / to maintenance before its Serving was
            // ready) gets its status block now
            if (! $region->is_active) {
                $this->appendComponentLog($region, 'serving', 'Region is '.$region->region_status.': applying the status block...');
                app(RegionStatusService::class)->syncBlock($region->fresh());
            }

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
