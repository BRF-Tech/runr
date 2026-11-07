#!/usr/bin/env bash
# Tests the dependency test bot's plan, run steps and landing (ci/deps-plan.sh, ci/deps-run.sh,
# ci/deps-land.sh) without GitHub or GitLab: the issues are a JSON file as `gh issue list` writes
# it, next is a bare git repository, the bump is a script that writes a report. Checks that:
#   - the plan takes every deps issue and the Knative release issue, tests only the newest version
#     of a dependency and supersedes the older ones, skips a passed one and one that waits for a
#     person unless the run names it (which takes the label off), and labels a Knative minor that
#     has no row;
#   - a run's bumps start from the same next, a bump is committed as deps/<dep> with the bot's
#     identity, and the jobs that test it are listed; a bump that needs a person or has nothing to
#     do is not;
#   - the outcome is green only when every suite of the dependency passed (a missing result is a
#     failure), and the issue actions follow it: green and landed → label, comment, close; green
#     but next moved → a comment and a new run of those issues, at most three in a row; red →
#     label and a comment with what failed; a bump that needs a person → label and its reasons;
#     nothing to do → closed;
#   - the landing pushes only a commit whose parent is next, as a fast-forward: a next that moved
#     is left as it is, and a commit on another parent is refused.
#
#   bash runtime/test/deps_bot_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export GIT_AUTHOR_NAME=someone GIT_AUTHOR_EMAIL=someone@example.test GIT_COMMITTER_NAME=someone GIT_COMMITTER_EMAIL=someone@example.test
export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1

# --- the plan ---------------------------------------------------------------------------------------
cat > "$tmp/issues.json" <<'EOF'
[
  {"number": 12, "title": "deps: traefik 42.3.1 released", "labels": []},
  {"number": 9, "title": "deps: traefik 42.2.0 released", "labels": [{"name": "needs attention"}]},
  {"number": 8, "title": "deps: kube-prometheus-stack 92.21.2 released", "labels": []},
  {"number": 14, "title": "deps: zot 0.1.131 released", "labels": [{"name": "needs attention"}]},
  {"number": 15, "title": "deps: loki-stack 2.10.4 released", "labels": [{"name": "deps: passed"}]},
  {"number": 16, "title": "Knative 1.25, 1.26 released: update KNATIVE_K8S_COMPAT", "labels": []},
  {"number": 17, "title": "Knative 1.27 is not in KNATIVE_K8S_COMPAT", "labels": []},
  {"number": 18, "title": "deps: nginx 1.0.0 released", "labels": []},
  {"number": 19, "title": "A bug report", "labels": []},
  {"number": 20, "title": "deps: traefik 42.10.0 released", "labels": []}
]
EOF
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/issues.json")"
[ "$(jq -c '[.tests[] | [.issue, .dep, .version]]' <<<"$plan")" = '[[16,"knative","1.26"],[8,"kube-prometheus-stack","92.21.2"],[20,"traefik","42.10.0"]]' ] \
  || fail "the tests: $(jq -c .tests <<<"$plan")"
[ "$(jq -c '[.superseded[] | [.issue, .by]] | sort' <<<"$plan")" = '[[9,20],[12,20]]' ] || fail "superseded: $(jq -c .superseded <<<"$plan")"
[ "$(jq -c '[.attention[].issue]' <<<"$plan")" = '[17]' ] || fail "attention: $(jq -c .attention <<<"$plan")"
[ "$(jq -c '.retrigger' <<<"$plan")" = '[]' ] || fail "retrigger: $(jq -c .retrigger <<<"$plan")"
jq -e '.skipped[] | select(.issue == 14) | .reason | test("waits for a person")' <<<"$plan" >/dev/null || fail "a held issue is not skipped: $plan"
jq -e '.skipped[] | select(.issue == 15) | .reason | test("passed")' <<<"$plan" >/dev/null || fail "a passed issue is not skipped: $plan"
jq -e '.skipped[] | select(.issue == 18) | .reason | test("does not know nginx")' <<<"$plan" >/dev/null || fail "an unknown chart: $plan"
echo "ok - the plan: the newest version of each dependency, the older ones superseded, passed and held ones skipped, a Knative minor without a row labelled"

