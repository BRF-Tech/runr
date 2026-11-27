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
#                  RunR <PREV>" labels
#   --check-only   edit nothing, only check
#
# Then no pin of PREV (nor, in a full release, of the CLI's release) may be left in the tracked files
# outside the release history (CHANGELOG.md, docs/upgrade.md), lock files, screenshots and images,
# helm/Chart.yaml (the release commit sets it), tests' data, the patches and notices of upstream
# sources, the bot's own files and the keep strings: a line that names it otherwise is a pin these
# rules do not know. A third party's version that happens to be PREV is neither moved nor a pin
# (ci/deps/release-pins.awk, with the bot's tables of ci/deps/lib.sh and packages.sh next to this
# script): a dependency manifest's requirement of a package that is not RunR's own, a place of the
# bot's pins of third-party tools, a third party's image, URL, version key or name, a helm command
# of another chart, a comment line. Prints "edit FILE" per file edited and "left FILE:LINE:TEXT"
# per pin left.
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

# What the bot's tables (ci/deps/lib.sh and packages.sh next to this script) and this tree's
# manifests say of the tree's versions (ci/deps/release-pins.awk):
#   places      the places of the bot's pins of third-party tools, "FILE<TAB>SLOT<TAB>NAME" per line
#               (DEPS_PINS' PLACES: FILE~SLOT~NAME, FILE a pathspec glob): the tools' versions
#   relimages   the images tagged with RunR's version (DEPS_DOCKERFILES' release rows): only their
#               tags pin a release
#   ownimages   "DIR IMAGE …": the directories of RunR's own images with versions of their own
#   thirdnames  the names of third parties, one per line: the bot's dependencies (DEPS_PINS,
#               DEPS_BOT, the own images), DEPS_NAMED, and the packages of the tracked package.json
#               and requirements files
places="$(mktemp)"
thirdnames="$(mktemp)"
trap 'rm -f -- "$places" "$thirdnames"' EXIT
relimages=""
ownimages=""
if [ -f "$here/lib.sh" ] && [ -f "$here/packages.sh" ]; then
  tables() {
    set -f
    # shellcheck source=ci/deps/lib.sh disable=SC1091
    DEPS_ROOT="$(cd "$here/../.." && pwd)" && . "$here/lib.sh" || return 1
    case "$1" in
      places)
        for row in "${DEPS_PINS[@]}"; do
          for place in $(deps_field "$row" 7); do
            IFS='~' read -r glob slot name <<<"$place"
            git ls-files -- "$glob" | while IFS= read -r f; do printf '%s\t%s\t%s\n' "$f" "$slot" "$name"; done
          done
        done ;;
      relimages)
        for row in "${DEPS_DOCKERFILES[@]}"; do
          if [ "$(deps_field "$row" 3)" = release ]; then deps_field "$row" 2; fi
        done | sort -u | paste -sd ' ' - ;;
      ownimages)
        for row in "${DEPS_DOCKERFILES[@]}"; do
          if [ "$(deps_field "$row" 3)" = own ]; then printf '%s %s\n' "$(dirname "$(deps_field "$row" 1)")" "$(deps_field "$row" 2)"; fi
        done | paste -sd ' ' - ;;
      names)
        for row in "${DEPS_PINS[@]}"; do name="$(deps_field "$row" 2)"; printf '%s\n%s\n' "$name" "${name%-[0-9]*}"; done
        for row in "${DEPS_BOT[@]}"; do deps_field "$row" 1; done
        for row in "${DEPS_DOCKERFILES[@]}"; do
          if [ "$(deps_field "$row" 3)" = own ]; then deps_field "$row" 2; fi
        done
        printf '%s\n' "${DEPS_NAMED[@]}" ;;
    esac
  }
  (tables places) > "$places" || { echo "release-pins: cannot read the pin places of ${here}/packages.sh" >&2; exit 1; }
  relimages="$(tables relimages)" || { echo "release-pins: cannot read the release images of ${here}/packages.sh" >&2; exit 1; }
  ownimages="$(tables ownimages)" || { echo "release-pins: cannot read the own images of ${here}/packages.sh" >&2; exit 1; }
  (tables names) > "$thirdnames" || { echo "release-pins: cannot read the third parties' names of ${here}/packages.sh" >&2; exit 1; }
fi
# the tree's npm and Python packages (the names a doc names their versions with)
if command -v jq >/dev/null 2>&1; then
  git ls-files -z -- '*package.json' ':!**/node_modules/**' | xargs -0 -r jq -r \
    '[.dependencies, .devDependencies, .peerDependencies, .optionalDependencies] | map(select(. != null) | keys[]) | .[]
     | if test("^@runr/") then empty else ., sub("^@[^/]+/"; "") end' \
    >> "$thirdnames" 2>/dev/null || true
fi
git ls-files -z -- '*requirements*.txt' | xargs -0 -r sed -nE 's/^[[:space:]]*([A-Za-z][A-Za-z0-9._-]*).*/\1/p' >> "$thirdnames" 2>/dev/null || true

# edit FILE PREV CLIPREV — FILE's pins of PREV (and its CLI lines' of CLIPREV, when given) to NEW
edit() {
  local f="$1" out rc=0
  [ -f "$f" ] || { echo "release-pins: ${f} missing" >&2; exit 1; }
  out="$(mktemp)"
  awk -v mode=edit -v prev="$2" -v new="$new" -v cliprev="$3" -v keep="$keep" -v path="$f" \
    -v relimages="$relimages" -v ownimages="$ownimages" -v thirdnames="$thirdnames" \
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

# left VERSION CLIKEEP [PATHSPEC…] — the lines that still pin VERSION, of the files that name it
# (read whole: a YAML key's parents name what it is of). Tests' data is no pin (a test that needs
# the release's version fails on the release commit), nor are images and the patches and notices
# of upstream sources RunR builds
left() {
  local v="$1" clikeep="$2" files=()
  shift 2
  mapfile -d '' files < <(git grep -l -z -I -F -e "$v" -- . \
    ':!CHANGELOG.md' ':!docs/upgrade.md' ':!**/package-lock.json' ':!**/npm-shrinkwrap.json' \
    ':!**/pnpm-lock.yaml' ':!**/go.sum' ':!**/go.work.sum' ':!**/*.lock' \
    ':!landing/shots/**' ':!vue/shots/**' ':!**/*.svg' ':!helm/Chart.yaml' \
    ':!**/tests/**' ':!**/testdata/**' ':!**/*_test.go' ':!**/runrtest/**' ':!**/*.spec.*' ':!**/*.test.*' \
    ':!vue/e2e/**' ':!runtime/test/**' ':!**/*.patch' ':!**/NOTICE' \
    ':!ci/deps/**' ':!ci/deps-*.sh' "$@" 2>/dev/null || true)
  [ "${#files[@]}" -gt 0 ] || return 0
  awk -v mode=left -v ver="$v" -v clikeep="$clikeep" -v keep="$keep" -v places="$places" \
    -v relimages="$relimages" -v ownimages="$ownimages" -v thirdnames="$thirdnames" \
    -f "$here/release-pins.awk" "${files[@]}" || true
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
