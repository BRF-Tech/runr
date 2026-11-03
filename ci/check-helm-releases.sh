#!/bin/bash
set -euo pipefail

# ── Helm dependency release check ────────────────────────────────────────────
# The latest version of every Helm chart RunR installs into a region from an upstream repository.
# Each one is printed, and written to RELEASES_REPORT_DIR when that is set: a Markdown file whose
# first line is its title, "deps: <chart> <version> released". .github/workflows/upstream-releases.yml
# files an issue for each title no issue has yet, so every new version is reported once.
#
#   bash ci/check-helm-releases.sh
#   RELEASES_REPORT_DIR=DIR bash ci/check-helm-releases.sh
#
# Needs bash, helm and jq.
# ─────────────────────────────────────────────────────────────────────────────

# ── Dependency definitions ───────────────────────────────────────────────────
# Format: NAME|REPO_URL|CHART_NAME|TYPE
# TYPE: helm (regular repo) or oci (OCI registry)
DEPS=(
    "traefik|https://traefik.github.io/charts|traefik|helm"
    "kube-prometheus-stack|https://prometheus-community.github.io/helm-charts|kube-prometheus-stack|helm"
    "loki-stack|https://grafana.github.io/helm-charts|loki-stack|helm"
    "zot|https://zotregistry.dev/helm-charts|zot|helm"
    "kata-deploy|ghcr.io/kata-containers/kata-deploy-charts/kata-deploy|kata-deploy|oci"
)

# ── Helper: get the latest version from a Helm repo ──────────────────────────
get_latest_helm() {
    local repo_name="$1" repo_url="$2" chart="$3"
    helm repo add "$repo_name" "$repo_url" --force-update >/dev/null 2>&1
    helm repo update "$repo_name" >/dev/null 2>&1
    helm search repo "${repo_name}/${chart}" --versions -o json \
        | jq -r '.[0].version // empty' 2>/dev/null
}

# ── Helper: get the latest version from an OCI registry ──────────────────────
get_latest_oci() {
    local chart_ref="$1"
    # helm show chart returns the latest version for OCI
    helm show chart "oci://${chart_ref}" 2>/dev/null \
        | grep '^version:' | awk '{print $2}' || true
}

# report NAME TITLE BODY — one finding as RELEASES_REPORT_DIR/NAME.md
report() {
    [ -n "${RELEASES_REPORT_DIR:-}" ] || return 0
    mkdir -p "$RELEASES_REPORT_DIR"
    printf '%s\n\n%s\n' "$2" "$3" > "${RELEASES_REPORT_DIR}/$1.md"
}

# ── Main loop ────────────────────────────────────────────────────────────────
echo "══════════════════════════════════════════════════════════"
echo "  Helm Dependency Release Check"
echo "══════════════════════════════════════════════════════════"
echo ""

unread=0
for dep in "${DEPS[@]}"; do
    IFS='|' read -r NAME REPO CHART TYPE <<< "$dep"

    if [ "$TYPE" = "oci" ]; then
        LATEST=$(get_latest_oci "$REPO")
        SOURCE="oci://${REPO}"
    else
        LATEST=$(get_latest_helm "$NAME" "$REPO" "$CHART" || true)
        SOURCE="${REPO} (${CHART})"
    fi

    if [ -z "$LATEST" ]; then
        echo "✗ ${NAME}: could not read the latest version from ${SOURCE}"
        unread=$((unread + 1))
        continue
    fi

    echo "▶ ${NAME}: ${LATEST}"
    report "deps-${NAME}-${LATEST}" "deps: ${NAME} ${LATEST} released" \
"New version of the Helm chart **${NAME}**: \`${LATEST}\` (${SOURCE}).

- [ ] Check the changelog
- [ ] Any breaking changes?
- [ ] Update the pinned version and the code if needed
- [ ] Test

Reported by ci/check-helm-releases.sh."
done

echo ""
if [ "$unread" -gt 0 ]; then
    echo "${unread} chart(s) could not be read (network, repository moved): see above."
    exit 1
fi
echo "Every chart read."
