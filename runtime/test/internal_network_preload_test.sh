#!/usr/bin/env bash
# Tests how test-internal-network gets the model service's hf-serve image onto the Kind nodes,
# without Docker or Kind: loaded by ai_after_kind_create (TEST_AFTER_KIND_CREATE) by default; with
# KIND_PRELOAD=0 the published image of the pinned version is left to the node that runs it, a
# local build (RUNTIME_IMAGES_LOCAL=1) or another tag is still loaded.
#
#   bash runtime/test/internal_network_preload_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/runtime-images.sh
source "$here/lib/runtime-images.sh"
# shellcheck source=../lib/test-ai.sh
source "$here/lib/test-ai.sh"
# shellcheck source=../lib/test-internal-network.sh
source "$here/lib/test-internal-network.sh"

# the suite itself (cmd_test) and the host's image check are not run here
cmd_test() { echo "cmd_test $*"; }
runtime_image_ready() { return 0; }
docker() { echo "docker $*"; }

run() {
  unset TEST_AFTER_KIND_CREATE TEST_E2E_EXTRA_ARGS
  _ai_hf_serve=""
  cmd_test_internal_network >/dev/null
}

[ "$AI_HF_SERVE_TAG" = "$AI_HF_SERVE_DEFAULT_TAG" ] || fail "the pinned hf-serve tag differs from the default: $AI_HF_SERVE_TAG"

unset KIND_PRELOAD RUNTIME_IMAGES_LOCAL
run
[ "${TEST_AFTER_KIND_CREATE:-}" = ai_after_kind_create ] || fail "default: hf-serve not loaded (TEST_AFTER_KIND_CREATE=${TEST_AFTER_KIND_CREATE:-})"
[ "$_ai_hf_serve" = 1 ] || fail "default: _ai_hf_serve=${_ai_hf_serve}"
[[ "$TEST_E2E_EXTRA_ARGS" == *"--hf-serve-image=${AI_HF_SERVE_TAG}"* ]] || fail "default: ${TEST_E2E_EXTRA_ARGS}"

KIND_PRELOAD=0 run
[ -z "${TEST_AFTER_KIND_CREATE:-}" ] || fail "KIND_PRELOAD=0: hf-serve loaded (TEST_AFTER_KIND_CREATE=${TEST_AFTER_KIND_CREATE})"
[ "$_ai_hf_serve" != 1 ] || fail "KIND_PRELOAD=0: _ai_hf_serve=1"
[[ "$TEST_E2E_EXTRA_ARGS" == *"--hf-serve-image=${AI_HF_SERVE_TAG}"* ]] || fail "KIND_PRELOAD=0: the model service's image not passed: ${TEST_E2E_EXTRA_ARGS}"

KIND_PRELOAD=0 RUNTIME_IMAGES_LOCAL=1 run
[ "${TEST_AFTER_KIND_CREATE:-}" = ai_after_kind_create ] || fail "KIND_PRELOAD=0 with a local build: not loaded"

AI_HF_SERVE_TAG="registry.example/hf-serve:custom" KIND_PRELOAD=0 run
[ "${TEST_AFTER_KIND_CREATE:-}" = ai_after_kind_create ] || fail "KIND_PRELOAD=0 with another tag: not loaded"

RUNR_E2E_IN_MODEL=0 run
[ -z "${TEST_AFTER_KIND_CREATE:-}" ] || fail "RUNR_E2E_IN_MODEL=0: hf-serve loaded"

echo "ok: internal network preload"
