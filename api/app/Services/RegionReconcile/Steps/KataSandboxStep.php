<?php

namespace App\Services\RegionReconcile\Steps;

use App\Jobs\InstallSandboxJob;
use App\Services\Helpers\KataRuntime;
use App\Services\RegionReconcile\ClusterGroup;
use App\Services\RegionReconcile\ReconcileStep;
use App\Services\RegionReconcile\ReconcileTarget;
use App\Services\SandboxInstallService;

/**
 * Brings a Kata Containers install made by an earlier RunR release to this release's desired
 * state (KataManifestTrait, KataRuntime): the kata-installer DaemonSet with RunR's kata-deploy
 * custom runtimes and config drop-in, and the RuntimeClasses with handler, pod overhead and
 * nodeSelector.
 *
 * Three kinds of change, reported apart:
 *  - what kata-deploy installs differs (its pod spec or the runtime handlers) → kata-deploy must
 *    run again on every node. Its cleanup and reinstall restart containerd (k3s / RKE2: the whole
 *    service) twice per node, one node at a time. Never silent: the dry run says so, a real run
 *    queues the region's Kata install once (InstallSandboxJob — it waits for every node, then
 *    switches the RuntimeClasses) and says so; while that runs the region shows "installing".
 *  - only RunR's config.d drop-ins differ → written in place on every node
 *    (SandboxInstallService::syncKataDropIns(): a short-lived DaemonSet, no kata-deploy run, no
 *    restart); the Kata shim reads them at every sandbox start, so Kata pods started afterwards
 *    use them. A RunR 0.4.1 install whose kata-deploy matches this release takes this path.
 *  - the RuntimeClasses differ → updated in place (or recreated where the handler changes); no
 *    node is touched, the overhead applies to Kata pods created afterwards.
 *
 * Hardware virtualization first: nodes without a /dev/kvm result are checked (label runr.dev/kvm,
 * which every Kata RuntimeClass selects). A cluster where no node has it is reported ("warning:")
 * and left as it is — Kata is never uninstalled by the reconcile.
 *
 * Kata is cluster-wide: the first region of the cluster whose Kata status is "ready" decides the
 * hypervisors. Regions whose Kata install is running or failed are left alone.
 */
final class KataSandboxStep implements ReconcileStep
{
    public function name(): string
    {
        return 'kata-sandbox';
    }

