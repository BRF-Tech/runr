#!/usr/bin/env bash
# Tests the dependency bots' weekly summary (ci/deps-digest.sh, ci/deps/digest.jq; the format shared
# with filex's bot) and the test bot's plan of its rows (ci/deps-plan.sh) without GitHub: gh is a
# stub that keeps the issues in a JSON file (as gh issue list writes them), the findings are files
# as ci/check-*-releases.sh writes them. Checks that:
#   - the first run folds the findings filed as issues of their own into the week's summary
#     ("Folded into the weekly summary #N: …" in their bodies, closed), a security one too; a
#     release outside a constraint is a row of its own (<group>--<package>), the earlier bot's
#     "major versions" issue and single outside issue closed with a pointer (one labelled next
#     update required left alone); a newer finding supersedes a red issue; an issue of its own
#     labelled next update required is not folded, its row says so; a finding an issue or a summary
#     had is not listed again; a major a hold keeps is a row "held (<reason>)", no issue; the rows,
#     columns and markers; the section "Next update required" (its updates, the holds);
#   - the same week: a new finding edits the summary; a security finding is a row flagged
#     "security: <advisory>", tested the same day (a dispatch of <summary>:<group>);
#   - the test bot's results: a run that starts marks its rows testing (no movement, no summary); a
#     result in a later week opens its summary with the open rows ("Continued from"), the last one
#     closed ("Continued in #N." in its body); green → shipped in vX.Y.Z, green, a row that failed
#     moved to an issue of its own labelled next update required (the section lists it) that the
#     plan tests on every run, a release that needs a person one issue for the run,
#     closed by itself once its rows moved on; the mirror of an issue of its own; the dispatch; no
#     comment on a summary;
#   - the plan: a summary's open rows by <summary>:<group>, one that needs attention only when named
#     (the summary's number names them all, <any>:<group> the row wherever it carried over); an
#     issue labelled next update required on every run (retry), a legacy needs attention one too,
#     relabelled;
#   - a quiet week opens no summary and keeps the open one; movement carries the open rows over;
#   - a release outside a constraint that the check of its group no longer reports: its row closed;
#     no major versions issue is made; one retired without a summary points at none; a hold that is
#     gone leaves the summary;
#   - a row that waits for a run that needs a person and that the check of its group no longer
#     reports (a person's release has it): closed, and the run's issue with its last row;
#   - a finding that landed on next and that the check no longer reports: shipped in the last
#     release; a later week closes a summary with nothing open ("Closed at the end of the week:
#     nothing was left open.") and opens none;
#   - apply: the operations in order, each created issue's number for its {{key}}, one whose issue
#     was not created skipped (exit 1).
#
#   bash runtime/test/deps_digest_test.sh
# shellcheck disable=SC2016,SC2015 # the bodies' Markdown, not this shell's expressions; checks as && chains
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
D="$root/ci/deps-digest.sh"

# --- gh: the issues in a JSON file ------------------------------------------------------------------
mkdir -p "$tmp/bin"
cat > "$tmp/bin/gh" <<'STUB'
#!/usr/bin/env bash
# gh issue create|edit|close|reopen|comment|list and gh workflow run on $GH_STATE (gh issue list's JSON)
set -euo pipefail
state="${GH_STATE:?}"
printf 'gh' >> "${GH_LOG:?}"; printf ' [%s]' "$@" >> "$GH_LOG"; echo >> "$GH_LOG"
what="$1 $2"; shift 2
num="" title="" body="" comment="" st="open" labels=() add=() rm=()
while [ "$#" -gt 0 ]; do
  case "$1" in
    --repo|--limit|--json|--ref|-f|--reason) shift 2 ;;
    --title) title="$2"; shift 2 ;;
    --body-file) body="$(cat "$2")"; shift 2 ;;
    --label) labels+=("$2"); shift 2 ;;
    --add-label) add+=("$2"); shift 2 ;;
    --remove-label) rm+=("$2"); shift 2 ;;
    --comment) comment="$2"; shift 2 ;;
    --state) st="$2"; shift 2 ;;
    [0-9]*) num="$1"; shift ;;
    *) shift ;;
  esac
done
upd() { jq "$@" "$state" > "$state.tmp" && mv "$state.tmp" "$state"; }
case "$what" in
  "issue create")
    num="$(jq '[.[].number] + [0] | max + 1' "$state")"
    upd --argjson n "$num" --arg t "$title" --arg b "$body" --arg d "${GH_DATE:-2026-11-14}T05:30:00Z" \
      --argjson l "$(printf '%s\n' ${labels[@]+"${labels[@]}"} | jq -R . | jq -sc 'map(select(length > 0) | {name: .})')" \
      '. + [{number: $n, title: $t, state: "OPEN", labels: $l, body: $b, createdAt: $d}]'
    echo "https://github.com/example/repo/issues/${num}" ;;
  "issue edit")
    [ -z "$title" ] || upd --argjson n "$num" --arg t "$title" 'map(if .number == $n then .title = $t else . end)'
    [ -z "$body" ] || upd --argjson n "$num" --arg b "$body" 'map(if .number == $n then .body = $b else . end)'
    for l in ${add[@]+"${add[@]}"}; do upd --argjson n "$num" --arg l "$l" 'map(if .number == $n then .labels += [{name: $l}] else . end)'; done
    for l in ${rm[@]+"${rm[@]}"}; do upd --argjson n "$num" --arg l "$l" 'map(if .number == $n then .labels |= map(select(.name != $l)) else . end)'; done ;;
  "issue close")
    upd --argjson n "$num" 'map(if .number == $n then .state = "CLOSED" else . end)'
    [ -z "$comment" ] || printf '%s\t%s\n' "$num" "$comment" >> "${GH_COMMENTS:?}" ;;
  "issue reopen")
    upd --argjson n "$num" 'map(if .number == $n then .state = "OPEN" else . end)' ;;
  "issue comment")
    printf '%s\t%s\n' "$num" "$(tr '\n' ' ' <<<"$body")" >> "${GH_COMMENTS:?}" ;;
  "issue list")
    if [ "$st" = all ]; then cat "$state"; else jq '[.[] | select(.state == "OPEN")]' "$state"; fi ;;
  "workflow run") ;;
  *) echo "gh stub: ${what}?" >&2; exit 1 ;;
esac
STUB
chmod +x "$tmp/bin/gh"
export PATH="$tmp/bin:$PATH" GH_REPO=example/repo GH_STATE="$tmp/state.json" GH_LOG="$tmp/gh.log" GH_COMMENTS="$tmp/comments.tsv"
: > "$GH_LOG"; : > "$GH_COMMENTS"

