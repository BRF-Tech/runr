<?php

namespace App\Services\Wasm;

use App\Events\PlatformEvent;
use App\Exceptions\SandboxInUseException;
use App\Models\KnativeRegion;
use App\Services\Concerns\KnativeClusterTrait;
use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\KubernetesClient;
use App\Support\Images\RegistryMove;
use Illuminate\Support\Facades\Log;

/**
 * WebAssembly support of a region (Settings → Regions → Sandbox → WebAssembly): runwasi shims on
 * the selected nodes, their RuntimeClasses and the Knative features Wasm functions need.
 *
 * Install (also: change of shims or nodes):
 *  1. checks: the selected nodes are eligible (WasmNodes) and run one containerd flavor; nodes
 *     taken out of the selection or shims deselected must not run WebAssembly pods;
 *  2. a deselected shim's RuntimeClass and node labels go first (no new pods for it);
 *  3. the selected nodes get runr.dev/wasm-target=true and the wasm-installer DaemonSet installs
 *     the shims there (WasmNodeScripts::install(); containerd / k3s / RKE2 restart once per node
 *     where the configuration changed, one node at a time);
 *  4. every node the installer finished on gets runr.dev/wasm=true and runr.dev/wasm-<shim>=true —
 *     what the RuntimeClasses select, so pods never land on a node without the shim;
 *  5. RuntimeClasses (wasmtime-spin-v2, wasmtime-v1) and the Knative features
 *     (WasmKnativeFeatures);
 *  6. nodes taken out of the selection are cleaned (wasm-cleanup DaemonSet).
 * A failed first install takes back what it did on the nodes; a failed change of a working
 * install leaves the nodes as they are and reports the error.
 *
 * Uninstall: refused while pods run under a Wasm RuntimeClass; RuntimeClasses and node labels
 * first, then the installer goes and the cleanup DaemonSet removes RunR's configuration and the
 * binaries from every node RunR prepared.
 */
class WasmInstallService
{
    use KnativeClusterTrait;

    public const INSTALL_WARNING = 'Installing WebAssembly support restarts the container runtime once on every selected node '
        .'where its configuration changes (containerd, or the k3s / RKE2 service that runs it), one node at a time. '
        .'Running pods keep running; on a single-node k3s or RKE2 cluster the Kubernetes API is unavailable for about 10–20 seconds.';

    /** Installer / cleanup waits (download, config, staggered runtime restarts; tests shorten them). */
    public int $installWaitSeconds = 900;

    public int $cleanupWaitSeconds = 600;

    public int $pollSeconds = 5;

