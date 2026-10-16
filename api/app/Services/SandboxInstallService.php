<?php

namespace App\Services;

use App\Events\PlatformEvent;
use App\Exceptions\SandboxAddonFailedException;
use App\Exceptions\SandboxInUseException;
use App\Models\KnativeRegion;
use App\Services\Concerns\CiliumSandboxCompatTrait;
use App\Services\Concerns\KataDevmapperTrait;
use App\Services\Concerns\KataKvmProbeTrait;
use App\Services\Concerns\KataManifestTrait;
use App\Services\Concerns\KataShimDetectionTrait;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Concerns\SandboxManifestTrait;
use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Helpers\KataRuntime;
use App\Services\RegionReconcile\Steps\NamespaceQuotaStep;
use App\Support\Images\RegistryMove;
use Illuminate\Support\Facades\Log;

class SandboxInstallService
{
    use CiliumSandboxCompatTrait, KataDevmapperTrait, KataKvmProbeTrait, KataManifestTrait, KataShimDetectionTrait, KnativeClusterTrait, SandboxManifestTrait;

    /** Namespace of the sandbox runtimes' node installers (Kata, gVisor; WebAssembly: WasmManifests::NAMESPACE). */
    public const SANDBOX_NAMESPACE = 'runr-sandbox';

    private const GVISOR_CLEANUP_DS = 'gvisor-cleanup';

    /** Install / node cleanup wait (download + unpack; k3s restarts staggered up to 1 min per node) */
    private const GVISOR_WAIT_SECONDS = 600;

    /**
     * kata-deploy on every node: image pull, artifacts copy (~5 GB) and the runtime restart —
     * about 4 min per node, nodes in parallel on a first install, one after the other on an
     * update. Stays below InstallSandboxJob::$timeout.
     */
    private const KATA_WAIT_SECONDS = 1200;

    /** kata-deploy's SIGTERM cleanup on every node (config, /opt/kata, runtime restart). */
    private const KATA_CLEANUP_WAIT_SECONDS = 600;

    /** kata-deploy's log line once the runtime restart is done and the node is labelled. */
    public const KATA_DONE_LOG = 'Kata Containers installation completed successfully';

    /** Polling of the Kata waits (tests shorten them). */
    public int $kataWaitSeconds = self::KATA_WAIT_SECONDS;

    public int $kataCleanupSeconds = self::KATA_CLEANUP_WAIT_SECONDS;

    public int $kataDevmapperSeconds = self::KATA_DEVMAPPER_WAIT_SECONDS;

    public int $kataPollSeconds = 5;

    /** What installing Kata does to the nodes (install API response, console warning). */
    public const KATA_INSTALL_WARNING = 'Installing Kata Containers restarts the container runtime on every target node '
        .'(containerd, or the k3s / RKE2 service that runs it). Running pods keep running; on a single-node '
        .'k3s or RKE2 cluster the Kubernetes API is unavailable for about 10–20 seconds.';

    /** Firecracker's devmapper setup on top (thin-pool, containerd config; its restart is kata-deploy's). */
    public const KATA_FC_WARNING = 'Firecracker (fc) also sets up containerd\'s devmapper snapshotter on those nodes: a thin-pool '
        .'on sparse files under '.KataRuntime::DEVMAPPER_DIR.' (disk is used only as images and containers fill it) and a '
        .'systemd unit that activates it at boot. It is loaded by the same container runtime restart.';

    /** Devmapper setup / node cleanup wait per phase. */
    private const KATA_DEVMAPPER_WAIT_SECONDS = 300;

