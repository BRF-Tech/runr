#!/usr/bin/env bash
set -euo pipefail

# ── Dependency test bot: the steps of a run ──────────────────────────────────
# The parts of .github/workflows/deps-test.yml that are more than a command, so that they are
# tested here (runtime/test/deps_release_test.sh, deps_scope_test.sh) and not only on GitHub. A
# run makes one release of all its green bumps (docs/development.md → Dependency test bot):
#
#   bash ci/deps-run.sh bumps TESTS.json CLONE DIR PUBLIC [RUN_URL]
#       every test of the plan (ci/deps-plan.sh), each alone on the last release: CLONE is a clone
#       of the origin (its main and next as origin/main and origin/next), PUBLIC the public
#       repository's URL; ci/deps-release.sh base names the release (DIR/base.env; when the bot
#       cannot release on top of it, every test is held with the reason). From the release tag,
#       the tag's own ci/deps-bump.sh moves the dependency, the bump is committed (the bot's
#       identity: DEPS_BOT_NAME, DEPS_BOT_EMAIL), ci/deps-release.sh prepare puts the release
#       commit of the next patch version on it, and that is the branch deps/<dep>, which the
#       suites test; a release that next could not take mechanically needs a person before any
#       suite runs. A dependency the tag's own bump does not know yet (a package group added after
#       the tag) is held until the next full release. A package group's bump that ships nothing
#       (ci/deps/packages.sh: PATH next, or what an auto bump moved) is made on origin/next
#       instead, with no release commit: deps/<dep> is next and the bump. Every bump commit
#       carries the trailer "RunR-Deps-Bot: <dep> <version>" (DEPS_TRAILER).
#       DIR/bump/<dep>/ holds the bump's report with issue, version, base, bump, head (the release
#       commit, or the bump on next) and path; DIR/matrix-suites.json (Kind suites),
#       matrix-checks.json (ci/deps-check.sh), matrix-ui.json (ui-tests.yml), matrix-ui-stack.json
#       (ui-stack-suite.yml), matrix-unit.json (PHPUnit) and matrix-shell.json (runtime/test) the
#       jobs that test them (SUITE_JOBS).
#   bash ci/deps-run.sh outcome DIR
#       after the test jobs (their results in DIR/results/<artifact>/): DIR/outcome/<dep> is
#       green, red, attention, held, current or error; DIR/green lists the green ones in the
#       order of a batch: an urgent one (a security advisory) first, then by issue
#   bash ci/deps-run.sh batch DIR CLONE
#       the green bumps together, one batch per path (DIR/batch/release/, DIR/batch/next/): the
#       first is its own bump, every other one is made again on the one before it by the tag's
#       (or next's) own ci/deps-bump.sh, a commit of the bot each; one that is not made
#       mechanically there stops the batch before it (DIR/batch/<path>/breaker; the ones after it
#       wait). The release's batch gets one release commit (ci/deps-release.sh prepare), which
#       next must take (merge-next). A batch of two or more is tested together on deps/batch or
#       deps/batch-next with the union of its bumps' suites (DIR/batch/matrix-*.json; the results
#       as deps-result-batch-<suite> and deps-result-batch-next-<suite>); a batch of one is the
#       tree that was tested alone.
#   bash ci/deps-run.sh pick DIR REPO
#       after the combined tests: what the run releases (DIR/release/release.env) and lands on next
#       (DIR/land/release.env), for ci/deps-release.sh (REPO: a clone of the batch's bundle). When a
#       combined tree failed a suite, the first bump from the second on whose suites, with those of
#       the bumps before it, include a failed one breaks the batch: the bumps before it go, made the
#       same way (the batch's own commits) and not tested again; the ones after it wait.
#       DIR/role/<dep> is release, land, breaker, wait or nobatch for every green bump
#       (DIR/role/<dep>.md says why for a breaker or one that waits). Prints release=true|false
#       and land=true|false.
#   bash ci/deps-run.sh resume DIR REPO PUBLIC TAG ISSUES
#       a release of the bot that did not finish (the workflow's `resume`): REPO is a clone of the
#       origin, TAG its tag; DIR/release/release.env (ci/deps-release.sh resume-env) and a report
#       for each issue of ISSUES (comma-separated), which the release closes
#   bash ci/deps-run.sh actions DIR RUN_URL ATTEMPT [--dry-run]
#       after the release and the landing (DIR/release/, DIR/land/: steps, failed, moved, see
#       ci/deps-release.sh): what the issues get, one action per line of DIR/actions
#       (tab-separated: comment ISSUE FILE, label ISSUE LABEL, unlabel ISSUE LABEL, close ISSUE,
#       dispatch ISSUES ATTEMPT: the bumps of a release or a landing whose base moved, tested
#       again), the comments in DIR/comments/; --dry-run writes the comments and no action
#
# Needs bash, git, jq.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC2034 # read by ci/deps/lib.sh
DEPS_ROOT="$ROOT"
# shellcheck source=ci/deps/lib.sh
source "$ROOT/ci/deps/lib.sh"
# the trailer that marks the bot's commits and tags (ci/deps/check-attribution.sh): their author is
# the identity of RunR's public history (DEPS_BOT_NAME, DEPS_BOT_EMAIL), the trailer tells them apart
DEPS_TRAILER="${DEPS_TRAILER:-RunR-Deps-Bot}"
PASSED_LABEL="${DEPS_PASSED_LABEL:-deps: passed}"
ATTENTION_LABEL="${DEPS_ATTENTION_LABEL:-needs attention}"
# the release moved under the green bumps this many runs in a row (another release came first): the
# next weekly run tries again
MAX_ATTEMPTS="${DEPS_MAX_ATTEMPTS:-3}"
# the steps of a release, in order (ci/deps-release.sh)
RELEASE_STEPS="origin public images github helm"

# the test jobs of suites.json, MATRIX:JOB: a suite of job JOB (its "job") goes to
# matrix-MATRIX.json, the matrix of deps-test.yml's job that runs it (kind: kind-suite.yml, check:
# ci/deps-check.sh, ui: ui-tests.yml, ui-stack: ui-stack-suite.yml); PHPUnit (unit) and
# runtime/test (shell) have a matrix of their own
SUITE_JOBS="suites:kind checks:check ui:ui ui-stack:ui-stack"

# result_name DEP SUITE — the artifact a test job of DEP (or of a batch) uploads its result as
result_name() { echo "deps-result-$1-$2"; }

# env_of FILE KEY — a value of a KEY=VALUE file
env_of() { [ -f "$1" ] || return 0; sed -n "s/^$2=//p" "$1" | tail -n 1; }

# bump_name DIR DEP — what the comments call a bump ("traefik 42.3.1")
bump_name() { echo "$2 $(cat "$1/bump/$2/version")"; }

# bump_names DIR DEP… — "#20 traefik 42.3.1, #14 zot 0.2.0" of the bumps
bump_names() {
  local dir="$1" d names=() joined
  shift
  for d in "$@"; do names+=("#$(cat "$dir/bump/$d/issue") $(bump_name "$dir" "$d")"); done
  local IFS=';'
  joined="${names[*]:-}"
  echo "${joined//;/, }"
}

