# cmd_test_image — Build + push CaaS test image (nginx-test)
# cmd_test     — Kind cluster + Docker Compose e2e test

cmd_test_image() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local images_dir="${SCRIPT_DIR}/nginx-test"
  local tag="${REGISTRY}/nginx-test:latest"

  if [ ! -d "$images_dir" ]; then
    echo -e "${RED}nginx-test directory not found: ${images_dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── nginx-test (CaaS test image) ──${NC}"

  echo "==> Building ${tag}"
  do_build "${tag}" "${images_dir}" "$push"

  if [ "$push" = "1" ]; then
    cleanup_buildx
  fi

  echo ""
  echo -e "${GREEN}Done.${NC}"
  if [ "$push" = "0" ]; then
    echo "To push: ci.sh test-image --push"
  fi
}

# Cleanup state (module-level so trap handler can access). Each is set before the step that
# creates the resource, so a run stopped during that step removes it as well — and only what this
# run created: its Kind cluster (named once it holds the Kind lock), its Compose project, the lock
# when it holds it, the shared Cloud Provider KIND unless another Kind cluster uses it.
_test_cloud_provider=""
_test_kubeconfig_file=""
_test_cleanup_kind=""
_test_cluster_name=""
_test_compose_up=""
_test_e2e_kubeconfig=""

# The EXIT trap of cmd_test: at the end of the run, and when it is stopped from outside (INT,
# TERM, HUP: see kind_cleanup_begin in test-runtimes.sh)
_test_cleanup() {
  kind_cleanup_begin "${_test_cluster_name:-runr-e2e-$$}"
  echo "Cleanup..."
  # stops it unless another run's Kind cluster still uses it
  [ -n "$_test_cloud_provider" ] && stop_cloud_provider_kind "$_test_cluster_name" || true
  if type cleanup_git_deploy_e2e >/dev/null 2>&1; then
    cleanup_git_deploy_e2e
  fi
  if [ "$_test_compose_up" = "1" ]; then
    echo "Stopping Docker Compose..."
    # the project of `docker compose up` (its directory, COMPOSE_PROJECT_NAME)
    (cd "${SCRIPT_DIR}/.." && docker compose down -v 2>/dev/null) || true
  fi
  [ -n "$_test_kubeconfig_file" ] && [ "$_test_cleanup_kind" = "1" ] && rm -f "$_test_kubeconfig_file"
  [ -n "$_test_e2e_kubeconfig" ] && rm -f "$_test_e2e_kubeconfig"
  # Deletes the cluster (or keeps it, --no-cleanup) and releases the Kind lock once it is gone
  # (test-runtimes.sh); an external cluster (--kubeconfig) is never deleted
  finish_kind_cluster "$_test_cluster_name" "$_test_cleanup_kind" || true
}

# The compose stack needs an application key (secrets are encrypted at rest; Octane resolves the
# encrypter when a worker starts): like scripts/devready.sh, write one to api/.env.docker when
# its APP_KEY= line is empty
ensure_compose_app_key() {
  local env_file="${SCRIPT_DIR}/../api/.env.docker"
  [ -f "$env_file" ] || return 0
  if grep -q '^APP_KEY=$' "$env_file" || ! grep -q '^APP_KEY=' "$env_file"; then
    local key
    key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    if grep -q '^APP_KEY=' "$env_file"; then
      sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" "$env_file"
    else
      printf 'APP_KEY=%s\n' "$key" >> "$env_file"
    fi
    echo "  APP_KEY generated in api/.env.docker"
  fi
}

