<?php

namespace App\Services\Helpers;

/**
 * The Kata Containers hypervisors RunR installs and what one Kata pod costs on its node.
 *
 * Each hypervisor is a kata-deploy custom runtime (handler "kata-<hv>-runr", kata-deploy 3.28
 * CUSTOM_RUNTIMES_ENABLED + /custom-configs) built from kata-deploy's stock configuration plus
 * RunR's config.d drop-in (dropIn()). The RuntimeClass keeps the name "kata-<hv>" that project
 * mappings (sandbox_*_runtime) refer to.
 *
 * Sizing model (measured on a single-node k3s test region, Kata 3.28.0, k3s 1.34, systemd cgroup driver):
 *  - sandbox_cgroup_only = true: the shim, the VMM and virtiofsd run inside the pod cgroup.
 *    With the go runtime's default (false) and the systemd cgroup driver the VMM ended up in an
 *    unlimited root-level cgroup ("kubepods-pod<uid>.slice:cri-containerd:<id>") and the shim /
 *    virtiofsd in /kata_overhead — outside the pod's limits and kubelet's accounting.
 *  - The pod cgroup is therefore sized as the containers' limits + the RuntimeClass
 *    overhead.podFixed (OVERHEAD), which kubelet, the scheduler, the namespace ResourceQuota and
 *    RunR's own capacity math (ResourceReservation) all add per pod.
 *  - static_sandbox_resource_mgmt = true: the VM boots with GUEST_BASE_VCPUS + the pod's CPU
 *    limits and GUEST_BASE_MEMORY_MB + the pod's memory limits (containerd's sandbox sizing
 *    annotations) instead of Kata's 1 vCPU / 2048 MiB default plus hot-plug. The guest never
 *    believes it has more memory than the pod may use: limits + base, with the base and the
 *    VMM's own memory inside the overhead. CRI-O does not pass the sizing annotations, so on
 *    CRI-O nodes Kata keeps hot-plugging (static sizing off).
 *  - Dragonball (runtime-rs) in kata-deploy 3.28 adds only the memory limits at boot, not the CPU
 *    limits (InitialSizeManager::setup_config logs the vCPUs and drops them; hot-plug is off with
 *    static sizing), so its guest kept GUEST_BASE_VCPUS whatever the pod's limit. RunR passes the
 *    vCPU count itself: withGuestSizing() annotates every workload manifest the API writes
 *    (GUEST_VCPUS_ANNOTATION / GUEST_MAX_VCPUS_ANNOTATION = guestVcpus()), and Dragonball's drop-in
 *    lets runtime-rs read those annotations on containerd nodes. Kata 3.29.0 sizes the vCPUs
 *    itself (upstream "runtime-rs: Fix initial vCPU / memory with static_sandbox_resource_mgmt"):
 *    on such a KATA_VERSION the annotations would be counted twice, so withGuestSizing() stops at
 *    RUNTIME_RS_STATIC_VCPUS_SINCE. docs/kata-hypervisors.md → Guest size; the sandbox-api e2e
 *    checks nproc in the guest per hypervisor.
 *  - reclaim_guest_freed_memory = true (clh, qemu, dragonball): free page reporting hands pages
 *    the guest frees back to the host, so the VMM's resident memory follows what the guest uses
 *    instead of growing towards the whole guest RAM over time.
 *
 * OVERHEAD derivation — host memory per idle Kata pod (PSS of shim + VMM + virtiofsd, guest at
 * the Kata default of 2048 MiB + limits): clh 227–264 MiB, qemu 308–329 MiB, dragonball
 * 203–223 MiB, of which 36–60 MiB was the application's own memory inside the guest (covered by
 * the container limit). The remaining ~190–210 / ~270 / ~165 MiB is shim + VMM + virtiofsd + the
 * guest kernel and agent; the 256 MiB guest base drops about 28 MiB of guest page tables from
 * that. 256Mi (clh, dragonball) and 320Mi (qemu) leave 50–110 MiB of headroom. Re-measured for
 * 0.4.2 (test VM, guest 256 MiB base + limits, idle 30 s after a request, n = 2 each; overhead = PSS −
 * the function container's memory in the guest, ~56 MiB): 0.4.1's systemd guest clh 164–177,
 * qemu 278–281, dragonball 140–141 MiB; with the agent as init and the queue-proxy burst
 * (3 vCPUs for clh / qemu) clh 130–131, qemu 221–222, dragonball 109 MiB — the overheads stay as
 * they are and now leave ~125 / ~100 / ~145 MiB of headroom. fc (estimate
 * until measured): no virtiofsd and a VMM of a few MiB, but Kata has no free page reporting for
 * Firecracker, so the whole 256 MiB guest base can become resident over time — 256 MiB + shim
 * (~24 MiB) + Firecracker (~10 MiB) → 320Mi. CPU: 250m per pod, the value upstream kata-deploy
 * uses for these RuntimeClasses — the VMM's own threads (I/O, virtio-fs, vCPU exits) at idle stay
 * far below it; VM boot is bounded by limits + 250m.
 *
 * Cold start (0.4.2; measured on a test VM — nested KVM in an 8-vCPU Broadwell OpenStack VM, k3s 1.34,
 * Kata 3.28.0, Knative scale from zero, Node.js function 250m / 256Mi, medians of n >= 6, "pod
 * side" = request to response minus Knative's request-to-pod-object time). A Kata cold start is
 * CPU-bound: guest kernel, init, agent and the first containers touch fresh guest memory, and on
 * nested KVM every first touch is an EPT exit. With 0.4.1's settings CLH needed 3.1 CPU-s inside a
 * 0.6 CPU pod cap and was throttled in ~40 % of its periods: 6.6 s (VM boot 3.7 s). Two changes:
 *
 *  - Guest boot without systemd (AGENT_INIT_KERNEL_PARAMS, every hypervisor): the container's init starts
 *    1.3 s after guest boot instead of 3.4 s; 1.9 instead of 3.1 CPU-s per cold start, VM boot
 *    1.8 s, pod side 4.0 s at the unchanged 0.6 CPU cap; guest memory −45 MiB. (Kata's own
 *    initrd, also agent-as-init, spends 0.8 s gunzipping itself on nested KVM: 5.2 s.)
 *  - CPU at boot (KnativeConstants::QUEUE_PROXY_CPU_LIMIT_M, on every project pod since 0.4.2):
 *    the VM boots inside the pod's CPU limit (sandbox cgroup), which Guaranteed QoS pinned to the
 *    requests. queue-proxy gets a CPU limit of 1 CPU over its 100m request: a Kata pod may use
 *    limits + overhead (1.5 CPU for a 250m function) while it boots, and queue-proxy — it starts
 *    inside the guest under its own limit — answers Knative's probes ~0.35 s sooner. Pod side by
 *    pod CPU cap (agent as init): 0.6 → 4.0 s, 0.85 → 3.2, 1.0 → 2.8, 1.5 → 2.6, 2.5 → 2.6 s;
 *    1 CPU is where it levels off. The function container keeps request == limit, so its CPU
 *    inside the guest is what it was. Trade (Burstable QoS; nothing more reserved) and the same
 *    burst for runc / gVisor pods: see KnativeConstants. The Guaranteed way to the same cap for
 *    Kata, a 1-CPU overhead, reserves 1.35 instead of 0.6 CPU per micro Kata pod and boots no
 *    faster (2.9 s pod side: queue-proxy stays at 100m in the guest). CronJob pods have no
 *    queue-proxy: they boot within their own limit + overhead.
 *  - Result, in exactly the form this release renders (drop-ins written by the kata-dropins
 *    DaemonSet, these RuntimeClasses, queue-proxy annotation; end to end, median, n >= 6):
 *    clh Node 6.7 → 2.7 s, Python 6.1 → 3.1 s; qemu Node 7.1 → 3.5 s, Python 3.7 s;
 *    dragonball Node 7.1 → 3.6 s, Python 3.7 s. Warm requests ~10 ms throughout.
 *  - No measurable effect on top (pod side 2.51–2.58 s vs 2.58 s, n = 6 each): 2 instead of 3
 *    vCPUs, virtio_fs_cache = always, reclaim_guest_freed_memory off, guest mitigations=off,
 *    boot-time kernel parameters (tsc=reliable, rcu_expedited, i8042 / 8250 off, …). The rest of the
 *    ~2.6 s is outside the VM's CPU budget: Knative/kubelet before the sandbox (~0.33 s, also runc),
 *    VM boot ~1.0 s, two container creates ~0.3 s, and the function's own start inside the guest
 *    (~0.9 s for Node at 250m — first-touch memory faults on nested KVM; 0.14 s under runc).
 *
 * Firecracker boots block devices only: its images live in containerd's devmapper snapshotter,
 * which RunR sets up on the nodes when fc is installed (KataDevmapperTrait) — a thin-pool on
 * sparse files under DEVMAPPER_DIR, activated at boot by a systemd unit.
 */
