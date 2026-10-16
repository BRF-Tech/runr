#!/usr/bin/env bash
# Tests the GPU path of `ci.sh test-ai` (lib/test-ai.sh) without Docker, a GPU or Kind: docker,
# nvidia-smi, nvidia-container-cli, df, kind, kubectl and cmd_test are shell functions. Checks the
# reasons the GPU case is skipped, how the Kind GPU worker gets the GPU (Docker's default runtime
# nvidia where it is configured, otherwise the driver's files as read-only extraMounts), that only
# Kind nodes where the driver works become GPU nodes — every privileged Kind node sees the host's
# /dev/nvidia* — and get the GPU image, the host's copy removed after that, `kind load`'s archive
# in RUNR_E2E_TMPDIR, the disk check (the built images' sizes, every Kind node's own data, the
# Compose stack, the preload) that keeps a GPU run from starting where it would fill the disk, and
# etcd in memory unless KIND_ETCD_IN_MEMORY=0.
#
#   bash runtime/test/ai_gpu_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/test-ai.sh
source "$here/lib/test-ai.sh"

# the knobs the expectations below assume unset
unset KIND_WORKERS KIND_PRELOAD KIND_ETCD_IN_MEMORY RUNR_E2E_GPU_RESERVE_GB RUNR_E2E_GPU
AI_NVIDIA_RUNTIME_CONFIG="$tmp/config.toml"
AI_NVIDIA_DEVICE="$tmp/nvidiactl"
calls="$tmp/calls"
mkdir "$tmp/disk"
export RUNR_E2E_TMPDIR="$tmp/disk"
GiB=1073741824

# ── the host ─────────────────────────────────────────────────────────────────────────────────────
absent=""                  # commands `command -v` does not find
smi_gpu=1                  # nvidia-smi -L lists a GPU
default_runtime=runc       # docker info → Default Runtime
host_free_kb=$((60 * 1024 * 1024))  # free under Docker's root dir (mounted on /)
scratch_mount=/            # the filesystem of RUNR_E2E_TMPDIR
scratch_dev=/dev/sdc       # …its device when it is not /
scratch_free_kb=0          # …and its free space
driver_files="/usr/bin/nvidia-smi
/usr/lib/x86_64-linux-gnu/libnvidia-ml.so.595.91.07
/usr/lib/x86_64-linux-gnu/libcuda.so.595.91.07
/lib/firmware/nvidia/595.91.07/gsp_ga10x.bin"
kind_nodes="ai-control-plane ai-worker ai-worker2 ai-worker3"
driver_nodes="ai-worker3"  # the Kind nodes where nvidia-smi works

host() {
  absent="" smi_gpu=1 default_runtime=runc host_free_kb=$((60 * 1024 * 1024))
  scratch_mount=/ scratch_dev=/dev/sdc scratch_free_kb=0
  img_hf_archive=$((GiB / 2)) img_hf_layers=("$GiB" 0 "$GiB")
  img_gpu_archive=$((45 * 1048576)) img_gpu_layers=($((126 * 1048576)) 0 $((8 * 1048576)))
  rm -f -- "$AI_NVIDIA_RUNTIME_CONFIG" "$tmp/etcd"
  touch "$AI_NVIDIA_DEVICE"
  : > "$calls"
}

# The images' sizes (host): hf-serve 0.5 GiB archive + 2 GiB unpacked, the GPU image 45 + 134 MiB
image_inspect_size() {
  case "$1" in
    *hf-serve*) echo "$img_hf_archive" ;;
    *ai-gpu-caas*) echo "$img_gpu_archive" ;;
  esac
}
image_history_sizes() {
  case "$1" in
    *hf-serve*) printf '%s\n' "${img_hf_layers[@]}" ;;
    *ai-gpu-caas*) printf '%s\n' "${img_gpu_layers[@]}" ;;
  esac
}

