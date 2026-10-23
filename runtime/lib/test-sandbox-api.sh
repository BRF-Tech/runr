# Sandbox API e2e (docs/sandbox-api.md → Testing).
#
# cmd_test_sandbox_api  — `ci.sh test-sandbox-api [cmd_test flags]`: Kind cluster + docker compose
#                         API (cmd_test) running only the Sandbox API suite
#                         (`php artisan test:e2e --suite=sandbox-api`): gVisor installed through
#                         the API — runsc runs inside the Kind nodes; a runc-handler RuntimeClass
#                         stands in only when the install fails, and the run says why — runc
#                         opted in on the region, sandbox lifecycle, exec + SSE,
#                         code runs, files, processes, pause / resume, network none, TTL expiry
#                         through the reaper, warm pool claim, quota refusal. It prints the start
#                         time of cold and pool-claimed sandboxes.
#
#                         Kata runs only where the host has hardware virtualization (vmx / svm
#                         and a /dev/kvm that opens): then the suite gets --sandbox-kata (installs
#                         Kata with Cloud Hypervisor, QEMU and Dragonball through the API, runs a Kata
#                         sandbox and checks each guest's vCPUs — nproc = 1 + the sandbox's CPU
#                         limit, for 1 and 2 CPUs); without it the Kata checks are reported as
#                         skipped. SANDBOX_KATA=0 / 1 overrides the detection.
#
#                         Afterwards sandbox_cli_after_e2e (TEST_AFTER_E2E hook of cmd_test) runs
#                         the runr CLI of this checkout on a pseudo-terminal against the same stack
#                         (lib/sandbox_tty_client.py): `runr sandbox exec -it` — terminal size,
#                         raw mode, window change, typed input, exit code, terminal restored,
#                         Ctrl+C reaching the command — and `-t` with output to a pipe. The CLI is
#                         built in a Go container and runs in a Python container that shares the
#                         api container's network (as the MCP client of test-mcp.sh does).
#                         SANDBOX_CLI_E2E=0 leaves it out.
#
#                         After the e2e (Kata runs only) the Kata runtime's errors from containerd's
#                         log on each Kind node are printed: what the shim and the hypervisor said
#                         when a Kata sandbox did not start.
#
#                         Kata runs give the Kind nodes a host-like /dev/shm first (KIND_NODE_SHM,
#                         default 4g): Docker gives a node container 64 MiB, and QEMU keeps each
#                         guest's memory in /dev/shm — RunR's install does not offer QEMU below
#                         1 GiB (KataRuntime::QEMU_MIN_SHM_MB). KIND_NODE_SHM=0 keeps the 64 MiB:
#                         the install then reports QEMU unavailable and the suite skips its cases.
#                         They also get D-Bus (kind_nodes_dbus, test-wasm.sh): Kata's Rust runtime
#                         (Dragonball) reaches systemd over the D-Bus system bus, which Kind's node
#                         image lacks — RunR's install does not offer Dragonball there.
#                         KIND_NODE_DBUS=0 leaves it out: the suite then skips Dragonball.
#
# RUNR_E2E_SANDBOX_CASES=kata — only the Kata install and cases, then the quota case (no gVisor,
#                         runc, TTL or warm pool cases; test:e2e --sandbox-cases=kata).
# KIND_LOCK=/path       — one Kind cluster at a time on a shared host (see test-runtimes.sh).

SANDBOX_CLI_E2E_IMAGE="${SANDBOX_CLI_E2E_IMAGE:-runr-sandbox-cli-e2e:latest}"
SANDBOX_CLI_GO_IMAGE="${SANDBOX_CLI_GO_IMAGE:-ghcr.io/brf-tech/runr/reqs/golang:1.26-alpine}"
SANDBOX_CLI_PYTHON_IMAGE="${SANDBOX_CLI_PYTHON_IMAGE:-ghcr.io/brf-tech/runr/reqs/python:3.13-slim-bookworm}"

# Hardware virtualization as RunR's node probe sees it (KataKvmProbeTrait): the CPU's vmx / svm
# flag and a /dev/kvm that opens. A VM without nested virtualization (WSL 2 on some hosts, cloud
# VMs) can have the device node without either: opening it fails with "No such device".
host_has_kvm() {
  grep -qwE 'vmx|svm' /proc/cpuinfo 2>/dev/null || return 1
  ( exec 3<>/dev/kvm ) 2>/dev/null
}

sandbox_api_kata_flag() {
  case "${SANDBOX_KATA:-auto}" in
    1|true|yes) echo "--sandbox-kata" ;;
    0|false|no) echo "" ;;
    *)
      if host_has_kvm; then
        echo "--sandbox-kata"
      else
        echo ""
      fi
      ;;
  esac
}

cmd_test_sandbox_api() {
  local kata
  kata="$(sandbox_api_kata_flag)"

  echo -e "${BOLD}Sandbox API e2e${NC}"
  if [ -n "$kata" ]; then
    echo "  hardware virtualization (vmx / svm, /dev/kvm opens): Kata sandboxes included"
  else
    echo "  no hardware virtualization (no vmx / svm, or /dev/kvm does not open): Kata sandboxes skipped (gVisor and runc only)"
  fi

  local cases=""
  if [ -n "${RUNR_E2E_SANDBOX_CASES:-}" ]; then
    echo "  cases: ${RUNR_E2E_SANDBOX_CASES} (RUNR_E2E_SANDBOX_CASES)"
    cases="--sandbox-cases=${RUNR_E2E_SANDBOX_CASES// /,}"
  fi

  _sandbox_api_kata="$kata"
  export TEST_BEFORE_E2E=sandbox_api_before_e2e
  export TEST_AFTER_E2E=sandbox_api_after_e2e
  TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} ${kata} ${cases}" cmd_test --suite=sandbox-api "$@"
}

