#!/usr/bin/env bash
# Tests the parts of lib/test-helm-upgrade.sh that need no cluster:
#   - the Kind cluster's nodes: a control-plane and a worker; --dedicated-worker a second worker;
#     KIND_WORKERS=0 (a host short of disk) the control-plane alone, unless --dedicated-worker;
#     KIND_ETCD_IN_MEMORY=1: the control-plane's etcd data on its tmpfs, as in the other Kind suites;
#   - the install values of E2E_EXECUTOR_RUNTIMES: the API's EXECUTOR_RUNTIMES through extraEnv,
#     nothing without it (the chart's defaults);
#   - the tenant role next to admin and viewer per installed version, the word and SQL helpers;
#   - the function warmed before the upgrade's prober starts (_upg_function_warm).
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

# --- KIND_ETCD_IN_MEMORY: etcd on the control-plane's tmpfs, as in the other Kind suites ---------
unset KIND_ETCD_IN_MEMORY
config=$(_upg_kind_config)
case "$config" in *etcd*|*kubeadmConfigPatches*) fail "etcd patch without KIND_ETCD_IN_MEMORY: ${config}" ;; esac
config=$(KIND_ETCD_IN_MEMORY=0 _upg_kind_config)
case "$config" in *dataDir*) fail "KIND_ETCD_IN_MEMORY=0 patches etcd: ${config}" ;; esac
config=$(KIND_ETCD_IN_MEMORY=1 _upg_kind_config)
case "$config" in
  *$'\n    kubeadmConfigPatches:\n      - |\n        kind: ClusterConfiguration\n        etcd:\n          local:\n            dataDir: /tmp/etcd'*) ;;
  *) fail "KIND_ETCD_IN_MEMORY=1: the control-plane has no etcd dataDir /tmp/etcd: ${config}" ;;
