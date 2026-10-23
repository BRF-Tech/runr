<?php

namespace App\Services\Secrets\External;

use App\Models\KnativeRegion;
use App\Models\ProjectSecret;
use App\Models\RegionSecretSetting;
use App\Services\HelmService;
use App\Services\KubernetesClient;
use App\Support\Images\ImageReference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * External Secrets Operator on a region's cluster (docs/secret-stores.md), the optional region
 * feature that syncs project secrets which are references to an organization's secret store. A
 * pinned chart (config secrets.external.chart_version) with RunR's mirror of its image; only
 * namespaced SecretStores and ExternalSecrets are processed — no ClusterSecretStore, no
 * PushSecret: a store is usable in the namespace RunR renders it in, by the projects RunR renders
 * there.
 *
 * Helm runs without --wait; refreshStatus() moves installing → ready once the controller, the
 * webhook and the cert controller run (the status sync and GET …/external-secrets call it).
 */
class ExternalSecretsInstallService
{
    public function __construct(private readonly HelmService $helm) {}

    /**
     * Install (or bring to this release's chart) the operator. The status is "installing" until
     * refreshStatus() sees it run.
     *
     * @throws Throwable the install failed (the status says why)
     */
    public function install(KnativeRegion $region): RegionSecretSetting
    {
        $settings = RegionSecretSetting::forRegion($region);
        if (! $region->kubeconfig || $region->is_demo) {
            throw new RuntimeException(__('api.region.kubeconfig_missing'));
        }

        $settings->fill(['eso_status' => RegionSecretSetting::ESO_INSTALLING, 'eso_message' => null])->save();

        try {
            $this->helm->repoAdd((string) config('secrets.external.chart_repo_name'), (string) config('secrets.external.chart_repo'));
            $this->helm->repoUpdate();
            $result = $this->helm->install(
                release: (string) config('secrets.external.release'),
                chart: (string) config('secrets.external.chart'),
                namespace: (string) config('secrets.external.namespace'),
                setValues: [],
                kubeconfigYaml: $region->kubeconfig,
                version: (string) config('secrets.external.chart_version'),
                valuesArray: self::values(),
                wait: false,
            );
            if (! $result->success) {
                throw new RuntimeException('External Secrets Operator: '.trim($result->error));
            }

            $settings->fill([
                'eso_status' => RegionSecretSetting::ESO_INSTALLING,
                'eso_message' => null,
                'eso_chart_version' => (string) config('secrets.external.chart_version'),
                'eso_installed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $settings->fill(['eso_status' => RegionSecretSetting::ESO_ERROR, 'eso_message' => mb_substr($e->getMessage(), 0, 2000)])->save();
            Log::warning('External Secrets Operator install failed', ['region' => $region->name, 'error' => $e->getMessage()]);

            throw $e;
        }

        return $settings;
    }

    /**
     * Remove the operator. Refused while projects on the region have references (their Secrets
     * would go with the operator's CRDs).
     *
     * @throws RuntimeException
     */
    public function uninstall(KnativeRegion $region): void
    {
        $settings = RegionSecretSetting::forRegion($region);
        if ($this->referencesOn($region) > 0) {
            throw new RuntimeException(__('api.secret.eso_in_use', ['region' => $region->name]));
        }

        // One release per cluster: another region on it keeps the operator
        $sibling = RegionSecretSetting::siblingWith($region, fn (RegionSecretSetting $s) => $s->eso_status !== RegionSecretSetting::ESO_NOT_INSTALLED);
        if ($sibling !== null) {
            $settings->fill([
                'eso_status' => RegionSecretSetting::ESO_NOT_INSTALLED,
                'eso_message' => "The operator stays on the cluster: region {$sibling} uses it",
                'eso_chart_version' => null,
                'eso_installed_at' => null,
            ])->save();

            return;
        }

        $result = $this->helm->uninstall((string) config('secrets.external.release'), (string) config('secrets.external.namespace'), (string) $region->kubeconfig);
        if (! $result->success && ! str_contains($result->error, 'not found')) {
            $settings->fill(['eso_status' => RegionSecretSetting::ESO_ERROR, 'eso_message' => mb_substr(trim($result->error), 0, 2000)])->save();

            throw new RuntimeException('External Secrets Operator: '.trim($result->error));
        }

        $settings->fill([
            'eso_status' => RegionSecretSetting::ESO_NOT_INSTALLED,
            'eso_message' => null,
            'eso_chart_version' => null,
            'eso_installed_at' => null,
        ])->save();
    }

    /**
     * installing → ready once every Deployment of the release has its replicas available; error
     * after 15 minutes without. Returns the settings as they are now.
     */
    public function refreshStatus(KnativeRegion $region, ?KubernetesClient $k8s = null): RegionSecretSetting
    {
        $settings = RegionSecretSetting::forRegion($region);
        if ($settings->eso_status !== RegionSecretSetting::ESO_INSTALLING || ! $region->kubeconfig) {
            return $settings;
        }

        try {
            $k8s ??= new KubernetesClient($region->kubeconfig);
            $deployments = $k8s->listResourcesOrFail('apps/v1', 'Deployment', (string) config('secrets.external.namespace'), [
                'app.kubernetes.io/instance' => (string) config('secrets.external.release'),
            ]);
            $ready = $deployments !== [] && collect($deployments)->every(fn (array $d) => (int) ($d['status']['availableReplicas'] ?? 0) >= max(1, (int) ($d['spec']['replicas'] ?? 1)));
            $crd = $k8s->hasApiVersion(ExternalSecretManifests::API_VERSION);

            if ($ready && $crd) {
                $settings->fill(['eso_status' => RegionSecretSetting::ESO_READY, 'eso_message' => null])->save();
            } elseif ($settings->eso_installed_at && $settings->eso_installed_at->lt(now()->subMinutes(15))) {
                $settings->fill(['eso_status' => RegionSecretSetting::ESO_ERROR, 'eso_message' => 'External Secrets Operator did not become ready within 15 minutes; see its pods in namespace '.config('secrets.external.namespace').'.'])->save();
            }
        } catch (Throwable $e) {
            Log::info('External Secrets Operator status not read', ['region' => $region->name, 'error' => $e->getMessage()]);
        }

        return $settings;
    }

    /**
     * Wait until the operator runs (refreshStatus() every 10 seconds) — a region move installs
     * OpenBao right after it, which needs it.
     *
     * @throws RuntimeException not ready within $seconds
     */
    public function waitReady(KnativeRegion $region, int $seconds = 300): void
    {
        $deadline = time() + $seconds;
        while (true) {
            if ($this->refreshStatus($region)->esoReady()) {
                return;
            }
            if (time() >= $deadline) {
                throw new RuntimeException("External Secrets Operator is not ready on {$region->name} after {$seconds} seconds");
            }
            Sleep::sleep(10);
        }
    }

    /** The chart version the release runs on the region's cluster (null: not installed, unknown). */
    public function deployedVersion(KnativeRegion $region): ?string
    {
        return $this->helm->deployedChartVersion(
            (string) config('secrets.external.release'),
            (string) config('secrets.external.namespace'),
            (string) $region->kubeconfig,
            'external-secrets',
        );
    }

    /** How many secret variables of the projects on the region are references (current context). */
    public function referencesOn(KnativeRegion $region): int
    {
        return ProjectSecret::query()
            ->where('source', ProjectSecret::SOURCE_EXTERNAL)
            ->whereHas('project', fn ($q) => $q->where('knative_region_id', $region->getKey()))
            ->count();
    }

    /**
     * The chart values: RunR's image mirror for the controller, the webhook and the cert
     * controller (one image), namespaced stores only, small requests.
     */
    public static function values(): array
    {
        $image = ImageReference::split((string) config('secrets.external.image'));
        $ref = [
            'repository' => trim(($image['registry'] !== '' ? $image['registry'].'/' : '').$image['repository'], '/'),
            'tag' => (string) ($image['tag'] ?? 'latest'),
        ];
        $requests = ['requests' => ['cpu' => '10m', 'memory' => '64Mi'], 'limits' => ['memory' => '256Mi']];

        return [
            'installCRDs' => true,
            'image' => $ref,
            'webhook' => ['image' => $ref, 'resources' => $requests],
            'certController' => ['image' => $ref, 'resources' => $requests],
            'resources' => $requests,
            // Namespaced SecretStores and ExternalSecrets only
            'processClusterStore' => false,
            'processClusterExternalSecret' => false,
            'processPushSecret' => false,
            // the chart (0.19) refuses ClusterPushSecrets on without PushSecrets
            'processClusterPushSecret' => false,
            'serviceMonitor' => ['enabled' => false],
        ];
    }
}
