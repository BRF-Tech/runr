<?php

namespace App\Services\SupplyChain;

use App\Jobs\InstallAdmissionPolicyJob;
use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\HelmService;
use App\Services\Helpers\WebhookRetry;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Optional admission control of a region: sigstore policy-controller (Helm release
 * runr-policy-controller in cosign-system) enforcing "only images signed by this region's key"
 * in the region's project namespace, with exemptions for RunR's system images and a CaaS policy
 * (AdmissionManifests).
 *
 * Why policy-controller and not Kyverno verifyImages: one webhook Deployment instead of
 * Kyverno's admission, background, cleanup and reports controllers; purpose-built for cosign
 * keys and attestations; opt-in per namespace by label, so system namespaces are never touched;
 * no general policy engine to keep in step with RunR.
 *
 * The ClusterImagePolicies (and its ConfigMap) pass through policy-controller's own webhook
 * (defaulting / validating .clusterimagepolicy.sigstore.dev). Right after the install Helm's wait
 * sees its Deployment available a moment before its Service has a ready endpoint, and the API
 * server then refuses the writes with "failed calling webhook … connection refused": the writes
 * wait for a ready endpoint of the webhook's Service (WEBHOOK_WAIT_SECONDS) and are tried again
 * while the webhook cannot be called (WebhookRetry) — at the install, on a settings change and in
 * region:reconcile alike.
 */
class AdmissionPolicyService
{
    /** Seconds the policy writes wait for a ready endpoint of the webhook's Service */
    public const WEBHOOK_WAIT_SECONDS = 120;

