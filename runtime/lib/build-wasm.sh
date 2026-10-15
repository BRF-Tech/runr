# cmd_build_wasm — build images of the WebAssembly runtimes (runtime/wasm-<lang>):
# ghcr.io/brf-tech/exec-wasm-<lang>:<version>, the version from the runtime's runtime.yaml.
#
#   ci.sh wasm                 all five (rust, go, js, python, exec), local single-platform build
#   ci.sh wasm rust js         only these
#   ci.sh wasm exec            the wasm-exec toolchain image (wasi-sdk, Zig, TinyGo); it copies
#                              runr-image-upload from IMAGE_UPLOAD_IMAGE (ci.sh image-upload first)
#   ci.sh wasm --push          multi-arch build + push (PLATFORMS)

WASM_RUNTIME_LANGS=(rust go js python exec)

# version of a WebAssembly runtime: the first `- version: "X"` of its runtime.yaml
wasm_runtime_version() {
  awk '/^[[:space:]]*-[[:space:]]*version:/ { v = $0; sub(/^[^:]*:[[:space:]]*/, "", v); gsub(/["'"'"' ]/, "", v); print v; exit }' \
    "${SCRIPT_DIR}/wasm-$1/runtime.yaml"
}

cmd_build_wasm() {
  local push=0
  local langs=()
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
      rust|go|js|python|exec) langs+=("$arg") ;;
      *) echo -e "${RED}unknown argument: ${arg} (rust, go, js, python, exec, --push)${NC}"; exit 1 ;;
    esac
  done
  [ "${#langs[@]}" -eq 0 ] && langs=("${WASM_RUNTIME_LANGS[@]}")

  echo -e "${BOLD}── WebAssembly runtime build images (Spin toolchains) ──${NC}"
  for lang in "${langs[@]}"; do
    local dir="${SCRIPT_DIR}/wasm-${lang}"
    local version
    version="$(wasm_runtime_version "$lang")"
    if [ ! -d "$dir" ] || [ -z "$version" ]; then
      echo -e "${RED}wasm-${lang}: runtime directory or version not found (${dir})${NC}"
      exit 1
    fi
    local tag="${REGISTRY}/exec-wasm-${lang}:${version}"
    echo "==> Building ${tag}"
    if [ "$lang" = "exec" ]; then
      # the receiver image carries runr-image-upload, which the toolchain image copies
      do_build "${tag}" "${dir}" "$push" --build-arg "IMAGE_UPLOAD_IMAGE=${IMAGE_UPLOAD_IMAGE:-${REGISTRY}/image-upload:${IMAGE_UPLOAD_VERSION:-1.0.0}}"
    else
      do_build "${tag}" "${dir}" "$push"
    fi
    echo ""
  done

  [ "$push" = "1" ] && cleanup_buildx

  echo -e "${GREEN}Done.${NC}"
  [ "$push" = "0" ] && echo "To push: ci.sh wasm --push"
  return 0
}

# cmd_build_wasm_shim — the Spin shim RunR installs on region nodes (runtime/wasm-shim-spin):
# ghcr.io/brf-tech/containerd-shim-spin:<version>, the version from the Dockerfile's ARG VERSION
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

  [ "$push" = "1" ] && cleanup_buildx

  echo ""
  echo -e "${GREEN}Done.${NC}"
  [ "$push" = "0" ] && echo "To push: ci.sh wasm-shim --push"
  return 0
}
