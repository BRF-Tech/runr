#!/bin/bash
set -euo pipefail

# ── Knative Release Checker ──────────────────────────────────────────────────
# Fetches the available Knative Operator chart versions from the Helm repo and
# checks the commented-out (unreleased) rows of the
# KnativeVersionHelper::KNATIVE_K8S_COMPAT table. When a new release exists it
# updates the table and opens a GitLab MR.
#
# Required env:
#   GITLAB_TOKEN       — api scope, to open the MR
#   CI_PROJECT_ID      — GitLab project ID (automatic in CI)
#   CI_SERVER_URL      — GitLab URL (automatic in CI)
#   CI_DEFAULT_BRANCH  — default branch (automatic in CI)
# ─────────────────────────────────────────────────────────────────────────────

COMPAT_FILE="api/app/Services/Helpers/KnativeVersionHelper.php"
HELM_REPO_URL="https://knative.github.io/operator"
HELM_REPO_NAME="knative-operator"

# ── 1. Get the available versions from the Helm repo ────────────────────────
helm repo add "$HELM_REPO_NAME" "$HELM_REPO_URL" --force-update >/dev/null 2>&1
helm repo update >/dev/null 2>&1

# Get all chart versions, extract the minors (unique)
AVAILABLE_MINORS=$(helm search repo "$HELM_REPO_NAME/knative-operator" --versions -o json \
    | jq -r '.[].version | ltrimstr("v") | split(".")[0:2] | join(".")' \
    | sort -Vu)

echo "Knative minor versions in the Helm repo:"
echo "$AVAILABLE_MINORS"
echo ""

# ── 2. Check the unreleased rows of the compat table ────────────────────────
UPDATED=false
NEWLY_RELEASED=()

while IFS= read -r line; do
    # Match the commented-out unreleased rows: // 23 => 34, // unreleased
    if echo "$line" | grep -qP '//\s*(\d+)\s*=>\s*(\d+).*unreleased'; then
        KN_MINOR=$(echo "$line" | grep -oP '//\s*\K\d+(?=\s*=>)')
        MIN_K8S=$(echo "$line" | grep -oP '=>\s*\K\d+')
        TARGET="1.${KN_MINOR}"

        if echo "$AVAILABLE_MINORS" | grep -qx "$TARGET"; then
            echo "✓ Knative $TARGET is now available! (min K8s 1.$MIN_K8S)"
            NEWLY_RELEASED+=("$TARGET")
            UPDATED=true

            # Remove the comment: "// 23 => 34, // unreleased" → "23 => 34,"
            sed -i "s|// *${KN_MINOR} *=> *${MIN_K8S},.*unreleased|${KN_MINOR} => ${MIN_K8S},|" "$COMPAT_FILE"
        else
            echo "✗ Knative $TARGET not released yet"
        fi
    fi
done < "$COMPAT_FILE"

# ── 3. Check for new versions in the repo that are missing from the table ──
# Find the highest minor in the table
HIGHEST_IN_TABLE=$(grep -oP '^\s+(\d+)\s*=>' "$COMPAT_FILE" | grep -oP '\d+' | sort -n | tail -1)
# Including the commented-out ones
HIGHEST_COMMENTED=$(grep -oP '//\s*(\d+)\s*=>' "$COMPAT_FILE" | grep -oP '\d+' | sort -n | tail -1)
HIGHEST=${HIGHEST_COMMENTED:-$HIGHEST_IN_TABLE}

echo ""
echo "Highest Knative minor in the table: 1.$HIGHEST"

for MINOR_VER in $AVAILABLE_MINORS; do
    KN_MINOR=$(echo "$MINOR_VER" | cut -d. -f2)
    if [ "$KN_MINOR" -gt "$HIGHEST" ]; then
        echo "⚠ Knative 1.$KN_MINOR is not in the table! Add it manually (check the min K8s constraint)"
        echo "  Source: https://github.com/knative/community/blob/main/mechanics/RELEASE-SCHEDULE.md"
    fi
done

if [ "$UPDATED" = false ]; then
    echo ""
    echo "No new releases, no MR will be opened."
    exit 0
fi

# ── 4. Pin a fallback chart for each newly released minor ───────────────────
# FALLBACK_CHART_VERSIONS: the latest patch of each minor (used when the chart repository
# cannot be read; the newest one the cluster's Kubernetes version supports is picked)
LATEST_CHART=""
for TARGET in "${NEWLY_RELEASED[@]}"; do
    CHART=$(helm search repo "$HELM_REPO_NAME/knative-operator" --versions -o json \
        | jq -r --arg m "$TARGET." '[.[].version | ltrimstr("v") | select(startswith($m))][0] // empty')
    [ -n "$CHART" ] || continue
    if grep -q "'${TARGET}' => '" "$COMPAT_FILE"; then
        sed -i "s|'${TARGET}' => '[^']*',|'${TARGET}' => '${CHART}',|" "$COMPAT_FILE"
    else
        sed -i "s|public const FALLBACK_CHART_VERSIONS = \[|public const FALLBACK_CHART_VERSIONS = [\n        '${TARGET}' => '${CHART}',|" "$COMPAT_FILE"
    fi
    LATEST_CHART="${LATEST_CHART:+$LATEST_CHART, }${TARGET} → ${CHART}"
    echo "FALLBACK_CHART_VERSIONS: ${TARGET} → ${CHART}"
done

# ── 5. Git commit + push + MR ───────────────────────────────────────────────
VERSIONS_STR=$(printf ", %s" "${NEWLY_RELEASED[@]}")
VERSIONS_STR=${VERSIONS_STR:2}
BRANCH="knative-release/${NEWLY_RELEASED[0]}"

git config user.email "ci@runr.sh"
git config user.name "RunR CI"
git checkout -b "$BRANCH"
git add "$COMPAT_FILE"
git commit -m "feat: add Knative ${VERSIONS_STR} to compat matrix

New Knative Operator chart(s) detected in Helm repo.
KNATIVE_K8S_COMPAT table updated automatically.

Source: https://github.com/knative/community/blob/main/mechanics/RELEASE-SCHEDULE.md"

git push -o ci.skip "https://gitlab-ci-token:${GITLAB_TOKEN}@${CI_SERVER_URL#https://}/${CI_PROJECT_PATH}.git" "$BRANCH"

# Open the MR
curl -sf --header "PRIVATE-TOKEN: ${GITLAB_TOKEN}" \
    "${CI_SERVER_URL}/api/v4/projects/${CI_PROJECT_ID}/merge_requests" \
    --data-urlencode "source_branch=${BRANCH}" \
    --data-urlencode "target_branch=${CI_DEFAULT_BRANCH}" \
    --data-urlencode "title=feat: Knative ${VERSIONS_STR} released" \
    --data-urlencode "description=## Knative New Release

New Knative Operator version(s) detected in the Helm repo:

$(printf "- **%s**\n" "${NEWLY_RELEASED[@]}")

### Changes
- Removed the unreleased comment in the \`KNATIVE_K8S_COMPAT\` table
- Pinned fallback charts (\`FALLBACK_CHART_VERSIONS\`): ${LATEST_CHART}

### To check
- [ ] Is the min K8s version correct? ([Release Schedule](https://github.com/knative/community/blob/main/mechanics/RELEASE-SCHEDULE.md))
- [ ] Is the compat matrix order correct?
- [ ] Try it on a test cluster

🤖 Created automatically (scheduled pipeline)" \
    --data-urlencode "remove_source_branch=true" \
    --data-urlencode "labels=knative,automated" | jq -r '.web_url'

echo ""
echo "MR created!"