final class KataRuntime
{
    /** Hypervisors the install API accepts (InstallSandboxRequest), in console order. */
    public const HYPERVISORS = ['clh', 'qemu', 'dragonball', 'fc'];

    /** kata-deploy release RunR installs (KataManifestTrait::KATA_IMAGE). */
    public const KATA_VERSION = '3.28.0';

    /** Hypervisors on Kata's Rust runtime (runtime-rs); the others run on the go runtime. */
    public const RUNTIME_RS = ['dragonball'];

    /** First kata-deploy release whose runtime-rs adds the pod's CPU limits to a statically sized guest. */
    public const RUNTIME_RS_STATIC_VCPUS_SINCE = '3.29.0';

    /** Pod annotations runtime-rs takes the guest's vCPUs from (Dragonball's drop-in enables them). */
    public const GUEST_VCPUS_ANNOTATION = 'io.katacontainers.config.hypervisor.default_vcpus';

    public const GUEST_MAX_VCPUS_ANNOTATION = 'io.katacontainers.config.hypervisor.default_max_vcpus';

    /**
     * enable_annotations of kata-deploy 3.28's runtime-rs configurations (DEFENABLEANNOTATIONS) —
     * a drop-in's list replaces the stock one, so RunR's starts from it.
     */
    private const RUNTIME_RS_STOCK_ANNOTATIONS = ['enable_iommu', 'virtio_fs_extra_args', 'kernel_params', 'kernel_verity_params', 'default_vcpus', 'default_memory'];

