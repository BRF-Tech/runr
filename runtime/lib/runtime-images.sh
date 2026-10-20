# The runtime images the end-to-end harness uses. BRF-Tech/runr-runtimes builds and publishes them
# (https://github.com/BRF-Tech/runr-runtimes); the harness pulls the tags pinned here and no longer
# builds a runtime. This is the one place the harness takes their tags from:
#
#   WRAPPER_VERSION       ghcr.io/brf-tech/runr/wrapper            test-runtimes (and every suite
#                                                                  that runs prepare_runtime_e2e)
#   WASM_RUNTIME_VERSION  ghcr.io/brf-tech/runr/exec-wasm-<lang>   test-wasm (rust, js), test-images
#                                                                  (exec)
#   HF_SERVE_VERSION      ghcr.io/brf-tech/runr/hf-serve           test-ai
#
# Each pin is also a default of the product: change them together. runtime/test/runtime_images_test.sh
# fails while they differ — the wrapper of api/config/executor.php (RUNR_WRAPPER_IMAGE), hf-serve of
# api/config/ai.php and helm/values.yaml (ai.images.hfServe), and the WebAssembly versions of the
# built-in catalog (api/resources/runtimes/builtin.json, bundled from BRF-Tech/runr-runtimes'
# runtimes/*/runtime.yaml). The language and ML runtimes (exec-python, exec-pytorch, …) are not
# pinned here: the e2e regions pull the versions executor:sync finds in the registry, as installs do.
#
#   RUNTIME_IMAGES_REGISTRY=…  the namespace they are pulled from (default ghcr.io/brf-tech/runr)
#   RUNTIME_IMAGES_LOCAL=1     an image this host has is used as it is, not pulled: a local build of
#                              BRF-Tech/runr-runtimes (./ci.sh wrapper, ./ci.sh wasm rust, ./ci.sh
#                              hf-serve cpu, …) carries the published name, so a runtime change is
#                              tested here before it is published

RUNTIME_IMAGES_REGISTRY="${RUNTIME_IMAGES_REGISTRY:-ghcr.io/brf-tech/runr}"
RUNTIMES_REPO="https://github.com/BRF-Tech/runr-runtimes"

WRAPPER_VERSION="${WRAPPER_VERSION:-1.1.0}"
WASM_RUNTIME_VERSION="${WASM_RUNTIME_VERSION:-4.1}"
HF_SERVE_VERSION="${HF_SERVE_VERSION:-1.1.1}"

wrapper_image() {
  echo "${RUNTIME_IMAGES_REGISTRY}/wrapper:${WRAPPER_VERSION}"
}

# wasm_runtime_image LANG — the build image of a WebAssembly runtime (rust, go, js, python, exec)
wasm_runtime_image() {
  echo "${RUNTIME_IMAGES_REGISTRY}/exec-wasm-$1:${WASM_RUNTIME_VERSION}"
}

hf_serve_image() {
  echo "${RUNTIME_IMAGES_REGISTRY}/hf-serve:${HF_SERVE_VERSION}"
}

# cmd_moved_to_runtimes COMMAND — the ci.sh commands that build, mirror or prune the runtime images
# (build, wrapper, wasm, ml-runtimes, mirror-reqs, mirror-bases, eol) are BRF-Tech/runr-runtimes'
cmd_moved_to_runtimes() {
  echo -e "${YELLOW}ci.sh $1 moved to ${RUNTIMES_REPO}: run ./ci.sh $1 in a checkout of it.${NC}"
  echo "Its workflows publish the runtime images; this repository's harness pulls the tags pinned in"
  echo "runtime/lib/runtime-images.sh (RUNTIME_IMAGES_LOCAL=1 uses a local build of them)."
  return 1
}

# runtime_image_ready IMAGE — a published runtime image on this host: pulled (a newer push of the
# tag replaces an older copy); with RUNTIME_IMAGES_LOCAL=1 a copy this host has is used as it is;
# when the pull fails, a copy this host has is used (offline). Fails with what to do otherwise.
runtime_image_ready() {
  local img="$1"
  if [ "${RUNTIME_IMAGES_LOCAL:-0}" = "1" ] && docker image inspect "$img" >/dev/null 2>&1; then
    echo "  ${img} (this host's copy, RUNTIME_IMAGES_LOCAL=1)"
    return 0
  fi
  if docker pull -q "$img" >/dev/null 2>&1; then
    echo "  ${img} (pulled)"
    return 0
  fi
  if docker image inspect "$img" >/dev/null 2>&1; then
    echo -e "  ${YELLOW}${img}: the pull failed, this host's copy is used${NC}"
    return 0
  fi
  echo -e "  ${RED}${img} could not be pulled and is not on this host: is it published by ${RUNTIMES_REPO}?" \
    "Build it there (./ci.sh) and run with RUNTIME_IMAGES_LOCAL=1, or check its pin in runtime/lib/runtime-images.sh${NC}"
  return 1
}
