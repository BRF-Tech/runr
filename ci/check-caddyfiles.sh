#!/bin/bash
set -euo pipefail

# ── Caddyfile check ──────────────────────────────────────────────────────────
# The web servers of the console's files: vue/Caddyfile (the UI image) and the SPA block of
# compose/Caddyfile (the Compose gateway runs the UI image with it). Each runs in the reqs
# mirror's Caddy, the UI image's base, over a small build tree, and must:
#
#  1. validate (caddy validate);
#  2. serve index.html for every console route (/, /login, /projects/<id>, /index.html) with
#     Cache-Control no-store: Caddy sets headers before try_files rewrites the path, so a header
#     matched on /index.html alone missed every route;
#  3. serve /branding-defaults.js (written at the container's start) with no-store;
#  4. serve a hashed build file under /assets/ with the immutable header, also as the
#     pre-compressed .br where the file serves them (precompressed: vue/Caddyfile);
#  5. answer a missing /assets/ file (a chunk of the previous build) with 404 and no-store, never
#     with index.html under the immutable header.
#
#   bash ci/check-caddyfiles.sh            vue/Caddyfile and compose/Caddyfile
#   bash ci/check-caddyfiles.sh FILE...    these files (e.g. an installation's own gateway)
# Needs bash, docker and curl. The files reach the containers with docker cp (no bind mounts).
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CADDY_IMAGE="${CADDY_IMAGE:-ghcr.io/brf-tech/runr/reqs/caddy:2-alpine}"
NO_STORE='no-store, no-cache, must-revalidate'
IMMUTABLE='public, max-age=31536000, immutable'

work="$(mktemp -d)"
containers=()
cleanup() {
  for c in "${containers[@]}"; do docker rm -f "$c" >/dev/null 2>&1 || true; done
  rm -rf "$work"
}
trap cleanup EXIT

failures=0
# verdict MESSAGE CONDITION... — ok when the condition (a command) holds, else FAIL
verdict() {
  local message="$1"
  shift
  if "$@"; then
    echo "ok - ${message}"
  else
    echo "FAIL: ${message} (status ${status:-?}, Cache-Control '$(header cache-control)', Content-Encoding '$(header content-encoding)')" >&2
    failures=$((failures + 1))
  fi
}

# The build tree: index.html, the branding defaults, one hashed file with its .br and .gz
srv="$work/srv"
mkdir -p "$srv/assets"
printf '<!doctype html><title>runr-index</title>\n' > "$srv/index.html"
printf 'window.__RUNR_BRANDING_DEFAULTS__ = null\n' > "$srv/branding-defaults.js"
printf 'console.log("runr-asset")\n' > "$srv/assets/index-Ab12Cd34.js"
printf 'runr-asset-br' > "$srv/assets/index-Ab12Cd34.js.br"
printf 'runr-asset-gz' > "$srv/assets/index-Ab12Cd34.js.gz"

docker pull -q "$CADDY_IMAGE" >/dev/null

status=''
headers=''
body=''
# get PORT PATH [Accept-Encoding] → $status, $headers (lower case), $body
get() {
  status="$(curl -s -o "$work/body" -D "$work/headers" -w '%{http_code}' -H "Accept-Encoding: ${3:-identity}" "http://127.0.0.1:$1$2")"
  headers="$(tr -d '\r' < "$work/headers" | tr '[:upper:]' '[:lower:]')"
  body="$(cat "$work/body")"
}
header() { grep -m1 "^$1:" <<<"$headers" | cut -d' ' -f2- || true; }

is_index()      { [ "$status" = 200 ] && [[ "$body" == *runr-index* ]] && [ "$(header cache-control)" = "$NO_STORE" ]; }
is_no_store()   { [ "$status" = 200 ] && [ "$(header cache-control)" = "$NO_STORE" ]; }
is_asset()      { [ "$status" = 200 ] && [[ "$body" == *runr-asset* ]] && [ "$(header cache-control)" = "$IMMUTABLE" ]; }
is_asset_br()   { [ "$status" = 200 ] && [ "$body" = runr-asset-br ] && [ "$(header content-encoding)" = br ] && [ "$(header cache-control)" = "$IMMUTABLE" ]; }
is_missing()    { [ "$status" = 404 ] && [[ "$body" != *runr-index* ]] && [ "$(header cache-control)" = no-store ]; }

# check CADDYFILE
check() {
  local file="$1" name v c port path precompressed=no
  name="${file#"$ROOT"/}"
  if grep -qE '^[[:space:]]*precompressed[[:space:]]' "$file"; then precompressed=yes; fi
  v="$(docker create "$CADDY_IMAGE" caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile)"
  containers+=("$v")
  docker cp "$file" "$v:/etc/caddy/Caddyfile" >/dev/null
  if docker start -a "$v" >"$work/validate" 2>&1; then
    echo "ok - ${name}: caddy validate"
  else
    echo "FAIL: ${name}: caddy validate: $(tail -3 "$work/validate")" >&2
    failures=$((failures + 1))
    return
  fi

  c="$(docker create -p 127.0.0.1::80 "$CADDY_IMAGE" caddy run --config /etc/caddy/Caddyfile --adapter caddyfile)"
  containers+=("$c")
  docker cp "$file" "$c:/etc/caddy/Caddyfile" >/dev/null
  docker cp "$srv/." "$c:/srv" >/dev/null
  docker start "$c" >/dev/null
  port="$(docker port "$c" 80/tcp | head -1 | sed 's/.*://')"
  for _ in $(seq 1 50); do
    curl -s -o /dev/null "http://127.0.0.1:${port}/index.html" && break
    sleep 0.2
  done

  for path in / /login /projects/01JBX2M3N4P5Q6R7S8T9V0W1X2 /index.html; do
    get "$port" "$path"
    verdict "${name}: ${path} → index.html, ${NO_STORE}" is_index
  done
  get "$port" /branding-defaults.js
  verdict "${name}: /branding-defaults.js → ${NO_STORE}" is_no_store
  get "$port" /assets/index-Ab12Cd34.js
  verdict "${name}: a hashed build file → ${IMMUTABLE}" is_asset
  if [ "$precompressed" = yes ]; then
    get "$port" /assets/index-Ab12Cd34.js 'br, gzip'
    verdict "${name}: the pre-compressed .br of a build file → ${IMMUTABLE}" is_asset_br
  fi
  get "$port" /assets/old-chunk-Zz99Yy88.js
  verdict "${name}: a missing build file → 404, no-store, not index.html" is_missing
}

if [ "$#" -eq 0 ]; then
  set -- "$ROOT/vue/Caddyfile" "$ROOT/compose/Caddyfile"
fi
for file in "$@"; do
  check "$file"
done

if [ "$failures" -gt 0 ]; then
  echo "${failures} Caddyfile check(s) failed" >&2
  exit 1
fi
echo "Caddyfiles: OK"
