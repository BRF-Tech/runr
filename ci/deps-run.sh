#!/usr/bin/env bash
set -euo pipefail

# ── Dependency test bot: the steps of a run ──────────────────────────────────
# The parts of .github/workflows/deps-test.yml that are more than a command, so that they are
# tested here (runtime/test/deps_bot_test.sh) and not only on GitHub:
#
#   bash ci/deps-run.sh bumps TESTS.json NEXT DIR [RUN_URL]
#       every test of the plan (ci/deps-plan.sh) on a clone of next (NEXT): from the same commit,
#       next's own ci/deps-bump.sh moves the dependency, and a bump is committed (the bot's
#       identity: DEPS_BOT_NAME, DEPS_BOT_EMAIL) as the branch deps/<dep>. DIR/bump/<dep>/ holds
#       the bump's report with issue, version, base and head; DIR/matrix-suites.json and
#       DIR/matrix-unit.json the jobs that test the bumps.
#   bash ci/deps-run.sh outcome DIR
#       after the test jobs (their results in DIR/results/<artifact>/): DIR/outcome/<dep> is
#       green, red, attention, current or error; DIR/green lists the green ones in the order
#       they land
#   bash ci/deps-run.sh actions DIR RUN_URL ATTEMPT
#       after the landing (DIR/land/<dep>: "landed SHA" or "moved SHA"): what the issues get, one
#       action per line of DIR/actions (tab-separated: comment ISSUE FILE, label ISSUE LABEL,
#       unlabel ISSUE LABEL, close ISSUE, dispatch ISSUES ATTEMPT), the comments in DIR/comments/
#
# Needs bash, git, jq.
# ─────────────────────────────────────────────────────────────────────────────

PASSED_LABEL="${DEPS_PASSED_LABEL:-deps: passed}"
ATTENTION_LABEL="${DEPS_ATTENTION_LABEL:-needs attention}"
# next moved under a green bump this many runs in a row: the next weekly run tries again
MAX_ATTEMPTS="${DEPS_MAX_ATTEMPTS:-3}"

# result_name DEP SUITE — the artifact a test job of DEP uploads its result as
result_name() { echo "deps-result-$1-$2"; }

cmd_bumps() {
  local tests="$1" next="$2" dir="$3" run_url="${4:-}" base n i issue dep version report rc
  mkdir -p "$dir/bump"
  base="$(git -C "$next" rev-parse HEAD)"
  n="$(jq length "$tests")"
  echo '[]' > "$dir/matrix-suites.json"
  echo '[]' > "$dir/matrix-unit.json"
  for ((i = 0; i < n; i++)); do
    issue="$(jq -r ".[$i].issue" "$tests")"
    dep="$(jq -r ".[$i].dep" "$tests")"
    version="$(jq -r ".[$i].version" "$tests")"
    report="$dir/bump/$dep"
    mkdir -p "$report"
    echo "$issue" > "$report/issue"
    echo "$version" > "$report/version"
    echo "$base" > "$report/base"
    git -C "$next" checkout -q --detach "$base"
    echo "── ${dep} ${version} (#${issue}) on ${base}"
    rc=0
    bash "$next/ci/deps-bump.sh" "$dep" "$version" --report="$report" ${run_url:+--run-url="$run_url"} > "$report/bump.log" 2>&1 || rc=$?
    cat "$report/bump.log"
    case "$rc" in
      0)
        GIT_AUTHOR_NAME="${DEPS_BOT_NAME:?}" GIT_AUTHOR_EMAIL="${DEPS_BOT_EMAIL:?}" \
        GIT_COMMITTER_NAME="$DEPS_BOT_NAME" GIT_COMMITTER_EMAIL="$DEPS_BOT_EMAIL" \
          git -C "$next" commit -q -a -F "$report/commit-msg.txt"
        git -C "$next" rev-parse HEAD > "$report/head"
        git -C "$next" branch -f "deps/${dep}" HEAD
        git -C "$next" show --stat --format='%H%n%an <%ae>%n%n%B' HEAD
        jq -c --arg dep "$dep" --argjson issue "$issue" --arg ref "deps/${dep}" \
          '[.[] | . + {dep: $dep, issue: $issue, ref: $ref}]' "$report/suites.json" > "$dir/suites.tmp"
        jq -s -c 'add' "$dir/matrix-suites.json" "$dir/suites.tmp" > "$dir/matrix.tmp" && mv "$dir/matrix.tmp" "$dir/matrix-suites.json"
        jq -c --arg dep "$dep" --argjson issue "$issue" --arg ref "deps/${dep}" '. + [{dep: $dep, issue: $issue, ref: $ref}]' \
          "$dir/matrix-unit.json" > "$dir/matrix.tmp" && mv "$dir/matrix.tmp" "$dir/matrix-unit.json"
        rm -f -- "$dir/suites.tmp" ;;
      2|3) ;;
      *) echo "error" > "$report/result" ;;
    esac
    # the next bump starts from next as it is, whatever this one left
    git -C "$next" checkout -q -f --detach "$base"
  done
  git -C "$next" checkout -q -f --detach "$base"
}

