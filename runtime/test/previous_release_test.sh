#!/usr/bin/env bash
# Tests previous_release_tag (lib/common.sh), the release `ci.sh test-upgrade` and
# `test-helm-upgrade` start from without --from, on throwaway git repositories:
#   - the newest vX.Y.Z tag at or below the checkout's version (appVersion of helm/Chart.yaml);
#     a newer release and tags that are no release (v0.7.0-rc.1, vnext) do not count;
#   - a tag on HEAD itself does not count (the tree of a release is not upgraded to itself), also
#     an annotated one;
#   - a checkout of kind-suite.yml's tree-artifact bundle (the tested branch and the tags, no main)
#     finds it, and cmd_test_upgrade starts from it instead of failing on `git archive main`;
#   - a shallow checkout without tags (actions/checkout) lists the origin's and fetches the chosen
#     one; no release tag anywhere is an error.
#
#   bash runtime/test/previous_release_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"

export GIT_AUTHOR_NAME=t GIT_AUTHOR_EMAIL=t@example.test GIT_COMMITTER_NAME=t GIT_COMMITTER_EMAIL=t@example.test
# no line-ending conversion (a host with core.autocrlf=true writes CRLF Chart.yaml files otherwise)
g() { git -C "$repo" -c init.defaultBranch=main -c core.autocrlf=false -c tag.gpgSign=false -c commit.gpgSign=false "$@"; }
# commit VERSION — a commit whose helm/Chart.yaml has appVersion VERSION
commit() {
  mkdir -p "$repo/helm"
  printf 'apiVersion: v2\nname: runr\nversion: %s\nappVersion: "%s"\n' "$1" "$1" > "$repo/helm/Chart.yaml"
  echo "$RANDOM" > "$repo/file"
  g add -A && g commit -q -m "version $1"
}

repo="$tmp/origin"
mkdir -p "$repo"
g init -q
commit 9.1.1 && g tag v9.1.1
commit 9.1.2 && g tag -a -m 'RunR 9.1.2' v9.1.2
commit 9.1.3 && g tag v9.1.3
g checkout -q -b next
commit 9.1.3
# a release above the checkout's version (another line) and tags that are no release
g checkout -q main
commit 0.7.0 && g tag v0.7.0 && g tag v0.7.0-rc.1 && g tag vnext
g checkout -q next

# --- the newest release at or below the checkout's version -----------------------------------------
[ "$(previous_release_tag "$repo")" = v9.1.3 ] || fail "next at 9.1.3: $(previous_release_tag "$repo" 2>&1)"
echo "ok - the newest vX.Y.Z tag at or below the checkout's version; newer and non-release tags do not count"

# --- a tag on HEAD is the tree itself -------------------------------------------------------------------
g checkout -q v9.1.3
[ "$(previous_release_tag "$repo")" = v9.1.2 ] || fail "on v9.1.3: $(previous_release_tag "$repo" 2>&1)"
g checkout -q v9.1.2
[ "$(previous_release_tag "$repo")" = v9.1.1 ] || fail "on the annotated v9.1.2: $(previous_release_tag "$repo" 2>&1)"
g checkout -q next
echo "ok - a release tag on HEAD (lightweight or annotated) is not the release upgraded from"

# --- kind-suite.yml's tree-artifact: a bundle of the tested branch and the tags, no main ----------------
g branch -q final-test next
g bundle create -q "$tmp/repo.bundle" refs/heads/final-test --tags 2>/dev/null
clone="$tmp/bundle-clone"
git -c init.defaultBranch=main clone -q -c core.autocrlf=false --no-checkout "$tmp/repo.bundle" "$clone" 2>/dev/null
git -C "$clone" checkout -q --detach refs/remotes/origin/final-test
git -C "$clone" rev-parse -q --verify 'main^{commit}' >/dev/null && fail "the bundle clone has main: the case tests nothing"
[ "$(previous_release_tag "$clone")" = v9.1.3 ] || fail "bundle clone: $(previous_release_tag "$clone" 2>&1)"

# cmd_test_upgrade without --from: the tree of v9.1.3, not `git archive main` (docker stubbed: the
# build fails, after the suite named the release it starts from)
mkdir -p "$clone/runtime"
out="$(
  # shellcheck source=../lib/test-upgrade.sh
  source "$here/lib/test-upgrade.sh"
  docker() { return 1; }
  SCRIPT_DIR="$clone/runtime" TMPDIR="$tmp" cmd_test_upgrade 2>&1
)" && fail "cmd_test_upgrade passed with docker failing: $out"
[[ "$out" == *"Compose upgrade test: v9.1.3 (9.1.3)"* ]] || fail "cmd_test_upgrade in the bundle clone: $out"
[[ "$out" == *"not a valid object name"* ]] && fail "cmd_test_upgrade still archives a ref the clone lacks: $out"
echo "ok - a checkout of the tree-artifact bundle (no main) upgrades from the previous release tag"

# --- a shallow checkout without tags (actions/checkout): the origin's tags ---------------------------------
shallow="$tmp/shallow"
git -c init.defaultBranch=main clone -q -c core.autocrlf=false --depth 1 --no-tags --branch next "file://$repo" "$shallow" 2>/dev/null
[ -z "$(git -C "$shallow" tag -l)" ] || fail "the shallow clone has tags: the case tests nothing"
[ "$(previous_release_tag "$shallow" 2>/dev/null)" = v9.1.3 ] || fail "shallow clone: $(previous_release_tag "$shallow" 2>&1)"
git -C "$shallow" rev-parse -q --verify 'v9.1.3^{commit}' >/dev/null || fail "the shallow clone did not fetch v9.1.3"
git -C "$shallow" archive v9.1.3 helm/Chart.yaml | tar -xO | grep -q 'appVersion: "9.1.3"' || fail "v9.1.3's tree is not readable in the shallow clone"
echo "ok - a shallow checkout without tags lists the origin's and fetches the release it starts from"

# --- no release tag anywhere -------------------------------------------------------------------------------
bare="$tmp/no-tags"
mkdir -p "$bare"
repo="$bare"
g init -q
commit 0.1.0
previous_release_tag "$bare" >/dev/null 2>&1 && fail "a repository without release tags gave a tag"
echo "ok - without any release tag there is no previous release (pass --from)"