    /** Knative's queue-proxy CPU limit when a revision does not set one (config-deployment, KnativeCRTrait). */
    private const QUEUE_PROXY_DEFAULT_CPU_LIMIT_M = 100;

    /** Annotation that sets a revision's queue-proxy CPU limit (KnativeConstants::QUEUE_PROXY_CPU_LIMIT_ANNOTATION). */
    private const QUEUE_PROXY_CPU_LIMIT_ANNOTATION = 'queue.sidecar.serving.knative.dev/cpu-resource-limit';

    /** Node label kata-deploy sets once the node's container runtime knows the Kata handlers. */
    public const NODE_LABEL = 'katacontainers.io/kata-runtime';

    /**
     * RuntimeClass overhead.podFixed per hypervisor (see the class doc for the derivation).
     *
     * @var array<string, array{cpu_m: int, memory_mb: int}>
     */
    public const OVERHEAD = [
        'clh' => ['cpu_m' => 250, 'memory_mb' => 256],
        'qemu' => ['cpu_m' => 250, 'memory_mb' => 320],
        'dragonball' => ['cpu_m' => 250, 'memory_mb' => 256],
        'fc' => ['cpu_m' => 250, 'memory_mb' => 320],
    ];

    /**
     * Node label RunR sets where containerd's devmapper snapshotter runs on RunR's thin-pool
     * (set up for Firecracker). kata-fc pods are scheduled only there; a node whose devmapper
     * snapshotter was set up by hand can carry it too.
     */
    public const DEVMAPPER_LABEL = 'runr.dev/devmapper-snapshotter';

    /**
     * Node label RunR sets from its /dev/kvm check (KataKvmProbeTrait): "true" where the node can
     * run VMs (the CPU's vmx / svm flag and /dev/kvm or the KVM module), "false" where it cannot — e.g.
     * cloud VMs without nested virtualization. Every Kata RuntimeClass selects "true", so in a
     * mixed cluster Kata pods only land on nodes that can start them.
     */
    public const KVM_LABEL = 'runr.dev/kvm';

    /** Node annotation with what the check saw and when ("kvm=yes dev_kvm=yes cpu=vmx module=kvm_intel at=…"). */
    public const KVM_PROBE_ANNOTATION = 'runr.dev/kvm-probe';

