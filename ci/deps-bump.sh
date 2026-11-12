#!/usr/bin/env bash
set -euo pipefail

# ── Dependency bump ──────────────────────────────────────────────────────────
# Moves one dependency of a region to a newer version in this checkout, for the dependency test
# bot (.github/workflows/deps-test.yml), or by hand to see what it would commit. It commits
# nothing: the workflow commits the change, runs the dependency's suites on it and lands it on
# next only when they pass.
#
#   bash ci/deps-bump.sh DEP VERSION [--report=DIR] [--values=FILE] [--no-render] [--run-url=URL]
#   bash ci/deps-bump.sh GROUP FINGERPRINT --updates=FILE [--report=DIR] [--run-url=URL]
#
#   DEP       a chart of ci/check-helm-releases.sh (traefik, kube-prometheus-stack, loki-stack, zot,
#             kata-deploy) or knative (VERSION is then a minor, 1.25)
#   GROUP     a package group of ci/deps/packages.sh (composer-api, npm-vue, go-agent, actions,
#             docker, …): FILE holds its finding's updates (ci/deps/bump-packages.sh)
#   --report  where the result goes (default: a new directory under TMPDIR, printed at the end):
#               result          bumped | attention | current
#               summary.md      what the bot found and did, for the issue comment
#               commit-msg.txt  the commit message (bumped)
#               suites.json     the suites the change must pass, with their ci.sh commands (bumped);
#                               job: kind (kind-suite.yml), check (ci/deps-check.sh), ui
#                               (ui-tests.yml) or ui-stack (ui-stack-suite.yml)
#               path            release | next: a package group's bump that ships nothing lands on
#                               next only (a chart's is always release)
#               unit            1 when PHPUnit runs on it (every chart's; a group's when its suites say)
#               edits.log       every line edited or kept
#   --values  RunR's values of the chart as a YAML file, instead of ci/deps/helm-values.php
#   --no-render  no chart is downloaded or rendered (the shell tests)
#   --run-url the workflow run, named in the commit message
#
# Exit status: 0 bumped, 2 needs a person (summary.md says why, nothing is left changed), 3 nothing
# to do (the checkout pins VERSION or a newer one already), 1 the bump could not be checked.
#
# A chart bump is mechanical only when the new chart takes RunR's values and renders what the
# pinned one renders: the same objects (Kind, namespace and name) with RunR's values on Kubernetes
# 1.30 and 1.35, the same CRDs (Helm never upgrades them), every value RunR sets still a key of the
# chart, no new major, no other kubeVersion, and no new app version where RunR pins the image
# from the reqs mirror. Then the pin moves, and with it every test and doc that names the pinned
# version as the current one (ci/deps/lib.sh: deps_replace_version), and the changelog gets a
# line under [Unreleased] → Changed. Anything else goes to a person.
#
# A value RunR sets that the new chart no longer reads (ci/deps/dead-values.php: its change renders
# nothing) goes to a person too.
#
# Needs bash, git, GNU sed and awk, jq, curl, helm and, for the charts RunR passes values to, php
# with the API's dependencies (composer install in api/).
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
# shellcheck disable=SC2034 # read by ci/deps/lib.sh
DEPS_ROOT="$ROOT"
# shellcheck source=ci/deps/lib.sh
source "$ROOT/ci/deps/lib.sh"

# Kubernetes versions the charts are rendered for: RunR's minimum (KNATIVE_MIN_K8S_MINOR) and the
# newest the e2e runs
DEPS_KUBE_VERSIONS="${DEPS_KUBE_VERSIONS:-1.30.0 1.35.0}"
# the Kind node image of the e2e (e2e.yml, lib/test.sh)
DEPS_DEFAULT_K8S="${DEPS_DEFAULT_K8S:-1.32.0}"
SCHEDULE_URL="https://github.com/knative/community/blob/main/mechanics/RELEASE-SCHEDULE.md"

