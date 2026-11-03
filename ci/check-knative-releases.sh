#!/bin/bash
set -euo pipefail

# ── Knative release check ────────────────────────────────────────────────────
# Reads the Knative Operator chart versions of its Helm repository and checks the commented-out
# (unreleased) rows of the KnativeVersionHelper::KNATIVE_K8S_COMPAT table. A minor that is out now
# gets its row uncommented and its latest chart pinned in FALLBACK_CHART_VERSIONS, in this
# checkout, for review; a minor newer than every row is named (its minimum Kubernetes minor is
# added by hand). Each finding is printed, and written to RELEASES_REPORT_DIR when that is set: a
# Markdown file whose first line is its title. .github/workflows/upstream-releases.yml files an
# issue for each title no issue has yet.
#
#   bash ci/check-knative-releases.sh
#   RELEASES_REPORT_DIR=DIR bash ci/check-knative-releases.sh
#
# Needs bash, helm, jq, git, GNU grep and sed.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMPAT_FILE="api/app/Services/Helpers/KnativeVersionHelper.php"
HELM_REPO_URL="https://knative.github.io/operator"
HELM_REPO_NAME="knative-operator"
SCHEDULE_URL="https://github.com/knative/community/blob/main/mechanics/RELEASE-SCHEDULE.md"

# report NAME TITLE BODY — one finding as RELEASES_REPORT_DIR/NAME.md
report() {
    [ -n "${RELEASES_REPORT_DIR:-}" ] || return 0
    mkdir -p "$RELEASES_REPORT_DIR"
    printf '%s\n\n%s\n' "$2" "$3" > "${RELEASES_REPORT_DIR}/$1.md"
}

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
HIGHEST_COMMENTED=$(grep -oP '//\s*(\d+)\s*=>' "$COMPAT_FILE" | grep -oP '\d+' | sort -n | tail -1 || true)
HIGHEST=${HIGHEST_COMMENTED:-$HIGHEST_IN_TABLE}

echo ""
echo "Highest Knative minor in the table: 1.$HIGHEST"

for MINOR_VER in $AVAILABLE_MINORS; do
    KN_MINOR=$(echo "$MINOR_VER" | cut -d. -f2)
    if [ "$KN_MINOR" -gt "$HIGHEST" ]; then
        echo "⚠ Knative 1.$KN_MINOR is not in the table! Add it manually (check the min K8s constraint)"
        echo "  Source: ${SCHEDULE_URL}"
        report "knative-1.${KN_MINOR}-missing" "Knative 1.${KN_MINOR} is not in KNATIVE_K8S_COMPAT" \
"The Knative Operator chart repository (${HELM_REPO_URL}) has Knative 1.${KN_MINOR}, newer than every row of \`KNATIVE_K8S_COMPAT\` in \`${COMPAT_FILE}\` (the highest is 1.${HIGHEST}).

- [ ] Add the row \`${KN_MINOR} => <minimum Kubernetes minor>\` ([release schedule](${SCHEDULE_URL}))
- [ ] Pin its latest chart in \`FALLBACK_CHART_VERSIONS\` (\`bash ci/check-knative-releases.sh\` does it for a commented-out row)
- [ ] Try it on a test cluster

Reported by ci/check-knative-releases.sh."
    fi
done

if [ "$UPDATED" = false ]; then
    echo ""
    echo "No unreleased row of the table has been released."
    exit 0
fi

# ── 4. Pin a fallback chart for each newly released minor ───────────────────
# FALLBACK_CHART_VERSIONS: the latest patch of each minor (used when the chart repository
# cannot be read; the newest one the cluster's Kubernetes version supports is picked). Each new
# minor goes on top, the oldest first: the list stays newest first.
mapfile -t NEWLY_RELEASED < <(printf '%s\n' "${NEWLY_RELEASED[@]}" | sort -V)
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

# ── 5. Report the change ─────────────────────────────────────────────────────
VERSIONS_STR=$(printf ", %s" "${NEWLY_RELEASED[@]}")
VERSIONS_STR=${VERSIONS_STR:2}
DIFF=$(git -c safe.directory="$ROOT" diff -- "$COMPAT_FILE")

echo ""
echo "${COMPAT_FILE} updated in this checkout:"
echo "$DIFF"

report "knative-${NEWLY_RELEASED[0]}-released" "Knative ${VERSIONS_STR} released: update KNATIVE_K8S_COMPAT" \
"New Knative Operator version(s) in the Helm repository (${HELM_REPO_URL}):

$(printf -- '- **%s**\n' "${NEWLY_RELEASED[@]}")

\`bash ci/check-knative-releases.sh\` makes this change in a checkout:

- uncomments the unreleased row(s) of \`KNATIVE_K8S_COMPAT\`
- pins the fallback chart(s) in \`FALLBACK_CHART_VERSIONS\`: ${LATEST_CHART:-none found}

\`\`\`diff
${DIFF}
\`\`\`

- [ ] Is the min K8s version correct? ([release schedule](${SCHEDULE_URL}))
- [ ] Is the compat matrix order correct?
- [ ] Try it on a test cluster

Reported by ci/check-knative-releases.sh."