# --- findings as the checks write them ------------------------------------------------------------
# group DIR GROUP FP UPDATES [URGENT] — a package group's finding (ci/check-package-releases.sh)
group() {
  local n first
  n="$(jq length <<<"$4")"
  first="$(jq -r '.[0] | "\(.name) \(.to)"' <<<"$4")"
  {
    echo "deps: $2 [$3]: ${n} update$([ "$n" = 1 ] || echo s): ${first}$([ "${5:-false}" != true ] || echo ' (security)')"
    echo ""
    echo "Minor and patch updates of **$2**."
    echo ""
    echo "<!-- deps-bot $(jq -nc --arg g "$2" --argjson u "$4" --argjson urgent "${5:-false}" '{group: $g, updates: $u, urgent: $urgent, advisories: (if $urgent then [{id: "GHSA-test", package: $u[0].name}] else [] end)}') -->"
  } > "$1/deps-$2-$3.md"
}
chart() { printf 'deps: %s %s released\n\nNew version of the Helm chart **%s**.\n' "$2" "$3" "$2" > "$1/deps-$2-$3.md"; }
# outside DIR ID FP NAME FROM TO CONSTRAINT — a release outside the constraint, as the check files
# it: a package group's finding of its own, id <group>--<package>
outside() {
  {
    echo "deps: $2 [$3]: 1 update: $4 $6 (outside $7)"
    echo ""
    echo "**$4** $6 is out, outside \`$7\`."
    echo ""
    echo "<!-- deps-bot $(jq -nc --arg g "$2" --arg n "$4" --arg f "$5" --arg t "$6" --arg c "$7" '{group: $g, updates: [{name: $n, from: $f, to: $t, outside: true, constraint: $c, line: "x", why: "a new major", files: ["x/package.json"]}], urgent: false, advisories: []}') -->"
  } > "$1/deps-$2-$3.md"
}
# a major versions issue of the earlier bot, and the text it gets when it is retired
MAJORS_BODY=$'Major versions of **npm-vue** need a decision.\n\n<!-- deps-bot {"group":"npm-vue","kind":"outside","items":[{"name":"vite","line":"8.x","from":"7.3.1","to":"8.0.1","constraint":"^7.0.0"}]} -->\n<!-- runr-deps:majors:npm-vue -->'
RETIRED='The dependency test bot now attempts every release outside a constraint on its weekly run: each one is a row of the weekly summary'
held() { mkdir -p "$1/held"; jq -nc --arg g "$2" --arg n "$3" --arg v "$4" --arg l "$5" --arg r "$6" '{group: $g, name: $n, version: $v, line: $l, from: "3.38.0", constraint: "3.38.0", reason: $r}' > "$1/held/$2--$3.json"; }
upd() { jq -nc --arg n "$1" --arg f "$2" --arg t "$3" '[{name: $n, from: $f, to: $t, files: ["x/lock"]}]'; }
gmarker() { jq -nc --arg g "$1" --argjson u "$2" --argjson urgent "${3:-false}" '{group: $g, updates: $u, urgent: $urgent, advisories: (if $urgent then [{id: "GHSA-test", package: "x"}] else [] end)}'; }
# body MARKER… — an issue body with its markers
body() { local m; printf 'Text.\n'; for m in "$@"; do printf '\n<!-- deps-bot %s -->' "$m"; done; printf '\n'; }
iss() {
  jq -nc --argjson n "$1" --arg t "$2" --arg s "$3" --arg l "$4" --arg b "$5" --arg d "${6:-2026-11-12}" \
    '{number: $n, title: $t, state: $s, labels: ($l | split(",") | map(select(length > 0) | {name: .})), body: $b, createdAt: "\($d)T05:30:00Z"}'
}
# run DATE FINDINGS — the daily check's summary step, as upstream-releases.yml runs it
run() {
  gh issue list --state all > "$tmp/all.json"
  GH_DATE="$1" DEPS_RELEASE_VERSION="${RELEASE:-0.7.0}" bash "$D" file "$2" "$tmp/all.json" "$1" > "$tmp/ops.json" || fail "file $1: $(cat "$tmp/ops.json")"
  GH_DATE="$1" bash "$D" apply "$tmp/ops.json" > "$tmp/apply.log" 2>&1 || fail "apply $1: $(cat "$tmp/apply.log")"
}
# report DATE DIR — the test bot's report, as deps-test.yml makes it
report() {
  gh issue list > "$tmp/open.json"
  GH_DATE="$1" DEPS_DISPATCH_REF=main bash "$D" report "$2" "$tmp/open.json" "$1" > "$tmp/rops.json" || fail "report: $(cat "$tmp/rops.json")"
  GH_DATE="$1" bash "$D" apply "$tmp/rops.json" > "$tmp/apply.log" 2>&1 || fail "apply: $(cat "$tmp/apply.log")"
}
# res REF KIND GROUP VERSION STATE MODE NOTE [FILE] [RELEASE] [URL] — a row of DIR/lines
res() { local IFS=$'\x1f'; echo "$*"; }
state() { jq -c "$1" "$GH_STATE"; }
title_of() { jq -r --argjson n "$1" '.[] | select(.number == $n) | .title' "$GH_STATE"; }
state_of() { jq -r --argjson n "$1" '.[] | select(.number == $n) | .state' "$GH_STATE"; }
body_of() { jq -r --argjson n "$1" '.[] | select(.number == $n) | .body' "$GH_STATE"; }
labels_of() { jq -c --argjson n "$1" '[.[] | select(.number == $n) | .labels[].name]' "$GH_STATE"; }
number_of() { jq -r --arg t "$1" '[.[] | select(.title | startswith($t)) | .number] | last // empty' "$GH_STATE"; }
# rows N — the summary N's rows (id, version, state, issue)
rows() { body_of "$1" | grep -o '<!-- deps-bot {.*} -->' | sed 's/^<!-- deps-bot //; s/ -->$//' \
  | jq -sc 'map([.id, (.version | tostring), .state, (.issue // null | if . == null then null else (tonumber? // .) end)]) | sort'; }
has_row() { rows "$1" | jq -e --argjson r "$2" 'any(.[]; . == $r)' >/dev/null; }
digest_of() { jq -r --arg w "$1" '.[] | select(.title == "deps: week \($w)") | .number' "$GH_STATE"; }