# pull_image IMAGE — docker pull. An image of the reqs mirror that is not published yet (`ci.sh
# mirror-reqs` copies REQS_IMAGES of lib/mirror.sh at release time) comes from its upstream source
# in that list, tagged with the mirror name, so a checkout can test a mirror entry it adds: from
# the upstreams of REQS_UPSTREAMS in order, a Docker Hub mirror only by Docker Hub's digest.
pull_image() {
  local img="$1" prefix="${REGISTRY}/reqs/" entry name path upstream src want=""
  docker pull -q "$img" >/dev/null 2>&1 && return 0
  docker image inspect "$img" >/dev/null 2>&1 && return 0
  [[ "$img" == "$prefix"* ]] || return 1
  name="${img#"$prefix"}"
  for entry in "${REQS_IMAGES[@]}"; do
    [ "${entry%%|*}" = "${name%@*}" ] || continue
    path="$(dockerhub_path "${entry#*|}")"
    for upstream in $REQS_UPSTREAMS; do
      if [ "$upstream" = "docker.io" ]; then
        src="docker.io/${path}"
      elif [ -n "$want" ] || want=$(dockerhub_digest "$path"); then
        src="${upstream}/${path%%[:@]*}@${want}"
      else
        break
      fi
      if docker pull -q "$src" >/dev/null 2>&1 && docker tag "$src" "${img%@*}"; then
        echo "  ${img}: not in the mirror yet, ${src} tagged as it"
        return 0
      fi
    done
  done
  return 1
}

# pull_dockerfile_bases DOCKERFILE [NAME=VALUE...] — the base images of a Dockerfile
# (dockerfile_bases) through pull_image: a build without --pull then runs on current bases, also
# on a reqs mirror entry this checkout adds before the release copies it
pull_dockerfile_bases() {
  local base
  for base in $(dockerfile_bases "$@"); do
    pull_image "$base" || echo "  ${base}: not pullable"
  done
}

# kind_load_image IMAGE CLUSTER — a local image into every node of a Kind cluster. A pulled
# multi-platform image in Docker's containerd image store does not go through `kind load
# docker-image` (the saved index names the other platforms' blobs: "content digest … not found"):
# then only the nodes' platform is saved and loaded as an archive.
kind_load_image() {
  local img="$1" cluster="$2" node="${2}-control-plane" arch archive
  kind load docker-image "$img" --name "$cluster" >/dev/null 2>&1 && return 0
  arch=$(docker exec "$node" uname -m 2>/dev/null || echo x86_64)
  case "$arch" in aarch64|arm64) arch=arm64 ;; *) arch=amd64 ;; esac
  archive=$(mktemp /tmp/runr-kind-image.XXXXXX)
  if docker save --platform "linux/${arch}" -o "$archive" "$img" >/dev/null 2>&1; then
    kind load image-archive "$archive" --name "$cluster" >/dev/null 2>&1 || true
  fi
  rm -f "$archive"
  docker exec "$node" crictl inspecti -q "$img" >/dev/null 2>&1
}

