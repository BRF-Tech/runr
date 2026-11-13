#!/usr/bin/env bash
# Tests e2e_runtime_catalog (lib/test.sh) and its place in docker-compose.yml: the e2e stack reads
# the checkout's built-in runtime catalog (RUNR_RUNTIME_CATALOG_REF empty) for every suite but
# runtime-sources, which reads RunR's published signed catalog itself; a value the caller set is
# kept; the API services pass the variable through, an empty one as empty (${VAR-default}).
#
#   bash runtime/test/e2e_runtime_catalog_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
fail() { echo "FAIL: $*" >&2; exit 1; }

# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/test.sh
source "$here/lib/test.sh"

# ref SUITE [PRESET] — RUNR_RUNTIME_CATALOG_REF after e2e_runtime_catalog SUITE ("<unset>" when unset)
ref() {
  (
    unset RUNR_RUNTIME_CATALOG_REF
    [ $# -gt 1 ] && export RUNR_RUNTIME_CATALOG_REF="$2"
    e2e_runtime_catalog "$1" >/dev/null
    echo "${RUNR_RUNTIME_CATALOG_REF-<unset>}"
  )
}

for suite in all wasm supply-chain runtimes runtime-patches images; do
  [ "$(ref "$suite")" = "" ] || fail "${suite}: $(ref "$suite") instead of the built-in catalog (empty)"
done
[ "$(ref runtime-sources)" = "<unset>" ] || fail "runtime-sources: $(ref runtime-sources) instead of the published catalog (unset)"
[ "$(ref wasm ghcr.io/acme/catalog:1)" = ghcr.io/acme/catalog:1 ] || fail "a set reference was replaced: $(ref wasm ghcr.io/acme/catalog:1)"
[ "$(ref runtime-sources "")" = "" ] || fail "an empty reference set by the caller was replaced"
echo "ok - the e2e stack reads the checkout's built-in catalog, runtime-sources the published one, a set reference stays"

# docker-compose.yml: api, horizon and scheduler pass it, unset → the published catalog, empty → empty
n="$(grep -c -F 'RUNR_RUNTIME_CATALOG_REF: ${RUNR_RUNTIME_CATALOG_REF-ghcr.io/brf-tech/runr/runtime-catalog:stable}' "$root/docker-compose.yml")"
[ "$n" = 3 ] || fail "docker-compose.yml passes RUNR_RUNTIME_CATALOG_REF (keeping an empty value) to ${n} services, not api, horizon and scheduler"
echo "ok - docker-compose.yml passes the reference to api, horizon and scheduler, an empty one as empty"