# --- the first run: the issues of their own folded into the week's summary ----------------------------
{
  iss 15 "deps: traefik 42.3.2 released" OPEN "" "New version." 2026-11-10
  iss 20 "deps: composer-api [27340472]: 1 update: laravel/framework v12.72.0" OPEN "" "$(body "$(gmarker composer-api "$(upd laravel/framework v12.70.0 v12.72.0)")")"
  iss 31 "deps: go-example-wasm github.com/x/sdk/v3 v3.x released (outside github.com/x/sdk/v2)" OPEN "needs attention" "**x** is out."
  iss 32 "deps: go-example-wasm github.com/y/sdk/v3 v3.x released (outside github.com/y/sdk/v2)" OPEN "next update required" "**y** is held."
  iss 37 "deps: go-agent [44444444]: 1 update: k8s.io/api v0.37.0" OPEN "deps,next update required" "$(body "$(gmarker go-agent "$(upd k8s.io/api v0.36.0 v0.37.0)")")"
  iss 40 "deps: npm-vue major versions need a decision" OPEN "deps,needs attention" "$MAJORS_BODY"
  iss 34 "deps: go-proxy [3063d268]: 1 update: github.com/redis/go-redis/v9 v9.23.0" OPEN "" "$(body "$(gmarker go-proxy "$(upd github.com/redis/go-redis/v9 v9.7.3 v9.23.0)")")"
  iss 35 "deps: go-queue-consumer [9e8b508b]: 1 update: github.com/jackc/pgx/v5 v5.13.0 (security)" OPEN "security" "$(body "$(gmarker go-queue-consumer "$(upd github.com/jackc/pgx/v5 v5.12.0 v5.13.0)" true)")"
  iss 36 "deps: go-cli [11111111]: 1 update: github.com/spf13/cobra v1.10.1" OPEN "needs attention" "$(body "$(gmarker go-cli "$(upd github.com/spf13/cobra v1.8.1 v1.10.1)")")"
  iss 47 "deps: npm-vue [15672bfe]: 1 update: axios 1.21.1" CLOSED "" "$(body "$(gmarker npm-vue "$(upd axios 1.21.0 1.21.1)")")"
  iss 50 "A bug report" OPEN "" "Something broke."
} | jq -s . > "$GH_STATE"
f1="$tmp/f1"; mkdir -p "$f1"
group "$f1" go-proxy 3063d268 "$(upd github.com/redis/go-redis/v9 v9.7.3 v9.23.0)"
group "$f1" go-cli 22222222 "$(upd github.com/spf13/cobra v1.8.1 v1.10.2)"
group "$f1" npm-vue 15672bfe "$(upd axios 1.21.0 1.21.1)"
chart "$f1" zot 0.1.135
outside "$f1" npm-vue--vite aaaa0001 vite 7.3.1 8.0.1 '^7.0.0'
held "$f1" npm-vue cronstrue 4.0.0 4.x "pinned exactly in vue/package.json: the warmer editor's tests compare its wording"
printf '%s\n' npm-vue go-proxy go-cli go-example-wasm zot > "$f1/checked"
run 2026-11-14 "$f1"
dg="$(digest_of 2026-W46)"
[ -n "$dg" ] && [ "$(labels_of "$dg")" = '["deps"]' ] || fail "no summary: $(state '[.[] | [.number, .title, .state]]')"
[ "$(state '[.[] | select(.title | test("major versions need a decision$")) | .number]')" = '[40]' ] \
  || fail "a major versions issue made: $(state '[.[] | [.number, .title, .state]]')"
[ "$(rows "$dg")" = '[["composer-api","27340472","new",null],["go-agent","44444444","next update required",37],["go-cli","22222222","new",null],["go-proxy","3063d268","new",null],["go-queue-consumer","9e8b508b","new",null],["npm-vue--vite","aaaa0001","new",null],["npm-vue/cronstrue","4.x","held",null],["traefik","42.3.2","new",null],["zot","0.1.135","new",null]]' ] \
  || fail "the first summary's rows: $(rows "$dg")"
[ "$(state '[.[] | select(.state == "OPEN") | .number] | sort')" = "$(jq -nc "[32, 37, 50, ${dg}] | sort")" ] \
  || fail "the open issues after the first run: $(state '[.[] | select(.state == "OPEN") | [.number, .title]]')"
for n in 15 20 34 35; do
  [ "$(body_of "$n" | tail -n 1)" = "Folded into the weekly summary #${dg}: the bot opens a separate issue only when a person has to do something." ] \
    || fail "#${n} folded: $(body_of "$n")"
done
body_of 36 | grep -qF "Superseded by go-cli 22222222 (the check of 2026-11-14), a row of the weekly summary #${dg}" || fail "the red issue superseded: $(body_of 36)"
# the earlier bot's issues of major versions: closed with a pointer (one labelled next update required stays)
[ "$(body_of 31 | tail -n 1)" = "${RETIRED} #${dg}; one it cannot move or that fails its suites gets \`next update required\` there." ] \
  && [ "$(state_of 31)" = CLOSED ] || fail "the single major retired: $(state_of 31) $(body_of 31)"
[ "$(state_of 40)" = CLOSED ] && [ "$(body_of 40 | tail -n 1)" = '<!-- runr-deps:majors:npm-vue -->' ] && body_of 40 | grep -qF "${RETIRED} #${dg};" \
  || fail "the major versions issue retired: $(state_of 40) $(body_of 40)"
[ "$(state_of 32)" = OPEN ] && [ "$(body_of 32)" = '**y** is held.' ] && [ "$(labels_of 32)" = '["next update required"]' ] \
  || fail "a single major labelled next update required was touched: $(state_of 32) $(body_of 32)"
! grep -qF '[edit] [37]' "$GH_LOG" || fail "an issue labelled next update required was folded: $(body_of 37)"
[ ! -s "$GH_COMMENTS" ] || fail "the bot wrote comments: $(cat "$GH_COMMENTS")"
b="$(body_of "$dg")"
grep -qxF '| Dependency | Version | State | Item | Note |' <<<"$b" \
  && grep -qxF '| `go-proxy` <!-- runr-deps:row:go-proxy --> | `github.com/redis/go-redis/v9` v9.7.3 → v9.23.0 | new |  |  |' <<<"$b" \
  && grep -qxF '| `npm-vue/cronstrue` <!-- runr-deps:row:npm-vue/cronstrue --> | 3.38.0 → 4.0.0 | held (pinned exactly in vue/package.json: the warmer editor'"'"'s tests compare its wording) |  |  |' <<<"$b" \
  && grep -qxF '| `npm-vue--vite` <!-- runr-deps:row:npm-vue--vite --> | `vite` 7.3.1 → 8.0.1 | new |  |  |' <<<"$b" \
  && grep -qxF '| `go-agent` <!-- runr-deps:row:go-agent --> | `k8s.io/api` v0.36.0 → v0.37.0 | next update required | #37 |  |' <<<"$b" \
  && grep -qxF '| `go-queue-consumer` <!-- runr-deps:row:go-queue-consumer --> | `github.com/jackc/pgx/v5` v5.12.0 → v5.13.0 | new |  | security: GHSA-test |' <<<"$b" \
  && grep -qxF '| `zot` <!-- runr-deps:row:zot --> | 0.1.135 | new |  |  |' <<<"$b" \
  && [ "$(tail -n 1 <<<"$b")" = '<!-- runr-deps:digest:2026-W46 -->' ] && grep -qF '**2026-W46** (2026-11-09 to 2026-11-15, UTC)' <<<"$b" \
  || fail "the summary's body: ${b}"
# the section: the updates the bot tries again every week, the holds with their reasons
sec="$(sed -n '/^### Next update required$/,/^<!-- deps-bot /{/^<!-- deps-bot /!p}' <<<"$b")"
grep -qxF -e '- `go-agent` `k8s.io/api` v0.36.0 → v0.37.0 (#37)' <<<"$sec" \
  && grep -qxF -e '- `npm-vue/cronstrue` 3.38.0 → 4.0.0: held, pinned exactly in vue/package.json: the warmer editor'"'"'s tests compare its wording' <<<"$sec" \
  && grep -qF 'The bot tries these updates again on every weekly run' <<<"$sec" && grep -qF '`ci/deps/holds.txt`' <<<"$sec" \
  && [ "$(grep -c '^- ' <<<"$sec")" = 2 ] || fail "the section Next update required: ${sec}"