plan="$(bash "$root/ci/deps-plan.sh" "$tmp/issues.json" --only=14,8)"
[ "$(jq -c '[.tests[].issue]' <<<"$plan")" = '[8,14]' ] || fail "--only: $(jq -c .tests <<<"$plan")"
[ "$(jq -c '.retrigger' <<<"$plan")" = '[14]' ] || fail "--only takes the label off: $(jq -c .retrigger <<<"$plan")"
plan="$(bash "$root/ci/deps-plan.sh" "$tmp/issues.json" --only=9)"
[ "$(jq -c '[.tests[].issue]' <<<"$plan")" = '[]' ] || fail "a superseded issue named: $(jq -c .tests <<<"$plan")"
rc=0
bash "$root/ci/deps-plan.sh" "$tmp/issues.json" --only='8;x' >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] || fail "--only takes numbers only"
echo "ok - a run that names a held issue tests it again and takes the label off; a superseded one stays superseded"

# --- the bumps of a run -----------------------------------------------------------------------------
remote="$tmp/origin.git"
git init -q --bare -b next "$remote"
next="$tmp/next"
git init -q -b next "$next"
mkdir -p "$next/ci"
# the bump of next: traefik and zot move, kube-prometheus-stack needs a person, loki-stack is there
cat > "$next/ci/deps-bump.sh" <<'EOF'
#!/usr/bin/env bash
dep="$1" version="$2" report="${3#--report=}"
mkdir -p "$report"
case "$dep" in
  traefik|zot)
    echo "${dep} ${version}" > "$(dirname "$0")/../pin-${dep}"
    echo "fix(regions): ${dep} chart ${version}" > "$report/commit-msg.txt"
    echo "the ${dep} bump" > "$report/summary.md"
    if [ "$dep" = traefik ]; then
      echo '[{"suite":"e2e","command":"test","k8s":"1.32.0","kvm":false,"env":""},{"suite":"upgrade","command":"test-helm-upgrade --from=v0.6.7","k8s":"1.32.0","kvm":false,"env":""}]' > "$report/suites.json"
    else
      echo '[{"suite":"e2e","command":"test","k8s":"1.32.0","kvm":false,"env":""}]' > "$report/suites.json"
    fi
    echo bumped > "$report/result"; exit 0 ;;
  kube-prometheus-stack) echo "**needs a person**: a new major" > "$report/summary.md"; echo attention > "$report/result"; exit 2 ;;
  loki-stack) echo "next pins it already" > "$report/summary.md"; echo current > "$report/result"; exit 3 ;;
  *) echo "cannot read the chart repository"; exit 1 ;;
esac
EOF
echo base > "$next/README"
echo none > "$next/pin-traefik"
echo none > "$next/pin-zot"
(cd "$next" && git add -A && git commit -q -m base && git push -q "$remote" next)
base="$(git -C "$next" rev-parse HEAD)"
cat > "$tmp/tests.json" <<'EOF'
[{"issue": 20, "dep": "traefik", "version": "42.3.1"}, {"issue": 8, "dep": "kube-prometheus-stack", "version": "93.0.0"},
 {"issue": 14, "dep": "zot", "version": "0.1.131"}, {"issue": 15, "dep": "loki-stack", "version": "2.10.3"},
 {"issue": 21, "dep": "kata-deploy", "version": "4.3.2"}]
EOF
dir="$tmp/deps"
DEPS_BOT_NAME="RunR bot" DEPS_BOT_EMAIL="bot@example.test" bash "$root/ci/deps-run.sh" bumps "$tmp/tests.json" "$next" "$dir" https://example.test/run/1 > "$tmp/out" 2>&1 \
  || fail "bumps: $(cat "$tmp/out")"
