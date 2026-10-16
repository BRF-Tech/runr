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

RunR installs each hypervisor as a kata-deploy "custom runtime" (handler `kata-<hv>-runr`, RuntimeClass name
`kata-<hv>`) with a `config.d` drop-in that sets:

- `sandbox_cgroup_only = true` (shim, VMM and virtiofsd run in the pod cgroup)
- `static_sandbox_resource_mgmt = true` (VM = 1 vCPU + 256 MiB + the pod limits; off on CRI-O; see
  [Guest size](#guest-size))
- `reclaim_guest_freed_memory = true` (clh, qemu, dragonball)
- `kernel_params` that start the Kata agent as the guest's init (see below)
- Dragonball only: `enable_annotations` with `default_max_vcpus` added to kata-deploy's list (containerd
  nodes), or without `default_vcpus` (CRI-O)

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
- **dragonball** runs on the Rust runtime (runtime-rs). Its static sizing in kata-deploy 3.28 adds
  the memory limits but not the CPU limits, and static sizing turns vCPU hot-plug off, so a
  Dragonball guest kept 1 vCPU whatever the pod's limit (Kata 3.29.0 sizes the vCPUs as well). RunR
  writes the vCPU count on every Dragonball workload it creates — Knative Services, CronJobs, Jobs
  and sandbox pods — as the pod annotations `io.katacontainers.config.hypervisor.default_vcpus`
  and `…default_max_vcpus` (both the same value: no hot-plug, and runtime-rs refuses a guest with
  more vCPUs than its maximum, which defaults to the host's CPU count). Dragonball's drop-in enables
  the two annotations on containerd nodes; on CRI-O, where Kata hot-plugs vCPUs, it leaves them
  unread. `KataRuntime::RUNTIME_RS_STATIC_VCPUS_SINCE` ends the annotations once RunR's
  kata-deploy is 3.29.0 or newer. Existing Dragonball functions get them at their next deploy;
  `region:reconcile` updates the drop-in on the nodes.

`ci.sh test-sandbox-api` (with `/dev/kvm`) checks `nproc` inside a 1-CPU and a 2-CPU sandbox on
each of clh, qemu and dragonball.

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

Measurement (8-vCPU Broadwell OpenStack VM with nested KVM, k3s 1.34, Kata 3.28.0; from zero to first response
through Knative, function 250m / 256Mi; median, n ≥ 6; 0.4.2 exactly as RunR renders it: the drop-ins written
by the kata-dropins DaemonSet, the RuntimeClasses and the queue-proxy annotation):

| | Node.js 0.4.1 | Node.js 0.4.2 | Python 0.4.1 | Python 0.4.2 |
|---|---|---|---|---|
| kata-clh | 6.7 s | **2.7 s** (2.5–3.6) | 6.1 s | **3.1 s** (2.7–3.9) |
| kata-qemu | 7.1 s | **3.5 s** (3.3–3.9) | 7.3 s | **3.7 s** (3.4–4.4) |
| kata-dragonball | 7.1 s | **3.6 s** (3.3–4.3) | 7.4 s | **3.7 s** (3.5–8.5) |

Where the remaining time goes (kata-clh, Node.js): Knative/kubelet work before the sandbox ~0.4 s (the same
under runc), VM boot ~1.1 s, creating the two containers ~0.3 s, the function's start inside the guest
~0.95 s (at a 250m limit; the cost of first memory touches on nested KVM, 0.17 s under runc).

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
it keeps running with its pod template, and a drop-in update re-applies that template as it is.

### Firecracker and devmapper

For Firecracker, RunR applies a `kata-devmapper` DaemonSet to the nodes before the kata-installer:

- a sparse data file (default 20 GiB, 10–1024 via `devmapper_pool_gb`) + a 1 GiB metadata file under
  `/var/lib/runr/devmapper`
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

New nodes (without the `runr.dev/kvm` label) are checked when the console asks (`ProbeKvmJob`), before an install,
and at `region:reconcile`.
