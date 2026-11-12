#!/usr/bin/env bash
set -euo pipefail

# ── The console's test results ───────────────────────────────────────────────
# What the console's test reports say, not only the exit status of their steps (the filex bot's
# #1807): ui-tests.yml reads Vitest's JSON report, ui-stack-suite.yml Playwright's. Both run in
# ci.yml / e2e.yml and for the dependency test bot (deps-test.yml), whose outcome
# (ci/deps-run.sh) reads what this writes: RESULT_DIR/failed (the failures the report counts,
# and one for each failed step), RESULT_DIR/failures.md (what failed, empty when nothing did)
# and RESULT_DIR/tail.txt (the end of each failed step's log). Exits 1 when anything failed.
#
#   bash ci/ui-results.sh vitest REPORT RESULT_DIR [STEP=OUTCOME[=LOG] …]
#       every test passed, no test file failed outside its tests (an import that throws), and
#       tests/composables/tutorialSteps.spec.js ran and passed (CLAUDE.md: the in-app tutorial)
#   bash ci/ui-results.sh playwright REPORT RESULT_DIR [STEP=OUTCOME[=LOG] …]
#       no unexpected result (stats.unexpected: failed, timed out, or passed where it should
#       fail) and no error of the run itself (errors)
#
# STEP=OUTCOME[=LOG]: a step of the job, its outcome (steps.<id>.outcome) and its log; a failure
# counts once (a step skipped after an earlier one failed is not counted again). tail.txt has the
# end of the log of every failed step, or, when none failed, of the last step given (the one that
# wrote the report).
#
# Needs bash and jq.
# ─────────────────────────────────────────────────────────────────────────────

usage="usage: ci/ui-results.sh vitest|playwright REPORT RESULT_DIR [STEP=OUTCOME[=LOG] …]"
kind="${1:?$usage}"
report="${2:?$usage}"
res="${3:?$usage}"
shift 3
case "$kind" in vitest|playwright) ;; *) echo "$usage" >&2; exit 2 ;; esac
mkdir -p "$res"
out="$res/failures.md"
: > "$out"
: > "$res/tail.txt"
failed=0

# the spec CLAUDE.md requires to pass: the steps of the in-app tutorial and their targets
TUTORIAL_SPEC="tests/composables/tutorialSteps.spec.js"

# count N — N more failures (anything but a number counts as one)
count() {
  if [[ "${1:-}" =~ ^[0-9]+$ ]]; then failed=$((failed + $1)); else failed=$((failed + 1)); fi
}

# log_tail NAME LOG — the end of a step's log, under its name, without the terminal's colours
log_tail() {
  [ -s "$2" ] || return 0
  { echo "── ${1}"; tail -n 30 "$2" | sed -E 's/\x1b\[[0-9;]*[A-Za-z]//g'; } >> "$res/tail.txt"
}

last_name="" last_log=""
for s in "$@"; do
  name="${s%%=*}" rest="${s#*=}" log=""
  outcome="${rest%%=*}"
  [ "$rest" = "$outcome" ] || log="${rest#*=}"
  last_name="$name" last_log="$log"
  if [ "$outcome" = failure ]; then
    echo "- ${name} failed" >> "$out"
    count 1
    log_tail "$name" "$log"
  fi
done
[ -s "$res/tail.txt" ] || log_tail "$last_name" "$last_log"

# report_ok JQ — the report is JSON of the expected shape
report_ok() { [ -s "$report" ] && jq -e "$1" "$report" >/dev/null 2>&1; }

# unreadable — a report whose failures cannot be listed (another shape): a failure of its own
unreadable() {
  echo "- the ${kind} report could not be read (another shape?)" >> "$out"
  count 1
}

case "$kind" in
  vitest)
    if ! report_ok '.testResults | type == "array"'; then
      echo "- Vitest wrote no report" >> "$out"
      count 1
    else
      # the failed tests, and the test files that failed without a failed test (they did not load)
      count "$(jq '(.numFailedTests // 0)
        + ([.testResults[] | select(.status == "failed")
            | select([.assertionResults[]? | select(.status == "failed")] | length == 0)] | length)' "$report")"
      jq -r '[.testResults[] | (.name | sub("^.*/vue/tests/"; "vue/tests/")) as $file
          | if ([.assertionResults[]? | select(.status == "failed")] | length) > 0
            then .assertionResults[] | select(.status == "failed") | "- \($file): \(.fullName // .title)"
            elif .status == "failed"
            then "- \($file): \(if (.message // "") == "" then "failed" else .message | split("\n")[0] end)"
            else empty end] | .[:30][]' "$report" >> "$out" || unreadable
      if ! jq -e --arg spec "/${TUTORIAL_SPEC}" \
          '[.testResults[] | select(.name | endswith($spec)) | select(.status == "passed")] | length == 1' "$report" >/dev/null; then
        echo "- vue/${TUTORIAL_SPEC} did not run and pass (the in-app tutorial's steps, CLAUDE.md)" >> "$out"
        count 1
      fi
    fi ;;
  playwright)
    if ! report_ok '.stats | type == "object"'; then
      echo "- Playwright wrote no report" >> "$out"
      count 1
    else
      count "$(jq '(.stats.unexpected // 0) + ((.errors // []) | length)' "$report")"
      jq -r '[.. | objects | select(has("specs")) | .specs[]?
          | select(any(.tests[]?; .status == "unexpected"))
          | "- vue/e2e/\(.file):\(.line) \(.title) (\([.tests[]? | select(.status == "unexpected") | .projectName] | unique | join(", ")))"]
        | unique | .[:30][]' "$report" >> "$out" || unreadable
      jq -r '[.errors[]? | "- \(.message // .value // "an error of the run" | tostring | split("\n")[0])"] | .[:10][]' "$report" >> "$out" || unreadable
    fi ;;
esac

# a run that failed without saying what (a count of its report and no line of it)
if [ "$failed" -gt 0 ] && [ ! -s "$out" ]; then
  echo "- ${kind}: ${failed} failure(s), see the log" >> "$out"
fi
echo "$failed" > "$res/failed"
echo "${kind}: ${failed} failure(s)"
[ "$failed" = 0 ]