for dep in traefik zot; do
  head="$(cat "$dir/bump/$dep/head")"
  [ "$(git -C "$next" rev-parse "deps/$dep")" = "$head" ] || fail "${dep}: no branch deps/${dep}"
  [ "$(git -C "$next" rev-parse "${head}^")" = "$base" ] || fail "${dep}: not on top of next"
  [ "$(git -C "$next" log -1 --format='%an <%ae>|%cn <%ce>|%s' "$head")" = "RunR bot <bot@example.test>|RunR bot <bot@example.test>|fix(regions): ${dep} chart $(cat "$dir/bump/$dep/version")" ] \
    || fail "${dep}: the commit: $(git -C "$next" log -1 --format='%an <%ae>|%cn <%ce>|%s' "$head")"
  [ "$(git -C "$next" show --name-only --format= "$head")" = "pin-${dep}" ] || fail "${dep}: the commit carries more than its bump"
done
[ "$(git -C "$next" rev-parse HEAD)" = "$base" ] || fail "the clone is not back on next"
[ -z "$(git -C "$next" status --porcelain)" ] || fail "the clone is left changed: $(git -C "$next" status --porcelain)"
[ "$(jq -c '[.[] | [.dep, .suite, .ref, .issue]]' "$dir/matrix-suites.json")" = '[["traefik","e2e","deps/traefik",20],["traefik","upgrade","deps/traefik",20],["zot","e2e","deps/zot",14]]' ] \
  || fail "the suite jobs: $(cat "$dir/matrix-suites.json")"
[ "$(jq -c '[.[].dep]' "$dir/matrix-unit.json")" = '["traefik","zot"]' ] || fail "the unit jobs: $(cat "$dir/matrix-unit.json")"
[ "$(cat "$dir/bump/kata-deploy/result")" = error ] || fail "a bump that failed is no error: $(cat "$dir/bump/kata-deploy/result" 2>/dev/null)"
echo "ok - the bumps: from the same next, committed as deps/<dep> with the bot's identity, their test jobs listed"

# --- outcome and the issues -------------------------------------------------------------------------
# result DEP SUITE STATUS — a test job's result artifact
result() {
  mkdir -p "$dir/results/deps-result-$1-$2"
  echo "$3" > "$dir/results/deps-result-$1-$2/status"
  echo "$2 of $1" > "$dir/results/deps-result-$1-$2/command"
  echo "the end of the $2 log of $1" > "$dir/results/deps-result-$1-$2/tail.txt"
}
for s in unit shell e2e upgrade; do result traefik "$s" success; done
result zot unit success
result zot shell success
result zot e2e failure
echo "- **xaas-python-3.15-http** [ready]: timed out" > "$dir/results/deps-result-zot-e2e/failures.md"
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir/outcome/traefik") $(cat "$dir/outcome/zot") $(cat "$dir/outcome/kube-prometheus-stack") $(cat "$dir/outcome/loki-stack") $(cat "$dir/outcome/kata-deploy")" = "green red attention current error" ] \
  || fail "outcomes: $(head -n 1 "$dir"/outcome/*)"
[ "$(cat "$dir/green")" = traefik ] || fail "green: $(cat "$dir/green")"
rm -rf "$dir/results/deps-result-traefik-upgrade"
bash "$root/ci/deps-run.sh" outcome "$dir"
[ "$(cat "$dir/outcome/traefik")" = red ] || fail "a suite without a result passed"
result traefik upgrade success
bash "$root/ci/deps-run.sh" outcome "$dir"
echo "ok - green only when every suite of the dependency passed; a missing result fails it"

