#!/usr/bin/env bash
# Tests ci/check-reqs-mirror.sh without a registry: curl is a script on PATH that answers like
# ghcr.io (an anonymous token, a manifest that exists or not, tags of an image, no answer at all)
# and records what it is asked. The check runs on a throwaway git checkout with a few references:
# a literal one, a Dockerfile FROM with an ARG default, a Helm repository + tag block and one
# without a tag. Checks that a reference that does not resolve fails with where it is and what to
# do, a network error fails apart from it, resolved references are cached (and the cache expires),
# --list checks nothing, and a directory that is no git checkout fails instead of passing empty.
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

# --- not a git checkout ------------------------------------------------------------------------------------
rm -rf -- "$repo/.git"
out=$(check 2>&1) && fail "no git checkout passed: $out"
[[ "$out" == *"is not a git checkout"* ]] || fail "no git checkout: $out"
echo "ok - outside a git checkout the check fails instead of passing with no reference"