dep="${1:-}"
version="${2:-}"
shift 2 || true
report=""
values_file=""
render=1
run_url=""
for arg in "$@"; do
  case "$arg" in
    --report=*) report="${arg#--report=}" ;;
    --values=*) values_file="${arg#--values=}" ;;
    --no-render) render=0 ;;
    --run-url=*) run_url="${arg#--run-url=}" ;;
    --updates=*) updates_file="${arg#--updates=}" ;;
    *) echo "unknown argument: ${arg}" >&2; exit 1 ;;
  esac
done
bot_row="$(deps_bot_row "$dep" || true)"
# a package group (ci/deps/packages.sh): VERSION is its finding's fingerprint, --updates its updates
pkg_row=""
[ -z "$dep" ] || pkg_row="$(deps_pkg_row "$dep" || true)"
if [ -z "$dep" ] || { [ -z "$bot_row" ] && [ -z "$pkg_row" ]; }; then
  echo "usage: ci/deps-bump.sh DEP VERSION [--report=DIR] [--updates=FILE] (DEP: $(deps_names | paste -sd, -))" >&2
  exit 1
fi
if [ -n "$pkg_row" ]; then
  [[ "$version" =~ ^[0-9a-f]{8}$ ]] || { echo "${dep}: '${version}' is not a finding's fingerprint" >&2; exit 1; }
elif ! deps_version_valid "$version"; then
  echo "${dep}: '${version}' is not a version" >&2
  exit 1
fi
# a bump that gives up puts back what it edited: only a clean checkout tells its edits apart
if [ -n "$(git -C "$ROOT" status --porcelain --untracked-files=no)" ]; then
  echo "ci/deps-bump.sh needs a checkout without changes (git status): commit or stash them first" >&2
  exit 1
fi
[ -n "$report" ] || report="$(mktemp -d "${TMPDIR:-/tmp}/deps-bump-${dep}.XXXXXX")"
mkdir -p "$report"
: > "$report/edits.log"
rm -f -- "$report/result" "$report/summary.md" "$report/commit-msg.txt" "$report/suites.json" "$report/path"
# a chart's bump is always a release, with PHPUnit (a package group's: ci/deps/bump-packages.sh)
echo release > "$report/path"
echo 1 > "$report/unit"
work="$(mktemp -d "${TMPDIR:-/tmp}/deps-work.XXXXXX")"
trap 'rm -rf -- "$work"' EXIT

scope="$(deps_field "$bot_row" 2)"
label="$(deps_field "$bot_row" 3)"
names="$(deps_field "$bot_row" 4)"
image="$(deps_field "$bot_row" 5)"
release="$(deps_field "$bot_row" 6)"
suites="$(deps_field "$bot_row" 7)"
reasons=()
notes=()

# finish RESULT STATUS — the result file, the summary's end, and the exit status
finish() {
  echo "$1" > "$report/result"
  echo "${dep} ${version}: $1 (report: ${report})"
  exit "$2"
}

# attention — every reason found: the summary, the checkout put back as it was, status 2
attention() {
  {
    echo "**${dep} ${version} needs a person: the bot does not move it by itself.**"
    echo ""
    printf -- '- %s\n' "${reasons[@]}"
    if [ "${#notes[@]}" -gt 0 ]; then
      echo ""
      echo "What the bot compared:"
      echo ""
      printf -- '- %s\n' "${notes[@]}"
    fi
  } > "$report/summary.md"
  deps_revert "$report/edits.log"
  finish attention 2
}

# suite_json SUITE COMMAND K8S KVM ENV — one entry of suites.json
suite_json() {
  jq -nc --arg suite "$1" --arg command "$2" --arg k8s "$3" --argjson kvm "$4" --arg env "$5" \
    '{suite: $suite, command: $command, k8s: $k8s, kvm: $kvm, env: $env}'
}