grep -q 'workflow run' "$GH_LOG" && fail "a dispatch without a new security finding"
echo "ok - the first run: the issues of their own folded into the week's summary (one labelled next update required not), a release outside a constraint a row of its own, the earlier bot's major versions issues closed with a pointer (one labelled next update required left), a red issue superseded, nothing listed twice, a held major a row; the columns, row and summary markers, the section Next update required, no comment"

# --- the same week: a new finding edits the summary; a security finding is a flagged row, tested today
f2="$tmp/f2"; cp -r "$f1" "$f2"
group "$f2" go-agent 33333333 "$(upd k8s.io/api v0.37.1 v0.37.2)" true
: > "$GH_LOG"
run 2026-11-15 "$f2"
[ "$(state '[.[] | select(.title | startswith("deps: week"))] | length')" = 1 ] && ! grep -qF '[issue] [create]' "$GH_LOG" || fail "an issue for a security finding: $(cat "$GH_LOG")"
grep -qxF '| `go-agent` <!-- runr-deps:row:go-agent --> | `k8s.io/api` v0.37.1 → v0.37.2 | new |  | security: GHSA-test |' <<<"$(body_of "$dg")" || fail "the security row: $(body_of "$dg")"
grep -qF "gh [workflow] [run] [deps-test.yml] [--repo] [example/repo] [-f] [issue=${dg}:go-agent] [-f] [attempt=1]" "$GH_LOG" || fail "the security finding is not tested today: $(cat "$GH_LOG")"
[ "$(state_of 37)" = CLOSED ] && body_of 37 | grep -qF "Superseded by go-agent 33333333" || fail "a newer finding supersedes an issue labelled next update required: $(body_of 37)"
: > "$GH_LOG"
run 2026-11-15 "$f2"
grep -q 'issue\] \[\(create\|edit\|close\|reopen\)\|workflow' "$GH_LOG" && fail "the same findings again changed something: $(cat "$GH_LOG")"
echo "ok - the same week: a new finding edits the summary; a security finding is a row flagged security: <advisory>, tested the same day; the same findings change nothing"

# --- the plan of the summary's rows ----------------------------------------------------------------
gh issue list > "$tmp/open.json"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json")"
[ "$(jq -c '[.tests[] | [.ref, .version]]' <<<"$plan")" = "[[\"${dg}:composer-api\",\"27340472\"],[\"${dg}:go-agent\",\"33333333\"],[\"${dg}:go-cli\",\"22222222\"],[\"${dg}:go-proxy\",\"3063d268\"],[\"${dg}:go-queue-consumer\",\"9e8b508b\"],[\"${dg}:npm-vue--vite\",\"aaaa0001\"],[\"${dg}:traefik\",\"42.3.2\"],[\"${dg}:zot\",\"0.1.135\"]]" ] \
  || fail "the plan of the summary: $(jq -c .tests <<<"$plan")"
[ "$(jq -c '.tests[] | select(.dep == "npm-vue--vite") | .updates[0] | [.name, .to, .outside]' <<<"$plan")" = '["vite","8.0.1",true]' ] \
  || fail "a release outside a constraint's test: ${plan}"
[ "$(jq -c '.tests[] | select(.dep == "go-agent") | [.issue, (.updates | length), .urgent]' <<<"$plan")" = "[${dg},1,true]" ] || fail "a security row's test: ${plan}"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json" --only="7:zot,${dg}:go-agent")"
[ "$(jq -c '[.tests[].ref]' <<<"$plan")" = "[\"${dg}:go-agent\",\"${dg}:zot\"]" ] || fail "a row named wherever it carried over: $(jq -c .tests <<<"$plan")"
rc=0; bash "$root/ci/deps-plan.sh" "$tmp/open.json" --only="${dg}:Zot" >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] || fail "a ref that is no N or N:group passed"
echo "ok - the plan: the summary's open rows by <summary>:<group> (a security one urgent, a release outside a constraint), a row named wherever it carried over"

# --- the markers: the summary's, its rows' and the other runr-deps: markers are no data marker, and
# a marker a person quotes in a text (not the last line of the body) makes no summary ---------------
plan_before="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json" | jq -c '[.tests[].ref]')"
jq --argjson n 900 '. + [
  {number: $n, title: "A question about the summary", state: "OPEN", labels: [], createdAt: "2026-11-15T08:00:00Z",
   body: "Row `zot` <!-- runr-deps:row:zot --> of the summary <!-- runr-deps:digest:2026-W46 --> and <!-- runr-deps:majors:npm-vue -->\n<!-- runr-deps:digest:2026-W46 -->\nwhy?"},
  {number: ($n + 1), title: "deps: go-cli [abcdef12]: 1 update: github.com/spf13/cobra v1.10.9", state: "OPEN", labels: [], createdAt: "2026-11-15T08:00:00Z",
   body: "Only the row marker:\n<!-- runr-deps:row:go-cli -->\nand the summary marker <!-- runr-deps:digest:2026-W46 --> in the text."},
  {number: ($n + 2), title: "deps: kube-prometheus-stack 99.0.0 released", state: "OPEN", labels: [{name: "needs attention"}], createdAt: "2026-11-15T08:00:00Z",
   body: "x\n<!-- runr-deps:row:zot/0.1.200 -->"}]' "$tmp/open.json" > "$tmp/quoted.json"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/quoted.json")"
# (902, an update issue of the earlier bot labelled needs attention: tested on every run as next
# update required, and relabelled)
[ "$(jq -c '[.tests[].ref | select(. != "902")]' <<<"$plan")" = "$plan_before" ] \
  && jq -e '.skipped[] | select(.issue == 901) | .reason | test("no deps-bot marker")' >/dev/null <<<"$plan" \
  && jq -e '.tests[] | select(.ref == "902") | .retry == true' >/dev/null <<<"$plan" && [ "$(jq -c .relabel <<<"$plan")" = '[902]' ] \
  && ! jq -e '[.tests[], .skipped[]] | any(.ref | startswith("900:") or startswith("901:"))' >/dev/null <<<"$plan" \
  || fail "the plan took a runr-deps: marker for a summary or a data marker, or a needs attention update issue waits: ${plan}"
mk="$tmp/mk"; mkdir -p "$mk"
res "${dg}:zot" "" zot 0.1.135 testing quiet "" "" "" https://example.test/run/8 > "$mk/lines"
GH_DATE=2026-11-15 bash "$D" report "$mk" "$tmp/quoted.json" 2026-11-15 > "$tmp/mk.json" || fail "report: $(cat "$tmp/mk.json")"
[ "$(jq -c '[.[] | select(.op == "edit" or .op == "create" or .op == "close") | .issue // .key]' "$tmp/mk.json")" = "[${dg}]" ] \
  || fail "a quoted summary marker took the summary's place: $(jq -c '[.[] | [.op, .issue // .key]]' "$tmp/mk.json")"