command() {
  if [ "${1:-}" = "-v" ] && [[ " $absent " == *" ${2:-} "* ]]; then return 1; fi
  builtin command "$@"
}
nvidia-smi() { [ "$smi_gpu" = 1 ] && echo "GPU 0: NVIDIA GeForce RTX 4050 Laptop GPU (UUID: GPU-0)"; return 0; }
nvidia-container-cli() {
  [ "$1" = list ] || return 1
  # what the real one lists: device nodes first, then the driver's files
  printf '%s\n' /dev/nvidiactl /dev/nvidia-uvm /dev/nvidia0
  [ -z "$driver_files" ] || printf '%s\n' "$driver_files"
}
df() {
  local path="${*: -1}"
  if [ "$path" = "$RUNR_E2E_TMPDIR" ] && [ "$scratch_mount" != / ]; then
    printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n%s 1 1 %s 1%% %s\n' "$scratch_dev" "$scratch_free_kb" "$scratch_mount"
  else
    printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n/dev/sdb 1 1 %s 1%% /\n' "$host_free_kb"
  fi
}
docker() {
  echo "docker $*" >> "$calls"
  case "$1" in
    info)
      case "$*" in
        *DefaultRuntime*) echo "$default_runtime" ;;
        *DockerRootDir*) echo /var/lib/docker ;;
      esac ;;
    image)
      case "$2" in
        inspect) image_inspect_size "${*: -1}" ;;
        history) image_history_sizes "${*: -1}" ;;
      esac ;;
    exec)
      # every Kind node sees /dev/nvidia0; nvidia-smi works where the driver is
      case "$*" in
        *"ls /dev/nvidia0"*) ;;
        *"nvidia-smi -L"*) [[ " $driver_nodes " == *" $2 "* ]] && echo "GPU 0: NVIDIA GeForce RTX 4050 Laptop GPU (UUID: GPU-0)" ;;
      esac ;;
  esac
  return 0
}
kind() {
  echo "kind $* TMPDIR=${TMPDIR:-}" >> "$calls"
  [ "$1 $2" = "get nodes" ] && printf '%s\n' $kind_nodes
  return 0
}
kubectl() { echo "kubectl $*" >> "$calls"; }
cmd_test() {
  echo "cmd_test $*" >> "$calls"
  printf '%s\n' "${KIND_EXTRA_NODES_YAML:-}" > "$tmp/kind-nodes.yaml"
  printf '%s\n' "$TEST_E2E_EXTRA_ARGS" > "$tmp/args"
  printf '%s\n' "${KIND_ETCD_IN_MEMORY:-}" > "$tmp/etcd"
}

reason_is() {
  local got
  got=$(ai_gpu_unavailable_reason)
  [[ "$got" == *"$1"* ]] || fail "reason: want '$1', got '$got'"
}

# ── when the GPU case is skipped ─────────────────────────────────────────────────────────────────
host; absent="nvidia-smi"; reason_is "nvidia-smi not found"
host; smi_gpu=0; reason_is "nvidia-smi lists no GPU"
host; absent="nvidia-container-cli"; reason_is "nvidia-container-cli not found"
host; rm -f -- "$AI_NVIDIA_DEVICE"; reason_is "no ${AI_NVIDIA_DEVICE} on the host"
host; driver_files=""; reason_is "nvidia-container-cli list names no driver files"
driver_files="/usr/bin/nvidia-smi
/usr/lib/x86_64-linux-gnu/libnvidia-ml.so.595.91.07
/usr/lib/x86_64-linux-gnu/libcuda.so.595.91.07
/lib/firmware/nvidia/595.91.07/gsp_ga10x.bin"
host; [ -z "$(ai_gpu_unavailable_reason)" ] || fail "a GPU host with Docker's default runtime runc was refused: $(ai_gpu_unavailable_reason)"
echo "ok - skip reasons; Docker's default runtime runc is no reason"

# ── the route ────────────────────────────────────────────────────────────────────────────────────
host; [ "$(ai_gpu_route)" = "driver-mounts" ] || fail "runc host: $(ai_gpu_route)"
host; default_runtime=nvidia
[ "$(ai_gpu_route)" = "driver-mounts" ] || fail "nvidia default runtime without volume mounts: $(ai_gpu_route)"
echo '#accept-nvidia-visible-devices-as-volume-mounts = true' > "$AI_NVIDIA_RUNTIME_CONFIG"
[ "$(ai_gpu_route)" = "driver-mounts" ] || fail "a commented-out volume-mounts key counted"
echo 'accept-nvidia-visible-devices-as-volume-mounts = true' > "$AI_NVIDIA_RUNTIME_CONFIG"
[ "$(ai_gpu_route)" = "runtime" ] || fail "configured default runtime not taken: $(ai_gpu_route)"
absent="nvidia-container-cli"
[ "$(ai_gpu_route)" = "runtime" ] || fail "the runtime route needs no nvidia-container-cli on the host"
[ -z "$(ai_gpu_unavailable_reason)" ] || fail "runtime route refused: $(ai_gpu_unavailable_reason)"
echo "ok - route: runtime where configured, driver-mounts otherwise"