    public function run(ClusterGroup $cluster, bool $dryRun): array
    {
        $lines = [];
        $target = null;

        foreach ($cluster->targets as $candidate) {
            $status = $candidate->region->sandbox_kata_status ?? 'not_installed';
            if ($status === 'ready') {
                $target ??= $candidate;
            } elseif (in_array($status, ['installing', 'uninstalling', 'error'], true)) {
                $lines[] = "skipped: Kata on region {$candidate->region->name} is {$status}"
                    .($status === 'error' ? ' — install it again from the Sandbox tab' : '');
            }
        }

        if (! $target) {
            return $lines;
        }

        $region = $target->region;
        $hypervisors = array_values((array) ($region->sandbox_kata_hypervisor ?: ['clh']));
        $unmanaged = array_values(array_diff($hypervisors, KataRuntime::HYPERVISORS));

        // Re-running kata-deploy without them would take their runtime handlers off the nodes
        if ($unmanaged) {
            $lines[] = 'skipped: Kata on region '.$region->name.' runs hypervisor(s) '.implode(', ', $unmanaged)
                .' that RunR no longer installs ('.implode(', ', KataRuntime::HYPERVISORS).') — move their projects to another'
                .' runtime, then install Kata again from the Sandbox tab';

            return $lines;
        }

        $service = app(SandboxInstallService::class);

        // Hardware virtualization: every node without a result (new nodes, installs before 0.4.4)
        // is checked — label runr.dev/kvm, which every Kata RuntimeClass selects. The dry run reads
        // what is recorded.
        if ($dryRun) {
            $kvm = $service->kvmStatus($cluster->k8s);
            if ($kvm['unprobed']) {
                $lines[] = 'would check /dev/kvm on node(s) '.implode(', ', $kvm['unprobed'])
                    .' (short-lived unprivileged DaemonSet) and label them '.KataRuntime::KVM_LABEL.' — no restart';
            }
        } else {
            $kvm = $service->ensureKvmProbed($cluster->k8s);
            if ($kvm['changed']) {
                $lines[] = 'checked /dev/kvm: '.SandboxInstallService::describeKvmNodes($kvm).' (node label '.KataRuntime::KVM_LABEL.')';
            }
        }

        if ($kvm['state'] === 'no') {
            // Reported, never uninstalled: projects mapped to Kata need another runtime first
            $lines[] = "warning: Kata is installed on region {$region->name} but none of its nodes has hardware virtualization (/dev/kvm) — "
                .SandboxInstallService::describeKvmNodes($kvm).'. Kata pods cannot start there: map the project types to gVisor or runc, '
                .'then uninstall Kata from the Sandbox tab. Nothing else was changed.';

            return $lines;
        }
        if (! $dryRun && $kvm['kvm_nodes'] === []) {
            $lines[] = 'skipped: /dev/kvm could not be checked on the nodes ('.SandboxInstallService::describeKvmNodes($kvm)
                .') — the Kata install is left as it is';

            return $lines;
        }
        if ($kvm['state'] === 'mixed') {
            $lines[] = 'note: Kata pods run only on the node(s) with /dev/kvm ('.KataRuntime::KVM_LABEL.'=true): '
                .implode(', ', $kvm['kvm_nodes']).' — '.SandboxInstallService::describeKvmNodes($kvm);
        }

        $drift = $service->kataDrift($cluster->k8s, $hypervisors);
        $classes = implode(', ', array_map([KataRuntime::class, 'runtimeClass'], $hypervisors));

        if ($drift['installer'] === 'missing') {
            $lines[] = "skipped: region {$region->name} reports Kata as installed but the kata-installer DaemonSet is missing — install Kata again from the Sandbox tab";

            return $lines;
        }

        if ($drift['installer'] === 'outdated') {
            $what = "kata-deploy on {$drift['nodes']} node(s) with this release's Kata configuration (runtime handlers kata-<hypervisor>-runr: "
                .'shim, VMM and virtiofsd in the pod cgroup, VM sized from the pod limits)';
            $after = "then RuntimeClasses {$classes} switch to those handlers with pod overhead "
                .SandboxInstallService::describeOverheads($hypervisors).' and nodeSelector '.KataRuntime::NODE_LABEL.'=true,'.KataRuntime::KVM_LABEL.'=true'
                .' (no RuntimeClass changes before every node is done)';
            $restart = "restart: kata-deploy's cleanup and reinstall restart {$drift['restart']} on each node, twice back to back, "
                .'one node at a time (running pods keep running; on a single-node cluster the Kubernetes API is unavailable '
                .'for ~10–20 s each time)';

            if ($dryRun) {
                $lines[] = "would re-run {$what}; {$after}";
                $lines[] = $restart;

                return $lines;
            }

            $this->queueReinstall($target, $hypervisors);
            $lines[] = "re-running {$what} — queued as the Kata install of region {$region->name} (status \"installing\" until every node is done); {$after}";
            $lines[] = $restart;

            return $lines;
        }

        // Installer current: drop-ins and RuntimeClasses may follow only once every node runs it
        $rollout = $drift['rollout'];
        $changes = array_filter($drift['runtime_classes'], fn (array $item) => $item['action'] !== null);
        $dropIns = ($drift['dropins'] ?? null) === 'outdated';

        if (! $changes && ! $dropIns) {
            return $lines;
        }

        if ($rollout['failure'] !== null || ! $rollout['done']) {
            $what = array_filter([$dropIns ? 'Kata configuration drop-ins' : null, $changes ? "RuntimeClasses {$classes}" : null]);
            $lines[] = 'skipped: '.implode(' and ', $what).' wait for kata-deploy — '
                .($rollout['failure'] ?? implode('; ', $rollout['pending']));

            return $lines;
        }

        if ($dropIns) {
            $handlers = implode(', ', array_map([KataRuntime::class, 'handler'], $hypervisors));
            if ($dryRun) {
                $lines[] = "would update the Kata configuration drop-ins ({$handlers}) in place on {$drift['nodes']} node(s): "
                    .KataRuntime::dropInSummary().' — no restart (kata-deploy does not run; Kata pods started afterwards use them, running ones keep theirs)';
            } else {
                foreach ($service->syncKataDropIns($cluster->k8s, $hypervisors) as $line) {
                    $lines[] = $line;
                }
            }
        }

        if (! $changes) {
            return $lines;
        }

        if ($dryRun) {
            foreach ($changes as $name => $item) {
                $lines[] = match ($item['action']) {
                    'create' => "would create RuntimeClass {$name}",
                    'recreate' => "would recreate RuntimeClass {$name} (the handler {$item['from_handler']} cannot change in place)",
                    default => "would update RuntimeClass {$name}",
                }.': '.SandboxInstallService::describeRuntimeClass($item['manifest']).' — no restart';
            }

            return $lines;
        }

        foreach ($service->syncKataRuntimeClasses($cluster->k8s, $hypervisors) as $line) {
            $lines[] = $line.' — no restart';
        }
        $lines[] = 'note: the overhead applies to Kata pods created from now on; running ones keep theirs until they are replaced';

        return $lines;
    }

    /** Queue the region's Kata install once (same hypervisors), in the row's database context. */
    private function queueReinstall(ReconcileTarget $target, array $hypervisors): void
    {
        $target->run(function () use ($target, $hypervisors) {
            $region = $target->region;
            $region->update([
                'sandbox_kata_status' => 'installing',
                'sandbox_kata_status_message' => 'Queued by region:reconcile: kata-deploy re-runs with the Kata configuration of this RunR release.',
            ]);

            // the region was ready (only those are queued): a failure before kata-deploy keeps it so
            InstallSandboxJob::dispatch($region, 'kata', $hypervisors, null, ['previous_status' => 'ready']);
        });
    }
}
