# AI e2e: model services (online / offline), offline snapshots, bindings and — when the host can
# give Kind a GPU — GPU support and a GPU CaaS project.
#
# cmd_test_ai — `ci.sh test-ai [cmd_test flags]`: builds the hf-serve CPU image (runtime/hf-serve)
#               and loads it into the Kind cluster, then runs `cmd_test --suite=ai`
#               (api: php artisan test:e2e --suite=ai, E2eTestAiTrait):
#                 ai-model, ai-online-cpu, ai-mirror, ai-offline-cpu, ai-binding
#                 [ai-tei-cpu]  RUNR_E2E_TEI=1
#                 [ai-gpu-caas] RUNR_E2E_GPU=auto (default) | 1 | 0
#
# Prerequisites: what `ci.sh test` needs (docker, kind, kubectl, the compose stack) plus internet
# access from the Kind nodes: the CPU cases download hf-internal-testing/tiny-random-bert (a few
# MB) from huggingface.co, the TEI case pulls ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4.
#
# Shared hosts: KIND_LOCK defaults to /tmp/runr-kind.lock here — one Kind cluster at a time
# (cmd_test waits for the lock, see test-runtimes.sh). KIND_ETCD_IN_MEMORY defaults to 1 here: etcd
# keeps its data on the control-plane's tmpfs, so the Kubernetes API answers in time on a disk
# that other jobs keep busy (the region setup's Helm installs failed with "etcdserver: request
# timed out" on such a host); KIND_ETCD_IN_MEMORY=0 puts it on the disk. `kind load` writes its
# temporary image archive to RUNR_E2E_TMPDIR (default /var/tmp, on disk: /tmp is a RAM-backed
# tmpfs on some hosts), else to $TMPDIR.
#
# GPU path. The host is never reconfigured; when any of these is not met the GPU case is skipped
# with the reason printed (RUNR_E2E_GPU=0 skips it always):
#   - an NVIDIA driver on the host: `nvidia-smi -L` lists a GPU
#   - the NVIDIA Container Toolkit, used in one of two ways (ai_gpu_route):
#       runtime        where Docker's default runtime is nvidia (`docker info` → Default Runtime:
#                      nvidia) and /etc/nvidia-container-runtime/config.toml has
#                      accept-nvidia-visible-devices-as-volume-mounts = true
#       driver-mounts  otherwise: `nvidia-container-cli list` names the driver's files and the host
#                      has the NVIDIA device nodes (/dev/nvidiactl); Docker's default runtime stays
#                      as it is
# Then the GPU check image is built (examples/ai/gpu-caas: Python's slim image and a CUDA kernel
# run through the driver API, no CUDA toolkit), and the run refuses to start when the GPU run would
# not fit the disk (ai_gpu_disk_problem): under Docker's root dir it needs RUNR_E2E_GPU_RESERVE_GB
# (default 12) for the images the Kind nodes pull for the region (registry, Knative, Redis,
# tracing, GPU support) and the rest of the run, the Compose stack, every Kind node's own data,
# the built images per Kind node that gets them (hf-serve: every node; the GPU image: the GPU
# worker only), the preloaded images on every node and the host unless KIND_PRELOAD=0, plus
# `kind load`'s temporary archive. RUNR_E2E_GPU=0 runs the CPU cases without the GPU worker.
# The Kind cluster gets a third worker with the host's GPU. runtime: an extraMount
# /dev/null → /var/run/nvidia-container-devices/all, the approach of NVIDIA's nvkind, from which
# the NVIDIA runtime injects the driver. driver-mounts: the files `nvidia-container-cli list`
# names (what the NVIDIA runtime puts into a GPU container), bind-mounted read-only at the same
# paths (Kind extraMounts); Kind nodes are privileged and see the host's /dev/nvidia* already.
# A Kind node counts as a GPU node when the driver works in it
# (`nvidia-smi -L` lists a GPU there): every privileged Kind node sees the device nodes, only the
# third worker has the driver. Inside that Kind node — not on the host — the NVIDIA Container
# Toolkit is installed and set as containerd's default runtime, the node is labelled
# nvidia.com/gpu.present=true and named to the suite (--gpu-node: GPU support restricted to it, so
# Kind workers without the GPU never get the device plugin although they share the host's PCI
# view), and the suite installs GPU support (device plugin) through the RunR API and deploys the
# gpu-caas image with config.gpu {count: 1}; the check runs a small CUDA kernel (PTX the driver
# compiles for its GPU), so any NVIDIA GPU from compute capability 5.0 on will do. The GPU image
# goes into the GPU node(s) only, and the host's copy is removed once they have it. The driver
# files are those of the host when the cluster is created; each run creates its own cluster.