# ── the Kind GPU worker ──────────────────────────────────────────────────────────────────────────
want_runtime='  - role: worker
    extraMounts:
      - hostPath: /dev/null
        containerPath: /var/run/nvidia-container-devices/all'
[ "$(ai_gpu_kind_node_yaml runtime)" = "$want_runtime" ] || fail "runtime worker: $(ai_gpu_kind_node_yaml runtime)"
host
want_mounts='  - role: worker
    extraMounts:
      - hostPath: "/usr/bin/nvidia-smi"
        containerPath: "/usr/bin/nvidia-smi"
        readOnly: true
      - hostPath: "/usr/lib/x86_64-linux-gnu/libnvidia-ml.so.595.91.07"
        containerPath: "/usr/lib/x86_64-linux-gnu/libnvidia-ml.so.595.91.07"
        readOnly: true
      - hostPath: "/usr/lib/x86_64-linux-gnu/libcuda.so.595.91.07"
        containerPath: "/usr/lib/x86_64-linux-gnu/libcuda.so.595.91.07"
        readOnly: true
      - hostPath: "/lib/firmware/nvidia/595.91.07/gsp_ga10x.bin"
        containerPath: "/lib/firmware/nvidia/595.91.07/gsp_ga10x.bin"
        readOnly: true'
[ "$(ai_gpu_kind_node_yaml driver-mounts)" = "$want_mounts" ] || fail "driver-mounts worker: $(ai_gpu_kind_node_yaml driver-mounts)"
echo "ok - Kind GPU worker per route (the device nodes are not mounted)"

# ── cmd_test_ai ──────────────────────────────────────────────────────────────────────────────────
host
out=$(cmd_test_ai 2>&1) || fail "cmd_test_ai: $out"
echo "$out" | grep -q 'GPU case: on (Kind GPU worker: driver-mounts)' || fail "GPU case not on: $out"
grep -q "^docker build -q --pull -t ${AI_GPU_IMAGE_TAG} .*examples/ai/gpu-caas" "$calls" || fail "gpu-caas not built"
[ "$(cat "$tmp/kind-nodes.yaml")" = "$want_mounts" ] || fail "KIND_EXTRA_NODES_YAML: $(cat "$tmp/kind-nodes.yaml")"
grep -q -- "--ai-gpu --gpu-image=${AI_GPU_IMAGE_TAG}" "$tmp/args" || fail "suite args: $(cat "$tmp/args")"
grep -q '^cmd_test --suite=ai' "$calls" || fail "cmd_test not called: $(cat "$calls")"
grep -q "hf-serve:1.1.1" "$tmp/args" || fail "hf-serve image: $(cat "$tmp/args")"
# etcd on the control-plane's tmpfs unless KIND_ETCD_IN_MEMORY=0
[ "$(cat "$tmp/etcd")" = 1 ] || fail "KIND_ETCD_IN_MEMORY by default: '$(cat "$tmp/etcd")'"

host; _ai_gpu=""; unset KIND_EXTRA_NODES_YAML; smi_gpu=0
out=$(KIND_ETCD_IN_MEMORY=0 cmd_test_ai 2>&1) || fail "cmd_test_ai without a GPU: $out"
echo "$out" | grep -q 'GPU case: skipped — nvidia-smi lists no GPU' || fail "skip not reported: $out"
grep -q -- '--ai-gpu' "$tmp/args" && fail "--ai-gpu without a GPU: $(cat "$tmp/args")"
[ -z "$(cat "$tmp/kind-nodes.yaml")" ] || fail "extra Kind node without a GPU"
grep -q 'gpu-caas' "$calls" && fail "gpu-caas built without a GPU"
[ "$(cat "$tmp/etcd")" = 0 ] || fail "KIND_ETCD_IN_MEMORY=0 not kept: '$(cat "$tmp/etcd")'"

host; unset KIND_EXTRA_NODES_YAML
out=$(RUNR_E2E_GPU=0 cmd_test_ai 2>&1) || fail "RUNR_E2E_GPU=0: $out"
echo "$out" | grep -q 'GPU case: off (RUNR_E2E_GPU=0)' || fail "RUNR_E2E_GPU=0: $out"
grep -q 'nvidia\|DefaultRuntime' "$calls" && fail "RUNR_E2E_GPU=0 looked at the host's GPU"
echo "ok - cmd_test_ai: GPU worker and suite flags, skipped and off, etcd in memory unless KIND_ETCD_IN_MEMORY=0"

