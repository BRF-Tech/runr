#!/usr/bin/env bash
# Tests the per-platform release pushes of runtime/ci.sh without Docker or a registry: docker is a
# shell function that keeps a small fake registry in a directory (manifests, configs, indexes).
#   - PUSH_BY_DIGEST=FILE: release-images --push pushes each image the registry lacks by digest only
#     (no tag), with RunR's OCI labels, and records "<repository>@<digest> <tag>…" in FILE; the
#     Spin shim is built for both platforms by the linux/amd64 leg, the linux/arm64 leg leaves it;
#   - compose-index tags one multi-arch index per tag set from the legs' lines, annotated with the
#     images' OCI labels and the source, and checks each tag afterwards; it refuses a tag set that
#     lacks a platform.
#
#   bash runtime/test/push_by_digest_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
command -v jq >/dev/null || fail "jq is needed (compose-index reads the manifests with it)"

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/build.sh
source "$here/lib/build.sh"
# shellcheck source=../lib/build-wasm.sh
source "$here/lib/build-wasm.sh"
# shellcheck source=../lib/test-supply-chain.sh
source "$here/lib/test-supply-chain.sh"

REGISTRY="registry.test/brf-tech/runr"
SOURCE="https://github.com/BRF-Tech/runr"
version="$(release_version)"
reg="$tmp/registry"
log="$tmp/docker.log"
mkdir -p "$reg"
: > "$log"

key() { printf '%s' "$1" | tr '/:@' '___'; }
sha() { printf '%s' "$*" | sha256sum | cut -c1-64; }

# fake_build ARGS… — `docker buildx build` with a push-by-digest output: one manifest and config per
# platform (the --label flags as its labels), an index for more than one platform; the digest in
# the --metadata-file
fake_build() {
  local platforms="" meta="" name="" label_json="{}" a prev="" p d digest entries="[]"
  for a in "$@"; do
    case "$prev" in
      --platform) platforms="$a" ;;
      --metadata-file) meta="$a" ;;
    esac
    case "$a" in
      --label=*)
        a="${a#--label=}"
        label_json="$(jq -c --arg k "${a%%=*}" --arg v "${a#*=}" '. + {($k): $v}' <<<"$label_json")" ;;
      type=image,name=*) name="${a#type=image,name=}"; name="${name%%,*}" ;;
    esac
    prev="$a"
  done
  [ -n "$name" ] && [ -n "$meta" ] && [ -n "$platforms" ] || return 1
  for p in ${platforms//,/ }; do
    d="sha256:$(sha "$* $p")"
    echo '{"schemaVersion":2,"mediaType":"application/vnd.oci.image.manifest.v1+json"}' > "$reg/$(key "${name}@${d}").json"
    jq -n --arg os "${p%/*}" --arg arch "${p#*/}" --argjson labels "$label_json" \
      '{os: $os, architecture: $arch, config: {Labels: $labels}}' > "$reg/$(key "${name}@${d}").config.json"
    entries="$(jq -c --arg d "$d" --arg os "${p%/*}" --arg arch "${p#*/}" '. + [{digest: $d, platform: {os: $os, architecture: $arch}}]' <<<"$entries")"
    digest="$d"
  done
  if [ "$(jq length <<<"$entries")" -gt 1 ]; then
    digest="sha256:$(sha "$* index")"
    jq -n --argjson m "$entries" '{schemaVersion: 2, mediaType: "application/vnd.oci.image.index.v1+json", manifests: $m}' \
      > "$reg/$(key "${name}@${digest}").json"
  fi
  printf '{\n  "containerimage.digest": "%s"\n}\n' "$digest" > "$meta"
}