AI_HF_SERVE_VERSION="${HF_SERVE_VERSION:-1.1.1}"
AI_HF_SERVE_TAG="${REGISTRY}/hf-serve:${AI_HF_SERVE_VERSION}"
AI_HF_SERVE_DEFAULT_TAG="ghcr.io/brf-tech/runr/hf-serve:${AI_HF_SERVE_VERSION}"
AI_GPU_IMAGE_TAG="${REGISTRY}/ai-gpu-caas:e2e"
# The NVIDIA runtime's configuration and the device node the driver-mounts route needs on the host
AI_NVIDIA_RUNTIME_CONFIG="${AI_NVIDIA_RUNTIME_CONFIG:-/etc/nvidia-container-runtime/config.toml}"
AI_NVIDIA_DEVICE="${AI_NVIDIA_DEVICE:-/dev/nvidiactl}"

_ai_gpu=""

# The NVIDIA driver's files the NVIDIA runtime puts into a GPU container (libraries, binaries,
# firmware), one per line, as `nvidia-container-cli list` names them; the device nodes left out.
ai_gpu_driver_files() {
  command -v nvidia-container-cli >/dev/null 2>&1 || return 0
  nvidia-container-cli list 2>/dev/null | grep -v -e '^/dev/' -e '^[[:space:]]*$' || true
}

# How a Kind node gets the host's GPU: "runtime" when Docker's default runtime is the NVIDIA
# runtime and takes device requests as volume mounts, else "driver-mounts" when the driver's files
# and device nodes are there; empty when neither works.
ai_gpu_route() {
  local runtime
  runtime=$(docker info --format '{{.DefaultRuntime}}' 2>/dev/null || true)
  if [ "$runtime" = "nvidia" ] && grep -Eq \
    '^[[:space:]]*accept-nvidia-visible-devices-as-volume-mounts[[:space:]]*=[[:space:]]*true' \
    "$AI_NVIDIA_RUNTIME_CONFIG" 2>/dev/null; then
    echo "runtime"
  elif [ -e "$AI_NVIDIA_DEVICE" ] && [ -n "$(ai_gpu_driver_files)" ]; then
    echo "driver-mounts"
  fi
}

# Empty when the host can give a Kind node an NVIDIA GPU, otherwise the reason it cannot.
ai_gpu_unavailable_reason() {
  if ! command -v nvidia-smi >/dev/null 2>&1; then
    echo "nvidia-smi not found on the host"; return
  fi
  if ! nvidia-smi -L 2>/dev/null | grep -q '^GPU '; then
    echo "nvidia-smi lists no GPU"; return
  fi

  if [ -z "$(ai_gpu_route)" ]; then
    if ! command -v nvidia-container-cli >/dev/null 2>&1; then
      echo "nvidia-container-cli not found (NVIDIA Container Toolkit)"; return
    fi
    if [ ! -e "$AI_NVIDIA_DEVICE" ]; then
      echo "no ${AI_NVIDIA_DEVICE} on the host (the Kind node uses the host's NVIDIA device nodes)"; return
    fi
    echo "nvidia-container-cli list names no driver files"; return
  fi

  echo ""
}

