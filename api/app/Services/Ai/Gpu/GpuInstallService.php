<?php

namespace App\Services\Ai\Gpu;

use App\Events\PlatformEvent;
use App\Models\KnativeRegion;
use App\Services\HelmService;
use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\KubernetesClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Installs, reconfigures and removes the NVIDIA GPU support of a region (GpuSupportManifests):
 * the device plugin (default) or the GPU Operator through Helm in the `runr-gpu` namespace, the
 * `nvidia` RuntimeClass where the node runtime needs it, and the Knative pod spec features GPU /
 * model workloads use. Every step is logged to gpu_status_message (shown in the region's GPU tab).
 */
class GpuInstallService
{
    public function __construct(
        private HelmService $helm,
        private GpuInventory $inventory,
    ) {}

    /** Default settings merged with the stored and the requested ones. */
    public static function settings(KnativeRegion $region, array $changes = []): array
    {
        $stored = array_merge([
            'time_slicing_replicas' => null,
            'runtime_class_choice' => 'auto',
            'runtime_class' => null,
            'gvisor_nvproxy' => false,
            'operator_driver' => true,
            'operator_toolkit' => true,
            // Device plugin / GFD (operator: operands) only on GPU nodes; gpu_nodes = the chosen
            // ones, null = detected automatically (GpuSupportManifests::gpuNodeNames())
            'restrict_to_gpu_nodes' => true,
            'gpu_nodes' => null,
        ], (array) ($region->gpu_settings ?? []));

        foreach ($changes as $key => $value) {
            if ($key === 'runtime_class') {
                $stored['runtime_class_choice'] = $value ?? 'auto';

                continue;
            }
            $stored[$key] = $value;
        }

        $replicas = $stored['time_slicing_replicas'];
        $stored['time_slicing_replicas'] = is_numeric($replicas) && (int) $replicas > 1
            ? min((int) $replicas, (int) config('ai.gpu.max_time_slicing_replicas', 16))
            : null;

        $stored['restrict_to_gpu_nodes'] = (bool) $stored['restrict_to_gpu_nodes'];
        $nodes = array_values(array_unique(array_filter((array) ($stored['gpu_nodes'] ?? []), fn ($n) => is_string($n) && $n !== '')));
        $stored['gpu_nodes'] = $nodes === [] ? null : $nodes;

        return $stored;
    }

    /**
     * @param  'device-plugin'|'operator'  $mode
     * @param  array  $options  time_slicing_replicas, runtime_class (auto|nvidia|none), operator_driver, operator_toolkit
     */
    public function install(KnativeRegion $region, string $mode, array $options = []): void
    {
        $settings = self::settings($region, $options);
        $region->update([
            'gpu_status' => 'installing',
            'gpu_status_message' => '',
        ]);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $kubeconf = (string) $region->kubeconfig;
            $namespace = (string) config('ai.gpu.namespace', 'runr-gpu');
            $nodes = $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
            $runtime = ContainerRuntimeHelper::resolveCluster($nodes);
            $flavor = $runtime['flavor'];

            $settings['runtime_class'] = GpuSupportManifests::resolveRuntimeClass($settings['runtime_class_choice'], $mode, $flavor);
            $this->log($region, "Node runtime: {$flavor} ({$runtime['runtime_version']})");

            $this->applyNodeLabels($region, $k8s, $mode, $settings, $nodes);

            if ($settings['runtime_class'] !== null && $mode === 'device-plugin') {
                $this->log($region, 'Creating RuntimeClass nvidia (handler nvidia)...');
                $k8s->applyClusterResource(GpuSupportManifests::runtimeClass());
            }

            if ($mode === 'operator') {
                $this->installOperator($region, $k8s, $kubeconf, $namespace, $settings);
            } else {
                $this->installDevicePlugin($region, $kubeconf, $namespace, $settings);
            }

            $this->log($region, 'Enabling Knative pod spec features (node selector, affinity, tolerations, RuntimeClass, PVC)...');
            $this->enableKnativeFeatures($k8s, $region);

            $this->log($region, 'Detecting GPU nodes...');
            $inventory = $this->inventory->refresh($region->fresh(), $k8s);
            $summary = $inventory['summary'] ?? ['allocatable' => 0, 'gpu_nodes' => 0];
            $this->log($region, $summary['allocatable'] > 0
                ? "GPU support ready: {$summary['allocatable']} GPU(s) on {$summary['gpu_nodes']} node(s)."
                : 'GPU support installed; no node advertises nvidia.com/gpu at this moment (the plugin starts on nodes with an NVIDIA GPU once their driver and container toolkit work).');

            $region->refresh();
            $region->update([
                'gpu_mode' => $mode,
                'gpu_status' => 'ready',
                'gpu_settings' => $settings,
            ]);
            $this->broadcast($region, 'gpu.installed');
        } catch (\Throwable $e) {
            Log::error('GPU support install failed', ['region' => $region->name, 'error' => $e->getMessage()]);
            $this->log($region, 'ERROR: '.$e->getMessage(), ['gpu_status' => 'error']);
            $this->broadcast($region, 'gpu.install_failed', ['error' => mb_substr($e->getMessage(), 0, 500)]);

            throw $e;
        }
    }

    /** Re-apply the installed mode with changed settings (time-slicing, RuntimeClass). */
    public function reconfigure(KnativeRegion $region, array $changes): void
    {
        $mode = (string) ($region->gpu_mode ?? 'none');
        if (! in_array($mode, ['device-plugin', 'operator'], true)) {
            // external / none: nothing RunR installed — only RunR-side settings change
            $region->update(['gpu_settings' => self::settings($region, $changes)]);

            return;
        }

        $this->install($region, $mode, $changes);
    }

    public function uninstall(KnativeRegion $region): void
    {
        $region->update(['gpu_status' => 'uninstalling', 'gpu_status_message' => '']);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);
            $kubeconf = (string) $region->kubeconfig;
            $namespace = (string) config('ai.gpu.namespace', 'runr-gpu');

            $inUse = array_filter(
                (array) ($region->gpu_allocated['workloads'] ?? []),
                fn ($w) => in_array($w['owner'] ?? null, ['project', 'model'], true),
            );
            if ($inUse !== []) {
                $this->log($region, 'Running GPU workloads keep their GPUs until they stop: '.implode(', ', array_slice(array_column($inUse, 'pod'), 0, 10)));
            }

            $release = $region->gpu_mode === 'operator' ? GpuSupportManifests::OPERATOR_RELEASE : GpuSupportManifests::DEVICE_PLUGIN_RELEASE;
            $this->log($region, "Removing Helm release {$release}...");
            $result = $this->helm->uninstall($release, $namespace, $kubeconf);
            if (! $result->success && ! str_contains(strtolower($result->error.$result->output), 'not found')) {
                throw new \RuntimeException('helm uninstall failed: '.trim($result->error ?: $result->output));
            }

            if (($region->gpu_settings['runtime_class'] ?? null) && $region->gpu_mode === 'device-plugin') {
                try {
                    $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', GpuSupportManifests::RUNTIME_CLASS);
                } catch (\Throwable) {
                }
            }
            try {
                $k8s->deleteResource('v1', 'ConfigMap', GpuSupportManifests::TIME_SLICING_CONFIG, $namespace);
            } catch (\Throwable) {
            }

            // Node labels RunR set for the plugin / operands (labels of the cluster admin stay)
            $nodes = $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
            foreach (GpuSupportManifests::nodeLabelCleanup($nodes) as $node => $patch) {
                $k8s->strategicMergePatchCluster('v1', 'Node', $node, $patch);
            }

            $settings = self::settings($region);
            $settings['runtime_class'] = null;

            $region->update([
                'gpu_mode' => 'none',
                'gpu_status' => 'not_installed',
                'gpu_status_message' => null,
                'gpu_settings' => $settings,
            ]);
            $this->inventory->refresh($region->fresh(), $k8s);
            $this->broadcast($region, 'gpu.uninstalled');
        } catch (\Throwable $e) {
            $this->log($region, 'ERROR: '.$e->getMessage(), ['gpu_status' => 'error']);
            throw $e;
        }
    }

    /**
     * Put nvidia.com/gpu.present=true on the GPU nodes (device plugin) or opt the other nodes out
     * of the operands (operator, chosen nodes), and take RunR's labels off nodes that are no GPU
     * nodes any more. Returns the GPU node names (null = not restricted).
     *
     * @param  list<array>|null  $nodes  v1 Node items (listed when null)
     * @return list<string>|null
     */
    public function applyNodeLabels(KnativeRegion $region, KubernetesClient $k8s, string $mode, array $settings, ?array $nodes = null, bool $log = true): ?array
    {
        $nodes ??= $k8s->listClusterResources('v1', 'Node')['items'] ?? [];
        $targets = GpuSupportManifests::gpuNodeNames($nodes, $settings);

        foreach (GpuSupportManifests::nodeLabelPatches($nodes, $mode, $settings) as $node => $patch) {
            $k8s->strategicMergePatchCluster('v1', 'Node', $node, $patch);
        }

        if ($log) {
            if ($targets === null) {
                $this->log($region, 'Device plugin on every node NVIDIA hardware is reported for (GPU nodes only: off).');
            } elseif ($targets === []) {
                $this->log($region, 'No node shows an NVIDIA GPU at this moment: RunR labels nodes '.GpuSupportManifests::GPU_NODE_LABEL.'=true as soon as Node Feature Discovery reports their NVIDIA device; choose the GPU nodes in the GPU settings when every node reports one (e.g. Kind).');
            } else {
                $this->log($region, 'GPU nodes: '.implode(', ', $targets).($mode === 'operator' && ! empty($settings['gpu_nodes'])
                    ? ' (operands on these nodes only)'
                    : ' (labelled '.GpuSupportManifests::GPU_NODE_LABEL.'=true; the device plugin runs only there)'));
            }
        }

        return $targets;
    }

    /**
     * gpu:refresh: GPU nodes that appeared since the install (NFD found their NVIDIA device)
     * get the label — automatic mode of a restricted device plugin only.
     */
    public function syncNodeLabels(KnativeRegion $region, ?KubernetesClient $k8s = null): void
    {
        $settings = self::settings($region);
        if (($region->gpu_mode ?? 'none') !== 'device-plugin' || ! $settings['restrict_to_gpu_nodes'] || ! $region->kubeconfig) {
            return;
        }

        try {
            $this->applyNodeLabels($region, $k8s ?? new KubernetesClient($region->kubeconfig), 'device-plugin', $settings, null, false);
        } catch (\Throwable $e) {
            Log::warning('GPU node labels could not be synced', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }

    /** How long a caller that needs the features waits for them to reach config-features. */
    public const FEATURES_EFFECTIVE_SECONDS = 120;

    /**
     * Knative Serving pod spec features of GPU and model workloads (operator CR when present,
     * the config-features ConfigMap otherwise).
     *
     * $waitUntilEffective: return once Knative's config-features carries them. Through the operator
     * the change reaches that ConfigMap — and the validating webhook that reads it — seconds after
     * the CR patch; a Knative Service applied before is refused ("Persistent volume claim support
     * is disabled"). Where they are in force already nothing is waited for.
     */
    public function enableKnativeFeatures(KubernetesClient $k8s, KnativeRegion $region, bool $waitUntilEffective = false): void
    {
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
        $features = GpuSupportManifests::knativeFeatures();
        $configMap = $k8s->getResourceStatus('v1', 'ConfigMap', 'config-features', $servingNs);
        $inForce = self::featuresInForce($configMap, $features);

        $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs);
        if ($cr) {
            $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs, [
                'spec' => ['config' => ['features' => $features]],
            ]);
        } elseif ($configMap) {
            $k8s->strategicMergePatch('v1', 'ConfigMap', 'config-features', $servingNs, ['data' => $features]);
        } else {
            $k8s->applyResource([
                'apiVersion' => 'v1',
                'kind' => 'ConfigMap',
                'metadata' => ['name' => 'config-features', 'namespace' => $servingNs],
                'data' => $features,
            ], $servingNs);
        }

        if (! $waitUntilEffective || $inForce) {
            return;
        }

        for ($polls = intdiv(self::FEATURES_EFFECTIVE_SECONDS, 2); $polls > 0; $polls--) {
            if (self::featuresInForce($k8s->getResourceStatus('v1', 'ConfigMap', 'config-features', $servingNs), $features)) {
                // the webhook's ConfigMap watch follows within moments
                Sleep::for(3)->seconds();

                return;
            }
            Sleep::for(2)->seconds();
        }

        Log::warning('Knative pod spec features not in config-features yet', ['region' => $region->name, 'waited_seconds' => self::FEATURES_EFFECTIVE_SECONDS]);
    }

    /** Every feature of $features set as given in a config-features ConfigMap. */
    public static function featuresInForce(?array $configMap, array $features): bool
    {
        $data = (array) ($configMap['data'] ?? []);
        foreach ($features as $key => $value) {
            if (($data[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function installDevicePlugin(KnativeRegion $region, string $kubeconfig, string $namespace, array $settings): void
    {
        $repo = GpuSupportManifests::DEVICE_PLUGIN_REPO;
        $this->helm->repoAdd($repo, (string) config('ai.gpu.device_plugin_repo'));
        $this->helm->repoUpdate();

        $replicas = $settings['time_slicing_replicas'];
        $this->log($region, 'Installing the NVIDIA device plugin '.config('ai.gpu.device_plugin_chart')
            .' with GPU Feature Discovery'.($replicas ? " (time-slicing: {$replicas} pods per GPU)" : '').'...');

        $result = $this->helm->install(
            GpuSupportManifests::DEVICE_PLUGIN_RELEASE,
            "{$repo}/nvidia-device-plugin",
            $namespace,
            [],
            $kubeconfig,
            (string) config('ai.gpu.device_plugin_chart'),
            GpuSupportManifests::devicePluginValues($settings),
            [],
            false,
        );

        if (! $result->success) {
            throw new \RuntimeException('helm install nvidia-device-plugin failed: '.trim($result->error ?: $result->output));
        }
    }

    private function installOperator(KnativeRegion $region, KubernetesClient $k8s, string $kubeconfig, string $namespace, array $settings): void
    {
        $repo = GpuSupportManifests::OPERATOR_REPO;
        $this->helm->repoAdd($repo, (string) config('ai.gpu.operator_repo'));
        $this->helm->repoUpdate();

        $replicas = $settings['time_slicing_replicas'];
        if ($replicas) {
            $this->ensureNamespace($k8s, $namespace);
            $k8s->applyResource(GpuSupportManifests::operatorTimeSlicingConfigMap($replicas, $namespace), $namespace);
        }

        $this->log($region, 'Installing the NVIDIA GPU Operator '.config('ai.gpu.operator_chart')
            .' (driver: '.($settings['operator_driver'] ? 'yes' : 'no').', container toolkit: '.($settings['operator_toolkit'] ? 'yes' : 'no')
            .($replicas ? ", time-slicing: {$replicas} pods per GPU" : '').'). The driver build can take several minutes per node.');

        $result = $this->helm->install(
            GpuSupportManifests::OPERATOR_RELEASE,
            "{$repo}/gpu-operator",
            $namespace,
            [],
            $kubeconfig,
            (string) config('ai.gpu.operator_chart'),
            GpuSupportManifests::operatorValues($settings, $namespace),
            [],
            false,
        );

        if (! $result->success) {
            throw new \RuntimeException('helm install gpu-operator failed: '.trim($result->error ?: $result->output));
        }
    }

    private function ensureNamespace(KubernetesClient $k8s, string $namespace): void
    {
        if (! $k8s->getClusterResource('v1', 'Namespace', $namespace)) {
            $k8s->applyClusterResource([
                'apiVersion' => 'v1',
                'kind' => 'Namespace',
                'metadata' => ['name' => $namespace, 'labels' => ['app.kubernetes.io/managed-by' => 'runr']],
            ]);
        }
    }

    /** Append a timestamped line to the GPU install log ($attributes saved with it). */
    private function log(KnativeRegion $region, string $message, array $attributes = []): void
    {
        $region->refresh();
        $log = (string) ($region->gpu_status_message ?? '');
        $log .= '['.now()->format('H:i:s')."] {$message}\n";
        $region->update(['gpu_status_message' => $log] + $attributes);

        try {
            event(new PlatformEvent('install.progress', [
                'region_id' => $region->id,
                'component' => 'gpu',
                'message' => $message,
            ]));
        } catch (\Throwable) {
            // live progress only
        }
    }

    private function broadcast(KnativeRegion $region, string $topic, array $extra = []): void
    {
        try {
            event(new PlatformEvent($topic, ['region_id' => $region->id, 'region_name' => $region->name] + $extra));
        } catch (\Throwable) {
            // best effort
        }
    }
}