cmd_bumps() {
  local tests="$1" clone="$2" dir="$3" public="$4" run_url="${5:-}" base base_tag new n i issue dep version report rc err path next_base f
  mkdir -p "$dir/bump"
  for f in $SUITE_JOBS unit shell; do echo '[]' > "$dir/matrix-${f%%:*}.json"; done
  err=""
  if ! bash "$ROOT/ci/deps-release.sh" base "$clone" "$public" > "$dir/base.env" 2> "$dir/base.err"; then
    err="$(tr '\n' ' ' < "$dir/base.err")"
    echo "ERROR=${err}" > "$dir/base.env"
    echo "::warning::no release now: ${err}"
  fi
  base="$(env_of "$dir/base.env" BASE_COMMIT)"
  base_tag="$(env_of "$dir/base.env" BASE_TAG)"
  new="$(env_of "$dir/base.env" NEW_VERSION)"
  next_base="$(git -C "$clone" rev-parse -q --verify refs/remotes/origin/next || true)"
  n="$(jq length "$tests")"
  for ((i = 0; i < n; i++)); do
    issue="$(jq -r ".[$i].issue" "$tests")"
    dep="$(jq -r ".[$i].dep" "$tests")"
    version="$(jq -r ".[$i].version" "$tests")"
    report="$dir/bump/$dep"
    mkdir -p "$report"
    echo "$issue" > "$report/issue"
    echo "$version" > "$report/version"
    echo "$run_url" > "$report/run-url"
    rm -f -- "$report/updates.json" "$report/urgent" "$report/held" "$report/held-wait"
    # a package group's updates (its finding's marker) and whether an advisory makes it urgent
    if jq -e ".[$i].updates" "$tests" >/dev/null 2>&1; then
      jq -c ".[$i] | {updates, urgent: (.urgent // false)}" "$tests" > "$report/updates.json"
      [ "$(jq -r ".[$i].urgent // false" "$tests")" != true ] || touch "$report/urgent"
    fi
    if [ -n "$err" ]; then
      echo "held" > "$report/result"
      echo "$err" > "$report/held"
      continue
    fi
    echo "$base" > "$report/base"
    echo "$base_tag" > "$report/base-tag"
    echo "$new" > "$report/release-version"
    # a group whose bumps never ship is made on next itself; any other on the release tag, and on
    # next instead when what it moved ships nothing
    path=release
    [ "$(deps_path_of "$dep")" != next ] || path=next
    if [ "$path" = release ]; then
      # the tag's own bump makes it: a dependency it does not know yet waits for the next full release
      if ! knows "$clone" "$base" "$dep"; then
        echo "held" > "$report/result"
        echo "the last release's bot does not know ${dep}; the next full release brings it" > "$report/held"
        touch "$report/held-wait"
        continue
      fi
      rc=0
      bump_on "$clone" "$base" "$dep" "$version" "$report" "$run_url" || rc=$?
      [ "$rc" = 0 ] || continue
      if [ "$(cat "$report/path" 2>/dev/null || echo release)" = next ]; then
        echo "── ${dep}: nothing it moved ships: made again on next, which takes it without a release"
        path=next
      fi
    fi
    if [ "$path" = next ]; then
      [ -n "$next_base" ] || { echo "error" > "$report/result"; echo "no origin/next in the clone" >> "$report/bump.log"; continue; }
      echo "$next_base" > "$report/next-base"
      rc=0
      bump_on "$clone" "$next_base" "$dep" "$version" "$report" "$run_url" || rc=$?
      [ "$rc" = 0 ] || continue
      echo next > "$report/path"
      git -C "$clone" rev-parse HEAD > "$report/head"
    else
      rc=0
      bash "$ROOT/ci/deps-release.sh" prepare "$clone" "$base_tag" "$new" "$report" >> "$report/bump.log" 2>&1 || rc=$?
      case "$rc" in
        0) ;;
        2) release_attention "$report" "$dep" "$version" "the release v${new} cannot be made" "$report/release-reasons.md"; continue ;;
        *) echo "error" > "$report/result"; tail -n 5 "$report/bump.log"; continue ;;
      esac
      git -C "$clone" rev-parse HEAD > "$report/head"
      # next must take the release: it is in next's history, so the next release from next carries it
      rc=0
      bash "$ROOT/ci/deps-release.sh" merge-next "$clone" HEAD refs/remotes/origin/next "${dep}:${version}" "$dir/bump" > "$report/merge" 2> "$report/merge.err" || rc=$?
      case "$rc" in
        0) ;;
        2) printf -- '- %s\n' "$(cat "$report/merge.err")" > "$report/release-reasons.md"
           release_attention "$report" "$dep" "$version" "next cannot take the release v${new}" "$report/release-reasons.md"; continue ;;
        *) echo "error" > "$report/result"; cat "$report/merge.err" >> "$report/bump.log"; continue ;;
      esac
    fi
    git -C "$clone" branch -f "deps/${dep}" HEAD
    git -C "$clone" log --format='%H %an <%ae> %s' -2 HEAD
    add_matrix "$dir" "$report" "$dep" "$issue"
  done
  [ -z "$base" ] || git -C "$clone" checkout -q -f --detach "$base"
}

# deps_path_of DEP — next for a package group whose bumps never ship (DEPS_PKG's PATH), else release
deps_path_of() {
  local row
  row="$(deps_pkg_row "$1" || true)"
  if [ -n "$row" ] && [ "$(deps_field "$row" 6)" = next ]; then echo next; else echo release; fi
}

# knows CLONE COMMIT DEP — COMMIT's own bump knows DEP: a package group's row in its
# ci/deps/packages.sh (none on a tag from before the groups), a chart's or Knative's in its
# ci/deps/lib.sh (DEPS_BOT)
knows() {
  local file=ci/deps/lib.sh text
  [ -z "$(deps_pkg_row "$3" || true)" ] || file=ci/deps/packages.sh
  text="$(git -C "$1" show "${2}:${file}" 2>/dev/null || true)"
  grep -qE "^[[:space:]]*\"${3}\|" <<<"$text"
}

# bump_on CLONE COMMIT DEP VERSION REPORT RUN_URL — COMMIT's own ci/deps-bump.sh moves the dependency
# and the bump is committed as the bot, with its trailer (RunR-Deps-Bot: DEP VERSION). Exit 0: a
# commit on COMMIT; otherwise REPORT/result says why (2 attention, 3 current, 1 error)
bump_on() {
  local clone="$1" commit="$2" dep="$3" version="$4" report="$5" run_url="$6" rc=0
  git -C "$clone" checkout -q -f --detach "$commit"
  echo "── ${dep} ${version} (#$(cat "$report/issue")) on ${commit:0:12}"
  bash "$clone/ci/deps-bump.sh" "$dep" "$version" --report="$report" ${run_url:+--run-url="$run_url"} \
    ${report:+$( [ -s "$report/updates.json" ] && echo "--updates=$report/updates.json")} < /dev/null > "$report/bump.log" 2>&1 || rc=$?
  cat "$report/bump.log"
  case "$rc" in
    0) ;;
    2|3) return "$rc" ;;
    *) echo "error" > "$report/result"; return 1 ;;
  esac
  printf '\n%s: %s %s\n' "$DEPS_TRAILER" "$dep" "$version" >> "$report/commit-msg.txt"
  GIT_AUTHOR_NAME="${DEPS_BOT_NAME:?}" GIT_AUTHOR_EMAIL="${DEPS_BOT_EMAIL:?}" \
  GIT_COMMITTER_NAME="$DEPS_BOT_NAME" GIT_COMMITTER_EMAIL="$DEPS_BOT_EMAIL" \
    git -C "$clone" -c commit.gpgSign=false commit -q -a -F "$report/commit-msg.txt"
  git -C "$clone" rev-parse HEAD > "$report/bump"
}

# add_matrix DIR REPORT DEP ISSUE — the bump's jobs: its Kind suites, its checks, the console's,
# PHPUnit when its suites name it (every chart's), runtime/test always
add_matrix() {
  local dir="$1" report="$2" dep="$3" issue="$4" ref="deps/$3" f
  for f in $SUITE_JOBS; do
    jq -c --arg dep "$dep" --argjson issue "$issue" --arg ref "$ref" --arg job "${f#*:}" \
      '[.[] | select((.job // "kind") == $job) | . + {dep: $dep, issue: $issue, ref: $ref}]' "$report/suites.json" > "$dir/suites.tmp"
    jq -s -c 'add' "$dir/matrix-${f%%:*}.json" "$dir/suites.tmp" > "$dir/matrix.tmp" && mv "$dir/matrix.tmp" "$dir/matrix-${f%%:*}.json"
  done
  rm -f -- "$dir/suites.tmp"
  for f in shell unit; do
    [ "$f" = shell ] || [ "$(cat "$report/unit" 2>/dev/null || echo 1)" = 1 ] || continue
    jq -c --arg dep "$dep" --argjson issue "$issue" --arg ref "$ref" '. + [{dep: $dep, issue: $issue, ref: $ref}]' \
      "$dir/matrix-${f}.json" > "$dir/matrix.tmp" && mv "$dir/matrix.tmp" "$dir/matrix-${f}.json"
  done
}

