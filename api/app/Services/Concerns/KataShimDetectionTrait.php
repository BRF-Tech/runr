<?php

namespace App\Services\Concerns;

use App\Services\Helpers\KataRuntime;
use App\Services\KubernetesClient;
use Throwable;

trait KataShimDetectionTrait
{
    /**
     * Shim definitions: key → [label, runtime, arches, category, requires].
     */
    private function shimDefinitions(): array
    {
        return [
            // General
            'clh' => ['label' => 'Cloud Hypervisor', 'runtime' => 'go',   'arches' => ['amd64', 'arm64'], 'category' => 'general',      'requires' => ['kvm'], 'recommended' => true],
            'cloud-hypervisor' => ['label' => 'Cloud Hypervisor (Rust)', 'runtime' => 'rs',   'arches' => ['amd64', 'arm64'], 'category' => 'general',      'requires' => ['kvm']],
            // QEMU keeps each guest's memory in /dev/shm (KataRuntime::QEMU_MIN_SHM_MB)
            'qemu' => ['label' => 'QEMU', 'runtime' => 'go',   'arches' => ['amd64', 'arm64', 's390x', 'ppc64le'], 'category' => 'general', 'requires' => ['kvm', 'shm']],
            'qemu-runtime-rs' => ['label' => 'QEMU (Rust)', 'runtime' => 'rs',   'arches' => ['amd64', 'arm64', 's390x'], 'category' => 'general',      'requires' => ['kvm']],
            // runtime-rs reaches systemd over the D-Bus system bus (KataRuntime::NEEDS_SYSTEM_DBUS)
            'dragonball' => ['label' => 'Dragonball', 'runtime' => 'rs',   'arches' => ['amd64', 'arm64'], 'category' => 'general',      'requires' => ['kvm', 'dbus']],
            'fc' => ['label' => 'Firecracker', 'runtime' => 'go',   'arches' => ['amd64', 'arm64'], 'category' => 'general',      'requires' => ['kvm', 'devmapper']],
            // GPU
            'qemu-nvidia-gpu' => ['label' => 'QEMU NVIDIA GPU', 'runtime' => 'go',   'arches' => ['amd64', 'arm64'], 'category' => 'gpu',          'requires' => ['kvm', 'gpu', 'iommu']],
            // Confidential Computing
            'qemu-snp' => ['label' => 'QEMU AMD SEV-SNP', 'runtime' => 'go',   'arches' => ['amd64'],          'category' => 'confidential', 'requires' => ['kvm', 'snp']],
            'qemu-tdx' => ['label' => 'QEMU Intel TDX', 'runtime' => 'go',   'arches' => ['amd64'],          'category' => 'confidential', 'requires' => ['kvm', 'tdx']],
        ];
    }

    /**
     * Detect which of the Kata shims RunR installs (KataRuntime::HYPERVISORS) are compatible
     * with the cluster.
     *
     * Returns: ['clh' => ['available' => true, ...], 'fc' => ['available' => false, 'reason' => '...'], ...]
     */
    public function detectCompatibleShims(KubernetesClient $k8s): array
    {
        $nodeCapabilities = $this->analyzeNodes($k8s);
        $shims = array_intersect_key($this->shimDefinitions(), array_flip(KataRuntime::HYPERVISORS));
        $result = [];

        foreach (KataRuntime::HYPERVISORS as $name) {
            $result[$name] = $this->evaluateShim($name, $shims[$name], $nodeCapabilities);
        }

        return $result;
    }

    /**
     * Why requested hypervisors cannot be installed on this cluster — the same check the
     * console's shim selector shows (detectCompatibleShims()).
     *
     * @param  list<string>  $hypervisors
     * @return array<string, string> hypervisor → human-readable reason; empty = all installable
     */
    public function unavailableHypervisors(KubernetesClient $k8s, array $hypervisors): array
    {
        $shims = $this->detectCompatibleShims($k8s);
        $problems = [];

        foreach ($hypervisors as $hv) {
            $shim = $shims[$hv] ?? null;
            if ($shim === null) {
                $problems[$hv] = 'not a hypervisor RunR installs ('.implode(', ', KataRuntime::HYPERVISORS).')';
            } elseif (! $shim['available']) {
                $problems[$hv] = self::shimReasonText((string) $shim['reason']).(! empty($shim['detail']) ? " ({$shim['detail']})" : '');
            }
        }

        return $problems;
    }