gh issue list --state all > "$tmp/all.json"
jq --slurpfile q "$tmp/quoted.json" '. + [$q[0][] | select(.number >= 900) | .state = "OPEN"]' "$tmp/all.json" > "$tmp/all-quoted.json"
GH_DATE=2026-11-15 bash "$D" file "$f2" "$tmp/all-quoted.json" 2026-11-15 > "$tmp/mk.json" || fail "file: $(cat "$tmp/mk.json")"
jq -e --argjson d "$dg" 'all(.[]; (.op != "edit" and .op != "close") or .issue == $d or .issue == 901)' "$tmp/mk.json" >/dev/null \
  && ! jq -e 'any(.[]; .op == "create")' "$tmp/mk.json" >/dev/null || fail "the daily run took a quoted marker for a summary or a major versions issue: $(jq -c '[.[] | [.op, .issue // .key]]' "$tmp/mk.json")"
echo "ok - the markers: a runr-deps: marker is no data marker (an issue with one only has no updates), one quoted in a text makes no summary or major versions issue (only the body's last line does)"

# --- the test bot's results ------------------------------------------------------------------------
# a run starts: its rows testing, nothing else (a later week: the open summary edited, none opened)
r0="$tmp/r0"; mkdir -p "$r0"
{ res "${dg}:zot" "" zot 0.1.135 testing quiet "" "" "" https://example.test/run/9; res "${dg}:go-cli" "" go-cli 22222222 testing quiet "" "" "" https://example.test/run/9; } > "$r0/lines"
: > "$GH_LOG"
report 2026-11-21 "$r0"
has_row "$dg" '["zot","0.1.135","testing",null]' && has_row "$dg" '["go-cli","22222222","testing",null]' || fail "testing: $(rows "$dg")"
grep -qxF '| `zot` <!-- runr-deps:row:zot --> | 0.1.135 | testing | [run](https://example.test/run/9) |  |' <<<"$(body_of "$dg")" || fail "the testing row: $(body_of "$dg")"
! grep -qF '[issue] [create]' "$GH_LOG" || fail "a run that starts opened a summary: $(cat "$GH_LOG")"
# the results: zot and go-agent shipped, go-cli red (an issue of its own), go-proxy green (tested
# again), composer-api's release needs a person
r1="$tmp/r1"; mkdir -p "$r1"
printf '**Next update required: go-cli 22222222 failed on next.**\n\nThe failing suite: e2e.\n' > "$r1/red.md"
printf '**Needs attention: composer-api passed, but its release v0.7.1 did not finish.**\n' > "$r1/fail.md"
{
  res "${dg}:zot" chart zot 0.1.135 shipped line "" "" v0.7.1 https://example.test/releases/v0.7.1
  res "${dg}:go-agent" group go-agent 33333333 shipped line "" "" v0.7.1 https://example.test/releases/v0.7.1
  res "${dg}:go-cli" group go-cli 22222222 "next update required" split "failed: e2e" "$r1/red.md" "" https://example.test/run/10
  res "${dg}:go-proxy" group go-proxy 3063d268 green line "the base moved under it: tested again, attempt 2 of 3" "" "" https://example.test/run/10
  res "${dg}:composer-api" group composer-api 27340472 "needs attention" incident "its release v0.7.1 did not finish" "$r1/fail.md" "" https://example.test/run/10
} > "$r1/lines"
printf 'dispatch\t%s:go-proxy\t2\n' "$dg" > "$r1/actions"
: > "$GH_LOG"
report 2026-11-21 "$r1"
dg2="$(digest_of 2026-W47)"
[ -n "$dg2" ] && [ "$(state_of "$dg")" = CLOSED ] && grep -qxF "Continued in #${dg2}." <<<"$(body_of "$dg")" && [ "$(body_of "$dg" | tail -n 1)" = '<!-- runr-deps:digest:2026-W46 -->' ] \
  || fail "a result in a later week: its summary, the last one closed: $(state '[.[] | [.number, .title, .state]]') $(body_of "$dg" | tail -n 4)"
split="$(number_of "deps: go-cli [22222222]")"
inc="$(number_of "deps: a dependency test run needs a person (2026-W47)")"
[ -n "$split" ] && [ "$(labels_of "$split")" = '["deps","next update required"]' ] && body_of "$split" | grep -qF 'failed on next' \
  && body_of "$split" | grep -qF 'From the weekly summary of 2026-W47: `next update required`. The dependency test bot tries it again on every weekly run' \
  && body_of "$split" | grep -q '<!-- deps-bot {"group":"go-cli","kind":"group","version":"22222222","separate":true' || fail "the row that failed: its issue: $(state '[.[] | [.number, .title]]') $(body_of "${split:-0}")"
[ -n "$inc" ] && [ "$(labels_of "$inc")" = '["deps","needs attention"]' ] && body_of "$inc" | grep -qF 'its release v0.7.1 did not finish' \
  && body_of "$inc" | grep -qxF '<!-- runr-deps:incident -->' || fail "the run that needs a person: no issue: $(state '[.[] | [.number, .title]]')"
[ "$(rows "$dg2")" = "[[\"composer-api\",\"27340472\",\"needs attention\",${inc}],[\"go-agent\",\"33333333\",\"shipped\",null],[\"go-cli\",\"22222222\",\"next update required\",${split}],[\"go-proxy\",\"3063d268\",\"green\",null],[\"go-queue-consumer\",\"9e8b508b\",\"new\",null],[\"npm-vue--vite\",\"aaaa0001\",\"new\",null],[\"npm-vue/cronstrue\",\"4.x\",\"held\",null],[\"traefik\",\"42.3.2\",\"new\",null],[\"zot\",\"0.1.135\",\"shipped\",null]]" ] \
  || fail "the later week's summary: $(rows "$dg2")"
b="$(body_of "$dg2")"
grep -qxF '| `zot` <!-- runr-deps:row:zot --> | 0.1.135 | green → shipped in v0.7.1 | [v0.7.1](https://example.test/releases/v0.7.1) |  |' <<<"$b" \
  && grep -qxF "| \`go-cli\` <!-- runr-deps:row:go-cli --> | \`github.com/spf13/cobra\` v1.8.1 → v1.10.2 | next update required | #${split} | failed: e2e |" <<<"$b" \
  && grep -qxF '| `go-proxy` <!-- runr-deps:row:go-proxy --> | `github.com/redis/go-redis/v9` v9.7.3 → v9.23.0 | green | [run](https://example.test/run/10) | the base moved under it: tested again, attempt 2 of 3 |' <<<"$b" \
  && grep -qxF "Continued from #${dg}." <<<"$b" || fail "the later week's body: ${b}"
sec="$(sed -n '/^### Next update required$/,/^<!-- deps-bot /{/^<!-- deps-bot /!p}' <<<"$b")"
grep -qxF -e "- \`go-cli\` \`github.com/spf13/cobra\` v1.8.1 → v1.10.2: failed: e2e (#${split}, [run](https://example.test/run/10))" <<<"$sec" \
  && grep -qF -- '- `npm-vue/cronstrue` 3.38.0 → 4.0.0: held, ' <<<"$sec" && [ "$(grep -c '^- ' <<<"$sec")" = 2 ] \
  && ! grep -qF 'composer-api' <<<"$sec" || fail "the section Next update required: ${sec}"
grep -qF "gh [workflow] [run] [deps-test.yml] [--repo] [example/repo] [--ref] [main] [-f] [issue=${dg}:go-proxy] [-f] [attempt=2]" "$GH_LOG" || fail "the dispatch: $(cat "$GH_LOG")"
[ ! -s "$GH_COMMENTS" ] || fail "a comment on a summary: $(cat "$GH_COMMENTS")"
# the plan: the row that failed through its issue on every run (next update required: retry), the
# row that needs a person only when named
gh issue list > "$tmp/open.json"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json")"
[ "$(jq -c '[.tests[].ref]' <<<"$plan")" = "[\"${split}\",\"${dg2}:go-proxy\",\"${dg2}:go-queue-consumer\",\"${dg2}:npm-vue--vite\",\"${dg2}:traefik\"]" ] \
  && jq -e '.tests[] | select(.ref == "'"$split"'") | .retry == true and (.updates | length) == 1' >/dev/null <<<"$plan" \
  && jq -e '.skipped[] | select(.ref == "'"${dg2}:composer-api"'") | .reason | test("waits for a person")' >/dev/null <<<"$plan" \
  && [ "$(jq -c '.relabel' <<<"$plan")" = '[]' ] && jq -e 'has("retrigger") | not' >/dev/null <<<"$plan" \
  || fail "the plan after the results: ${plan}"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json" --only="${dg2},${split}")"
[ "$(jq -c '[.tests[].ref]' <<<"$plan")" = "[\"${dg2}:composer-api\",\"${split}\",\"${dg2}:go-proxy\",\"${dg2}:go-queue-consumer\",\"${dg2}:npm-vue--vite\",\"${dg2}:traefik\"]" ] \
  && jq -e 'has("retrigger") | not' >/dev/null <<<"$plan" && [ "$(jq -c '.tests[] | select(.dep == "go-cli") | (.updates | length)' <<<"$plan")" = 1 ] \
  || fail "the summary named: ${plan}"
# tested again: the red one shipped (its issue's own actions, its row mirrors it), the one that
# needed a person green: the run's issue closes by itself
r2="$tmp/r2"; mkdir -p "$r2"
printf 'released\n' > "$r2/c.md"
{
  res "${split}" group go-cli 22222222 shipped line "" "" v0.7.2 https://example.test/releases/v0.7.2
  res "${dg2}:composer-api" group composer-api 27340472 green line "passed alone; this run's release stops before it: the next run tests it again" "" "" https://example.test/run/11
} > "$r2/lines"
printf 'comment\t%s\t%s\nlabel\t%s\tdeps: passed\nclose\t%s\n' "$split" "$r2/c.md" "$split" "$split" > "$r2/actions"
report 2026-11-22 "$r2"
has_row "$dg2" "[\"go-cli\",\"22222222\",\"shipped\",${split}]" && has_row "$dg2" '["composer-api","27340472","green",null]' || fail "the results of the rows tested again: $(rows "$dg2")"
[ "$(state_of "$split")" = CLOSED ] && grep -qxF "$(printf '%s\treleased ' "$split")" "$GH_COMMENTS" || fail "the red issue's own actions"
[ "$(state_of "$inc")" = CLOSED ] && body_of "$inc" | grep -qF 'Nothing it held is open any more' || fail "the run's issue stays open with nothing it holds"
echo "ok - the test bot's results: testing (no movement), a later week's summary with the open rows (the last one: Continued in #N.), green → shipped in vX.Y.Z, green, a row that failed an issue of its own labelled next update required (listed under Next update required), a run that needs a person one issue (closed once its rows moved on), the mirror of an issue of its own, the dispatch, no comment on a summary; the plan tests an issue labelled next update required on every run and names the rows that need a person only when asked"

# --- a quiet week keeps the summary; movement carries the open rows over ---------------------------
f3="$tmp/f3"; cp -r "$f2" "$f3"
rm -f "$f3"/deps-go-agent-*.md "$f3"/deps-zot-*.md "$f3"/deps-go-cli-*.md
printf '%s\n' npm-vue go-proxy go-example-wasm > "$f3/checked"
: > "$GH_LOG"
run 2026-11-30 "$f3"
grep -qF '[issue] [create]' "$GH_LOG" && fail "a quiet week opened a summary: $(cat "$GH_LOG")"
[ "$(state_of "$dg2")" = OPEN ] || fail "a quiet week closed a summary with open rows"
chart "$f3" zot 0.1.136
run 2026-12-01 "$f3"
dg3="$(digest_of 2026-W49)"
[ -n "$dg3" ] && [ "$(state_of "$dg2")" = CLOSED ] && grep -qxF "Continued in #${dg3}." <<<"$(body_of "$dg2")" || fail "movement in a later week: $(state '[.[] | [.number, .title, .state]]')"
[ "$(rows "$dg3" | jq -c 'map(.[0:3])')" = '[["composer-api","27340472","green"],["go-proxy","3063d268","green"],["go-queue-consumer","9e8b508b","new"],["npm-vue--vite","aaaa0001","new"],["npm-vue/cronstrue","4.x","held"],["traefik","42.3.2","new"],["zot","0.1.136","new"]]' ] \
  || fail "the carried rows: $(rows "$dg3")"
echo "ok - a quiet week opens no summary and keeps the open one; movement opens the week's summary with the open rows (the resolved ones stay behind)"

# --- a release outside a constraint the check no longer reports; no major versions issue ------------
f4="$tmp/f4"; cp -r "$f3" "$f4"
# a derived row of a group that was not read stays; its group read without it: closed
grep -vx npm-vue "$f4/checked" > "$f4/checked.tmp"; mv "$f4/checked.tmp" "$f4/checked"
rm -f "$f4"/deps-npm-vue--vite-*.md
run 2026-12-02 "$f4"
has_row "$dg3" '["npm-vue--vite","aaaa0001","new",null]' || fail "a release outside a constraint of a group that was not read left: $(rows "$dg3")"
echo npm-vue >> "$f4/checked"
run 2026-12-03 "$f4"
has_row "$dg3" '["npm-vue--vite","aaaa0001","closed",null]' && grep -qF '| closed |  | no longer found by the check of 2026-12-03 |' <<<"$(body_of "$dg3")" \
  || fail "a release outside a constraint its group's check no longer reports: not closed: $(rows "$dg3")"
[ "$(state '[.[] | select(.title | test("major versions need a decision$")) | select(.state == "OPEN")] | length')" = 0 ] && [ "$(state_of 32)" = OPEN ] \
  || fail "a major versions issue, or the single major labelled next update required touched: $(state '[.[] | [.number, .title, .state]]')"
rm -rf "$f4/held"
run 2026-12-04 "$f4"
rows "$dg3" | jq -e 'map(select(.[2] == "held")) | length == 0' >/dev/null || fail "a hold that is gone stays: $(rows "$dg3")"
# a major versions issue retired with no summary to point at: the text names none
jq -n --arg b "$MAJORS_BODY" '[{number: 7, title: "deps: npm-vue major versions need a decision", state: "OPEN", labels: [{name: "deps"}], body: $b, createdAt: "2026-11-01T05:30:00Z"}]' > "$tmp/lone.json"
mkdir -p "$tmp/empty"
bash "$D" file "$tmp/empty" "$tmp/lone.json" 2026-12-04 > "$tmp/lone-ops.json" || fail "file: $(cat "$tmp/lone-ops.json")"
[ "$(jq -c '[.[] | [.op, .issue // .key]]' "$tmp/lone-ops.json")" = '[["edit",7],["close",7]]' ] \
  && jq -e '.[0].body | contains("each one is a row of the weekly summary; one it cannot move") and (contains("{{") | not)' "$tmp/lone-ops.json" >/dev/null \
  || fail "a major versions issue retired without a summary: $(cat "$tmp/lone-ops.json")"
# the earlier bot's rows of an issue of its own (red, needs attention) whose issue is labelled: next
# update required, in the section
m84="$(jq -nc '{id: "traefik", kind: "chart", group: "traefik", version: "42.4.0", title: "deps: traefik 42.4.0 released", issue: 84, item_kind: "issue", state: "needs attention", note: "no mechanical bump: a person moves it", found: "2026-12-01"}')"
m80="$(jq -nc '{id: "docker", kind: "group", group: "docker", version: "ee4ab2fa", title: "deps: docker [ee4ab2fa]: 1 update: x 3.22", issue: 80, item_kind: "issue", state: "red", note: "failed on v0.7.3", found: "2026-12-01", updates: [{name: "x", from: "3.21", to: "3.22"}]}')"
jq -n --arg b "$(printf 'Summary.\n\n<!-- deps-bot %s -->\n<!-- deps-bot %s -->\n\n<!-- runr-deps:digest:2026-W49 -->\n' "$m84" "$m80")" \
  '[{number: 60, title: "deps: week 2026-W49", state: "OPEN", labels: [{name: "deps"}], body: $b, createdAt: "2026-12-01T05:30:00Z"},
    {number: 84, title: "deps: traefik 42.4.0 released", state: "OPEN", labels: [{name: "needs attention"}], body: "x", createdAt: "2026-12-01T05:30:00Z"},
    {number: 80, title: "deps: docker [ee4ab2fa]: 1 update: x 3.22", state: "OPEN", labels: [{name: "deps"}, {name: "next update required"}], body: "x", createdAt: "2026-12-01T05:30:00Z"}]' > "$tmp/legacy.json"