# fake_create ARGS… — `docker buildx imagetools create`: an index of the sources' platforms under
# every -t tag, with the index: annotations
fake_create() {
  local tags=() sources=() annotation_json="{}" a src cfg entries="[]" t
  while [ "$#" -gt 0 ]; do
    case "$1" in
      --annotation=index:*)
        a="${1#--annotation=index:}"
        annotation_json="$(jq -c --arg k "${a%%=*}" --arg v "${a#*=}" '. + {($k): $v}' <<<"$annotation_json")"; shift ;;
      -t) tags+=("$2"); shift 2 ;;
      *) sources+=("$1"); shift ;;
    esac
  done
  for src in "${sources[@]}"; do
    cfg="$reg/$(key "$src").config.json"
    [ -f "$cfg" ] || return 1
    entries="$(jq -c --arg d "${src##*@}" --slurpfile c "$cfg" '. + [{digest: $d, platform: {os: $c[0].os, architecture: $c[0].architecture}}]' <<<"$entries")"
  done
  for t in "${tags[@]}"; do
    jq -n --argjson m "$entries" --argjson a "$annotation_json" \
      '{schemaVersion: 2, mediaType: "application/vnd.oci.image.index.v1+json", manifests: $m, annotations: $a}' > "$reg/$(key "$t").json"
  done
}

docker() {
  echo "docker $*" >> "$log"
  case "$1 ${2:-} ${3:-}" in
    "buildx build "*) fake_build "$@" ;;
    "buildx imagetools create") shift 3; fake_create "$@" ;;
    "buildx imagetools inspect")
      case "${4:-}" in
        --raw) cat "$reg/$(key "$5").json" 2>/dev/null ;;
        --format) cat "$reg/$(key "$6").config.json" 2>/dev/null ;;
        *) [ -f "$reg/$(key "$4").json" ] ;;
      esac ;;
    *) return 0 ;;
  esac
}

# run OUT ARGS… — a ci.sh command in a subshell with ci.sh's shell options; its exit status must be 0
run() {
  local out="$1" status=0
  shift
  ( set -euo pipefail; "$@" ) > "$out" 2>&1 || status=$?
  [ "$status" = "0" ] || { cat "$out" >&2; fail "$* exited ${status}"; }
}

# gateway-auth is on the registry already: neither leg builds it
echo '{}' > "$reg/$(key "${REGISTRY}/gateway-auth:$(gateway_auth_version)").json"
shim="$(wasm_shim_image)"

# --- the legs ---------------------------------------------------------------------------------------
for arch in amd64 arm64; do
  : > "$log"
  : > "$tmp/region-${arch}.txt"
  PLATFORMS="linux/${arch}" PUSH_BY_DIGEST="$tmp/region-${arch}.txt" run "$tmp/out-${arch}" cmd_release_images --push
  builds="$(grep -E '^docker buildx build ' "$log" || true)"
  [ -n "$builds" ] || fail "${arch}: nothing built"
  if grep -E ' -t | --tag ' <<<"$builds"; then fail "${arch}: a by-digest build pushed a tag"; fi
  while read -r line; do
    grep -q -- ',push-by-digest=true,' <<<"$line" || fail "${arch}: not pushed by digest: ${line}"
    grep -q -- "--label=org.opencontainers.image.source=${SOURCE} " <<<"$line" || fail "${arch}: no source label: ${line}"
    grep -q -- '--label=org.opencontainers.image.version=' <<<"$line" || fail "${arch}: no version label: ${line}"
    grep -q -- ' --pull ' <<<"$line" || fail "${arch}: a build without --pull: ${line}"
  done <<<"$builds"
  for ref in "${REGISTRY}/agent:${version} ${REGISTRY}/agent:latest" "${REGISTRY}/queue-consumer:${version} ${REGISTRY}/queue-consumer:latest" \
    "${REGISTRY}/image-upload:$(image_upload_version)" "$(supply_chain_image_tag)"; do
    grep -qE "^${REGISTRY//./\\.}/[a-z-]+@sha256:[0-9a-f]{64} ${ref//./\\.}\$" "$tmp/region-${arch}.txt" \
      || fail "${arch}: no line for ${ref}: $(cat "$tmp/region-${arch}.txt")"
  done
  grep -q "/gateway-auth" "$tmp/region-${arch}.txt" && fail "${arch}: gateway-auth is on the registry, built again"
  cp "$log" "$tmp/docker-${arch}.log"
