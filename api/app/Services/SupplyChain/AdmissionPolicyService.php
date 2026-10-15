<?php

namespace App\Services\SupplyChain;

use App\Jobs\InstallAdmissionPolicyJob;
use App\Models\AuditEvent;
use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\HelmService;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;

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
 */
class AdmissionPolicyService
{
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

    /** Applies the ConfigMap and the ClusterImagePolicies of the current settings, removes stale ones. */
    public function syncPolicies(KnativeRegion $region): void
    {
        $settings = RegionSupplyChainSetting::forRegion($region);
        $k8s = $this->client($region);

        $k8s->applyResource(AdmissionManifests::configMap($settings), AdmissionManifests::namespace());

        $wanted = [];
        foreach (AdmissionManifests::policies($region, $settings) as $policy) {
            $k8s->applyClusterResource($policy);
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