    /** kata-deploy's custom runtimes on the nodes (INSTALLATION_PREFIX ''): <handler>/config.d/50-overrides.toml. */
    public const CUSTOM_RUNTIMES_DIR = '/opt/kata/share/defaults/kata-containers/custom-runtimes';

    /** Thin-pool on the nodes: sparse data + metadata files, pool name, containerd root_path. */
    public const DEVMAPPER_DIR = '/var/lib/runr/devmapper';

    public const DEVMAPPER_POOL = 'runr-thinpool';

    /** Thin-pool data size (GiB, sparse): default and the range the install API accepts. */
    public const DEVMAPPER_POOL_DEFAULT_GB = 20;

    public const DEVMAPPER_POOL_MIN_GB = 10;

    public const DEVMAPPER_POOL_MAX_GB = 1024;

    /** Hypervisors whose images need containerd's devmapper snapshotter. */
    public static function needsDevmapper(string $hypervisor): bool
    {
        return isset(self::SNAPSHOTTER[$hypervisor]) && self::SNAPSHOTTER[$hypervisor] === 'devmapper';
    }

    /** Thin-pool metadata size for a data size (well above dm-thin's ~1 MiB per GiB at 64 KiB blocks). */
    public static function devmapperMetaGb(int $poolGb): int
    {
        return $poolGb > 512 ? 2 : 1;
    }

    /** Guest VM size before the pod's limits are added (static sandbox sizing). */
    public const GUEST_BASE_VCPUS = 1;

    /** The go runtime silently falls back to 2048 MiB below 256 MiB (vc.MinHypervisorMemory). */
    public const GUEST_BASE_MEMORY_MB = 256;

    /** [hypervisor.<section>] of each kata-deploy base configuration. */
    private const CONFIG_SECTION = [
        'clh' => 'clh',
        'qemu' => 'qemu',
        'dragonball' => 'dragonball',
        'fc' => 'firecracker',
    ];

    /** containerd snapshotter a hypervisor needs (fc boots block devices from devmapper). */
    private const SNAPSHOTTER = [
        'fc' => 'devmapper',
    ];

    /** Hypervisors that support free page reporting (reclaim_guest_freed_memory). */
    private const FREE_PAGE_REPORTING = ['clh', 'qemu', 'dragonball'];

    /**
     * kernel_params of kata-deploy 3.28's configuration per hypervisor — a drop-in's kernel_params
     * replaces the whole string, so RunR's starts from these. Dragonball's has no cgroup parameters
     * (its guest's systemd defaults to cgroup2); the agent as init needs them to mount cgroup2
     * instead of the v1 hierarchies (without them the Dragonball guest does not come up).
     */
    private const BASE_KERNEL_PARAMS = [
        'clh' => 'cgroup_no_v1=all systemd.unified_cgroup_hierarchy=1',
        'qemu' => 'cgroup_no_v1=all systemd.unified_cgroup_hierarchy=1',
        'dragonball' => 'console=ttyS1 agent.log_vport=1025 cgroup_no_v1=all systemd.unified_cgroup_hierarchy=1',
        'fc' => 'cgroup_no_v1=all systemd.unified_cgroup_hierarchy=1',
    ];

    /**
     * Guest init without systemd: the kernel starts the Kata agent as PID 1 — the agent's own init
     * mode, as in Kata's agent-init images and its initrd (it mounts /proc, /sys, /dev, /run and
     * the cgroup2 hierarchy itself). The guest image stays kata-deploy's (read-only rootfs); systemd
     * never starts. A POSIX sh runs first and keeps what systemd gave the guest: the image's
     * sysctl.d settings (sysctl --system: kptr_restrict, protected_* links, mmap_min_addr,
     * max_map_count, rp_filter, …) and RLIMIT_NOFILE 1048576 (systemd's LimitNOFILE for the agent;
     * containers inherit it, as with runc — the kernel's init default is 1024/4096), then execs the
     * agent, which keeps PID 1.
     *
     * How sh gets its arguments: the kernel hands every command-line parameter it does not know and
     * that has neither "." nor "=" to init as an argument, in order — here "-c" and the quoted
     * command (one parameter: the kernel, the go runtime and runtime-rs all keep double-quoted text
     * together). So the command must never contain "." or "=", and no other such parameter may come
     * before it. Not "--": runtime-rs (Dragonball: passfd port, virtio-mmio devices) and the go
     * runtime for Firecracker (root device, pci=off, acpi=off) append kernel parameters after the
     * configured ones, and behind "--" those would go to init — the guest would not boot.
     *
     * Not started any more: journald (the agent never logged to it), chronyd (kvm-clock keeps the
     * guest clock; pods are short-lived), dbus and systemd's sysinit services — none of them is used
     * by the agent or the containers. Measured on the test VM (nested KVM; CLH, QEMU, Dragonball): see the
     * class doc. Firecracker boots the same kernel and image with the same parameters (not installed
     * on that VM, so not measured there).
     */
    public const AGENT_INIT_KERNEL_PARAMS = 'init=/bin/sh -c "mount -t proc none /proc; sysctl --system >/dev/null 2>&1; ulimit -n 1048576; exec /usr/bin/kata-agent"';