    /**
     * @param  list<string>  $shimIds
     * @param  list<string>|null  $nodeNames  null = every eligible node
     * @param  string|null  $previousStatus  the WebAssembly status before the caller marked the region
     *                                       "installing" (null: the region's status now) — "ready" makes
     *                                       this a change of a working install
     */
    public function install(KnativeRegion $region, array $shimIds, ?array $nodeNames = null, ?string $previousStatus = null): void
    {
        $shimIds = array_values(array_intersect(WasmShim::IDS, array_unique($shimIds ?: [WasmShim::SPIN])));
        $shims = array_map([WasmShim::class, 'get'], $shimIds);
        $wasReady = ($previousStatus ?? $region->sandbox_wasm_status) === 'ready';
        $prevShims = array_values((array) ($region->sandbox_wasm_shims ?? []));
        $prevNodes = $region->sandbox_wasm_nodes;
        $applied = false;
        $k8s = null;
        $runtime = null;
        $targets = [];

        $region->update([
            'sandbox_wasm_status' => 'installing',
            'sandbox_wasm_status_message' => '',
            'sandbox_wasm_shims' => $shimIds,
            'sandbox_wasm_nodes' => $nodeNames,
        ]);

        $log = fn (string $message) => $this->appendLog($region, $message);

        try {
            if ($shims === []) {
                throw new \RuntimeException('Select at least one WebAssembly shim ('.implode(', ', WasmShim::IDS).')');
            }

            $k8s = new KubernetesClient($region->kubeconfig);
            $nodes = $this->listNodes($k8s);

            // 1. which nodes
            $eligible = WasmNodes::eligibleNames($nodes);
            $targets = $nodeNames === null ? $eligible : array_values(array_unique($nodeNames));
            $notEligible = array_values(array_diff($targets, $eligible));
            if ($notEligible) {
                $why = collect(WasmNodes::describe($nodes))->keyBy('name');
                throw new \RuntimeException('These nodes cannot get WebAssembly support: '.implode(', ', array_map(
                    fn ($n) => $n.' ('.($why[$n]['reason'] ?? 'unknown node').')',
                    $notEligible,
                )));
            }
            if ($targets === []) {
                throw new \RuntimeException('No node can get WebAssembly support: it needs Linux nodes (amd64 / arm64) with containerd '
                    .'(plain, k3s or RKE2) that projects can run on');
            }

            $runtime = ContainerRuntimeHelper::resolveCluster(WasmNodes::pick($nodes, $targets));
            if ($runtime['mixed']) {
                throw new \RuntimeException('The selected nodes run different container runtimes ('
                    .collect($runtime['nodes'])->map(fn ($flavor, $node) => "{$node}={$flavor}")->implode(', ')
                    .'); select nodes of one kind');
            }
            $log('Nodes: '.implode(', ', $targets)." — {$runtime['flavor']} ({$runtime['runtime_version']})");
            $log('Shims: '.implode(', ', array_map(fn (WasmShim $s) => "{$s->name} {$s->version} (RuntimeClass {$s->runtimeClass})", $shims)));

            $previousTargets = WasmNodes::targetNames($nodes);
            $dropped = array_values(array_diff($previousTargets, $targets));
            $removedShims = array_values(array_diff(
                array_unique(array_merge($prevShims, $this->labelledShims($nodes))),
                $shimIds,
            ));

            // Never take a shim away from running pods
            $inUse = $this->podsUsingWasm($k8s, $removedShims ? array_map(fn ($id) => WasmShim::get($id)->runtimeClass, $removedShims) : [], $dropped);
            if ($inUse) {
                $shown = implode(', ', array_slice($inUse, 0, 5)).(count($inUse) > 5 ? ', …' : '');
                throw new SandboxInUseException(count($inUse)." running WebAssembly pod(s) use what this change removes ({$shown}). "
                    .'Redeploy or delete those projects first, then apply the change again.');
            }

            $this->ensureNamespace($k8s, WasmManifests::NAMESPACE);

            // 2. deselected shims: no new pods for them
            foreach ($removedShims as $id) {
                $shim = WasmShim::get($id);
                $this->deleteRuntimeClass($k8s, $shim);
                foreach ($nodes as $node) {
                    $name = $node['metadata']['name'] ?? '';
                    if (($node['metadata']['labels'][$shim->nodeLabel()] ?? null) !== null) {
                        $this->labelNode($k8s, $name, [$shim->nodeLabel() => null]);
                    }
                }
                $log("{$shim->name} shim deselected: RuntimeClass {$shim->runtimeClass} and its node labels removed");
            }
            foreach ($dropped as $name) {
                $this->labelNode($k8s, $name, $this->wasmLabels(null) + [WasmShim::TARGET_LABEL => null, WasmShim::CLEANUP_LABEL => 'true']);
            }
            if ($dropped) {
                $log('Taken out of the selection (cleaned after the install): '.implode(', ', $dropped));
            }

            // 3. installer on the selected nodes
            foreach ($targets as $name) {
                $this->labelNode($k8s, $name, [WasmShim::TARGET_LABEL => 'true', WasmShim::CLEANUP_LABEL => null]);
            }
            // a cleanup DaemonSet of an interrupted uninstall would undo this install
            $this->deleteNodeDaemonSet($k8s, WasmManifests::CLEANUP_DS);

            $log('Deploying the WebAssembly installer: on each node it installs the shim binaries (sha256-checked; a node with '
                .'another build of a shim gets this one) and registers them with '
                .self::restartText($runtime['flavor']).', which restarts once where its configuration changes, one node at a time '
                .'(running pods keep running)...');
            $applied = true;
            if (! $k8s->applyResource(WasmManifests::installerDaemonSet($shims, $runtime), WasmManifests::NAMESPACE)) {
                throw new \RuntimeException('cannot apply DaemonSet '.WasmManifests::NAMESPACE.'/'.WasmManifests::INSTALLER_DS);
            }

            $ready = $this->waitForDaemonSet($k8s, WasmManifests::INSTALLER_DS, 'wasm-installer', $this->installWaitSeconds);
            $log('WebAssembly shims installed and loaded on '.implode(', ', $ready));

            // 4. node labels: what the RuntimeClasses select
            foreach ($ready as $name) {
                $this->labelNode($k8s, $name, $this->wasmLabels($shims));
            }
            $log('Node labels set: '.WasmShim::NODE_LABEL.'=true, '.implode(', ', array_map(fn (WasmShim $s) => $s->nodeLabel().'=true', $shims)));

            // 5. RuntimeClasses + Knative features
            foreach ($shims as $shim) {
                $log($this->syncRuntimeClass($k8s, $shim));
            }
            $changed = WasmKnativeFeatures::enable($k8s, WasmKnativeFeatures::servingNamespace($region));
            $log($changed
                ? 'Knative features enabled: '.implode(', ', array_keys($changed))
                : 'Knative features already enabled: '.implode(', ', array_keys(WasmKnativeFeatures::FEATURES)));

            // 6. nodes taken out of the selection
            if ($dropped) {
                $this->runCleanup($k8s, $region, $runtime, $dropped);
            }

            $this->appendLog($region, 'WebAssembly support installed.', ['sandbox_wasm_status' => 'ready']);
            Log::info('WebAssembly support installed', ['region' => $region->name, 'shims' => $shimIds, 'nodes' => $targets]);
        } catch (SandboxInUseException $e) {
            // Nothing was changed: the previous install stays as it was
            $this->appendLog($region, 'Refused: '.$e->getMessage(), [
                'sandbox_wasm_status' => $wasReady ? 'ready' : 'error',
                'sandbox_wasm_shims' => $wasReady ? $prevShims : $shimIds,
                'sandbox_wasm_nodes' => $wasReady ? $prevNodes : $nodeNames,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $message = $e->getMessage();

            if ($k8s && $applied) {
                // Stop the installer: a crash-looping script would re-apply its changes
                $this->deleteNodeDaemonSet($k8s, WasmManifests::INSTALLER_DS, false);
            }
            if ($k8s && $applied && ! $wasReady && $runtime) {
                // A failed first install takes back what it changed on the nodes
                $this->appendLog($region, "Install failed: {$message}");
                $this->appendLog($region, 'Rolling back the node changes (containerd configuration, shim binaries)...');
                try {
                    foreach (WasmShim::all() as $shim) {
                        $this->deleteRuntimeClass($k8s, $shim);
                    }
                    foreach ($targets as $name) {
                        $this->labelNode($k8s, $name, $this->wasmLabels(null));
                    }
                    $this->runCleanup($k8s, $region, $runtime, $targets);
                    $message .= ' — node changes rolled back';
                } catch (\Throwable $cleanup) {
                    $message .= ' — node rollback failed too: '.$cleanup->getMessage();
                }
            }

            if ($wasReady && $k8s) {
                // A change of a working install: what the nodes have (their shim labels) keeps working
                // and deploying — the region stays ready with those shims, the log ends with the error
                try {
                    $actual = $this->labelledShims($this->listNodes($k8s));
                } catch (\Throwable) {
                    $actual = $prevShims;
                }
                $this->appendLog($region, "ERROR: {$message}");
                $this->appendLog($region, 'WebAssembly support stays installed with '.(implode(', ', $actual) ?: 'no shim')
                    .'; the requested change was not completed.', [
                        'sandbox_wasm_status' => $actual ? 'ready' : 'error',
                        'sandbox_wasm_shims' => $actual ?: $prevShims,
                        'sandbox_wasm_nodes' => $prevNodes,
                    ]);
            } else {
                $this->appendLog($region, "ERROR: {$message}", ['sandbox_wasm_status' => 'error']);
            }
            Log::error('WebAssembly install failed', ['region' => $region->name, 'error' => $message]);
            throw new \RuntimeException($message, 0, $e);
        }
    }

    public function uninstall(KnativeRegion $region): void
    {
        $region->update([
            'sandbox_wasm_status' => 'uninstalling',
            'sandbox_wasm_status_message' => '',
        ]);

        try {
            $k8s = new KubernetesClient($region->kubeconfig);

            $inUse = $this->podsUsingWasm($k8s, WasmShim::runtimeClassNames());
            if ($inUse) {
                $shown = implode(', ', array_slice($inUse, 0, 5)).(count($inUse) > 5 ? ', …' : '');
                throw new SandboxInUseException(count($inUse)." running pod(s) still use WebAssembly ({$shown}). "
                    .'Delete those projects (or move them to another runtime), then uninstall again.');
            }

            // No new Wasm pods from here on
            foreach (WasmShim::all() as $shim) {
                $this->deleteRuntimeClass($k8s, $shim);
            }
            $this->appendLog($region, 'WebAssembly RuntimeClasses removed');

            $nodes = $this->listNodes($k8s);
            $prepared = WasmNodes::preparedNames($nodes);
            foreach ($prepared as $name) {
                $this->labelNode($k8s, $name, $this->wasmLabels(null) + [WasmShim::TARGET_LABEL => null, WasmShim::CLEANUP_LABEL => 'true']);
            }

            $this->deleteNodeDaemonSet($k8s, WasmManifests::INSTALLER_DS);

            if ($prepared) {
                $runtime = ContainerRuntimeHelper::resolveCluster(WasmNodes::pick($nodes, $prepared));
                $this->appendLog($region, 'Removing the shims from '.implode(', ', $prepared).': RunR\'s runtime handlers out of the '
                    .'containerd configuration ('.self::restartText($runtime['flavor']).' restarts where they are loaded), then the binaries');
                $this->runCleanup($k8s, $region, $runtime, $prepared);
            }

            $region->update([
                'sandbox_wasm_status' => 'not_installed',
                'sandbox_wasm_status_message' => null,
                'sandbox_wasm_shims' => null,
                'sandbox_wasm_nodes' => null,
            ]);
            $this->broadcast($region, '');

            Log::info('WebAssembly support uninstalled', ['region' => $region->name]);
        } catch (SandboxInUseException $e) {
            $region->update([
                'sandbox_wasm_status' => 'ready',
                'sandbox_wasm_status_message' => 'Uninstall refused: '.$e->getMessage(),
            ]);
            throw $e;
        } catch (\Throwable $e) {
            $this->appendLog($region, 'ERROR: '.$e->getMessage(), ['sandbox_wasm_status' => 'error']);
            throw $e;
        }
    }

    /**
     * The region's WebAssembly state on the cluster now (read-only): nodes with their eligibility
     * and shims, the RuntimeClasses and the Knative features still to enable.
     *
     * @return array{nodes: list<array>, runtime_classes: array<string, ?string>, knative_features_to_enable: list<string>, installer: ?array}
     */
    public function clusterState(KnativeRegion $region, KubernetesClient $k8s): array
    {
        $nodes = WasmNodes::describe($this->listNodes($k8s));

        $classes = [];
        foreach (WasmShim::all() as $shim) {
            $live = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', $shim->runtimeClass);
            $classes[$shim->runtimeClass] = $live['handler'] ?? null;
        }

        $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', WasmManifests::INSTALLER_DS, WasmManifests::NAMESPACE);

        return [
            'nodes' => $nodes,
            'runtime_classes' => $classes,
            'knative_features_to_enable' => array_keys(WasmKnativeFeatures::missing($k8s, WasmKnativeFeatures::servingNamespace($region))),
            'installer' => $ds ? [
                'desired' => (int) ($ds['status']['desiredNumberScheduled'] ?? 0),
                'ready' => (int) ($ds['status']['numberReady'] ?? 0),
                'shims' => array_values(array_filter(explode(',', (string) ($ds['spec']['template']['metadata']['annotations'][WasmManifests::SHIMS_ANNOTATION] ?? '')))),
            ] : null,
        ];
    }

    /**
     * Whether the wasm-installer DaemonSet on the cluster installs what this release installs for
     * $shims: "current", "outdated" (it installs other shim binaries — an install of an earlier
     * release) or "missing". namespace: outdated only because a shim image is referenced under
     * one of RunR's earlier image namespaces (RegistryMove) — the same image, the same binaries.
     *
     * @param  list<WasmShim>  $shims
     * @return array{state: string, installs: string, namespace?: bool} installs: the shims the live
     *                                                                  installer installs (id + source), for the report
     */
    public function installerDrift(KubernetesClient $k8s, array $shims): array
    {
        $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', WasmManifests::INSTALLER_DS, WasmManifests::NAMESPACE);
        if (! $ds) {
            return ['state' => 'missing', 'installs' => ''];
        }

        $live = '';
        foreach ($ds['spec']['template']['spec']['containers'][0]['env'] ?? [] as $env) {
            if (($env['name'] ?? null) === 'WASM_SHIMS') {
                $live = (string) ($env['value'] ?? '');
            }
        }
        // "id … source_x86_64 ref_x86_64 …" → "id (source)" per line
        $installs = implode(', ', array_map(function (string $line) {
            $f = preg_split('/\s+/', trim($line)) ?: [];

            return ($f[0] ?? '?').' ('.(($f[4] ?? '') === 'image' ? ($f[5] ?? '?') : basename(dirname((string) ($f[4] ?? '?')))).')';
        }, array_filter(explode("\n", $live), fn ($l) => trim($l) !== '')));

        $wanted = WasmManifests::shimLines($shims);

        return [
            'state' => $live === $wanted ? 'current' : 'outdated',
            'installs' => $installs,
            'namespace' => $live !== $wanted && RegistryMove::text($live) === $wanted,
        ];
    }

    /**
     * Create / update a shim's RuntimeClass. A RuntimeClass of that name that RunR did not create
     * (e.g. installed by SpinKube's runtime-class-manager) is left alone and reported; one of RunR's
     * with another handler is recreated (the handler is immutable).
     */
    public function syncRuntimeClass(KubernetesClient $k8s, WasmShim $shim): string
    {
        $desired = WasmManifests::runtimeClass($shim);
        $live = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', $shim->runtimeClass);

        if ($live && ($live['metadata']['labels']['app.kubernetes.io/managed-by'] ?? null) !== 'runr') {
            throw new \RuntimeException("RuntimeClass {$shim->runtimeClass} exists and was not created by RunR (handler "
                .($live['handler'] ?? '?').'); remove it or the tool that manages it, then install again');
        }

        if ($live && ($live['handler'] ?? null) !== $shim->handler) {
            if (! $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', $shim->runtimeClass)) {
                throw new \RuntimeException("cannot delete RuntimeClass {$shim->runtimeClass} to change its handler");
            }
            $k8s->applyClusterResource($desired);

            return "recreated RuntimeClass {$shim->runtimeClass}: handler {$live['handler']} → {$shim->handler}";
        }

        $k8s->applyClusterResource($desired);

        return ($live ? 'updated' : 'created')." RuntimeClass {$shim->runtimeClass} (handler {$shim->handler}, nodeSelector {$shim->nodeLabel()}=true)";
    }

    public static function restartText(string $flavor): string
    {
        return match ($flavor) {
            ContainerRuntimeHelper::FLAVOR_K3S => 'the k3s service (containerd, kubelet and, on servers, the Kubernetes API)',
            ContainerRuntimeHelper::FLAVOR_RKE2 => 'the RKE2 service (containerd, kubelet and, on servers, the Kubernetes API)',
            default => 'containerd',
        };
    }

    // ── internals ───────────────────────────────────────────────────────────

    /** @return list<array> */
    private function listNodes(KubernetesClient $k8s): array
    {
        $nodes = $k8s->listClusterResources('v1', 'Node');
        if ($nodes === null) {
            throw new \RuntimeException('cannot list the nodes of the cluster');
        }

        return array_values($nodes['items'] ?? []);
    }

    /**
     * Labels of a node with WebAssembly support for $shims (null: every WebAssembly label removed).
     *
     * @param  list<WasmShim>|null  $shims
     * @return array<string, ?string>
     */
    private function wasmLabels(?array $shims): array
    {
        $labels = [WasmShim::NODE_LABEL => $shims === null ? null : 'true'];
        $wanted = $shims === null ? [] : array_map(fn (WasmShim $s) => $s->id, $shims);
        foreach (WasmShim::all() as $shim) {
            $labels[$shim->nodeLabel()] = in_array($shim->id, $wanted, true) ? 'true' : null;
        }

        return $labels;
    }

    /** @return list<string> shims some node is labelled for */
    private function labelledShims(array $nodes): array
    {
        $ids = [];
        foreach (WasmNodes::describe($nodes) as $node) {
            $ids = array_merge($ids, $node['shims']);
        }

        return array_values(array_unique($ids));
    }

    /** @param array<string, ?string> $labels null removes the label */
    private function labelNode(KubernetesClient $k8s, string $node, array $labels): void
    {
        if (! $k8s->strategicMergePatchCluster('v1', 'Node', $node, ['metadata' => ['labels' => $labels]])) {
            throw new \RuntimeException("cannot label node {$node}");
        }
    }

    private function deleteRuntimeClass(KubernetesClient $k8s, WasmShim $shim): void
    {
        $live = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', $shim->runtimeClass);
        if ($live && ($live['metadata']['labels']['app.kubernetes.io/managed-by'] ?? null) === 'runr') {
            $k8s->deleteClusterResource('node.k8s.io/v1', 'RuntimeClass', $shim->runtimeClass);
        }
    }

    /**
     * "namespace/name" of the running pods with one of the RuntimeClasses — on $nodes only when
     * given (with no RuntimeClass given: any Wasm RuntimeClass on those nodes).
     *
     * @param  list<string>  $runtimeClasses
     * @param  list<string>  $nodes
     * @return list<string>
     */
    private function podsUsingWasm(KubernetesClient $k8s, array $runtimeClasses, array $nodes = []): array
    {
        if ($runtimeClasses === [] && $nodes === []) {
            return [];
        }

        $pods = $k8s->listClusterResources('v1', 'Pod');
        if ($pods === null) {
            throw new \RuntimeException('cannot list the pods of the cluster');
        }

        $wasm = WasmShim::runtimeClassNames();
        $inUse = [];
        foreach ($pods['items'] ?? [] as $pod) {
            $rc = $pod['spec']['runtimeClassName'] ?? null;
            if (! is_string($rc) || ($pod['status']['phase'] ?? '') !== 'Running') {
                continue;
            }
            $byClass = in_array($rc, $runtimeClasses, true);
            $byNode = $nodes !== [] && in_array($rc, $wasm, true) && in_array($pod['spec']['nodeName'] ?? '', $nodes, true);
            if ($byClass || $byNode) {
                $inUse[] = ($pod['metadata']['namespace'] ?? '?').'/'.($pod['metadata']['name'] ?? '?');
            }
        }

        return array_values(array_unique($inUse));
    }

    /**
     * Run the cleanup DaemonSet on $nodes (labelled runr.dev/wasm-cleanup=true here) and wait for
     * it; the DaemonSet and the labels are removed afterwards.
     *
     * @param  list<string>  $nodes
     */
    private function runCleanup(KubernetesClient $k8s, KnativeRegion $region, array $runtime, array $nodes): void
    {
        foreach ($nodes as $name) {
            $this->labelNode($k8s, $name, [WasmShim::CLEANUP_LABEL => 'true']);
        }

        $this->deleteNodeDaemonSet($k8s, WasmManifests::CLEANUP_DS);
        try {
            if (! $k8s->applyResource(WasmManifests::cleanupDaemonSet($runtime), WasmManifests::NAMESPACE)) {
                throw new \RuntimeException('cannot apply DaemonSet '.WasmManifests::NAMESPACE.'/'.WasmManifests::CLEANUP_DS);
            }
            $clean = $this->waitForDaemonSet($k8s, WasmManifests::CLEANUP_DS, 'wasm-cleanup', $this->cleanupWaitSeconds);
        } finally {
            $this->deleteNodeDaemonSet($k8s, WasmManifests::CLEANUP_DS, false);
        }

        foreach ($nodes as $name) {
            $this->labelNode($k8s, $name, [WasmShim::CLEANUP_LABEL => null, WasmShim::TARGET_LABEL => null]);
        }
        $this->appendLog($region, 'Nodes clean: '.implode(', ', $clean));
    }

    /**
     * Wait until the node DaemonSet is rolled out and ready on every node it targets.
     *
     * @return list<string> the nodes it is ready on
     */
    private function waitForDaemonSet(KubernetesClient $k8s, string $name, string $what, int $timeout): array
    {
        $failure = null;
        $state = ['ready' => [], 'pending' => ['no status yet']];

        $done = $this->waitFor(function () use ($k8s, $name, &$failure, &$state) {
            $ds = $k8s->getResourceStatus('apps/v1', 'DaemonSet', $name, WasmManifests::NAMESPACE);
            if (! $ds) {
                $state = ['ready' => [], 'pending' => ["{$name} DaemonSet not found"]];

                return false;
            }
            $pods = $k8s->listResourcesOrFail('v1', 'Pod', WasmManifests::NAMESPACE, ['runr.dev/component' => $name]);
            $failure = self::podFailure($pods);
            if ($failure !== null) {
                return true;
            }
            $state = self::rolloutState($ds, $pods);

            return $state['pending'] === [];
        }, $timeout, $this->pollSeconds);

        if ($failure !== null) {
            throw new \RuntimeException("{$what} failed: {$failure}");
        }
        if (! $done) {
            throw new \RuntimeException(sprintf('%s not finished within %d minutes — waiting for: %s (kubectl -n %s logs ds/%s)',
                $what, max(1, intdiv($timeout, 60)), implode('; ', $state['pending']), WasmManifests::NAMESPACE, $name));
        }

        return $state['ready'];
    }

    /**
     * @param  list<array>  $pods
     * @return array{ready: list<string>, pending: list<string>}
     */
    public static function rolloutState(array $ds, array $pods): array
    {
        $status = $ds['status'] ?? [];
        $desired = (int) ($status['desiredNumberScheduled'] ?? 0);
        $updated = (int) ($status['updatedNumberScheduled'] ?? 0);

        if (($status['observedGeneration'] ?? 0) < ($ds['metadata']['generation'] ?? 1) || $desired < 1 || $updated < $desired) {
            return ['ready' => [], 'pending' => ["rollout ({$updated}/{$desired} pods updated)"]];
        }

        $ready = [];
        $pending = [];
        foreach ($pods as $pod) {
            if (! empty($pod['metadata']['deletionTimestamp'])) {
                continue;
            }
            $generation = $pod['metadata']['labels']['pod-template-generation'] ?? null;
            if ($generation !== null && (string) $generation !== (string) ($ds['metadata']['generation'] ?? $generation)) {
                continue;
            }
            $node = $pod['spec']['nodeName'] ?? ($pod['metadata']['name'] ?? '?');
            $isReady = false;
            foreach ($pod['status']['conditions'] ?? [] as $c) {
                if (($c['type'] ?? '') === 'Ready' && ($c['status'] ?? '') === 'True') {
                    $isReady = true;
                }
            }
            if ($isReady) {
                $ready[] = $node;
            } else {
                $pending[] = $node;
            }
        }

        if (count($ready) + count($pending) < $desired) {
            $pending[] = ($desired - count($ready) - count($pending)).' pod(s) not started';
        }

        sort($ready);

        return ['ready' => $ready, 'pending' => $pending];
    }

    /** "runr: ERROR ..." of a node script that failed (termination message), else null. */
    public static function podFailure(array $pods): ?string
    {
        foreach ($pods as $pod) {
            if (! empty($pod['metadata']['deletionTimestamp'])) {
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

    /** Delete a node DaemonSet and (by default) wait until its pods are gone. */
    private function deleteNodeDaemonSet(KubernetesClient $k8s, string $name, bool $wait = true): void
    {
        try {
            $k8s->deleteResource('apps/v1', 'DaemonSet', $name, WasmManifests::NAMESPACE);
        } catch (\Throwable) {
        }
        if ($wait) {
            $this->waitFor(
                fn () => $k8s->listResourcesOrFail('v1', 'Pod', WasmManifests::NAMESPACE, ['runr.dev/component' => $name]) === [],
                90,
                max(1, min(2, $this->pollSeconds)),
            );
        }
    }

    /**
     * Append a timestamped line to the install log (sandbox_wasm_status_message); $attributes are
     * saved with it. Broadcast as install.progress (component wasm) for the console.
     */
    private function appendLog(KnativeRegion $region, string $message, array $attributes = []): void
    {
        $region->refresh();
        $log = (string) ($region->sandbox_wasm_status_message ?? '');
        $log .= '['.now()->format('H:i:s')."] {$message}\n";
        $region->update(['sandbox_wasm_status_message' => $log] + $attributes);

        $this->broadcast($region, $log);
    }

    private function broadcast(KnativeRegion $region, string $log): void
    {
        // Live progress only: the log is in the region row
        try {
            event(new PlatformEvent('install.progress', [
                'region_id' => $region->id,
                'component' => 'wasm',
                'message' => $log,
            ]));
        } catch (\Throwable $e) {
            Log::warning('WebAssembly install progress not broadcast', ['region' => $region->name, 'error' => $e->getMessage()]);
        }
    }
}