done
grep -qE "@sha256:[0-9a-f]{64} ${shim//./\\.}\$" "$tmp/region-amd64.txt" || fail "the amd64 leg did not build the shim"
grep -q -- '--platform linux/amd64,linux/arm64 ' <<<"$(grep -E '^docker buildx build .*wasm-shim-spin$' "$tmp/docker-amd64.log")" \
  || fail "the amd64 leg did not build the shim for both platforms"
grep -q "${shim}" "$tmp/region-arm64.txt" && fail "the arm64 leg built the shim"
grep -qF "${shim}: built for both platforms by the linux/amd64 leg" "$tmp/out-arm64" || fail "the arm64 leg did not say it leaves the shim"
[ "$(grep -c . "$tmp/region-amd64.txt")" = 5 ] || fail "amd64: $(grep -c . "$tmp/region-amd64.txt") images, want 5"
[ "$(grep -c . "$tmp/region-arm64.txt")" = 4 ] || fail "arm64: $(grep -c . "$tmp/region-arm64.txt") images, want 4"
echo "ok - PUSH_BY_DIGEST: every missing image pushed by digest with its labels, no tag; the shim on the amd64 leg only"

# --- compose-index ----------------------------------------------------------------------------------
: > "$log"
run "$tmp/out-compose" cmd_compose_index "$tmp/region-amd64.txt" "$tmp/region-arm64.txt"
[ "$(grep -c '^docker buildx imagetools create ' "$log")" = 5 ] || fail "not one index per image: $(grep '^docker buildx imagetools create ' "$log")"
for ref in "${REGISTRY}/agent:${version}" "${REGISTRY}/agent:latest" "${REGISTRY}/queue-consumer:${version}" "${REGISTRY}/queue-consumer:latest" \
  "${REGISTRY}/image-upload:$(image_upload_version)" "$(supply_chain_image_tag)" "$shim"; do
  index="$reg/$(key "$ref").json"
  [ -f "$index" ] || fail "${ref} not tagged"
  [ "$(jq -r '[.manifests[].platform | "\(.os)/\(.architecture)"] | sort | join(",")' "$index")" = "linux/amd64,linux/arm64" ] \
    || fail "${ref}: $(jq -c '.manifests' "$index")"
  [ "$(jq -r '.annotations["org.opencontainers.image.source"]' "$index")" = "$SOURCE" ] || fail "${ref}: no source annotation"
  [ "$(jq -r '.annotations["org.opencontainers.image.version"]' "$index")" = "${ref##*:}" ] \
    || [ "${ref##*:}" = latest ] || fail "${ref}: version annotation $(jq -r '.annotations["org.opencontainers.image.version"]' "$index")"
  grep -qF "✓ ${ref}: " "$tmp/out-compose" || fail "${ref}: not checked after the create"
done
# the agent's index holds the digests the legs recorded
for arch in amd64 arm64; do
  d="$(awk -v r="${REGISTRY}/agent:${version}" '$2 == r { sub(/.*@/, "", $1); print $1 }' "$tmp/region-${arch}.txt")"
  [ "$(jq -r --arg a "$arch" '.manifests[] | select(.platform.architecture == $a) | .digest' "$reg/$(key "${REGISTRY}/agent:${version}").json")" = "$d" ] \
    || fail "agent:${version}: the ${arch} entry is not the ${arch} leg's ${d}"
done
echo "ok - compose-index: one index per tag set, amd64 + arm64 as pushed, annotated with the labels and the source"

# a tag set without its arm64 image is refused, nothing tagged
grep "/agent@" "$tmp/region-amd64.txt" | sed "s#:${version} #:9.9.9 #; s#:latest\$#:next#" > "$tmp/one-leg.txt"
status=0
( set -euo pipefail; cmd_compose_index "$tmp/one-leg.txt" ) > "$tmp/out-one" 2>&1 || status=$?
[ "$status" != 0 ] || fail "compose-index tagged an image without its arm64 platform"
grep -q "no linux/arm64 image" "$tmp/out-one" || fail "compose-index did not say the platform is missing: $(cat "$tmp/out-one")"
[ -f "$reg/$(key "${REGISTRY}/agent:9.9.9").json" ] && fail "agent:9.9.9 was tagged"
echo "ok - compose-index refuses a tag set that lacks a platform"
