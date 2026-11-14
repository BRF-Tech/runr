#!/usr/bin/env bash
# Tests the dependency bots' weekly summary (ci/deps-digest.sh, ci/deps/digest.jq; the format shared
# with filex's bot) and the test bot's plan of its rows (ci/deps-plan.sh) without GitHub: gh is a
# stub that keeps the issues in a JSON file (as gh issue list writes them), the findings are files
# as ci/check-*-releases.sh writes them. Checks that:
#   - the first run folds the findings filed as issues of their own into the week's summary
#     ("Folded into the weekly summary #N: …" in their bodies, closed), a security one too; the
#     single releases outside a constraint into one "major versions" issue per group; a newer
#     finding supersedes a red issue; a finding an issue or a summary had is not listed again; a
#     major a hold keeps is a row "held (<reason>)", no issue; the rows, columns and markers;
#   - the same week: a new finding edits the summary; a security finding is a row flagged
#     "security: <advisory>", tested the same day (a dispatch of <summary>:<group>);
#   - the test bot's results: a run that starts marks its rows testing (no movement, no summary); a
#     result in a later week opens its summary with the open rows ("Continued from"), the last one
#     closed ("Continued in #N." in its body); green → shipped in vX.Y.Z, green, a red row moved to
#     an issue of its own the plan reads, a release that needs a person one issue for the run,
#     closed by itself once its rows moved on; the mirror of an issue of its own; the dispatch; no
#     comment on a summary;
#   - the plan: a summary's open rows by <summary>:<group>, one that needs attention only when named
#     (the summary's number names them all, <any>:<group> the row wherever it carried over);
#   - a quiet week opens no summary and keeps the open one; movement carries the open rows over;
#   - a group's major versions: one issue, its list edited, reopened when closed by hand while not
#     empty, closed when empty, an unchecked group's kept; a hold that is gone leaves the summary;
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
# outside DIR GROUP NAME LINE TO CONSTRAINT — a release outside the constraint
outside() {
  printf 'deps: %s %s %s released (outside %s)\n\n**%s** %s is out.\n\n<!-- deps-bot %s -->\n' "$2" "$3" "$4" "$6" "$3" "$5" \
    "$(jq -nc --arg g "$2" --arg n "$3" --arg l "$4" --arg t "$5" --arg c "$6" '{group: $g, kind: "outside", name: $n, line: $l, from: "1.0.0", to: $t, constraint: $c, where: "x/package.json", why: "a new major"}')" \
    > "$1/deps-$2-outside-${3//\//-}-$4.md"
}
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
outside "$f1" npm-vue vite 8.x 8.0.1 '^6.4.5'
outside "$f1" npm-vue pinia 4.x 4.0.0 '^2.2'
outside "$f1" go-example-wasm github.com/x/sdk/v3 v3.x v3.0.0 github.com/x/sdk/v2
held "$f1" npm-vue cronstrue 4.0.0 4.x "pinned exactly in vue/package.json: the warmer editor's tests compare its wording"
printf '%s\n' npm-vue go-proxy go-cli go-example-wasm zot > "$f1/checked"
run 2026-11-14 "$f1"
dg="$(digest_of 2026-W46)"
[ -n "$dg" ] && [ "$(labels_of "$dg")" = '["deps"]' ] || fail "no summary: $(state '[.[] | [.number, .title, .state]]')"
on="$(number_of "deps: npm-vue major versions need a decision")"
ow="$(number_of "deps: go-example-wasm major versions need a decision")"
[ -n "$on" ] && [ -n "$ow" ] && [ "$(labels_of "$on")" = '["deps","needs attention"]' ] \
  || fail "one major versions issue per group: $(state '[.[] | [.number, .title, .state]]')"
