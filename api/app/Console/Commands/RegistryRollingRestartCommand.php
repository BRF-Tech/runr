<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Helpers\RunrClusterIdentity;
use App\Services\KubernetesClient;
use Illuminate\Console\Command;

/**
 * Rolling restart of containerd across selected worker nodes.
 * Required after the registry mirror DaemonSet set config_path in config.toml
 * (its log says "containerd restart required"). k3s/rke2 need no restart (hosts.toml only).
 * Only targets nodes with runr.dev/dedicated=projects label (when workers are selected).
 * Drains one node at a time -> restarts containerd -> waits for Ready -> uncordons.
 *
 * Never drains a node that hosts RunR's own pods (the command would evict the control plane
 * it runs in) and never drains when there is only one schedulable node (evicted pods would
 * have nowhere to go). Those nodes are skipped unless --in-place is given: containerd is then
 * restarted without cordon/drain — running containers survive a containerd restart.
 * When no target node can be drained at all (RunR shares the cluster and runs on every
 * target, or a single-node cluster) the command falls back to --in-place by itself instead of
 * skipping everything; when only some nodes were skipped it prints the --in-place command.
 */
class RegistryRollingRestartCommand extends Command
{
    protected $signature = 'registry:rolling-restart {regionId}
        {--timeout=300 : Seconds to wait for node to become Ready after restart}
        {--in-place : Restart containerd without cordon/drain (single-node clusters, nodes hosting RunR)}';

    protected $description = 'Rolling containerd restart on selected worker nodes (safe, one-by-one)';

