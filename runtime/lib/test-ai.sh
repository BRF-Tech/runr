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
# (cmd_test waits for the lock, see test-runtimes.sh).
#
# GPU path. The host is never reconfigured; when any of these is not met the GPU case is skipped
# with the reason printed (RUNR_E2E_GPU=0 skips it always):
#   - an NVIDIA driver on the host: `nvidia-smi -L` lists a GPU
#   - the NVIDIA Container Toolkit with nvidia as Docker's default runtime
#     (`docker info` → Default Runtime: nvidia)
#   - /etc/nvidia-container-runtime/config.toml: accept-nvidia-visible-devices-as-volume-mounts = true
#   - free space under Docker's root dir for the CUDA PyTorch image of examples/ai/gpu-caas
#     (RUNR_E2E_GPU_MIN_FREE_GB, default 20)
# Then the Kind cluster gets a third worker that sees the host's GPUs (extraMounts
# /dev/null → /var/run/nvidia-container-devices/all, the approach of NVIDIA's nvkind). Inside that
# Kind node — not on the host — the NVIDIA Container Toolkit is installed and set as containerd's
# default runtime, the node is labelled nvidia.com/gpu.present=true and named to the suite
# (--gpu-node: GPU support restricted to it, so Kind workers without the GPU never get the
# device plugin although they share the host's PCI view), and the suite installs GPU
# support (device plugin) through the RunR API and deploys the gpu-caas image with
# config.gpu {count: 1}; the check runs a tiny network, so any CUDA-capable GPU will do.

AI_HF_SERVE_VERSION="${HF_SERVE_VERSION:-1.1.0}"
AI_HF_SERVE_TAG="${REGISTRY}/hf-serve:${AI_HF_SERVE_VERSION}"
AI_HF_SERVE_DEFAULT_TAG="ghcr.io/brf-tech/runr/hf-serve:${AI_HF_SERVE_VERSION}"
AI_GPU_IMAGE_TAG="${REGISTRY}/ai-gpu-caas:e2e"

_ai_gpu=""

# Empty when the host can give a Kind node an NVIDIA GPU, otherwise the reason it cannot.
ai_gpu_unavailable_reason() {
  if ! command -v nvidia-smi >/dev/null 2>&1; then
    echo "nvidia-smi not found on the host"; return
  fi
  if ! nvidia-smi -L 2>/dev/null | grep -q '^GPU '; then
    echo "nvidia-smi lists no GPU"; return
  fi

  local runtime
  runtime=$(docker info --format '{{.DefaultRuntime}}' 2>/dev/null || true)
  if [ "$runtime" != "nvidia" ]; then
    echo "Docker's default runtime is '${runtime:-unknown}', not nvidia (the e2e does not change it)"; return
  fi

  local cfg=/etc/nvidia-container-runtime/config.toml
  if ! grep -Eq '^[[:space:]]*accept-nvidia-visible-devices-as-volume-mounts[[:space:]]*=[[:space:]]*true' "$cfg" 2>/dev/null; then
    echo "${cfg}: accept-nvidia-visible-devices-as-volume-mounts is not true (the e2e does not change it)"; return
  fi

  local root free_kb need_gb="${RUNR_E2E_GPU_MIN_FREE_GB:-20}"
  root=$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || echo /var/lib/docker)
  free_kb=$(df -Pk "$root" 2>/dev/null | awk 'NR==2 {print $4}' || true)
  if [ -n "$free_kb" ] && [ "$free_kb" -lt $((need_gb * 1024 * 1024)) ]; then
    echo "$((free_kb / 1024 / 1024)) GiB free under ${root}, ${need_gb} GiB wanted for the CUDA image"; return
  fi

  echo ""
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
  kind load docker-image "$AI_HF_SERVE_TAG" --name "$cluster" >/dev/null
  if [ "$AI_HF_SERVE_TAG" != "$AI_HF_SERVE_DEFAULT_TAG" ]; then
    # mirror Jobs and receivers use the configured default image
    kind load docker-image "$AI_HF_SERVE_DEFAULT_TAG" --name "$cluster" >/dev/null
  fi
  echo "  ${AI_HF_SERVE_TAG}"

  [ "$_ai_gpu" = "1" ] || return 0

  kind load docker-image "$AI_GPU_IMAGE_TAG" --name "$cluster" >/dev/null
  echo "  ${AI_GPU_IMAGE_TAG}"

  local node prepared=0
  for node in $(kind get nodes --name "$cluster"); do
    if docker exec "$node" sh -c 'ls /dev/nvidia0' >/dev/null 2>&1; then
      if ai_prepare_kind_gpu_node "$cluster" "$node"; then
        prepared=1
        # GPU support is restricted to these nodes (restrict_to_gpu_nodes + gpu_nodes)
        TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS} --gpu-node=${node}"
        export TEST_E2E_EXTRA_ARGS
        echo -e "  ${GREEN}✓ GPU node ${node}${NC}"
      fi
    fi
  done

  if [ "$prepared" != "1" ]; then
    echo -e "${YELLOW}  No Kind node sees /dev/nvidia0 after setup: the GPU case is skipped.${NC}"
    TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS//--ai-gpu/}"
    export TEST_E2E_EXTRA_ARGS
  fi
}

cmd_test_ai() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"

  local external=0 arg
  for arg in "$@"; do
    case "$arg" in
      --kubeconfig=*) external=1 ;;
    esac
  done

  echo -e "${BOLD}AI e2e — model services, offline snapshots, bindings, GPU${NC}"
  echo "  hf-serve image: ${AI_HF_SERVE_TAG} (building runtime/hf-serve, CPU)"
  docker build -q --pull -t "$AI_HF_SERVE_TAG" "${SCRIPT_DIR}/hf-serve" >/dev/null
  if [ "$AI_HF_SERVE_TAG" != "$AI_HF_SERVE_DEFAULT_TAG" ]; then
    docker tag "$AI_HF_SERVE_TAG" "$AI_HF_SERVE_DEFAULT_TAG"
  fi

  local extra="--hf-serve-image=${AI_HF_SERVE_TAG}"
  if [ "${RUNR_E2E_TEI:-0}" = "1" ]; then
    extra="${extra} --ai-tei"
  fi

  local gpu="${RUNR_E2E_GPU:-auto}" reason=""
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
      echo "  GPU case: on — building ${AI_GPU_IMAGE_TAG} (examples/ai/gpu-caas)"
      docker build -q --pull -t "$AI_GPU_IMAGE_TAG" "${SCRIPT_DIR}/../examples/ai/gpu-caas" >/dev/null
      export KIND_EXTRA_NODES_YAML='  - role: worker
    extraMounts:
      - hostPath: /dev/null
        containerPath: /var/run/nvidia-container-devices/all'
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