mkdir -p "$dir/land"
echo "landed $(cat "$dir/bump/traefik/head")" > "$dir/land/traefik"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/1 1
actions="$(cat "$dir/actions")"
expect=$(printf 'label\t20\tdeps: passed\ncomment\t20\t%s\nclose\t20' "$dir/comments/20.md")
grep -qF "$expect" <<<"$actions" || fail "green and landed: ${actions}"
grep -qF "Fixed on next by $(cat "$dir/bump/traefik/head"), ships with the next release." "$dir/comments/20.md" || fail "the green comment: $(cat "$dir/comments/20.md")"
grep -qF 'https://example.test/run/1' "$dir/comments/20.md" || fail "the green comment has no run link"
grep -qF "$(printf 'label\t14\tneeds attention')" <<<"$actions" || fail "red: no label: ${actions}"
grep -qF 'xaas-python-3.15-http' "$dir/comments/14.md" || fail "red: the failures: $(cat "$dir/comments/14.md")"
grep -qF 'the end of the e2e log of zot' "$dir/comments/14.md" || fail "red: the log excerpt"
grep -qF 'Nothing was pushed' "$dir/comments/14.md" || fail "red: next untouched not said"
grep -qF "$(printf 'label\t8\tneeds attention')" <<<"$actions" || fail "attention: no label"
grep -qF 'a new major' "$dir/comments/8.md" || fail "attention: the reasons"
grep -qF "$(printf 'close\t15')" <<<"$actions" || fail "nothing to do: not closed"
grep -qF "$(printf 'comment\t21\t')" <<<"$actions" && grep -qvF "$(printf 'label\t21')" <<<"$actions" || fail "an error: a comment, no label"
grep -q '^dispatch' <<<"$actions" && fail "a dispatch without a moved next"
echo "ok - the issues: green and landed closed with deps: passed, red and attention labelled with what failed, nothing to do closed, an error commented"

# next moved under the green bump: a new run of the issue, up to three in a row
echo "moved 0123456789012345678901234567890123456789" > "$dir/land/traefik"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/2 1
grep -qxF "$(printf 'dispatch\t20\t2')" "$dir/actions" || fail "moved: no new run: $(cat "$dir/actions")"
grep -qF 'next moved meanwhile' "$dir/comments/20.md" || fail "moved: the comment"
grep -qF "$(printf 'close\t20')" "$dir/actions" && fail "moved: closed"
bash "$root/ci/deps-run.sh" actions "$dir" https://example.test/run/3 3
grep -q '^dispatch' "$dir/actions" && fail "a fourth run in a row"
grep -qF 'next weekly run' "$dir/comments/20.md" || fail "the third run's comment: $(cat "$dir/comments/20.md")"
echo "ok - next moved under a green bump: tested again on the new next, at most three runs in a row"

# --- the landing ------------------------------------------------------------------------------------
traefik="$(cat "$dir/bump/traefik/head")"
zot="$(cat "$dir/bump/zot/head")"
out="$(cd "$next" && bash "$root/ci/deps-land.sh" "$remote" "$base" "$traefik" --dry-run)" || fail "dry run: ${out}"
[ "$(git --git-dir="$remote" rev-parse next)" = "$base" ] || fail "the dry run pushed"
out="$(cd "$next" && bash "$root/ci/deps-land.sh" "$remote" "$base" "$traefik")" || fail "land: ${out}"
[ "$out" = "landed ${traefik}" ] || fail "land said: ${out}"
[ "$(git --git-dir="$remote" rev-parse next)" = "$traefik" ] || fail "next is not the bump"
rc=0
out="$(cd "$next" && bash "$root/ci/deps-land.sh" "$remote" "$base" "$zot")" || rc=$?
[ "$rc" = 4 ] && [ "$out" = "moved ${traefik}" ] || fail "a moved next: exit ${rc}: ${out}"
[ "$(git --git-dir="$remote" rev-parse next)" = "$traefik" ] || fail "a moved next was written"
rc=0
(cd "$next" && bash "$root/ci/deps-land.sh" "$remote" "$traefik" "$zot") >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] || fail "a commit on another parent: exit ${rc}"
rc=0
(cd "$next" && bash "$root/ci/deps-land.sh" "$remote" "$base" main) >/dev/null 2>&1 || rc=$?
[ "$rc" = 1 ] || fail "not a commit id: exit ${rc}"
[ "$(git --git-dir="$remote" for-each-ref --format='%(refname)')" = refs/heads/next ]   || fail "the landing wrote other refs: $(git --git-dir="$remote" for-each-ref --format='%(refname)')"
echo "ok - the landing: HEAD:refs/heads/next only, a fast-forward of the next it was tested on, a moved next left alone, another parent refused"