cmd_outcome() {
  local dir="$1" report dep result suite names ok
  mkdir -p "$dir/outcome"
  : > "$dir/green"
  for report in "$dir"/bump/*/; do
    [ -d "$report" ] || continue
    report="${report%/}"
    dep="${report##*/}"
    result="$(cat "$report/result" 2>/dev/null || echo error)"
    case "$result" in
      current|attention|error) echo "$result" > "$dir/outcome/$dep"; continue ;;
    esac
    names="unit shell $(jq -r '.[].suite' "$report/suites.json" | tr '\n' ' ')"
    ok=1
    : > "$report/failed"
    for suite in $names; do
      if [ "$(cat "$dir/results/$(result_name "$dep" "$suite")/status" 2>/dev/null || true)" != "success" ]; then
        ok=0
        echo "$suite" >> "$report/failed"
      fi
    done
    if [ "$ok" = 1 ]; then
      echo green > "$dir/outcome/$dep"
      echo "$dep" >> "$dir/green"
    else
      echo red > "$dir/outcome/$dep"
    fi
  done
}

# suite_line DIR DEP SUITE — "the command: status" of one test job
suite_line() {
  local res command status
  res="$1/results/$(result_name "$2" "$3")"
  command="$(cat "$res/command" 2>/dev/null || echo "$3")"
  status="$(cat "$res/status" 2>/dev/null || echo "no result (the job was cancelled, timed out or did not start)")"
  case "$3" in
    unit) command="PHPUnit, Pint, Larastan, API reference" ;;
    shell) command="runtime/test and the reqs mirror check" ;;
    *) command="runtime/ci.sh ${command}" ;;
  esac
  echo "\`${command}\`: ${status}"
}