bash "$D" file "$tmp/empty" "$tmp/legacy.json" 2026-12-04 > "$tmp/legacy-ops.json" || fail "file: $(cat "$tmp/legacy-ops.json")"
lb="$(jq -r '.[] | select(.op == "edit" and .issue == 60) | .body' "$tmp/legacy-ops.json")"
grep -qF '| `traefik` <!-- runr-deps:row:traefik --> | 42.4.0 | next update required | #84 | no mechanical bump: a person moves it |' <<<"$lb" \
  && grep -qF '| next update required | #80 | failed on v0.7.3 |' <<<"$lb" \
  && grep -qxF -e '- `traefik` 42.4.0: no mechanical bump: a person moves it (#84)' <<<"$lb" && grep -qxF -e '- `docker` `x` 3.21 → 3.22: failed on v0.7.3 (#80)' <<<"$lb" \
  || fail "the earlier bot's rows of a labelled issue: $(cat "$tmp/legacy-ops.json")"
echo "ok - a release outside a constraint: its row closed once the check of its group no longer reports it (kept while the group is not read); no major versions issue made, one retired without a summary names none; a hold that is gone leaves the summary; the earlier bot's red and needs attention rows of a labelled issue: next update required"

# --- rows that wait for a run that needs a person, shipped by a person's release ----------------------
# (the W47 run's issue, #91: the bot could not release, a manual release then took the updates) the
# check of their group no longer reports them: closed, and the run's issue closes once none of its
# rows waits; a row of a group that was not read keeps waiting, and a row of an issue of its own
# (next update required) is the weekly run's to retry
i91="$(jq -nc '{id: "kata-deploy", kind: "chart", group: "kata-deploy", version: "4.4.0", title: "deps: kata-deploy 4.4.0 released", state: "needs attention", found: "2026-11-18", issue: 91, url: "https://example.test/run/44", note: "the bot cannot release now", item_kind: "incident"}')"
i91b="$(jq -nc '{id: "actions", kind: "group", group: "actions", version: "8e575e46", title: "deps: actions [8e575e46]: 1 update: actions/setup-go v7.1.0", state: "needs attention", found: "2026-11-19", updates: [{name: "actions/setup-go", from: "v7.0.0", to: "v7.1.0"}], issue: 91, url: "https://example.test/run/44", note: "the bot cannot release now", item_kind: "incident"}')"
i91c="$(jq -nc '{id: "zot", kind: "chart", group: "zot", version: "0.1.135", title: "deps: zot 0.1.135 released", state: "needs attention", found: "2026-11-20", issue: 91, url: "https://example.test/run/44", note: "the bot cannot release now", item_kind: "incident"}')"
i80="$(jq -nc '{id: "docker", kind: "group", group: "docker", version: "ee4ab2fa", title: "deps: docker [ee4ab2fa]: 1 update: x 3.22", issue: 80, item_kind: "issue", state: "next update required", note: "failed on v0.7.3", found: "2026-11-13", updates: [{name: "x", from: "3.21", to: "3.22"}]}')"
jq -n --arg b "$(printf 'Summary.\n\n<!-- deps-bot %s -->\n<!-- deps-bot %s -->\n<!-- deps-bot %s -->\n<!-- deps-bot %s -->\n\n<!-- runr-deps:digest:2026-W48 -->\n' "$i91" "$i91b" "$i91c" "$i80")" \
  '[{number: 92, title: "deps: week 2026-W48", state: "OPEN", labels: [{name: "deps"}], body: $b, createdAt: "2026-11-23T05:30:00Z"},
    {number: 91, title: "deps: a dependency test run needs a person (2026-W47)", state: "OPEN", labels: [{name: "deps"}, {name: "needs attention"}],
     body: "**The bot cannot release now:** x\n\n<!-- runr-deps:incident -->\n", createdAt: "2026-11-21T14:57:00Z"},
    {number: 80, title: "deps: docker [ee4ab2fa]: 1 update: x 3.22", state: "OPEN", labels: [{name: "deps"}, {name: "next update required"}], body: "x", createdAt: "2026-11-13T05:30:00Z"}]' > "$tmp/w48.json"