grep -qxF '| `pinia` | 1.0.0 | 4.0.0 | `^2.2` |' <<<"$(body_of "$on")" && grep -qxF '| `vite` | 1.0.0 | 8.0.1 | `^6.4.5` |' <<<"$(body_of "$on")" \
  && grep -qxF '| Dependency | Pinned | Newest major | allow |' <<<"$(body_of "$on")" && grep -qxF '<!-- runr-deps:majors:npm-vue -->' <<<"$(body_of "$on")" \
  || fail "the major versions issue's body: $(body_of "$on")"
[ "$(rows "$dg")" = '[["composer-api","27340472","new",null],["go-cli","22222222","new",null],["go-proxy","3063d268","new",null],["go-queue-consumer","9e8b508b","new",null],["npm-vue/cronstrue","4.x","held",null],["traefik","42.3.2","new",null],["zot","0.1.135","new",null]]' ] \
  || fail "the first summary's rows: $(rows "$dg")"
[ "$(state '[.[] | select(.state == "OPEN") | .number] | sort')" = "$(jq -nc "[50, ${dg}, ${on}, ${ow}] | sort")" ] \
  || fail "the open issues after the first run: $(state '[.[] | select(.state == "OPEN") | [.number, .title]]')"
for n in 15 20 34 35; do
  [ "$(body_of "$n" | tail -n 1)" = "Folded into the weekly summary #${dg}: the bot opens a separate issue only when a person has to do something." ] \
    || fail "#${n} folded: $(body_of "$n")"
done
body_of 36 | grep -qF "Superseded by go-cli 22222222 (the check of 2026-11-14), a row of the weekly summary #${dg}" || fail "the red issue superseded: $(body_of 36)"
body_of 31 | grep -qF "Folded into #${ow}: the bot keeps one issue per group for its major versions" || fail "the single major folded: $(body_of 31)"
[ ! -s "$GH_COMMENTS" ] || fail "the bot wrote comments: $(cat "$GH_COMMENTS")"
b="$(body_of "$dg")"
grep -qxF '| Dependency | Version | State | Item | Note |' <<<"$b" \
  && grep -qxF '| `go-proxy` <!-- runr-deps:row:go-proxy --> | `github.com/redis/go-redis/v9` v9.7.3 → v9.23.0 | new |  |  |' <<<"$b" \
  && grep -qxF '| `npm-vue/cronstrue` <!-- runr-deps:row:npm-vue/cronstrue --> | 3.38.0 → 4.0.0 | held (pinned exactly in vue/package.json: the warmer editor'"'"'s tests compare its wording) |  |  |' <<<"$b" \
  && grep -qxF '| `go-queue-consumer` <!-- runr-deps:row:go-queue-consumer --> | `github.com/jackc/pgx/v5` v5.12.0 → v5.13.0 | new |  | security: GHSA-test |' <<<"$b" \
  && grep -qxF '| `zot` <!-- runr-deps:row:zot --> | 0.1.135 | new |  |  |' <<<"$b" \
  && [ "$(tail -n 1 <<<"$b")" = '<!-- runr-deps:digest:2026-W46 -->' ] && grep -qF '**2026-W46** (2026-11-09 to 2026-11-15, UTC)' <<<"$b" \
  || fail "the summary's body: ${b}"
grep -q 'workflow run' "$GH_LOG" && fail "a dispatch without a new security finding"
echo "ok - the first run: the issues of their own folded into the week's summary, the single majors into one issue per group, a red issue superseded, nothing listed twice, a held major a row; the columns, row and summary markers, no comment"

