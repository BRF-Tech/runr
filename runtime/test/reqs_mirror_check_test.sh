#!/usr/bin/env bash
# Tests ci/check-reqs-mirror.sh without a registry: curl is a script on PATH that answers like
# ghcr.io (an anonymous token, a manifest that exists or not, tags of an image, no answer at all)
# and records what it is asked. The check runs on a throwaway git checkout with a few references:
# a literal one, a Dockerfile FROM with an ARG default, a Helm repository + tag block and one
# without a tag. Checks that a reference that does not resolve fails with where it is and what to
# do, a network error fails apart from it, resolved references are cached (and the cache expires),
# --list checks nothing, a test image of the e2e harness straight from a third-party registry
# and a CI script's tool image from Docker Hub or quay.io fail, and a directory that is no git checkout fails instead of passing empty.
#
#   bash runtime/test/reqs_mirror_check_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# the checkout: the check, the lib it sources, and files that reference reqs images
repo="$tmp/repo"
mkdir -p "$repo/ci" "$repo/runtime/lib" "$repo/app" "$repo/chart" "$tmp/bin"
cp "$root/ci/check-reqs-mirror.sh" "$repo/ci/"
cp "$here/lib/common.sh" "$repo/runtime/lib/"
echo 'image = "ghcr.io/brf-tech/runr/reqs/alpine:3.21"' > "$repo/app/config.txt"
printf 'ARG TINYGO_VERSION=0.42.0\nFROM ghcr.io/brf-tech/runr/reqs/tinygo:${TINYGO_VERSION}\n' > "$repo/app/Dockerfile"
cat > "$repo/chart/values.yaml" <<'EOF'
redis:
  image:
    repository: ghcr.io/brf-tech/runr/reqs/redis
    tag: 7-alpine
postgres:
  image:
    repository: ghcr.io/brf-tech/runr/reqs/postgres
EOF
git -C "$repo" init -q
git -C "$repo" add .

