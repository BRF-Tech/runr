#!/usr/bin/env bash
set -euo pipefail

# ── Dependency test bot: what this run tests ─────────────────────────────────
# Reads the repository's open issues (gh issue list --json number,title,labels,body) and decides, for
# .github/workflows/deps-test.yml, which dependency versions the run moves and tests:
#
#   - the weekly summary ("deps: week <ISO week>", <!-- runr-deps:digest:<week> -->,
#     ci/deps-digest.sh) has one row per dependency, its data a marker <!-- deps-bot {"id", "kind",
#     "group", "version", "state", …} -->: a row of kind group (a package group's minor and patch
#     updates: its marker's updates), chart or knative whose state is new, testing (a run that did
#     not finish) or green (passed, not shipped yet) is tested; one that needs attention only when
#     the run names it; one that lives in an issue of its own (item_kind "issue": a bump that went
#     red) is tested through that issue. Its ref is <summary>:<group>;
#   - an issue of its own: of ci/check-helm-releases.sh ("deps: <chart> <version> released") or of
#     ci/check-knative-releases.sh ("Knative 1.25 released: update KNATIVE_K8S_COMPAT") names one
#     dependency version; "Knative 1.26 is not in KNATIVE_K8S_COMPAT" needs a person (its minimum
#     Kubernetes minor) and is only labelled; of ci/check-package-releases.sh ("deps: <group>
#     [<fingerprint>]: <n> updates: …": a row the bot moved out of the summary because it went red,
#     or one filed before the summary) names a package group's minor and patch updates, which its
#     body's marker lists; "deps: <group> <name> <version> released (outside <constraint>)" needs a
#     person and is only labelled. Its ref is its number;
#   - of one dependency only the newest finding is tested (a chart's or Knative's newest version, a
#     package group's latest finding: its marker's "found" date, then its number): an older issue
#     is superseded (closed with a pointer to the newer one), labelled or not; an older row is
#     skipped;
#   - an issue labelled "deps: passed" is done; one labelled "needs attention" (or a row in that
#     state) waits for a person and is tested again only when the run names it (--only, the
#     workflow's `issue` input), which takes the label off.
#
#   bash ci/deps-plan.sh ISSUES.json [--only=REF[,REF…]]
#
# A REF of --only: N (an issue of its own, or a summary: all its open rows, those that need
# attention too) or N:group (the group's row of the open summary, whichever week it is in now).
# ISSUES.json: gh issue list --json number,title,labels,body.
# Prints JSON: {"tests": [{"issue", "ref", "dep", "version"[, "updates", "urgent"]}], "superseded":
# [{"issue", "by", "dep", "version"}], "retrigger": [issue], "attention": [{"issue", "reason"}],
# "skipped": [{"issue", "ref", "reason"}]}. Needs bash, jq and GNU sort.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC2034 # read by ci/deps/lib.sh
DEPS_ROOT="$ROOT"
# shellcheck source=ci/deps/lib.sh
source "$ROOT/ci/deps/lib.sh"

PASSED_LABEL="${DEPS_PASSED_LABEL:-deps: passed}"
ATTENTION_LABEL="${DEPS_ATTENTION_LABEL:-needs attention}"

issues_file="${1:?usage: ci/deps-plan.sh ISSUES.json [--only=REF,…]}"
only=""
for arg in "${@:2}"; do
  case "$arg" in
    --only=*) only="${arg#--only=}" ;;
    *) echo "unknown argument: ${arg}" >&2; exit 1 ;;
  esac
done
if [[ ! "$only" =~ ^([0-9]+(:[a-z0-9-]+)?(,[0-9]+(:[a-z0-9-]+)?)*)?$ ]]; then
  echo "--only: issue numbers (N, or N:group for a line of the digest) separated by commas" >&2
  exit 1
fi
only=" ${only//,/ } "
# named N — the run names issue N (or digest N: all its open lines)
named() { [[ "$only" == *" $1 "* ]]; }
# named_line DIGEST GROUP — the run names the group's line: its digest, or <any digest>:GROUP (the
# line may have carried over into a newer digest since the ref was written)
named_line() { named "$1" || [[ "$only" =~ \ [0-9]+:$2\  ]]; }