# --- the same week: a new finding edits the summary; a security finding is a flagged row, tested today
f2="$tmp/f2"; cp -r "$f1" "$f2"
group "$f2" go-agent 33333333 "$(upd k8s.io/api v0.37.1 v0.37.2)" true
: > "$GH_LOG"
run 2026-11-15 "$f2"
[ "$(state '[.[] | select(.title | startswith("deps: week"))] | length')" = 1 ] && ! grep -qF '[issue] [create]' "$GH_LOG" || fail "an issue for a security finding: $(cat "$GH_LOG")"
grep -qxF '| `go-agent` <!-- runr-deps:row:go-agent --> | `k8s.io/api` v0.37.1 → v0.37.2 | new |  | security: GHSA-test |' <<<"$(body_of "$dg")" || fail "the security row: $(body_of "$dg")"
grep -qF "gh [workflow] [run] [deps-test.yml] [--repo] [example/repo] [-f] [issue=${dg}:go-agent] [-f] [attempt=1]" "$GH_LOG" || fail "the security finding is not tested today: $(cat "$GH_LOG")"
: > "$GH_LOG"
run 2026-11-15 "$f2"
grep -q 'issue\] \[\(create\|edit\|close\|reopen\)\|workflow' "$GH_LOG" && fail "the same findings again changed something: $(cat "$GH_LOG")"
echo "ok - the same week: a new finding edits the summary; a security finding is a row flagged security: <advisory>, tested the same day; the same findings change nothing"

# --- the plan of the summary's rows ----------------------------------------------------------------
gh issue list > "$tmp/open.json"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json")"
[ "$(jq -c '[.tests[] | [.ref, .version]]' <<<"$plan")" = "[[\"${dg}:composer-api\",\"27340472\"],[\"${dg}:go-agent\",\"33333333\"],[\"${dg}:go-cli\",\"22222222\"],[\"${dg}:go-proxy\",\"3063d268\"],[\"${dg}:go-queue-consumer\",\"9e8b508b\"],[\"${dg}:traefik\",\"42.3.2\"],[\"${dg}:zot\",\"0.1.135\"]]" ] \
  || fail "the plan of the summary: $(jq -c .tests <<<"$plan")"
[ "$(jq -c '.tests[] | select(.dep == "go-agent") | [.issue, (.updates | length), .urgent]' <<<"$plan")" = "[${dg},1,true]" ] || fail "a security row's test: ${plan}"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json" --only="7:zot,${dg}:go-agent")"
[ "$(jq -c '[.tests[].ref]' <<<"$plan")" = "[\"${dg}:go-agent\",\"${dg}:zot\"]" ] || fail "a row named wherever it carried over: $(jq -c .tests <<<"$plan")"
rc=0; bash "$root/ci/deps-plan.sh" "$tmp/open.json" --only="${dg}:Zot" >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] || fail "a ref that is no N or N:group passed"
echo "ok - the plan: the summary's open rows by <summary>:<group> (a security one urgent), a row named wherever it carried over"

# --- the markers: the summary's, its rows' and the other runr-deps: markers are no data marker, and
# a marker a person quotes in a text (not the last line of the body) makes no summary ---------------
plan_before="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json" | jq -c '[.tests[].ref]')"
jq --argjson n 900 '. + [
  {number: $n, title: "A question about the summary", state: "OPEN", labels: [], createdAt: "2026-11-15T08:00:00Z",
   body: "Row `zot` <!-- runr-deps:row:zot --> of the summary <!-- runr-deps:digest:2026-W46 --> and <!-- runr-deps:majors:npm-vue -->\n<!-- runr-deps:digest:2026-W46 -->\nwhy?"},
  {number: ($n + 1), title: "deps: go-cli [abcdef12]: 1 update: github.com/spf13/cobra v1.10.9", state: "OPEN", labels: [], createdAt: "2026-11-15T08:00:00Z",
   body: "Only the row marker:\n<!-- runr-deps:row:go-cli -->\nand the summary marker <!-- runr-deps:digest:2026-W46 --> in the text."},
  {number: ($n + 2), title: "deps: kata-deploy 4.9.0 released", state: "OPEN", labels: [{name: "needs attention"}], createdAt: "2026-11-15T08:00:00Z",
   body: "x\n<!-- runr-deps:row:zot/0.1.200 -->"}]' "$tmp/open.json" > "$tmp/quoted.json"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/quoted.json")"
