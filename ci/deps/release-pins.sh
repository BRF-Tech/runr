#!/usr/bin/env bash
# The version pins of a RunR release in this checkout (the working tree of the current directory):
# the docs' examples (the chart's --version, actions/deploy@v<x>, git clone --branch v<x>, image
# tags), the CLI's release in the docs, and what runr.sh shows and pins (landing/). It commits
# nothing.
#
#   bash ci/deps/release-pins.sh PREV NEW [--server-only] [--cli-from=VERSION] [--only=docs|landing]
#                                [--keep=FILE] [--check-only]
#
#   PREV           the release before (the version the pins name now)
#   NEW            the release
#   --server-only  a dependency release (ci/deps-release.sh): the chart, the images and the tag move,
#                  the CLI, the Terraform provider and runr.sh stay those of the last full release.
#                  So the lines of the docs that pin the CLI (RUNR_VERSION, "CLI release") and
#                  landing/ keep their version.
#   --cli-from     a full release: the release the CLI lines and landing/ name now (default: the one
#                  docs/cli.md's RUNR_VERSION pins, PREV unless dependency releases came in between)
#   --only         only the docs (README*, actions/, compose/, docs/) or only landing/: the manual
#                  release commits them apart (the landing/ commit carries other authors)
#   --keep         literal strings that name PREV as history, not as a pin, one per line, #
#                  comments (default: ci/deps/pin-keep.txt next to this script): the "measured on
#                  RunR <PREV>" labels, a third-party version that happens to be the same
#   --check-only   edit nothing, only check
#
# Then no pin of PREV (nor, in a full release, of the CLI's release) may be left in the tracked files
# outside the release history (CHANGELOG.md, docs/upgrade.md), lock files, screenshots,
# helm/Chart.yaml (the release commit sets it), API tests' data, the bot's own files and the keep
# strings: a line that names it otherwise is a pin these rules do not know. Prints "edit FILE" per
# file edited and "left FILE:LINE:TEXT" per pin left.
#
# Exit status: 0 done, 2 pins left (nothing is put back: the caller decides), 1 an error.
set -euo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
prev="${1:-}"
new="${2:-}"
shift 2 || true
server_only=0
cli_from=""
only=""
keep="$here/pin-keep.txt"
check_only=0
for arg in "$@"; do
  case "$arg" in
    --server-only) server_only=1 ;;
    --cli-from=*) cli_from="${arg#--cli-from=}" ;;
    --only=docs|--only=landing) only="${arg#--only=}" ;;
    --keep=*) keep="${arg#--keep=}" ;;
    --check-only) check_only=1 ;;
    *) echo "release-pins: unknown argument ${arg}" >&2; exit 1 ;;
  esac
done
re='^[0-9]+\.[0-9]+\.[0-9]+$'
[[ "$prev" =~ $re ]] && [[ "$new" =~ $re ]] || { echo "usage: ci/deps/release-pins.sh PREV NEW [--server-only] [--cli-from=X] [--only=docs|landing] [--keep=FILE] [--check-only]" >&2; exit 1; }
[ -f "$keep" ] || { echo "release-pins: no keep file ${keep}" >&2; exit 1; }
git rev-parse --show-toplevel >/dev/null 2>&1 || { echo "release-pins: not in a git checkout" >&2; exit 1; }
cd "$(git rev-parse --show-toplevel)"

DOCS=(README.md README.tr.md actions/deploy/README.md compose/README.md docs/cli.md docs/github-action.md
      docs/helm-values.md docs/install.md docs/modules.md docs/tenancy.md)
LANDING=(landing/index.html landing/install.sh)

# the CLI's release as the docs pin it (install.sh's RUNR_VERSION example)
if [ "$server_only" = 0 ] && [ -z "$cli_from" ]; then
  cli_from="$(sed -nE 's/.*RUNR_VERSION=v([0-9]+\.[0-9]+\.[0-9]+) sh.*/\1/p' docs/cli.md | head -n 1)"
  [ -n "$cli_from" ] || cli_from="$prev"
fi
[ -z "$cli_from" ] || [[ "$cli_from" =~ $re ]] || { echo "release-pins: --cli-from=${cli_from} is no version" >&2; exit 1; }

# edit FILE PREV CLIPREV — FILE's pins of PREV (and its CLI lines' of CLIPREV, when given) to NEW
edit() {
  local f="$1" out rc=0
  [ -f "$f" ] || { echo "release-pins: ${f} missing" >&2; exit 1; }
  out="$(mktemp)"
  awk -v mode=edit -v prev="$2" -v new="$new" -v cliprev="$3" -v keep="$keep" -v path="$f" \
    -f "$here/release-pins.awk" "$f" > "$out" || rc=$?
  case "$rc" in
    0) cat "$out" > "$f"; echo "edit ${f}" ;;
    3) ;;
    *) rm -f -- "$out"; echo "release-pins: awk failed on ${f}" >&2; exit 1 ;;
  esac
  rm -f -- "$out"
}

if [ "$check_only" = 0 ]; then
  if [ "$only" != landing ]; then
    for f in "${DOCS[@]}"; do
      if [ "$server_only" = 1 ]; then edit "$f" "$prev" ""; else edit "$f" "$prev" "$cli_from"; fi
    done
  fi
  if [ "$only" != docs ] && [ "$server_only" = 0 ]; then
    # runr.sh names the CLI it serves: landing/ moves from the CLI's release
    for f in "${LANDING[@]}"; do edit "$f" "$cli_from" "$cli_from"; done
  fi
fi

# left VERSION CLIKEEP [PATHSPEC…] — the lines that still pin VERSION
left() {
  local v="$1" clikeep="$2"
  shift 2
  git grep -n -I -F -e "$v" -- . \
    ':!CHANGELOG.md' ':!docs/upgrade.md' ':!**/package-lock.json' ':!**/go.sum' ':!**/*.lock' \
    ':!landing/shots/**' ':!vue/shots/**' ':!helm/Chart.yaml' ':!api/tests/**' \
    ':!ci/deps/**' ':!ci/deps-*.sh' ':!runtime/test/deps_*_test.sh' "$@" 2>/dev/null \
    | awk -v mode=left -v ver="$v" -v clikeep="$clikeep" -v keep="$keep" -f "$here/release-pins.awk" || true
}

found=""
case "$server_only:$only" in
  1:*) found="$(left "$prev" 1 ':!landing/**')" ;;
  0:docs) found="$(left "$prev" 0 ':!landing/**')" ;;
  0:landing) found="$(left "$cli_from" 0 'landing/**')" ;;
  0:*)
    found="$(left "$prev" 0)"
    if [ "$cli_from" != "$prev" ]; then found="${found:+${found}$'\n'}$(left "$cli_from" 0)"; fi ;;
esac
found="$(sed '/^$/d' <<<"$found")"
if [ -n "$found" ]; then
  sed 's/^/left /' <<<"$found"
  exit 2
fi
exit 0