    public static function shimReasonText(string $reason): string
    {
        return match ($reason) {
            'arch_mismatch' => 'the node architecture is not supported',
            'no_kvm' => 'hardware virtualization (/dev/kvm) is not available on the nodes',
            'no_devmapper' => "it needs containerd's devmapper snapshotter, which RunR sets up only on containerd nodes (plain, k3s, rke2)",
            'small_shm' => 'the nodes\' /dev/shm is smaller than '.KataRuntime::QEMU_MIN_SHM_MB.' MiB: QEMU keeps each guest\'s whole memory there '
                .'(shared with virtiofsd) and dies of SIGBUS when it is full — nodes that run in a container (Kind, k3d) get 64 MiB',
            'no_dbus' => 'the nodes run systemd without the D-Bus system bus: Kata\'s Rust runtime puts each sandbox into its systemd cgroup '
                .'over D-Bus and fails without it — Kind\'s node image has none',
            'no_gpu' => 'no NVIDIA GPU was detected',
            'no_iommu' => 'IOMMU is not available (bare metal required)',
            'no_snp' => 'AMD SEV-SNP was not detected',
            'no_tdx' => 'Intel TDX was not detected',
            default => $reason,
        };
    }

    /**
     * Analyze all worker nodes and aggregate capabilities.
     */
    private function analyzeNodes(KubernetesClient $k8s): array
    {
        $capabilities = [
            'arches' => [],
            'kvm' => 'unknown', // true, false, unknown
            // containerd's devmapper snapshotter runs on a node (label set by RunR's setup)
            'devmapper' => false,
            // RunR can set it up: every node runs containerd (plain, k3s, rke2)
            'devmapper_setup' => false,
            'gpu' => false,
            'iommu' => 'unknown',
            'snp' => false,
            'tdx' => false,
            'nfd' => false,
            'containerd_version' => null,
            // /dev/shm of the Kata target nodes for QEMU guests: ok | small | unknown (qemuShmSummary())
            'shm' => 'unknown',
            'shm_nodes' => [],
            // D-Bus system bus on the Kata target nodes that run systemd: ok | missing | unknown (systemDbusSummary())
            'dbus' => 'unknown',
            'dbus_nodes' => [],
        ];

        try {
            // Nodes are cluster-scoped (listResources() with a null namespace threw a TypeError,
            // so no node was ever analysed)
            $nodes = $k8s->listClusterResources('v1', 'Node');
        } catch (Throwable) {
            return $capabilities;
        }

        if (! $nodes || empty($nodes['items'])) {
            return $capabilities;
        }

        $hasNfd = false;
        $allContainerd = true;
        $linuxNodes = false;

        foreach ($nodes['items'] as $node) {
            $labels = $node['metadata']['labels'] ?? [];
            $nodeInfo = $node['status']['nodeInfo'] ?? [];
            $capacity = $node['status']['capacity'] ?? [];

            if (($labels['kubernetes.io/os'] ?? 'linux') !== 'linux') {
                continue;
            }
            $linuxNodes = true;

            if (($labels[KataRuntime::DEVMAPPER_LABEL] ?? null) === 'true') {
                $capabilities['devmapper'] = true;
            }
            if (! str_starts_with((string) ($nodeInfo['containerRuntimeVersion'] ?? ''), 'containerd://')) {
                $allContainerd = false;
            }

            // Architecture
            $arch = $nodeInfo['architecture'] ?? null;
            if ($arch) {
                $capabilities['arches'][$arch] = true;
            }

            // containerd version (take first non-null)
            if (! $capabilities['containerd_version']) {
                $runtime = $nodeInfo['containerRuntimeVersion'] ?? '';
                if (preg_match('/containerd:\/\/(\d+\.\d+)/', $runtime, $m)) {
                    $capabilities['containerd_version'] = $m[1];
                }
            }

            // Check any NFD label presence
            foreach ($labels as $key => $val) {
                if (str_starts_with($key, 'feature.node.kubernetes.io/')) {
                    $hasNfd = true;
                    break;
                }
            }

            // GPU detection
            $gpuCount = (int) ($capacity['nvidia.com/gpu'] ?? 0);
            if ($gpuCount > 0) {
                $capabilities['gpu'] = true;
            }

            // IOMMU
            if (isset($labels['feature.node.kubernetes.io/iommu-enabled'])) {
                $capabilities['iommu'] = true;
            }

            // AMD SEV-SNP
            if (isset($labels['amd.feature.node.kubernetes.io/snp'])) {
                $capabilities['snp'] = true;
            }

            // Intel TDX
            if (isset($labels['intel.feature.node.kubernetes.io/tdx'])) {
                $capabilities['tdx'] = true;
            }
        }

        $capabilities['nfd'] = $hasNfd;
        $capabilities['arches'] = array_keys($capabilities['arches']);
        $capabilities['devmapper_setup'] = $linuxNodes && $allContainerd;

        // KVM on the Kata target nodes: RunR's /dev/kvm check (node label), NFD's CPU labels as a
        // fast path (KataKvmProbeTrait). None of them can run VMs → no Kata hypervisor is offered.
        $kvm = self::kvmSummary($nodes['items']);
        $capabilities['kvm'] = match ($kvm['state']) {
            'no' => false,
            'yes', 'mixed' => true,
            default => 'unknown',
        };
        $capabilities['kvm_summary'] = $kvm;

        $shm = self::qemuShmSummary($nodes['items']);
        $capabilities['shm'] = $shm['state'];
        $capabilities['shm_nodes'] = $shm['nodes'];

        $dbus = self::systemDbusSummary($nodes['items']);
        $capabilities['dbus'] = $dbus['state'];
        $capabilities['dbus_nodes'] = $dbus['nodes'];

        return $capabilities;
    }