mkdir -p "$tmp/f48"
# zot's chart was not read: its row keeps waiting, and so does the run's issue
printf '%s\n' kata-deploy actions docker > "$tmp/f48/checked"
bash "$D" file "$tmp/f48" "$tmp/w48.json" 2026-11-28 > "$tmp/w48-ops.json" || fail "file: $(cat "$tmp/w48-ops.json")"
wb="$(jq -r '.[] | select(.op == "edit" and .issue == 92) | .body' "$tmp/w48-ops.json")"
grep -qxF '| `kata-deploy` <!-- runr-deps:row:kata-deploy --> | 4.4.0 | closed | [run](https://example.test/run/44) | no longer found by the check of 2026-11-28 |' <<<"$wb" \
  && grep -qF '| `actions` <!-- runr-deps:row:actions --> | `actions/setup-go` v7.0.0 → v7.1.0 | closed | [run](https://example.test/run/44) | no longer found by the check of 2026-11-28 |' <<<"$wb" \
  && grep -qxF '| `zot` <!-- runr-deps:row:zot --> | 0.1.135 | needs attention | #91 | the bot cannot release now |' <<<"$wb" \
  && grep -qF '| `docker` <!-- runr-deps:row:docker --> | `x` 3.21 → 3.22 | next update required | #80 | failed on v0.7.3 |' <<<"$wb" \
  && ! jq -e 'any(.[]; .issue == 91 or .issue == 80)' "$tmp/w48-ops.json" >/dev/null \
  || fail "the rows of a run that needs a person, gone from the check: $(cat "$tmp/w48-ops.json")"
