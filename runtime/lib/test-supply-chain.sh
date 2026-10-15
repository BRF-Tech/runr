# Software supply chain (docs/supply-chain.md): tools image build + Kind e2e.
#
# cmd_build_supply_chain     — `ci.sh supply-chain [--push]`: builds the supply-chain tools image
#                              (runtime/supply-chain: trivy + syft + cosign + oras + runr-supply-chain)
# cmd_test_supply_chain      — `ci.sh test-supply-chain [cmd_test flags]`: Kind + Knative (cmd_test)
#                              running only the supply-chain suite (test:e2e --suite=supply-chain):
#                              region signing key, a FaaS build, its SBOM / scan / signature /
#                              attestations, the SBOMs as OCI referrers and the .sig / .att tags in
#                              Zot, the policy-controller admission policy (signed image admitted,
#                              unsigned image denied), a Spin app (wasm-rust) under an enforced
#                              gate that requires a scan, the policy gate with an override and the
#                              data-location pin of region migration.
# prepare_supply_chain_e2e   — builds the tools image and loads it into the Kind cluster, and
#                              prepares the Spin app check like test-wasm (prepare_wasm_e2e: the
#                              wasm-rust build image, examples/wasm in the API container)
#                              (called by cmd_test for --suite=supply-chain).
# SUPPLY_CHAIN_WASM_LANGS    — Wasm build images prepared for it (default "rust").
#
# One Kind cluster at a time on a shared host: KIND_LOCK (default /tmp/runr-kind.lock here,
# see acquire_kind_lock in test-runtimes.sh).

supply_chain_image_tag() {
  echo "${REGISTRY}/runr-supply-chain:${SUPPLY_CHAIN_VERSION:-1.0.0}"
}

cmd_build_supply_chain() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/supply-chain"
  local tag
  tag="$(supply_chain_image_tag)"

  if [ ! -f "${dir}/Dockerfile" ]; then
    echo -e "${RED}supply-chain directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── runr-supply-chain (syft, trivy, cosign, oras) ──${NC}"
  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push"

  [ "$push" = "1" ] && cleanup_buildx

  echo ""
  echo -e "${GREEN}Done.${NC}"
  [ "$push" = "0" ] && echo "Push: ci.sh supply-chain --push"
}

prepare_supply_chain_e2e() {
  local cluster="$1"
  local tag
  tag="$(supply_chain_image_tag)"

  echo ""
  echo "Supply-chain e2e preparation..."
  echo "  tools image: ${tag}"
  docker build -q -t "${tag}" "${SCRIPT_DIR}/supply-chain" >/dev/null

  if [ -n "$cluster" ]; then
    kind load docker-image "${tag}" --name "$cluster" >/dev/null
    echo "  tools image loaded into kind/${cluster}"
  fi

  # sc-wasm-gated deploys the Rust example as a Spin app (test-wasm.sh)
  WASM_E2E_LANGS="${SUPPLY_CHAIN_WASM_LANGS:-rust}" prepare_wasm_e2e "$cluster"
}

cmd_test_supply_chain() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=supply-chain --skip-sandbox "$@"
}
