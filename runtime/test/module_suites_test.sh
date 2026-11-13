#!/usr/bin/env bash
# Tests the API-only module suites (`ci.sh test-tenancy`, `test-authprovider`, `test-ticket`)
# without Docker: docker and curl are shell functions. A fresh checkout's api/.env.docker has an
# empty APP_KEY=, with which the API container exits (secrets are encrypted at rest): every suite
# writes a key before `docker compose up`, and keeps a key that is already set. The API services
# join the external `kind` network, which no cluster of these suites creates: every suite creates
# it before `docker compose up` when the host has none, and leaves one that is there.
#
#   bash runtime/test/module_suites_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/test.sh
source "$here/lib/test.sh"
# shellcheck source=../lib/test-tenancy.sh
source "$here/lib/test-tenancy.sh"
# shellcheck source=../lib/test-authprovider.sh
source "$here/lib/test-authprovider.sh"
# shellcheck source=../lib/test-ticket.sh
source "$here/lib/test-ticket.sh"

# A checkout: runtime/ (SCRIPT_DIR) next to api/
SCRIPT_DIR="$tmp/runtime"
mkdir -p "$SCRIPT_DIR" "$tmp/api"
env_file="$tmp/api/.env.docker"
calls="$tmp/calls"

# `docker compose up` records the APP_KEY the API containers would start with and fails, as
# Compose does, without the external kind network; `docker network create kind` is recorded and
# makes the network exist ($tmp/kind-network); everything else (exec of the e2e command, logs)
# succeeds
docker() {
  case "$1 $2 ${3:-}" in
    "network inspect kind") [ -e "$tmp/kind-network" ]; return ;;
    "network create kind") echo "network create kind" >>"$calls"; touch "$tmp/kind-network"; return 0 ;;
  esac
  if [ "$1 $2" = "compose up" ]; then
    [ -e "$tmp/kind-network" ] || { echo "network kind declared as external, but could not be found" >&2; return 1; }
    echo "up APP_KEY=$(sed -n 's/^APP_KEY=//p' "$env_file")" >>"$calls"
  fi
  return 0
}
curl() { return 0; }

for suite in tenancy authprovider ticket; do
  # a host without the kind network (a runner that creates no Kind cluster)
  rm -f -- "$tmp/kind-network"
  printf 'APP_NAME=RunR\nAPP_KEY=\nDB_CONNECTION=pgsql\n' >"$env_file"
  : >"$calls"
  ("cmd_test_${suite}") >"$tmp/out" 2>&1 || { cat "$tmp/out"; fail "test-${suite} failed with docker stubbed"; }
  grep -q '^up APP_KEY=base64:.' "$calls" || fail "test-${suite} started the stack without an APP_KEY: $(cat "$calls")"
  grep -q '^DB_CONNECTION=pgsql$' "$env_file" || fail "test-${suite} lost the other settings of api/.env.docker"
  [ "$(head -n 1 "$calls")" = "network create kind" ] || fail "test-${suite} did not create the kind network before docker compose up: $(cat "$calls")"

  # the kind network is there now: kept, not created again
  printf 'APP_KEY=base64:kept\n' >"$env_file"
  : >"$calls"
  ("cmd_test_${suite}") >"$tmp/out" 2>&1 || { cat "$tmp/out"; fail "test-${suite} failed with docker stubbed"; }
  [ "$(cat "$calls")" = "up APP_KEY=base64:kept" ] || fail "test-${suite} replaced a key that was set or created the kind network again: $(cat "$calls")"
done

echo "ok - module suites start the compose stack with an APP_KEY and the kind network"
