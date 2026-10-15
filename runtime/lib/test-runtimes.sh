# Runtime plugin e2e (custom runtimes through the universal wrapper).
#
# cmd_test_runtimes     — `ci.sh test-runtimes [cmd_test flags]`: Kind + Knative (cmd_test) running
#                         only the runtime plugin suite: built-in Python (unchanged), C (compiled,
#                         exec), Q (persistent, image built in the region from its Dockerfile), a
#                         schedule-triggered Bash function and a queue-triggered Q function. It
#                         prints the cold start of every HTTP function (scaled to zero → first
#                         response). `ci.sh test` runs the same suite after the other phases.
# prepare_runtime_e2e   — builds the wrapper image (runtime/wrapper), loads it into the Kind
#                         cluster and copies examples/runtimes into the API container.
#
# KIND_LOCK=/path       — optional: one Kind cluster at a time on a shared host. cmd_test takes
#                         the lock (mkdir) before creating its cluster and releases it on cleanup.
# KIND_LOCK_OWNER=name  — who holds the lock, written to <lock>/owner with the PID and the time
#                         (default runtimes-e2e), so the other users of the host see who waits on whom.

prepare_runtime_e2e() {
  local cluster="$1"
  local tag="${REGISTRY}/runr-wrapper:${WRAPPER_VERSION:-1.1.0}"

  echo ""
  echo "Preparing the runtime plugin e2e..."
  echo "  wrapper image: ${tag}"
  docker build -q -t "${tag}" "${SCRIPT_DIR}/wrapper" >/dev/null

  if [ -n "$cluster" ]; then
    kind load docker-image "${tag}" --name "$cluster" >/dev/null
    echo "  wrapper image loaded into kind/${cluster}"

    # The images of the example runtimes the suite deploys (docker.io/library/bash:5.2, …) go in
    # too: a node that pulls them itself depends on the registry's anonymous pull limit, which a
    # busy host runs out of
    local img rt manifests=()
    for rt in bash c q; do
      [ -f "${SCRIPT_DIR}/../examples/runtimes/${rt}/runtime.yaml" ] && manifests+=("${SCRIPT_DIR}/../examples/runtimes/${rt}/runtime.yaml")
    done
    for img in $([ ${#manifests[@]} -gt 0 ] && grep -h -E '^[[:space:]]+image:[[:space:]]*[a-z0-9.-]+\.[a-z]+/' "${manifests[@]}" \
      | awk '{print $2}' | tr -d '"' | sort -u); do
      pull_image "$img" || true
      if kind_load_image "$img" "$cluster"; then
        echo "  ${img} loaded into kind/${cluster}"
      else
        echo "  ${img}: not loaded (the nodes pull it)"
      fi
    done
  fi

  # The API container mounts api/ and runtime/, not examples/
  docker compose exec -T api rm -rf /tmp/runr-examples >/dev/null 2>&1 || true
  docker compose cp "${SCRIPT_DIR}/../examples/runtimes" api:/tmp/runr-examples
  echo "  examples/runtimes → api:/tmp/runr-examples"
}

cmd_test_runtimes() {
  cmd_test --suite=runtimes "$@"
}

# Kind lock (KIND_LOCK): wait for it up to KIND_LOCK_WAIT seconds (default 3600)
acquire_kind_lock() {
  [ -z "${KIND_LOCK:-}" ] && return 0
  local waited=0 max="${KIND_LOCK_WAIT:-3600}"
  until mkdir "${KIND_LOCK}" 2>/dev/null; do
    if [ "$waited" -ge "$max" ]; then
      echo -e "${RED}Kind lock ${KIND_LOCK} not released after ${max}s ($(cat "${KIND_LOCK}/owner" 2>/dev/null || echo '?'))${NC}"
      exit 1
    fi
    [ $((waited % 60)) -eq 0 ] && echo "Kind lock ${KIND_LOCK} busy ($(cat "${KIND_LOCK}/owner" 2>/dev/null || echo '?')), waiting..."
    sleep 10
    waited=$((waited + 10))
  done
  echo "${KIND_LOCK_OWNER:-runtimes-e2e} $$ $(date -u +%FT%TZ)" > "${KIND_LOCK}/owner"
  _test_kind_lock_held=1
}

release_kind_lock() {
  [ "${_test_kind_lock_held:-}" = "1" ] || return 0
  rm -f "${KIND_LOCK}/owner"
  rmdir "${KIND_LOCK}" 2>/dev/null || true
  _test_kind_lock_held=""
}

# finish_kind_cluster NAME DELETE — the end of a run's Kind cluster: DELETE=1 deletes it, DELETE=0
# (--no-cleanup) keeps it. The Kind lock is released only once the cluster is gone, so no other run
# starts a second cluster next to one that is kept or could not be deleted. Returns 1 when the
# cluster could not be deleted.
finish_kind_cluster() {
  local cluster="$1" delete="$2"
  if [ "$delete" = "1" ] && [ -n "$cluster" ]; then
    echo "Deleting Kind cluster: ${cluster}"
    # A delete can fail on a busy host (the node containers stay): retry
    for _ in 1 2 3; do
      kind delete cluster --name "$cluster" 2>&1 | tail -2 || true
      kind get clusters 2>/dev/null | grep -qx "$cluster" || break
      sleep 10
    done
    if kind get clusters 2>/dev/null | grep -qx "$cluster"; then
      echo -e "${RED}Kind cluster ${cluster} could not be deleted: delete it with 'kind delete cluster --name ${cluster}'${NC}"
      [ "${_test_kind_lock_held:-}" = "1" ] && echo -e "${RED}The Kind lock ${KIND_LOCK} stays held until then (rm -rf ${KIND_LOCK} afterwards)${NC}"
      return 1
    fi
  elif [ -n "$cluster" ] && [ "${_test_kind_lock_held:-}" = "1" ]; then
    echo -e "${YELLOW}Kind cluster ${cluster} kept (--no-cleanup) and the Kind lock ${KIND_LOCK} with it:${NC}"
    echo "  kind delete cluster --name ${cluster} && rm -rf ${KIND_LOCK}"
    return 0
  fi
  release_kind_lock
}

# Cloud Provider KIND (the LoadBalancer Services of Kind clusters): one process per Docker host,
# shared by the runs on it. cloud-provider-kind serves every Kind cluster of the host, and when it
# stops it deletes the load balancer containers of all of them — a run that stopped the process it
# had started while another run's cluster was up took that cluster's load balancers with it. A run
# joins the process that runs (CPK_PIDFILE) or starts it; the run that ends while no other Kind
# cluster is left stops it. A single run (CI) starts its own process and stops it at its end.
#
# CPK_PIDFILE=/path — the shared process's PID file (default /tmp/runr-cloud-provider-kind.pid);
#                     start and stop hold the lock <CPK_PIDFILE>.lock (flock, where there is one)
CPK_PIDFILE="${CPK_PIDFILE:-/tmp/runr-cloud-provider-kind.pid}"

# _cpk_alive PID — the process runs and is cloud-provider-kind (not a PID given to another process
# since; kill -0 only where there is no /proc)
_cpk_alive() {
  kill -0 "$1" 2>/dev/null || return 1
  [ -r "/proc/$1/cmdline" ] || return 0
  tr '\0' ' ' < "/proc/$1/cmdline" 2>/dev/null | grep -q cloud-provider-kind
}

# _cpk_pid — the shared process's PID while it runs
_cpk_pid() {
  local pid
  pid=$(cat "$CPK_PIDFILE" 2>/dev/null) || return 1
  [ -n "$pid" ] && _cpk_alive "$pid" || return 1
  echo "$pid"
}

# _cpk_locked CMD... — CMD under the lock of the shared process
_cpk_locked() {
  if command -v flock >/dev/null 2>&1 && { [ -w "${CPK_PIDFILE}.lock" ] || [ ! -e "${CPK_PIDFILE}.lock" ]; }; then
    { flock 9; "$@"; } 9>"${CPK_PIDFILE}.lock"
  else
    "$@"
  fi
}

_cpk_start() {
  local pid
  if pid=$(_cpk_pid); then
    echo "Cloud Provider KIND already running (PID ${pid}, shared with the host's other Kind clusters)"
    return 0
  fi
  echo "Starting Cloud Provider KIND..."
  # the lock (fd 9) stays with this shell
  cloud-provider-kind >/dev/null 2>&1 9>&- &
  echo "$!" > "$CPK_PIDFILE" || true
}

_cpk_stop() {
  local own="$1" pid other
  if ! pid=$(_cpk_pid); then
    rm -f "$CPK_PIDFILE"
    return 0
  fi
  other=$(kind get clusters 2>/dev/null | grep -vx -e "$own" -e 'No kind clusters found.' | head -1 || true)
  if [ -n "$other" ]; then
    echo "Cloud Provider KIND (PID ${pid}) keeps running: Kind cluster ${other} uses it"
    return 0
  fi
  echo "Stopping Cloud Provider KIND (PID ${pid})..."
  kill "$pid" 2>/dev/null || true
  # it deletes its load balancer containers on the way out: a process started next must not have
  # its own deleted by that sweep
  for _ in $(seq 1 30); do
    _cpk_alive "$pid" || break
    sleep 1
  done
  rm -f "$CPK_PIDFILE"
}

# start_cloud_provider_kind — joins the host's Cloud Provider KIND or starts it (installs it first
# when it is missing)
start_cloud_provider_kind() {
  if ! command -v cloud-provider-kind >/dev/null 2>&1; then
    echo "Installing cloud-provider-kind..."
    local cpk_ver="v0.10.0"
    local cpk_os; cpk_os="$(uname -s | tr '[:upper:]' '[:lower:]')"
    local cpk_arch; cpk_arch="$(uname -m)"; [ "$cpk_arch" = "x86_64" ] && cpk_arch="amd64"
    local cpk_url="https://github.com/kubernetes-sigs/cloud-provider-kind/releases/download/${cpk_ver}/cloud-provider-kind_${cpk_ver#v}_${cpk_os}_${cpk_arch}.tar.gz"
    curl -fsSL "$cpk_url" | sudo tar xz -C /usr/local/bin cloud-provider-kind
  fi
  _cpk_locked _cpk_start
}

# stop_cloud_provider_kind CLUSTER — the end of a run whose Kind cluster is CLUSTER: stops the
# host's Cloud Provider KIND unless another Kind cluster is left
stop_cloud_provider_kind() {
  _cpk_locked _cpk_stop "${1:-}"
}