cmd_actions() {
  local dir="$1" run_url="$2" attempt="${3:-1}" report dep issue version outcome base head land comment suite res moved=()
  mkdir -p "$dir/comments"
  : > "$dir/actions"
  [[ "$attempt" =~ ^[0-9]+$ ]] || attempt=1
  for report in "$dir"/bump/*/; do
    [ -d "$report" ] || continue
    report="${report%/}"
    dep="${report##*/}"
    issue="$(cat "$report/issue")"
    version="$(cat "$report/version")"
    base="$(cat "$report/base")"
    head="$(cat "$report/head" 2>/dev/null || true)"
    outcome="$(cat "$dir/outcome/$dep" 2>/dev/null || echo error)"
    comment="$dir/comments/${issue}.md"
    case "$outcome" in
      current)
        {
          echo "**Nothing to do: next has ${dep} ${version} or a newer one.**"
          echo ""
          cat "$report/summary.md" 2>/dev/null || true
          echo ""
          echo "Closed by the dependency test bot ([run](${run_url}))."
        } > "$comment"
        printf 'comment\t%s\t%s\nclose\t%s\n' "$issue" "$comment" "$issue" >> "$dir/actions" ;;
      attention)
        {
          cat "$report/summary.md"
          echo ""
          echo "Nothing was pushed: next is unchanged ([run](${run_url})). Once next can take it (or a person moved it), run **Actions → Dependency tests → Run workflow** with this issue's number to test it again; a newer release supersedes this issue by itself."
        } > "$comment"
        printf 'label\t%s\t%s\ncomment\t%s\t%s\n' "$issue" "$ATTENTION_LABEL" "$issue" "$comment" >> "$dir/actions" ;;
      error)
        {
          echo "**The dependency test bot could not check ${dep} ${version}** ([run](${run_url})): the next run tries again."
          echo ""
          echo '```'
          tail -n 15 "$report/bump.log" 2>/dev/null || echo "no log"
          echo '```'
        } > "$comment"
        printf 'comment\t%s\t%s\n' "$issue" "$comment" >> "$dir/actions" ;;
      red)
        {
          echo "**Needs attention: ${dep} ${version} failed on next.** Nothing was pushed: next is unchanged."
          echo ""
          echo "The bump (on ${base:0:12}, commit ${head:0:12} of this [run](${run_url}), not pushed):"
          echo ""
          cat "$report/summary.md"
          echo ""
          echo "Suites:"
          echo ""
          for suite in unit shell $(jq -r '.[].suite' "$report/suites.json" | tr '\n' ' '); do
            echo "- $(suite_line "$dir" "$dep" "$suite")"
          done
          while read -r suite; do
            [ -n "$suite" ] || continue
            res="$dir/results/$(result_name "$dep" "$suite")"
            echo ""
            echo "<details><summary>${suite}: what failed</summary>"
            echo ""
            if [ -s "$res/failures.md" ]; then
              head -n 30 "$res/failures.md"
              echo ""
            fi
            echo '```'
            tail -n 40 "$res/tail.txt" 2>/dev/null || echo "no log: the job did not get to the suite"
            echo '```'
            echo ""
            echo "</details>"
          done < "$report/failed"
          echo ""
          echo "Fix what failed on next (or decide the version waits), then run **Actions → Dependency tests → Run workflow** with this issue's number; a newer release supersedes this issue by itself."
        } > "$comment"
        printf 'label\t%s\t%s\ncomment\t%s\t%s\n' "$issue" "$ATTENTION_LABEL" "$issue" "$comment" >> "$dir/actions" ;;
      green)
        land="$(cat "$dir/land/$dep" 2>/dev/null || echo "failed")"
        case "$land" in
          landed\ *)
            {
              echo "**Passed: ${dep} ${version} is on next** as ${land#landed }, a fast-forward of the next it was tested on."
              echo ""
              cat "$report/summary.md"
              echo ""
              echo "Suites ([run](${run_url})):"
              echo ""
              for suite in unit shell $(jq -r '.[].suite' "$report/suites.json" | tr '\n' ' '); do
                echo "- $(suite_line "$dir" "$dep" "$suite")"
              done
              echo ""
              echo "Fixed on next by ${land#landed }, ships with the next release."
            } > "$comment"
            printf 'label\t%s\t%s\ncomment\t%s\t%s\nclose\t%s\n' "$issue" "$PASSED_LABEL" "$issue" "$comment" "$issue" >> "$dir/actions" ;;
          moved\ *)
            if [ "$attempt" -lt "$MAX_ATTEMPTS" ]; then
              echo "**${dep} ${version} passed on ${base:0:12}, but next moved meanwhile** (to ${land#moved }). The bot lands a bump only on the next it tested: it moves and tests it again on the new next ([run](${run_url}); attempt $((attempt + 1)) of ${MAX_ATTEMPTS})." > "$comment"
              moved+=("$issue")
            else
              echo "**${dep} ${version} passed on ${base:0:12}, but next moved under ${MAX_ATTEMPTS} runs in a row** (now ${land#moved }). The next weekly run tests it again ([run](${run_url}))." > "$comment"
            fi
            printf 'comment\t%s\t%s\n' "$issue" "$comment" >> "$dir/actions" ;;
          *)
            {
              echo "**${dep} ${version} passed, but the push to next failed** ([run](${run_url})): next is unchanged and the next run tries again."
              echo ""
              echo "${land}"
            } > "$comment"
            printf 'comment\t%s\t%s\n' "$issue" "$comment" >> "$dir/actions" ;;
        esac ;;
    esac
  done
  if [ "${#moved[@]}" -gt 0 ]; then
    printf 'dispatch\t%s\t%s\n' "$(IFS=,; echo "${moved[*]}")" "$((attempt + 1))" >> "$dir/actions"
  fi
}

command="${1:-}"
shift || true
case "$command" in
  bumps) cmd_bumps "$@" ;;
  outcome) cmd_outcome "$@" ;;
  actions) cmd_actions "$@" ;;
  *) sed -n '4,25p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
