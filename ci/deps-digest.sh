#!/usr/bin/env bash
set -euo pipefail

# ── The dependency bots' weekly summary ──────────────────────────────────────
# One issue per ISO week with movement, "deps: week 2026-W46", lists what the dependency bots
# found and did: one row per dependency (a package group's minor and patch updates, a Helm chart,
# Knative) with its state, rendered from the data markers its body carries (ci/deps/digest.jq; the
# format, shared with filex's bot, in docs/development.md → Dependency test bot → The weekly
# summary). A security finding is a row like any other (Note "security: <advisory>", tested the
# same day). A separate issue only when a person has to do something: a bump that went red (split
# out of the summary by the test bot, its row's Item links it), a test run that needs a person
# (one issue for its rows), one issue per group for its major versions ("deps: <group> major
# versions need a decision"), a Knative minor without its row. A major a hold keeps
# (ci/deps/holds.txt, with its reason) is a row "held (<reason>)", no issue.
#
# A week opens its summary only when it has movement (a new finding, a test result, a release):
# the open rows of the last one carry over into it and the last one closes ("Continued in #N.");
# a later week closes a summary with nothing open ("Closed at the end of the week: nothing was
# left open."); a quiet week edits the open one at most (a row whose issue was closed, a run that
# starts). The bot edits bodies and writes no comment on a summary; an issue it closes gets its
# closing text in its body.
#
#   bash ci/deps-digest.sh file FINDINGS_DIR ISSUES.json DATE
#       upstream-releases.yml: the findings of ci/check-*-releases.sh (FINDINGS_DIR: their *.md,
#       held/*.json and checked) against every issue (ISSUES.json: gh issue list --state all --json
#       number,title,state,labels,body,createdAt); prints the operations (JSON). The findings filed
#       as issues of their own before the summary (open, not labelled "needs attention") fold into
#       it ("Folded into the weekly summary #N: …"); the issues of single releases outside a
#       constraint into their group's major versions issue. A finding a read group (checked) no
#       longer reports: landed → shipped in the last release (DEPS_RELEASE_VERSION, default
#       helm/Chart.yaml's appVersion), open → closed.
#   bash ci/deps-digest.sh report DIR ISSUES.json DATE
#       deps-test.yml: what ci/deps-run.sh actions wrote (DIR/actions: the issues of their own;
#       DIR/lines: the rows, fields separated by \x1f: REF KIND GROUP VERSION STATE MODE NOTE FILE
#       RELEASE URL) against the open issues; prints the operations (JSON). DEPS_DISPATCH_REF: the
#       branch a dispatch runs the workflow on.
#   bash ci/deps-digest.sh apply OPS.json
#       the operations with gh (GH_REPO or GITHUB_REPOSITORY): create (its number for {{key}}),
#       alias, edit, reopen, comment, label, unlabel, close, dispatch, in order; exit 1 when one
#       failed (the others still run, one that needs its number is skipped).
#   bash ci/deps-digest.sh week DATE
#       {"week", "monday", "sunday"} of a date (YYYY-MM-DD).
#
# Needs bash, jq (1.7) and GNU date; apply needs gh.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LIB="$ROOT/ci/deps"
die() { echo "deps-digest: $*" >&2; exit 1; }

