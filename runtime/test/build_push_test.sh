#!/usr/bin/env bash
# Tests the image builds of runtime/ci.sh without Docker: docker is a shell function that records
# every command and succeeds. Every build command exits 0 with and without --push (each used to end
# with `[ "$push" = "0" ] && echo …`, the exit status 1 of a successful push), every image build —
# buildx and plain docker build, the release tars of `ci.sh build-deploy` included — pulls its base
# images (--pull), and the Compose build scripts do the same.
#
#   bash runtime/test/build_push_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/build.sh
source "$here/lib/build.sh"
# shellcheck source=../lib/build-wasm.sh
source "$here/lib/build-wasm.sh"
# shellcheck source=../lib/test-supply-chain.sh
source "$here/lib/test-supply-chain.sh"
# shellcheck source=../lib/test.sh
source "$here/lib/test.sh"
# shellcheck source=../lib/deploy-images.sh
source "$here/lib/deploy-images.sh"

REGISTRY="registry.test/brf-tech"
log="$tmp/docker.log"
local_images="$tmp/local-images"
: > "$local_images"

# docker: records the command; `image inspect` finds the images listed in $local_images; a build
# with --output type=docker,dest=FILE writes FILE
docker() {
  echo "docker $*" >> "$log"
  case "$1 ${2:-}" in
    "image inspect") grep -qxF -- "$3" "$local_images"; return ;;
  esac
  local arg
  for arg in "$@"; do
    case "$arg" in
      type=docker,dest=*) : > "${arg#type=docker,dest=}" ;;
    esac
  done
  return 0
}

# run NAME ARGS… — a ci.sh command in a subshell with ci.sh's shell options; its exit status must be 0
run() {
  : > "$log"
  local status=0
  ( set -euo pipefail; "$@" ) > "$tmp/out" 2>&1 || status=$?
  [ "$status" = "0" ] || { cat "$tmp/out" >&2; fail "$* exited ${status}"; }
}

# every image build of the last command pulls its bases; prints how many builds there were
builds_pull() {
  local builds
  builds=$(grep -cE '^docker (buildx )?build ' "$log" || true)
  [ "$builds" -gt 0 ] || fail "$1: no image build"
  if grep -E '^docker (buildx )?build ' "$log" | grep -vqE ' --pull( |$)'; then
    fail "$1: an image build without --pull: $(grep -E '^docker (buildx )?build ' "$log" | grep -vE ' --pull( |$)' | head -n 1)"
  fi
  echo "$builds"
}

pushed() {
  grep -E '^docker buildx build ' "$log" | grep -q -- '--output type=image,push=true' || fail "$1: no buildx push"
  if grep -E '^docker buildx build ' "$log" | grep -vq -- '--builder runr-multiarch '; then
    fail "$1: a push build without --builder runr-multiarch"
  fi
  grep -qE '^docker buildx use ' "$log" && fail "$1: the push builder became the host's current builder"
  grep -qxF 'docker buildx rm runr-multiarch' "$log" || fail "$1: the push builder was not removed"
}

# --- every build command, local and --push ------------------------------------------------------
# (the runtime images — ci.sh build, wrapper, wasm, ml-runtimes — are BRF-Tech/runr-runtimes', with
# their own build tests there)
for cmd in cmd_build_queue_consumer cmd_build_image_upload cmd_build_gateway_auth cmd_build_agent \
           cmd_build_supply_chain cmd_test_image cmd_build_wasm_shim; do
  run "$cmd"
  builds_pull "$cmd" > /dev/null
  grep -qE '^docker buildx build ' "$log" && fail "$cmd: a local build went through buildx"

  run "$cmd" --push
  builds_pull "$cmd --push" > /dev/null
  pushed "$cmd --push"
done
echo "ok - build commands exit 0 with and without --push, every build with --pull"

# --- release tars (ci.sh build-deploy) ----------------------------------------------------------
mkdir -p "$tmp/repo/runtime" "$tmp/repo/docs" "$tmp/repo/api" "$tmp/repo/vue"
echo "# doc" > "$tmp/repo/docs/a.md"
SCRIPT_DIR="$tmp/repo/runtime"
_DEPLOY_TAR_DIR="$tmp/repo/deploy"
_deploy_no_cache=0 _deploy_tenancy=false _deploy_auth_provider=false _deploy_ticket=false _deploy_billing=false
resolve_app_version() { echo "v0.0.0-test"; }
run _deploy_build_images
[ "$(builds_pull "build-deploy")" = "3" ] || fail "build-deploy: not three images (api, ui, gateway)"
for tar in api ui gateway; do
  [ -f "$tmp/repo/deploy/${tar}.tar" ] || fail "build-deploy: ${tar}.tar missing"
done
echo "ok - the release tars (api, ui, gateway) are built with --pull"

# --- Compose build scripts ----------------------------------------------------------------------
for script in "$here/../compose/build.sh" "$here/../compose/deploy.sh"; do
  missing=$(awk '/^[[:space:]]*docker build/ { want = 1; at = FNR; if ($0 ~ /--pull/) want = 0; next }
                 want && /--pull/ { want = 0 }
                 want && !/\\[[:space:]]*$/ { print at; want = 0 }' "$script")
  [ -z "$missing" ] || fail "$(basename "$script"): docker build without --pull at line(s) ${missing}"
  grep -q 'docker build' "$script" || fail "$(basename "$script"): no docker build"
done
echo "ok - compose/build.sh and compose/deploy.sh build with --pull"