    /** Queues the install (or an update of the policies). */
    public function requestInstall(KnativeRegion $region): RegionSupplyChainSetting
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);

        if (! $settings->hasSigningKey()) {
            throw new \RuntimeException(__('supply_chain.admission.key_required'));
        }
        if (! $region->registry_address) {
            throw new \RuntimeException(__('supply_chain.admission.registry_required'));
        }

        $settings->forceFill([
            'admission_mode' => $settings->admission_mode === 'off' ? 'enforce' : $settings->admission_mode,
            'admission_status' => 'installing',
            'admission_message' => null,
        ])->save();

        InstallAdmissionPolicyJob::dispatch($region, 'install', auth()->id());

        return $settings;
    }

    public function requestUninstall(KnativeRegion $region): RegionSupplyChainSetting
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $settings->forceFill(['admission_status' => 'removing', 'admission_message' => null])->save();

        InstallAdmissionPolicyJob::dispatch($region, 'uninstall', auth()->id());

        return $settings;
    }

    public function install(KnativeRegion $region): void
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $helm = $this->helm();

        $helm->repoAdd((string) config('supply_chain.admission.helm_repo_name'), (string) config('supply_chain.admission.helm_repo_url'));
        $helm->repoUpdate();

        $result = $helm->install(
            release: (string) config('supply_chain.admission.release'),
            chart: (string) config('supply_chain.admission.chart'),
            namespace: AdmissionManifests::namespace(),
            setValues: [],
            kubeconfigYaml: (string) $region->kubeconfig,
            version: (string) config('supply_chain.admission.chart_version') ?: null,
            valuesArray: AdmissionManifests::helmValues(),
        );

        if (! $result->success) {
            throw new \RuntimeException('policy-controller install failed: '.$result->error);
        }

        $this->syncPolicies($region);
        $this->labelProjectNamespace($region, true);

        $settings->forceFill([
            'admission_status' => 'ready',
            'admission_message' => null,
        ])->save();

        SupplyChainAudit::record('region.admission.installed', [
            'mode' => $settings->admission_mode,
            'caas_policy' => $settings->caas_policy,
            'keys' => $settings->trustedFingerprints(),
        ], $region->getKey());
    }

    /**
     * Applies the ConfigMap and the ClusterImagePolicies of the current settings, removes stale
     * ones — once the webhook that admits them answers (waitForWebhook(), WebhookRetry).
     */
    public function syncPolicies(KnativeRegion $region): void
    {
        $settings = RegionSupplyChainSetting::forRegion($region);
        $k8s = $this->client($region);
        $this->waitForWebhook($k8s);
        $retried = fn (\RuntimeException $e, int $wait) => Log::info('Admission policy: the webhook does not answer yet, trying again', ['region' => $region->name, 'in_seconds' => $wait, 'error' => mb_substr($e->getMessage(), 0, 300)]);

        $config = AdmissionManifests::configMap($settings);
        WebhookRetry::run(function () use ($k8s, $config) {
            if (! $k8s->applyResource($config, AdmissionManifests::namespace())) {
                throw new \RuntimeException("ConfigMap {$config['metadata']['name']} was not applied: ".($k8s->lastApplyError() ?? 'unknown error'));
            }
        }, onRetry: $retried);

        $wanted = [];
        foreach (AdmissionManifests::policies($region, $settings) as $policy) {
            WebhookRetry::run(fn () => $k8s->applyClusterResource($policy), onRetry: $retried);
            $wanted[] = $policy['metadata']['name'];
        }

        foreach ([AdmissionManifests::SIGNED_POLICY, AdmissionManifests::SYSTEM_POLICY, AdmissionManifests::CAAS_POLICY] as $name) {
            if (! in_array($name, $wanted, true)) {
                $k8s->deleteClusterResource(AdmissionManifests::API_VERSION, 'ClusterImagePolicy', $name);
            }
        }
    }

    public function uninstall(KnativeRegion $region): void
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $k8s = $this->client($region);

        // Unlabel first: from here on the webhook no longer looks at the project namespace
        $this->labelProjectNamespace($region, false);

        foreach ([AdmissionManifests::SIGNED_POLICY, AdmissionManifests::SYSTEM_POLICY, AdmissionManifests::CAAS_POLICY] as $name) {
            try {
                $k8s->deleteClusterResource(AdmissionManifests::API_VERSION, 'ClusterImagePolicy', $name);
            } catch (\Throwable $e) {
                Log::debug('ClusterImagePolicy delete failed', ['name' => $name, 'error' => $e->getMessage()]);
            }
        }

        $result = $this->helm()->uninstall((string) config('supply_chain.admission.release'), AdmissionManifests::namespace(), (string) $region->kubeconfig);
        if (! $result->success && ! str_contains((string) $result->error, 'not found')) {
            throw new \RuntimeException('policy-controller uninstall failed: '.$result->error);
        }

        $settings->forceFill([
            'admission_status' => 'not_installed',
            'admission_mode' => 'off',
            'admission_message' => null,
        ])->save();

        SupplyChainAudit::record('region.admission.removed', [], $region->getKey());
    }

    public function markFailed(KnativeRegion $region, string $action, string $reason): void
    {
        $settings = RegionSupplyChainSetting::firstOrCreateFor($region);
        $settings->forceFill([
            'admission_status' => 'failed',
            'admission_message' => mb_substr($reason, 0, 1000),
        ])->save();

        SupplyChainAudit::record('region.admission.failed', ['action' => $action, 'reason' => mb_substr($reason, 0, 300)], $region->getKey(), AuditEvent::RESULT_FAILURE);
    }

    /** What install / syncPolicies apply (shown in the region's Supply chain tab). */
    public function preview(KnativeRegion $region): array
    {
        $settings = RegionSupplyChainSetting::forRegion($region);

        return [
            'release' => config('supply_chain.admission.release'),
            'chart' => config('supply_chain.admission.chart'),
            'chart_version' => config('supply_chain.admission.chart_version'),
            'namespace' => AdmissionManifests::namespace(),
            'project_namespace' => $region->project_namespace ?? $region->name,
            'namespace_label' => AdmissionManifests::namespaceLabel(),
            'values' => AdmissionManifests::helmValues(),
            'no_match_policy' => AdmissionManifests::noMatchPolicy($settings),
            'policies' => AdmissionManifests::policies($region, $settings),
        ];
    }

    /**
     * Until the webhook's Service (config supply_chain.admission.webhook_service in the release's
     * namespace) has a ready endpoint, WEBHOOK_WAIT_SECONDS at most: a fresh install's webhook
     * serves a moment after Helm's wait saw its Deployment. When its EndpointSlices cannot be read
     * it goes on at once — the writes are tried again all the same.
     */
    private function waitForWebhook(KubernetesClient $k8s): void
    {
        $service = (string) config('supply_chain.admission.webhook_service', 'webhook');
        for ($waited = 0; ; $waited += 3) {
            $slices = $k8s->listResources('discovery.k8s.io/v1', 'EndpointSlice', AdmissionManifests::namespace(), ['kubernetes.io/service-name' => $service]);
            if ($slices === null || self::hasReadyEndpoint($slices) || $waited >= self::WEBHOOK_WAIT_SECONDS) {
                return;
            }
            Sleep::for(3)->seconds();
        }
    }

    /** An endpoint of the EndpointSlices that takes calls (ready unset counts as ready, as for kube-proxy). */
    public static function hasReadyEndpoint(array $slices): bool
    {
        foreach ((array) ($slices['items'] ?? []) as $slice) {
            foreach ((array) ($slice['endpoints'] ?? []) as $endpoint) {
                if (($endpoint['conditions']['ready'] ?? true) !== false && ! empty($endpoint['addresses'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function labelProjectNamespace(KnativeRegion $region, bool $on): void
    {
        $ns = $region->project_namespace ?? $region->name;

        $this->client($region)->strategicMergePatchCluster('v1', 'Namespace', $ns, [
            'metadata' => ['labels' => [AdmissionManifests::namespaceLabel() => $on ? 'true' : null]],
        ]);
    }

    protected function client(KnativeRegion $region): KubernetesClient
    {
        return new KubernetesClient($region->kubeconfig);
    }

    protected function helm(): HelmService
    {
        return app(HelmService::class);
    }
}