# ── disk: the GPU run starts only where it fits ─────────────────────────────────────────────────
# 12 GiB reserve + the Compose stack 3 GiB + (1 GiB of the node's own + hf-serve 2.5 GiB) × 4 Kind
# nodes + the GPU image 0.17 GiB + the preloaded images 1.37 GiB × (4 nodes + the host) + kind's
# archive 0.5 GiB (RUNR_E2E_TMPDIR on the same filesystem) = 36.51 GiB
host
[ "$(ai_image_sizes "$AI_HF_SERVE_TAG")" = "$((GiB / 2)) $((2 * GiB))" ] || fail "hf-serve sizes: $(ai_image_sizes "$AI_HF_SERVE_TAG")"
[ "$(ai_image_sizes "$AI_GPU_IMAGE_TAG")" = "$((45 * 1048576)) $((134 * 1048576))" ] || fail "GPU image sizes: $(ai_image_sizes "$AI_GPU_IMAGE_TAG")"
host; host_free_kb=$((37 * 1024 * 1024))
[ -z "$(ai_gpu_disk_problem)" ] || fail "37 GiB free refused: $(ai_gpu_disk_problem)"
host; host_free_kb=$((36 * 1024 * 1024))
problem=$(ai_gpu_disk_problem)
[[ "$problem" == "the GPU run needs about 36.5 GiB free under /var/lib/docker, 36.0 GiB are free (12 GiB for the region's images and the rest of the run (RUNR_E2E_GPU_RESERVE_GB) + the Compose stack 3.0 GiB + (1.0 GiB of the node's own + hf-serve 2.5 GiB) × 4 Kind nodes + the GPU image 0.2 GiB on the GPU worker + the preloaded images 1.4 GiB × 4 Kind nodes and the host + kind load's archive 0.5 GiB)" ]] \
  || fail "36 GiB free: $problem"
# KIND_PRELOAD=0: no preloaded images (29.7 GiB); KIND_WORKERS=1: one node less (31.6 GiB)
[ -z "$(KIND_PRELOAD=0 ai_gpu_disk_problem)" ] || fail "KIND_PRELOAD=0 (29.7 GiB) refused at 36 GiB"
[[ "$(KIND_PRELOAD=0 ai_gpu_disk_problem)" != *preloaded* ]] || fail "KIND_PRELOAD=0 counted the preloaded images"
host; host_free_kb=$((29 * 1024 * 1024))
[[ "$(KIND_PRELOAD=0 ai_gpu_disk_problem)" == "the GPU run needs about 29.7 GiB free"*"× 4 Kind nodes + the GPU image 0.2 GiB on the GPU worker + kind load's archive 0.5 GiB)" ]] \
  || fail "KIND_PRELOAD=0 at 29 GiB: $(KIND_PRELOAD=0 ai_gpu_disk_problem)"
host; host_free_kb=$((32 * 1024 * 1024))
[ -z "$(KIND_WORKERS=1 ai_gpu_disk_problem)" ] || fail "KIND_WORKERS=1 (3 nodes, 31.6 GiB) refused at 32 GiB"
host; host_free_kb=$((36 * 1024 * 1024))
[ -z "$(RUNR_E2E_GPU_RESERVE_GB=10 ai_gpu_disk_problem)" ] || fail "RUNR_E2E_GPU_RESERVE_GB=10 not taken"
# the archive on another filesystem: not counted under Docker's root (36.01 GiB), but it has to
# fit there
host; host_free_kb=$((36 * 1024 * 1024 + 256 * 1024))
[ -n "$(ai_gpu_disk_problem)" ] || fail "36.25 GiB free taken with the archive on the same disk"
scratch_mount=/scratch scratch_free_kb=$((1024 * 1024))
[ -z "$(ai_gpu_disk_problem)" ] || fail "archive on another disk counted under Docker's root: $(ai_gpu_disk_problem)"
# another mount point of the same device (Docker's root bind-mounted): the same filesystem
scratch_mount=/var/tmp scratch_dev=/dev/sdb scratch_free_kb=$((36 * 1024 * 1024 + 256 * 1024))
[[ "$(ai_gpu_disk_problem)" == *"+ kind load's archive 0.5 GiB)" ]] || fail "same device, other mount: $(ai_gpu_disk_problem)"
host; scratch_mount=/scratch scratch_free_kb=$((200 * 1024))
[[ "$(ai_gpu_disk_problem)" == "kind load's archive takes 0.5 GiB, 0.2 GiB are free in ${RUNR_E2E_TMPDIR}: set RUNR_E2E_TMPDIR"* ]] \
  || fail "small temp dir: $(ai_gpu_disk_problem)"