# curl: ghcr.io as $FAKE_GHCR describes it — "missing" lists refs without a manifest, "down" makes
# every request fail without an answer
cat > "$tmp/bin/curl" <<'EOF'
#!/usr/bin/env bash
url="${*: -1}"
echo "$url" >> "$FAKE_GHCR/calls"
[ -e "$FAKE_GHCR/down" ] && { [[ " $* " == *" -w "* ]] && printf '000'; exit 7; }
case "$url" in
  https://ghcr.io/token*) echo '{"token":"anonymous"}' ;;
  */tags/list) echo '{"name":"x","tags":["17-alpine"]}' ;;
  */manifests/*)
    ref="${url#https://ghcr.io/v2/brf-tech/runr/reqs/}"
    ref="${ref/\/manifests\//:}"
    if grep -qxF "$ref" "$FAKE_GHCR/missing" 2>/dev/null; then printf '404'; else printf '200'; fi ;;
esac
EOF
chmod +x "$tmp/bin/curl"
export PATH="$tmp/bin:$PATH" FAKE_GHCR="$tmp/ghcr" REQS_MIRROR_CACHE="$tmp/cache/reqs"
mkdir -p "$FAKE_GHCR"

check() { : > "$FAKE_GHCR/calls"; bash "$repo/ci/check-reqs-mirror.sh" "$@"; }

# --- every reference resolves ----------------------------------------------------------------------
out=$(check 2>&1) || fail "all resolve: $out"
[[ "$out" == *"✓ 4 reqs images referenced, all resolve anonymously on ghcr.io (4 checked, 0 from the cache"* ]] || fail "all resolve: $out"
for ref in alpine/manifests/3.21 tinygo/manifests/0.42.0 redis/manifests/7-alpine postgres/tags/list; do
  grep -q "/brf-tech/runr/reqs/${ref}$" "$FAKE_GHCR/calls" || fail "${ref} not asked: $(cat "$FAKE_GHCR/calls")"
done
echo "ok - literal, Dockerfile ARG, Helm repository + tag and tagless references resolve"

# --- the cache -------------------------------------------------------------------------------------------
out=$(check 2>&1) || fail "cached: $out"
[[ "$out" == *"(0 checked, 4 from the cache"* ]] || fail "not from the cache: $out"
grep -q manifests "$FAKE_GHCR/calls" && fail "a cached reference was asked again"
out=$(REQS_MIRROR_CACHE_TTL=0 check 2>&1) || fail "expired cache: $out"
[[ "$out" == *"(4 checked, 0 from the cache"* ]] || fail "an expired cache entry was used: $out"
echo "ok - resolved references are cached until REQS_MIRROR_CACHE_TTL"

# --- a reference that does not resolve -----------------------------------------------------------------
rm -f -- "$REQS_MIRROR_CACHE"
echo "tinygo:0.42.0" > "$FAKE_GHCR/missing"
out=$(check 2>&1) && fail "a missing image passed: $out"
[[ "$out" == *"✗ ghcr.io/brf-tech/runr/reqs/tinygo:0.42.0 does not resolve anonymously (HTTP 404): app/Dockerfile:FROM"* ]] || fail "missing: $out"
[[ "$out" == *"lib/mirror.sh of https://github.com/BRF-Tech/runr-runtimes"* ]] || fail "no hint where to add it: $out"
grep -q '^tinygo:' "$REQS_MIRROR_CACHE" && fail "a missing reference was cached"
: > "$FAKE_GHCR/missing"
echo "ok - a reference that does not resolve fails with its place and what to do, and is not cached"

# --- no answer -----------------------------------------------------------------------------------------------
rm -f -- "$REQS_MIRROR_CACHE"
touch "$FAKE_GHCR/down"
out=$(check 2>&1) && fail "an unreachable registry passed: $out"
[[ "$out" == *"no answer from ghcr.io"* && "$out" == *"could not be checked"* ]] || fail "network error: $out"
[[ "$out" == *"does not resolve"* ]] && fail "a network error reported as a missing image: $out"
out=$(check --list 2>&1) || fail "--list: $out"
[ "$(echo "$out" | wc -l)" = 4 ] || fail "--list: $out"
[ -s "$FAKE_GHCR/calls" ] && fail "--list asked the registry"
rm -f -- "$FAKE_GHCR/down"
echo "ok - a network error fails apart from missing images; --list checks nothing"

# --- test images straight from a third-party registry ----------------------------------------------------
# the e2e harness: image defaults of runtime/lib/*.sh, image constants of the e2e commands (the
# mirror only); the CI scripts' tool images (ci/**/*.sh: the mirror or the upstream's ghcr.io image)
mkdir -p "$repo/runtime/lib" "$repo/api/app/Console/Commands/Concerns" "$repo/ci/site"
cat > "$repo/runtime/lib/test-fake.sh" <<'EOF'
prepare_fake() {
  local image="${FORGEJO_IMAGE:-codeberg.org/forgejo/forgejo:9}"
  local tool="${TOOL_IMAGE:-alpine:3.21}"
  local client="${CLIENT_IMAGE:-runr-e2e-client:latest}"
  local python="${PY_IMAGE:-ghcr.io/brf-tech/runr/reqs/alpine:3.21}"
  local gateway="${GATEWAY_IMAGE:-${REGISTRY}/gateway-auth:1.1.0}"
  local upstream="${UPSTREAM_IMAGE:-ghcr.io/acme/tool:1.0}"
  [ "${E2E_LOCAL_RUNR_IMAGES:-1}" = 1 ] && echo "$image $tool $client $python $gateway $upstream"
}
EOF
cat > "$repo/ci/site/verify.sh" <<'EOF'
PANDOC_IMAGE="${PANDOC_IMAGE:-pandoc/core:3.6.4}"
LINT_IMAGE="${LINT_IMAGE:-ghcr.io/gitleaks/gitleaks:v8.30.1}"
CERT_IMAGE="${CERT_IMAGE:-quay.io/acme/cert-tool:2}"
CADDY_IMAGE="${CADDY_IMAGE:-ghcr.io/brf-tech/runr/reqs/alpine:3.21}"
EOF
cat > "$repo/api/app/Console/Commands/Concerns/E2eTestFakeTrait.php" <<'EOF'
<?php
trait E2eTestFakeTrait
{
    private const FK_BASH_IMAGE = 'docker.io/library/bash:5.2';

