# Kata Containers hypervisors

RunR offers VM-based container isolation through Kata Containers. Kata supports several hypervisor backends
(shims). This document covers the shims RunR installs, their requirements and how RunR checks compatibility.

---

## Shims RunR installs

RunR installs four hypervisors (`KataRuntime::HYPERVISORS`). The install API
(`POST /api/knative-regions/{id}/install-sandbox`) rejects any other value with 422 and validates the
selection with the same compatibility check the console shows (kata-shims).

| Shim | Hypervisor | Runtime | Architectures | RuntimeClass overhead (CPU / memory) | Notes |
|------|-----------|---------|---------------|--------------------------------------|-------|
| **clh** ★ | Cloud Hypervisor | Go | amd64, arm64 | 250m / 256Mi | Lightweight VMM with a minimal attack surface. **Recommended default.** |
| **qemu** | QEMU | Go | amd64, arm64, s390x, ppc64le | 250m / 320Mi | Widest compatibility and feature set. |
| **dragonball** | Dragonball | Rust (runtime-rs) | amd64, arm64 | 250m / 256Mi | VMM built into the shim process. |
| **fc** | Firecracker | Go | amd64, arm64 | 250m / 320Mi | Needs the devmapper snapshotter, which RunR sets up on containerd nodes (plain, k3s, rke2). |

RunR runs kata-deploy (`KataRuntime::KATA_VERSION`, 4.3.1) as a DaemonSet of its own, `kata-installer` in
`runr-sandbox`: the upstream Helm chart's daemonset mode, not its default job mode. The pod mounts what the chart
mounts for it, because kata-deploy works inside the pod: `/opt/kata` (where Kata is installed), systemd's private
socket `/run/systemd/private` (how it tells k3s and RKE2 from containerd and restarts the runtime, so a node needs
systemd), `/run/lock` and the node's containerd and CRI-O configuration.

RunR installs each hypervisor as a kata-deploy "custom runtime" (handler `kata-<hv>-runr`, RuntimeClass name
`kata-<hv>`) with a `config.d` drop-in that sets:

- `sandbox_cgroup_only = true` (shim, VMM and virtiofsd run in the pod cgroup)
- `static_sandbox_resource_mgmt = true` (VM = 1 vCPU + 256 MiB + the pod limits; off on CRI-O; see
  [Guest size](#guest-size))
- `reclaim_guest_freed_memory = true` (clh, qemu, dragonball)
- `kernel_params` that start the Kata agent as the guest's init (see below)
- Dragonball only: `overhead_vcpus` and `overhead_memory` (the same 1 vCPU and 256 MiB, see
  [Guest size](#guest-size)), and on CRI-O `enable_annotations` without `default_vcpus`

The RuntimeClasses carry `overhead.podFixed` and `scheduling.nodeSelector`
`katacontainers.io/kata-runtime=true` + `runr.dev/kvm=true` (fc also `runr.dev/devmapper-snapshotter=true`).
The overhead values are derived in `api/app/Services/Helpers/KataRuntime.php`.
Region capacity admission reserves the overhead of the RuntimeClass a project or model service was
deployed with on the region's always-on budget: per pod for always-on workloads, one pod's worth
for scale-to-zero ones ([Region capacity and admission](architecture.md#region-capacity-and-admission)).

### Guest size

On containerd nodes a Kata VM boots at its final size: 1 vCPU and 256 MiB plus the pod's limits —
vCPUs = 1 + the containers' CPU limits rounded up (Knative's queue-proxy counts: with its 1-CPU
limit a 250m function gets 3), memory = 256 MiB + the containers' memory limits. The RuntimeClass
overhead is not part of the guest.

- **clh, qemu, fc** run on Kata's Go runtime, which takes the size from containerd's sandbox
  sizing annotations itself.
- **dragonball** runs on the Rust runtime (runtime-rs), which takes the same annotations but has
  two pairs of settings: `overhead_vcpus` / `overhead_memory` are added to a pod's limits,
  `default_vcpus` / `default_memory` apply to a pod without limits. kata-deploy's own overhead is
  0.2 vCPU and 32 MiB, far below what the guest's kernel and agent need on top of the limits, so
  RunR's drop-in sets both pairs to 1 vCPU and 256 MiB and a Dragonball guest has the size of the
  others. Workloads deployed while RunR ran Kata 3.28 still carry the vCPU pod annotations RunR wrote for it
  (`io.katacontainers.config.hypervisor.default_vcpus`, `…default_max_vcpus`); runtime-rs ignores
  them under static sizing, and on CRI-O, where Kata hot-plugs vCPUs, the drop-in leaves them
  unread.

`ci.sh test-sandbox-api` (with `/dev/kvm`) checks `nproc` inside a 1-CPU and a 2-CPU sandbox on
each of clh, qemu and dragonball. It gives the Kind nodes a host-like `/dev/shm` first (`KIND_NODE_SHM`,
default 4g; see [/dev/shm (QEMU)](#devshm-qemu)) and D-Bus (`KIND_NODE_DBUS`, see
[D-Bus (Dragonball)](#d-bus-dragonball)) first; a hypervisor the install reports unavailable is
skipped with the install's reason.

### Cold start

A Kata cold start is CPU-bound: the guest kernel, init, agent and the first container touch fresh guest memory,
and on nested KVM every first touch is an EPT exit. The VM boots inside the pod's CPU limit (sandbox cgroup).
RunR does two things here:

- **Agent as the guest's init (all hypervisors):** instead of guest systemd, the Kata agent starts directly as
  PID 1 (the mode Kata's initrd and agent-init images use). `kernel_params` in the drop-in
  (`init=/bin/sh -c "…"`) first runs a `sh` that applies the image's `sysctl.d` settings (`sysctl --system`)
  and raises `RLIMIT_NOFILE` to 1048576 as systemd would, then `exec`s the agent. The kernel passes parameters
  it does not recognise and that contain no "." or "=" to init as arguments, in order; the command therefore
  contains no "." or "=" and does not use `--` (the Dragonball and Firecracker runtimes append kernel
  parameters after the configured ones, and anything after `--` would go to init). journald, chronyd, dbus and
  systemd's sysinit services do not start; neither the agent nor the containers use them. On Dragonball,
  `cgroup_no_v1=all systemd.unified_cgroup_hierarchy=1` is added for the agent's cgroup2 mount.
- **queue-proxy CPU burst (all project pods: runc, gVisor, Kata):** in every Knative Service RunR renders, the
  queue-proxy CPU limit is 1 CPU (request stays 100m, memory request = limit; Knative revision annotation
  `queue.sidecar.serving.knative.dev/cpu-resource-limit`). A Kata pod can then use its limits + overhead while
  it boots (1.5 CPU for a 250m function), and queue-proxy (inside the guest on Kata) is ready sooner; under runc
  the cold start also drops from 2.0 s to 1.5 s. The function container stays request = limit. The trade-off:
  project pods are Burstable rather than Guaranteed; the burst only uses idle node CPU, and under contention
  every pod gets its request's share. Reservations are unchanged (the scheduler, `ResourceReservation`, tenant
  admission and the organization ceiling count requests, so the ceiling ResourceQuota contains only
  `requests.cpu`). Existing projects pick up the annotation on their next deploy (Knative's
  `config-deployment` default of 100m request = limit stays, since changing it would restart every Revision).

Measurement with Kata 4.3.1 (RunR 0.6.6, 2026-11-06; 8-vCPU Broadwell OpenStack VM with nested KVM, k3s 1.34,
Knative 1.21.3; from zero replicas to the first full HTTPS response through Traefik, Python 3.15 function
250m / 256Mi, image on the node; median (p90) and range, n = 12; the last column is the same measurement with
RunR 0.5.0 and Kata 3.28.0, Python 3.14):

| | Python, Kata 4.3.1 | Kata 3.28.0 |
|---|---|---|
| kata-clh | **3.19 s** (3.53), 3.00–3.60 | 3.11 s (3.25) |
| kata-fc | **3.17 s** (3.52), 2.91–3.69 | 3.21 s (3.47) |
| kata-qemu | **3.96 s** (4.21), 3.62–4.22 | 3.89 s (4.45) |
| kata-dragonball | **4.15 s** (4.48), 3.93–4.67 | 3.95 s (4.04) |

A runc control in the same run took 1.58 s (1.50 s with 0.5.0); warm requests take 10–12 ms on every
hypervisor. Where the time goes (kata-clh, medians): Knative/kubelet work before the sandbox ~0.45 s (the same
under runc), VM boot ~1.2 s, creating the two containers ~0.3 s, the function's start inside the guest ~1.3 s
(at a 250m limit; the cost of first memory touches on nested KVM, 0.37 s under runc), the kubelet's status
relist ~0.7 s. VM boot by hypervisor: fc 1.14 s, clh 1.18 s, qemu 1.92 s, dragonball 2.07 s (with Kata 3.28.0:
1.04 / 1.12 / 1.84 / 1.85 s); Dragonball's 0.2 s more is CPU its VMM spends at boot on Kata 4.3.1, for the
same 3-vCPU / 576 MiB guest.

The two changes above were measured with RunR 0.4.2 on Kata 3.28.0 (the same VM; from zero to first response
through Knative, function 250m / 256Mi; median and range, n ≥ 6; 0.4.2 exactly as RunR renders it: the
drop-ins written by the kata-dropins DaemonSet, the RuntimeClasses and the queue-proxy annotation):

| | Node.js 0.4.1 | Node.js 0.4.2 | Python 0.4.1 | Python 0.4.2 |
|---|---|---|---|---|
| kata-clh | 6.7 s | **2.7 s** (2.5–3.6) | 6.1 s | **3.1 s** (2.7–3.9) |
| kata-qemu | 7.1 s | **3.5 s** (3.3–3.9) | 7.3 s | **3.7 s** (3.4–4.4) |
| kata-dragonball | 7.1 s | **3.6 s** (3.3–4.3) | 7.4 s | **3.7 s** (3.5–8.5) |

### Drop-in updates without restarts

The kata-installer DaemonSet's pod template hash covers only what kata-deploy installs (pod spec, handler
list), not the drop-ins. The DaemonSet keeps the installer hash running on the nodes
(`runr.dev/kata-installer-hash`) and the drop-in hash (`runr.dev/kata-dropins-hash`) as annotations. When only
the drop-ins change (`region:reconcile`, or a reinstall with the same hypervisors), kata-deploy does not run
again: a short-lived `kata-dropins` DaemonSet atomically replaces
`custom-runtimes/<handler>/config.d/50-overrides.toml` on every installer node (written outside `config.d`,
then renamed) and is deleted; the ConfigMap is updated as well (kata-deploy copies the same file on its next
run). containerd / k3s / RKE2 are not restarted. The shim reads its configuration at every sandbox start, so
new Kata pods start with the new drop-in while running pods keep their VMs. RunR 0.4.1 installs are
recognised (0.4.1's hash over pod spec + ConfigMap matches this release's pod spec and the ConfigMap in the
cluster), so a 0.4.1 → 0.4.2 upgrade restarts nothing on Kata nodes. An installer whose pod spec differs only in
RunR's image namespace (`ghcr.io/runrsh` up to 0.5.0, `ghcr.io/brf-tech` in 0.5.1, `ghcr.io/brf-tech/runr` since) counts as the same installer:
kata-deploy does not run again for it. `region:reconcile` (`image-registry`) moves the pod template to
`ghcr.io/brf-tech/runr` with the update strategy `OnDelete`, so the installer pods keep running as they are
and a pod created later (a new node) starts from the moved image; the rollout status counts the pinned pods
by their config hash, and a drop-in update re-applies the template and the strategy as they are. A Kata
install that re-runs kata-deploy applies `RollingUpdate` again.

### Firecracker and devmapper

For Firecracker, RunR applies a `kata-devmapper` DaemonSet to the nodes before the kata-installer:

- a sparse data file (default 20 GiB, 10–1024 via `devmapper_pool_gb`) + a 1 GiB metadata file under
  `/var/lib/runr/devmapper`. The files are created once and never resized: the region records the
  size (`sandbox_kata_devmapper_pool_gb` of `GET /knative-regions/{id}`, `devmapper.pool_gb` of
  `GET /knative-regions/{id}/kata-shims`) and later installs — **Add hypervisors**, `region:reconcile`,
  a [move to another cluster](regions.md#moving-a-region-to-another-cluster) — use it, whatever
  `devmapper_pool_gb` they send. Uninstalling Kata clears it; a pool set up before the size was
  recorded keeps the size of its `kata-devmapper` DaemonSet
- the `runr-thinpool` thin-pool on loop devices
- `runr-devmapper.service`, which activates the pool at boot before containerd / k3s / rke2
- `[plugins."io.containerd.snapshotter.v1.devmapper"]` in containerd (k3s/rke2:
  `config-v3.toml.d/runr-devmapper.toml`)

The plugin is loaded by the same runtime restart kata-deploy performs.

---

## Hardware requirements

### KVM (all shims)
- **Intel**: VT-x (VMX), enabled in the BIOS
- **AMD**: AMD-V (SVM), enabled in the BIOS
- **Check**: `/dev/kvm` must exist on the node
- **Cloud VMs**: the provider must expose nested virtualization to the VM

### D-Bus (Dragonball)
- Kata's Rust runtime (runtime-rs) moves each sandbox into its systemd cgroup over the D-Bus system
  bus (the go runtime, like runc, uses systemd's private socket). On a node that runs systemd
  without D-Bus every Dragonball pod sandbox fails: `add runtime to sandbox cgroup: systemd dbus
  error: … No such file or directory`
- A regular node runs D-Bus; Kind's node image does not. RunR's node check records it (`dbus=` in the
  `runr.dev/kvm-probe` annotation: `yes`, `missing`, `n/a` without systemd), and where every Kata
  node is `missing` Dragonball is not offered (`no_dbus`); the install API's 422 lists it under
  `unavailable`

### /dev/shm (QEMU)
- With virtio-fs the guest's memory is shared with virtiofsd. Kata's Go runtime gives QEMU a
  `memory-backend-file` in `/dev/shm` for it (`file_mem_backend` unset); Cloud Hypervisor and
  Dragonball use anonymous shared memory and do not need `/dev/shm`
- The file is as large as the guest (256 MiB + the pod's memory limits), and every page the guest
  touches lives in that tmpfs: when `/dev/shm` is full, QEMU dies of SIGBUS while the guest boots and
  the kubelet keeps retrying the pod sandbox
- A host's `/dev/shm` is half its memory. A node that runs in a container (Kind, k3d) gets Docker's
  64 MiB: RunR's node check records the size (`shm_mb` in the `runr.dev/kvm-probe` annotation), and
  where every Kata node has less than 1 GiB (`KataRuntime::QEMU_MIN_SHM_MB`) QEMU is not offered
  (`small_shm`); the install API's 422 lists it under `unavailable`, so the other hypervisors can be
  installed

### devmapper snapshotter (Firecracker)
- Firecracker works with block devices and does not support overlayfs
- containerd needs the `devmapper` snapshotter backed by a thin-provisioning pool
- RunR sets both up on containerd nodes (see [Firecracker and devmapper](#firecracker-and-devmapper)); the
  pool is re-activated at every boot by `runr-devmapper.service`

---

## Go runtime vs Rust runtime (runtime-rs)

| | Go runtime | Rust runtime (runtime-rs) |
|---|---|---|
| Process model | `containerd-shim-kata-v2` + separate VMM process | Single process (VMM built into the shim) |
| Shims in RunR | clh, qemu, fc | dragonball |
| Shim binary | `/opt/kata/bin/containerd-shim-kata-v2` | `/opt/kata/runtime-rs/bin/containerd-shim-kata-v2` |

Since Kata 4.0 the Rust runtime is upstream's default and the Go runtime is deprecated: it is still shipped and
supported, gets critical fixes and no new features, and has no removal date. RunR stays on it for clh, qemu and fc,
whose sizing and measurements above are the Go runtime's. Firecracker has no runtime-rs shim in kata-deploy.

---

## How RunR checks compatibility

During a Kata install (and for the console's shim selector), RunR analyses the cluster nodes and returns a
compatibility state per shim (`KataShimDetectionTrait`):

| Check | How it is detected |
|-------|--------------------|
| Architecture | `node.status.nodeInfo.architecture` (`arch_mismatch`) |
| KVM | RunR's check (0.4.4+): a short-lived, unprivileged `kata-kvm-probe` DaemonSet on every Kata target node looks for the CPU's `vmx`/`svm` flag and `/dev/kvm` or a loaded `kvm_intel`/`kvm_amd`; the result is the node label `runr.dev/kvm=true/false` (+ the `runr.dev/kvm-probe` annotation). Nodes without that label use NFD's `cpu-cpuid.VMX`/`SVM` labels as a fast path. If no node has KVM, every Kata hypervisor is `no_kvm`; in a mixed cluster Kata pods land only on `runr.dev/kvm=true` nodes |
| Container runtime | `node.status.nodeInfo.containerRuntimeVersion` (containerd or not) |
| devmapper | Node label `runr.dev/devmapper-snapshotter=true` (set by RunR's setup, or by hand for a snapshotter configured manually); otherwise RunR sets it up if every node runs containerd, and reports `no_devmapper` if not (CRI-O) |
| D-Bus (Dragonball) | The same check looks for the node's `/run/dbus/system_bus_socket` where systemd runs (`dbus=` in `runr.dev/kvm-probe`). If every Kata node with KVM runs systemd without it, Dragonball is `no_dbus` (see [D-Bus (Dragonball)](#d-bus-dragonball)) |
| /dev/shm (QEMU) | The same check reads the size of the node's `/dev/shm` (`shm_mb=` in `runr.dev/kvm-probe`). If every Kata node with KVM and a known size has less than 1 GiB, QEMU is `small_shm` (see [/dev/shm (QEMU)](#devshm-qemu)); nodes checked by a release without the size keep QEMU offered |

New nodes (without the `runr.dev/kvm` label) are checked when the console asks (`ProbeKvmJob`), before an install,
and at `region:reconcile`.
