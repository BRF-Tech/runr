#!/usr/bin/env bash
# Tests ci/check-helm-releases.sh without a network: helm is a script on PATH that answers like the
# chart repositories, from one file per chart. The check runs on a throwaway tree with the PHP files
# it reads its pins from, so nothing here depends on the versions RunR pins. Checks that only a
# version newer than the pin is a finding (not the pin itself, not an older one, a number compared
# as a number, a leading v ignored), that a pin moved in the code ends the finding, that a sibling
# chart of the repository is not taken for the chart, that the title stays the one the workflow
# files issues by, and that a chart or a pin it cannot read fails the check with the rest checked.
#
#   bash runtime/test/helm_releases_check_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

repo="$tmp/repo"
mkdir -p "$repo/ci" "$repo/api/app/Services/Contracts" "$repo/api/app/Services/Concerns" "$repo/api/app/Services/Helpers" "$tmp/bin"
cp "$root/ci/check-helm-releases.sh" "$repo/ci/"

# pins TRAEFIK PROMETHEUS LOKI ZOT KATA — the constants as the code writes them
pins() {
  printf "<?php\ninterface KnativeConstants\n{\n    // Changes with TRAEFIK_CHART_VERSION\n    const TRAEFIK_CHART_VERSION = '%s';\n}\n" "$1" \
    > "$repo/api/app/Services/Contracts/KnativeConstants.php"
  printf "<?php\ntrait KnativePrometheusTrait\n{\n    public const PROMETHEUS_CHART_VERSION = '%s';\n}\n" "$2" \
    > "$repo/api/app/Services/Concerns/KnativePrometheusTrait.php"
  printf "<?php\nfinal class ManagedLoki\n{\n    public const RELEASE = 'loki-stack';\n\n    public const CHART_VERSION = '%s';\n}\n" "$3" \
    > "$repo/api/app/Services/Helpers/ManagedLoki.php"
  printf "<?php\nclass ZotRegistryService\n{\n    private const ZOT_CHART_VERSION = '%s';\n}\n" "$4" \
    > "$repo/api/app/Services/ZotRegistryService.php"
  printf "<?php\nclass KataRuntime\n{\n    public const KATA_VERSION = '%s';\n}\n" "$5" \
    > "$repo/api/app/Services/Helpers/KataRuntime.php"
}

# helm: what a repository holds of a chart is the file $FAKE_HELM/<name>; without it the repository
# has no such chart, and an OCI registry does not answer. Sibling charts sort first, as traefik's do
export FAKE_HELM="$tmp/charts"
mkdir -p "$FAKE_HELM"
cat > "$tmp/bin/helm" <<'EOF'
#!/usr/bin/env bash
case "$1 $2" in
  'repo add'|'repo update') exit 0 ;;
  'search repo')
    name="${3%%/*}"
    [ -f "$FAKE_HELM/$name" ] || { echo '[]'; exit 0; }
    printf '[{"name":"%s-a-sibling","version":"999.0.0"},{"name":"%s","version":"%s"}]\n' "$3" "$3" "$(cat "$FAKE_HELM/$name")"
    ;;
  'show chart')
    name="${3##*/}"
    [ -f "$FAKE_HELM/$name" ] || exit 1
    printf 'apiVersion: v2\nname: %s\nversion: %s\ndependencies:\n- name: sub\n  version: 999.0.0\n' "$name" "$(cat "$FAKE_HELM/$name")"
    ;;
  *) echo "helm: unexpected call: $*" >&2; exit 2 ;;
esac
EOF
chmod +x "$tmp/bin/helm"

