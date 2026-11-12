#!/usr/bin/env bash
# Tests the dependency test bot's move of one of RunR's own images to its next patch version
# (own_version_bump of ci/deps/bump-packages.sh), on a copy of this checkout:
#   - every image of RunR's own that release-images lists moves a patch up, and the copy still
#     passes runtime/test/release_images_test.sh (the API's references, the Dockerfile's version,
#     and image-upload's `var version` in main.go, which a build without -ldflags reports, are
#     the build's default);
#   - nothing names the old version as the current one afterwards (the function's own check).
# 2026-11-12: the bot's first security bump of image-upload moved its Dockerfile and build default
# to 1.1.2 and left main.go at 1.1.1, so release_images_test.sh failed the bump.
#
#   bash runtime/test/deps_own_version_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

command -v jq >/dev/null || fail "jq is needed (ci/deps/lib.sh)"
command -v perl >/dev/null || fail "perl is needed (own_version_bump)"

# a copy of the checkout as it is (its tracked files with their changes; an exported tree, such as a
# release's source archive, has no .git: all of it), its own git repository
copy="$tmp/runr"
mkdir -p "$copy"
if git -C "$root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  (cd "$root" && git ls-files -z | tar --null --ignore-failed-read -T - -cf -) | tar -xf - -C "$copy"
else
  (cd "$root" && tar --exclude=./node_modules --exclude=./vue/node_modules -cf - .) | tar -xf - -C "$copy"
fi
git -C "$copy" init -q && git -C "$copy" add -A && git -C "$copy" -c user.name=t -c user.email=t@t commit -q -m copy

images="$(bash "$copy/runtime/ci.sh" release-images 2>/dev/null | sed -n 's#^ghcr.io/brf-tech/runr/\(image-upload\):.*#\1#p' | sort -u)"
[ -n "$images" ] || fail "runtime/ci.sh release-images lists no image-upload"

for image in $images; do
  git -C "$copy" checkout -q -- . && git -C "$copy" clean -qfd
  (
    cd "$copy"
    ROOT="$copy"; report="$tmp/report-$image"; mkdir -p "$report"
    # shellcheck source=../../ci/deps/lib.sh
    source "$copy/ci/deps/lib.sh"
    eval "$(sed -n '/^replace_pin()/,/^}/p;/^own_version_bump()/,/^}/p' "$copy/ci/deps/bump-packages.sh")"
    reasons=(); moved=(); notes=()
    old="$(bash "$copy/runtime/ci.sh" release-images 2>/dev/null | sed -n "s#^ghcr.io/brf-tech/runr/${image}:##p" | head -n 1)"
    own_version_bump "$image"
    [ "${#reasons[@]}" -eq 0 ] || fail "${image}: the bump needs a person: ${reasons[*]}"
    [ "${#moved[@]}" -eq 1 ] || fail "${image}: nothing moved"
    new="$(bash "$copy/runtime/ci.sh" release-images 2>/dev/null | sed -n "s#^ghcr.io/brf-tech/runr/${image}:##p" | head -n 1)"
    [ "$new" = "$(deps_bump_patch "$old")" ] || fail "${image}: release-images lists ${new} after the bump, not $(deps_bump_patch "$old")"
    out="$(bash "$copy/runtime/test/release_images_test.sh" 2>&1)" || fail "${image} ${old} → ${new}: release_images_test.sh fails on the bumped copy: $(grep FAIL <<<"$out" | head -n 3)"
    echo "ok - ${image} ${old} → ${new}: every place moved, release_images_test.sh passes on the bump"
  )
done