# release_attention REPORT DEP VERSION WHAT REASONS — a bump whose release needs a person
release_attention() {
  {
    echo "**${2} ${3} needs a person: ${4}.** No suite ran, nothing was pushed."
    echo ""
    cat "$5"
    if [ -s "$1/summary.md" ]; then
      echo ""
      echo "The bump itself:"
      echo ""
      cat "$1/summary.md"
    fi
  } > "$1/summary.tmp"
  mv "$1/summary.tmp" "$1/summary.md"
  echo attention > "$1/result"
}

# suite_green DIR NAME SUITE — the test job of SUITE (of the bump or batch NAME) passed: its job
# succeeded and its results say so too (a suite whose script ended 0 with failures in its results
# file failed, the filex bot's #1807)
suite_green() {
  local res failed
  res="$1/results/$(result_name "$2" "$3")"
  failed="$(cat "$res/failed" 2>/dev/null || echo 0)"
  [ "$(cat "$res/status" 2>/dev/null || true)" = success ] && [ ! -s "$res/failures.md" ] \
    && [[ "$failed" =~ ^[0-9]+$ ]] && [ "$failed" = 0 ]
}

cmd_outcome() {
  local dir="$1" report dep result suite ok
  mkdir -p "$dir/outcome"
  : > "$dir/green"
  : > "$dir/green.tmp"
  for report in "$dir"/bump/*/; do
    [ -d "$report" ] || continue
    report="${report%/}"
    dep="${report##*/}"
    result="$(cat "$report/result" 2>/dev/null || echo error)"
    case "$result" in
      current|attention|error|held) echo "$result" > "$dir/outcome/$dep"; continue ;;
    esac
    ok=1
    : > "$report/failed"
    for suite in $(report_suites "$report"); do
      suite_green "$dir" "$dep" "$suite" || { ok=0; echo "$suite" >> "$report/failed"; }
    done
    if [ "$ok" = 1 ]; then
      echo green > "$dir/outcome/$dep"
      # an urgent one (a security advisory) first, then the oldest issue
      echo "$([ -e "$report/urgent" ] && echo 0 || echo 1) $(cat "$report/issue") ${dep}" >> "$dir/green.tmp"
    else
      echo red > "$dir/outcome/$dep"
    fi
  done
  sort -k1,1n -k2,2n "$dir/green.tmp" | awk '{ print $3 }' > "$dir/green"
  rm -f -- "$dir/green.tmp"
}

# report_suites REPORT — the suites of a bump's (or a batch's) jobs, as their results are named:
# unit (PHPUnit, when its suites name it), shell (runtime/test) and every suite of suites.json
# (Kind suites and checks)
report_suites() {
  local s=""
  [ "$(cat "$1/unit" 2>/dev/null || echo 1)" != 1 ] || s="unit "
  echo "${s}shell $(jq -r '.[].suite' "$1/suites.json" | tr '\n' ' ')"
}

# ── batch: the green bumps of a run together ─────────────────────────────────
# batch_id KIND — the name of a batch's test jobs, branch and results: batch (the release),
# batch-next (next only)
batch_id() { if [ "$1" = release ]; then echo batch; else echo batch-next; fi; }

# alone_head DIR DEP — the tree DEP was tested on alone: its release commit, or its bump on next
alone_head() { cat "$1/bump/$2/head"; }

# chain_commit BATCH N — the commit of the N-th bump of a batch
chain_commit() { sed -n "${2}p" "$1/chain" | cut -d' ' -f2; }

# batch_deps DIR DEP… — the bumps as ci/deps-release.sh takes them: dep:version,dep:version…
batch_deps() {
  local dir="$1" d list=""
  shift
  for d in "$@"; do list="${list:+${list},}${d}:$(cat "$dir/bump/$d/version")"; done
  echo "$list"
}

