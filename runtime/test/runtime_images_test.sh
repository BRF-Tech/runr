#!/usr/bin/env bash
# Tests the runtime images of the e2e harness (lib/runtime-images.sh) without Docker or a registry:
# the tags it pins are the product's defaults (the wrapper of the API, hf-serve of the API, the
# Helm chart and the AI suite, the WebAssembly versions of the bundled built-in catalog), the
# harness pulls them and builds no runtime (BRF-Tech/runr-runtimes does), runtime_image_ready pulls,
# falls back to this host's copy, takes a local build with RUNTIME_IMAGES_LOCAL=1 and otherwise
# fails naming BRF-Tech/runr-runtimes, and the ci.sh commands that moved there say so.
# Needs jq.
#
#   bash runtime/test/runtime_images_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/runtime-images.sh
source "$here/lib/runtime-images.sh"

# --- the pins are the product's defaults ----------------------------------------------------------
wrapper="$(wrapper_image)"
[ "$wrapper" = "ghcr.io/brf-tech/runr/wrapper:${WRAPPER_VERSION}" ] || fail "wrapper: ${wrapper}"
grep -qF "env('RUNR_WRAPPER_IMAGE', '${wrapper}')" "$root/api/config/executor.php" \
  || fail "api/config/executor.php does not default to ${wrapper}"

hf="$(hf_serve_image)"
[ "$hf" = "ghcr.io/brf-tech/runr/hf-serve:${HF_SERVE_VERSION}" ] || fail "hf-serve: ${hf}"
grep -qF "env('AI_IMAGE_HF_SERVE', '${hf}')" "$root/api/config/ai.php" || fail "api/config/ai.php does not default to ${hf}"
grep -qF "env('AI_IMAGE_HF_SERVE_CUDA', '${hf}-cuda')" "$root/api/config/ai.php" || fail "api/config/ai.php does not default to ${hf}-cuda"
grep -qE "^    hfServe: ${hf}$" "$root/helm/values.yaml" || fail "helm/values.yaml ai.images.hfServe is not ${hf}"
grep -qE "^    hfServeCuda: ${hf}-cuda$" "$root/helm/values.yaml" || fail "helm/values.yaml ai.images.hfServeCuda is not ${hf}-cuda"
grep -qF "default \"${hf}\")" "$root/helm/templates/configmap.yaml" || fail "helm/templates/configmap.yaml does not default to ${hf}"
grep -qF "'${hf}'" "$root/api/app/Console/Commands/Concerns/E2eTestAiTrait.php" || fail "the AI suite does not default to ${hf}"

# every WebAssembly runtime of the bundled catalog: version and image = the pin
wasm=$(jq -r '.runtimes[] | select(.id | startswith("wasm-")) | .id as $id | .versions[] | "\($id) \(.version) \(.image)"' \
  "$root/api/resources/runtimes/builtin.json")