    /**
     * A failure before kata-deploy or the nodes' Kata configuration change (e.g. Firecracker's
     * devmapper setup) on a region whose Kata was ready keeps it ready with its previous
     * hypervisors and throws SandboxAddonFailedException; any later failure sets the status to error.
     *
     * @param  array{devmapper_pool_gb?: int, previous_status?: ?string}  $options  previous_status: the
     *                                                                              Kata status before the caller marked the region "installing"
     */
    public function installKata(KnativeRegion $region, array $hypervisors = ['clh'], array $options = []): void
    {
        $hypervisors = array_values(array_unique($hypervisors)) ?: ['clh'];
        $previous = array_values((array) ($region->sandbox_kata_hypervisor ?? []));
        $wasReady = ($options['previous_status'] ?? $region->sandbox_kata_status) === 'ready' && $previous !== [];
        $kataChanged = false;

        $region->update([
            'sandbox_kata_status' => 'installing',
            'sandbox_kata_status_message' => '',
            'sandbox_kata_hypervisor' => $hypervisors,
        ]);

        $shimNames = implode(', ', $hypervisors);
        $log = fn (string $message) => $this->appendLog($region, 'sandbox_kata_status_message', $message);

        try {
            $unsupported = array_diff($hypervisors, KataRuntime::HYPERVISORS);
            if ($unsupported) {
                throw new \RuntimeException('Unsupported Kata hypervisor(s): '.implode(', ', $unsupported)
                    .' (RunR installs '.implode(', ', KataRuntime::HYPERVISORS).')');
            }

            $k8s = new KubernetesClient($region->kubeconfig);

            $log("Creating Kata installer ({$shimNames})...");
            $this->ensureNamespace($k8s, self::SANDBOX_NAMESPACE);
            $this->removeLegacyKataHelmRelease($k8s, $region);

            // RBAC for kata-deploy binary (node labeling, RuntimeClasses, its SIGTERM cleanup)
            $this->applyKataRbac($k8s);

            // Install on the dedicated worker nodes only if there are any, else on all workers
            $hasDedicated = $this->hasDedicatedWorkerNodes($k8s);

            // kata-deploy supports containerd (plain / k3s / rke2) and CRI-O
            $runtime = $this->detectSandboxRuntime($k8s, $hasDedicated);
            if (! ContainerRuntimeHelper::isContainerd($runtime['flavor']) && $runtime['flavor'] !== ContainerRuntimeHelper::FLAVOR_CRIO) {
                throw new \RuntimeException("Kata Containers needs containerd (plain, k3s, rke2) or CRI-O nodes; node {$runtime['node']} runs '{$runtime['runtime_version']}'");
            }
            $log("Node runtime: {$runtime['flavor']} ({$runtime['runtime_version']})");

            // Kata starts VMs: only nodes with hardware virtualization can run its pods
            $kvm = $this->ensureKvmProbed($k8s);
            if ($kvm['state'] === 'no') {
                throw new \RuntimeException('Kata Containers needs hardware virtualization (/dev/kvm), which none of the nodes has ('
                    .self::describeKvmNodes($kvm).') — use gVisor or runc on this region');
            }
            if ($kvm['kvm_nodes'] === []) {
                throw new \RuntimeException('/dev/kvm could not be checked on node(s) '.implode(', ', $kvm['unprobed'] ?: array_keys($kvm['nodes']))
                    .' (KVM check DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KVM_PROBE_DS.') — install again');
            }
            $log('Hardware virtualization (/dev/kvm): '.self::describeKvmNodes($kvm)
                .($kvm['state'] === 'mixed' ? ' — Kata pods run only on the nodes with it ('.KataRuntime::KVM_LABEL.'=true)' : ''));

            $configMap = $this->buildKataConfigMap(self::SANDBOX_NAMESPACE, $hypervisors, $runtime['flavor']);
            $ds = $this->buildKataInstallerDaemonSet(self::SANDBOX_NAMESPACE, $hypervisors, $hasDedicated, $runtime['flavor']);
            $live = $k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DS_NAME, self::SANDBOX_NAMESPACE);
            $restart = self::runtimeRestartText($runtime['flavor']);
            // kata-deploy runs again only when what it installs changed — a drop-in-only change is
            // written to the nodes in place afterwards (syncKataDropIns(), no restart)
            $rerun = ! $live || $this->kataLiveState($k8s, $live, $ds, $configMap['data'])['installer'] !== self::kataInstallerHashOf($ds);

            // Firecracker: devmapper snapshotter on the nodes before kata-deploy restarts the runtime,
            // so that one restart loads both
            if (array_filter($hypervisors, [KataRuntime::class, 'needsDevmapper'])) {
                $this->prepareKataDevmapper($k8s, $region, $runtime, $hasDedicated, (int) ($options['devmapper_pool_gb'] ?? KataRuntime::DEVMAPPER_POOL_DEFAULT_GB), $rerun);
            } elseif ($k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DEVMAPPER_DS, self::SANDBOX_NAMESPACE)
                && $this->devmapperLabelledNodes($k8s) === []) {
                // An unfinished Firecracker setup (a devmapper snapshotter that never ran on any
                // node): take it back; a working one stays until Kata is uninstalled
                $log('Removing an unfinished Firecracker devmapper setup (kata-devmapper DaemonSet, thin-pool files, runr-devmapper.service)...');
                $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_DS);
                $this->runDevmapperCleanup($k8s, $region, $runtime, $hasDedicated);
            }

            // From here on kata-deploy, the nodes' Kata configuration and the RuntimeClasses change
            $kataChanged = true;

            if (! $live) {
                // A new DaemonSet starts on every node at once (maxUnavailable only paces updates)
                $log("Deploying the Kata installer: kata-deploy restarts {$restart} on every target node at about the same time "
                    .'(running pods keep running; on a single-node cluster the Kubernetes API is unavailable for ~10–20 s)...');
            } elseif ($rerun) {
                $log("Updating the Kata installer: rolling, one node at a time — on each node kata-deploy's cleanup and reinstall restart "
                    ."{$restart} (twice, back to back; running pods keep running)...");
            } else {
                $log('Kata installer unchanged — nothing is restarted');
            }

            if ($rerun) {
                if (! $k8s->applyResource($configMap, self::SANDBOX_NAMESPACE)) {
                    throw new \RuntimeException('cannot apply ConfigMap '.self::SANDBOX_NAMESPACE.'/'.self::KATA_CONFIG_MAP);
                }
                if (! $k8s->applyResource($ds, self::SANDBOX_NAMESPACE)) {
                    throw new \RuntimeException('cannot apply DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KATA_DS_NAME);
                }
            }

            // Ready only once every node's container runtime knows the Kata handlers: kata-deploy
            // labels the node after its runtime restart (the pod itself is Running long before)
            $log('Waiting for kata-deploy on every node (node label '.KataRuntime::NODE_LABEL.'=true)...');
            $status = ['done' => false, 'failure' => null, 'pending' => ['no status yet'], 'desired' => 0];
            $done = $this->waitFor(function () use ($k8s, &$status) {
                $status = $this->kataRolloutStatus($k8s);

                return $status['failure'] !== null || $status['done'];
            }, $this->kataWaitSeconds, $this->kataPollSeconds);

            if ($status['failure'] !== null) {
                throw new \RuntimeException("kata-deploy failed: {$status['failure']}");
            }
            if (! $done) {
                throw new \RuntimeException(sprintf(
                    'Kata was not ready within %d minutes — waiting for: %s. No RuntimeClass was changed; kata-deploy keeps running '
                    .'(kubectl -n %s logs ds/%s). Install Kata again once it has finished — nothing is restarted then.',
                    max(1, intdiv($this->kataWaitSeconds, 60)),
                    implode('; ', $status['pending']),
                    self::SANDBOX_NAMESPACE,
                    self::KATA_DS_NAME,
                ));
            }
            $log("kata-deploy finished on {$status['desired']} node(s)");

            if (! $rerun) {
                foreach ($this->syncKataDropIns($k8s, $hypervisors) as $line) {
                    $log($line);
                }
            }

            if (array_filter($hypervisors, [KataRuntime::class, 'needsDevmapper'])) {
                $this->awaitKataDevmapperLoaded($k8s, $region);
            }

            $log('Creating RuntimeClasses (pod overhead '.self::describeOverheads($hypervisors).')...');
            foreach ($this->syncKataRuntimeClasses($k8s, $hypervisors) as $line) {
                $log($line);
            }

            $this->enableRuntimeClassFeature($k8s, $region);

            $log('Configuring Cilium for sandbox compatibility...');
            $this->ensureCiliumSandboxCompat($k8s, $region);

            // The step log (devmapper, kata-deploy, RuntimeClasses) stays: the result is its last line
            $this->appendLog($region, 'sandbox_kata_status_message', 'Kata Containers installed successfully.', ['sandbox_kata_status' => 'ready']);

            Log::info('Kata Containers installed', ['region' => $region->name]);
        } catch (\Throwable $e) {
            if ($wasReady && ! $kataChanged) {
                // Nothing of the working install changed (kata-deploy, drop-ins, RuntimeClasses):
                // it stays ready with its hypervisors — only the additions failed
                $notAdded = array_values(array_diff($hypervisors, $previous));
                $this->appendLog($region, 'sandbox_kata_status_message', 'ERROR: '.$e->getMessage());
                $this->appendLog($region, 'sandbox_kata_status_message', sprintf(
                    'Kata stays installed with %s; %s not added — the running Kata install was not changed.',
                    implode(', ', $previous),
                    $notAdded ? implode(', ', $notAdded) : 'the requested change was',
                ), ['sandbox_kata_status' => 'ready', 'sandbox_kata_hypervisor' => $previous]);
                Log::warning('Kata install change failed, previous install kept', ['region' => $region->name, 'error' => $e->getMessage()]);

                throw new SandboxAddonFailedException(
                    ($notAdded ? implode(', ', $notAdded).' not added: ' : '').$e->getMessage(),
                    $notAdded,
                    $previous,
                    $e,
                );
            }

            $this->appendLog($region, 'sandbox_kata_status_message', 'ERROR: '.$e->getMessage(), ['sandbox_kata_status' => 'error']);
            Log::error('Kata install failed', ['region' => $region->name, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function uninstallKata(KnativeRegion $region): void
    {
        $region->update([
            'sandbox_kata_status' => 'uninstalling',
            'sandbox_kata_status_message' => '',
        ]);

        $log = fn (string $message) => $this->appendLog($region, 'sandbox_kata_status_message', $message);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            // Taking the runtime away from running VMs would break them: first they move to runc
            $inUse = $this->podsUsingRuntimeClass($k8s, fn (string $rc) => str_starts_with($rc, 'kata'));
            if ($inUse) {
                $shown = implode(', ', array_slice($inUse, 0, 5)).(count($inUse) > 5 ? ', …' : '');
                throw new SandboxInUseException(count($inUse)." running pod(s) still use Kata Containers ({$shown}). "
                    .'Set the sandbox mappings to runc and redeploy those projects (or delete them), then uninstall again.');
            }

            // No new Kata pods from here on
            $this->resetMappings($region, 'kata');
            foreach ($this->kataRuntimeClassNames() as $name) {
                try {
                    $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', $name);
                } catch (\Throwable) {
                }
            }
            $log('Kata RuntimeClasses removed; sandbox mappings set to runc');

            $hasDedicated = $this->hasDedicatedWorkerNodes($k8s);
            $runtime = $this->detectSandboxRuntime($k8s, $hasDedicated);

            // a drop-in update interrupted before it removed its DaemonSet
            if ($k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DROPINS_DS, self::SANDBOX_NAMESPACE)) {
                $this->deleteNodeDaemonSet($k8s, self::KATA_DROPINS_DS);
            }

            if ($k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DS_NAME, self::SANDBOX_NAMESPACE)) {
                // kata-deploy's SIGTERM cleanup needs its RBAC (RunR ≤ 0.4.0 lacked the DaemonSet
                // list it starts with — the cleanup then stopped before touching the node)
                $this->applyKataRbac($k8s);

                $log('Removing Kata from the nodes: kata-deploy removes its runtime handlers and /opt/kata and restarts '
                    .self::runtimeRestartText($runtime['flavor']).' on each node');
                $k8s->deleteResource('apps/v1', 'DaemonSet', self::KATA_DS_NAME, self::SANDBOX_NAMESPACE);

                $left = null;
                $gone = $this->waitFor(function () use ($k8s, &$left) {
                    $left = $k8s->listResourcesOrFail('v1', 'Pod', self::SANDBOX_NAMESPACE, ['runr.dev/component' => 'kata-installer']);

                    return $left === [];
                }, $this->kataCleanupSeconds, $this->kataPollSeconds);

                if (! $gone) {
                    $nodes = array_filter(array_map(fn ($p) => $p['spec']['nodeName'] ?? null, $left ?? []));
                    throw new \RuntimeException(sprintf(
                        'kata-deploy cleanup did not finish within %d minutes%s',
                        max(1, intdiv($this->kataCleanupSeconds, 60)),
                        $nodes ? ' on: '.implode(', ', $nodes) : '',
                    ));
                }
                $log('Nodes clean: kata-deploy removed Kata');
            }

            // Firecracker's devmapper snapshotter: only after kata-deploy took the kata-fc handler
            // (and its devmapper snapshotter reference) off the nodes
            $this->cleanupKataDevmapper($k8s, $region, $runtime, $hasDedicated);

            // Only now: the terminating installer pods needed their ConfigMap, ServiceAccount and RBAC
            try {
                $k8s->deleteResource('v1', 'ConfigMap', self::KATA_CONFIG_MAP, self::SANDBOX_NAMESPACE);
            } catch (\Throwable) {
            }
            try {
                $k8s->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRoleBinding', 'kata-installer-binding');
            } catch (\Throwable) {
            }
            try {
                $k8s->deleteClusterResource('rbac.authorization.k8s.io/v1', 'ClusterRole', 'kata-installer-role');
            } catch (\Throwable) {
            }
            try {
                $k8s->deleteResource('v1', 'ServiceAccount', self::KATA_SA_NAME, self::SANDBOX_NAMESPACE);
            } catch (\Throwable) {
            }

            $this->removeLegacyKataHelmRelease($k8s, $region);

            $region->update([
                'sandbox_kata_status' => 'not_installed',
                'sandbox_kata_status_message' => null,
            ]);

            Log::info('Kata Containers uninstalled', ['region' => $region->name]);
        } catch (SandboxInUseException $e) {
            // Nothing was changed: Kata stays installed and working
            $region->update([
                'sandbox_kata_status' => 'ready',
                'sandbox_kata_status_message' => 'Uninstall refused: '.$e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $region->update([
                'sandbox_kata_status' => 'error',
                'sandbox_kata_status_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * How the cluster's Kata install differs from what this release installs for $hypervisors
     * (region:reconcile, KataSandboxStep). Read-only.
     *
     * installer: missing | outdated (what kata-deploy installs changed — pod spec or runtime
     * handlers: applying it re-runs kata-deploy on every node) | current. rollout:
     * kataRolloutStatus() when current. dropins: current | outdated (RunR's config.d drop-ins on
     * the nodes differ — written in place by syncKataDropIns(), no restart) when the installer is
     * current, else null. runtime_classes: kataRuntimeClassPlan().
     *
     * @return array{installer: string, restart: string, nodes: int, rollout: ?array, dropins: ?string, runtime_classes: array}
     */
    public function kataDrift(KubernetesClient $k8s, array $hypervisors): array
    {
        $hypervisors = array_values(array_intersect($hypervisors, KataRuntime::HYPERVISORS));
        $live = $k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DS_NAME, self::SANDBOX_NAMESPACE);

        if (! $live) {
            return ['installer' => 'missing', 'restart' => '', 'nodes' => 0, 'rollout' => null, 'dropins' => null, 'runtime_classes' => []];
        }

        $hasDedicated = $this->hasDedicatedWorkerNodes($k8s);
        $runtime = $this->detectSandboxRuntime($k8s, $hasDedicated);
        $desired = $this->buildKataInstallerDaemonSet(self::SANDBOX_NAMESPACE, $hypervisors, $hasDedicated, $runtime['flavor']);
        $configMap = $this->buildKataConfigMap(self::SANDBOX_NAMESPACE, $hypervisors, $runtime['flavor']);
        $state = $this->kataLiveState($k8s, $live, $desired, $configMap['data']);
        $current = $state['installer'] === self::kataInstallerHashOf($desired);

        return [
            'installer' => $current ? 'current' : 'outdated',
            'restart' => self::runtimeRestartText($runtime['flavor']),
            'nodes' => (int) ($live['status']['desiredNumberScheduled'] ?? 0),
            'rollout' => $current ? $this->kataRolloutStatus($k8s) : null,
            'dropins' => $current ? ($state['dropins'] === self::kataDropInsHashOf($desired) ? 'current' : 'outdated') : null,
            'runtime_classes' => $this->kataRuntimeClassPlan($k8s, $hypervisors),
        ];
    }

    /**
     * What the live kata-installer stands for: the installer hash kata-deploy ran with and the
     * hash of the drop-ins on its nodes (null: unknown — treated as outdated).
     *
     * RunR ≥ 0.4.2 keeps both as DaemonSet annotations. A RunR 0.4.1 installer has one hash in its
     * pod template over the pod spec and the whole ConfigMap (kataConfigHash()); it is recognised
     * when that hash matches $desired's pod spec with the ConfigMap on the cluster — then kata-deploy
     * ran with exactly that ConfigMap and its drop-ins are the ones on the nodes.
     *
     * legacy: the installer is $desired's with RunR's images under one of RunR's earlier image
     * namespaces (RegistryMove::FROM up to 0.5.0, RegistryMove::FLAT in 0.5.1) — the same Kata
     * install, reported with $desired's installer hash; legacy names that namespace. It keeps its
     * pod template: rolling kata-deploy for the image namespace alone would restart the container
     * runtime on every node (syncKataDropIns() re-applies it as it is).
     *
     * @param  array<string, string>  $configMapData  buildKataConfigMap() data of $desired
     * @return array{installer: ?string, dropins: ?string, legacy: ?string}
     */
    private function kataLiveState(KubernetesClient $k8s, array $live, array $desired, array $configMapData): array
    {
        $podSpec = $desired['spec']['template']['spec'];
        $legacySpecs = [];
        foreach ([RegistryMove::FLAT, RegistryMove::FROM] as $namespace) {
            $legacySpec = RegistryMove::legacy($podSpec, $namespace);
            if ($legacySpec !== $podSpec) {
                $legacySpecs[$namespace] = $legacySpec;
            }
        }

        $annotations = $live['metadata']['annotations'] ?? [];
        if (isset($annotations[self::KATA_INSTALLER_HASH_ANNOTATION])) {
            $installer = $annotations[self::KATA_INSTALLER_HASH_ANNOTATION];
            $dropins = $annotations[self::KATA_DROPINS_HASH_ANNOTATION] ?? null;
            if ($installer !== self::kataInstallerHashOf($desired)) {
                foreach ($legacySpecs as $namespace => $legacySpec) {
                    if ($installer === self::kataInstallerHash($legacySpec, $configMapData)) {
                        return ['installer' => self::kataInstallerHashOf($desired), 'dropins' => $dropins, 'legacy' => $namespace];
                    }
                }
            }

            return ['installer' => $installer, 'dropins' => $dropins, 'legacy' => null];
        }

        $template = self::kataTemplateHash($live);
        $data = $template ? ($k8s->getResourceStatus('v1', 'ConfigMap', self::KATA_CONFIG_MAP, self::SANDBOX_NAMESPACE)['data'] ?? null) : null;

        if (is_array($data) && $template === self::kataConfigHash($podSpec, $data)) {
            return ['installer' => self::kataInstallerHash($podSpec, $data), 'dropins' => self::kataDropInsHash($data), 'legacy' => null];
        }
        foreach (is_array($data) ? $legacySpecs : [] as $namespace => $legacySpec) {
            if ($template === self::kataConfigHash($legacySpec, $data)) {
                return ['installer' => self::kataInstallerHash($podSpec, $data), 'dropins' => self::kataDropInsHash($data), 'legacy' => $namespace];
            }
        }

        return ['installer' => null, 'dropins' => null, 'legacy' => null];
    }

    private static function kataInstallerHashOf(array $ds): ?string
    {
        return $ds['metadata']['annotations'][self::KATA_INSTALLER_HASH_ANNOTATION] ?? null;
    }

    private static function kataDropInsHashOf(array $ds): ?string
    {
        return $ds['metadata']['annotations'][self::KATA_DROPINS_HASH_ANNOTATION] ?? null;
    }

    /**
     * Bring RunR's config.d drop-ins on the nodes to this release's without re-running kata-deploy
     * (no container runtime restart): the Kata shim reads its configuration at every sandbox start,
     * so new Kata pods use the new drop-ins; running pods keep the VM they have.
     *
     *  1. the kata-installer DaemonSet records what its nodes run (a RunR 0.4.1 installer carried
     *     that only in its pod template hash) — its pod template is re-applied unchanged, nothing rolls;
     *  2. the ConfigMap gets the new drop-ins (what kata-deploy copies when it runs next: a new node,
     *     a restarted installer pod);
     *  3. only when the drop-ins differ: the kata-dropins DaemonSet writes them on every installer
     *     node (buildKataDropInsDaemonSet()) and is removed again; the installer then records them.
     *
     * Needs a current installer whose kata-deploy finished on every node (kataDrift()).
     *
     * @return list<string> what was done
     */
    public function syncKataDropIns(KubernetesClient $k8s, array $hypervisors): array
    {
        $hypervisors = array_values(array_intersect($hypervisors, KataRuntime::HYPERVISORS));
        $hasDedicated = $this->hasDedicatedWorkerNodes($k8s);
        $runtime = $this->detectSandboxRuntime($k8s, $hasDedicated);
        $configMap = $this->buildKataConfigMap(self::SANDBOX_NAMESPACE, $hypervisors, $runtime['flavor']);
        $desired = $this->buildKataInstallerDaemonSet(self::SANDBOX_NAMESPACE, $hypervisors, $hasDedicated, $runtime['flavor']);
        $live = $k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DS_NAME, self::SANDBOX_NAMESPACE);
        $state = $live ? $this->kataLiveState($k8s, $live, $desired, $configMap['data']) : ['installer' => null, 'dropins' => null, 'legacy' => null];

        if ($state['installer'] === null || $state['installer'] !== self::kataInstallerHashOf($desired)) {
            throw new \RuntimeException('the Kata installer is not current — install Kata again (kata-deploy re-runs)');
        }

        // 1. record what the nodes run; the pod template stays exactly as it is
        $recorded = $desired;
        if ($state['legacy'] !== null) {
            // RunR's images under the earlier namespace the installer runs them from
            $recorded['spec']['template']['spec'] = RegistryMove::legacy($recorded['spec']['template']['spec'], $state['legacy']);
            $recorded['metadata']['annotations'][self::KATA_INSTALLER_HASH_ANNOTATION] = self::kataInstallerHash($recorded['spec']['template']['spec'], $configMap['data']);
        }
        $recorded['spec']['template']['metadata']['annotations'][self::KATA_CONFIG_HASH_ANNOTATION] = self::kataTemplateHash($live);
        $recorded['metadata']['annotations'][self::KATA_DROPINS_HASH_ANNOTATION] = $state['dropins'];
        if (! $k8s->applyResource($recorded, self::SANDBOX_NAMESPACE)) {
            throw new \RuntimeException('cannot apply DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KATA_DS_NAME);
        }

        // 2. what kata-deploy installs from now on
        if (! $k8s->applyResource($configMap, self::SANDBOX_NAMESPACE)) {
            throw new \RuntimeException('cannot apply ConfigMap '.self::SANDBOX_NAMESPACE.'/'.self::KATA_CONFIG_MAP);
        }

        if ($state['dropins'] === self::kataDropInsHashOf($desired)) {
            return [];
        }

        // 3. the nodes
        $this->deleteNodeDaemonSet($k8s, self::KATA_DROPINS_DS);
        try {
            if (! $k8s->applyResource($this->buildKataDropInsDaemonSet(self::SANDBOX_NAMESPACE, $configMap['data'], $hasDedicated), self::SANDBOX_NAMESPACE)) {
                throw new \RuntimeException('cannot apply DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KATA_DROPINS_DS);
            }
            $nodes = $this->waitForNodeScriptPods($k8s, self::KATA_DROPINS_DS, [self::KATA_DROPINS_LOG], 'Kata drop-in update');
        } finally {
            $this->deleteNodeDaemonSet($k8s, self::KATA_DROPINS_DS, false);
        }

        $recorded['metadata']['annotations'][self::KATA_DROPINS_HASH_ANNOTATION] = self::kataDropInsHashOf($desired);
        if (! $k8s->applyResource($recorded, self::SANDBOX_NAMESPACE)) {
            throw new \RuntimeException('cannot apply DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KATA_DS_NAME);
        }

        return ['Kata configuration drop-ins ('.implode(', ', array_map([KataRuntime::class, 'handler'], $hypervisors)).') updated in place on '
            .count($nodes).' node(s) — nothing restarted: Kata pods started from now on use them, running ones keep theirs until they are replaced'];
    }

    /**
     * RuntimeClass changes needed for $hypervisors: name → action (create | recreate — the
     * handler differs and is immutable | update — overhead or scheduling differ | null) with the
     * desired manifest and the live handler.
     *
     * @return array<string, array{action: ?string, manifest: array, from_handler: ?string}>
     */
    public function kataRuntimeClassPlan(KubernetesClient $k8s, array $hypervisors): array
    {
        $plan = [];

        foreach ($this->buildKataRuntimeClasses($hypervisors) as $rc) {
            $name = $rc['metadata']['name'];
            $live = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', $name);

            $action = match (true) {
                $live === null => 'create',
                ($live['handler'] ?? null) !== $rc['handler'] => 'recreate',
                ! NamespaceQuotaStep::sameQuantities($live['overhead']['podFixed'] ?? [], $rc['overhead']['podFixed'])
                    || ($live['scheduling']['nodeSelector'] ?? []) != $rc['scheduling']['nodeSelector'] => 'update',
                default => null,
            };

            $plan[$name] = ['action' => $action, 'manifest' => $rc, 'from_handler' => $live['handler'] ?? null];
        }

        return $plan;
    }

    /**
     * Bring the Kata RuntimeClasses to kataRuntimeClassPlan(). No node is touched: the overhead
     * and nodeSelector apply to Kata pods created from now on.
     *
     * @return list<string> what changed
     */
    public function syncKataRuntimeClasses(KubernetesClient $k8s, array $hypervisors): array
    {
        $lines = [];

        foreach ($this->kataRuntimeClassPlan($k8s, $hypervisors) as $name => $item) {
            $rc = $item['manifest'];

            switch ($item['action']) {
                case 'create':
                    $k8s->applyClusterResource($rc);
                    $lines[] = "created RuntimeClass {$name} (".self::describeRuntimeClass($rc).')';
                    break;
                case 'recreate':
                    // handler is immutable: delete + create. A pod created in between is retried
                    // by its controller (Knative / ReplicaSet), running pods are not affected
                    if (! $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', $name)) {
                        throw new \RuntimeException("cannot delete RuntimeClass {$name} to change its handler");
                    }
                    $k8s->applyClusterResource($rc);
                    $lines[] = "recreated RuntimeClass {$name}: handler {$item['from_handler']} → ".self::describeRuntimeClass($rc);
                    break;
                case 'update':
                    $k8s->applyClusterResource($rc);
                    $lines[] = "updated RuntimeClass {$name} (".self::describeRuntimeClass($rc).')';
                    break;
            }
        }

        return $lines;
    }

    /**
     * kata-deploy's progress on the nodes of the kata-installer DaemonSet.
     *
     * done: the rollout is complete and on every node the current pod logged KATA_DONE_LOG and
     * the node carries KataRuntime::NODE_LABEL=true (set after the runtime restart).
     * failure: an installer container that keeps crashing (with its log tail), else null.
     *
     * @return array{done: bool, failure: ?string, desired: int, pending: list<string>}
     */
    public function kataRolloutStatus(KubernetesClient $k8s): array
    {
        $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DS_NAME, self::SANDBOX_NAMESPACE);
        if (! $ds) {
            return ['done' => false, 'failure' => null, 'desired' => 0, 'pending' => ['the kata-installer DaemonSet does not exist']];
        }

        $status = $ds['status'] ?? [];
        $desired = (int) ($status['desiredNumberScheduled'] ?? 0);
        $updated = (int) ($status['updatedNumberScheduled'] ?? 0);
        $pods = $k8s->listResourcesOrFail('v1', 'Pod', self::SANDBOX_NAMESPACE, ['runr.dev/component' => 'kata-installer']);

        $failure = self::kataInstallerFailure($pods);
        if ($failure !== null) {
            return ['done' => false, 'failure' => $failure, 'desired' => $desired, 'pending' => []];
        }

        if (($status['observedGeneration'] ?? 0) < ($ds['metadata']['generation'] ?? 1) || $desired < 1 || $updated < $desired) {
            return ['done' => false, 'failure' => null, 'desired' => $desired, 'pending' => ["installer rollout ({$updated}/{$desired} pods updated)"]];
        }

        $hash = self::kataTemplateHash($ds);
        // per node, without logging: the API may be down while kata-deploy restarts k3s / RKE2
        $labels = fn (string $node) => $k8s->getClusterResource('v1', 'Node', $node)['metadata']['labels'] ?? [];
        $pending = [];
        $current = 0;
        foreach ($pods as $pod) {
            // pods of the previous template are on their way out (cleanup)
            if (! empty($pod['metadata']['deletionTimestamp'])
                || ($pod['metadata']['annotations'][self::KATA_CONFIG_HASH_ANNOTATION] ?? null) !== $hash) {
                continue;
            }
            $current++;

            $node = $pod['spec']['nodeName'] ?? null;
            if (! $node) {
                $pending[] = ($pod['metadata']['name'] ?? '?').' (not scheduled)';
            } elseif (($labels($node)[KataRuntime::NODE_LABEL] ?? null) !== 'true') {
                $pending[] = "{$node} (kata-deploy installing, node not labelled yet)";
            } elseif (! str_contains((string) $k8s->getPodLogs($pod['metadata']['name'], self::SANDBOX_NAMESPACE, 'kata-deploy', 50), self::KATA_DONE_LOG)) {
                $pending[] = "{$node} (kata-deploy installing)";
            }
        }

        if ($current < $desired) {
            $pending[] = ($desired - $current).' installer pod(s) not started';
        }

        return ['done' => $pending === [], 'failure' => null, 'desired' => $desired, 'pending' => $pending];
    }

    /**
     * Firecracker: set up containerd's devmapper snapshotter on the kata-installer's nodes
     * (KataDevmapperTrait) and wait until it is in place. With $kataRestarts the runtime restart
     * that loads it is kata-deploy's (no extra restart); otherwise the step restarts it itself.
     */
    private function prepareKataDevmapper(KubernetesClient $k8s, KnativeRegion $region, array $runtime, bool $hasDedicated, int $poolGb, bool $kataRestarts): void
    {
        $log = fn (string $message) => $this->appendLog($region, 'sandbox_kata_status_message', $message);

        if (! ContainerRuntimeHelper::isContainerd($runtime['flavor'])) {
            throw new \RuntimeException("Firecracker needs containerd's devmapper snapshotter, which RunR sets up only on containerd nodes "
                ."(plain, k3s, rke2); node {$runtime['node']} runs '{$runtime['runtime_version']}'");
        }

        $mode = $kataRestarts ? 'kata-deploy' : 'self';
        $log(sprintf(
            "Firecracker: setting up containerd's devmapper snapshotter — thin-pool on sparse files under %s (%d GiB data + %d GiB metadata; "
            .'existing pool files are kept), systemd unit runr-devmapper.service, containerd config; %s...',
            KataRuntime::DEVMAPPER_DIR,
            $poolGb,
            KataRuntime::devmapperMetaGb($poolGb),
            $mode === 'self'
                ? 'kata-deploy does not restart the runtime this time, so '.self::runtimeRestartText($runtime['flavor']).' restarts once for it where it is not loaded yet'
                : "loaded by kata-deploy's runtime restart (no extra restart)",
        ));

        // A cleanup DaemonSet left by an interrupted uninstall would undo the setup; a kata-devmapper
        // DaemonSet of an earlier run (e.g. RunR 0.4.1's, crash-looping) would be read as this run's
        // result. Both go first, with their pods — the scripts are idempotent, nothing is undone.
        $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_CLEANUP_DS);
        $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_DS);

        // Set up earlier (and working) on some node: a failure now must not take that away
        $wasSetUp = $this->devmapperLabelledNodes($k8s) !== [];

        if (! $k8s->applyResource($this->buildKataDevmapperDaemonSet(self::SANDBOX_NAMESPACE, $runtime, $hasDedicated, $poolGb, $mode), self::SANDBOX_NAMESPACE)) {
            throw new \RuntimeException('cannot apply DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KATA_DEVMAPPER_DS);
        }

        try {
            $nodes = $this->waitForNodeScriptPods($k8s, self::KATA_DEVMAPPER_DS, [$mode === 'self' ? self::DEVMAPPER_READY_LOG : self::DEVMAPPER_CONFIGURED_LOG], 'devmapper setup');
        } catch (\RuntimeException $e) {
            throw new \RuntimeException($this->abandonKataDevmapper($k8s, $region, $runtime, $hasDedicated, ! $wasSetUp, $e->getMessage()), 0, $e);
        }
        $log('devmapper snapshotter set up on '.count($nodes).' node(s)');
    }

    /**
     * A failed devmapper setup: stop the kata-devmapper DaemonSet (its script stays up after an
     * error; left alone it would re-run on every pod restart) and, when this was the first setup,
     * take back what it created on the nodes (pool files, unit, config — no restart: the runtime
     * never loaded it).
     *
     * @return string the error for the region's Kata status
     */
    private function abandonKataDevmapper(KubernetesClient $k8s, KnativeRegion $region, array $runtime, bool $hasDedicated, bool $rollback, string $error): string
    {
        $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_DS);
        $error .= ' — kata-devmapper DaemonSet removed';

        if (! $rollback) {
            return $error;
        }

        // $error already reads "devmapper setup failed: …"
        $this->appendLog($region, 'sandbox_kata_status_message', "{$error}; removing what it created on the nodes...");
        try {
            $this->runDevmapperCleanup($k8s, $region, $runtime, $hasDedicated);

            return $error.'; the thin-pool files, runr-devmapper.service and config it had created were removed again';
        } catch (\Throwable $e) {
            return $error.'; removing its node changes failed too: '.$e->getMessage();
        }
    }

    /** Nodes where RunR's devmapper snapshotter runs (KataRuntime::DEVMAPPER_LABEL). */
    private function devmapperLabelledNodes(KubernetesClient $k8s): array
    {
        $nodes = $k8s->listClusterResources('v1', 'Node', [KataRuntime::DEVMAPPER_LABEL => 'true'])['items'] ?? [];

        return array_values(array_filter(array_map(fn ($n) => $n['metadata']['name'] ?? null, $nodes)));
    }

    /** After kata-deploy: the devmapper plugin is loaded on every node → node label for kata-fc scheduling. */
    private function awaitKataDevmapperLoaded(KubernetesClient $k8s, KnativeRegion $region): void
    {
        $this->appendLog($region, 'sandbox_kata_status_message', 'Waiting for containerd to load the devmapper snapshotter...');
        try {
            $nodes = $this->waitForNodeScriptPods($k8s, self::KATA_DEVMAPPER_DS, [self::DEVMAPPER_READY_LOG], 'devmapper snapshotter');
        } catch (\RuntimeException $e) {
            // The config is in containerd by now: removing it needs another runtime restart —
            // left to the Kata uninstall (or a new install); kata-fc gets no RuntimeClass
            $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_DS);
            throw new \RuntimeException($e->getMessage().' — kata-devmapper DaemonSet removed; no kata-fc RuntimeClass was created', 0, $e);
        }

        foreach ($nodes as $node) {
            if (! $k8s->strategicMergePatchCluster('v1', 'Node', $node, ['metadata' => ['labels' => [KataRuntime::DEVMAPPER_LABEL => 'true']]])) {
                throw new \RuntimeException("cannot label node {$node} with ".KataRuntime::DEVMAPPER_LABEL.'=true');
            }
        }
        $this->appendLog($region, 'sandbox_kata_status_message', 'devmapper snapshotter loaded on '.implode(', ', $nodes)
            .' (node label '.KataRuntime::DEVMAPPER_LABEL.'=true)');
    }

    /**
     * Kata uninstall: remove RunR's devmapper setup from the nodes where nothing uses it (the
     * cleanup script keeps it where Firecracker VMs or devmapper container snapshots remain).
     */
    private function cleanupKataDevmapper(KubernetesClient $k8s, KnativeRegion $region, array $runtime, bool $hasDedicated): void
    {
        if (! $k8s->getResourceStatus('apps/v1', 'DaemonSet', self::KATA_DEVMAPPER_DS, self::SANDBOX_NAMESPACE)) {
            return; // RunR never set it up here
        }

        $this->appendLog($region, 'sandbox_kata_status_message', 'Removing the Firecracker devmapper thin-pool where no container uses it: '
            .'containerd config first, then '.self::runtimeRestartText($runtime['flavor']).' restarts once more where it has it loaded, '
            .'then the pool, '.KataRuntime::DEVMAPPER_DIR.' and runr-devmapper.service');

        $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_DS);
        $this->runDevmapperCleanup($k8s, $region, $runtime, $hasDedicated);
    }

    /**
     * Run the devmapper cleanup DaemonSet on the nodes: removes RunR's devmapper setup where no
     * container uses it (keeps it, and its node label, elsewhere).
     */
    private function runDevmapperCleanup(KubernetesClient $k8s, KnativeRegion $region, array $runtime, bool $hasDedicated): void
    {
        $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_CLEANUP_DS);

        try {
            if (! $k8s->applyResource($this->buildKataDevmapperCleanupDaemonSet(self::SANDBOX_NAMESPACE, $runtime, $hasDedicated), self::SANDBOX_NAMESPACE)) {
                throw new \RuntimeException('cannot apply DaemonSet '.self::SANDBOX_NAMESPACE.'/'.self::KATA_DEVMAPPER_CLEANUP_DS);
            }

            $results = $this->waitForNodeScriptPods($k8s, self::KATA_DEVMAPPER_CLEANUP_DS, [self::DEVMAPPER_REMOVED_LOG, self::DEVMAPPER_KEPT_LOG], 'devmapper cleanup', true);
        } finally {
            $this->deleteNodeDaemonSet($k8s, self::KATA_DEVMAPPER_CLEANUP_DS, false);
        }

        foreach ($results as $node => $line) {
            if (str_contains($line, self::DEVMAPPER_KEPT_LOG)) {
                $this->appendLog($region, 'sandbox_kata_status_message', "devmapper thin-pool kept on {$node}: ".trim(substr($line, strlen(self::DEVMAPPER_KEPT_LOG) + 1)));

                continue;
            }
            if (! str_contains($line, 'nothing was set up')) {
                $k8s->strategicMergePatchCluster('v1', 'Node', $node, ['metadata' => ['labels' => [KataRuntime::DEVMAPPER_LABEL => null]]]);
            }
            $this->appendLog($region, 'sandbox_kata_status_message', "devmapper thin-pool removed from {$node}");
        }
    }

    /**
     * Wait until the current pod of a RunR node-script DaemonSet (devmapper setup / cleanup, Kata
     * drop-ins) logged one of $markers on every node.
     *
     * @return array<string, string>|list<string> node → matching log line ($withLines), else node names
     */
    private function waitForNodeScriptPods(KubernetesClient $k8s, string $name, array $markers, string $what, bool $withLines = false): array
    {
        $failure = null;
        $state = ['pending' => ['no status yet'], 'nodes' => []];

        $done = $this->waitFor(function () use ($k8s, $name, $markers, &$failure, &$state) {
            // Only pods of the DaemonSet as applied now count — a pod of an earlier one (an older
            // RunR's crash-looping script, 146 restarts on a test cluster) must not be read as this result
            $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', $name, self::SANDBOX_NAMESPACE);
            if (! $ds) {
                $state = ['pending' => ["{$name} DaemonSet not found"], 'nodes' => [], 'failure' => null];

                return false;
            }
            // a script that crashed (terminated with "runr: ERROR"), or one that logged its error
            // and stays up (the node scripts do not exit on failure — no restart loop)
            $failure = $this->installerFailure($k8s, $name, $ds);
            if ($failure !== null) {
                return true;
            }
            $state = $this->nodeScriptPodState($k8s, $name, $markers, $ds);
            $failure = $state['failure'];

            return $failure !== null || $state['pending'] === [];
        }, $this->kataDevmapperSeconds, $this->kataPollSeconds);

        if ($failure !== null) {
            throw new \RuntimeException("{$what} failed: {$failure}");
        }
        if (! $done) {
            throw new \RuntimeException(sprintf('%s not finished within %d minutes — waiting for: %s (kubectl -n %s logs ds/%s)',
                $what, max(1, intdiv($this->kataDevmapperSeconds, 60)), implode('; ', $state['pending']), self::SANDBOX_NAMESPACE, $name));
        }

        return $withLines ? $state['nodes'] : array_keys($state['nodes']);
    }

    /**
     * @return array{pending: list<string>, nodes: array<string, string>, failure: ?string}
     *                                                                                      nodes: node → first log line with a marker; failure: the "runr: ERROR" a pod logged
     */
    private function nodeScriptPodState(KubernetesClient $k8s, string $name, array $markers, array $ds): array
    {
        $status = $ds['status'] ?? [];
        $desired = (int) ($status['desiredNumberScheduled'] ?? 0);
        $updated = (int) ($status['updatedNumberScheduled'] ?? 0);

        if (($status['observedGeneration'] ?? 0) < ($ds['metadata']['generation'] ?? 1) || $desired < 1 || $updated < $desired) {
            return ['pending' => ["{$name} rollout ({$updated}/{$desired} pods updated)"], 'nodes' => [], 'failure' => null];
        }

        $pending = [];
        $nodes = [];
        $failure = null;
        foreach ($k8s->listResourcesOrFail('v1', 'Pod', self::SANDBOX_NAMESPACE, ['runr.dev/component' => $name]) as $pod) {
            if (! empty($pod['metadata']['deletionTimestamp']) || ! self::isPodOf($pod, $ds)) {
                continue;
            }
            $node = $pod['spec']['nodeName'] ?? ($pod['metadata']['name'] ?? '?');
            $line = null;
            foreach (explode("\n", (string) $k8s->getPodLogs($pod['metadata']['name'], self::SANDBOX_NAMESPACE, null, 200)) as $l) {
                if (($pos = strpos($l, 'runr: ERROR:')) !== false) {
                    $failure ??= trim(substr($l, $pos + strlen('runr: ERROR:')));
                    $pending[] = $node;

                    continue 2;
                }
                foreach ($markers as $marker) {
                    if (str_contains($l, $marker)) {
                        $line = trim($l);
                        break 2;
                    }
                }
            }
            if ($line === null) {
                $pending[] = $node;
            } else {
                $nodes[$node] = $line;
            }
        }

        if (count($nodes) + count($pending) < $desired) {
            $pending[] = ($desired - count($nodes) - count($pending))." {$name} pod(s) not started";
        }

        return ['pending' => $pending, 'nodes' => $nodes, 'failure' => $failure];
    }

    /** Delete a node DaemonSet of RunR and (by default) wait until its pods are gone. */
    private function deleteNodeDaemonSet(KubernetesClient $k8s, string $name, bool $wait = true): void
    {
        try {
            $k8s->deleteResource('apps/v1', 'DaemonSet', $name, self::SANDBOX_NAMESPACE);
        } catch (\Throwable) {
        }
        if ($wait) {
            $this->waitFor(fn () => $k8s->listResourcesOrFail('v1', 'Pod', self::SANDBOX_NAMESPACE, ['runr.dev/component' => $name]) === [], 90, max(1, min(2, $this->kataPollSeconds)));
        }
    }

    /** RuntimeClass names RunR may have created for Kata (current and earlier hypervisor lists). */
    private function kataRuntimeClassNames(): array
    {
        $shims = array_unique(array_merge(KataRuntime::HYPERVISORS, array_keys($this->shimDefinitions())));

        return array_map([KataRuntime::class, 'runtimeClass'], $shims);
    }

    private function applyKataRbac(KubernetesClient $k8s): void
    {
        [$sa, $role, $binding] = $this->buildKataRbac(self::SANDBOX_NAMESPACE);
        $k8s->applyResource($sa, self::SANDBOX_NAMESPACE);
        $k8s->applyClusterResource($role);
        $k8s->applyClusterResource($binding);
    }

    /**
     * Earlier RunR versions installed kata-deploy with Helm. Uninstall that release only when it
     * exists (Helm keeps it as sh.helm.release.v1.kata-deploy.* Secrets) — a blind `helm
     * uninstall` logged "Release not loaded" as an error on every install.
     */
    private function removeLegacyKataHelmRelease(KubernetesClient $k8s, KnativeRegion $region): void
    {
        $releases = $k8s->listResources('v1', 'Secret', self::SANDBOX_NAMESPACE, ['owner' => 'helm', 'name' => 'kata-deploy']);
        if (empty($releases['items'])) {
            return;
        }

        $this->appendLog($region, 'sandbox_kata_status_message', 'Removing the Helm release kata-deploy of an earlier RunR version...');
        (new HelmService)->uninstall('kata-deploy', self::SANDBOX_NAMESPACE, $region->kubeconfig);
    }

    /**
     * A kata-deploy container that keeps failing: "node: reason — last log lines".
     *
     * @param  list<array>  $pods
     */
    private static function kataInstallerFailure(array $pods): ?string
    {
        foreach ($pods as $pod) {
            foreach ($pod['status']['containerStatuses'] ?? [] as $cs) {
                $waiting = $cs['state']['waiting']['reason'] ?? null;
                if ($waiting !== 'CrashLoopBackOff' || (int) ($cs['restartCount'] ?? 0) < 3) {
                    continue;
                }

                $message = trim((string) ($cs['lastState']['terminated']['message'] ?? ''));
                $lines = array_slice(array_values(array_filter(array_map('trim', explode("\n", $message)))), -3);
                $node = $pod['spec']['nodeName'] ?? ($pod['metadata']['name'] ?? '?');

                return "node {$node}: the installer keeps crashing ({$cs['restartCount']} restarts)"
                    .($lines ? ' — '.mb_substr(implode(' | ', $lines), 0, 400) : '');
            }
        }

        return null;
    }

    /** Hash annotation of a kata-installer DaemonSet's pod template (null before RunR 0.4.1). */
    private static function kataTemplateHash(array $ds): ?string
    {
        return $ds['spec']['template']['metadata']['annotations'][self::KATA_CONFIG_HASH_ANNOTATION] ?? null;
    }

    /** "runr-test: yes" / "n1, n2: yes; n3: no; n4: not checked" */
    public static function describeKvmNodes(array $summary): string
    {
        $groups = [];
        foreach ($summary['nodes'] as $node => $kvm) {
            $groups[$kvm === null ? 'not checked' : ($kvm ? 'yes' : 'no')][] = $node;
        }

        return implode('; ', array_map(fn ($k, $nodes) => implode(', ', $nodes).": {$k}", array_keys($groups), $groups)) ?: 'no nodes';
    }

    /** What kata-deploy restarts on a node of this runtime flavor. */
    public static function runtimeRestartText(string $flavor): string
    {
        return match ($flavor) {
            ContainerRuntimeHelper::FLAVOR_K3S => 'the k3s service (containerd, kubelet and, on servers, the Kubernetes API)',
            ContainerRuntimeHelper::FLAVOR_RKE2 => 'the RKE2 service (containerd, kubelet and, on servers, the Kubernetes API)',
            ContainerRuntimeHelper::FLAVOR_CRIO => 'CRI-O',
            default => 'containerd',
        };
    }

    /** "kata-clh 250m / 256Mi, kata-qemu 250m / 320Mi" */
    public static function describeOverheads(array $hypervisors): string
    {
        return implode(', ', array_map(function (string $hv) {
            $q = KataRuntime::overheadQuantities($hv);

            return KataRuntime::runtimeClass($hv)." {$q['cpu']} / {$q['memory']}";
        }, array_values(array_filter($hypervisors, [KataRuntime::class, 'isSupported']))));
    }

    public static function describeRuntimeClass(array $rc): string
    {
        $o = $rc['overhead']['podFixed'];
        $selector = implode(',', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($rc['scheduling']['nodeSelector']), $rc['scheduling']['nodeSelector']));

        return "handler {$rc['handler']}, overhead {$o['cpu']} CPU / {$o['memory']}, nodeSelector {$selector}";
    }

    public function installGvisor(KnativeRegion $region): void
    {
        // A re-install of a working gVisor keeps its RuntimeClass when it fails (services use it)
        $wasReady = $region->sandbox_gvisor_status === 'ready';
        $k8s = null;
        $runtime = null;
        $applied = false;

        $region->update([
            'sandbox_gvisor_status' => 'installing',
            'sandbox_gvisor_status_message' => '',
        ]);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            // The installer only knows containerd's config (plain, k3s, rke2) — refuse others up front
            $runtime = $this->detectSandboxRuntime($k8s, false);
            if (! ContainerRuntimeHelper::isContainerd($runtime['flavor'])) {
                throw new \RuntimeException("gVisor needs containerd nodes (plain, k3s or rke2); node {$runtime['node']} runs '{$runtime['runtime_version']}'");
            }
            if ($runtime['mixed']) {
                throw new \RuntimeException('gVisor installer needs the same container runtime on all nodes; found: '
                    .collect($runtime['nodes'])->map(fn ($flavor, $node) => "{$node}={$flavor}")->implode(', '));
            }

            $this->ensureNamespace($k8s, self::SANDBOX_NAMESPACE);

            $this->appendLog($region, 'sandbox_gvisor_status_message', "Node runtime: {$runtime['flavor']} ({$runtime['runtime_version']})");
            if (ContainerRuntimeHelper::isK3sFamily($runtime['flavor'])) {
                $this->appendLog($region, 'sandbox_gvisor_status_message', "{$runtime['flavor']}: the installer restarts the {$runtime['flavor']} service once per node to load runsc (running pods keep running)");
            }

            // A cleanup DaemonSet left by an interrupted uninstall would undo this install
            try {
                $k8s->deleteResource('apps/v1', 'DaemonSet', self::GVISOR_CLEANUP_DS, self::SANDBOX_NAMESPACE);
            } catch (\Throwable) {
            }

            $this->appendLog($region, 'sandbox_gvisor_status_message', 'Deploying gVisor installer DaemonSet (gVisor '.self::GVISOR_RELEASE.')...');
            $applied = true;
            $k8s->applyResource($this->buildGvisorDaemonSet(self::SANDBOX_NAMESPACE, $runtime), self::SANDBOX_NAMESPACE);

            $this->appendLog($region, 'sandbox_gvisor_status_message', 'Waiting for gVisor DaemonSet to be ready...');

            $failure = null;
            $ready = $this->waitFor(function () use ($k8s, &$failure) {
                $failure = $this->installerFailure($k8s, 'gvisor-installer');

                return $failure !== null || $this->isDaemonSetReady($k8s, self::SANDBOX_NAMESPACE, 'gvisor-installer');
            }, self::GVISOR_WAIT_SECONDS);

            if ($failure !== null) {
                throw new \RuntimeException("gVisor installer failed: {$failure}");
            }
            if (! $ready) {
                throw new \RuntimeException('gVisor DaemonSet did not become ready within '.intdiv(self::GVISOR_WAIT_SECONDS, 60).' minutes');
            }

            // Only once runsc is installed and loaded on every node: pods asking for the
            // gvisor RuntimeClass before that would fail to start
            $this->appendLog($region, 'sandbox_gvisor_status_message', 'Creating gVisor RuntimeClass...');
            $k8s->applyClusterResource($this->buildGvisorRuntimeClass());

            $this->enableRuntimeClassFeature($k8s, $region);

            $this->appendLog($region, 'sandbox_gvisor_status_message', 'Configuring Cilium for sandbox compatibility...');
            $this->ensureCiliumSandboxCompat($k8s, $region);

            // The step log stays: the result is its last line
            $this->appendLog($region, 'sandbox_gvisor_status_message', 'gVisor installed successfully.', ['sandbox_gvisor_status' => 'ready']);

            Log::info('gVisor installed', ['region' => $region->name]);
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            // No half-installed gVisor: a RuntimeClass without a working runsc on the nodes
            // lets pods be scheduled that can never start (and is detected as "ready" later).
            // A failed first install also takes back what it changed on the nodes.
            if ($k8s && ! $wasReady) {
                try {
                    $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', 'gvisor');
                } catch (\Throwable) {
                }
            }
            if ($k8s && $applied) {
                // Stop the installer (a crash loop would re-apply its changes)
                try {
                    $k8s->deleteResource('apps/v1', 'DaemonSet', 'gvisor-installer', self::SANDBOX_NAMESPACE);
                } catch (\Throwable) {
                }
            }
            if ($k8s && $applied && ! $wasReady && $runtime) {
                $this->appendLog($region, 'sandbox_gvisor_status_message', "Install failed: {$message}");
                $this->appendLog($region, 'sandbox_gvisor_status_message', 'Rolling back the node changes (runsc config, binaries)...');
                $cleanup = $this->cleanupGvisorNodes($k8s, $runtime);
                $message .= $cleanup === null
                    ? ' — node changes rolled back'
                    : " — node rollback failed too: {$cleanup}";
            }

            $this->appendLog($region, 'sandbox_gvisor_status_message', "ERROR: {$message}", ['sandbox_gvisor_status' => 'error']);
            Log::error('gVisor install failed', ['region' => $region->name, 'error' => $message]);
            throw new \RuntimeException($message, 0, $e);
        }
    }

    public function uninstallGvisor(KnativeRegion $region): void
    {
        $region->update([
            'sandbox_gvisor_status' => 'uninstalling',
            'sandbox_gvisor_status_message' => '',
        ]);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            // Taking runsc away from running sandboxes would break them: first they move to runc
            $inUse = $this->podsUsingRuntimeClass($k8s, 'gvisor');
            if ($inUse) {
                $shown = implode(', ', array_slice($inUse, 0, 5)).(count($inUse) > 5 ? ', …' : '');
                throw new SandboxInUseException(count($inUse)." running pod(s) still use gVisor ({$shown}). "
                    .'Set the sandbox mapping to runc and redeploy those projects (or delete them), then uninstall again.');
            }

            $runtime = $this->detectSandboxRuntime($k8s, false);

            // No new gVisor pods from here on
            $this->resetMappings($region, 'gvisor');
            try {
                $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', 'gvisor');
            } catch (\Throwable) {
            }
            try {
                $k8s->deleteResource('apps/v1', 'DaemonSet', 'gvisor-installer', self::SANDBOX_NAMESPACE);
            } catch (\Throwable) {
            }
            $this->appendLog($region, 'sandbox_gvisor_status_message', 'gVisor RuntimeClass and installer removed; sandbox mappings set to runc');

            if (ContainerRuntimeHelper::isContainerd($runtime['flavor'])) {
                $this->appendLog($region, 'sandbox_gvisor_status_message', "Removing runsc from the nodes ({$runtime['flavor']} {$runtime['runtime_version']}): containerd config, binaries"
                    .(ContainerRuntimeHelper::isK3sFamily($runtime['flavor']) ? "; {$runtime['flavor']} restarts once per node where runsc is loaded" : '; containerd restarts where runsc is loaded'));
                $failure = $this->cleanupGvisorNodes($k8s, $runtime);
                if ($failure !== null) {
                    throw new \RuntimeException("gVisor node cleanup failed: {$failure}");
                }
                $this->appendLog($region, 'sandbox_gvisor_status_message', 'Nodes clean: runsc runtime and binaries removed');
            }

            $region->update([
                'sandbox_gvisor_status' => 'not_installed',
                'sandbox_gvisor_status_message' => null,
            ]);

            Log::info('gVisor uninstalled', ['region' => $region->name]);
        } catch (SandboxInUseException $e) {
            // Nothing was changed: gVisor stays installed and working
            $region->update([
                'sandbox_gvisor_status' => 'ready',
                'sandbox_gvisor_status_message' => 'Uninstall refused: '.$e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $region->update([
                'sandbox_gvisor_status' => 'error',
                'sandbox_gvisor_status_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Run the gVisor cleanup DaemonSet on the nodes and wait for it; the DaemonSet is removed
     * afterwards (or after a failure, so it does not crash-loop).
     *
     * @return string|null null when every node is clean, else the failure
     */
    private function cleanupGvisorNodes(KubernetesClient $k8s, array $runtime): ?string
    {
        try {
            $this->ensureNamespace($k8s, self::SANDBOX_NAMESPACE);
            // Pods of an earlier run still carry its termination message: let them go first
            $k8s->deleteResource('apps/v1', 'DaemonSet', self::GVISOR_CLEANUP_DS, self::SANDBOX_NAMESPACE);
            $this->waitFor(fn () => empty($k8s->listResources('v1', 'Pod', self::SANDBOX_NAMESPACE, ['runr.dev/component' => self::GVISOR_CLEANUP_DS])['items'] ?? []), 90, 2);
            if (! $k8s->applyResource($this->buildGvisorCleanupDaemonSet(self::SANDBOX_NAMESPACE, $runtime), self::SANDBOX_NAMESPACE)) {
                return 'cannot create the gvisor-cleanup DaemonSet';
            }

            $failure = null;
            $ready = $this->waitFor(function () use ($k8s, &$failure) {
                $failure = $this->installerFailure($k8s, self::GVISOR_CLEANUP_DS);

                return $failure !== null || $this->isDaemonSetReady($k8s, self::SANDBOX_NAMESPACE, self::GVISOR_CLEANUP_DS);
            }, self::GVISOR_WAIT_SECONDS);

            if ($failure !== null) {
                return $failure;
            }
            if (! $ready) {
                return 'the gvisor-cleanup DaemonSet did not finish within '.intdiv(self::GVISOR_WAIT_SECONDS, 60).' minutes';
            }

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        } finally {
            try {
                $k8s->deleteResource('apps/v1', 'DaemonSet', self::GVISOR_CLEANUP_DS, self::SANDBOX_NAMESPACE);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * "namespace/name" of the running pods with the RuntimeClass (a name, or a predicate on
     * the name).
     *
     * @param  string|callable(string): bool  $runtimeClass
     * @return list<string>
     */
    private function podsUsingRuntimeClass(KubernetesClient $k8s, string|callable $runtimeClass): array
    {
        $pods = $k8s->listClusterResources('v1', 'Pod');
        if ($pods === null) {
            throw new \RuntimeException('cannot list the pods of the cluster');
        }

        $matches = is_string($runtimeClass)
            ? fn (string $rc) => $rc === $runtimeClass
            : $runtimeClass;

        $inUse = [];
        foreach ($pods['items'] ?? [] as $pod) {
            $rc = $pod['spec']['runtimeClassName'] ?? null;
            if (! is_string($rc) || ! $matches($rc)) {
                continue;
            }
            // Only running sandboxes matter: a pod that never started holds no runsc process
            if (($pod['status']['phase'] ?? '') !== 'Running') {
                continue;
            }
            $inUse[] = ($pod['metadata']['namespace'] ?? '?').'/'.($pod['metadata']['name'] ?? '?');
        }

        return $inUse;
    }

    /**
     * Enable kubernetes.podspec-runtimeclassname in Knative Serving config-features.
     * Prefers CR patch (operator reconciles), falls back to ConfigMap.
     */
    private function enableRuntimeClassFeature(KubernetesClient $k8s, KnativeRegion $region): void
    {
        $servingNs = $region->knative_serving_namespace ?? 'knative-serving';
        $feature = ['kubernetes.podspec-runtimeclassname' => 'enabled'];

        $cr = $k8s->getResourceStatus('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs);

        if ($cr) {
            $k8s->mergePatch('operator.knative.dev/v1beta1', 'KnativeServing', 'knative-serving', $servingNs, [
                'spec' => ['config' => ['features' => $feature]],
            ]);
        } else {
            $k8s->strategicMergePatch('v1', 'ConfigMap', 'config-features', $servingNs, ['data' => $feature]);
        }
    }

    /**
     * Append a timestamped line to an install / uninstall step log (the region's
     * sandbox_*_status_message). $attributes are saved with it — the final line of an install
     * sets the status in the same write, so the log is kept next to "ready" / "error".
     */
    private function appendLog(KnativeRegion $region, string $field, string $message, array $attributes = []): void
    {
        $region->refresh();
        $log = $region->{$field} ?? '';
        $time = now()->format('H:i:s');
        $log .= "[{$time}] {$message}\n";
        $region->update([$field => $log] + $attributes);

        $component = str_contains($field, 'kata') ? 'kata' : 'gvisor';
        // Live progress only: the log is in the region row. A broadcast failure (Reverb restarting
        // during a RunR upgrade, when region:reconcile queues a Kata install) must not fail the install
        try {
            event(new PlatformEvent('install.progress', [
                'region_id' => $region->id,
                'component' => $component,
                'message' => $log,
            ]));
        } catch (\Throwable $e) {
            Log::warning('Sandbox install progress not broadcast', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Container runtime of the nodes the sandbox installers run on
     * (the runr.dev/dedicated=projects workers when $dedicatedOnly).
     */
    private function detectSandboxRuntime(KubernetesClient $k8s, bool $dedicatedOnly): array
    {
        $selector = $dedicatedOnly ? ['runr.dev/dedicated' => 'projects'] : [];
        $nodes = $k8s->listClusterResources('v1', 'Node', $selector)['items'] ?? [];
        $nodes = array_filter($nodes, fn ($n) => ($n['metadata']['labels']['kubernetes.io/os'] ?? 'linux') === 'linux');

        return ContainerRuntimeHelper::resolveCluster($nodes);
    }

    /**
     * "runr: ERROR ..." termination message of a failed installer container, or null.
     * With $ds: only pods of that DaemonSet object at its current generation (isPodOf()).
     */
    private function installerFailure(KubernetesClient $k8s, string $component, ?array $ds = null): ?string
    {
        $pods = $k8s->listResources('v1', 'Pod', self::SANDBOX_NAMESPACE, ['runr.dev/component' => $component]);

        foreach ($pods['items'] ?? [] as $pod) {
            if ($ds !== null && (! empty($pod['metadata']['deletionTimestamp']) || ! self::isPodOf($pod, $ds))) {
                continue;
            }
            foreach ($pod['status']['containerStatuses'] ?? [] as $cs) {
                foreach ([$cs['state']['terminated'] ?? null, $cs['lastState']['terminated'] ?? null] as $term) {
                    $message = (string) ($term['message'] ?? '');
                    $pos = strrpos($message, 'runr: ERROR:');
                    if ($pos !== false && ($term['exitCode'] ?? 0) !== 0) {
                        return trim(substr($message, $pos + strlen('runr: ERROR:')));
                    }
                }
            }
        }

        return null;
    }

    /**
     * Whether a pod belongs to this DaemonSet object (owner UID — a DaemonSet re-created under the
     * same name starts at generation 1 again) and to its current pod template
     * (pod-template-generation, set by the DaemonSet controller). Missing fields are not held
     * against the pod.
     */
    private static function isPodOf(array $pod, array $ds): bool
    {
        $uid = $ds['metadata']['uid'] ?? null;
        $owners = array_column($pod['metadata']['ownerReferences'] ?? [], 'uid');
        if ($uid !== null && $owners !== [] && ! in_array($uid, $owners, true)) {
            return false;
        }

        $generation = $pod['metadata']['labels']['pod-template-generation'] ?? null;

        return $generation === null || (string) $generation === (string) ($ds['metadata']['generation'] ?? $generation);
    }

    private function hasDedicatedWorkerNodes(KubernetesClient $k8s): bool
    {
        try {
            // cluster-scoped (a null namespace threw a TypeError: dedicated workers were never seen)
            $nodes = $k8s->listClusterResources('v1', 'Node', ['runr.dev/dedicated' => 'projects']);

            return ! empty($nodes['items']);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resetMappings(KnativeRegion $region, string $runtime): void
    {
        $updates = [];
        foreach (['sandbox_xaas_runtime', 'sandbox_faas_runtime', 'sandbox_caas_runtime', 'sandbox_build_runtime'] as $field) {
            $val = $region->{$field};
            if ($val === $runtime || ($runtime === 'kata' && $val && str_starts_with($val, 'kata-'))) {
                $updates[$field] = 'runc';
            }
        }
        if ($updates) {
            $region->update($updates);
        }
    }
}