# --- the deploy key ---------------------------------------------------------------------------------
key=$'-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA-not-a-real-key\n-----END OPENSSH PRIVATE KEY-----'
hosts='gitlab.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAfuCHKVTjquxvt6CM6tdG4SLp1Btn/nOeHHE5UOzRdf'
export ORIGIN_REMOTE='git@gitlab.com:example-group/example-project.git'
cmd="$(PUSH_KEY="$key" KNOWN_HOSTS="$hosts" bash "$root/ci/deps/ssh-key.sh" "$tmp/ssh")" || fail "ssh-key.sh: ${cmd}"
# the origin: an SSH URL of the pinned host, from the variable
for good in 'git@gitlab.com:example-group/sub/example-project.git' 'ssh://git@gitlab.com/example-group/example-project.git'; do
  ORIGIN_REMOTE="$good" PUSH_KEY="$key" KNOWN_HOSTS="$hosts" bash "$root/ci/deps/ssh-key.sh" "$tmp/ssh4" >/dev/null || fail "an SSH URL refused: ${good}"
done
for bad in '' 'https://gitlab.com/example-group/example-project.git' 'git@example.com:example-group/example-project.git' \
  'git@gitlab.com:example-project.git' 'git@gitlab.com:example-group/example-project.git; rm -rf /' 'git@gitlab.com:../x/y.git ' \
  'ext::sh -c touch% /tmp/x'; do
  rc=0
  ORIGIN_REMOTE="$bad" PUSH_KEY="$key" KNOWN_HOSTS="$hosts" bash "$root/ci/deps/ssh-key.sh" "$tmp/ssh5" >/dev/null 2>&1 || rc=$?
  [ "$rc" = 1 ] || fail "an origin that is no SSH URL of the pinned host passed: '${bad}'"
  [ ! -e "$tmp/ssh5/key" ] || fail "a refused origin left the key"
done
[ "$cmd" = "ssh -i ${tmp}/ssh/key -o IdentitiesOnly=yes -o IdentityAgent=none -o UserKnownHostsFile=${tmp}/ssh/known_hosts -o StrictHostKeyChecking=yes -o BatchMode=yes" ] \
  || fail "the ssh command: ${cmd}"
[ "$(stat -c %a "$tmp/ssh/key") $(stat -c %a "$tmp/ssh/known_hosts") $(stat -c %a "$tmp/ssh")" = "600 600 700" ] || fail "modes: $(stat -c '%n %a' "$tmp/ssh" "$tmp"/ssh/*)"
[ "$(cat "$tmp/ssh/key")" = "$key" ] && [ "$(tail -c 1 "$tmp/ssh/key" | od -An -c | tr -d ' ')" = '\n' ] || fail "the key file"
[ "$(cat "$tmp/ssh/known_hosts")" = "$hosts" ] || fail "the known_hosts file"
grep -qF 'not-a-real-key' <<<"$cmd" && fail "the key is printed"
for bad in "PUSH_KEY= KNOWN_HOSTS=x" "PUSH_KEY=not-a-key KNOWN_HOSTS=x"; do
  rc=0
  out="$(env -u PUSH_KEY -u KNOWN_HOSTS $bad bash "$root/ci/deps/ssh-key.sh" "$tmp/ssh2" 2>&1)" || rc=$?
  [ "$rc" = 1 ] || fail "${bad}: exit ${rc}"
  grep -qF 'not-a-key' <<<"$out" && fail "${bad}: the key in the error"
done
# only gitlab.com's published host key, alone: not another key, another host or a second line
ssh-keygen -q -t ed25519 -N '' -f "$tmp/other" >/dev/null
other="gitlab.com $(cut -d' ' -f1,2 "$tmp/other.pub")"
for bad in "gitlab.com" "$other" "${hosts/gitlab.com/gitlab.example.com}" "${hosts}"$'
'"${other}"; do
  rc=0
  PUSH_KEY="$key" KNOWN_HOSTS="$bad" bash "$root/ci/deps/ssh-key.sh" "$tmp/ssh3" >/dev/null 2>&1 || rc=$?
  [ "$rc" = 1 ] || fail "a host key that is not gitlab.com's: exit ${rc}: ${bad}"
  [ ! -e "$tmp/ssh3" ] || fail "a refused host key left its files"
done
echo "ok - the deploy key: an SSH URL of the pinned host as the origin, 0600 files, the host's published key only (its fingerprint pinned), nothing of the key printed, missing or malformed values refused"
