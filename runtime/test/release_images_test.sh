#!/usr/bin/env bash
# Tests the release image list of runtime/ci.sh (release-images) without Docker or a registry:
#   - the region agent and the event gateway / queue consumer are listed at helm/Chart.yaml's
#     appVersion — the version the API runs them at (App\Support\Images\ReleaseImages);
#   - every image of RunR's own the API references by version (api/config, the Spin shim of
#     App\Services\Wasm\WasmShim, the defaults the Helm values document) is in the list, at the
#     version its Dockerfile builds;
#   - no default of RunR's own images is :latest (api/config, api/app, helm/, the Compose files);
#   - release-images --push builds and pushes every image the registry does not have (agent and
#     queue-consumer also as :latest), and leaves the ones it has.
#
#   bash runtime/test/release_images_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
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

version=$(sed -n 's/^appVersion:[[:space:]]*"\{0,1\}\([^"[:space:]]*\)"\{0,1\}[[:space:]]*$/\1/p' "$root/helm/Chart.yaml")
[ -n "$version" ] || fail "no appVersion in helm/Chart.yaml"
[ "$(release_version)" = "$version" ] || fail "release_version $(release_version), appVersion ${version}"

list=$(cmd_release_images)
[ "$(cmd_release_images --list)" = "$list" ] || fail "--list differs from the default"
for name in agent queue-consumer; do
  echo "$list" | grep -qxF "ghcr.io/brf-tech/runr/${name}:${version}" || fail "${name}:${version} not in the release list: ${list}"
done
echo "ok - the region agent and the queue consumer are released at appVersion ${version}"

# --- every image of RunR's own the API references by version is released --------------------------
refs=$(grep -rhoE "ghcr\.io/brf-tech/runr/(agent|queue-consumer|gateway-auth|image-upload|supply-chain|containerd-shim-spin):[A-Za-z0-9_]([A-Za-z0-9._-]*[A-Za-z0-9])?" \
  "$root/api/config" "$root/api/app/Services/Wasm/WasmShim.php" "$root/helm/values.yaml" "$root/helm/values.schema.json" | sort -u)
[ "$(echo "$refs" | grep -c .)" -ge 4 ] || fail "too few references found: ${refs}"
for ref in $refs; do
  echo "$list" | grep -qxF "$ref" || fail "${ref} is referenced but not in the release list: ${list}"
done
for name in gateway-auth image-upload; do
  built=$(awk -F= '/^ARG VERSION=/ { print $2; exit }' "$here/${name}/Dockerfile")
  echo "$list" | grep -qxF "ghcr.io/brf-tech/runr/${name}:${built}" || fail "${name}: its Dockerfile builds ${built}, the list has $(echo "$list" | grep "/${name}:")"
done
echo "ok - every image the API references by version is in the release list ($(echo "$refs" | wc -l | tr -d ' ') references)"

# --- no :latest default of RunR's own images ----------------------------------------------------------
latest=$(grep -rnE "ghcr\.io/brf-tech/runr/(api|ui|agent|queue-consumer|gateway-auth|image-upload|supply-chain|containerd-shim-spin|transfer)(:latest|['\"])" \
  "$root/api/config" "$root/api/app" "$root/helm" "$root/docker-compose.yml" "$root/compose/docker-compose.yml" || true)
[ -z "$latest" ] || fail "a default of RunR's own images is :latest (or has no tag): ${latest}"
echo "ok - no default of RunR's own images is :latest"

# --- release-images --push ------------------------------------------------------------------------
REGISTRY="registry.test/brf-tech/runr"
log="$tmp/docker.log"
: > "$log"
# docker: records the command; `buildx imagetools inspect` finds what $tmp/on-registry lists
printf '%s\n' "${REGISTRY}/gateway-auth:$(gateway_auth_version)" "$(wasm_shim_image)" > "$tmp/on-registry"
docker() {
  echo "docker $*" >> "$log"
  if [ "$1 ${2:-} ${3:-}" = "buildx imagetools inspect" ]; then
    grep -qxF -- "$4" "$tmp/on-registry"
    return
  fi
  return 0
}
( set -euo pipefail; cmd_release_images --push ) > "$tmp/out" 2>&1 || { cat "$tmp/out" >&2; fail "release-images --push failed"; }
builds=$(grep -E '^docker buildx build ' "$log" || true)
for ref in "${REGISTRY}/agent:${version}" "${REGISTRY}/agent:latest" "${REGISTRY}/queue-consumer:${version}" "${REGISTRY}/queue-consumer:latest" \
  "${REGISTRY}/image-upload:$(image_upload_version)" "$(supply_chain_image_tag)"; do
  echo "$builds" | grep -q -- "-t ${ref} " || fail "${ref} not built and pushed: ${builds}"
done
for ref in "${REGISTRY}/gateway-auth:$(gateway_auth_version)" "$(wasm_shim_image)"; do
  echo "$builds" | grep -q -- "-t ${ref} " && fail "${ref} is on the registry, built again"
  grep -qF "✓ ${ref} is on the registry" "$tmp/out" || fail "${ref}: not reported as on the registry"
done
[ "$(echo "$builds" | grep -c .)" = 4 ] || fail "$(echo "$builds" | grep -c .) builds, want 4: ${builds}"
: > "$log"
( set -euo pipefail; cmd_release_images --push --force ) > "$tmp/out" 2>&1 || fail "release-images --push --force failed"
[ "$(grep -cE '^docker buildx build ' "$log")" = 6 ] || fail "--force: not every image built"
echo "ok - release-images --push builds what the registry does not have (agent and queue-consumer with :latest), --force every one"