# write_suites TAG K8S EXPECT — suites.json: the ci.sh command of each suite of the dependency
# (unit: PHPUnit and the shell tests, run by the workflow itself, not listed)
write_suites() {
  local tag="$1" k8s="$2" expect="$3" suite after="TEST_AFTER_E2E=ci/deps/after-e2e.sh DEPS_EXPECT=${3}"
  {
    for suite in $suites; do
      case "$suite" in
        unit) ;;
        e2e) suite_json e2e "test" "$k8s" false "$after" ;;
        network) suite_json network "test-network" "$k8s" false "$after" ;;
        observability) suite_json observability "test-observability" "$k8s" false "$after" ;;
        upgrade) suite_json upgrade "test-helm-upgrade --from=${tag} --expect-release=${expect}" "$k8s" false "" ;;
        upgrade-observability)
          suite_json upgrade "test-helm-upgrade --from=${tag} --after-upgrade=cluster:patch-observability,{region} --expect-release=${expect}" "$k8s" false "" ;;
        upgrade-zot)
          suite_json upgrade "test-helm-upgrade --from=${tag} --after-upgrade=registry:upgrade-zot,{region} --expect-release=${expect}" "$k8s" false "" ;;
        sandbox-kata) suite_json sandbox-kata "test-sandbox-api" "$k8s" true "SANDBOX_KATA=1 RUNR_E2E_SANDBOX_CASES=kata" ;;
        upgrade-kata) suite_json upgrade-kata "test-helm-upgrade --from=${tag} --kata" "$k8s" true "" ;;
        e2e-knative) suite_json e2e "test" "$k8s" false "TEST_AFTER_E2E=ci/deps/after-e2e.sh DEPS_EXPECT_KNATIVE=${version}" ;;
        *) echo "deps-bump: unknown suite ${suite}" >&2; return 1 ;;
      esac
    done
  } | jq -s -c . > "$report/suites.json"
}

# suite_names — the suites of the dependency as the commit message lists them
suite_names() {
  local suite out=()
  for suite in $suites; do
    case "$suite" in
      unit) out+=("PHPUnit and runtime/test") ;;
      e2e|e2e-knative) out+=("ci.sh test (Kind e2e)") ;;
      network) out+=("ci.sh test-network") ;;
      observability) out+=("ci.sh test-observability") ;;
      upgrade) out+=("ci.sh test-helm-upgrade from the last release") ;;
      upgrade-observability) out+=("ci.sh test-helm-upgrade from the last release with cluster:patch-observability") ;;
      upgrade-zot) out+=("ci.sh test-helm-upgrade from the last release with registry:upgrade-zot") ;;
      sandbox-kata) out+=("ci.sh test-sandbox-api with Kata") ;;
      upgrade-kata) out+=("ci.sh test-helm-upgrade from the last release with Kata installed") ;;
    esac
  done
  local IFS=';'
  echo "${out[*]}" | sed 's/;/, /g'
}

# ── A package group: ci/deps/bump-packages.sh ──
if [ -n "$pkg_row" ]; then
  # shellcheck source=ci/deps/bump-packages.sh
  source "$ROOT/ci/deps/bump-packages.sh"
  bump_packages
fi

# chart_field DIR KEY — a top-level key of DIR/Chart.yaml, without quotes
chart_field() {
  sed -n "s/^${2}:[[:space:]]*//p" "$1/Chart.yaml" | head -n 1 | sed -E "s/^[\"']//; s/[\"'][[:space:]]*$//; s/[[:space:]]+$//"
}

# pull_chart VERSION DIR — the chart at VERSION, unpacked in DIR/<chart>
pull_chart() {
  local v="$1" dir="$2"
  mkdir -p "$dir"
  if [ "$type" = oci ]; then
    helm pull "oci://${repo}" --version "$v" --untar --untardir "$dir" >/dev/null 2>"$work/pull.err"
  else
    helm repo add "deps-${dep}" "$repo" --force-update >/dev/null 2>&1 || true
    helm repo update "deps-${dep}" >/dev/null 2>&1 || true
    helm pull "deps-${dep}/${chart}" --version "$v" --untar --untardir "$dir" >/dev/null 2>"$work/pull.err"
  fi
}

