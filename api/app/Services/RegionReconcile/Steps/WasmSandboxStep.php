<?php

namespace App\Services\RegionReconcile\Steps;

use App\Jobs\InstallWasmJob;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\Wasm\WasmInstallService;
use App\Services\Wasm\WasmKnativeFeatures;
use App\Services\Wasm\WasmManifests;
use App\Services\Wasm\WasmShim;

/**
 * Keeps what WebAssembly functions depend on in place on clusters whose region has WebAssembly
 * support ready:
 *  - the Knative features kubernetes.podspec-runtimeclassname / -nodeselector (a Knative
 *    re-install or a change of the dedicated workers may have switched them off) — merge patch of
 *    those two keys only (WasmKnativeFeatures);
 *  - the shims on the nodes: when the wasm-installer DaemonSet installs other shim binaries than
 *    this release (an install of an earlier release, e.g. the upstream Spin shim before RunR's
 *    v0.26.0-runr.1 build) or is missing (a failed change deletes it; the nodes keep what they
 *    had), the region's WebAssembly install is queued once with its recorded shims and nodes
 *    (InstallWasmJob, as a change of a working install: a failure keeps the region ready). The
 *    installer replaces the binaries in place, one node at a time; the container runtime restarts
 *    only on nodes where RunR's configuration block changes. Never silent: the dry run says so, a
 *    real run says it queued it;
 *  - the RuntimeClasses of the installed shims with this release's handler and nodeSelector (the
 *    queued install syncs them itself).
 */
final class WasmSandboxStep implements ReconcileStep
{
    public function name(): string
    {
        return 'wasm-sandbox';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $target = null;
        foreach ($cluster->targets as $candidate) {
            if (($candidate->region->sandbox_wasm_status ?? 'not_installed') === 'ready') {
                $target = $candidate;
                break;
            }
        }
        if (! $target) {
            return [];
        }

        $lines = [];
        $k8s = $cluster->k8s;

        foreach ($cluster->servingNamespaces() as $ns) {
            $missing = WasmKnativeFeatures::missing($k8s, $ns);
            if ($missing === []) {
                continue;
            }
            if (! $dryRun) {
                WasmKnativeFeatures::enable($k8s, $ns);
            }
            $lines[] = ($dryRun ? 'would enable' : 'enabled')." Knative features in {$ns}: ".implode(', ', array_keys($missing));
        }

        $region = $target->region;
        $shims = array_map([WasmShim::class, 'get'], array_values(array_filter(
            (array) ($region->sandbox_wasm_shims ?? []),
            fn ($id) => is_string($id) && WasmShim::isSupported($id),
        )));
        if ($shims === []) {
            return $lines;
        }

        $service = app(WasmInstallService::class);

        $drift = $service->installerDrift($k8s, $shims);
        if ($drift['state'] !== 'current') {
            $now = match (true) {
                $drift['state'] === 'missing' => 'the '.WasmManifests::INSTALLER_DS.' DaemonSet is missing, as after a failed change',
                $drift['installs'] !== '' => "installed now: {$drift['installs']}",
                default => 'the installer installs other shim binaries',
            };
            $what = 'the WebAssembly installer on the region\'s nodes with the shims of this release ('
                .implode(', ', array_map(fn (WasmShim $s) => "{$s->name} {$s->version}", $shims))."; {$now})";
            $how = 'the shim binaries are replaced in place one node at a time (running Wasm pods keep the shim they started with); '
                .'the container runtime restarts only on nodes where RunR\'s configuration block changes';

            if ($dryRun) {
                $lines[] = "would re-run {$what} — {$how}";

                return $lines;
            }

            $this->queueReinstall($target, array_map(fn (WasmShim $s) => $s->id, $shims));
            $lines[] = "re-running {$what} — queued as the WebAssembly install of region {$region->name} "
                ."(status \"installing\" until every node is done); {$how}";

            return $lines;
        }

        foreach ($shims as $shim) {
            $desired = WasmManifests::runtimeClass($shim);
            $live = $k8s->getClusterResource('node.k8s.io/v1', 'RuntimeClass', $shim->runtimeClass);

            $current = $live
                && ($live['handler'] ?? null) === $desired['handler']
                && ($live['scheduling']['nodeSelector'] ?? []) == $desired['scheduling']['nodeSelector'];
            if ($current) {
                continue;
            }

            $lines[] = $dryRun
                ? "would sync RuntimeClass {$shim->runtimeClass} (handler {$shim->handler}, nodeSelector {$shim->nodeLabel()}=true)"
                : $service->syncRuntimeClass($k8s, $shim);
        }

        return $lines;
    }

    /**
     * Queue the region's WebAssembly install once (same shims and nodes), in the row's database
     * context, as a change of a working install: a failure keeps what the nodes have.
     *
     * @param  list<string>  $shimIds
     */
    private function queueReinstall(ReconcileTarget $target, array $shimIds): void
    {
        $target->run(function () use ($target, $shimIds) {
            $region = $target->region;
            $region->update([
                'sandbox_wasm_status' => 'installing',
                'sandbox_wasm_status_message' => 'Queued by region:reconcile: the WebAssembly installer runs again with the shims of this RunR release.',
            ]);

            InstallWasmJob::dispatch($region, $shimIds, $region->sandbox_wasm_nodes, null, 'ready');
        });
    }
}
