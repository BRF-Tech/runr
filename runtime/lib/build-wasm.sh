# cmd_build_wasm_shim — the Spin shim RunR installs on region nodes (runtime/wasm-shim-spin):
# ghcr.io/brf-tech/runr/containerd-shim-spin:<version>, the version from the Dockerfile's ARG VERSION
# (App\Services\Wasm\WasmShim pins the same tag). The binaries are cross-compiled on the build
# platform (amd64), so --push builds both platforms without emulating a compiler.
#
#   ci.sh wasm-shim            local build for the build platform (docker build)
#   ci.sh wasm-shim --push     linux/amd64 + linux/arm64 build + push

wasm_shim_version() {
  awk -F= '/^ARG VERSION=/ { print $2; exit }' "${SCRIPT_DIR}/wasm-shim-spin/Dockerfile"
}

wasm_shim_image() {
  echo "${REGISTRY}/containerd-shim-spin:$(wasm_shim_version)"
}

cmd_build_wasm_shim() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
      *) echo -e "${RED}unknown argument: ${arg} (--push)${NC}"; exit 1 ;;
    esac
  done

  local tag
  tag="$(wasm_shim_image)"
  echo -e "${BOLD}── containerd-shim-spin (RunR build of the Spin shim) ──${NC}"
  echo "==> Building ${tag}"
  PLATFORMS="linux/amd64,linux/arm64" do_build "${tag}" "${SCRIPT_DIR}/wasm-shim-spin" "$push"

  if [ "$push" = "1" ]; then
    cleanup_buildx
  fi

  echo ""
  echo -e "${GREEN}Done.${NC}"
  if [ "$push" = "0" ]; then
    echo "To push: ci.sh wasm-shim --push"
  fi
  return 0
}