# render DIR OUT KUBE — helm template of the chart in DIR with RunR's values (none for kata-deploy)
render() {
  local args=("${rel_name}" "$1" -n "${rel_ns}" --kube-version "$3")
  if [ -s "$work/values.yaml" ]; then args+=(-f "$work/values.yaml"); fi
  local line
  while IFS= read -r line; do
    [ -n "$line" ] && args+=(--set-json "$line")
  done < "$work/set-json"
  helm template "${args[@]}" > "$2" 2>"$2.err"
}

# ── Knative: the unreleased row of the minor, through ci/check-knative-releases.sh ──
bump_knative() {
  local compat="api/app/Services/Helpers/KnativeVersionHelper.php" minor min tag tags enabled m k
  if ! [[ "$version" =~ ^1\.([0-9]+)$ ]]; then
    echo "knative: '${version}' is not a Knative minor (1.25)" >&2
    exit 1
  fi
  minor="${BASH_REMATCH[1]}"
  if grep -qE "^[[:space:]]+${minor}[[:space:]]*=>[[:space:]]*[0-9]+," "$compat"; then
    echo "next offers Knative ${version} already (\`KNATIVE_K8S_COMPAT\` in \`${compat}\`)." > "$report/summary.md"
    finish current 3
  fi
  min="$(sed -nE "s|^[[:space:]]*//[[:space:]]*${minor}[[:space:]]*=>[[:space:]]*([0-9]+),.*unreleased.*|\1|p" "$compat" | head -n 1)"
  if [ -z "$min" ]; then
    reasons+=("\`KNATIVE_K8S_COMPAT\` has no unreleased row for Knative ${version}: its minimum Kubernetes minor is a person's call ([release schedule](${SCHEDULE_URL})); add \`// ${minor} => <minor>, // unreleased\` and the bot turns it on.")
    attention
  fi
  # the script turns on every unreleased row whose minor the chart repository has
  echo "edit ${compat}" >> "$report/edits.log"
  RELEASES_REPORT_DIR="" bash ci/check-knative-releases.sh > "$work/knative.log" 2>&1 || {
    reasons+=("\`ci/check-knative-releases.sh\` failed: $(tail -n 3 "$work/knative.log" | tr '\n' ' ')")
    attention
  }
  if ! grep -qE "^[[:space:]]+${minor}[[:space:]]*=>[[:space:]]*${min}," "$compat"; then
    reasons+=("the Knative Operator chart repository has no ${version} chart yet: \`ci/check-knative-releases.sh\` left the row commented out")
    attention
  fi
  # every row on now, newest first: the docs table gets the ones it lacks
  enabled="$(sed -nE 's/^[[:space:]]+([0-9]+)[[:space:]]*=>[[:space:]]*([0-9]+),.*/\1 \2/p' "$compat")"
  while read -r m k; do
    [ -n "$m" ] || continue
    grep -qE "^\| 1\.${m} \|" docs/regions.md && continue
    awk -v row="| 1.${m} | 1.${k} |" '
      !done && prev ~ /^\| Knative \| Kubernetes, at least \|/ && $0 ~ /^\|---/ { print; print row; done = 1; prev = $0; next }
      { print; prev = $0 }
    ' docs/regions.md > "$work/regions.md"
    cat "$work/regions.md" > docs/regions.md
    echo "edit docs/regions.md: | 1.${m} | 1.${k} |" >> "$report/edits.log"
  done <<< "$(printf '%s\n' "$enabled" | sort -n)"

  # the e2e needs a Kind node image of a Kubernetes the minor supports (the reqs mirror's kindest-node)
  tags="${DEPS_KIND_NODE_TAGS:-}"
  if [ -z "$tags" ]; then
    local token
    token="$(curl -fsSL "https://ghcr.io/token?scope=repository:brf-tech/runr/reqs/kindest-node:pull" | jq -r .token)" || token=""
    tags="$(curl -fsSL -H "Authorization: Bearer ${token}" "https://ghcr.io/v2/brf-tech/runr/reqs/kindest-node/tags/list?n=1000" 2>/dev/null \
      | jq -r '.tags[]?' | sed -n 's/^v\([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)$/\1/p' | tr '\n' ' ')" || tags=""
  fi
  # shellcheck disable=SC2086 # the tags, one per word
  tag="$(printf '%s\n' $tags | awk -F. -v min="$min" '$1 == 1 && $2 >= min' | sort -V | head -n 1)"
  if [ -z "$tag" ]; then
    reasons+=("Knative ${version} needs Kubernetes 1.${min}, and the reqs mirror has no Kind node image of it (\`ghcr.io/brf-tech/runr/reqs/kindest-node\`: ${tags:-no tags read}): the e2e cannot run it. Mirror \`kindest/node:v1.${min}.x\` (BRF-Tech/runr-runtimes) and run this again; the Kind of e2e.yml must know that node image.")
    attention
  fi

  local line
  line="$(deps_changelog_line knative "" "$version" "" "")"
  deps_changelog_add CHANGELOG.md "- **Knative ${version}** " "$line"
  write_suites "" "$tag" ""
  {
    echo "fix(regions): Knative ${version}"
    echo ""
    echo "KNATIVE_K8S_COMPAT turns on its unreleased row (${minor} => ${min}: Kubernetes 1.${min} or"
    echo "newer) and FALLBACK_CHART_VERSIONS pins the minor's newest Operator chart, as"
    echo "ci/check-knative-releases.sh does; docs/regions.md lists it. A cluster below Kubernetes 1.${min}"
    echo "sees no change."
    echo ""
    echo "The dependency test bot ran these suites on this commit before it landed: $(suite_names)"
    echo "(the e2e on a Kind node of Kubernetes ${tag})${run_url:+, ${run_url}}."
  } > "$report/commit-msg.txt"
  {
    echo "Knative ${version}: the unreleased row \`${minor} => ${min}\` of \`KNATIVE_K8S_COMPAT\` is on, its Operator chart pinned in \`FALLBACK_CHART_VERSIONS\`, and docs/regions.md lists it. The e2e runs on Kubernetes ${tag}."
    echo ""
    echo "Other mentions of Knative minors in docs are prose about a cluster's Kubernetes version and stay as they are; check them in the diff."
  } > "$report/summary.md"
  finish bumped 0
}

