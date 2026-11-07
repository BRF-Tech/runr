# Runtime sources e2e (RunR's signed catalog, OCI catalog and Git sources, private images, lifecycle).
#
# cmd_test_runtime_sources — `ci.sh test-runtime-sources [cmd_test flags]`: Kind + Knative
#                      (cmd_test), running only the runtime source suite (test:e2e
#                      --suite=runtime-sources, E2eTestRuntimeSourcesTrait):
#                       - RunR's published catalog (ghcr.io/brf-tech/runr/runtime-catalog:stable)
#                         verifies with the default key and becomes the built-in catalog
#                       - an OCI catalog signed with a throwaway key: its Bash runtime deploys and
#                         answers; dropped from the catalog it is orphaned, the function answers on,
#                         a new project on it is refused
#                       - the layout of BRF-Tech/runr-sample-custom-executor (a copy in
#                         examples/runtime-sources) served by git daemon in the cluster, connected
#                         as a git source: its runtimes are offered; one with a published image runs
#                       - a private image (optional): RUNR_E2E_PRIVATE_IMAGE, an http-mode image on
#                         a registry that needs RUNR_E2E_PRIVATE_REGISTRY_USER and
#                         RUNR_E2E_PRIVATE_REGISTRY_PASSWORD — the pod pulls it with RunR's pull Secret
#                       - a sunset passing while a function runs: deploy and save refused, the
#                         function answers on
#                      The region pulls docker.io/library/bash:5.2 (loaded into Kind by
#                      prepare_runtime_e2e) and the git daemon pod installs git from Alpine's mirror:
#                      the host needs a way out.
#
#                      The stack's scheduler is stopped while the suite runs: every five minutes it
#                      reads the sources into the same database and would race the suite's reads.

# TEST_BEFORE_E2E of cmd_test (the API stack runs, the current directory is the checkout)
runtime_sources_prepare() {
  echo "  stopping the stack's scheduler: the suite reads the sources itself"
  docker compose stop scheduler >/dev/null 2>&1 || echo -e "${YELLOW}  the scheduler could not be stopped: its source sync may race the suite${NC}"

  docker compose exec -T api rm -rf /tmp/runr-examples/runtime-sources /tmp/runr-e2e-private.json >/dev/null 2>&1 || true
  docker compose exec -T api mkdir -p /tmp/runr-examples >/dev/null 2>&1 || true
  docker compose cp "${SCRIPT_DIR}/../examples/runtime-sources" api:/tmp/runr-examples/runtime-sources
  echo "  examples/runtime-sources → api:/tmp/runr-examples/runtime-sources"

  # The private image case: its settings in a file of the container (never on a command line)
  if [ -n "${RUNR_E2E_PRIVATE_IMAGE:-}" ] && [ -n "${RUNR_E2E_PRIVATE_REGISTRY_USER:-}" ] && [ -n "${RUNR_E2E_PRIVATE_REGISTRY_PASSWORD:-}" ]; then
    local file
    file=$(mktemp /tmp/runr-e2e-private.XXXXXX)
    chmod 600 "$file"
    jq -n --arg image "$RUNR_E2E_PRIVATE_IMAGE" --arg username "$RUNR_E2E_PRIVATE_REGISTRY_USER" --arg password "$RUNR_E2E_PRIVATE_REGISTRY_PASSWORD" \
      '{image: $image, username: $username, password: $password}' > "$file"
    docker compose cp "$file" api:/tmp/runr-e2e-private.json
    rm -f "$file"
    echo "  private image case: ${RUNR_E2E_PRIVATE_IMAGE}"
  else
    echo "  private image case: skipped (RUNR_E2E_PRIVATE_IMAGE, RUNR_E2E_PRIVATE_REGISTRY_USER, RUNR_E2E_PRIVATE_REGISTRY_PASSWORD)"
  fi
}

cmd_test_runtime_sources() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  export TEST_BEFORE_E2E=runtime_sources_prepare
  cmd_test --suite=runtime-sources "$@"
}
