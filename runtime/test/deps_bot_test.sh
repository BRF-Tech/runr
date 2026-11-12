#!/usr/bin/env bash
# Tests the dependency test bot's plan (ci/deps-plan.sh) and its deploy key (ci/deps/ssh-key.sh)
# without GitHub or GitLab: the issues are a JSON file as `gh issue list` writes it. Checks that:
#   - the plan takes every deps issue and the Knative release issue, tests only the newest version
#     of a dependency and supersedes the older ones, skips a passed one and one that waits for a
#     person unless the run names it (which takes the label off), and labels a Knative minor that
#     has no row;
#   - the deploy key: an SSH URL of the pinned host, 0600 files, the host's published key only.
# The bumps, their outcome, the release and the issues' actions: runtime/test/deps_release_test.sh.
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

# --- the deploy key ---------------------------------------------------------------------------------
# a real key: ssh-keygen must load what the script writes
ssh-keygen -q -t ed25519 -N '' -C deploy -f "$tmp/deploy" >/dev/null
key="$(cat "$tmp/deploy")"
chunk="$(sed -n 2p "$tmp/deploy" | cut -c1-24)"
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
grep -qF "$chunk" <<<"$cmd" && fail "the key is printed"
# what a vault note or a copy makes of a key: CRLF, lines around the block, the block on one line —
# the key file is the block alone, as ssh-keygen wrote it
for variant in crlf header folded; do
  case "$variant" in
    crlf) pk="$(sed 's/$/\r/' "$tmp/deploy")" ;;
    header) pk=$'GitLab deploy key (write)\n# the bot pushes main, next and the release tags\n'"${key}"$'\n\nCreated 2026-11-06' ;;
    folded) pk="$(tr '\n' ' ' < "$tmp/deploy")" ;;
  esac
  rm -rf "$tmp/ssh6"
  PUSH_KEY="$pk" KNOWN_HOSTS="$hosts" bash "$root/ci/deps/ssh-key.sh" "$tmp/ssh6" >/dev/null || fail "a ${variant} key refused"
  [ "$(cat "$tmp/ssh6/key")" = "$key" ] && [ "$(tail -c 1 "$tmp/ssh6/key" | od -An -c | tr -d ' ')" = '\n' ] || fail "the ${variant} key's file: $(od -c "$tmp/ssh6/key" | head -n 3)"
done
# a key that does not load: refused at once with the reason, no file left, nothing of it printed
ssh-keygen -q -t ed25519 -N 'a-passphrase' -C deploy -f "$tmp/locked" >/dev/null
for variant in garbage nobase64 noend passphrase; do
  case "$variant" in
    garbage) pk=$'-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAB3NzaC1yc2EAAAADAQABnotakeyatall\n-----END OPENSSH PRIVATE KEY-----'; want='does not load' ;;
    nobase64) pk=$'-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA!not*base64\n-----END OPENSSH PRIVATE KEY-----'; want='no base64' ;;
    noend) pk="$(head -n 3 "$tmp/deploy")"; want='has no -----END OPENSSH PRIVATE KEY----- line' ;;
    passphrase) pk="$(cat "$tmp/locked")"; want='does not load' ;;
  esac
  rc=0
  out="$(PUSH_KEY="$pk" KNOWN_HOSTS="$hosts" bash "$root/ci/deps/ssh-key.sh" "$tmp/ssh7" 2>&1 >/dev/null)" || rc=$?
  [ "$rc" = 1 ] && grep -qF "$want" <<<"$out" || fail "a ${variant} key: exit ${rc}: ${out}"
  [ ! -e "$tmp/ssh7" ] || fail "a ${variant} key left its files"
  if grep -qF "$(sed -n 2p <<<"$pk" | cut -c1-12)" <<<"$out"; then fail "a ${variant} key is printed in the error"; fi
done
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
echo "ok - the deploy key: an SSH URL of the pinned host as the origin, 0600 files, the OpenSSH block alone (CRLF, lines around it, a folded block) and only a key that loads, the host's published key only (its fingerprint pinned), nothing of the key printed, missing or malformed values refused"