# the union of the bumps' suites: one per command and environment (job, command, k8s, kvm, env),
# with the bumps it is a suite of (members); a suite name the union has twice (two upgrade suites
# with another --expect-release) gets -2, -3, … so that every result has a name of its own
# shellcheck disable=SC2016 # jq's variables, not the shell's
UNION_JQ='add // []
  | reduce .[] as $s ([];
      ($s | {job: (.job // "kind"), command, k8s, kvm, env} | tojson) as $k
      | (first(range(length) as $j | select(.[$j].key == $k) | $j) // null) as $i
      | if $i == null then . + [$s + {key: $k, members: [$s.member]}]
        else .[$i].members += [$s.member] end)
  | . as $all
  | [range(length) as $i | $all[$i]
      | ([$all[:$i][] | select(.suite == $all[$i].suite)] | length) as $n
      | .suite = (if $n == 0 then .suite else "\(.suite)-\($n + 1)" end)
      | del(.key, .member)]'

# batch_report DIR OUT COMBINED DEP… — OUT as the report of the bumps together, for prepare and the
# combined tests: the union of their suites, unit when one of them needs PHPUnit, the run, and
# COMBINED (batch: tested together on the release; prefix: on the release and later bumps)
batch_report() {
  local dir="$1" out="$2" combined="$3" dep unit=0
  shift 3
  rm -rf -- "$out"
  mkdir -p "$out"
  for dep in "$@"; do
    jq -c --arg m "$dep" '[.[] | . + {member: $m}]' "$dir/bump/$dep/suites.json"
    [ "$(cat "$dir/bump/$dep/unit" 2>/dev/null || echo 1)" != 1 ] || unit=1
  done > "$out/all.json"
  jq -s -c "$UNION_JQ" "$out/all.json" > "$out/suites.json"
  rm -f -- "$out/all.json"
  if [ "$unit" = 1 ]; then echo 1 > "$out/unit"; else : > "$out/unit"; fi
  cat "$dir/bump/$1/run-url" > "$out/run-url" 2>/dev/null || : > "$out/run-url"
  [ -z "$combined" ] || echo "$combined" > "$out/combined"
}

# batch_break BATCH DEP REASON HOW — DEP stops the batch before it (HOW: remake, its bump made again
# on the bumps before it is not mechanical; release, their release cannot be made with it)
batch_break() {
  echo "$2" > "$1/breaker"
  printf '%s\n' "$3" > "$1/breaker.md"
  echo "$4" > "$1/breaker.how"
}

# batch_drop_last BATCH REASON — the last bump of the batch breaks it (their release cannot be made
# with it): the breaker before and the ones that waited wait after it
batch_drop_last() {
  local b="$1" last
  last="$(tail -n 1 "$b/members")"
  sed -i '$d' "$b/members" "$b/chain"
  { cat "$b/breaker" 2>/dev/null || true; cat "$b/waiting"; } > "$b/waiting.tmp"
  mv "$b/waiting.tmp" "$b/waiting"
  batch_break "$b" "$last" "$2" release
}

# remake_reason REMAKE RC — why a bump made again on the bumps before it is not mechanical
remake_reason() {
  case "$2" in
    2) cat "$1/summary.md" 2>/dev/null || echo "its bump needs a person" ;;
    3) echo "Its bump found nothing to do there: a bump before it moved it already." ;;
    *) echo "Its bump failed:"; echo ""; echo '```'; tail -n 15 "$1/bump.log" 2>/dev/null || true; echo '```' ;;
  esac
}

# batch_chain DIR CLONE KIND — the bumps of DIR/batch/KIND/list one after the other: the first is
# its own commit, made and tested alone; every other one is made again on the commit before it by
# that tree's own ci/deps-bump.sh (as bump_on makes it, with --updates for a package group), until
# one is not mechanical. A release's batch then gets its release commit, which next must take; one
# that cannot be made drops the batch's last bump until it can (a batch of one needs none).
batch_chain() {
  local dir="$1" clone="$2" kind="$3" b="$1/batch/$3" deps=() members=() dep head base remake rc i release next reason base_tag new
  base_tag="$(env_of "$dir/base.env" BASE_TAG)"
  new="$(env_of "$dir/base.env" NEW_VERSION)"
  mapfile -t deps < "$b/list"
  : > "$b/members"
  : > "$b/chain"
  : > "$b/waiting"
  rm -f -- "$b/breaker" "$b/breaker.md" "$b/breaker.how" "$b/tested" "$b/head" "$b/base"
  rm -rf -- "$b/report"
  [ "${#deps[@]}" -gt 0 ] || return 0
  dep="${deps[0]}"
  if [ "$kind" = release ]; then
    head="$(cat "$dir/bump/$dep/bump")"
    base="$(cat "$dir/bump/$dep/base")"
  else
    head="$(alone_head "$dir" "$dep")"
    base="$(cat "$dir/bump/$dep/next-base")"
  fi
  echo "$base" > "$b/base"
  echo "$dep" >> "$b/members"
  echo "$dep $head" >> "$b/chain"
  for ((i = 1; i < ${#deps[@]}; i++)); do
    dep="${deps[$i]}"
    if [ -e "$b/breaker" ]; then echo "$dep" >> "$b/waiting"; continue; fi
    remake="$b/remake/$dep"
    rm -rf -- "$remake"
    mkdir -p "$remake"
    cp "$dir/bump/$dep/issue" "$remake/issue"
    [ ! -s "$dir/bump/$dep/updates.json" ] || cp "$dir/bump/$dep/updates.json" "$remake/updates.json"
    echo "── the ${kind} batch: ${dep}, made again on $(paste -sd ' ' "$b/members")"
    rc=0
    bump_on "$clone" "$head" "$dep" "$(cat "$dir/bump/$dep/version")" "$remake" "$(cat "$dir/bump/$dep/run-url" 2>/dev/null || true)" || rc=$?
    if [ "$rc" != 0 ]; then
      batch_break "$b" "$dep" "$(remake_reason "$remake" "$rc")" remake
    elif [ "$(cat "$remake/path" 2>/dev/null || echo release)" != "$kind" ]; then
      batch_break "$b" "$dep" "Made on the bumps before it, it $([ "$kind" = release ] && echo "ships nothing" || echo "changes what a release ships"), which it did not alone." remake
    else
      head="$(cat "$remake/bump")"
      echo "$dep" >> "$b/members"
      echo "$dep $head" >> "$b/chain"
    fi
  done
  if [ "$(wc -l < "$b/members")" -lt 2 ]; then
    alone_head "$dir" "${deps[0]}" > "$b/head"
    return 0
  fi
  if [ "$kind" = next ]; then
    mapfile -t members < "$b/members"
    batch_report "$dir" "$b/report" "" "${members[@]}"
    echo "$head" > "$b/head"
    touch "$b/tested"
    return 0
  fi
  # the release takes the next its landing makes, when the run lands bumps of next
  next="$(cat "$dir/batch/next/head" 2>/dev/null || git -C "$clone" rev-parse refs/remotes/origin/next)"
  while [ "$(wc -l < "$b/members")" -ge 2 ]; do
    mapfile -t members < "$b/members"
    batch_report "$dir" "$b/report" batch "${members[@]}"
    git -C "$clone" checkout -q -f --detach "$(chain_commit "$b" "${#members[@]}")"
    rc=0
    bash "$ROOT/ci/deps-release.sh" prepare "$clone" "$base_tag" "$new" "$b/report" > "$b/prepare.log" 2>&1 || rc=$?
    if [ "$rc" = 0 ]; then
      release="$(git -C "$clone" rev-parse HEAD)"
      rc=0
      bash "$ROOT/ci/deps-release.sh" merge-next "$clone" "$release" "$next" "$(batch_deps "$dir" "${members[@]}")" "$dir/bump" > "$b/merge" 2> "$b/merge.err" || rc=$?
      if [ "$rc" = 0 ]; then
        echo "$release" > "$b/head"
        touch "$b/tested"
        return 0
      fi
      reason="Next cannot take the release of these bumps together: $(cat "$b/merge.err")"
    else
      reason="The release commit of these bumps together cannot be made: $(sed 's/^- //' "$b/report/release-reasons.md" 2>/dev/null || tail -n 3 "$b/prepare.log" | tr '\n' ' ')"
    fi
    batch_drop_last "$b" "$reason"
  done
  rm -rf -- "$b/report"
  alone_head "$dir" "${deps[0]}" > "$b/head"
}

# batch_matrix DIR KIND — the combined tests of a batch: the union's Kind suites, checks and the
# console's, PHPUnit when one of its bumps needs it, runtime/test
batch_matrix() {
  local dir="$1" b="$1/batch/$2" id ref f
  id="$(batch_id "$2")"
  ref="deps/${id}"
  for f in $SUITE_JOBS; do
    jq -c --arg dep "$id" --arg ref "$ref" --arg job "${f#*:}" \
      '[.[] | select((.job // "kind") == $job) | del(.members) | . + {dep: $dep, ref: $ref}]' "$b/report/suites.json" > "$dir/batch/add.json"
    jq -s -c 'add' "$dir/batch/matrix-${f%%:*}.json" "$dir/batch/add.json" > "$dir/batch/matrix.tmp"
    mv "$dir/batch/matrix.tmp" "$dir/batch/matrix-${f%%:*}.json"
  done
  rm -f -- "$dir/batch/add.json"
  for f in shell unit; do
    [ "$f" = shell ] || [ "$(cat "$b/report/unit")" = 1 ] || continue
    jq -c --arg dep "$id" --arg ref "$ref" '. + [{dep: $dep, ref: $ref}]' "$dir/batch/matrix-${f}.json" > "$dir/batch/matrix.tmp"
    mv "$dir/batch/matrix.tmp" "$dir/batch/matrix-${f}.json"
  done
}

cmd_batch() {
  local dir="$1" clone="$2" dep kind f base
  cmd_outcome "$dir"
  rm -rf -- "$dir/batch"
  mkdir -p "$dir/batch/release" "$dir/batch/next"
  for f in $SUITE_JOBS unit shell; do echo '[]' > "$dir/batch/matrix-${f%%:*}.json"; done
  : > "$dir/batch/release/list"
  : > "$dir/batch/next/list"
  while read -r dep; do
    [ -n "$dep" ] || continue
    kind=release
    [ "$(cat "$dir/bump/$dep/path" 2>/dev/null || echo release)" != next ] || kind=next
    echo "$dep" >> "$dir/batch/$kind/list"
  done < "$dir/green"
  # next first: the release's merge into next is tried on the next its landing makes
  batch_chain "$dir" "$clone" next
  batch_chain "$dir" "$clone" release
  for kind in next release; do
    [ -e "$dir/batch/$kind/tested" ] || continue
    git -C "$clone" branch -f "deps/$(batch_id "$kind")" "$(cat "$dir/batch/$kind/head")"
    batch_matrix "$dir" "$kind"
    echo "── deps/$(batch_id "$kind"): $(paste -sd ' ' "$dir/batch/$kind/members") together; $(report_suites "$dir/batch/$kind/report")"
  done
  base="$(env_of "$dir/base.env" BASE_COMMIT)"
  [ -z "$base" ] || git -C "$clone" checkout -q -f --detach "$base"
}

# ── pick: what the run releases and lands ────────────────────────────────────
# where_of DIR KIND — where a batch's bumps were tested alone: the base tag, or next
where_of() { if [ "$2" = release ]; then env_of "$1/base.env" BASE_TAG; else echo next; fi; }

# what_of DIR KIND — what a batch makes: the release, or the landing on next
what_of() { if [ "$2" = release ]; then echo "release $(env_of "$1/base.env" NEW_TAG)"; else echo "landing on next"; fi; }

# member_suites DIR BATCH DEP — DEP's suites of the combined tree: runtime/test, PHPUnit when it
# needs it, and the union's suites it brought
member_suites() {
  echo shell
  [ "$(cat "$1/bump/$3/unit" 2>/dev/null || echo 1)" != 1 ] || echo unit
  jq -r --arg d "$3" '.[] | select(any(.members[]; . == $d)) | .suite' "$2/report/suites.json"
}

# failure_details DIR NAME SUITE — what failed in a suite of a bump or a batch, folded
failure_details() {
  local res
  res="$1/results/$(result_name "$2" "$3")"
  echo ""
  echo "<details><summary>${3}: what failed</summary>"
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
}

# wait_role DIR KIND DEP STOP WHY — DEP passed alone and waits for the next run: the batch stops
# before STOP, which WHY
wait_role() {
  local dir="$1" kind="$2" dep="$3" stop
  echo wait > "$dir/role/$dep"
  stop="before $(bump_names "$dir" "$4"), which ${5}"
  [ "$4" != "$dep" ] || stop="before it: it ${5}"
  {
    echo "**$(bump_name "$dir" "$dep") passed alone on $(where_of "$dir" "$kind"); it waits for the next run.** This run's $(what_of "$dir" "$kind") stops ${stop}. Nothing was pushed for it, and the issue keeps no label: the next run makes and tests it again."
  } > "$dir/role/$dep.md"
}

# combined_breaker DIR KIND K — the comment of the batch's bump K (from 0), which failed on the
# combined tree with the bumps before it
combined_breaker() {
  local dir="$1" kind="$2" k="$3" b="$1/batch/$2" id members=() dep mine suite i
  id="$(batch_id "$kind")"
  mapfile -t members < "$b/members"
  dep="${members[$k]}"
  # the failed suites that are its own or those of the bumps before it
  mine="$(for ((i = 0; i <= k; i++)); do member_suites "$dir" "$b" "${members[$i]}"; done | grep -xF -f "$b/failed" | sort -u | paste -sd ' ' - || true)"
  echo "**Needs attention: $(bump_name "$dir" "$dep") passed alone on $(where_of "$dir" "$kind"), but failed together with the bumps before it** ($(bump_names "$dir" "${members[@]:0:$k}")). This run's $(what_of "$dir" "$kind") leaves it out; nothing of it was pushed."
  echo ""
  echo "The combined tree of this run's green bumps (\`deps/${id}\`, $(cut -c1-12 "$b/head"): $(bump_names "$dir" "${members[@]}")) failed $(paste -sd ' ' "$b/failed" | sed 's/ /, /g'); with the bumps before it, its suites include ${mine// /, }. The $(what_of "$dir" "$kind") takes the bumps before it; the ones after it wait for the next run."
  echo ""
  echo "The suites of the combined tree:"
  echo ""
  for suite in $(report_suites "$b/report"); do
    echo "- $(suite_line "$dir" "$id" "$suite" "$b/report")"
  done
  while read -r suite; do
    [ -n "$suite" ] || continue
    failure_details "$dir" "$id" "$suite"
  done < "$b/failed"
}

# remake_breaker DIR KIND DEP — the comment of the bump that stopped the batch when it was made
remake_breaker() {
  local dir="$1" kind="$2" dep="$3" b="$1/batch/$2" members=()
  mapfile -t members < "$b/members"
  echo "**Needs attention: $(bump_name "$dir" "$dep") passed alone on $(where_of "$dir" "$kind"), but not together with the other green bumps of this run.** This run's $(what_of "$dir" "$kind") leaves it out; nothing of it was pushed."
  echo ""
  if [ "$(cat "$b/breaker.how" 2>/dev/null || echo remake)" = release ]; then
    echo "With the bumps before it ($(bump_names "$dir" "${members[@]}")):"
  else
    echo "Made again by $([ "$kind" = release ] && echo "the last release's" || echo "next's") own \`ci/deps-bump.sh\` on the bumps before it ($(bump_names "$dir" "${members[@]}")), it is not mechanical:"
  fi
  echo ""
  cat "$b/breaker.md"
}

# pick_kind DIR REPO KIND — the bumps a batch releases (or lands) and their tree
pick_kind() {
  local dir="$1" repo="$2" kind="$3" b="$1/batch/$3" id members=() n final k i found head how rc dep stop why base_tag new suite
  id="$(batch_id "$kind")"
  base_tag="$(env_of "$dir/base.env" BASE_TAG)"
  new="$(env_of "$dir/base.env" NEW_VERSION)"
  rm -f -- "$b/final" "$b/how" "$b/failed" "$b/acc"
  [ -s "$b/members" ] || return 0
  mapfile -t members < "$b/members"
  n="${#members[@]}"
  final="$n"
  : > "$b/failed"
  if [ -e "$b/tested" ]; then
    for suite in $(report_suites "$b/report"); do
      suite_green "$dir" "$id" "$suite" || echo "$suite" >> "$b/failed"
    done
  fi
  if [ -s "$b/failed" ]; then
    # the breaker: the first bump from the second on whose suites, with those of the bumps before
    # it, include one that failed on the combined tree (the first bump is the tree it passed alone)
    found=0
    : > "$b/acc"
    for ((k = 0; k < n; k++)); do
      member_suites "$dir" "$b" "${members[$k]}" >> "$b/acc"
      if [ "$k" -ge 1 ] && grep -qxF -f "$b/failed" "$b/acc"; then found=1; break; fi
    done
    [ "$found" = 1 ] || k=1
    final="$k"
    combined_breaker "$dir" "$kind" "$k" > "$dir/role/${members[$k]}.md"
    echo breaker > "$dir/role/${members[$k]}"
    for ((i = k + 1; i < n; i++)); do
      wait_role "$dir" "$kind" "${members[$i]}" "${members[$k]}" "failed together with the bumps before it"
    done
  fi
  # the bump that stopped the batch when it was made, and the ones after it
  stop="${members[$final]:-}"
  why="failed together with the bumps before it"
  if [ -s "$b/breaker" ]; then
    dep="$(cat "$b/breaker")"
    if [ "$final" -lt "$n" ]; then
      wait_role "$dir" "$kind" "$dep" "$stop" "$why"
    else
      remake_breaker "$dir" "$kind" "$dep" > "$dir/role/$dep.md"
      echo breaker > "$dir/role/$dep"
      stop="$dep"
      if [ "$(cat "$b/breaker.how" 2>/dev/null || echo remake)" = release ]; then
        why="cannot be released together with the bumps before it"
      else
        why="the bot could not make again on the bumps before it"
      fi
    fi
  fi
  while read -r dep; do
    [ -n "$dep" ] || continue
    wait_role "$dir" "$kind" "$dep" "$stop" "$why"
  done < "$b/waiting"
  # the tree: the batch's own when all of it goes, the first bump's alone, or a release commit on
  # the batch's own commit of the last bump that goes
  how=single
  [ ! -e "$b/tested" ] || how="batch"
  if [ "$final" = "$n" ]; then
    head="$(cat "$b/head")"
  else
    how=prefix
    head=""
    if [ "$kind" = release ]; then
      while [ "$final" -ge 2 ]; do
        batch_report "$dir" "$b/prefix" prefix "${members[@]:0:$final}"
        git -C "$repo" checkout -q -f --detach "$(chain_commit "$b" "$final")"
        rc=0
        bash "$ROOT/ci/deps-release.sh" prepare "$repo" "$base_tag" "$new" "$b/prefix" > "$b/prefix.log" 2>&1 || rc=$?
        if [ "$rc" = 0 ]; then
          head="$(git -C "$repo" rev-parse HEAD)"
          break
        fi
        final=$((final - 1))
        wait_role "$dir" "$kind" "${members[$final]}" "${members[$final]}" "cannot be released together with the bumps before it ($(sed 's/^- //' "$b/prefix/release-reasons.md" 2>/dev/null | head -n 1 || true))"
      done
    elif [ "$final" -ge 2 ]; then
      head="$(chain_commit "$b" "$final")"
    fi
    [ "$final" -ge 2 ] || head="$(alone_head "$dir" "${members[0]}")"
  fi
  for ((i = 0; i < final; i++)); do
    if [ "$kind" = release ]; then echo release; else echo land; fi > "$dir/role/${members[$i]}"
    echo "${members[$i]}" >> "$b/final"
  done
  echo "$how" > "$b/how"
  if [ "$kind" = release ]; then
    {
      echo "REPO=${repo}"
      echo "PATH_KIND=release"
      echo "RELEASE=${head}"
      echo "DEPS=$(batch_deps "$dir" "${members[@]:0:$final}")"
      echo "UPDATES=${dir}/bump"
      grep -E '^(BASE_TAG|BASE_COMMIT|PUBLIC_BASE|NEW_VERSION|NEW_TAG)=' "$dir/base.env"
    } > "$dir/release/release.env"
  else
    {
      echo "REPO=${repo}"
      echo "PATH_KIND=next"
      echo "LAND=${head}"
      echo "NEXT_BASE=$(cat "$b/base")"
      echo "DEPS=$(batch_deps "$dir" "${members[@]:0:$final}")"
    } > "$dir/land/release.env"
  fi
  echo "── the ${kind} batch: $(paste -sd ' ' "$b/final") (${how}, ${head:0:12})"
}

cmd_pick() {
  local dir="$1" repo="$2" dep
  mkdir -p "$dir/release" "$dir/land"
  rm -rf -- "$dir/role"
  mkdir -p "$dir/role"
  rm -f -- "$dir/release/release.env" "$dir/land/release.env"
  [ -f "$dir/green" ] || cmd_outcome "$dir"
  if [ -d "$dir/batch" ]; then
    pick_kind "$dir" "$repo" next >&2
    pick_kind "$dir" "$repo" release >&2
  else
    # the batch was not made (its job did not finish): every green bump waits for the next run
    while read -r dep; do
      [ -n "$dep" ] || continue
      echo nobatch > "$dir/role/$dep"
    done < "$dir/green"
  fi
  echo "release=$([ -f "$dir/release/release.env" ] && echo true || echo false)"
  echo "land=$([ -f "$dir/land/release.env" ] && echo true || echo false)"
}

cmd_resume() {
  local dir="$1" repo="$2" public="$3" tag="$4" issues="$5" issue report label release base_tag
  [[ "$issues" =~ ^[0-9]+(,[0-9]+)*$ ]] || { echo "resume: the issue numbers (comma-separated)" >&2; exit 1; }
  mkdir -p "$dir/release" "$dir/outcome" "$dir/role"
  if ! bash "$ROOT/ci/deps-release.sh" resume-env "$repo" "$public" "$tag" > "$dir/release/release.env" 2> "$dir/release/resume.err"; then
    echo "resume: $(cat "$dir/release/resume.err")" > "$dir/release/failed"
    rm -f -- "$dir/release/release.env"
  fi
  release="$(env_of "$dir/release/release.env" RELEASE)"
  base_tag="$(env_of "$dir/release/release.env" BASE_TAG)"
  # the tag says what it releases ("A dependency release of 0.6.8: Region Traefik chart 42.3.1.")
  label="$(git -C "$repo" cat-file tag "refs/tags/${tag}" 2>/dev/null | sed '1,/^$/d' | tr '\n' ' ' | sed -nE 's/.*A dependency release of [^:]*: (.*)\. .*/\1/p' || true)"
  for issue in ${issues//,/ }; do
    report="$dir/bump/resume-${issue}"
    mkdir -p "$report"
    echo "$issue" > "$report/issue"
    echo "$tag" > "$report/version"
    touch "$report/resumed" "$report/summary.md"
    echo green > "$dir/outcome/resume-${issue}"
    echo release > "$dir/role/resume-${issue}"
    echo "$base_tag" > "$report/base-tag"
    echo "$release" > "$report/head"
    echo "${label:-the release ${tag}}" > "$report/label"
  done
}

# ── actions: what the issues get ─────────────────────────────────────────────
# suite_line DIR NAME SUITE [REPORT] — "the command: status" of one test job (REPORT's suites.json
# names the command of a job that left no result)
suite_line() {
  local res command status
  res="$1/results/$(result_name "$2" "$3")"
  command="$(cat "$res/command" 2>/dev/null || true)"
  [ -n "$command" ] || [ -z "${4:-}" ] || command="$(jq -r --arg s "$3" '[.[] | select(.suite == $s) | .command][0] // empty' "$4/suites.json" 2>/dev/null || true)"
  [ -n "$command" ] || command="$3"
  status="$(cat "$res/status" 2>/dev/null || echo "no result (the job was cancelled, timed out or did not start)")"
  if [ "$status" = success ] && { [ -s "$res/failures.md" ] || [ "$(cat "$res/failed" 2>/dev/null || echo 0)" != 0 ]; }; then
    status="failure (the job ended 0, its results name $(cat "$res/failed" 2>/dev/null || echo some) failed test(s))"
  fi
  case "$3" in
    unit) command="PHPUnit, Pint, Larastan, API reference" ;;
    shell) command="runtime/test and the reqs mirror check" ;;
    ui) command="vue: npm run lint, npm run test:coverage (Vitest), npm run build" ;;
    ui-stack) command="vue: npm run test:e2e (Playwright) against the dev stack" ;;
    *) case "$command" in ci/*) ;; *) command="runtime/ci.sh ${command}" ;; esac ;;
  esac
  echo "\`${command}\`: ${status}"
}

# suites_list DIR NAME REPORT — the suites of a bump (or a batch) as a list (none for a resumed
# release: they ran in the run that made it)
suites_list() {
  local suite
  [ ! -e "$3/resumed" ] || { echo "- the suites of the run that made ${new_tag:-the release}"; return 0; }
  for suite in $(report_suites "$3"); do
    echo "- $(suite_line "$1" "$2" "$suite" "$3")"
  done
}

# release_state DIR SUB — what became of the release (SUB release: released | moved | failed |
# stopped | none) or the landing (SUB land: landed | moved | failed | stopped | none)
release_state() {
  local st="$1/$2" s
  if [ -s "$st/moved" ]; then echo moved; return; fi
  if [ -s "$st/failed" ]; then echo failed; return; fi
  [ -f "$st/release.env" ] || { echo none; return; }
  if [ "$2" = land ]; then
    if grep -q '^land ' "$st/steps" 2>/dev/null; then echo landed; else echo stopped; fi
    return
  fi
  for s in $RELEASE_STEPS; do
    grep -q "^${s} " "$st/steps" 2>/dev/null || { echo stopped; return; }
  done
  echo released
}

# step_field DIR SUB STEP N — field N of the release's (or landing's) step line
step_field() { awk -v s="$3" -v f="$4" '$1 == s { v = $f } END { print v }' "$1/$2/steps" 2>/dev/null; }

# role_deps DIR ROLE — the green bumps of a role (release, land, …), in the batch's order when it
# has one (pick: DIR/batch/<path>/final)
role_deps() {
  local f final="$1/batch/release/final"
  [ "$2" != land ] || final="$1/batch/next/final"
  if [ -s "$final" ]; then
    cat "$final"
    return 0
  fi
  for f in "$1"/role/*; do
    [ -f "$f" ] && [ "${f%.md}" = "$f" ] && [ "$(cat "$f")" = "$2" ] && echo "${f##*/}"
  done
  return 0
}