# Where `kind load` writes its temporary image archive: RUNR_E2E_TMPDIR (fails unless it is a
# writable directory), else /var/tmp, which is on disk where /tmp may be a RAM-backed tmpfs, else
# $TMPDIR
ai_kind_tmpdir() {
  if [ -n "${RUNR_E2E_TMPDIR:-}" ]; then
    [ -d "$RUNR_E2E_TMPDIR" ] && [ -w "$RUNR_E2E_TMPDIR" ] || return 1
    echo "$RUNR_E2E_TMPDIR"
  elif [ -d /var/tmp ] && [ -w /var/tmp ]; then
    echo /var/tmp
  else
    echo "${TMPDIR:-/tmp}"
  fi
}

# ai_kind_load <cluster> <image> [node,...] — the image into the Kind nodes (all of them, or the
# ones named), kind's archive in ai_kind_tmpdir
ai_kind_load() {
  local cluster="$1" image="$2" nodes="${3:-}" tmpdir args
  tmpdir=$(ai_kind_tmpdir) || return 1
  args=(docker-image "$image" --name "$cluster")
  [ -z "$nodes" ] || args+=(--nodes "$nodes")
  TMPDIR="$tmpdir" kind load "${args[@]}" >/dev/null
}

# ai_image_sizes <image> — "<archive> <unpacked>" in bytes: what `docker save` writes for the
# image (docker image inspect's Size: the unpacked layers with Docker's classic image store, the
# compressed content with the containerd image store) and its layers unpacked (the sum of
# docker image history). A Kind node keeps both after `kind load`.
ai_image_sizes() {
  local archive unpacked
  archive=$(docker image inspect -f '{{.Size}}' "$1" 2>/dev/null || true)
  unpacked=$(docker image history --human=false --format '{{.Size}}' "$1" 2>/dev/null \
    | awk '{ s += $1 } END { printf "%d\n", s }')
  echo "${archive:-0} ${unpacked:-0}"
}

ai_gib() { awk -v b="$1" 'BEGIN { printf "%.1f", b / 1073741824 }'; }

# ai_df <path> — "<free KiB> <mount point> <source>" of the filesystem of <path>
ai_df() { df -Pk "$1" 2>/dev/null | awk 'NR==2 {print $4, $6, $1}'; }

# What a GPU run writes under Docker's root besides the suite's own images, in MiB, measured with
# `ci.sh test-ai` on a GPU host (3 and 4 Kind nodes, free space sampled every 5 s):
#   AI_DISK_NODE_MIB     a Kind node's own data before anything is loaded into it: the node image's
#                        /var (Kubernetes' images) copied into the node's volume, 0.7–1.2 GiB
#   AI_DISK_COMPOSE_MIB  the Compose stack: the API and vendor images, their base images, build cache
#   AI_DISK_PRELOAD_MIB  the images preload_kind_images loads (0.35 GiB compressed): a node keeps the
#                        compressed and the unpacked layers, the host keeps what it pulled
AI_DISK_NODE_MIB=1024
AI_DISK_COMPOSE_MIB=3072
AI_DISK_PRELOAD_MIB=1400