[ "$(echo "$wasm" | wc -l)" = 5 ] || fail "the catalog's WebAssembly runtimes: ${wasm}"
while read -r id version image; do
  [ "$version" = "$WASM_RUNTIME_VERSION" ] || fail "${id}: the catalog's version ${version} is not the pin ${WASM_RUNTIME_VERSION}"
  [ "$image" = "$(wasm_runtime_image "${id#wasm-}")" ] || fail "${id}: the catalog's image ${image} is not $(wasm_runtime_image "${id#wasm-}")"
done <<< "$wasm"
echo "ok - the pins (wrapper ${WRAPPER_VERSION}, hf-serve ${HF_SERVE_VERSION}, WebAssembly ${WASM_RUNTIME_VERSION}) are the product's defaults"

# --- the harness pulls the runtime images and builds none ---------------------------------------------
built=$(grep -nE '(docker build|do_build).*\$\{?SCRIPT_DIR\}?/(python|nodejs|go|php|pytorch|tensorflow|hf-serve|wrapper|wasm-(rust|go|js|python|exec))\b' \
  "$here"/lib/*.sh || true)
[ -z "$built" ] || fail "the harness builds a runtime image: ${built}"
for dir in python nodejs go php pytorch tensorflow hf-serve wrapper wasm-rust wasm-go wasm-js wasm-python wasm-exec; do
  [ ! -e "$here/${dir}" ] || fail "runtime/${dir} is back: the runtime images are BRF-Tech/runr-runtimes'"
done
grep -q 'runtime_image_ready "\$tag"' "$here/lib/test-runtimes.sh" || fail "test-runtimes does not pull the wrapper"
grep -q 'runtime_image_ready "\$tag"' "$here/lib/test-wasm.sh" || fail "test-wasm does not pull the build images"
grep -q 'runtime_image_ready "\$exec_tag"' "$here/lib/test-images.sh" || fail "test-images does not pull wasm-exec"
grep -q 'runtime_image_ready "\$AI_HF_SERVE_TAG"' "$here/lib/test-ai.sh" || fail "test-ai does not pull hf-serve"
echo "ok - test-runtimes, test-wasm, test-images and test-ai pull the pinned images, nothing builds one"

# --- runtime_image_ready -------------------------------------------------------------------------------
calls="$tmp/calls"
pull_ok=1 local_copy=0
docker() {
  echo "docker $*" >> "$calls"
  case "$1" in
    pull) [ "$pull_ok" = 1 ] ;;
    image) [ "$local_copy" = 1 ] ;;
    *) return 0 ;;
  esac
}

: > "$calls"
out=$(runtime_image_ready "$wrapper" 2>&1) || fail "a published image: $out"
[[ "$out" == *"${wrapper} (pulled)"* ]] || fail "pulled: $out"
grep -qx "docker pull -q ${wrapper}" "$calls" || fail "not pulled: $(cat "$calls")"

: > "$calls"
local_copy=1
out=$(RUNTIME_IMAGES_LOCAL=1 runtime_image_ready "$wrapper" 2>&1) || fail "RUNTIME_IMAGES_LOCAL=1: $out"
grep -q '^docker pull' "$calls" && fail "RUNTIME_IMAGES_LOCAL=1 pulled over this host's copy"
[[ "$out" == *"this host's copy, RUNTIME_IMAGES_LOCAL=1"* ]] || fail "RUNTIME_IMAGES_LOCAL=1: $out"
out=$(runtime_image_ready "$wrapper" 2>&1) || fail "a local copy without RUNTIME_IMAGES_LOCAL: $out"
grep -q "^docker pull -q ${wrapper}" "$calls" || fail "without RUNTIME_IMAGES_LOCAL the published image is not pulled"

pull_ok=0
out=$(runtime_image_ready "$wrapper" 2>&1) || fail "offline with a local copy: $out"
[[ "$out" == *"the pull failed, this host's copy is used"* ]] || fail "offline: $out"
local_copy=0
out=$(runtime_image_ready "$wrapper" 2>&1) && fail "neither published nor local passed: $out"
[[ "$out" == *"https://github.com/BRF-Tech/runr-runtimes"* && "$out" == *"runtime/lib/runtime-images.sh"* ]] \
  || fail "no hint where the image comes from: $out"
echo "ok - runtime_image_ready: pulled, RUNTIME_IMAGES_LOCAL=1, offline fallback, a clear failure"

# --- the commands that moved to BRF-Tech/runr-runtimes ---------------------------------------------------
for cmd in build wrapper wasm ml-runtimes mirror-reqs mirror-bases eol; do
  out=$(bash "$here/ci.sh" "$cmd" --push 2>&1) && fail "ci.sh ${cmd} passed: $out"
  [[ "$out" == *"ci.sh ${cmd} moved to https://github.com/BRF-Tech/runr-runtimes"* ]] || fail "ci.sh ${cmd}: $out"
done
echo "ok - ci.sh build, wrapper, wasm, ml-runtimes, mirror-reqs, mirror-bases and eol point to BRF-Tech/runr-runtimes"