    /**
     * Evaluate a single shim against node capabilities.
     */
    private function evaluateShim(string $name, array $def, array $caps): array
    {
        $entry = [
            'label' => $def['label'],
            'runtime' => $def['runtime'],
            'category' => $def['category'],
            'arches' => $def['arches'],
            'available' => true,
            'reason' => null,
            'recommended' => $def['recommended'] ?? false,
        ];

        // Architecture check
        $matchedArches = array_intersect($def['arches'], $caps['arches']);
        if (! empty($caps['arches']) && empty($matchedArches)) {
            $entry['available'] = false;
            $entry['reason'] = 'arch_mismatch';

            return $entry;
        }

        // Requirement checks
        foreach ($def['requires'] as $req) {
            $check = match ($req) {
                'kvm' => $this->checkKvm($caps),
                'shm' => $this->checkShm($caps),
                'dbus' => ($caps['dbus'] ?? 'unknown') === 'missing' ? 'no_dbus' : null,
                'devmapper' => $this->checkDevmapper($caps),
                'gpu' => $this->checkGpu($caps),
                'iommu' => $this->checkIommu($caps),
                'snp' => $this->checkSnp($caps),
                'tdx' => $this->checkTdx($caps),
                default => null,
            };

            if ($check !== null) {
                $entry['available'] = false;
                $entry['reason'] = $check;
                if ($check === 'small_shm') {
                    $entry['detail'] = implode(', ', array_map(fn ($node, $mb) => "{$node}: {$mb} MiB", array_keys($caps['shm_nodes']), $caps['shm_nodes']));
                } elseif ($check === 'no_dbus') {
                    $entry['detail'] = 'no D-Bus on '.implode(', ', $caps['dbus_nodes']);
                }

                return $entry;
            }
        }

        // Installable, but RunR first sets up containerd's devmapper snapshotter on the nodes
        if (in_array('devmapper', $def['requires'], true) && ! $caps['devmapper']) {
            $entry['setup'] = 'devmapper';
        }

        return $entry;
    }

    private function checkKvm(array $caps): ?string
    {
        if ($caps['kvm'] === false) {
            return 'no_kvm';
        }

        return null; // true or unknown → allow
    }

    /** Every Kata target node with a known /dev/shm is too small for QEMU guests; unknown → allow. */
    private function checkShm(array $caps): ?string
    {
        return ($caps['shm'] ?? 'unknown') === 'small' ? 'small_shm' : null;
    }

    /**
     * Ready where RunR set the snapshotter up (node label KataRuntime::DEVMAPPER_LABEL, also
     * usable for a snapshotter configured by hand); otherwise RunR sets it up on containerd
     * nodes as part of the Kata install. CRI-O / unknown runtimes: blocked.
     */
    private function checkDevmapper(array $caps): ?string
    {
        if ($caps['devmapper'] || $caps['devmapper_setup']) {
            return null;
        }

        return 'no_devmapper';
    }

    private function checkGpu(array $caps): ?string
    {
        return $caps['gpu'] ? null : 'no_gpu';
    }

    private function checkIommu(array $caps): ?string
    {
        if ($caps['iommu'] === false) {
            return 'no_iommu';
        }
        if ($caps['iommu'] === 'unknown' && $caps['nfd']) {
            return 'no_iommu';
        }
        if ($caps['iommu'] === 'unknown') {
            return 'no_iommu'; // bare metal requirement — safer to block
        }

        return null;
    }

    private function checkSnp(array $caps): ?string
    {
        return $caps['snp'] ? null : 'no_snp';
    }

    private function checkTdx(array $caps): ?string
    {
        return $caps['tdx'] ? null : 'no_tdx';
    }
}