# role_issues DIR ROLE — their issue numbers, comma-separated
role_issues() {
  local d list=""
  for d in $(role_deps "$1" "$2"); do list="${list:+${list},}$(cat "$1/bump/$d/issue")"; done
  echo "$list"
}

# role_others DIR ROLE DEP — the other bumps of DEP's release (or landing), as the comments name them
role_others() {
  local d others=()
  [ ! -e "$1/bump/$3/resumed" ] || return 0
  for d in $(role_deps "$1" "$2"); do [ "$d" = "$3" ] || others+=("$d"); done
  [ "${#others[@]}" = 0 ] || bump_names "$1" "${others[@]}"
}

# rerun_line ROLE — how to run the workflow for the bumps of a release (or landing) again
rerun_line() {
  local issues
  issues="$(role_issues "$dir" "$1")"
  if [[ "$issues" == *,* ]]; then echo "the issue numbers \`${issues}\`"; else echo "this issue's number"; fi
}

# comment_release DIR DEP NAME — the comment of a bump of the run's release; verdict: what the issue
# gets (passed, attention, retest, comment, none)
comment_release() {
  local dir="$1" dep="$2" name="$3" report="$1/bump/$2" b="$1/batch/release" base_tag others how n public merge images release_url
  base_tag="$(env_of "$dir/release/release.env" BASE_TAG)"
  [ -n "$base_tag" ] || base_tag="$(cat "$report/base-tag" 2>/dev/null || true)"
  others="$(role_others "$dir" release "$dep")"
  n="$(role_deps "$dir" release | wc -l | tr -d ' ')"
  how="$(cat "$b/how" 2>/dev/null || echo single)"
  if [ "$dry" = --dry-run ]; then
    verdict=none
    echo "**Dry run: ${name} passed on ${base_tag} and would be RunR ${new_tag#v}**${others:+, together with ${others}} (${rstate}; nothing was pushed or created, the issue is left as it is)."
    echo ""
    if [ -s "$dir/release/steps" ]; then sed 's/^/- /' "$dir/release/steps"; fi
    [ ! -s "$dir/release/failed" ] || echo "- failed: $(cat "$dir/release/failed")"
    [ ! -s "$dir/release/moved" ] || echo "- moved: $(cat "$dir/release/moved")"
    echo ""
    echo "Suites ([run](${run_url})):"
    echo ""
    suites_list "$dir" "$dep" "$report"
    combined_suites release
    return 0
  fi
  case "$rstate" in
    released)
      verdict=passed
      public="$(step_field "$dir" release public 3)"
      merge="$(step_field "$dir" release origin 4)"
      images="$(step_field "$dir" release images 3)"
      release_url="$(step_field "$dir" release github 3)"
      echo "**Released: ${name} is RunR ${new_tag#v}** ([release](${release_url})), a dependency release of ${base_tag}${others:+, together with ${others}}."
      echo ""
      case "$how" in
        batch) echo "- Made and tested on ${base_tag}: the bump alone, then the release commit ${release:0:12} with all ${n} bumps together, nothing else of next." ;;
        prefix)
          if [ "$n" -le 1 ]; then
            echo "- Made and tested on ${base_tag}: the bump and the release commit ${release:0:12}, alone, and the bump on the combined tree of this run's green bumps, where its suites passed; nothing else of next."
          else
            echo "- Made and tested on ${base_tag}: the bump alone, and on the combined tree of this run's green bumps, where its suites passed; the release commit ${release:0:12} has the first ${n} of them, made the same way, nothing else of next."
          fi ;;
        *) echo "- Made and tested on ${base_tag}: the bump and the release commit ${release:0:12}, nothing else of next." ;;
      esac
      echo "- Tagged ${new_tag} (signed) on the origin's main and on this repository's main (${public:0:12}); next takes it as ${merge:0:12}, so the next release from next carries it."
      echo "- Images built by release-images.yml ([run](${images})) and checked: every tag pulls without credentials and names the digests the run pushed."
      echo "- The chart ${new_tag#v} is on helm.brf.sh and is the release's."
      echo ""
      cat "$report/summary.md"
      echo ""
      echo "Suites ([run](${run_url})):"
      echo ""
      suites_list "$dir" "$dep" "$report"
      combined_suites release
      echo ""
      echo "Fixed in ${new_tag}." ;;
    moved)
      if [ "$next_attempt" -le "$MAX_ATTEMPTS" ]; then
        verdict=retest
        echo "**${name} passed on ${base_tag}, but the last release moved meanwhile** ($(cat "$dir/release/moved")). Nothing was pushed. The bot releases only on top of the release it tested on: it makes and tests $([ "$n" -le 1 ] && echo "it" || echo "the ${n} bumps of the release") again on the new one ([run](${run_url}); attempt ${next_attempt} of ${MAX_ATTEMPTS})."
      else
        verdict=comment
        echo "**${name} passed on ${base_tag}, but the last release moved under ${MAX_ATTEMPTS} runs in a row** ($(cat "$dir/release/moved")). Nothing was pushed; the next weekly run tests it again ([run](${run_url}))."
      fi ;;
    *)
      verdict=attention
      echo "**Needs attention: ${name} passed on ${base_tag}, but its release ${new_tag} did not finish** ([run](${run_url}))."
      echo ""
      if [ -s "$dir/release/failed" ]; then
        cat "$dir/release/failed"
      else
        echo "The release stopped after: $(awk '{ print $1 }' "$dir/release/steps" 2>/dev/null | paste -sd ' ' - | sed 's/^$/nothing/') (see the run's log)."
      fi
      echo ""
      echo "Done so far:"
      echo ""
      if [ -s "$dir/release/steps" ]; then sed 's/^/- /' "$dir/release/steps"; else echo "- nothing: no push"; fi
      echo ""
      if grep -qE '^origin [^ ]+ [0-9a-f]{40} [0-9a-f]{40}$' "$dir/release/steps" 2>/dev/null; then
        echo "The origin has ${new_tag}. Once what failed is fixed, run **Actions → Dependency tests → Run workflow** with \`resume\` = \`${new_tag}\` and $(rerun_line release): the bot goes on from there (the public push, the images, the GitHub release, the chart)."
      else
        echo "Nothing of the release was pushed. Once what failed is fixed, run **Actions → Dependency tests → Run workflow** with $(rerun_line release)."
      fi ;;
  esac
}