[ "$(jq -c '[.tests[].ref]' <<<"$plan")" = "$plan_before" ] \
  && jq -e '.skipped[] | select(.issue == 901) | .reason | test("no deps-bot marker")' >/dev/null <<<"$plan" \
  && jq -e '.skipped[] | select(.issue == 902) | .reason | test("waits for a person")' >/dev/null <<<"$plan" \
  && ! jq -e '[.tests[], .skipped[]] | any(.ref | startswith("900:") or startswith("901:"))' >/dev/null <<<"$plan" \
  || fail "the plan took a runr-deps: marker for a summary or a data marker: ${plan}"
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
printf '**Needs attention: go-cli 22222222 failed on next.**\n\nrun the workflow with this issue'"'"'s number.\n' > "$r1/red.md"
printf '**Needs attention: composer-api passed, but its release v0.7.1 did not finish.**\n' > "$r1/fail.md"
{
  res "${dg}:zot" chart zot 0.1.135 shipped line "" "" v0.7.1 https://example.test/releases/v0.7.1
  res "${dg}:go-agent" group go-agent 33333333 shipped line "" "" v0.7.1 https://example.test/releases/v0.7.1
  res "${dg}:go-cli" group go-cli 22222222 red split "failed on next" "$r1/red.md" "" https://example.test/run/10
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
[ -n "$split" ] && [ "$(labels_of "$split")" = '["deps","needs attention"]' ] && body_of "$split" | grep -qF 'failed on next' \
  && body_of "$split" | grep -q '<!-- deps-bot {"group":"go-cli","kind":"group","version":"22222222","separate":true' || fail "the red row's issue: $(state '[.[] | [.number, .title]]')"
[ -n "$inc" ] && [ "$(labels_of "$inc")" = '["deps","needs attention"]' ] && body_of "$inc" | grep -qF 'its release v0.7.1 did not finish' \
  && body_of "$inc" | grep -qxF '<!-- runr-deps:incident -->' || fail "the run that needs a person: no issue: $(state '[.[] | [.number, .title]]')"
[ "$(rows "$dg2")" = "[[\"composer-api\",\"27340472\",\"needs attention\",${inc}],[\"go-agent\",\"33333333\",\"shipped\",null],[\"go-cli\",\"22222222\",\"red\",${split}],[\"go-proxy\",\"3063d268\",\"green\",null],[\"go-queue-consumer\",\"9e8b508b\",\"new\",null],[\"npm-vue/cronstrue\",\"4.x\",\"held\",null],[\"traefik\",\"42.3.2\",\"new\",null],[\"zot\",\"0.1.135\",\"shipped\",null]]" ] \
  || fail "the later week's summary: $(rows "$dg2")"
b="$(body_of "$dg2")"
grep -qxF '| `zot` <!-- runr-deps:row:zot --> | 0.1.135 | green → shipped in v0.7.1 | [v0.7.1](https://example.test/releases/v0.7.1) |  |' <<<"$b" \
  && grep -qxF "| \`go-cli\` <!-- runr-deps:row:go-cli --> | \`github.com/spf13/cobra\` v1.8.1 → v1.10.2 | red | #${split} | failed on next |" <<<"$b" \
  && grep -qxF '| `go-proxy` <!-- runr-deps:row:go-proxy --> | `github.com/redis/go-redis/v9` v9.7.3 → v9.23.0 | green | [run](https://example.test/run/10) | the base moved under it: tested again, attempt 2 of 3 |' <<<"$b" \
  && grep -qxF "Continued from #${dg}." <<<"$b" || fail "the later week's body: ${b}"
grep -qF "gh [workflow] [run] [deps-test.yml] [--repo] [example/repo] [--ref] [main] [-f] [issue=${dg}:go-proxy] [-f] [attempt=2]" "$GH_LOG" || fail "the dispatch: $(cat "$GH_LOG")"
[ ! -s "$GH_COMMENTS" ] || fail "a comment on a summary: $(cat "$GH_COMMENTS")"
# the plan: the red row through its issue, the row that needs a person only when named
gh issue list > "$tmp/open.json"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json")"
[ "$(jq -c '[.tests[].ref]' <<<"$plan")" = "[\"${dg2}:go-proxy\",\"${dg2}:go-queue-consumer\",\"${dg2}:traefik\"]" ] \
  && jq -e '.skipped[] | select(.ref == "'"$split"'") | .reason | test("waits for a person")' >/dev/null <<<"$plan" \
  && jq -e '.skipped[] | select(.ref == "'"${dg2}:composer-api"'") | .reason | test("waits for a person")' >/dev/null <<<"$plan" \
  || fail "the plan after the results: ${plan}"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/open.json" --only="${dg2},${split}")"
[ "$(jq -c '[.tests[].ref]' <<<"$plan")" = "[\"${dg2}:composer-api\",\"${split}\",\"${dg2}:go-proxy\",\"${dg2}:go-queue-consumer\",\"${dg2}:traefik\"]" ] \
  && [ "$(jq -c .retrigger <<<"$plan")" = "[${split}]" ] && [ "$(jq -c '.tests[] | select(.dep == "go-cli") | (.updates | length)' <<<"$plan")" = 1 ] \
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
echo "ok - the test bot's results: testing (no movement), a later week's summary with the open rows (the last one: Continued in #N.), green → shipped in vX.Y.Z, green, a red row an issue of its own, a run that needs a person one issue (closed once its rows moved on), the mirror of an issue of its own, the dispatch, no comment on a summary; the plan names the rows that need a person only when asked"

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
[ "$(rows "$dg3" | jq -c 'map(.[0:3])')" = '[["composer-api","27340472","green"],["go-proxy","3063d268","green"],["go-queue-consumer","9e8b508b","new"],["npm-vue/cronstrue","4.x","held"],["traefik","42.3.2","new"],["zot","0.1.136","new"]]' ] \
  || fail "the carried rows: $(rows "$dg3")"
echo "ok - a quiet week opens no summary and keeps the open one; movement opens the week's summary with the open rows (the resolved ones stay behind)"

# --- a group's major versions ------------------------------------------------------------------------
f4="$tmp/f4"; cp -r "$f3" "$f4"
outside "$f4" npm-vue zod 4.x 4.0.0 '^3.25'
run 2026-12-02 "$f4"
grep -qxF '| `zod` | 1.0.0 | 4.0.0 | `^3.25` |' <<<"$(body_of "$on")" && [ "$(title_of "$on")" = "deps: npm-vue major versions need a decision" ] || fail "a new major: $(body_of "$on")"
gh issue close "$on" --repo example/repo
run 2026-12-02 "$f4"
[ "$(state_of "$on")" = OPEN ] || fail "closed by hand while its list is not empty: not reopened"
rm -f "$f4"/deps-npm-vue-outside-*.md
run 2026-12-03 "$f4"
[ "$(state_of "$on")" = CLOSED ] || fail "the list empty: the issue not closed"
grep -vx go-example-wasm "$f4/checked" > "$f4/checked.tmp"; mv "$f4/checked.tmp" "$f4/checked"
rm -f "$f4"/deps-go-example-wasm-outside-*.md
run 2026-12-04 "$f4"
[ "$(state_of "$ow")" = OPEN ] || fail "the issue of a group that was not read closed"
rm -rf "$f4/held"
run 2026-12-04 "$f4"
rows "$dg3" | jq -e 'map(select(.[2] == "held")) | length == 0' >/dev/null || fail "a hold that is gone stays: $(rows "$dg3")"
echo "ok - a group's major versions: one issue, its list edited, reopened when closed by hand while not empty, closed when empty, an unread group's kept; a hold that is gone leaves the summary"

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