    public static function isSupported(string $hypervisor): bool
    {
        return in_array($hypervisor, self::HYPERVISORS, true);
    }

    /** RuntimeClass name that project mappings use: "kata-clh". */
    public static function runtimeClass(string $hypervisor): string
    {
        return "kata-{$hypervisor}";
    }

    /** containerd / CRI-O runtime handler of RunR's kata-deploy custom runtime: "kata-clh-runr". */
    public static function handler(string $hypervisor): string
    {
        return "kata-{$hypervisor}-runr";
    }

    /** "kata-clh" → "clh"; null for any other RuntimeClass (runc, gvisor, …). */
    public static function hypervisorOf(?string $runtimeClass): ?string
    {
        if (! $runtimeClass || ! str_starts_with($runtimeClass, 'kata-')) {
            return null;
        }

        $hypervisor = substr($runtimeClass, strlen('kata-'));

        return self::isSupported($hypervisor) ? $hypervisor : null;
    }

    /**
     * Pod overhead a RuntimeClass adds to every pod (zero for runc, gVisor and RuntimeClasses
     * RunR does not manage).
     *
     * @return array{cpu_m: int, memory_mb: int}
     */
    public static function podOverhead(?string $runtimeClass): array
    {
        $hypervisor = self::hypervisorOf($runtimeClass);

        return $hypervisor ? self::OVERHEAD[$hypervisor] : ['cpu_m' => 0, 'memory_mb' => 0];
    }

    /**
     * scheduling.nodeSelector of a hypervisor's RuntimeClass: nodes kata-deploy finished on that
     * have /dev/kvm (RunR's check) — and, for fc, whose containerd runs the devmapper snapshotter.
     *
     * @return array<string, string>
     */
    public static function nodeSelector(string $hypervisor): array
    {
        $selector = [self::NODE_LABEL => 'true', self::KVM_LABEL => 'true'];
        if (self::needsDevmapper($hypervisor)) {
            $selector[self::DEVMAPPER_LABEL] = 'true';
        }

        return $selector;
    }

    /** overhead.podFixed quantities of a hypervisor's RuntimeClass. */
    public static function overheadQuantities(string $hypervisor): array
    {
        $o = self::OVERHEAD[$hypervisor];

        return ['cpu' => "{$o['cpu_m']}m", 'memory' => "{$o['memory_mb']}Mi"];
    }

    /** kata-deploy custom-runtimes.list line: handler:baseConfig:containerd_snapshotter:crio_pulltype */
    public static function customRuntimeLine(string $hypervisor): string
    {
        return self::handler($hypervisor).':'.$hypervisor.':'.(self::SNAPSHOTTER[$hypervisor] ?? '').':';
    }