if [ "$dep" = knative ]; then
  bump_knative
fi

# ── A Helm chart ──
helm_row="$(deps_helm_row "$dep")"
[ -n "$helm_row" ] || { echo "${dep}: no DEPS row in ci/check-helm-releases.sh" >&2; exit 1; }
repo="$(deps_field "$helm_row" 2)"
chart="$(deps_field "$helm_row" 3)"
type="$(deps_field "$helm_row" 4)"
pin_file="$(deps_field "$helm_row" 5)"
pin_constant="$(deps_field "$helm_row" 6)"
pin="$(deps_read_pin "$pin_file" "$pin_constant")"
[ -n "$pin" ] || { echo "${dep}: no version in ${pin_constant} of ${pin_file}" >&2; exit 1; }
if ! deps_is_newer "$version" "$pin"; then
  echo "next pins ${dep} ${pin} already (\`${pin_constant}\` in \`${pin_file}\`): ${version} is not newer." > "$report/summary.md"
  finish current 3
fi
rel_ns="${release%%/*}"
rel_name="${release#*/}"
if [ "$release" = "-" ]; then rel_ns="kube-system"; rel_name="$chart"; fi

app_old=""
app_new=""
if [ "$render" = 1 ]; then
  if ! pull_chart "$pin" "$work/old"; then
    echo "${dep}: the pinned chart ${pin} cannot be downloaded: $(cat "$work/pull.err")" >&2
    exit 1
  fi
  if ! pull_chart "$version" "$work/new"; then
    echo "${dep}: chart ${version} cannot be downloaded: $(cat "$work/pull.err")" >&2
    exit 1
  fi
  old_dir="$work/old/${chart##*/}"
  new_dir="$work/new/${chart##*/}"
  app_old="$(chart_field "$old_dir" appVersion)"
  app_new="$(chart_field "$new_dir" appVersion)"
  kube_old="$(chart_field "$old_dir" kubeVersion)"
  kube_new="$(chart_field "$new_dir" kubeVersion)"
  notes+=("chart ${pin} → ${version}, app version ${app_old:-none} → ${app_new:-none}, kubeVersion ${kube_old:-none} → ${kube_new:-none}")

  if [ "$(deps_major "$version")" != "$(deps_major "$pin")" ]; then
    reasons+=("a new major release (${pin} → ${version}): its values or upgrade path may change (Traefik 41 renamed \`logs\`, which RunR's values and an installed region's had to follow); read its release notes and move it by hand")
  fi
  if [ "$kube_old" != "$kube_new" ]; then
    reasons+=("the chart's kubeVersion changed (\`${kube_old:-none}\` → \`${kube_new:-none}\`): RunR's minimum Kubernetes and the chart's must still agree")
  fi
  case "$image" in
    reqs:*)
      if [ "$app_old" != "$app_new" ]; then
        reasons+=("the chart runs a new app version (${app_old} → ${app_new}) and RunR pins the image from the reqs mirror: mirror \`${image#reqs:}:${app_new}\` first (\`REQS_IMAGES\` of BRF-Tech/runr-runtimes \`lib/mirror.sh\`), then move the image pin with the chart")
      fi ;;
  esac

  # RunR's values: the file the install writes, and its --set-json values
  : > "$work/set-json"
  : > "$work/values.yaml"
  case "$dep" in
    traefik|kube-prometheus-stack|loki-stack|zot)
      if [ -n "$values_file" ]; then
        cp "$values_file" "$work/values.yaml"
      elif ! php ci/deps/helm-values.php "$dep" > "$work/values.yaml" 2>"$work/values.err" \
        || ! php ci/deps/helm-values.php "$dep" --set-json > "$work/set-json" 2>>"$work/values.err"; then
        echo "${dep}: RunR's values could not be rendered (ci/deps/helm-values.php): $(cat "$work/values.err")" >&2
        exit 1
      fi ;;
  esac

  rendered=0
  for kube in $DEPS_KUBE_VERSIONS; do
    if ! render "$old_dir" "$work/old-${kube}.yaml" "$kube"; then
      echo "${dep}: the pinned chart ${pin} does not render with RunR's values on Kubernetes ${kube}: $(tail -n 5 "$work/old-${kube}.yaml.err")" >&2
      exit 1
    fi
    if ! render "$new_dir" "$work/new-${kube}.yaml" "$kube"; then
      reasons+=("chart ${version} refuses RunR's values on Kubernetes ${kube}: \`$(tail -n 3 "$work/new-${kube}.yaml.err" | tr '\n' ' ' | cut -c1-400)\`")
      continue
    fi
    rendered=1
    php ci/deps/render-diff.php objects "$work/old-${kube}.yaml" "$work/new-${kube}.yaml" "$pin" "$version" "$app_old" "$app_new" > "$work/diff-${kube}.json"
    added="$(jq -r '.added | join(", ")' "$work/diff-${kube}.json")"
    removed="$(jq -r '.removed | join(", ")' "$work/diff-${kube}.json")"
    crds="$(jq -r '.crds | join(", ")' "$work/diff-${kube}.json")"
    [ -z "$added" ] || reasons+=("on Kubernetes ${kube} chart ${version} renders objects ${pin} does not: ${added}")
    [ -z "$removed" ] || reasons+=("on Kubernetes ${kube} chart ${version} no longer renders: ${removed}")
    [ -z "$crds" ] || reasons+=("on Kubernetes ${kube} the chart's templated CRDs changed: ${crds}")
    changed="$(jq -r '.changed[] | "\(.id) (\(.paths | join(", ")))"' "$work/diff-${kube}.json" | paste -sd ';' - | sed 's/;/; /g')"
    if [ "$dep" = kata-deploy ] && [ -n "$changed" ]; then
      reasons+=("RunR renders kata-deploy's DaemonSet itself, after the chart's daemonset mode (KataManifestTrait): the chart's objects changed on Kubernetes ${kube} beyond its version and image (${changed}), which a person carries over")
    fi
    notes+=("Kubernetes ${kube}: $(jq -r '.old' "$work/diff-${kube}.json") objects with ${pin}, $(jq -r '.new' "$work/diff-${kube}.json") with ${version}; apart from version labels and images: ${changed:-nothing changes}")
  done
  if [ "$rendered" = 1 ]; then
    php ci/deps/render-diff.php crds "$old_dir" "$new_dir" > "$work/crds.json"
    crd_change="$(jq -r '[.added[] | "added \(.)"] + [.removed[] | "removed \(.)"] + [.changed[] | "changed \(.)"] | join(", ")' "$work/crds.json")"
    if [ -n "$crd_change" ]; then
      reasons+=("the chart's CRDs changed (${crd_change}): Helm installs a chart's crds/ once and never upgrades them, so an installed region needs a CRD step")
    else
      notes+=("CRDs (crds/): the same, labels and annotations aside")
    fi
    if [ -s "$work/values.yaml" ]; then
      php ci/deps/render-diff.php keys "$work/values.yaml" "$old_dir/values.yaml" "$new_dir/values.yaml" > "$work/keys.json"
      dropped="$(jq -r '.dropped | join(", ")' "$work/keys.json")"
      [ -z "$dropped" ] || reasons+=("chart ${version} no longer has values RunR sets: ${dropped}")
      # a value whose change renders nothing new is one the chart does not read (a rename without a
      # schema to refuse the old key): one the pinned chart reads and the new one does not is a value
      # the new chart dropped; one neither reads is noted (it waits for a condition, such as
      # replicas under autoscaling)
      kube="${DEPS_KUBE_VERSIONS##* }"
      php ci/deps/dead-values.php "$old_dir" "$work/values.yaml" "$rel_name" "$rel_ns" "$kube" "$work/set-json" > "$work/dead-old.json"
      php ci/deps/dead-values.php "$new_dir" "$work/values.yaml" "$rel_name" "$rel_ns" "$kube" "$work/set-json" > "$work/dead-new.json"
      ignored="$(jq -r --slurpfile old "$work/dead-old.json" '.dead - $old[0].dead | join(", ")' "$work/dead-new.json")"
      [ -z "$ignored" ] || reasons+=("chart ${version} ignores values RunR sets that ${pin} reads: ${ignored}")
      unread="$(jq -r '.dead | join(", ")' "$work/dead-old.json")"
      [ -z "$unread" ] || notes+=("values neither chart reads on Kubernetes ${kube} (a condition they wait for, or a key to look at): ${unread}")
    fi
  fi