# combined_suites KIND — the suites of a batch's combined tree, when it ran them
combined_suites() {
  local b="$dir/batch/$1"
  [ -e "$b/tested" ] && [ "$(cat "$b/how" 2>/dev/null || true)" != single ] || return 0
  echo ""
  echo "The suites of the combined tree of this run's green bumps (\`deps/$(batch_id "$1")\`, $(cut -c1-12 "$b/head")):"
  echo ""
  suites_list "$dir" "$(batch_id "$1")" "$b/report"
}

# comment_land DIR DEP NAME — the comment of a bump of the run's landing on next; verdict as above
comment_land() {
  local dir="$1" dep="$2" name="$3" report="$1/bump/$2" b="$1/batch/next" others how n
  others="$(role_others "$dir" land "$dep")"
  n="$(role_deps "$dir" land | wc -l | tr -d ' ')"
  how="$(cat "$b/how" 2>/dev/null || echo single)"
  if [ "$dry" = --dry-run ]; then
    verdict=none
    echo "**Dry run: ${name} passed on next and would land on next**${others:+, together with ${others}} (${lstate}; nothing was pushed, the issue is left as it is)."
    echo ""
    if [ -s "$dir/land/steps" ]; then sed 's/^/- /' "$dir/land/steps"; fi
    [ ! -s "$dir/land/failed" ] || echo "- failed: $(cat "$dir/land/failed")"
    [ ! -s "$dir/land/moved" ] || echo "- moved: $(cat "$dir/land/moved")"
    echo ""
    echo "Suites ([run](${run_url})):"
    echo ""
    suites_list "$dir" "$dep" "$report"
    combined_suites next
    return 0
  fi
  case "$lstate" in
    landed)
      verdict=passed
      echo "**Landed on next: ${name}** ([run](${run_url}))${others:+, together with ${others}}. It changes nothing a release ships (no image, chart or API code), so it makes no release of its own: the next full release carries it."
      echo ""
      case "$how" in
        batch) echo "- Made and tested on next ($(cut -c1-12 "$report/next-base")): the bump alone, then the ${n} bumps of this run together (${land:0:12}), one fast-forward of next." ;;
        prefix) echo "- Made and tested on next ($(cut -c1-12 "$report/next-base")): the bump alone, and on the combined tree of this run's bumps of next, where its suites passed; next takes the first ${n} of them (${land:0:12}), one fast-forward." ;;
        *) echo "- Made and tested on next ($(cut -c1-12 "$report/next-base")): the bump ${land:0:12}, a fast-forward of next." ;;
      esac
      echo ""
      cat "$report/summary.md"
      echo ""
      echo "Suites ([run](${run_url})):"
      echo ""
      suites_list "$dir" "$dep" "$report"
      combined_suites next ;;
    moved)
      if [ "$next_attempt" -le "$MAX_ATTEMPTS" ]; then
        verdict=retest
        echo "**${name} passed on next, but next moved meanwhile** ($(cat "$dir/land/moved")). Nothing was pushed: the bot makes and tests it again on the new next ([run](${run_url}); attempt ${next_attempt} of ${MAX_ATTEMPTS})."
      else
        verdict=comment
        echo "**${name} passed on next, but next moved under ${MAX_ATTEMPTS} runs in a row** ($(cat "$dir/land/moved")). Nothing was pushed; the next weekly run tests it again ([run](${run_url}))."
      fi ;;
    *)
      verdict=attention
      echo "**Needs attention: ${name} passed on next, but its landing did not finish** ([run](${run_url}))."
      echo ""
      if [ -s "$dir/land/failed" ]; then cat "$dir/land/failed"; else echo "The landing stopped before its push (see the run's log)."; fi
      echo ""
      echo "Nothing was pushed. Once what failed is fixed, run **Actions → Dependency tests → Run workflow** with $(rerun_line land)." ;;
  esac
}