preload_kind_images() {
  local cluster="$1"
  echo ""
  # KIND_PRELOAD=0: the nodes pull what they run. A loaded image keeps its compressed layers next
  # to the unpacked ones on every node, a pulled one only its unpacked layers (Kind's containerd
  # discards the rest) on the nodes that run it: less disk on a shared host, more pulls
  if [ "${KIND_PRELOAD:-1}" = "0" ]; then
    echo "Images not loaded into the Kind cluster (KIND_PRELOAD=0): the nodes pull them"
    return 0
  fi
  echo "Loading images into the Kind cluster..."

  # Default executor images from DB (executor:sync runs inside e2e, so this can be empty here):
  # RunR's general runtimes under ghcr.io/brf-tech/runr/; an ML runtime (several GB) is pulled by
  # the node that runs it
  local executor_imgs=""
  executor_imgs=$(docker compose exec -T api php artisan tinker --execute='
    App\Models\ExecutorImage::where("is_default", true)
      ->where("is_active", true)
      ->where("category", "general")
      ->pluck("docker_image")
      ->each(fn($i) => print($i . "\n"));
  ' 2>/dev/null | grep '^ghcr\.io/brf-tech/runr/' || true)

  # Static utility images
  local static_imgs=(
    "ghcr.io/brf-tech/runr/reqs/nginx:1.27-alpine"
    "ghcr.io/brf-tech/runr/reqs/curl:latest"
    "ghcr.io/brf-tech/runr/reqs/busybox:latest"
    "ghcr.io/brf-tech/runr/reqs/redis:7-alpine"
    "ghcr.io/brf-tech/runr/queue-consumer:latest"
    # the Zot of the pinned chart (ZotRegistryService::ZOT_CHART_VERSION 0.1.125)
    "ghcr.io/project-zot/zot:v2.1.21"
    "ghcr.io/osscontainertools/kaniko:v1.28.5"
    # the region Traefik (KnativeConstants::TRAEFIK_IMAGE): three replicas that would each pull it
    # (pull_image takes it from Docker Hub while the mirror lacks it)
    "ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4"
    # the region tracing backend (ManagedTracing::COLLECTOR_IMAGE / TEMPO_IMAGE), installed with
    # Knative Serving
    "ghcr.io/brf-tech/runr/reqs/opentelemetry-collector-contrib:0.162.0"
    "ghcr.io/brf-tech/runr/reqs/tempo:3.1.0"
  )

  # Load executor images (if any)
  for img in $executor_imgs; do
    [ -z "$img" ] && continue
    echo "  ${img}"
    pull_image "$img" || true
    kind_load_image "$img" "$cluster" || echo "    not loaded (the nodes pull it)"
  done

  # Load static images
  for img in "${static_imgs[@]}"; do
    echo "  ${img}"
    pull_image "$img" || true
    kind_load_image "$img" "$cluster" || echo "    not loaded (the nodes pull it)"
  done

  echo -e "  ${GREEN}✓ Images loaded.${NC}"
}

cmd_test() {
  local k8s_ver="${K8S_VERSION:-1.32.0}"
  local cleanup="${CLEANUP:-1}"
  local cluster_name=""
  local external_kubeconfig=""
  local skip_sandbox=""
  local suite="all"

  # Where this shell reaches ports published by the Docker daemon: localhost for a
  # local daemon, the daemon's hostname under docker-in-docker (E2E_DOCKER_ADDR=docker).
  # A remote daemon also needs the Kind API server on all interfaces.
  local docker_addr="${E2E_DOCKER_ADDR:-localhost}"
  local kind_api_addr="127.0.0.1"
  local kube_server_addr="127.0.0.1"
  if [ "$docker_addr" != "localhost" ]; then
    kind_api_addr="0.0.0.0"
    kube_server_addr="$docker_addr"
  fi

  for arg in "$@"; do
    case "$arg" in
      --k8s=*) k8s_ver="${arg#--k8s=}" ;;
      --name=*) cluster_name="${arg#--name=}" ;;
      --kubeconfig=*) external_kubeconfig="${arg#--kubeconfig=}" ;;
      --cleanup) cleanup=1 ;;
      --no-cleanup) cleanup=0 ;;
      --skip-sandbox) skip_sandbox="--skip-sandbox" ;;
      --suite=*) suite="${arg#--suite=}" ;;
    esac
  done

  # Cleanup at the end, also when the run is stopped from outside. bash runs the EXIT trap on TERM
  # and HUP by itself, right away, also during a long foreground step (a trap set for them would
  # wait until that step ends). INT gets a trap: without it, a foreground command that handles INT
  # and exits normally would let the run go on with its next step.
  trap _test_cleanup EXIT
  trap 'exit 130' INT

  if [ -n "$external_kubeconfig" ]; then
    # External cluster mode — skip Kind entirely
    if [ ! -f "$external_kubeconfig" ]; then
      echo -e "${RED}Kubeconfig file not found: ${external_kubeconfig}${NC}"
      exit 1
    fi
    _test_kubeconfig_file="$external_kubeconfig"
    _test_cleanup_kind="0"

    echo -e "${BOLD}E2E Test — External Cluster (API-driven)${NC}"
    echo "  Kubeconfig  : ${external_kubeconfig}"
    echo ""
  else
    # Kind cluster mode
    if [ -z "$cluster_name" ]; then
      cluster_name="runr-e2e-$(head -c4 /dev/urandom | od -An -tx1 | tr -d ' ')"
    fi
    _test_cleanup_kind="$cleanup"

    echo -e "${BOLD}E2E Test — Kind + Knative (API-driven)${NC}"
    echo "  K8s version : v${k8s_ver}"
    echo "  Cluster     : ${cluster_name}"
    echo ""

    # 0. One Kind cluster at a time on a shared host (KIND_LOCK, see test-runtimes.sh)
    acquire_kind_lock

    # 1. Kind cluster (1 control-plane + 2 workers). KIND_WORKERS=1 (a host short of disk: every
    #    node holds the region's pre-pulled images): one worker, which the suites select as the
    #    dedicated project worker, and a control-plane without its NoSchedule taint for the
    #    platform pods (registry, Knative, observability) that run next to the workers otherwise
    local workers="${KIND_WORKERS:-2}" worker_nodes="" cp_patch=""
    for _ in $(seq 1 "$workers"); do
      worker_nodes="${worker_nodes}  - role: worker"$'\n'
    done
    if [ "$workers" -lt 2 ]; then
      cp_patch='      - |
        kind: InitConfiguration
        nodeRegistration:
          taints: []'
    fi
    echo "Creating Kind cluster (1m + ${workers}w)..."
    # KIND_ETCD_IN_MEMORY=1: etcd keeps its data on the node's tmpfs (/tmp) — for hosts whose disk
    # is too slow for etcd's fsyncs ("etcdserver: request timed out", Jobs never started)
    local etcd_patch=""
    if [ "${KIND_ETCD_IN_MEMORY:-0}" = "1" ]; then
      etcd_patch='        etcd:
          local:
            dataDir: /tmp/etcd'
      echo "  etcd data on the control-plane's tmpfs (KIND_ETCD_IN_MEMORY=1)"
    fi
    local kind_config
    kind_config=$(mktemp /tmp/runr-kind-config.XXXXXX)
    cat > "$kind_config" <<KINDEOF
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
networking:
  apiServerAddress: "${kind_api_addr}"
