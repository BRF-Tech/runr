#!/usr/bin/env bash
# Tests the API-only module suites (`ci.sh test-tenancy`, `test-authprovider`, `test-ticket`)
# without Docker: docker and curl are shell functions. A fresh checkout's api/.env.docker has an
# empty APP_KEY=, with which the API container exits (secrets are encrypted at rest): every suite
# writes a key before `docker compose up`, and keeps a key that is already set.
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

# `docker compose up` records the APP_KEY the API containers would start with; everything else
# (exec of the e2e command, logs) succeeds
docker() {
  if [ "$1 $2" = "compose up" ]; then
    echo "up APP_KEY=$(sed -n 's/^APP_KEY=//p' "$env_file")" >>"$calls"
  fi
  return 0
}
curl() { return 0; }

for suite in tenancy authprovider ticket; do
  printf 'APP_NAME=RunR\nAPP_KEY=\nDB_CONNECTION=pgsql\n' >"$env_file"
  : >"$calls"
  ("cmd_test_${suite}") >"$tmp/out" 2>&1 || { cat "$tmp/out"; fail "test-${suite} failed with docker stubbed"; }
  grep -q '^up APP_KEY=base64:.' "$calls" || fail "test-${suite} started the stack without an APP_KEY: $(cat "$calls")"
  grep -q '^DB_CONNECTION=pgsql$' "$env_file" || fail "test-${suite} lost the other settings of api/.env.docker"

  printf 'APP_KEY=base64:kept\n' >"$env_file"
  : >"$calls"
  ("cmd_test_${suite}") >"$tmp/out" 2>&1 || { cat "$tmp/out"; fail "test-${suite} failed with docker stubbed"; }
  [ "$(cat "$calls")" = "up APP_KEY=base64:kept" ] || fail "test-${suite} replaced a key that was set: $(cat "$calls")"
done

echo "ok - module suites start the compose stack with an APP_KEY"