    public function handle(): int
    {
        $region = KnativeRegion::find($this->argument('regionId'));
        if (! $region?->kubeconfig) {
            $this->error('Region not found or missing kubeconfig');

            return 1;
        }

        $k8s = new KubernetesClient($region->kubeconfig);
        $timeout = (int) $this->option('timeout');
        $inPlace = (bool) $this->option('in-place');
        $ns = $region->registry_namespace ?? 'runr-registry';

        // Determine target nodes
        $allNodes = $k8s->listClusterResources('v1', 'Node');
        $items = $allNodes['items'] ?? [];

        $hasWorkers = ! empty($region->selected_workers);
        $targetNodes = [];

        foreach ($items as $node) {
            $name = $node['metadata']['name'];
            $labels = $node['metadata']['labels'] ?? [];

            if ($hasWorkers) {
                // Only target dedicated worker nodes
                if (($labels['runr.dev/dedicated'] ?? '') === 'projects') {
                    $targetNodes[$name] = $node;
                }
            } else {
                // Skip masters, target all workers
                $isMaster = isset($labels['node-role.kubernetes.io/master'])
                         || isset($labels['node-role.kubernetes.io/control-plane']);
                if (! $isMaster) {
                    $targetNodes[$name] = $node;
                }
            }
        }

        if (empty($targetNodes)) {
            $this->info('No target nodes found for rolling restart.');

            return 0;
        }

        // k3s/rke2: the mirror writes hosts.toml under the k3s containerd dir, read on every pull
        $runtime = ContainerRuntimeHelper::resolveCluster(array_values($targetNodes));
        if (ContainerRuntimeHelper::isK3sFamily($runtime['flavor'])) {
            $this->info("{$runtime['flavor']} nodes: the registry mirror only writes hosts.toml under {$runtime['certs_dir']}, "
                .'which containerd reloads on every pull — no restart needed.');

            return 0;
        }
        if ($runtime['flavor'] !== ContainerRuntimeHelper::FLAVOR_CONTAINERD) {
            $this->error("Unsupported container runtime '{$runtime['runtime_version']}' — only plain containerd nodes need this restart.");

            return 1;
        }

        $runrNodes = $this->runrNodes($region, $k8s);
        $schedulable = $this->schedulableNodeCount($items);

        $this->info(count($targetNodes).' worker nodes targeted. Masters untouched.');
        if ($runrNodes) {
            $this->line('  RunR control plane runs on: '.implode(', ', $runrNodes));
        }

        // Nothing could be drained safely → the default mode would skip every node and restart
        // nothing: restart in place instead (no eviction; running containers survive)
        if (! $inPlace) {
            $undrainable = $this->undrainableNodes(array_keys($targetNodes), $runrNodes, $schedulable);
            if (count($undrainable) === count($targetNodes)) {
                $why = $schedulable <= 1
                    ? 'the cluster has a single schedulable node'
                    : "every target node hosts RunR's own pods (RunR runs in this cluster)";
                $this->warn("No node can be drained safely ({$why}) — falling back to --in-place: "
                    .'containerd is restarted without cordon/drain, running containers keep running.');
                $inPlace = true;
            }
        }
        $this->newLine();

        $skipped = [];

        foreach (array_keys($targetNodes) as $nodeName) {
            $this->info("Processing: {$nodeName}");

            $drain = ! $inPlace;
            if ($drain && in_array($nodeName, $runrNodes, true)) {
                $this->warn("  Hosts RunR's own pods — not drained, skipped (use --in-place to restart containerd without eviction)");
                $skipped[] = $nodeName;

                continue;
            }
            if ($drain && $schedulable <= 1) {
                $this->warn('  Only one schedulable node — draining would leave evicted pods nowhere to run; skipped (use --in-place)');
                $skipped[] = $nodeName;

                continue;
            }

            if ($drain) {
                // Step 1: Cordon
                $this->line('  Cordoning...');
                $k8s->cordonNode($nodeName);

                // Step 2: Drain (evict pods, DaemonSets stay)
                $this->line('  Draining...');
                $k8s->drainNode($nodeName);
            } else {
                $this->line('  In place: no cordon/drain (running containers survive a containerd restart)');
            }

            // Step 3: Restart containerd via a one-shot privileged pod pinned to this node
            $this->line('  Restarting containerd...');
            $restarted = $this->restartContainerdOnNode($k8s, $ns, $nodeName);
            if (! $restarted) {
                $this->warn("  Could not restart containerd on {$nodeName} — skipping");
                if ($drain) {
                    $k8s->uncordonNode($nodeName);
                }
                $skipped[] = $nodeName;

                continue;
            }

            // Step 4: Wait for node Ready
            $this->line("  Waiting for Ready (max {$timeout}s)...");
            $ready = $this->waitForNodeReady($k8s, $nodeName, $timeout);

            if (! $ready) {
                $this->error("  {$nodeName} did not become Ready within {$timeout}s!");
                $this->error('  Aborting to prevent further damage.');
                if ($drain) {
                    $k8s->uncordonNode($nodeName);
                }

                return 1;
            }

            // Step 5: Uncordon
            if ($drain) {
                $k8s->uncordonNode($nodeName);
            }
            $this->info("  {$nodeName} Ready".($drain ? ' and uncordoned' : ''));
            $this->newLine();

            sleep(10);
        }

        if ($skipped) {
            $this->warn('Skipped nodes (containerd NOT restarted): '.implode(', ', $skipped));
            if (! $inPlace) {
                $this->warn("Restart them without eviction: php artisan registry:rolling-restart {$region->id} --in-place");
            }

            return 1;
        }

        $this->info('Rolling restart complete. All worker nodes are Ready.');

        return 0;
    }

    /**
     * Target nodes the default (drain) mode would skip.
     *
     * @param  list<string>  $targets
     * @param  list<string>  $runrNodes
     * @return list<string>
     */
    private function undrainableNodes(array $targets, array $runrNodes, int $schedulable): array
    {
        if ($schedulable <= 1) {
            return $targets;
        }

        return array_values(array_intersect($targets, $runrNodes));
    }