esac
[ "$(KIND_ETCD_IN_MEMORY=1 roles)" = "control-plane worker " ] || fail "KIND_ETCD_IN_MEMORY=1 nodes: $(KIND_ETCD_IN_MEMORY=1 roles)"
[ "$(KIND_ETCD_IN_MEMORY=1 KIND_WORKERS=0 roles)" = "control-plane " ] || fail "KIND_ETCD_IN_MEMORY=1 KIND_WORKERS=0 nodes"
if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' 2>/dev/null; then
  for workers in 0 1; do
    out=$(KIND_ETCD_IN_MEMORY=1 KIND_WORKERS=$workers _upg_kind_config | python3 -c '
import sys, yaml
c = yaml.safe_load(sys.stdin)
cp = c["nodes"][0]
patch = yaml.safe_load(cp["kubeadmConfigPatches"][0])
print(cp["role"], patch["kind"], patch["etcd"]["local"]["dataDir"], len(c["nodes"]))') \
      || fail "KIND_ETCD_IN_MEMORY=1 KIND_WORKERS=${workers}: the Kind config is no YAML: $(KIND_ETCD_IN_MEMORY=1 KIND_WORKERS=$workers _upg_kind_config)"
    [ "$out" = "control-plane ClusterConfiguration /tmp/etcd $((workers + 1))" ] || fail "KIND_ETCD_IN_MEMORY=1 KIND_WORKERS=${workers}: ${out}"
  done
fi

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

# --- the function answers before the prober starts ------------------------------------------------
# (a scaled-to-zero function's cold start is no request the upgrade lost)
gets="$(mktemp)"
trap 'rm -f "$gets"' EXIT
echo 0 > "$gets"
# answers on the third GET, as a function starting from zero does
_upg_function_get() {
  local n=$(( $(cat "$gets") + 1 ))
  echo "$n" > "$gets"
  if [ "$n" -ge 3 ]; then echo '{"plain":"hello-plain"}'; else echo ' (curl exit 28)'; fi
}
out=$(UPG_WARM_SLEEP=0 _upg_function_warm) || fail "_upg_function_warm: a function answering on the third GET: ${out}"
[ "$out" = '{"plain":"hello-plain"}' ] || fail "_upg_function_warm prints the answer: ${out}"
[ "$(cat "$gets")" = 3 ] || fail "_upg_function_warm: $(cat "$gets") GETs, not 3"
_upg_function_get() { echo ' (curl exit 7)'; }
out=$(UPG_WARM_TRIES=2 UPG_WARM_SLEEP=0 _upg_function_warm) && fail "_upg_function_warm: a function that never answers passed"
[ "$out" = ' (curl exit 7)' ] || fail "_upg_function_warm prints the last answer: ${out}"

# --- --rollback (docs/upgrade.md → Back to 0.6.7) -------------------------------------------------
# only with the single-tenant edition's region and function; UPG_ROLLBACK_RUNTIME is a runtime id
out=$(cmd_test_helm_upgrade --edition=multi-tenant --rollback 2>&1) && fail "--rollback with the multi-tenant edition passed"
[[ "$out" == *"--rollback needs the single-tenant edition's region and function"* ]] || fail "--rollback, multi-tenant: ${out}"
out=$(UPG_ROLLBACK_RUNTIME='Java 25' cmd_test_helm_upgrade --rollback 2>&1) && fail "an UPG_ROLLBACK_RUNTIME that is no id passed"
[[ "$out" == *"UPG_ROLLBACK_RUNTIME: a runtime id, not Java 25"* ]] || fail "UPG_ROLLBACK_RUNTIME check: ${out}"

# the revision helm rollback goes back to: the newest superseded one
history='[{"revision":1,"status":"superseded","app_version":"0.6.7"},{"revision":2,"status":"deployed","app_version":"0.6.8"}]'
[ "$(_upg_previous_revision "$history")" = 1 ] || fail "_upg_previous_revision: $(_upg_previous_revision "$history")"
history='[{"revision":1,"status":"superseded"},{"revision":2,"status":"superseded"},{"revision":3,"status":"failed"},{"revision":4,"status":"deployed"}]'
[ "$(_upg_previous_revision "$history")" = 2 ] || fail "_upg_previous_revision after a failed upgrade: $(_upg_previous_revision "$history")"
[ -z "$(_upg_previous_revision '[{"revision":1,"status":"deployed"}]')" ] || fail "_upg_previous_revision of a release without an earlier revision"

# word lists and SQL
[ "$(_upg_only_in "python nodejs bun java" "nodejs python")" = "bun java" ] || fail "_upg_only_in: $(_upg_only_in "python nodejs bun java" "nodejs python")"
[ "$(_upg_only_in "a b" "")" = "a b" ] || fail "_upg_only_in of an empty second list"
[ -z "$(_upg_only_in "" "a")" ] || fail "_upg_only_in of an empty first list"
[ "$(_upg_in_both "bun dotnet java ruby" "python java nodejs ruby")" = "java ruby" ] || fail "_upg_in_both: $(_upg_in_both "bun dotnet java ruby" "python java nodejs ruby")"
[ -z "$(_upg_in_both "bun java" "python go")" ] || fail "_upg_in_both without common words"
[ "$(_upg_count_words "a b  c")" = 3 ] || fail "_upg_count_words: $(_upg_count_words "a b  c")"
[ "$(_upg_count_words "")" = 0 ] || fail "_upg_count_words of nothing"
[ "$(_upg_sql_list "a b'c")" = "'a','b''c'" ] || fail "_upg_sql_list: $(_upg_sql_list "a b'c")"
[ "$(_upg_sql_list "")" = "''" ] || fail "_upg_sql_list of nothing: $(_upg_sql_list "")"

# the runtimes only this checkout's catalog has, against 0.6.7's: the default UPG_ROLLBACK_RUNTIME is one
tmp="$(mktemp -d)"
trap 'rm -f "$gets"; rm -rf "$tmp"' EXIT
printf '%s' '{"runtimes":[{"id":"python"},{"id":"nodejs"},{"id":"go"},{"id":"php"},{"id":"tensorflow"},{"id":"pytorch"},{"id":"wasm-exec"},{"id":"wasm-go"},{"id":"wasm-js"},{"id":"wasm-python"},{"id":"wasm-rust"}]}' > "${tmp}/builtin-067.json"
new=$(_upg_only_in "$(_upg_bundle_ids "${here}/../api/resources/runtimes/builtin.json")" "$(_upg_bundle_ids "${tmp}/builtin-067.json")")
[ "$new" = "bun dotnet java ruby" ] || fail "runtimes only this catalog has, against 0.6.7's: ${new}"
_upg_words " ${new} " java || fail "the default UPG_ROLLBACK_RUNTIME (java) is no new runtime: ${new}"
[ -z "$(_upg_bundle_ids "${tmp}/missing.json")" ] || fail "_upg_bundle_ids of a missing file"

# the API's answers
catalog='{"data":{"mode":"bundle","runtimes":[{"id":"python","offered":true},{"id":"java","offered":false},{"id":"go","offered":true}]}}'
[ "$(_upg_catalog_ids "$catalog")" = "python java go" ] || fail "_upg_catalog_ids: $(_upg_catalog_ids "$catalog")"
[ "$(_upg_offered_ids "$catalog")" = "python go" ] || fail "_upg_offered_ids: $(_upg_offered_ids "$catalog")"
[ "$(_upg_offer_body "python go" java)" = '{"builtin_runtimes":["go","java","python"]}' ] || fail "_upg_offer_body: $(_upg_offer_body "python go" java)"
[ "$(_upg_offer_body "" java)" = '{"builtin_runtimes":["java"]}' ] || fail "_upg_offer_body of nothing offered: $(_upg_offer_body "" java)"
[ "$(_upg_runtime_ids '{"data":[{"id":"python"},{"id":"wasm-go"}]}')" = "python wasm-go" ] || fail "_upg_runtime_ids"
[ "$(_upg_picker_runtimes '{"executor_images":[{"runtime":"python","version":"3.14"},{"runtime":"python","version":"3.13"},{"runtime":"go"}]}')" = "go python" ] \
  || fail "_upg_picker_runtimes"

# a function by its host: until the answer has the text
echo 0 > "$gets"
_upg_host_get() {
  local n=$(( $(cat "$gets") + 1 ))
  echo "$n" > "$gets"
  if [ "$n" -ge 2 ]; then echo "Hello, World!"; else echo ' (curl exit 7)'; fi
}
out=$(UPG_WARM_SLEEP=0 _upg_host_wait fn.upgrade.test "Hello, World!") || fail "_upg_host_wait: a function answering on the second GET: ${out}"
[ "$out" = "Hello, World!" ] || fail "_upg_host_wait prints the answer: ${out}"
out=$(UPG_WARM_TRIES=2 UPG_WARM_SLEEP=0 _upg_host_wait fn.upgrade.test "Hello, 0.6.7!") && fail "_upg_host_wait: an answer without the text passed"
[ "$out" = "Hello, World!" ] || fail "_upg_host_wait prints the last answer: ${out}"

# deploys through a fake API: the deployment the deploy answered, not the project's latest
calls="${tmp}/calls"
: > "$calls"
_upg_call() {
  local n
  echo "$1 $2 ${4:-}" >> "$calls"
  n=$(grep -c "^$1 $2 " "$calls")
  case "$1 $2" in
    "GET /api/projects/p1") _upg_code=200; _upg_body='{"data":{"latest_version":{"id":"v1","build_status":"ready"},"latest_deployment":{"status":"active"}}}' ;;
    "POST /api/projects/p1/deploy") _upg_code=202; _upg_body='{"id":"d2","status":"pending"}' ;;
    "GET /api/projects/p1/deployments/d2")
      _upg_code=200
      if [ "$n" -ge 2 ]; then _upg_body='{"data":{"id":"d2","status":"active"}}'; else _upg_body='{"data":{"id":"d2","status":"running"}}'; fi ;;
    "POST /api/projects/p2/code") _upg_code=201; _upg_body='{"version":{"id":"v3"}}' ;;
    "GET /api/projects/p2")
      _upg_code=200
      if [ "$n" -ge 2 ]; then _upg_body='{"data":{"latest_version":{"id":"v3","build_status":"ready"}}}'; else _upg_body='{"data":{"latest_version":{"id":"v3","build_status":"building"}}}'; fi ;;
    "POST /api/projects/p2/deploy") _upg_code=202; _upg_body='{"id":"d4"}' ;;
    "GET /api/projects/p2/deployments/d4") _upg_code=200; _upg_body='{"data":{"id":"d4","status":"failed"}}' ;;
    "POST /api/projects/p3/deploy") _upg_code=422; _upg_body='{"message":"Runtime java is not registered"}' ;;
    *) _upg_code=404; _upg_body='{}' ;;
  esac
}
UPG_WAIT_SLEEP=0 _upg_redeploy p1 token >/dev/null || fail "_upg_redeploy: $(cat "$calls")"
grep -qxF 'POST /api/projects/p1/deploy {"version_id":"v1"}' "$calls" || fail "_upg_redeploy deploys the latest version: $(cat "$calls")"
[ "$(grep -c '^GET /api/projects/p1/deployments/d2 ' "$calls")" = 2 ] || fail "_upg_redeploy waits for the deployment it made: $(cat "$calls")"
UPG_WAIT_SLEEP=0 _upg_redeploy p3 token >/dev/null && fail "_upg_redeploy: a refused deploy passed"
: > "$calls"
out=$(UPG_WAIT_SLEEP=0 _upg_deploy_code p2 token 'Hello, 0.6.7!') && fail "_upg_deploy_code: a failed deployment passed"
[[ "$out" == *"deployment d4 of p2: failed"* ]] || fail "_upg_deploy_code says which deployment failed: ${out}"
grep -qxF 'POST /api/projects/p2/code {"code":"Hello, 0.6.7!","message":"upgrade e2e: after the rollback"}' "$calls" || fail "_upg_deploy_code saves the code: $(cat "$calls")"
[ "$(grep -c '^GET /api/projects/p2 ' "$calls")" = 2 ] || fail "_upg_deploy_code waits for the build: $(cat "$calls")"
grep -qxF 'POST /api/projects/p2/deploy {"version_id":"v3"}' "$calls" || fail "_upg_deploy_code deploys the new version: $(cat "$calls")"
out=$(UPG_WAIT_SLEEP=0 _upg_wait_deployment_id p1 token "") && fail "_upg_wait_deployment_id without an id passed"

echo "ok: helm upgrade"