# Empty when the GPU run fits the disk, otherwise why not. Under Docker's root dir:
# RUNR_E2E_GPU_RESERVE_GB (default 12) for the images the Kind nodes pull for the region and the
# rest of the run, the Compose stack, and on every Kind node (control-plane, KIND_WORKERS, the GPU
# worker) its own data and hf-serve's archive + unpacked layers (from the built image's sizes),
# the GPU image on the GPU worker, and unless KIND_PRELOAD=0 the preloaded images on every node
# and the host. `kind load`'s temporary archive (the larger image's) adds to that on the same
# filesystem, or needs that much free in ai_kind_tmpdir on another one.
ai_gpu_disk_problem() {
  local reserve_gb="${RUNR_E2E_GPU_RESERVE_GB:-12}" nodes=$((1 + ${KIND_WORKERS:-2} + 1)) mib=1048576
  local hf_archive hf_unpacked gpu_archive gpu_unpacked hf gpu archive need root tmpdir
  local node=$((AI_DISK_NODE_MIB * mib)) compose=$((AI_DISK_COMPOSE_MIB * mib)) preload=$((AI_DISK_PRELOAD_MIB * mib))
  local root_free="" root_mount="" root_source="" tmp_free="" tmp_mount="" tmp_source="" detail
  read -r hf_archive hf_unpacked < <(ai_image_sizes "$AI_HF_SERVE_TAG")
  read -r gpu_archive gpu_unpacked < <(ai_image_sizes "$AI_GPU_IMAGE_TAG")
  hf=$((hf_archive + hf_unpacked))
  gpu=$((gpu_archive + gpu_unpacked))
  archive=$((hf_archive > gpu_archive ? hf_archive : gpu_archive))
  need=$((reserve_gb * 1073741824 + compose + (node + hf) * nodes + gpu))
  detail="${reserve_gb} GiB for the region's images and the rest of the run (RUNR_E2E_GPU_RESERVE_GB) + the Compose stack $(ai_gib "$compose") GiB + ($(ai_gib "$node") GiB of the node's own + hf-serve $(ai_gib "$hf") GiB) × ${nodes} Kind nodes + the GPU image $(ai_gib "$gpu") GiB on the GPU worker"
  if [ "${KIND_PRELOAD:-1}" != "0" ]; then
    need=$((need + preload * (nodes + 1)))
    detail="${detail} + the preloaded images $(ai_gib "$preload") GiB × ${nodes} Kind nodes and the host"
  fi

  root=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || echo /var/lib/docker)
  tmpdir=$(ai_kind_tmpdir) || tmpdir="${TMPDIR:-/tmp}"
  read -r root_free root_mount root_source < <(ai_df "$root") || true
  read -r tmp_free tmp_mount tmp_source < <(ai_df "$tmpdir") || true
  # the same filesystem: the same mount, or the same block device (Docker's root bind-mounted)
  if [ -n "$tmp_mount" ] && { [ "$tmp_mount" = "$root_mount" ] \
    || { [[ "$tmp_source" == /dev/* ]] && [ "$tmp_source" = "$root_source" ]; }; }; then
    need=$((need + archive))
    detail="${detail} + kind load's archive $(ai_gib "$archive") GiB"
  elif [ -n "$tmp_free" ] && [ $((tmp_free * 1024)) -lt "$archive" ]; then
    echo "kind load's archive takes $(ai_gib "$archive") GiB, $(ai_gib $((tmp_free * 1024))) GiB are free in ${tmpdir}: set RUNR_E2E_TMPDIR to a directory on a disk with room"
    return
  fi
  if [ -n "$root_free" ] && [ $((root_free * 1024)) -lt "$need" ]; then
    echo "the GPU run needs about $(ai_gib "$need") GiB free under ${root}, $(ai_gib $((root_free * 1024))) GiB are free (${detail})"
    return
  fi
  echo ""
}

# ai_gpu_kind_node_yaml <route> — the Kind worker with the GPU (KIND_EXTRA_NODES_YAML)
ai_gpu_kind_node_yaml() {
  local route="$1" file
  echo '  - role: worker'
  echo '    extraMounts:'
  if [ "$route" = "runtime" ]; then
    echo '      - hostPath: /dev/null'
    echo '        containerPath: /var/run/nvidia-container-devices/all'
    return 0
  fi
  while IFS= read -r file; do
    echo "      - hostPath: \"${file}\""
    echo "        containerPath: \"${file}\""
    echo '        readOnly: true'
  done < <(ai_gpu_driver_files)
}

# A Kind node with a working NVIDIA driver: nvidia-smi lists a GPU in it. ldconfig first links
# mounted driver libraries by their sonames (libnvidia-ml.so.1, libcuda.so.1).
ai_kind_node_has_gpu() {
  docker exec "$1" sh -c 'command -v nvidia-smi >/dev/null 2>&1 && ldconfig && nvidia-smi -L' 2>/dev/null \
    | grep -q '^GPU '
}

# Inside the Kind node only: NVIDIA Container Toolkit as containerd's default runtime.
ai_prepare_kind_gpu_node() {
  local cluster="$1" node="$2"
  echo "  ${node}: NVIDIA Container Toolkit inside the Kind node (the host stays as it is)..."
  docker exec "$node" bash -ceu '
    umount -R /proc/driver/nvidia 2>/dev/null || true
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends curl gpg ca-certificates >/dev/null
    curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey \
      | gpg --dearmor --yes -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
    curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list \
      | sed "s#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g" \
      > /etc/apt/sources.list.d/nvidia-container-toolkit.list
    apt-get update -qq
    apt-get install -y -qq nvidia-container-toolkit >/dev/null
    nvidia-ctk runtime configure --runtime=containerd --set-as-default
    systemctl restart containerd
  ' || return 1

  kubectl --context "kind-${cluster}" wait --for=condition=Ready "node/${node}" --timeout=180s >/dev/null || return 1
  kubectl --context "kind-${cluster}" label node "$node" nvidia.com/gpu.present=true --overwrite >/dev/null
}

# TEST_AFTER_KIND_CREATE hook of cmd_test: images into the cluster, the GPU node ready.
ai_after_kind_create() {
  local cluster="$1"
  echo ""
  echo "AI e2e: images into kind/${cluster}..."
  ai_kind_load "$cluster" "$AI_HF_SERVE_TAG"
  if [ "$AI_HF_SERVE_TAG" != "$AI_HF_SERVE_DEFAULT_TAG" ]; then
    # mirror Jobs and receivers use the configured default image
    ai_kind_load "$cluster" "$AI_HF_SERVE_DEFAULT_TAG"
  fi
  echo "  ${AI_HF_SERVE_TAG}"

  [ "$_ai_gpu" = "1" ] || return 0

  local node gpu_nodes=()
  for node in $(kind get nodes --name "$cluster"); do
    ai_kind_node_has_gpu "$node" || continue
    if ai_prepare_kind_gpu_node "$cluster" "$node"; then
      gpu_nodes+=("$node")
      # GPU support is restricted to these nodes (restrict_to_gpu_nodes + gpu_nodes)
      TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS} --gpu-node=${node}"
      export TEST_E2E_EXTRA_ARGS
      echo -e "  ${GREEN}✓ GPU node ${node}${NC}"
    fi
  done

  if [ "${#gpu_nodes[@]}" -eq 0 ]; then
    echo -e "${YELLOW}  No Kind node with a working NVIDIA driver after setup: the GPU case is skipped.${NC}"
    TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS//--ai-gpu/}"
    export TEST_E2E_EXTRA_ARGS
    docker rmi "$AI_GPU_IMAGE_TAG" >/dev/null 2>&1 || true
    return 0
  fi

  # The GPU image into the GPU nodes only: the project asks for nvidia.com/gpu and runs there. The
  # host's copy is not needed after that.
  local targets
  targets=$(IFS=,; echo "${gpu_nodes[*]}")
  ai_kind_load "$cluster" "$AI_GPU_IMAGE_TAG" "$targets"
  docker rmi "$AI_GPU_IMAGE_TAG" >/dev/null 2>&1 || echo "  (docker rmi ${AI_GPU_IMAGE_TAG} failed: the host's copy stays)"
  echo "  ${AI_GPU_IMAGE_TAG} → ${targets}"
}

cmd_test_ai() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  # etcd on the control-plane's tmpfs unless KIND_ETCD_IN_MEMORY=0 (see the top of this file)
  export KIND_ETCD_IN_MEMORY="${KIND_ETCD_IN_MEMORY:-1}"

  local external=0 arg
  for arg in "$@"; do
    case "$arg" in
      --kubeconfig=*) external=1 ;;
    esac
  done

  echo -e "${BOLD}AI e2e — model services, offline snapshots, bindings, GPU${NC}"
  if [ "$external" = "0" ] && ! ai_kind_tmpdir >/dev/null; then
    echo -e "${RED}  RUNR_E2E_TMPDIR=${RUNR_E2E_TMPDIR} is not a writable directory (kind load's temporary archive)${NC}"
    return 1
  fi
  echo "  hf-serve image: ${AI_HF_SERVE_TAG} (building runtime/hf-serve, CPU)"
  docker build -q --pull -t "$AI_HF_SERVE_TAG" "${SCRIPT_DIR}/hf-serve" >/dev/null
  if [ "$AI_HF_SERVE_TAG" != "$AI_HF_SERVE_DEFAULT_TAG" ]; then
    docker tag "$AI_HF_SERVE_TAG" "$AI_HF_SERVE_DEFAULT_TAG"
  fi

  local extra="--hf-serve-image=${AI_HF_SERVE_TAG}"
  if [ "${RUNR_E2E_TEI:-0}" = "1" ]; then
    extra="${extra} --ai-tei"
  fi

  local gpu="${RUNR_E2E_GPU:-auto}" reason="" route="" problem=""
  if [ "$gpu" = "0" ]; then
    echo "  GPU case: off (RUNR_E2E_GPU=0)"
  elif [ "$external" = "1" ]; then
    # External cluster: its GPU nodes are its own business; only on explicit request
    if [ "$gpu" = "1" ]; then
      extra="${extra} --ai-gpu --gpu-image=${RUNR_E2E_GPU_IMAGE:-${AI_GPU_IMAGE_TAG}}"
      echo "  GPU case: on (external cluster, image ${RUNR_E2E_GPU_IMAGE:-${AI_GPU_IMAGE_TAG}} must be pullable there)"
    else
      echo "  GPU case: skipped on an external cluster unless RUNR_E2E_GPU=1"
    fi
  else
    reason=$(ai_gpu_unavailable_reason)
    if [ -z "$reason" ]; then
      route=$(ai_gpu_route)
      echo "  GPU case: on (Kind GPU worker: ${route}) — building ${AI_GPU_IMAGE_TAG} (examples/ai/gpu-caas)"
      docker build -q --pull -t "$AI_GPU_IMAGE_TAG" "${SCRIPT_DIR}/../examples/ai/gpu-caas" >/dev/null
      problem=$(ai_gpu_disk_problem)
      if [ -n "$problem" ]; then
        docker rmi "$AI_GPU_IMAGE_TAG" >/dev/null 2>&1 || true
        echo -e "  ${RED}✗ not started: ${problem}${NC}"
        echo "  Free disk space, run fewer Kind nodes without the preload (KIND_WORKERS=1 KIND_PRELOAD=0),"
        echo "  or run the CPU cases only: RUNR_E2E_GPU=0 runtime/ci.sh test-ai"
        return 1
      fi
      KIND_EXTRA_NODES_YAML="$(ai_gpu_kind_node_yaml "$route")"
      export KIND_EXTRA_NODES_YAML
      extra="${extra} --ai-gpu --gpu-image=${AI_GPU_IMAGE_TAG}"
      _ai_gpu=1
    else
      echo -e "  ${YELLOW}GPU case: skipped — ${reason}${NC}"
    fi
  fi

  export TEST_E2E_EXTRA_ARGS="$extra"
  export TEST_AFTER_KIND_CREATE=ai_after_kind_create

  cmd_test --suite=ai "$@"
}