# repo_has TRAEFIK PROMETHEUS LOKI ZOT KATA — the versions the repositories hold; - for none
repo_has() {
  local name version
  rm -f "$FAKE_HELM"/*
  for name in traefik kube-prometheus-stack loki-stack zot kata-deploy; do
    version="$1"
    shift
    [ "$version" = - ] || echo "$version" > "$FAKE_HELM/$name"
  done
}

reports="$tmp/reports"
out="$tmp/out"
# check — the run's output in $out, its exit code in $rc, the reports fresh in $reports
check() {
  rm -rf "$reports"
  rc=0
  PATH="$tmp/bin:$PATH" RELEASES_REPORT_DIR="$reports" bash "$repo/ci/check-helm-releases.sh" > "$out" 2>&1 || rc=$?
}
reported() { (cd "$reports" 2>/dev/null && ls -1 -- *.md 2>/dev/null) | tr '\n' ' ' || true; }

# --- every chart at its pin: nothing to report --------------------------------------------------
pins 40.3.0 92.18.0 2.10.3 0.1.130 3.28.0
repo_has 40.3.0 92.18.0 2.10.3 0.1.130 3.28.0
check
[ "$rc" = 0 ] || fail "at the pins: exit ${rc}: $(cat "$out")"
[ -z "$(reported)" ] || fail "at the pins: reported $(reported)"
[ "$(grep -c '^✓' "$out")" = 5 ] || fail "at the pins, five charts are fine: $(cat "$out")"
echo "ok - a chart at its pin is no finding (loki-stack's latest stays quiet)"

# --- a newer version is a finding, an older one is not ------------------------------------------
pins 40.9.0 92.18.0 2.10.3 0.1.130 3.28.0
repo_has 40.10.0 92.21.1 2.9.12 0.1.99 4.3.1
check
[ "$rc" = 0 ] || fail "newer versions: exit ${rc}: $(cat "$out")"
[ "$(reported)" = "deps-kata-deploy-4.3.1.md deps-kube-prometheus-stack-92.21.1.md deps-traefik-40.10.0.md " ] \
  || fail "reported: $(reported)"
[ "$(head -n 1 "$reports/deps-traefik-40.10.0.md")" = "deps: traefik 40.10.0 released" ] \
  || fail "the title the workflow files issues by: $(head -n 1 "$reports/deps-traefik-40.10.0.md")"
grep -qF '`40.9.0`' "$reports/deps-traefik-40.10.0.md" || fail "the report does not name the pin"
grep -qF 'TRAEFIK_CHART_VERSION' "$reports/deps-traefik-40.10.0.md" || fail "the report does not name the constant"
grep -q '^▶ traefik: 40.10.0 (pinned 40.9.0)$' "$out" || fail "the finding is not printed: $(cat "$out")"
grep -q '^✓ loki-stack: pinned 2.10.3' "$out" || fail "an older repository version: $(cat "$out")"
echo "ok - a newer version is reported with its pin, 40.10.0 is newer than 40.9.0, an older one is not"

# --- the pin moved in the code: the finding ends ------------------------------------------------
pins 40.10.0 92.21.1 2.10.3 0.1.130 4.3.1
check
[ "$rc" = 0 ] || fail "pins moved: exit ${rc}: $(cat "$out")"
[ -z "$(reported)" ] || fail "pins moved: reported $(reported)"
echo "ok - the check reads the constants: a bumped pin ends the finding"

# --- a leading v counts for nothing -------------------------------------------------------------
pins v40.10.0 92.21.1 2.10.3 0.1.130 4.3.1
repo_has 40.10.0 v92.21.1 2.10.3 0.1.130 4.3.1
check
[ -z "$(reported)" ] || fail "a leading v: reported $(reported)"
echo "ok - a leading v is the same version"

# --- what cannot be read fails the check, the rest is still checked -----------------------------
pins 40.3.0 92.18.0 2.10.3 0.1.130 3.28.0
repo_has 40.3.0 92.21.1 - 0.1.130 -
check
[ "$rc" = 1 ] || fail "unreadable charts: exit ${rc}: $(cat "$out")"
grep -q '^✗ loki-stack: could not read' "$out" || fail "a repository without the chart: $(cat "$out")"
grep -q '^✗ kata-deploy: could not read' "$out" || fail "an OCI registry that does not answer: $(cat "$out")"
[ "$(reported)" = "deps-kube-prometheus-stack-92.21.1.md " ] || fail "the charts it could read: $(reported)"

repo_has 40.3.0 92.18.0 2.10.3 0.1.130 3.28.0
printf "<?php\nclass ZotRegistryService\n{\n    private const ZOT_CHART = 'zot/zot';\n}\n" > "$repo/api/app/Services/ZotRegistryService.php"
check
[ "$rc" = 1 ] || fail "a renamed constant: exit ${rc}: $(cat "$out")"
grep -q '^✗ zot: no version in ZOT_CHART_VERSION of api/app/Services/ZotRegistryService.php$' "$out" || fail "a renamed constant: $(cat "$out")"
rm "$repo/api/app/Services/Helpers/KataRuntime.php"
check
[ "$rc" = 1 ] || fail "a missing file: exit ${rc}: $(cat "$out")"
grep -q '^✗ kata-deploy: no version in KATA_VERSION' "$out" || fail "a missing file: $(cat "$out")"
echo "ok - a chart or a pin it cannot read fails the check, the other charts are still checked"