fi

# the console and the landing page are never edited: a pin named there goes to a person
outside="$(deps_outside_mentions "$pin" "$names")"
if [ -n "$outside" ]; then
  reasons+=("the console or the landing page names ${pin}, which the bot does not edit (their commits carry other authors): ${outside//$'\n'/; }")
fi
[ "${#reasons[@]}" -eq 0 ] || attention

# ── The bump ──
deps_replace_version "$pin" "$version" "$names" "$report/edits.log" > /dev/null
case "$image" in
  direct:*)
    if [ -n "$app_new" ] && [ "$app_old" != "$app_new" ]; then
      deps_replace_version "${app_old#v}" "${app_new#v}" "$names" "$report/edits.log" > /dev/null
    fi ;;
esac
now="$(deps_read_pin "$pin_file" "$pin_constant")"
if [ "$now" != "$version" ]; then
  echo "${dep}: ${pin_constant} of ${pin_file} reads '${now}' after the bump, not ${version}" >&2
  deps_revert "$report/edits.log"
  exit 1
fi

key_label="$(deps_changelog_line "$dep" "$pin" "$version" "$app_old" "$app_new" | sed -E 's/^(- \*\*[^0-9]*)[0-9].*/\1/')"
deps_changelog_add CHANGELOG.md "$key_label" "$(deps_changelog_line "$dep" "$pin" "$version" "$app_old" "$app_new")"
echo "edit CHANGELOG.md: [Unreleased] → Changed" >> "$report/edits.log"