    /**
     * Nodes running pods of RunR's own namespace, when the region is the cluster RunR runs in.
     */
    private function runrNodes(KnativeRegion $region, KubernetesClient $k8s): array
    {
        $identity = app(RunrClusterIdentity::class);
        $ns = $identity->namespace();
        if (! $ns) {
            return [];
        }

        try {
            $pods = $k8s->listResources('v1', 'Pod', $ns)['items'] ?? [];
        } catch (\Throwable) {
            return [];
        }

        // Same cluster: kube-system UID matches, or this very pod is listed in the region cluster
        $podNames = array_map(fn ($p) => $p['metadata']['name'] ?? '', $pods);
        if (! in_array(gethostname(), $podNames, true) && ! $identity->isRunrCluster($region)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(fn ($p) => $p['spec']['nodeName'] ?? null, $pods))));
    }

    /**
     * Ready nodes that accept regular pods (not cordoned, no NoSchedule/NoExecute taint).
     */
    private function schedulableNodeCount(array $nodes): int
    {
        return count(array_filter($nodes, function ($node) {
            if (! empty($node['spec']['unschedulable'])) {
                return false;
            }
            foreach ($node['spec']['taints'] ?? [] as $taint) {
                if (in_array($taint['effect'] ?? '', ['NoSchedule', 'NoExecute'], true)) {
                    return false;
                }
            }
            foreach ($node['status']['conditions'] ?? [] as $c) {
                if ($c['type'] === 'Ready') {
                    return $c['status'] === 'True';
                }
            }

            return false;
        }));
    }

    /**
     * `systemctl restart containerd` on the node through a short-lived privileged pod
     * (nodeName bypasses the scheduler, so a cordoned node still runs it).
     */
    private function restartContainerdOnNode(KubernetesClient $k8s, string $ns, string $nodeName): bool
    {
        $name = 'runr-containerd-restart-'.substr(md5($nodeName), 0, 10);

        try {
            $k8s->deleteResource('v1', 'Pod', $name, $ns);
        } catch (\Throwable) {
        }

        try {
            $k8s->applyResource($this->restartPodManifest($name, $ns, $nodeName), $ns);
        } catch (\Throwable $e) {
            $this->warn("  Restart pod failed: {$e->getMessage()}");

            return false;
        }

        $phase = 'Pending';
        $start = time();
        while (time() - $start < 120) {
            sleep(5);
            try {
                $phase = $k8s->getResourceStatus('v1', 'Pod', $name, $ns)['status']['phase'] ?? 'Pending';
            } catch (\Throwable) {
                continue; // API briefly unavailable while containerd restarts
            }
            if (in_array($phase, ['Succeeded', 'Failed'], true)) {
                break;
            }
        }

        $logs = '';
        try {
            $logs = trim((string) $k8s->getPodLogs($name, $ns));
        } catch (\Throwable) {
        }
        try {
            $k8s->deleteResource('v1', 'Pod', $name, $ns);
        } catch (\Throwable) {
        }

        if ($phase !== 'Succeeded') {
            $this->warn("  Restart pod ended in phase {$phase}".($logs !== '' ? ": {$logs}" : ''));

            return false;
        }

        return true;
    }

    private function restartPodManifest(string $name, string $ns, string $nodeName): array
    {
        return [
            'apiVersion' => 'v1',
            'kind' => 'Pod',
            'metadata' => [
                'name' => $name,
                'namespace' => $ns,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'app' => 'runr-containerd-restart'],
            ],
            'spec' => [
                'nodeName' => $nodeName,
                'hostPID' => true,
                'restartPolicy' => 'Never',
                'tolerations' => [['operator' => 'Exists']],
                'containers' => [[
                    'name' => 'restart',
                    'image' => 'ghcr.io/brf-tech/runr/reqs/busybox:latest',
                    'command' => ['nsenter', '--target', '1', '--mount', '--uts', '--ipc', '--pid', '--',
                        'systemctl', 'restart', 'containerd'],
                    'securityContext' => ['privileged' => true],
                    'resources' => [
                        'requests' => ['cpu' => '10m', 'memory' => '16Mi'],
                        'limits' => ['cpu' => '100m', 'memory' => '64Mi'],
                    ],
                ]],
            ],
        ];
    }

    private function waitForNodeReady(KubernetesClient $k8s, string $name, int $timeout): bool
    {
        $start = time();
        while (time() - $start < $timeout) {
            sleep(15);
            try {
                $node = $k8s->getClusterResource('v1', 'Node', $name);
                foreach ($node['status']['conditions'] ?? [] as $c) {
                    if ($c['type'] === 'Ready' && $c['status'] === 'True') {
                        return true;
                    }
                }
                $this->line('    Still waiting...');
            } catch (\Throwable) {
                $this->line('    API unreachable, retrying...');
            }
        }

        return false;
    }
}
