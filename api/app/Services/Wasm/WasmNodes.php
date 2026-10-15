<?php

namespace App\Services\Wasm;

use App\Services\Helpers\ContainerRuntimeHelper;

/**
 * Which nodes of a region can get WebAssembly support, and what they have. Pure functions over
 * the items of a v1 NodeList.
 *
 * A node is eligible when it runs Linux on amd64 / arm64 with containerd (plain, k3s or RKE2),
 * has no NoSchedule / NoExecute taint other than runr.dev/dedicated, and — when the cluster has
 * runr.dev/dedicated=projects workers — is one of them (the nodes projects run on).
 */
final class WasmNodes
{
    public const REASON_NOT_LINUX = 'not_linux';

    public const REASON_NOT_DEDICATED = 'not_dedicated';

    public const REASON_TAINTED = 'tainted';

    public const REASON_RUNTIME = 'runtime';

    public const REASON_ARCH = 'arch';

    /**
     * @param  list<array>  $nodes
     * @return list<array{name: string, arch: string, flavor: string, runtime_version: string, eligible: bool,
     *                    reason: ?string, target: bool, shims: list<string>}>
     */
    public static function describe(array $nodes): array
    {
        $dedicated = array_filter($nodes, fn ($n) => ($n['metadata']['labels']['runr.dev/dedicated'] ?? null) === 'projects');
        $out = [];

        foreach ($nodes as $node) {
            $labels = $node['metadata']['labels'] ?? [];
            $runtime = ContainerRuntimeHelper::fromNode($node);
            $arch = (string) ($labels['kubernetes.io/arch'] ?? ($node['status']['nodeInfo']['architecture'] ?? 'amd64'));

            $reason = match (true) {
                ($labels['kubernetes.io/os'] ?? 'linux') !== 'linux' => self::REASON_NOT_LINUX,
                $dedicated !== [] && ($labels['runr.dev/dedicated'] ?? null) !== 'projects' => self::REASON_NOT_DEDICATED,
                self::blockingTaint($node) => self::REASON_TAINTED,
                ! ContainerRuntimeHelper::isContainerd($runtime['flavor']) => self::REASON_RUNTIME,
                ! in_array($arch, ['amd64', 'arm64'], true) => self::REASON_ARCH,
                default => null,
            };

            $shims = [];
            foreach (WasmShim::all() as $shim) {
                if (($labels[$shim->nodeLabel()] ?? null) === 'true') {
                    $shims[] = $shim->id;
                }
            }

            $out[] = [
                'name' => (string) ($node['metadata']['name'] ?? ''),
                'arch' => $arch,
                'flavor' => $runtime['flavor'],
                'runtime_version' => $runtime['runtime_version'],
                'eligible' => $reason === null,
                'reason' => $reason,
                'target' => ($labels[WasmShim::TARGET_LABEL] ?? null) === 'true',
                'shims' => $shims,
            ];
        }

        return $out;
    }

    /** @param list<array> $nodes @return list<string> */
    public static function eligibleNames(array $nodes): array
    {
        return array_values(array_map(fn ($n) => $n['name'], array_filter(self::describe($nodes), fn ($n) => $n['eligible'])));
    }

    /** @param list<array> $nodes @return list<string> nodes labelled as installer targets */
    public static function targetNames(array $nodes): array
    {
        return array_values(array_map(fn ($n) => $n['name'], array_filter(self::describe($nodes), fn ($n) => $n['target'])));
    }

    /**
     * Nodes RunR touched for WebAssembly (target, any shim label, cleanup label) — what an
     * uninstall cleans.
     *
     * @param  list<array>  $nodes
     * @return list<string>
     */
    public static function preparedNames(array $nodes): array
    {
        $names = [];
        foreach ($nodes as $node) {
            $labels = $node['metadata']['labels'] ?? [];
            foreach ($labels as $key => $value) {
                if ($key === WasmShim::NODE_LABEL || $key === WasmShim::TARGET_LABEL || $key === WasmShim::CLEANUP_LABEL
                    || str_starts_with($key, WasmShim::NODE_LABEL.'-')) {
                    $names[] = (string) ($node['metadata']['name'] ?? '');
                    break;
                }
            }
        }

        return array_values(array_filter(array_unique($names)));
    }

    /** @param list<array> $nodes @param list<string> $names */
    public static function pick(array $nodes, array $names): array
    {
        return array_values(array_filter($nodes, fn ($n) => in_array($n['metadata']['name'] ?? '', $names, true)));
    }

    private static function blockingTaint(array $node): bool
    {
        if (! empty($node['spec']['unschedulable'])) {
            return true;
        }
        foreach ($node['spec']['taints'] ?? [] as $taint) {
            if (in_array($taint['effect'] ?? '', ['NoSchedule', 'NoExecute'], true) && ($taint['key'] ?? '') !== 'runr.dev/dedicated') {
                return true;
            }
        }

        return false;
    }
}
