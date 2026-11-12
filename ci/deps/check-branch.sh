#!/usr/bin/env bash
set -euo pipefail

# ── A branch for the public repository, checked before it is pushed ──────────
# What the dependency test bot checks on a release before its push (ci/deps-release.sh publish),
# for a branch a person pushes to this repository by hand, such as the temporary branch a dry run
# of deps-test.yml runs on: COMMIT must be commits on BASE (the public main), and
#   - the scrub: no line the branch adds and no message of its commits matches DEPS_FORBIDDEN (the
#     internal names, the secret RUNR_SCRUB_PATTERN; an extended regex, case-insensitive). The
#     output names the files and counts, never the lines;
#   - gitleaks: the files of COMMIT's tree, with the repository's .gitleaks.toml (docker, the image
#     ci.yml pins; the tree goes to the container on its input, so it works with any docker daemon),
#     unless --no-gitleaks; a docker error is reported as such, not as findings;
#   - the attribution rule: ci/deps/check-attribution.sh on BASE..COMMIT;
#   - --tree=REF: COMMIT's tree is REF's tree (the branch carries that tree and nothing else).
#
#   DEPS_FORBIDDEN=… bash ci/deps/check-branch.sh REPO BASE COMMIT [--tree=REF] [--no-gitleaks]
#
# Exit 0 when every check holds; 1 and a FAIL line per problem otherwise. Needs bash, git, grep,
# tar and docker (gitleaks).
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GITLEAKS_IMAGE="${GITLEAKS_IMAGE:-ghcr.io/gitleaks/gitleaks:v8.30.1}"
repo="${1:?usage: ci/deps/check-branch.sh REPO BASE COMMIT [--tree=REF] [--no-gitleaks]}"
base="${2:?usage: ci/deps/check-branch.sh REPO BASE COMMIT [--tree=REF] [--no-gitleaks]}"
commit="${3:?usage: ci/deps/check-branch.sh REPO BASE COMMIT [--tree=REF] [--no-gitleaks]}"
shift 3
tree_ref=""
gitleaks=1
for arg in "$@"; do
  case "$arg" in
    --tree=*) tree_ref="${arg#--tree=}" ;;
    --no-gitleaks) gitleaks=0 ;;
    *) echo "unknown argument: ${arg}" >&2; exit 2 ;;
  esac
done
fails=0
fail() { echo "FAIL $*"; fails=$((fails + 1)); }
g() { git -C "$repo" "$@"; }

g rev-parse -q --verify "${base}^{commit}" >/dev/null || { echo "FAIL: ${base} is not a commit of ${repo}"; exit 1; }
g rev-parse -q --verify "${commit}^{commit}" >/dev/null || { echo "FAIL: ${commit} is not a commit of ${repo}"; exit 1; }
g merge-base --is-ancestor "$base" "$commit" || fail "${commit:0:12} is not on ${base:0:12}"

if [ -n "$tree_ref" ]; then
  [ "$(g rev-parse "${commit}^{tree}")" = "$(g rev-parse "${tree_ref}^{tree}")" ] || fail "the tree of ${commit:0:12} is not ${tree_ref}'s"
fi

# the scrub: the added lines and the messages, counted, never printed
if [ -z "${DEPS_FORBIDDEN:-}" ]; then
  fail "no scrub pattern (DEPS_FORBIDDEN, the value of the secret RUNR_SCRUB_PATTERN)"
else
  hits="$( { g diff "$base" "$commit" | grep '^+[^+]' || true; g log --format=%B "${base}..${commit}"; } | grep -ciE "$DEPS_FORBIDDEN" || true)"
  if [ "${hits:-0}" != 0 ]; then
    files="$(g diff -U0 "$base" "$commit" | awk '/^\+\+\+ b\// { f = substr($0, 7); next } /^\+/ { print f "\t" substr($0, 2) }' \
      | grep -iE "$DEPS_FORBIDDEN" | cut -f1 | sort -u | paste -sd ' ' - || true)"
    fail "the scrub pattern matches ${hits} line(s) (${files:-in the commit messages})"
  fi
fi

# gitleaks on the files of the branch's tree, streamed into the container (no host path is mounted:
# a docker CLI whose daemon runs elsewhere, such as in WSL, sees none of this host's paths). gitleaks
# exits 1 for findings; anything else is an error of docker or of gitleaks, not a finding
if [ "$gitleaks" = 1 ]; then
  err="$(mktemp)"
  rc=0
  g archive "$commit" | docker run -i --rm --entrypoint sh "$GITLEAKS_IMAGE" -c \
    'mkdir /tree && tar -x -C /tree && exec gitleaks dir /tree --config /tree/.gitleaks.toml --redact --no-banner' \
    >/dev/null 2>"$err" || rc=$?
  case "$rc" in
    0) ;;
    1) if grep -qiE 'leaks found|finding' "$err"; then
         fail "gitleaks: the tree has findings (run it without --redact to see them)"
       else
         fail "gitleaks ended 1 without findings: $(tail -n 3 "$err" | tr '\n' ' ')"
       fi ;;
    *) fail "gitleaks could not run (docker exit ${rc}): $(tail -n 3 "$err" | tr '\n' ' ')" ;;
  esac
  rm -f -- "$err"
fi

# the attribution rule of the public repository
if ! out="$(bash "$ROOT/ci/deps/check-attribution.sh" "$repo" "$base" "$commit" 2>&1)"; then
  while IFS= read -r line; do fail "attribution: ${line#FAIL }"; done < <(grep '^FAIL ' <<<"$out")
fi

if [ "$fails" = 0 ]; then
  echo "OK: ${base:0:12}..${commit:0:12} is scrub-clean, $([ "$gitleaks" = 1 ] && echo "gitleaks-clean, ")follows the attribution rule$([ -n "$tree_ref" ] && echo " and carries ${tree_ref}'s tree")"
  exit 0
fi
echo "FAILED: ${fails} problem(s)"
exit 1
