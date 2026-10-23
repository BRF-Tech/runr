#!/usr/bin/env bash
# Tests the parts of lib/test-helm-upgrade.sh that need no cluster:
#   - the Kind cluster's nodes: a control-plane and a worker; --dedicated-worker a second worker;
#     KIND_WORKERS=0 (a host short of disk) the control-plane alone, unless --dedicated-worker;
#   - the install values of E2E_EXECUTOR_RUNTIMES: the API's EXECUTOR_RUNTIMES through extraEnv,
#     nothing without it (the chart's defaults);
#   - the tenant role next to admin and viewer per installed version, the word and SQL helpers.
#
#   bash runtime/test/helm_upgrade_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/test-helm-upgrade.sh
source "$here/lib/test-helm-upgrade.sh"

roles() { _upg_kind_config | sed -n 's/^  - role: //p' | tr '\n' ' '; }

# --- the Kind cluster's nodes ------------------------------------------------------------------
unset KIND_WORKERS
_upg_dedicated=0
[ "$(roles)" = "control-plane worker " ] || fail "default nodes: $(roles)"
_upg_kind_config | grep -F 'config_path = "/etc/containerd/certs.d"' >/dev/null || fail "registry config_path missing: $(_upg_kind_config)"
[ "$(KIND_WORKERS=0 roles)" = "control-plane " ] || fail "KIND_WORKERS=0 nodes: $(KIND_WORKERS=0 roles)"
[ "$(KIND_WORKERS=2 roles)" = "control-plane worker " ] || fail "KIND_WORKERS=2 nodes: $(KIND_WORKERS=2 roles)"
_upg_dedicated=1
[ "$(roles)" = "control-plane worker worker " ] || fail "--dedicated-worker nodes: $(roles)"
[ "$(KIND_WORKERS=0 roles)" = "control-plane worker worker " ] || fail "--dedicated-worker with KIND_WORKERS=0: $(KIND_WORKERS=0 roles)"
_upg_dedicated=0

# --- install values -----------------------------------------------------------------------------
unset E2E_EXECUTOR_RUNTIMES
[ -z "$(_upg_install_env_values)" ] || fail "values without E2E_EXECUTOR_RUNTIMES: $(_upg_install_env_values)"
out=$(E2E_EXECUTOR_RUNTIMES=python _upg_install_env_values)
[ "$out" = $'extraEnv:\n  EXECUTOR_RUNTIMES: "python"' ] || fail "E2E_EXECUTOR_RUNTIMES=python: $out"
out=$(E2E_EXECUTOR_RUNTIMES=python,nodejs _upg_install_env_values)
[ "$out" = $'extraEnv:\n  EXECUTOR_RUNTIMES: "python,nodejs"' ] || fail "E2E_EXECUTOR_RUNTIMES=python,nodejs: $out"
if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' 2>/dev/null; then
  [ "$(printf '%s\n' "$out" | python3 -c 'import sys, yaml; print(yaml.safe_load(sys.stdin)["extraEnv"]["EXECUTOR_RUNTIMES"])')" = "python,nodejs" ] \
    || fail "install values are no YAML map extraEnv.EXECUTOR_RUNTIMES: $out"
fi

# --- tenant roles and helpers -------------------------------------------------------------------
[ "$(_upg_mt_second_role 0.4.4)" = member ] || fail "0.4.4: $(_upg_mt_second_role 0.4.4)"
[ "$(_upg_mt_second_role 0.5.10)" = developer ] || fail "0.5.10: $(_upg_mt_second_role 0.5.10)"
_upg_words " a b.c d " a b.c || fail "_upg_words: present words not found"
_upg_words " a b.c d " d || fail "_upg_words: d"
_upg_words " a b.c d " b && fail "_upg_words: b found in b.c"
_upg_words " a bc d " b && fail "_upg_words: b found in bc"
_upg_words " a b " a e && fail "_upg_words: e found"
[ "$(_upg_sql_literal "it's")" = "'it''s'" ] || fail "_upg_sql_literal: $(_upg_sql_literal "it's")"
[ "$(_upg_sql_literal '["a","b"]')" = "'[\"a\",\"b\"]'" ] || fail "_upg_sql_literal JSON: $(_upg_sql_literal '["a","b"]')"

echo "ok: helm upgrade"