    private const FK_BAO_IMAGE = 'ghcr.io/brf-tech/runr/reqs/alpine:3.21';
}
EOF
git -C "$repo" add .
rm -f -- "$REQS_MIRROR_CACHE"
out=$(check 2>&1) && fail "third-party test images passed: $out"
[[ "$out" == *"✗ runtime/lib/test-fake.sh:2: FORGEJO_IMAGE = codeberg.org/forgejo/forgejo:9, a test image straight from codeberg.org"* ]] || fail "codeberg default: $out"
[[ "$out" == *"✗ runtime/lib/test-fake.sh:3: TOOL_IMAGE = alpine:3.21, a test image straight from Docker Hub"* ]] || fail "Docker Hub default: $out"
[[ "$out" == *"✗ api/app/Console/Commands/Concerns/E2eTestFakeTrait.php:4: FK_BASH_IMAGE = docker.io/library/bash:5.2, a test image straight from docker.io"* ]] || fail "e2e constant: $out"
[[ "$out" == *"✗ runtime/lib/test-fake.sh:7: UPSTREAM_IMAGE = ghcr.io/acme/tool:1.0, a test image straight from ghcr.io"* ]] || fail "upstream ghcr.io test image: $out"
[[ "$out" == *"✗ ci/site/verify.sh:1: PANDOC_IMAGE = pandoc/core:3.6.4, a CI tool image from Docker Hub"* ]] || fail "Docker Hub CI tool: $out"
[[ "$out" == *"✗ ci/site/verify.sh:3: CERT_IMAGE = quay.io/acme/cert-tool:2, a CI tool image from quay.io"* ]] || fail "quay.io CI tool: $out"
[[ "$out" == *"6 test or CI tool image(s) from a third-party registry"* && "$out" == *"REQS_IMAGES in lib/mirror.sh"* ]] || fail "no count or hint: $out"
[[ "$out" == *"CLIENT_IMAGE"* || "$out" == *"PY_IMAGE"* || "$out" == *"GATEWAY_IMAGE"* || "$out" == *"FK_BAO_IMAGE"* || "$out" == *"E2E_LOCAL_RUNR_IMAGES"* || "$out" == *"LINT_IMAGE"* || "$out" == *"CADDY_IMAGE"* ]] \
  && fail "a reqs, local, variable, non-image or upstream ghcr.io CI value was reported: $out"
out=$(check --list 2>&1) || fail "--list with third-party test images: $out"

sed -i -e 's#codeberg.org/forgejo/forgejo:9#ghcr.io/brf-tech/runr/reqs/forgejo:9.0.3#' \
  -e 's#TOOL_IMAGE:-alpine:3.21#TOOL_IMAGE:-ghcr.io/brf-tech/runr/reqs/alpine:3.21#' \
  -e 's#ghcr.io/acme/tool:1.0#ghcr.io/brf-tech/runr/reqs/alpine:3.21#' "$repo/runtime/lib/test-fake.sh"
sed -i -e 's#pandoc/core:3.6.4#ghcr.io/brf-tech/runr/reqs/pandoc:3.6.4#' \
  -e 's#quay.io/acme/cert-tool:2#ghcr.io/brf-tech/runr/reqs/alpine:3.21#' "$repo/ci/site/verify.sh"
sed -i 's#docker.io/library/bash:5.2#ghcr.io/brf-tech/runr/reqs/bash:5.2#' "$repo/api/app/Console/Commands/Concerns/E2eTestFakeTrait.php"
git -C "$repo" add .
out=$(check 2>&1) || fail "mirrored test images: $out"
[[ "$out" == *"no test or CI tool image from a third-party registry"* ]] || fail "mirrored test images: $out"
grep -q '/reqs/forgejo/manifests/9.0.3$' "$FAKE_GHCR/calls" || fail "the mirrored Forgejo was not checked: $(cat "$FAKE_GHCR/calls")"
grep -q '/reqs/pandoc/manifests/3.6.4$' "$FAKE_GHCR/calls" || fail "the mirrored pandoc was not checked: $(cat "$FAKE_GHCR/calls")"
echo "ok - a test image from a third-party registry, a CI tool image from Docker Hub or quay.io fails with its place; mirrored, local and RunR images, CI tools on their ghcr.io pass"

# --- not a git checkout ------------------------------------------------------------------------------------
rm -rf -- "$repo/.git"
out=$(check 2>&1) && fail "no git checkout passed: $out"
[[ "$out" == *"is not a git checkout"* ]] || fail "no git checkout: $out"
echo "ok - outside a git checkout the check fails instead of passing with no reference"