# zot read too: nothing of the run's issue waits any more, it closes; the issue of its own stays
echo zot >> "$tmp/f48/checked"
bash "$D" file "$tmp/f48" "$tmp/w48.json" 2026-11-28 > "$tmp/w48-ops.json" || fail "file: $(cat "$tmp/w48-ops.json")"
wb="$(jq -r '.[] | select(.op == "edit" and .issue == 92) | .body' "$tmp/w48-ops.json")"
grep -qxF '| `zot` <!-- runr-deps:row:zot --> | 0.1.135 | closed | [run](https://example.test/run/44) | no longer found by the check of 2026-11-28 |' <<<"$wb" \
  && [ "$(jq -c '[.[] | select(.issue == 91) | .op]' "$tmp/w48-ops.json")" = '["edit","close"]' ] \
  && jq -e '.[] | select(.op == "edit" and .issue == 91) | .body | contains("Nothing it held is open any more")' "$tmp/w48-ops.json" >/dev/null \
  && ! jq -e 'any(.[]; .issue == 80)' "$tmp/w48-ops.json" >/dev/null && ! grep -qF '"issue":91' <<<"$(grep -o '<!-- deps-bot [^>]*' <<<"$wb")" \
  || fail "the run's issue with nothing that waits for it: $(cat "$tmp/w48-ops.json")"
echo "ok - rows that wait for a run that needs a person: closed once the check of their group no longer reports them (a release has them), the run's issue closed with them; a row of a group that was not read, and one of an issue of its own, untouched"

# --- landed on next, then shipped; a later week closes a summary with nothing open ---------------------
r3="$tmp/r3"; mkdir -p "$r3"
{
  res "${dg3}:go-proxy" group go-proxy 3063d268 landed line "" "" "" https://example.test/run/12
  res "${dg3}:composer-api" group composer-api 27340472 shipped line "" "" v0.7.2 https://example.test/releases/v0.7.2
  res "${dg3}:go-queue-consumer" group go-queue-consumer 9e8b508b closed line "the last release has it" "" "" https://example.test/run/12
  res "${dg3}:traefik" chart traefik 42.3.2 shipped line "" "" v0.7.2 https://example.test/releases/v0.7.2
  res "${dg3}:zot" chart zot 0.1.136 shipped line "" "" v0.7.2 https://example.test/releases/v0.7.2
} > "$r3/lines"
report 2026-12-05 "$r3"
grep -qxF '| `go-proxy` <!-- runr-deps:row:go-proxy --> | `github.com/redis/go-redis/v9` v9.7.3 → v9.23.0 | green | [run](https://example.test/run/12) | landed on next: ships with the next release |' <<<"$(body_of "$dg3")" \
  || fail "the landed row: $(body_of "$dg3")"
rm -f "$f4"/deps-go-proxy-*.md "$f4"/deps-zot-*.md
RELEASE=0.8.0 run 2026-12-06 "$f4"
has_row "$dg3" '["go-proxy","3063d268","shipped",null]' && grep -qF '| green → shipped in v0.8.0 |' <<<"$(body_of "$dg3")" || fail "landed, then gone from the check: not shipped: $(rows "$dg3")"
: > "$GH_LOG"
run 2026-12-08 "$f4"
[ "$(state_of "$dg3")" = CLOSED ] && grep -qxF 'Closed at the end of the week: nothing was left open.' <<<"$(body_of "$dg3")" && ! grep -qF '[issue] [create]' "$GH_LOG" \
  || fail "a later week: the summary with nothing open not closed, or a new one opened: $(state '[.[] | [.number, .title, .state]]')"
echo "ok - a row landed on next is shipped in the last release once the check no longer reports it; a later week closes a summary with nothing open and opens none"

# --- apply: in order, {{key}} as the created number, one that needs a missing number skipped ----------
jq -n '[{op: "create", key: "a", title: "deps: x [aaaaaaaa]: 1 update: y 1", labels: ["deps"], body: "x"},
        {op: "create", key: "digest", title: "deps: week 2027-W01", labels: [], body: "see #{{a}}"},
        {op: "comment", issue: "{{a}}", body: "hello #{{digest}}"},
        {op: "edit", issue: "{{nope}}", title: "t", body: "b"},
        {op: "dispatch", refs: "{{digest}}:zot,5", attempt: "1", ref: null}]' > "$tmp/apply.json"
: > "$GH_LOG"; : > "$GH_COMMENTS"
rc=0
bash "$D" apply "$tmp/apply.json" > "$tmp/apply.log" 2>&1 || rc=$?
a="$(number_of "deps: x [aaaaaaaa]")"
w="$(digest_of 2027-W01)"
[ "$rc" = 1 ] && [ "$(body_of "$w")" = "see #${a}" ] && grep -qxF "$(printf '%s\thello #%s ' "$a" "$w")" "$GH_COMMENTS" \
  && grep -qF "[issue=${w}:zot,5]" "$GH_LOG" && ! grep -qF '[t]' "$GH_LOG" || fail "apply: rc ${rc}, $(cat "$tmp/apply.log" "$GH_LOG")"
echo "ok - apply: the operations in order, a created issue's number for its {{key}}, one whose issue was not created skipped (exit 1)"
