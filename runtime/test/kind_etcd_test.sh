#!/usr/bin/env bash
# Tests that every suite creating a Kind cluster honours KIND_ETCD_IN_MEMORY (etcd's data on the
# control-plane's tmpfs, for hosts whose disk is too slow for etcd's fsyncs), without Kind:
#   - kind_etcd_patch (lib/common.sh): the etcd lines of a ClusterConfiguration patch with
#     KIND_ETCD_IN_MEMORY=1, nothing with 0 or unset;
#   - the second clusters of test-region-move and test-global-projects: `kind` is a function that
#     keeps the --config it is given (and fails, so nothing else runs); the configs are YAML
#     whose control-plane patch has etcd's dataDir /tmp/etcd with the variable, none without;
#   - every lib that runs `kind create cluster` builds its config with kind_etcd_patch (test.sh,
#     test-helm-upgrade.sh — whose config helm_upgrade_test.sh checks — and the two above).
#
#   bash runtime/test/kind_etcd_test.sh
# shellcheck source-path=SCRIPTDIR
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/test-region-move.sh
source "$here/lib/test-region-move.sh"
# shellcheck source=../lib/test-global-projects.sh
source "$here/lib/test-global-projects.sh"

# --- kind_etcd_patch ------------------------------------------------------------------------------
unset KIND_ETCD_IN_MEMORY
[ -z "$(kind_etcd_patch)" ] || fail "kind_etcd_patch without KIND_ETCD_IN_MEMORY: $(kind_etcd_patch)"
[ -z "$(KIND_ETCD_IN_MEMORY=0 kind_etcd_patch)" ] || fail "KIND_ETCD_IN_MEMORY=0: $(KIND_ETCD_IN_MEMORY=0 kind_etcd_patch)"
[ "$(KIND_ETCD_IN_MEMORY=1 kind_etcd_patch)" = $'        etcd:\n          local:\n            dataDir: /tmp/etcd' ] \
  || fail "KIND_ETCD_IN_MEMORY=1: $(KIND_ETCD_IN_MEMORY=1 kind_etcd_patch)"

# --- the second clusters of region-move and global-projects --------------------------------------
# kind create cluster … --config FILE: the config is kept as $tmp/<cluster>.yaml, the create fails
kind() {
  local name="" config="" a
  while [ "$#" -gt 0 ]; do
    a="$1"
    shift
    case "$a" in
      --name) name="$1"; shift ;;
      --config) config="$1"; shift ;;
    esac
  done
  [ -n "$config" ] && cp "$config" "$tmp/${name}.yaml"
  return 1
}

# config <function> <cluster> <KIND_ETCD_IN_MEMORY>: the Kind config the function creates its cluster with
config() {
  rm -f "$tmp/$2.yaml"
  KIND_ETCD_IN_MEMORY="$3" "$1" e2e >/dev/null 2>&1 && fail "$1: the fake kind create succeeded"
  [ -f "$tmp/$2.yaml" ] || fail "$1 (KIND_ETCD_IN_MEMORY=$3) created no cluster with a --config"
  cat "$tmp/$2.yaml"
}

for case in "region_move_create_target e2e-to" "global_projects_create_target e2e-global"; do
  read -r fn cluster <<<"$case"
  off=$(config "$fn" "$cluster" 0)
  case "$off" in *dataDir*) fail "${fn}: etcd patched without KIND_ETCD_IN_MEMORY: ${off}" ;; esac
  on=$(config "$fn" "$cluster" 1)
  case "$on" in
    *$'        kind: ClusterConfiguration\n'*$'\n        etcd:\n          local:\n            dataDir: /tmp/etcd'*) ;;
    *) fail "${fn}: KIND_ETCD_IN_MEMORY=1 without etcd's dataDir /tmp/etcd in the ClusterConfiguration: ${on}" ;;
  esac
  if command -v python3 >/dev/null 2>&1 && python3 -c 'import yaml' 2>/dev/null; then
    for v in 0 1; do
      got=$(config "$fn" "$cluster" "$v" | python3 -c '
import sys, yaml
c = yaml.safe_load(sys.stdin)
patch = yaml.safe_load(c["nodes"][0]["kubeadmConfigPatches"][0])
print(c["nodes"][0]["role"], patch["kind"], (patch.get("etcd") or {}).get("local", {}).get("dataDir", "-"), "localhost" in patch["apiServer"]["certSANs"])') \
        || fail "${fn} (KIND_ETCD_IN_MEMORY=${v}): the Kind config is no YAML: $(config "$fn" "$cluster" "$v")"
      want="control-plane ClusterConfiguration - True"
      [ "$v" = 1 ] && want="control-plane ClusterConfiguration /tmp/etcd True"
      [ "$got" = "$want" ] || fail "${fn} (KIND_ETCD_IN_MEMORY=${v}): ${got}, not ${want}"
    done
  fi
done

# --- every lib that creates a Kind cluster uses it -------------------------------------------------
for lib in "$here"/lib/*.sh; do
  grep -q 'kind create cluster' "$lib" || continue
  grep -q 'kind_etcd_patch' "$lib" || fail "$(basename "$lib") creates a Kind cluster without kind_etcd_patch (KIND_ETCD_IN_MEMORY)"
done

echo "ok: KIND_ETCD_IN_MEMORY in every Kind suite"