containerdConfigPatches:
  - |-
    [plugins."io.containerd.grpc.v1.cri".registry]
      config_path = "/etc/containerd/certs.d"
nodes:
  - role: control-plane
    kubeadmConfigPatches:
      - |
        kind: ClusterConfiguration
        apiServer:
          certSANs:
            - "localhost"
            - "127.0.0.1"
            - "host.docker.internal"
            - "${kube_server_addr}"
${etcd_patch}
${cp_patch}
${worker_nodes}${KIND_EXTRA_NODES_YAML:-}
KINDEOF
    local kind_status=0
    # this run's cluster from here on (cleanup deletes it, also half-created)
    _test_cluster_name="$cluster_name"
    kind create cluster --name "$cluster_name" \
      --image "${REGISTRY}/reqs/kindest-node:v${k8s_ver}" \
      --config "$kind_config" || kind_status=$?
    rm -f "$kind_config"
    if [ "$kind_status" != "0" ]; then
      # e.g. "could not find a log line that matches Reached target .*Multi-User System": the
      # host's inotify limits are spent by the clusters already running
      # (fs.inotify.max_user_instances / max_user_watches, kind's known issues)
      echo -e "${RED}Kind cluster ${cluster_name} could not be created${NC}"
      exit "$kind_status"
    fi

    # 2. Extract kubeconfig (API server address as this shell reaches it)
    _test_kubeconfig_file=$(mktemp /tmp/runr-e2e-kubeconfig.XXXXXX)
    kind get kubeconfig --name "$cluster_name" \
      | sed -E "s#https://(127\.0\.0\.1|0\.0\.0\.0):#https://${kube_server_addr}:#g" > "$_test_kubeconfig_file"

    kubectl --kubeconfig "$_test_kubeconfig_file" cluster-info

    # Suite hook (e.g. test-ai.sh: load locally built images, prepare a GPU node)
    if [ -n "${TEST_AFTER_KIND_CREATE:-}" ]; then
      "$TEST_AFTER_KIND_CREATE" "$cluster_name"
    fi

    # 1b. Cloud Provider KIND (LoadBalancer support), shared with the host's other Kind runs
    #     (test-runtimes.sh)
    _test_cloud_provider=1
    start_cloud_provider_kind

    echo ""
    echo "Kubeconfig: ${_test_kubeconfig_file}"
  fi

  # 3. Start API + Horizon + Scheduler with Docker Compose (tenancy disabled)
  export TENANCY_ENABLED=false
  # A second run on the same Docker host sets COMPOSE_PROJECT_NAME (and its own *_PORT values and
  # KIND_LOCK): its stack then gets its own network and images, so the two stacks never resolve
  # each other's postgres, redis or api
  if [ -n "${COMPOSE_PROJECT_NAME:-}" ]; then
    export RUNR_COMPOSE_NETWORK="${RUNR_COMPOSE_NETWORK:-${COMPOSE_PROJECT_NAME}_default}"
    export RUNR_API_IMAGE="${RUNR_API_IMAGE:-${COMPOSE_PROJECT_NAME}-api}"
    export RUNR_VENDOR_IMAGE="${RUNR_VENDOR_IMAGE:-${COMPOSE_PROJECT_NAME}-vendor}"
    # Mailpit is reached by its service name; its published ports go to free host ports unless set
    export MAILPIT_SMTP_PORT="${MAILPIT_SMTP_PORT:-0}" MAILPIT_UI_PORT="${MAILPIT_UI_PORT:-0}"
    echo "  Compose project ${COMPOSE_PROJECT_NAME}: network ${RUNR_COMPOSE_NETWORK}, image ${RUNR_API_IMAGE}"
  fi
  local api_port="${API_PORT:-8060}"
  local base_url="http://${docker_addr}:${api_port}"
  echo ""
  # Reverb and Mailpit as in an install: the API broadcasts to Reverb, queued mail goes to Mailpit
  echo "Starting Docker Compose (api, horizon, scheduler, reverb, mailpit) [TENANCY_ENABLED=false]..."
  cd "${SCRIPT_DIR}/.."
  ensure_compose_app_key
  _test_compose_up="1"
  docker compose up -d --build api horizon scheduler reverb mailpit

  # Wait for API to be ready (E2E_API_WAIT seconds, default 30 — build + startup)
  echo "Waiting for the API to be ready..."
  local api_ready=0
  for i in $(seq 1 "${E2E_API_WAIT:-30}"); do
    if curl -sf "${base_url}/up" >/dev/null 2>&1; then
      api_ready=1
      break
    fi
    sleep 1
  done

  if [ "$api_ready" = "0" ]; then
    echo -e "${RED}API unreachable (port ${api_port}). Exiting.${NC}"
    docker compose logs api
    exit 1
  fi
  echo "API ready."

  # 4. Pre-load images into Kind (skip for external clusters)
  if [ -n "$_test_cluster_name" ]; then
    preload_kind_images "$_test_cluster_name"
  fi

  # 4b. Runtime plugin suite: universal wrapper image + example runtimes (test-runtimes.sh);
  #     git deploy suite: a throwaway Forgejo next to the API (test-git-deploy.sh)
  if [ "$suite" = "git-deploy" ]; then
    prepare_git_deploy_e2e
  else
    prepare_runtime_e2e "$_test_cluster_name"
  fi

  # 4c. Bindings suite: data service images (test-bindings.sh)
  if [ "$suite" = "bindings" ]; then
    preload_bindings_images "$_test_cluster_name"
  fi
  # 4c. Supply-chain suite: tools image (test-supply-chain.sh)
  [ "$suite" = "supply-chain" ] && prepare_supply_chain_e2e "$_test_cluster_name"
  # 4c. WebAssembly suite: Wasm build images + examples/wasm (test-wasm.sh)
  [ "$suite" = "wasm" ] && prepare_wasm_e2e "$_test_cluster_name"
  # 4c. Uploaded images suite: receiver + wasm-exec images, echo.tar, q.wasm (test-images.sh)
  [ "$suite" = "images" ] && prepare_images_e2e "$_test_cluster_name"

  # 5. Copy the kubeconfig into the container. The API services join the kind network
  #    (docker-compose.yml), so a Kind cluster is reached at its control-plane container
  #    (`kind get kubeconfig --internal`) — also on a Linux host, where the API server's port
  #    published on 127.0.0.1 is not reachable through host.docker.internal. An external
  #    cluster keeps its address, 127.0.0.1 rewritten to host.docker.internal. The file is this
  #    run's own: runs of other checkouts may share the host.
  echo ""
  echo "Running E2E tests..."
  _test_e2e_kubeconfig=$(mktemp /tmp/runr-e2e-api-kubeconfig.XXXXXX)
  if [ -n "$_test_cluster_name" ]; then
    kind get kubeconfig --internal --name "$_test_cluster_name" > "$_test_e2e_kubeconfig"
  else
    sed -E "s#https://(127\.0\.0\.1|0\.0\.0\.0|${kube_server_addr//./\\.}):#https://host.docker.internal:#g" \
      "$_test_kubeconfig_file" > "$_test_e2e_kubeconfig"
  fi
  docker compose cp "$_test_e2e_kubeconfig" api:/tmp/kubeconfig

  # 6. E2E test (Knative install happens via API, DaemonSet handles containerd config)
  local e2e_status=0
  docker compose exec -T api php artisan test:e2e \
    --base-url=http://localhost \
    --kubeconfig=/tmp/kubeconfig \
    --suite="$suite" \
    $skip_sandbox ${TEST_E2E_EXTRA_ARGS:-} || e2e_status=$?

  # 7. Suite hook on the running stack (e.g. test-mcp.sh: MCP client against the API, OAuth,
  #    hosted MCP servers in the e2e region): base URL as this shell reaches it + kubeconfig
  if [ -n "${TEST_AFTER_E2E:-}" ]; then
    "$TEST_AFTER_E2E" "$base_url" "$_test_kubeconfig_file" || e2e_status=1
  fi

  return "$e2e_status"
}