_sandbox_api_kata=""

# TEST_BEFORE_E2E of the suite (Kata runs on Kind): each node's /dev/shm resized to KIND_NODE_SHM
# (see above). The node is a privileged container with its own mount namespace: containerd, the
# Kata shim and QEMU see the new size; the host's /dev/shm is not touched.
sandbox_api_before_e2e() {
  local size="${KIND_NODE_SHM:-4g}" node
  if [ -z "$_sandbox_api_kata" ] || [ -z "${_test_cluster_name:-}" ] || [ "$size" = "0" ]; then
    return 0
  fi
  echo ""
  echo "Kind nodes: /dev/shm ${size} (Docker's 64 MiB holds no QEMU guest; KIND_NODE_SHM=0 keeps it)"
  for node in $(kind get nodes --name "$_test_cluster_name" 2>/dev/null); do
    if docker exec "$node" mount -o "remount,size=${size}" /dev/shm; then
      echo "  ${node}: /dev/shm $(docker exec "$node" df -h /dev/shm | awk 'NR == 2 { print $2 }')"
    else
      echo "  ${node}: /dev/shm could not be resized — the install will report QEMU unavailable"
    fi
  done
  if [ "${KIND_NODE_DBUS:-1}" != "0" ]; then
    echo "Kind nodes: D-Bus (Kata's Rust runtime reaches systemd over it; KIND_NODE_DBUS=0 leaves it out)"
    kind_nodes_dbus "$_test_cluster_name"
  fi
  return 0
}

# TEST_AFTER_E2E of the suite: the Kata runtime's log lines (Kata runs only), then the CLI e2e.
sandbox_api_after_e2e() {
  if [ -n "$_sandbox_api_kata" ] && [ -n "${_test_cluster_name:-}" ]; then
    sandbox_api_kata_node_log "$_test_cluster_name"
  fi
  if [ "${SANDBOX_CLI_E2E:-1}" != "0" ]; then
    sandbox_cli_after_e2e "$@"
  fi
}

# containerd's log on each Kind node, Kata lines with an error or a warning (the shim, the
# hypervisor, virtiofsd): why a Kata sandbox did not start. Diagnostics only.
sandbox_api_kata_node_log() {
  local cluster="$1" node lines
  echo ""
  echo -e "${BOLD}Kata runtime log on the nodes (containerd, errors and warnings)${NC}"
  for node in $(kind get nodes --name "$cluster" 2>/dev/null); do
    # containerd's own log and the Kata runtime's (syslog tag "kata"); a shim's exit lines say nothing
    lines=$({ docker exec "$node" journalctl -u containerd --no-pager -o cat; docker exec "$node" journalctl -t kata --no-pager -o cat; } 2>/dev/null \
      | grep -iE 'kata|qemu|dragonball|cloud-hypervisor|virtiofsd|shim|vmm|sandbox' \
      | grep -iE 'level=(error|fatal)|"level":"(error|crit)|error|fail|panic|bus error|sigbus' \
      | grep -vE 'cleaning up after shim disconnected|failed to delete shim|cleaning up dead shim' \
      | tail -n 60 | cut -c1-1500)
    if [ -n "$lines" ]; then
      echo "  ── ${node} ──"
      printf '%s\n' "$lines" | sed 's/^/    /'
    else
      echo "  ${node}: no Kata errors in containerd's log"
    fi
  done
  return 0
}

# runr sandbox exec -it / -t of this checkout's CLI against the stack the suite ran on (see above).
# The image is built from cli/ as the build context, so it works with a remote Docker daemon too.
sandbox_cli_after_e2e() {
  local base_url="$1"
  echo ""
  echo -e "${BOLD}Sandbox CLI e2e — runr sandbox exec -it on a pseudo-terminal (${base_url})${NC}"

  pull_image "$SANDBOX_CLI_GO_IMAGE" || true
  pull_image "$SANDBOX_CLI_PYTHON_IMAGE" || true
  if ! docker build -q -t "$SANDBOX_CLI_E2E_IMAGE" -f - "${SCRIPT_DIR}/../cli" >/dev/null <<DOCKERFILE
FROM ${SANDBOX_CLI_GO_IMAGE} AS build
WORKDIR /src
COPY . .
RUN CGO_ENABLED=0 GOTOOLCHAIN=auto GOFLAGS=-buildvcs=false go build -trimpath -o /runr .
FROM ${SANDBOX_CLI_PYTHON_IMAGE}
COPY --from=build /runr /usr/local/bin/runr
DOCKERFILE
  then
    echo -e "${RED}Sandbox CLI e2e: the runr CLI image could not be built${NC}"
    return 1
  fi

  local admin_user admin_password env_file="${SCRIPT_DIR}/../api/.env.docker" api_cid
  admin_user=$(grep -E '^ADMIN_USERNAME=' "$env_file" 2>/dev/null | cut -d= -f2- || true)
  admin_password=$(grep -E '^ADMIN_PASSWORD=' "$env_file" 2>/dev/null | cut -d= -f2- || true)
  api_cid=$(docker compose ps -q api)

  local status=0
  docker run --rm -i --network "container:${api_cid}" "$SANDBOX_CLI_E2E_IMAGE" \
    python - --base http://localhost --admin-user "${admin_user:-admin}" --admin-password "${admin_password:-admin123}" \
    < "${SCRIPT_DIR}/lib/sandbox_tty_client.py" || status=$?
  if [ "$status" != "0" ]; then
    echo -e "${RED}Sandbox CLI e2e: failed${NC}"
    return 1
  fi
  echo -e "${GREEN}Sandbox CLI e2e: passed${NC}"
}