# Measured: hf-serve 1.1.1 (0.5 GiB archive, 1.7 GiB unpacked), the 43 MB GPU image, 3 Kind nodes
# without the preload. That run had used 20.3 GiB of the disk by its first AI case, with region
# setup, the other AI cases, GPU support and the GPU case still ahead; it started with 23 GiB
# free under Docker's root. It needs 25.3 GiB now and is refused there.
host; host_free_kb=$((23 * 1024 * 1024))
img_hf_archive=$((GiB / 2)) img_hf_layers=($((17 * GiB / 10)))
img_gpu_archive=43276184 img_gpu_layers=($((128 * 1048576)))
problem=$(KIND_WORKERS=1 KIND_PRELOAD=0 ai_gpu_disk_problem)
[[ "$problem" == "the GPU run needs about 25.3 GiB free under /var/lib/docker, 23.0 GiB are free ("*"(1.0 GiB of the node's own + hf-serve 2.2 GiB) × 3 Kind nodes"* ]] \
  || fail "measured run at 23 GiB free: $problem"
host_free_kb=$((26 * 1024 * 1024))
[ -z "$(KIND_WORKERS=1 KIND_PRELOAD=0 ai_gpu_disk_problem)" ] || fail "measured run refused at 26 GiB free"

# cmd_test_ai refuses to start: no cluster, the GPU image removed again
host; unset KIND_EXTRA_NODES_YAML; host_free_kb=$((15 * 1024 * 1024))
out=$( (cmd_test_ai) 2>&1) && fail "a GPU run that does not fit the disk started: $out"
echo "$out" | grep -q '✗ not started: the GPU run needs about 36.5 GiB free under /var/lib/docker, 15.0 GiB are free' || fail "refusal: $out"
echo "$out" | grep -q 'KIND_WORKERS=1 KIND_PRELOAD=0' || fail "smaller run not named: $out"
echo "$out" | grep -q 'RUNR_E2E_GPU=0 runtime/ci.sh test-ai' || fail "no way out named: $out"
grep -q '^cmd_test' "$calls" && fail "cmd_test called on a full disk"
grep -q "^docker rmi ${AI_GPU_IMAGE_TAG}" "$calls" || fail "the GPU image stayed after the refusal"
# the CPU cases alone are not checked
host; unset KIND_EXTRA_NODES_YAML; host_free_kb=$((15 * 1024 * 1024))
out=$(RUNR_E2E_GPU=0 cmd_test_ai 2>&1) || fail "RUNR_E2E_GPU=0 on a small disk: $out"
grep -q '^cmd_test --suite=ai' "$calls" || fail "RUNR_E2E_GPU=0: cmd_test not called"

# RUNR_E2E_TMPDIR: must be a writable directory; unset, /var/tmp or $TMPDIR
host
out=$( (RUNR_E2E_TMPDIR="$tmp/missing" cmd_test_ai) 2>&1) && fail "a missing RUNR_E2E_TMPDIR passed: $out"
echo "$out" | grep -q "RUNR_E2E_TMPDIR=$tmp/missing is not a writable directory" || fail "missing RUNR_E2E_TMPDIR: $out"
grep -q '^docker build' "$calls" && fail "built with a missing RUNR_E2E_TMPDIR"
default_tmp=$(RUNR_E2E_TMPDIR="" ai_kind_tmpdir)
if [ -d /var/tmp ] && [ -w /var/tmp ]; then want_tmp=/var/tmp; else want_tmp="${TMPDIR:-/tmp}"; fi
[ "$default_tmp" = "$want_tmp" ] || fail "default temp dir: $default_tmp"
echo "ok - disk check from the images' sizes, RUNR_E2E_TMPDIR"

