#!/usr/bin/env bash
# Tests preload_kind_images (lib/test.sh) without Docker or Kind: docker and kind are shell
# functions that record their calls. The preload needs nothing of the Compose stack (no
# `docker compose exec`, no database): each image of its list is pulled on the host and loaded
# into the Kind cluster, an image that cannot be loaded is left to the nodes, no runtime image is
# among them, and KIND_PRELOAD=0 loads nothing.
#
#   bash runtime/test/kind_preload_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
calls="$(mktemp)"
trap 'rm -f -- "$calls"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/mirror.sh
source "$here/lib/mirror.sh"
# shellcheck source=../lib/test.sh
source "$here/lib/test.sh"

kind_fails=0  # 1: `kind load` fails and the node does not have the image afterwards
docker() {
  echo "docker $*" >> "$calls"
  case "$1" in
    exec) [ "$kind_fails" = 0 ] ;;
    *) return 0 ;;
  esac
}
kind() {
  echo "kind $*" >> "$calls"
  [ "$kind_fails" = 0 ]
}

# --- the preload --------------------------------------------------------------------------------
out=$(preload_kind_images runr-e2e-1 2>&1) || fail "preload failed: $out"
grep -q '^docker compose' "$calls" && fail "the preload used the Compose stack: $(grep '^docker compose' "$calls")"
listed=$(echo "$out" | sed -n 's/^  \(ghcr\.io\/[^ ]*\)$/\1/p')
[ "$(echo "$listed" | wc -l)" -ge 10 ] || fail "images listed: $out"
for img in $listed; do
  grep -qxF "docker pull -q ${img}" "$calls" || fail "${img} not pulled"
  grep -qxF "kind load docker-image ${img} --name runr-e2e-1" "$calls" || fail "${img} not loaded"
done
[ "$(grep -c '^docker pull ' "$calls")" = "$(echo "$listed" | wc -l)" ] || fail "pulled something not listed: $(grep '^docker pull' "$calls")"
# the images the e2e regions run before anything is deployed
for img in ghcr.io/brf-tech/runr/reqs/traefik:v3.7.4 ghcr.io/project-zot/zot:v2.1.21 ghcr.io/osscontainertools/kaniko:v1.28.5 \
  ghcr.io/brf-tech/runr/reqs/redis:7-alpine ghcr.io/brf-tech/runr/queue-consumer:latest; do
  echo "$listed" | grep -qxF "$img" || fail "${img} not preloaded"
done
echo "$listed" | grep -q '/exec-' && fail "a runtime image was preloaded: $listed"
echo "$out" | grep -q '✓ Images loaded.' || fail "result: $out"

# --- an image Kind cannot load: left to the nodes, the preload goes on -----------------------------
: > "$calls"
kind_fails=1
out=$(preload_kind_images runr-e2e-1 2>&1) || fail "preload failed with kind load failing: $out"
[ "$(echo "$out" | grep -c 'not loaded (the nodes pull it)')" = "$(echo "$listed" | wc -l)" ] || fail "failed loads: $out"
kind_fails=0

# --- KIND_PRELOAD=0 -------------------------------------------------------------------------------
: > "$calls"
out=$(KIND_PRELOAD=0 preload_kind_images runr-e2e-1 2>&1) || fail "KIND_PRELOAD=0 failed: $out"
[ -s "$calls" ] && fail "KIND_PRELOAD=0 ran: $(cat "$calls")"
echo "$out" | grep -q 'Images not loaded into the Kind cluster (KIND_PRELOAD=0): the nodes pull them' || fail "KIND_PRELOAD=0: $out"

echo "ok: kind preload"
