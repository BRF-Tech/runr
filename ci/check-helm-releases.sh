#!/bin/bash
set -euo pipefail

# ── Helm Dependency Release Checker ──────────────────────────────────────────
# Checks every Helm dependency for new versions.
# Opens an informational MR when a new version exists.
#
# Required env:
#   GITLAB_TOKEN       — api scope
#   CI_PROJECT_ID      — GitLab project ID
#   CI_SERVER_URL      — GitLab URL
#   CI_PROJECT_PATH    — GitLab project path
#   CI_DEFAULT_BRANCH  — default branch
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

NEW_RELEASES=()

# ── Helper: check whether an MR already exists ───────────────────────────────
mr_exists() {
    local branch="$1"
    local count
    count=$(curl -sf --header "PRIVATE-TOKEN: ${GITLAB_TOKEN}" \
        "${CI_SERVER_URL}/api/v4/projects/${CI_PROJECT_ID}/merge_requests?source_branch=${branch}&state=opened" \
        | jq 'length')
    [ "$count" -gt 0 ]
}

# ── Helper: get the latest version from a Helm repo ──────────────────────────
get_latest_helm() {
    local repo_name="$1" repo_url="$2" chart="$3"
    helm repo add "$repo_name" "$repo_url" --force-update >/dev/null 2>&1
    helm repo update >/dev/null 2>&1
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

# ── Helper: read the previous-version cache file ─────────────────────────────
CACHE_DIR="${CI_PROJECT_DIR:-.}/.helm-version-cache"
mkdir -p "$CACHE_DIR"

get_cached_version() {
    local name="$1"
    cat "${CACHE_DIR}/${name}" 2>/dev/null || echo ""
}

save_cached_version() {
    local name="$1" version="$2"
    echo "$version" > "${CACHE_DIR}/${name}"
}

# ── Main loop ────────────────────────────────────────────────────────────────
echo "══════════════════════════════════════════════════════════"
echo "  Helm Dependency Release Check"
echo "══════════════════════════════════════════════════════════"
echo ""

for dep in "${DEPS[@]}"; do
    IFS='|' read -r NAME REPO CHART TYPE <<< "$dep"

    echo "▶ ${NAME}..."

    if [ "$TYPE" = "oci" ]; then
        LATEST=$(get_latest_oci "$REPO")
    else
        LATEST=$(get_latest_helm "$NAME" "$REPO" "$CHART")
    fi

    if [ -z "$LATEST" ]; then
        echo "  ✗ Could not get the version, skipping"
        continue
    fi

    CACHED=$(get_cached_version "$NAME")
    echo "  Latest: ${LATEST}  |  Previous: ${CACHED:-unknown}"

    if [ "$LATEST" = "$CACHED" ]; then
        echo "  ✓ No change"
        continue
    fi

    # New version detected
    save_cached_version "$NAME" "$LATEST"

    # Was an MR already opened?
    BRANCH="deps/${NAME}-${LATEST}"
    if mr_exists "$BRANCH"; then
        echo "  ⏭ MR already open: $BRANCH"
        continue
    fi

    echo "  ★ New version: ${LATEST}"
    NEW_RELEASES+=("${NAME}|${LATEST}|${BRANCH}")
done

echo ""

if [ ${#NEW_RELEASES[@]} -eq 0 ]; then
    echo "No new releases."
    exit 0
fi

# ── Git commit + open MR ────────────────────────────────────────────────────
git config user.email "ci@runr.sh"
git config user.name "RunR CI"

# Commit the cache files (for comparison on the next run)
git add "$CACHE_DIR/" 2>/dev/null || true

for release in "${NEW_RELEASES[@]}"; do
    IFS='|' read -r NAME VERSION BRANCH <<< "$release"

    echo "▶ Creating MR: ${NAME} ${VERSION}"

    git checkout -B "$BRANCH" "${CI_DEFAULT_BRANCH}"
    git add "$CACHE_DIR/" 2>/dev/null || true

    git commit --allow-empty -m "deps: ${NAME} ${VERSION} released

New version detected for ${NAME}: ${VERSION}
This is an informational MR — review and update if needed."

    git push -f -o ci.skip \
        "https://gitlab-ci-token:${GITLAB_TOKEN}@${CI_SERVER_URL#https://}/${CI_PROJECT_PATH}.git" \
        "$BRANCH"

    # Create the MR
    MR_URL=$(curl -sf --header "PRIVATE-TOKEN: ${GITLAB_TOKEN}" \
        "${CI_SERVER_URL}/api/v4/projects/${CI_PROJECT_ID}/merge_requests" \
        --data-urlencode "source_branch=${BRANCH}" \
        --data-urlencode "target_branch=${CI_DEFAULT_BRANCH}" \
        --data-urlencode "title=deps: ${NAME} ${VERSION} released" \
        --data-urlencode "description=## New Release: ${NAME} ${VERSION}

| Dependency | New Version |
|------------|-------------|
| **${NAME}** | \`${VERSION}\` |

### Action Items
- [ ] Check the changelog
- [ ] Any breaking changes?
- [ ] Update the code if needed
- [ ] Test

🤖 Created automatically (scheduled pipeline)" \
        --data-urlencode "remove_source_branch=true" \
        --data-urlencode "labels=dependencies,automated" \
        | jq -r '.web_url')

    echo "  ✓ MR: ${MR_URL}"
done

echo ""
echo "══════════════════════════════════════════════════════════"
echo "  ${#NEW_RELEASES[@]} new release(s) detected, MRs created."
echo "══════════════════════════════════════════════════════════"