# ── GPU nodes: only where the driver works ──────────────────────────────────────────────────────
host; _ai_gpu=1
TEST_E2E_EXTRA_ARGS="--hf-serve-image=x --ai-gpu --gpu-image=${AI_GPU_IMAGE_TAG}"
out=$(ai_after_kind_create ai 2>&1; echo "ARGS=$TEST_E2E_EXTRA_ARGS") || fail "after create: $out"
echo "$out" | grep -q '✓ GPU node ai-worker3' || fail "GPU node not prepared: $out"
[ "$(echo "$out" | grep -c '✓ GPU node')" = 1 ] || fail "nodes without the driver became GPU nodes: $out"
echo "$out" | grep -q 'ARGS=.*--ai-gpu .* --gpu-node=ai-worker3$' || fail "suite args: $out"
grep -q '^kubectl --context kind-ai label node ai-worker3 nvidia.com/gpu.present=true' "$calls" || fail "ai-worker3 not labelled"
[ "$(grep -c 'label node' "$calls")" = 1 ] || fail "other nodes labelled: $(grep 'label node' "$calls")"
[ "$(grep -c '^docker exec .* bash -ceu' "$calls")" = 1 ] || fail "toolkit installed on more than the GPU node"
grep -q '^docker exec ai-worker3 bash -ceu' "$calls" || fail "toolkit not installed on ai-worker3"
# hf-serve into every node, the GPU image into the GPU node only, kind's archive in RUNR_E2E_TMPDIR,
# the host's copy of the GPU image removed after the load
grep -qx "kind load docker-image ${AI_HF_SERVE_TAG} --name ai TMPDIR=${RUNR_E2E_TMPDIR}" "$calls" || fail "hf-serve load: $(grep '^kind load' "$calls")"
grep -qx "kind load docker-image ${AI_GPU_IMAGE_TAG} --name ai --nodes ai-worker3 TMPDIR=${RUNR_E2E_TMPDIR}" "$calls" || fail "GPU image load: $(grep '^kind load' "$calls")"
[ "$(grep -c "^kind load docker-image ${AI_GPU_IMAGE_TAG}" "$calls")" = 1 ] || fail "GPU image loaded more than once"
load_at=$(grep -n "^kind load docker-image ${AI_GPU_IMAGE_TAG}" "$calls" | cut -d: -f1)
rmi_at=$(grep -n "^docker rmi ${AI_GPU_IMAGE_TAG}" "$calls" | cut -d: -f1)
[ -n "$rmi_at" ] && [ "$rmi_at" -gt "$load_at" ] || fail "the host's copy not removed after the load: $(cat "$calls")"
prepare_at=$(grep -n '^docker exec ai-worker3 bash -ceu' "$calls" | cut -d: -f1)
[ "$load_at" -gt "$prepare_at" ] || fail "GPU image loaded before the GPU node was prepared"

# two GPU nodes: one load into both
host; _ai_gpu=1; driver_nodes="ai-worker2 ai-worker3"
TEST_E2E_EXTRA_ARGS="--hf-serve-image=x --ai-gpu --gpu-image=${AI_GPU_IMAGE_TAG}"
out=$(ai_after_kind_create ai 2>&1) || fail "after create, two GPU nodes: $out"
grep -qx "kind load docker-image ${AI_GPU_IMAGE_TAG} --name ai --nodes ai-worker2,ai-worker3 TMPDIR=${RUNR_E2E_TMPDIR}" "$calls" || fail "two GPU nodes: $(grep '^kind load' "$calls")"

host; _ai_gpu=1; driver_nodes=""
TEST_E2E_EXTRA_ARGS="--hf-serve-image=x --ai-gpu --gpu-image=${AI_GPU_IMAGE_TAG}"
out=$(ai_after_kind_create ai 2>&1; echo "ARGS=$TEST_E2E_EXTRA_ARGS") || fail "after create without a driver: $out"
echo "$out" | grep -q 'No Kind node with a working NVIDIA driver' || fail "no skip message: $out"
echo "$out" | grep -q 'ARGS=.*--ai-gpu' && fail "--ai-gpu kept without a GPU node: $out"
grep -q 'label node\|bash -ceu' "$calls" && fail "a node without the driver was prepared"
grep -q "^kind load docker-image ${AI_GPU_IMAGE_TAG}" "$calls" && fail "GPU image loaded without a GPU node"
grep -q "^docker rmi ${AI_GPU_IMAGE_TAG}" "$calls" || fail "GPU image kept on the host without a GPU node"
driver_nodes="ai-worker3"
echo "ok - only Kind nodes with a working driver become GPU nodes and get the GPU image"

echo "ok: ai-gpu"
