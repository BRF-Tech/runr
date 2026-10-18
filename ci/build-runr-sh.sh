#!/usr/bin/env bash
# Builds the runr.sh site as one ZIP for the StaticSites "ZIP Deploy": the landing (landing/),
# the documentation (docs/*.md, the root Markdown files and every page they link to) as HTML
# under /docs, /security and /license, the CLI under /dl/cli (+ /install.sh), the Terraform
# provider under /dl/terraform with the provider registry protocol (/.well-known/terraform.json,
# /terraform/providers/v1/…) and the release signing key.
#
#   ci/build-runr-sh.sh --release-dir DIR --signing-key FILE --out FILE.zip [--ref REF | --worktree]
#
#   --release-dir   verified release files: DIR/cli/ (runr_<os>_<arch>.tar.gz|.zip, checksums.txt)
#                   and DIR/terraform-provider/ (terraform-provider-runr_<v>_* archives, SHA256SUMS,
#                   SHA256SUMS.sig, manifest.json); the directory name is the version (0.5.6)
#   --signing-key   armored public key the provider SHA256SUMS are signed with
#   --out           ZIP to write; a FILE.txt next to it records sha256, size and file count
#   --ref           git ref whose landing/ and docs are published (default HEAD)
#   --worktree      publish the working tree instead of a ref (tracked and untracked files)
#   --version       release version (default: the release directory's name)
#   --site-dir DIR  also keep the unpacked site in DIR (must not exist)
#
# Needs git, python3 and pandoc 3.x — a local `pandoc`, else the image $PANDOC_IMAGE
# (default pandoc/core:3.6.4) through docker. The ZIP is deterministic for a ref: entries
# sorted, timestamps set to the commit time.
set -euo pipefail

PANDOC_IMAGE="${PANDOC_IMAGE:-pandoc/core:3.6.4}"
ref=HEAD
worktree=false
release=""
key=""
out=""
version=""
keep=""

while [ $# -gt 0 ]; do
  case "$1" in
    --release-dir) release="$2"; shift 2 ;;
    --signing-key) key="$2"; shift 2 ;;
    --out) out="$2"; shift 2 ;;
    --ref) ref="$2"; shift 2 ;;
    --worktree) worktree=true; shift ;;
    --version) version="$2"; shift 2 ;;
    --site-dir) keep="$2"; shift 2 ;;
    -h | --help) sed -n '2,24p' "$0"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done
[ -n "$release" ] && [ -n "$key" ] && [ -n "$out" ] || { sed -n '2,24p' "$0" >&2; exit 2; }
[ -d "$release/cli" ] && [ -d "$release/terraform-provider" ] || { echo "no cli/ and terraform-provider/ in $release" >&2; exit 1; }
[ -f "$key" ] || { echo "signing key not found: $key" >&2; exit 1; }
[ -n "$version" ] || version="$(basename "$release")"
[ -z "$keep" ] || [ ! -e "$keep" ] || { echo "--site-dir exists: $keep" >&2; exit 1; }

PY=""
for c in "${PYTHON:-}" python3 python; do
  [ -n "$c" ] || continue
  if command -v "$c" >/dev/null 2>&1 && "$c" -c 'import sys; sys.exit(sys.version_info < (3, 8))' 2>/dev/null; then
    PY="$c"; break
  fi
done
[ -n "$PY" ] || { echo "python 3.8+ is required" >&2; exit 1; }

here="$(cd "$(dirname "$0")" && pwd)"
root="$(git -C "$here" rev-parse --show-toplevel)"
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
mkdir -p "$work/src" "$work/md" "$work/html"

# Source tree: a ref through git archive, or the working tree.
if [ "$worktree" = true ]; then
  # every file, as in a ref's git archive: links to files that are not published point at GitHub
  (cd "$root" && git ls-files -z --cached --others --exclude-standard \
    | tar --null --ignore-failed-read -T - -cf -) | tar -xf - -C "$work/src"
  commit="$(git -C "$root" rev-parse HEAD)+worktree"
  epoch="$(date +%s)"
else
  git -C "$root" archive --format=tar "$ref" | tar -xf - -C "$work/src"
  commit="$(git -C "$root" rev-parse "$ref^{commit}")"
  epoch="$(git -C "$root" log -1 --format=%ct "$ref^{commit}")"
fi
date="$("$PY" -c 'import sys, time; print(time.strftime("%Y-%m-%d", time.gmtime(int(sys.argv[1]))))' "$epoch")"

"$PY" "$here/runr-sh/build.py" prepare --src "$work/src" --work "$work"

# Markdown → HTML fragments (GitHub-flavored Markdown, GitHub-style heading ids).
convert='for f in *.md; do pandoc -f gfm -t html5 --no-highlight --wrap=none "$f" -o "../html/${f%.md}.html" || exit 1; done'
if command -v pandoc >/dev/null 2>&1 && pandoc --version | head -1 | grep -q '^pandoc 3\.'; then
  (cd "$work/md" && sh -c "$convert")
else
  tar -C "$work/md" -cf - . | docker run --rm -i --entrypoint sh "$PANDOC_IMAGE" -c \
    "mkdir -p /w/md /w/html && cd /w/md && tar -xf - && $convert && cd /w/html && tar -cf - ." \
    | tar -xf - -C "$work/html"
fi

"$PY" "$here/runr-sh/build.py" render --src "$work/src" --work "$work" --site "$work/site" \
  --release "$release" --key "$key" --key-id 2588846B241BC095 \
  --key-fpr 2CA513A757AE0C94BD8A7EE22588846B241BC095 \
  --version "$version" --zip "$out" --date "$date" --epoch "$epoch"

sha="$("$PY" -c 'import hashlib, sys; print(hashlib.sha256(open(sys.argv[1], "rb").read()).hexdigest())' "$out")"
size="$(wc -c < "$out" | tr -d ' ')"
files="$("$PY" -c 'import zipfile, sys; print(len(zipfile.ZipFile(sys.argv[1]).namelist()))' "$out")"
{
  echo "runr.sh site, RunR $version"
  echo "zip:     $(basename "$out")"
  echo "sha256:  $sha"
  echo "bytes:   $size"
  echo "files:   $files"
  echo "source:  $commit ($ref$([ "$worktree" = true ] && echo ', working tree'))"
  echo "built:   $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo
  echo "summary:"
  cat "$work/summary.json"
  echo
  echo "links to repository files that are not published (to GitHub at the release tag):"
  cat "$work/github.txt"
  echo
  echo "links to nothing in the repository (shown as text):"
  cat "$work/unlinked.txt"
} > "${out%.zip}.txt"

if [ -n "$keep" ]; then
  mkdir -p "$(dirname "$keep")"
  cp -R "$work/site" "$keep"
fi
echo "wrote $out ($size bytes, $files files, sha256 $sha)"