    /**
     * RunR's config.d drop-in for a hypervisor (kata-deploy copies it to
     * custom-runtimes/<handler>/config.d/50-overrides.toml; the go runtime and runtime-rs read
     * it at every sandbox start).
     *
     * @param  bool  $staticSizing  containerd nodes (sandbox sizing annotations); false on CRI-O
     */
    public static function dropIn(string $hypervisor, bool $staticSizing = true): string
    {
        $section = self::CONFIG_SECTION[$hypervisor];
        $lines = [
            '# RunR: the whole sandbox (shim, VMM, virtiofsd) runs in the pod cgroup, sized by the',
            '# RuntimeClass overhead; the VM gets the pod limits on top of a small base.',
            "[hypervisor.{$section}]",
            'default_vcpus = '.self::GUEST_BASE_VCPUS,
            'default_memory = '.self::GUEST_BASE_MEMORY_MB,
        ];
        if (in_array($hypervisor, self::FREE_PAGE_REPORTING, true)) {
            $lines[] = 'reclaim_guest_freed_memory = true';
        }
        if (in_array($hypervisor, self::RUNTIME_RS, true)) {
            $lines = [...$lines, ...self::runtimeRsAnnotationLines($hypervisor, $staticSizing)];
        }
        $lines[] = '# RunR: the Kata agent is the guest init (no systemd), for a faster cold start';
        $lines[] = 'kernel_params = '.self::tomlString(self::kernelParams($hypervisor));
        $lines[] = '';
        $lines[] = '[runtime]';
        $lines[] = 'sandbox_cgroup_only = true';
        $lines[] = 'static_sandbox_resource_mgmt = '.($staticSizing ? 'true' : 'false');

        return implode("\n", $lines)."\n";
    }

    /** Guest kernel parameters RunR's drop-in sets: kata-deploy's + the agent as init. */
    public static function kernelParams(string $hypervisor): string
    {
        return self::BASE_KERNEL_PARAMS[$hypervisor].' '.self::AGENT_INIT_KERNEL_PARAMS;
    }

    /** One line on what the drop-ins of this release do (reconcile report). */
    public static function dropInSummary(): string
    {
        return 'the guest boots the Kata agent as init instead of systemd (faster cold start); '
            .'shim / VMM / virtiofsd in the pod cgroup, VM sized from the pod limits '
            .'(Dragonball: vCPUs from the pod annotations RunR sets)';
    }

    // ── Guest vCPUs of runtime-rs hypervisors ────────────────────────────────

    /** Does this hypervisor's guest need its vCPUs from RunR's pod annotations (see the class doc)? */
    public static function needsGuestVcpuAnnotations(?string $hypervisor): bool
    {
        return in_array($hypervisor, self::RUNTIME_RS, true)
            && ! self::sizesRuntimeRsVcpusItself(self::KATA_VERSION);
    }

    /** Does this Kata release give a runtime-rs guest the pod's CPU limits itself (RUNTIME_RS_STATIC_VCPUS_SINCE)? */
    private static function sizesRuntimeRsVcpusItself(string $kataVersion): bool
    {
        return version_compare($kataVersion, self::RUNTIME_RS_STATIC_VCPUS_SINCE, '>=');
    }

    /**
     * vCPUs of a statically sized guest: GUEST_BASE_VCPUS + the pod's CPU limits, rounded up — what
     * the go runtime gives clh / qemu / fc guests from containerd's sandbox sizing annotations (the
     * containers' limits without the RuntimeClass overhead; the largest init container when it is
     * larger than the sum). $sidecarCpuLimitM: containers added after RunR renders the pod
     * (Knative's queue-proxy). A pod without CPU limits keeps the base.
     */
    public static function guestVcpus(array $podSpec, int $sidecarCpuLimitM = 0): int
    {
        $sum = $sidecarCpuLimitM;
        foreach ($podSpec['containers'] ?? [] as $container) {
            $sum += self::cpuMillis($container['resources']['limits']['cpu'] ?? null);
        }
        foreach ($podSpec['initContainers'] ?? [] as $container) {
            $sum = max($sum, self::cpuMillis($container['resources']['limits']['cpu'] ?? null));
        }

        return self::GUEST_BASE_VCPUS + (int) ceil($sum / 1000);
    }

    /**
     * The guest sizing annotations of a pod spec — empty unless it runs on a hypervisor that needs
     * them (needsGuestVcpuAnnotations()). default_max_vcpus = default_vcpus: no vCPU hot-plug under
     * static sizing, and runtime-rs refuses a guest with more vCPUs than its maximum (by default the
     * host's CPU count).
     *
     * @return array<string, string>
     */
    public static function guestSizingAnnotations(array $podSpec, int $sidecarCpuLimitM = 0): array
    {
        if (! self::needsGuestVcpuAnnotations(self::hypervisorOf($podSpec['runtimeClassName'] ?? null))) {
            return [];
        }

        $vcpus = (string) self::guestVcpus($podSpec, $sidecarCpuLimitM);

        return [self::GUEST_VCPUS_ANNOTATION => $vcpus, self::GUEST_MAX_VCPUS_ANNOTATION => $vcpus];
    }

