#!/usr/bin/env bash
# Tests ci/deps/check-branch.sh, the check of a branch a person pushes to the public repository by
# hand (the temporary branch of a deps-test.yml dry run), without gitleaks (docker): a branch on the
# public main whose added lines and messages the scrub pattern does not match, that follows the
# attribution rule and carries the tree it should passes; one that names an internal system, has a
# Claude co-author on backend files, is not on the base or carries another tree fails, and the
# failure never prints the matched line.
#
#   bash runtime/test/deps_branch_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_NOSYSTEM=1
export GIT_AUTHOR_NAME='Burak Faruk ŞAHİN' GIT_AUTHOR_EMAIL='19393636+brkfun@users.noreply.github.com'
export GIT_COMMITTER_NAME="$GIT_AUTHOR_NAME" GIT_COMMITTER_EMAIL="$GIT_AUTHOR_EMAIL"
export DEPS_FORBIDDEN='internal-name|another-internal-host'

r="$tmp/repo"
git init -q -b main "$r"
echo base > "$r/a.txt"
(cd "$r" && git add -A && git commit -q -m "RunR 1.2.3")
base="$(git -C "$r" rev-parse HEAD)"
git -C "$r" checkout -q -b test
mkdir -p "$r/ci"
echo 'echo the bot' > "$r/ci/x.sh"
(cd "$r" && git add -A && git commit -q -m "test: the bot's dry run")
good="$(git -C "$r" rev-parse HEAD)"
check() { bash "$root/ci/deps/check-branch.sh" "$r" "$@" --no-gitleaks 2>&1 || true; }

check "$base" "$good" --tree="$good" | grep -q '^OK: ' || fail "a clean branch: $(check "$base" "$good" --tree="$good")"
# another tree than the one it should carry
check "$base" "$good" --tree="$base" | grep -qF 'is not' || fail "another tree passed"
# not on the base
git -C "$r" checkout -q --orphan other && git -C "$r" commit -q -m other --allow-empty
other="$(git -C "$r" rev-parse HEAD)"
check "$base" "$other" | grep -qF "is not on ${base:0:12}" || fail "a branch off the base passed"
git -C "$r" checkout -q test
# an internal name in a line or a message: counted with its file, the line never printed
echo 'host internal-name.example' > "$r/ci/y.sh"
(cd "$r" && git add -A && git commit -q -m "ci: y")
out="$(check "$base" HEAD)"
grep -qF 'the scrub pattern matches 1 line(s) (ci/y.sh)' <<<"$out" || fail "the scrub: ${out}"
if grep -qF 'internal-name.example' <<<"$out"; then fail "the scrub printed the line"; fi
git -C "$r" reset -q --hard "$good"
(cd "$r" && git commit -q --allow-empty -m "fix: for another-internal-host")
grep -qF 'the scrub pattern matches 1 line(s) (in the commit messages)' <<<"$(check "$base" HEAD)" || fail "a message's internal name passed"
git -C "$r" reset -q --hard "$good"
# the attribution rule: Claude on a backend commit
echo z > "$r/ci/z.sh"
(cd "$r" && git add -A && git commit -q -m "$(printf 'ci: z\n\nCo-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>')")
check "$base" HEAD | grep -qF 'attribution: ' || fail "a Claude co-author on ci/ passed"
# no scrub pattern: no check
out="$(DEPS_FORBIDDEN='' bash "$root/ci/deps/check-branch.sh" "$r" "$base" "$good" --no-gitleaks 2>&1 || true)"
grep -qF 'no scrub pattern' <<<"$out" || fail "a check without the scrub pattern passed: ${out}"
# gitleaks: the tree streamed into the container (no host path mounted); findings (exit 1) apart
# from a docker error (125: the daemon cannot mount a path of this host, or no daemon)
mkdir -p "$tmp/bin"
cat > "$tmp/bin/docker" <<'EOF'
#!/usr/bin/env bash
[[ " $* " == *" -v "* ]] && { echo "docker: invalid volume specification" >&2; exit 125; }
tar -t > "$FAKE_DOCKER_LIST" || exit 2
case "$FAKE_DOCKER" in
  clean) exit 0 ;;
  leaks) echo "WRN leaks found: 1" >&2; exit 1 ;;
  daemon) echo "Cannot connect to the Docker daemon at unix:///var/run/docker.sock" >&2; exit 125 ;;
esac
EOF
chmod +x "$tmp/bin/docker"
export FAKE_DOCKER_LIST="$tmp/docker-list"
gl() { FAKE_DOCKER="$1" PATH="$tmp/bin:$PATH" bash "$root/ci/deps/check-branch.sh" "$r" "$base" "$good" 2>&1 || true; }
grep -q '^OK: .*gitleaks-clean' <<<"$(gl clean)" && grep -qx 'ci/x.sh' "$FAKE_DOCKER_LIST" || fail "gitleaks on the streamed tree: $(gl clean)"
grep -qF 'gitleaks: the tree has findings' <<<"$(gl leaks)" || fail "gitleaks' findings: $(gl leaks)"
out="$(gl daemon)"
grep -qF 'gitleaks could not run (docker exit 125): Cannot connect to the Docker daemon' <<<"$out" || fail "a docker error read as findings: ${out}"
if grep -qF 'has findings' <<<"$out"; then fail "a docker error reported as findings"; fi
echo "ok - check-branch.sh: a clean branch on the base passes; another tree, a branch off the base, an internal name (counted, never printed), a Claude co-author on backend files or a missing scrub pattern fail; gitleaks reads the tree from its input, and a docker error is told apart from findings"