known=" $(deps_names | tr '\n' ' ') "
declare -A newest=() newest_ref=() newest_order=() markers=()
rows=()
attention=()
skipped=()

skip() { skipped+=("$(jq -nc --argjson issue "$1" --arg ref "$2" --arg reason "$3" '{issue: $issue, ref: $ref, reason: $reason}')"); }

# NUMBER REF TITLE LABELS MARKER FOUND of an issue of its own, or of one line of a digest (its
# title in the form of the issue it stands for)
while IFS=$'\t' read -r number ref title labels marker found; do
  [ -n "$number" ] || continue
  dep=""
  version=""
  group=0
  if [[ "$title" =~ ^deps:\ ([a-z0-9-]+)\ \[([0-9a-f]{8})\]:\  ]]; then
    # a package group (ci/check-package-releases.sh): its updates are the marker's
    dep="${BASH_REMATCH[1]}"
    version="${BASH_REMATCH[2]}"
    group=1
    if [ -z "$marker" ] || ! jq -e --arg dep "$dep" '.group == $dep and (.updates | type == "array" and length > 0)' >/dev/null 2>&1 <<<"$marker"; then
      skip "$number" "$ref" "the issue's body has no deps-bot marker of ${dep} (its updates)"
      continue
    fi
    markers[$ref]="$marker"
  elif [[ "$title" =~ ^deps:\ ([a-z0-9-]+)\ (.+)\ ([^\ ]+)\ released\ \(outside\ (.*)\)$ ]]; then
    # outside the manifest's constraint (a new major, a 0.x minor, an exact pin, the Go toolchain)
    if [[ "$labels" != *"|${ATTENTION_LABEL}|"* ]] && [[ "$labels" != *"|${PASSED_LABEL}|"* ]]; then
      attention+=("$(jq -nc --argjson issue "$number" --arg reason "${BASH_REMATCH[2]} ${BASH_REMATCH[3]} is outside ${BASH_REMATCH[1]}'s constraint \`${BASH_REMATCH[4]}\`: a person reads its release notes and moves it with the code it needs; the bot takes the minor and patch updates from there" '{issue: $issue, reason: $reason}')")
    fi
    continue
  elif [[ "$title" =~ ^deps:\ ([a-z0-9-]+)\ (v?[0-9][0-9A-Za-z.-]*)\ released$ ]]; then
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
    skip "$number" "$ref" "the bot does not know ${dep} (ci/deps/lib.sh, DEPS_BOT)"
    continue
  fi
  if [ "$group" = 0 ] && ! deps_version_valid "$version"; then
    skip "$number" "$ref" "'${version}' is not a version"
    continue
  fi
  if [[ "$labels" == *"|${PASSED_LABEL}|"* ]]; then
    skip "$number" "$ref" "passed already"
    continue
  fi
  rows+=("${number}"$'\t'"${ref}"$'\t'"${dep}"$'\t'"${version}"$'\t'"${labels}")
  # the newest finding of a dependency: a chart's or Knative's newest version; of a package group
  # (its fingerprint is no version) the latest found, then the newest issue
  order="${found:-0000-00-00}#$(printf '%09d' "$number")"
  if [ -z "${newest[$dep]:-}" ] || { [ "$group" = 0 ] && deps_is_newer "$version" "${newest[$dep]}"; } \
    || { { [ "$group" = 1 ] || [ "$version" = "${newest[$dep]}" ]; } && [[ "$order" > "${newest_order[$dep]}" ]]; }; then
    newest[$dep]="$version"
    newest_ref[$dep]="$ref"
    newest_order[$dep]="$order"
  fi
done < <(jq -r '
  def labels: "|" + ([.labels[]?.name] | join("|")) + "|";
  # the data markers only: the runr-deps: markers of the summary and its rows are none of them
  def markers: [(.body // "") | scan("<!-- deps-bot (\\{[^\n]*\\}) -->") | .[0] | fromjson? // empty];
  # a summary: its title, or its marker as the last line of the body (one quoted in a text is none)
  def summary: (.title // "" | test("^deps: week [0-9]{4}-W[0-9]{2}$"))
    or (.body // "" | sub("\\s+$"; "") | split("\n") | (last // "") | test("^<!-- runr-deps:digest:[0-9]{4}-W[0-9]{2} -->$"));
  .[]
  | if summary then
      .number as $n
      | markers[]
      | select(.item_kind != "issue" and (.group != null) and (.kind | IN("group", "chart", "knative")) and ((.state // "new") | IN("new", "testing", "green", "needs attention")))
      | [$n, "\($n):\(.group)",
         (if .kind == "group" then "deps: \(.group) [\(.version)]: " elif .kind == "chart" then "deps: \(.group) \(.version) released" else (.title // "") end),
         (if .state == "needs attention" then "|needs attention|" else "||" end), tojson, (.found // "")]
    else
      (markers[0] // null) as $m
      | [.number, "\(.number)", .title, labels, (if $m == null then "" else ($m | tojson) end), ($m.found // "")]
    end
  | @tsv' "$issues_file")

tests=()
superseded=()
retrigger=()
for row in ${rows[@]+"${rows[@]}"}; do
  IFS=$'\t' read -r number ref dep version labels <<< "$row"
  if [ "$ref" != "${newest_ref[$dep]}" ]; then
    if [[ "$ref" == *:* ]]; then
      skip "$number" "$ref" "a newer finding of ${dep} is tested (${newest_ref[$dep]})"
    else
      superseded+=("$(jq -nc --argjson issue "$number" --argjson by "${newest_ref[$dep]%%:*}" --arg dep "$dep" --arg version "${newest[$dep]}" \
        '{issue: $issue, by: $by, dep: $dep, version: $version}')")
    fi
    continue
  fi
  is_named=0
  if [[ "$ref" == *:* ]]; then named_line "$number" "$dep" && is_named=1; else named "$number" && is_named=1; fi
  if [ "$only" != "  " ] && [ "$is_named" = 0 ]; then
    skip "$number" "$ref" "not named in this run"
    continue
  fi
  if [[ "$labels" == *"|${ATTENTION_LABEL}|"* ]]; then
    if [ "$is_named" = 0 ]; then
      skip "$number" "$ref" "waits for a person (${ATTENTION_LABEL}); run the workflow with $( [[ "$ref" == *:* ]] && echo "\`${ref}\`" || echo "this issue number") to test it again"
      continue
    fi
    [[ "$ref" == *:* ]] || retrigger+=("$number")
  fi
  if [ -n "${markers[$ref]:-}" ]; then
    tests+=("$(jq -nc --argjson issue "$number" --arg ref "$ref" --arg dep "$dep" --arg version "$version" --argjson m "${markers[$ref]}" \
      '{issue: $issue, ref: $ref, dep: $dep, version: $version, updates: $m.updates, urgent: ($m.urgent // false)}')")
  else
    tests+=("$(jq -nc --argjson issue "$number" --arg ref "$ref" --arg dep "$dep" --arg version "$version" '{issue: $issue, ref: $ref, dep: $dep, version: $version}')")
  fi
done

join_json() { if [ "$#" -gt 0 ]; then printf '%s\n' "$@" | jq -s -c .; else echo '[]'; fi; }
jq -nc \
  --argjson tests "$(join_json ${tests[@]+"${tests[@]}"})" \
  --argjson superseded "$(join_json ${superseded[@]+"${superseded[@]}"})" \
  --argjson retrigger "$(if [ "${#retrigger[@]}" -gt 0 ]; then printf '%s\n' "${retrigger[@]}" | jq -s -c .; else echo '[]'; fi)" \
  --argjson attention "$(join_json ${attention[@]+"${attention[@]}"})" \
  --argjson skipped "$(join_json ${skipped[@]+"${skipped[@]}"})" \
  '{tests: ($tests | sort_by(.dep)), superseded: $superseded, retrigger: $retrigger, attention: $attention, skipped: $skipped}'
