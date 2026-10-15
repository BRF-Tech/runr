<?php

namespace App\Services\Helpers;

/**
 * Container runtime of a node, derived from its status.nodeInfo:
 * containerRuntimeVersion ("containerd://2.2.7-k3s1", "cri-o://1.31.0") and
 * kubeletVersion ("v1.34.11+k3s1", "v1.31.4+rke2r1").
 *
 * k3s and rke2 embed their own containerd: socket under /run/k3s/containerd,
 * config generated from templates under /var/lib/rancher/<flavor>/agent/etc/containerd,
 * restart via the k3s / rke2 systemd unit. Pure functions — no cluster access.
 */
class ContainerRuntimeHelper
{
    public const FLAVOR_CONTAINERD = 'containerd';

    public const FLAVOR_K3S = 'k3s';

    public const FLAVOR_RKE2 = 'rke2';

    public const FLAVOR_CRIO = 'cri-o';

    public const FLAVOR_UNKNOWN = 'unknown';

    public const CONTAINERD_SOCKET = '/run/containerd/containerd.sock';

    public const K3S_SOCKET = '/run/k3s/containerd/containerd.sock';

    /**
     * @return array{node: string, flavor: string, runtime_version: string, containerd_major: ?int,
     *               socket: string, config_dir: string, certs_dir: string, restart_units: string[]}
     */
    public static function fromNode(array $node): array
    {
        $info = $node['status']['nodeInfo'] ?? [];
        $runtime = (string) ($info['containerRuntimeVersion'] ?? '');
        $kubelet = (string) ($info['kubeletVersion'] ?? '');
        $flavor = self::detectFlavor($runtime, $kubelet);

        return [
            'node' => (string) ($node['metadata']['name'] ?? ''),
            'flavor' => $flavor,
            'runtime_version' => $runtime,
            'containerd_major' => self::containerdMajor($runtime),
            'socket' => self::socketPath($flavor),
            'config_dir' => self::configDir($flavor),
            'certs_dir' => self::configDir($flavor).'/certs.d',
            'restart_units' => self::restartUnits($flavor),
        ];
    }

    public static function detectFlavor(string $runtimeVersion, string $kubeletVersion = ''): string
    {
        $runtime = strtolower($runtimeVersion);
        $kubelet = strtolower($kubeletVersion);

        if (str_starts_with($runtime, 'cri-o://')) {
            return self::FLAVOR_CRIO;
        }
        if (! str_starts_with($runtime, 'containerd://')) {
            return self::FLAVOR_UNKNOWN;
        }
        // rke2's containerd also carries the "-k3s" suffix — the kubelet version tells them apart
        if (str_contains($kubelet, '+rke2')) {
            return self::FLAVOR_RKE2;
        }
        if (str_contains($kubelet, '+k3s') || str_contains($runtime, '-k3s')) {
            return self::FLAVOR_K3S;
        }

        return self::FLAVOR_CONTAINERD;
    }

    public static function containerdMajor(string $runtimeVersion): ?int
    {
        if (preg_match('#^containerd://v?(\d+)\.#i', $runtimeVersion, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public static function isK3sFamily(string $flavor): bool
    {
        return in_array($flavor, [self::FLAVOR_K3S, self::FLAVOR_RKE2], true);
    }

    public static function isContainerd(string $flavor): bool
    {
        return $flavor === self::FLAVOR_CONTAINERD || self::isK3sFamily($flavor);
    }

    public static function socketPath(string $flavor): string
    {
        return self::isK3sFamily($flavor) ? self::K3S_SOCKET : self::CONTAINERD_SOCKET;
    }

    /**
     * Host directory holding containerd's config.toml (and certs.d).
     */
    public static function configDir(string $flavor): string
    {
        return match ($flavor) {
            self::FLAVOR_K3S => '/var/lib/rancher/k3s/agent/etc/containerd',
            self::FLAVOR_RKE2 => '/var/lib/rancher/rke2/agent/etc/containerd',
            default => '/etc/containerd',
        };
    }

    /**
     * systemd units that (re)start containerd, in lookup order. k3s/rke2 run containerd as a
     * child of the k3s/rke2 process — restarting it restarts kubelet (and on servers the API),
     * running pods survive (KillMode=process, shims stay up).
     */
    public static function restartUnits(string $flavor): array
    {
        return match ($flavor) {
            self::FLAVOR_K3S => ['k3s', 'k3s-agent'],
            self::FLAVOR_RKE2 => ['rke2-server', 'rke2-agent'],
            self::FLAVOR_CONTAINERD => ['containerd'],
            default => [],
        };
    }

    /**
     * Cluster-wide runtime: the flavor most nodes run (ties → first node's flavor).
     * `mixed` is true when the nodes disagree; `nodes` maps node name → flavor.
     *
     * @param  array  $nodes  Node objects (items of a v1 NodeList)
     * @return array{node: string, flavor: string, runtime_version: string, containerd_major: ?int,
     *               socket: string, config_dir: string, certs_dir: string, restart_units: string[],
     *               mixed: bool, nodes: array<string, string>}
     */
    public static function resolveCluster(array $nodes): array
    {
        $infos = array_map([self::class, 'fromNode'], array_values($nodes));

        if (empty($infos)) {
            return self::fromNode([]) + ['mixed' => false, 'nodes' => []];
        }

        $counts = [];
        foreach ($infos as $info) {
            $counts[$info['flavor']] = ($counts[$info['flavor']] ?? 0) + 1;
        }

        $winner = $infos[0]['flavor'];
        foreach ($counts as $flavor => $count) {
            if ($count > $counts[$winner]) {
                $winner = $flavor;
            }
        }

        $representative = collect($infos)->first(fn ($i) => $i['flavor'] === $winner);

        // Highest containerd major among the winning flavor (config v3 needs containerd ≥ 2)
        $majors = array_filter(array_map(
            fn ($i) => $i['flavor'] === $winner ? $i['containerd_major'] : null,
            $infos,
        ));
        if ($majors) {
            $representative['containerd_major'] = max($majors);
        }

        return $representative + [
            'mixed' => count($counts) > 1,
            'nodes' => array_column($infos, 'flavor', 'node'),
        ];
    }
}