    /**
     * A workload manifest with the guest sizing annotations on its pod template — every manifest the
     * API writes passes here (KubernetesNamespacedTrait), so Pods, Jobs, CronJobs, Deployments and
     * Knative Services on such a hypervisor all get them. Knative adds queue-proxy to the pod: its CPU
     * limit is the revision's queue-proxy annotation, or Knative's default from config-deployment.
     * A manifest that sets either sizing annotation itself is left as it is.
     */
    public static function withGuestSizing(array $manifest): array
    {
        $kind = $manifest['kind'] ?? null;
        $knative = $kind === 'Service' && str_starts_with((string) ($manifest['apiVersion'] ?? ''), 'serving.knative.dev/');

        $path = match (true) {
            $kind === 'Pod' => [],
            $kind === 'CronJob' => ['spec', 'jobTemplate', 'spec', 'template'],
            $knative, in_array($kind, ['Job', 'Deployment', 'StatefulSet', 'DaemonSet', 'ReplicaSet'], true) => ['spec', 'template'],
            default => null,
        };
        if ($path === null) {
            return $manifest;
        }

        $template = $path === [] ? $manifest : data_get($manifest, implode('.', $path));
        if (! is_array($template) || ! is_array($template['spec'] ?? null)) {
            return $manifest;
        }

        $sidecar = 0;
        if ($knative) {
            $limit = $template['metadata']['annotations'][self::QUEUE_PROXY_CPU_LIMIT_ANNOTATION] ?? null;
            $sidecar = $limit !== null ? self::cpuMillis($limit) : self::QUEUE_PROXY_DEFAULT_CPU_LIMIT_M;
        }

        $sizing = self::guestSizingAnnotations($template['spec'], $sidecar);
        if ($sizing === []) {
            return $manifest;
        }

        $annotations = (array) ($template['metadata']['annotations'] ?? []);
        if (array_intersect_key($annotations, $sizing) !== []) {
            return $manifest; // sized by the manifest itself
        }
        $template['metadata']['annotations'] = $annotations + $sizing;

        if ($path === []) {
            return $template;
        }
        data_set($manifest, implode('.', $path), $template);

        return $manifest;
    }

    /** Kubernetes CPU quantity ("250m", "1", "1.5", 2) in millicores; 0 when absent or unparsable. */
    public static function cpuMillis(mixed $quantity): int
    {
        if (is_int($quantity) || is_float($quantity)) {
            return (int) ceil($quantity * 1000);
        }
        if (! is_string($quantity) || ! preg_match('/^(\d+(?:\.\d+)?)(m?)$/', trim($quantity), $m)) {
            return 0;
        }

        return (int) ceil($m[2] === 'm' ? (float) $m[1] : (float) $m[1] * 1000);
    }

    /**
     * enable_annotations of a runtime-rs hypervisor: kata-deploy's list, plus the guest's maximum
     * vCPUs where RunR sizes the guest through pod annotations (static sizing on containerd). On
     * CRI-O Kata hot-plugs vCPUs on top of default_vcpus, so there RunR's vCPU annotations stay
     * unread (they would count the pod's CPU limits twice). A Kata release that sizes the vCPUs
     * itself keeps kata-deploy's list.
     *
     * @return list<string>
     */
    private static function runtimeRsAnnotationLines(string $hypervisor, bool $staticSizing): array
    {
        if (! $staticSizing) {
            $annotations = array_values(array_diff(self::RUNTIME_RS_STOCK_ANNOTATIONS, ['default_vcpus']));
            $comment = '# RunR: Kata hot-plugs the vCPUs here; RunR\'s vCPU pod annotations stay unread';
        } elseif (self::needsGuestVcpuAnnotations($hypervisor)) {
            $annotations = [...self::RUNTIME_RS_STOCK_ANNOTATIONS, 'default_max_vcpus'];
            $comment = '# RunR: vCPUs = 1 + the pod\'s CPU limits from the pod annotations RunR sets (runtime-rs '
                .self::KATA_VERSION.' sizes only the memory)';
        } else {
            return [];
        }

        return [$comment, 'enable_annotations = ['.implode(', ', array_map(self::tomlString(...), $annotations)).']'];
    }

    /** TOML basic string. */
    private static function tomlString(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
