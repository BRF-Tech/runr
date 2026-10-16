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

  if [ "${SANDBOX_CLI_E2E:-1}" != "0" ]; then
    export TEST_AFTER_E2E=sandbox_cli_after_e2e
  fi
  TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} ${kata}" cmd_test --suite=sandbox-api "$@"
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