week_json() {
  local d="$1" dow monday
  [[ "$d" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || die "a date YYYY-MM-DD: '${d}'"
  dow="$(date -u -d "$d" +%u)"
  monday="$(date -u -d "$d -$((dow - 1)) days" +%F)"
  jq -nc --arg w "$(date -u -d "$d" +%G-W%V)" --arg m "$monday" --arg s "$(date -u -d "$monday +6 days" +%F)" \
    '{week: $w, monday: $m, sunday: $s}'
}

cmd_file() {
  local dir="${1:?FINDINGS_DIR}" issues="${2:?ISSUES.json}" date="${3:?DATE}" work f release
  work="$(mktemp -d)"
  # shellcheck disable=SC2064 # the directory of this call
  trap "rm -rf -- '$work'" RETURN
  shopt -s nullglob
  for f in "$dir"/*.md; do
    jq -Rs '(split("\n")) as $l | {title: $l[0], body: ($l[2:] | join("\n"))}' "$f"
  done | jq -s . > "$work/findings.json"
  for f in "$dir"/held/*.json; do cat "$f"; echo; done | jq -s . > "$work/held.json"
  shopt -u nullglob
  if [ -f "$dir/checked" ]; then jq -Rs 'split("\n") | map(select(length > 0)) | unique' "$dir/checked"; else echo '[]'; fi > "$work/checked.json"
  # the last release (public main's): what a finding landed on next ships in once the check no
  # longer reports it
  release="${DEPS_RELEASE_VERSION:-$(sed -n 's/^appVersion: *"\{0,1\}\([^"]*\)"\{0,1\} *$/\1/p' "$ROOT/helm/Chart.yaml" 2>/dev/null | head -n 1)}"
  jq -n -L "$LIB" --slurpfile issues "$issues" --slurpfile findings "$work/findings.json" --slurpfile held "$work/held.json" \
    --slurpfile checked "$work/checked.json" --arg date "$date" --argjson week "$(week_json "$date")" --arg release "${release:-?}" \
    'include "digest"; {issues: $issues[0], findings: $findings[0], held: $held[0], checked: $checked[0], date: $date, week: $week,
      release: ($release | ltrimstr("v"))} | file_main'
}

cmd_report() {
  local dir="${1:?DIR}" issues="${2:?ISSUES.json}" date="${3:?DATE}" work action issue arg ref kind group version state mode note file release url
  work="$(mktemp -d)"
  # shellcheck disable=SC2064 # the directory of this call
  trap "rm -rf -- '$work'" RETURN
  # the issues of their own, as ci/deps-run.sh actions wrote them (tab-separated)
  : > "$work/actions.jsonl"
  if [ -f "$dir/actions" ]; then
    while IFS=$'\t' read -r action issue arg; do
      case "$action" in
        comment) jq -nc --arg i "$issue" --rawfile b "$arg" '{op: "comment", issue: ($i | tonumber), body: $b}' ;;
        label|unlabel) jq -nc --arg o "$action" --arg i "$issue" --arg l "$arg" '{op: $o, issue: ($i | tonumber), label: $l}' ;;
        close) jq -nc --arg i "$issue" '{op: "close", issue: ($i | tonumber), reason: "completed"}' ;;
        dispatch) jq -nc --arg r "$issue" --arg a "$arg" '{op: "dispatch", refs: $r, attempt: $a}' ;;
        '') ;;
        *) die "an unknown action '${action}' in ${dir}/actions" ;;
      esac
    done < "$dir/actions" >> "$work/actions.jsonl"
  fi
  : > "$work/updates.jsonl"
  if [ -f "$dir/lines" ]; then
    while IFS=$'\x1f' read -r ref kind group version state mode note file release url; do
      [ -n "$ref" ] || continue
      [[ "$ref" =~ ^[0-9]+(:[a-z0-9-]+)?$ ]] || die "a line's ref '${ref}' in ${dir}/lines"
      [ -n "$file" ] && [ -f "$file" ] || file=/dev/null
      jq -nc --arg ref "$ref" --arg kind "$kind" --arg group "$group" --arg version "$version" --arg state "$state" \
        --arg mode "${mode:-line}" --arg note "$note" --rawfile body "$file" --arg release "$release" --arg url "$url" \
        '{ref: $ref, kind: $kind, group: $group, version: $version, state: $state, mode: $mode, note: $note, body: $body, release: $release, url: $url}'
    done < "$dir/lines" >> "$work/updates.jsonl"
  fi
  jq -n -L "$LIB" --slurpfile issues "$issues" --slurpfile actions "$work/actions.jsonl" --slurpfile updates "$work/updates.jsonl" \
    --arg date "$date" --argjson week "$(week_json "$date")" --arg ref "${DEPS_DISPATCH_REF:-}" \
    'include "digest"; {issues: $issues[0], actions: $actions, updates: $updates, date: $date, week: $week,
      dispatch_ref: (if $ref == "" then null else $ref end)} | report_main'
}

cmd_apply() {
  local ops="${1:?OPS.json}" repo="${GH_REPO:-${GITHUB_REPOSITORY:-}}" n i op key issue title label reason comment refs attempt ref url errors=0 work
  local -a labels args
  local -A num=()
  [ -n "$repo" ] || die "apply: GH_REPO or GITHUB_REPOSITORY names the repository"
  work="$(mktemp -d)"
  # shellcheck disable=SC2064 # the directory of this call
  trap "rm -rf -- '$work'" RETURN
  # sub TEXT — TEXT with every {{key}} of an issue created (or aliased) so far as its number
  sub() {
    local s="$1" k
    for k in "${!num[@]}"; do s="${s//"{{${k}}}"/${num[$k]}}"; done
    printf '%s' "$s"
  }
  field() { jq -r --arg f "$1" '.[$f] // "" | if type == "array" then join("\n") else tostring end' "$work/op.json"; }
  n="$(jq length "$ops")"
  for ((i = 0; i < n; i++)); do
    jq -c ".[$i]" "$ops" > "$work/op.json"
    op="$(field op)"
    issue="$(sub "$(field issue)")"
    if [[ "$issue" == *"{{"* ]]; then
      echo "::warning::${op} skipped: ${issue} was not created" >&2
      errors=$((errors + 1))
      continue
    fi
    case "$op" in
      alias)
        num[$(field key)]="$issue" ;;
      create)
        key="$(field key)"
        title="$(sub "$(field title)")"
        sub "$(field body)" > "$work/body.md"
        labels=()
        while IFS= read -r label; do [ -z "$label" ] || labels+=(--label "$label"); done < <(field labels)
        if url="$(gh issue create --repo "$repo" --title "$title" --body-file "$work/body.md" "${labels[@]}")"; then
          num[$key]="${url##*/}"
          echo "created #${url##*/}: ${title}"
        else
          echo "::warning::could not create: ${title}" >&2
          errors=$((errors + 1))
        fi ;;
      edit)
        sub "$(field body)" > "$work/body.md"
        args=(--body-file "$work/body.md")
        title="$(sub "$(field title)")"
        [ -z "$title" ] || args+=(--title "$title")
        gh issue edit "$issue" --repo "$repo" "${args[@]}" >/dev/null && echo "edited #${issue}" || errors=$((errors + 1)) ;;
      reopen)
        gh issue reopen "$issue" --repo "$repo" >/dev/null && echo "reopened #${issue}" || errors=$((errors + 1)) ;;
      comment)
        sub "$(field body)" > "$work/body.md"
        gh issue comment "$issue" --repo "$repo" --body-file "$work/body.md" >/dev/null && echo "commented on #${issue}" || errors=$((errors + 1)) ;;
      label|unlabel)
        label="$(field label)"
        if [ "$op" = label ]; then args=(--add-label "$label"); else args=(--remove-label "$label"); fi
        gh issue edit "$issue" --repo "$repo" "${args[@]}" >/dev/null && echo "${op}led #${issue}: ${label}" || errors=$((errors + 1)) ;;
      close)
        reason="$(field reason)"
        comment="$(sub "$(field comment)")"
        args=(--reason "${reason:-completed}")
        [ -z "$comment" ] || args+=(--comment "$comment")
        gh issue close "$issue" --repo "$repo" "${args[@]}" >/dev/null && echo "closed #${issue}" || errors=$((errors + 1)) ;;
      dispatch)
        refs="$(sub "$(field refs)")"
        attempt="$(field attempt)"
        ref="$(field ref)"
        if [[ "$refs" == *"{{"* ]] || ! [[ "$refs" =~ ^[0-9]+(:[a-z0-9-]+)?(,[0-9]+(:[a-z0-9-]+)?)*$ ]]; then
          echo "::warning::dispatch skipped: '${refs}'" >&2
          errors=$((errors + 1))
          continue
        fi
        args=(-f issue="$refs" -f attempt="${attempt:-1}")
        [ -z "$ref" ] || args=(--ref "$ref" "${args[@]}")
        gh workflow run deps-test.yml --repo "$repo" "${args[@]}" && echo "deps-test.yml started for ${refs} (attempt ${attempt:-1})" || errors=$((errors + 1)) ;;
      *) echo "::warning::unknown operation ${op}" >&2; errors=$((errors + 1)) ;;
    esac
  done
  [ "$errors" = 0 ] || { echo "${errors} operation(s) failed or were skipped" >&2; return 1; }
}

command="${1:-}"
shift || true
case "$command" in
  file) cmd_file "$@" ;;
  report) cmd_report "$@" ;;
  apply) cmd_apply "$@" ;;
  week) week_json "${1:?DATE}" ;;
  *) sed -n '4,44p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