tag="$(deps_last_release_tag HEAD || true)"
if [ -z "$tag" ]; then
  echo "${dep}: no release tag v<x.y.z> in this checkout's history: the upgrade suites need the last release (fetch the tags)" >&2
  deps_revert "$report/edits.log"
  exit 1
fi
expect=""
[ "$release" = "-" ] || expect="${release}=${chart##*/}-${version}"
write_suites "$tag" "$DEPS_DEFAULT_K8S" "$expect"

edited="$(grep -c '^edit ' "$report/edits.log" || true)"
kept="$(grep '^kept ' "$report/edits.log" | sed 's/^kept /  - /' || true)"
touched="$(git -C "$ROOT" diff --name-only | paste -sd ',' - | sed 's/,/, /g')"
subject="fix(${scope}): ${label} ${version}"
if [ "$dep" = zot ] && [ -n "$app_new" ]; then subject="${subject} (zot ${app_new})"; fi
{
  echo "$subject"
  echo ""
  if [ -n "$app_new" ]; then
    if [ "$app_old" = "$app_new" ]; then
      echo "${label^} ${pin} → ${version}; the app version stays ${app_new}."
    else
      echo "${label^} ${pin} → ${version}, app version ${app_old} → ${app_new}."
    fi
  else
    echo "${label^} ${pin} → ${version}."
  fi
  if [ "${#notes[@]}" -gt 0 ]; then
    echo ""
    echo "Rendered with RunR's values$([ "$release" = "-" ] && echo " (the chart's defaults)" || echo " (release ${rel_name} in ${rel_ns})"):"
    printf -- '- %s\n' "${notes[@]}" | fold -s -w 100 | sed -E '/^- /!s/^/  /'
  fi
  echo ""
  echo "The pin moves in ${pin_file} (${pin_constant}), with the tests and docs that name it as the"
  echo "current one (${edited} lines in ${touched})."
  if [ -n "$kept" ]; then
    echo "Lines that record the past keep ${pin}:"
    echo "$kept"
  fi
  echo ""
  echo "The dependency test bot ran these suites on this commit before it landed on next:"
  echo "$(suite_names)${run_url:+ (${run_url})}."
} > "$report/commit-msg.txt"
{
  echo "**${dep} ${pin} → ${version}**${app_new:+ (app version ${app_old:-none} → ${app_new})}: a mechanical bump."
  echo ""
  if [ "${#notes[@]}" -gt 0 ]; then
    echo "Rendered with RunR's values:"
    echo ""
    printf -- '- %s\n' "${notes[@]}"
    echo ""
  fi
  echo "Edited (${edited} lines): ${touched}."
  if [ -n "$kept" ]; then
    echo ""
    echo "Kept as history:"
    echo ""
    echo "$kept"
  fi
} > "$report/summary.md"
finish bumped 0