cmd_actions() {
  local dir="$1" run_url="$2" attempt="${3:-1}" dry="${4:-}" report dep issue version outcome base_tag head comment suite
  local name role rstate lstate new_tag release land next_attempt verdict where retest=()
  mkdir -p "$dir/comments"
  : > "$dir/actions"
  [[ "$attempt" =~ ^[0-9]+$ ]] || attempt=1
  next_attempt=$((attempt + 1))
  rstate="$(release_state "$dir" release)"
  lstate="$(release_state "$dir" land)"
  new_tag="$(env_of "$dir/release/release.env" NEW_TAG)"
  release="$(env_of "$dir/release/release.env" RELEASE)"
  land="$(env_of "$dir/land/release.env" LAND)"
  # act ISSUE LINE… — the issue's actions (none in a dry run)
  act() { [ "$dry" = --dry-run ] || printf '%s\n' "${@:2}" >> "$dir/actions"; }
  for report in "$dir"/bump/*/; do
    [ -d "$report" ] || continue
    report="${report%/}"
    dep="${report##*/}"
    issue="$(cat "$report/issue")"
    version="$(cat "$report/version")"
    # what the comments call it (a resumed release: what its tag says it releases)
    name="$(cat "$report/label" 2>/dev/null || echo "${dep} ${version}")"
    base_tag="$(cat "$report/base-tag" 2>/dev/null || true)"
    head="$(cat "$report/head" 2>/dev/null || true)"
    where="$base_tag"
    [ "$(cat "$report/path" 2>/dev/null || echo release)" != next ] || where="next"
    outcome="$(cat "$dir/outcome/$dep" 2>/dev/null || echo error)"
    comment="$dir/comments/${issue}.md"
    case "$outcome" in
      current)
        {
          echo "**Nothing to do: the last release has ${name} or a newer one.**"
          echo ""
          cat "$report/summary.md" 2>/dev/null || true
          echo ""
          echo "Closed by the dependency test bot ([run](${run_url}))."
        } > "$comment"
        act "$issue" "$(printf 'comment\t%s\t%s' "$issue" "$comment")" "$(printf 'close\t%s' "$issue")" ;;
      held)
        if [ -e "$report/held-wait" ]; then
          # nothing a person does: the next full release brings what the bot needs
          {
            echo "**The bot cannot test ${name} yet:** $(cat "$report/held")."
            echo ""
            echo "Nothing was tested or pushed ([run](${run_url})). The issue keeps no label: a run after that release tests it by itself."
          } > "$comment"
          act "$issue" "$(printf 'comment\t%s\t%s' "$issue" "$comment")"
        else
          {
            echo "**The bot cannot release now:** $(cat "$report/held")"
            echo ""
            echo "Nothing was tested or pushed ([run](${run_url})). Once that is fixed, run **Actions → Dependency tests → Run workflow** with this issue's number."
          } > "$comment"
          act "$issue" "$(printf 'label\t%s\t%s' "$issue" "$ATTENTION_LABEL")" "$(printf 'comment\t%s\t%s' "$issue" "$comment")"
        fi ;;
      attention)
        {
          cat "$report/summary.md"
          echo ""
          echo "Nothing was pushed: the origin and this repository are unchanged ([run](${run_url})). Once the last release can take it (or a person moved it), run **Actions → Dependency tests → Run workflow** with this issue's number to test it again; a newer release supersedes this issue by itself."
        } > "$comment"
        act "$issue" "$(printf 'label\t%s\t%s' "$issue" "$ATTENTION_LABEL")" "$(printf 'comment\t%s\t%s' "$issue" "$comment")" ;;
      error)
        {
          echo "**The dependency test bot could not check ${name}** ([run](${run_url})): the next run tries again."
          echo ""
          echo '```'
          tail -n 15 "$report/bump.log" 2>/dev/null || echo "no log"
          echo '```'
        } > "$comment"
        act "$issue" "$(printf 'comment\t%s\t%s' "$issue" "$comment")" ;;
      red)
        {
          echo "**Needs attention: ${name} failed on ${where}.** Nothing was released or pushed; the run's other bumps went without it."
          echo ""
          if [ "$where" = next ]; then
            echo "The bump (on next, commit ${head:0:12} of this [run](${run_url}), not pushed):"
          else
            echo "The bump and its release commit (on ${base_tag}, commit ${head:0:12} of this [run](${run_url}), not pushed):"
          fi
          echo ""
          cat "$report/summary.md"
          echo ""
          echo "Suites:"
          echo ""
          suites_list "$dir" "$dep" "$report"
          while read -r suite; do
            [ -n "$suite" ] || continue
            failure_details "$dir" "$dep" "$suite"
          done < "$report/failed"
          echo ""
          echo "Fix what failed (on next, and in a release the bot can build on), or decide the version waits; then run **Actions → Dependency tests → Run workflow** with this issue's number. A newer release supersedes this issue by itself."
        } > "$comment"
        act "$issue" "$(printf 'label\t%s\t%s' "$issue" "$ATTENTION_LABEL")" "$(printf 'comment\t%s\t%s' "$issue" "$comment")" ;;
      green)
        role="$(cat "$dir/role/$dep" 2>/dev/null || echo nobatch)"
        verdict=comment
        case "$role" in
          release) comment_release "$dir" "$dep" "$name" > "$comment" ;;
          land) comment_land "$dir" "$dep" "$name" > "$comment" ;;
          breaker)
            verdict=attention
            {
              cat "$dir/role/$dep.md"
              echo ""
              echo "Fix what keeps them apart (on next, and in a release the bot can build on), or decide the version waits; then run **Actions → Dependency tests → Run workflow** with this issue's number ([run](${run_url})). A newer release supersedes this issue by itself."
            } > "$comment" ;;
          wait)
            { cat "$dir/role/$dep.md"; echo ""; echo "This [run](${run_url})."; } > "$comment" ;;
          *)
            echo "**${name} passed alone on ${where}; it waits for the next run** ([run](${run_url})): this run could not put its green bumps together (its job *The green bumps together* did not finish). Nothing was pushed for it, and the issue keeps no label: the next run makes and tests it again." > "$comment" ;;
        esac
        case "$verdict" in
          passed) act "$issue" "$(printf 'label\t%s\t%s' "$issue" "$PASSED_LABEL")" "$(printf 'comment\t%s\t%s' "$issue" "$comment")" "$(printf 'close\t%s' "$issue")" ;;
          attention) act "$issue" "$(printf 'label\t%s\t%s' "$issue" "$ATTENTION_LABEL")" "$(printf 'comment\t%s\t%s' "$issue" "$comment")" ;;
          retest) act "$issue" "$(printf 'comment\t%s\t%s' "$issue" "$comment")"; retest+=("$issue") ;;
          comment) act "$issue" "$(printf 'comment\t%s\t%s' "$issue" "$comment")" ;;
        esac ;;
    esac
  done
  # the bumps of a release (or landing) whose base moved: tested again on the new one, one run
  if [ "${#retest[@]}" -gt 0 ] && [ "$dry" != --dry-run ]; then
    printf 'dispatch\t%s\t%s\n' "$(IFS=,; echo "${retest[*]}")" "$next_attempt" >> "$dir/actions"
  fi
}

command="${1:-}"
shift || true
case "$command" in
  bumps) cmd_bumps "$@" ;;
  outcome) cmd_outcome "$@" ;;
  batch) cmd_batch "$@" ;;
  pick) cmd_pick "$@" ;;
  resume) cmd_resume "$@" ;;
  actions) cmd_actions "$@" ;;
  *) sed -n '4,61p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
