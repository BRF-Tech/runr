#!/usr/bin/env bash
set -euo pipefail

# ── Dependency test bot: what this run tests ─────────────────────────────────
# Reads the repository's open issues (gh issue list --json number,title,labels) and decides, for
# .github/workflows/deps-test.yml, which dependency versions the run moves and tests:
#
#   - an issue of ci/check-helm-releases.sh ("deps: <chart> <version> released") or of
#     ci/check-knative-releases.sh ("Knative 1.25 released: update KNATIVE_K8S_COMPAT") names one
#     dependency version; "Knative 1.26 is not in KNATIVE_K8S_COMPAT" needs a person (its minimum
#     Kubernetes minor) and is only labelled;
#   - of the open issues of one dependency only the newest version is tested: the older ones are
#     superseded (closed with a pointer to the newer issue), labelled or not;
#   - an issue labelled "deps: passed" is done; one labelled "needs attention" waits for a person
#     and is tested again only when the run names it (--only, the workflow's `issue` input),
#     which takes the label off.
#
#   bash ci/deps-plan.sh ISSUES.json [--only=N[,N…]]
#
# Prints JSON: {"tests": [{"issue", "dep", "version"}], "superseded": [{"issue", "by", "dep",
# "version"}], "retrigger": [issue], "attention": [{"issue", "reason"}], "skipped": [{"issue",
# "reason"}]}. Needs bash, jq and GNU sort.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC2034 # read by ci/deps/lib.sh
DEPS_ROOT="$ROOT"
# shellcheck source=ci/deps/lib.sh
source "$ROOT/ci/deps/lib.sh"

PASSED_LABEL="${DEPS_PASSED_LABEL:-deps: passed}"
ATTENTION_LABEL="${DEPS_ATTENTION_LABEL:-needs attention}"

issues_file="${1:?usage: ci/deps-plan.sh ISSUES.json [--only=N,…]}"
only=""
for arg in "${@:2}"; do
  case "$arg" in
    --only=*) only="${arg#--only=}" ;;
    *) echo "unknown argument: ${arg}" >&2; exit 1 ;;
  esac
done
only=" ${only//,/ } "
if [[ ! "$only" =~ ^[0-9\ ]*$ ]]; then
  echo "--only: issue numbers separated by commas" >&2
  exit 1
fi
named() { [[ "$only" == *" $1 "* ]]; }

known=" $(deps_names | tr '\n' ' ') "
declare -A newest=() newest_issue=()
rows=()
attention=()
skipped=()

while IFS=$'\t' read -r number title labels; do
  [ -n "$number" ] || continue
  dep=""
  version=""
  if [[ "$title" =~ ^deps:\ ([a-z0-9-]+)\ (v?[0-9][0-9A-Za-z.-]*)\ released$ ]]; then
    dep="${BASH_REMATCH[1]}"
    version="${BASH_REMATCH[2]}"
  elif [[ "$title" =~ ^Knative\ (1\.[0-9]+(,\ 1\.[0-9]+)*)\ released:\ update\ KNATIVE_K8S_COMPAT$ ]]; then
    dep="knative"
    version="$(tr ',' '\n' <<< "${BASH_REMATCH[1]}" | tr -d ' ' | sort -V | tail -n 1)"
  elif [[ "$title" =~ ^Knative\ 1\.[0-9]+\ is\ not\ in\ KNATIVE_K8S_COMPAT$ ]]; then
    if [[ "$labels" != *"|${ATTENTION_LABEL}|"* ]]; then
      attention+=("$(jq -nc --argjson issue "$number" --arg reason "a Knative minor newer than every row of KNATIVE_K8S_COMPAT: its minimum Kubernetes minor is a person's call; add its row commented out as unreleased (or turned on) and the bot takes it from there" '{issue: $issue, reason: $reason}')")
    fi
    continue
  else
    continue
  fi
  if [[ "$known" != *" ${dep} "* ]]; then
    skipped+=("$(jq -nc --argjson issue "$number" --arg reason "the bot does not know ${dep} (ci/deps/lib.sh, DEPS_BOT)" '{issue: $issue, reason: $reason}')")
    continue
  fi
  if ! deps_version_valid "$version"; then
    skipped+=("$(jq -nc --argjson issue "$number" --arg reason "'${version}' is not a version" '{issue: $issue, reason: $reason}')")
    continue
  fi
  if [[ "$labels" == *"|${PASSED_LABEL}|"* ]]; then
    skipped+=("$(jq -nc --argjson issue "$number" --arg reason "passed already" '{issue: $issue, reason: $reason}')")
    continue
  fi
  rows+=("${number}"$'\t'"${dep}"$'\t'"${version}"$'\t'"${labels}")
  if [ -z "${newest[$dep]:-}" ] || deps_is_newer "$version" "${newest[$dep]}" \
    || { [ "$version" = "${newest[$dep]}" ] && [ "$number" -gt "${newest_issue[$dep]}" ]; }; then
    newest[$dep]="$version"
    newest_issue[$dep]="$number"
  fi
done < <(jq -r '.[] | [.number, .title, ("|" + ([.labels[]?.name] | join("|")) + "|")] | @tsv' "$issues_file")

tests=()
superseded=()
retrigger=()
for row in ${rows[@]+"${rows[@]}"}; do
  IFS=$'\t' read -r number dep version labels <<< "$row"
  if [ "$number" != "${newest_issue[$dep]}" ]; then
    superseded+=("$(jq -nc --argjson issue "$number" --argjson by "${newest_issue[$dep]}" --arg dep "$dep" --arg version "${newest[$dep]}" \
      '{issue: $issue, by: $by, dep: $dep, version: $version}')")
    continue
  fi
  if [ "$only" != "  " ] && ! named "$number"; then
    skipped+=("$(jq -nc --argjson issue "$number" --arg reason "not named in this run" '{issue: $issue, reason: $reason}')")
    continue
  fi
  if [[ "$labels" == *"|${ATTENTION_LABEL}|"* ]]; then
    if ! named "$number"; then
      skipped+=("$(jq -nc --argjson issue "$number" --arg reason "waits for a person (${ATTENTION_LABEL}); run the workflow with this issue number to test it again" '{issue: $issue, reason: $reason}')")
      continue
    fi
    retrigger+=("$number")
  fi
  tests+=("$(jq -nc --argjson issue "$number" --arg dep "$dep" --arg version "$version" '{issue: $issue, dep: $dep, version: $version}')")
done

join_json() { if [ "$#" -gt 0 ]; then printf '%s\n' "$@" | jq -s -c .; else echo '[]'; fi; }
jq -nc \
  --argjson tests "$(join_json ${tests[@]+"${tests[@]}"})" \
  --argjson superseded "$(join_json ${superseded[@]+"${superseded[@]}"})" \
  --argjson retrigger "$(if [ "${#retrigger[@]}" -gt 0 ]; then printf '%s\n' "${retrigger[@]}" | jq -s -c .; else echo '[]'; fi)" \
  --argjson attention "$(join_json ${attention[@]+"${attention[@]}"})" \
  --argjson skipped "$(join_json ${skipped[@]+"${skipped[@]}"})" \
  '{tests: ($tests | sort_by(.dep)), superseded: $superseded, retrigger: $retrigger, attention: $attention, skipped: $skipped}'
