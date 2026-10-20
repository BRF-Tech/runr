#!/bin/bash
set -euo pipefail

# ── Reqs mirror check ────────────────────────────────────────────────────────
# Every ghcr.io/brf-tech/runr/reqs/<image>:<tag> the repository references — API code and config, the
# Helm chart (templates and values), Compose files, Dockerfiles, CI, runtime scripts, examples,
# tests and docs — must resolve anonymously on ghcr.io: nodes, build hosts and CI runners pull it
# without credentials. BRF-Tech/runr-runtimes publishes the mirror (REQS_IMAGES of its
# lib/mirror.sh and the base images of its language runtimes, copied by its mirror workflow): an
# image this repository starts to use is added there and mirrored first.
#
#   bash ci/check-reqs-mirror.sh           check every reference (needs network access to ghcr.io)
#   bash ci/check-reqs-mirror.sh --list    print the references and where they are, check nothing
#
# A reference resolves when ghcr.io answers 200 for its manifest with an anonymous pull token (a
# HEAD request: no layer is downloaded). A Helm block without a tag needs at least one tag of the
# image. Resolved references are remembered for REQS_MIRROR_CACHE_TTL seconds (default 86400) in
# REQS_MIRROR_CACHE (default ${XDG_CACHE_HOME:-~/.cache}/runr-reqs-mirror); a reference that
# does not resolve is checked again on every run. A network error (no answer, 429, 5xx) fails the
# check with its own message, apart from the references that do not exist.
#
# Also counted: ${REGISTRY}/reqs/… and "/reqs/…" string literals (code that prefixes the
# registry). References with a variable tag (${VERSION}, {version}) are not checked, except a
# Dockerfile FROM whose ARGs have defaults (resolved with them); a Helm
# `repository: ghcr.io/brf-tech/runr/reqs/<image>` takes the `tag:` of its block.
# Needs bash, git, GNU grep and curl.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=/dev/null
source runtime/lib/common.sh

MIRROR="ghcr.io/brf-tech/runr/reqs"
RUNTIMES_REPO="https://github.com/BRF-Tech/runr-runtimes"
CACHE="${REQS_MIRROR_CACHE:-${XDG_CACHE_HOME:-${HOME:-/tmp}/.cache}/runr-reqs-mirror}"
CACHE_TTL="${REQS_MIRROR_CACHE_TTL:-86400}"
MANIFEST_TYPES="application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json"

files() {
  git -c safe.directory="$ROOT" ls-files -z -- . \
    ':!:CHANGELOG.md' ':!:*.lock' ':!:*package-lock.json' ':!:ci/check-reqs-mirror.sh'
}
# the references come from the tracked files: outside a git checkout there would be none
if ! git -c safe.directory="$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "✗ ${ROOT} is not a git checkout: the reqs references are read from its tracked files"
  exit 1
fi

# file:line:<image>:<tag> for every reference
references() {
  # literal references; a trailing $ or { is a variable tag
  files | xargs -0 grep -I -n -o -E "(ghcr\.io/brf-tech/runr|REGISTRY\}?|[\"'\`])/reqs/[a-z0-9._/-]+:[A-Za-z0-9._-]+[\$\{]?" 2>/dev/null \
    | sed -E 's#^([^:]+:[0-9]+:).*/reqs/#\1#' \
    | grep -v -E '[$\{]$' || true

  # Helm values: repository: ghcr.io/brf-tech/runr/reqs/<image> + the tag: of the same block
  # (a block that ends without a tag: "<image>:", any tag of the image counts)
  files | grep -z -E '\.ya?ml$' | xargs -0 awk '
    function pending() { if (repo != "") print file ":" at ":" repo ":"; repo = "" }
    FNR == 1 { pending(); file = FILENAME }
    /^[[:space:]]*repository:[[:space:]]*"?ghcr\.io\/brf-tech\/runr\/reqs\/[a-z0-9._\/-]+"?[[:space:]]*(#.*)?$/ {
      pending()
      repo = $0
      sub(/^[[:space:]]*repository:[[:space:]]*"?ghcr\.io\/brf-tech\/runr\/reqs\//, "", repo)
      sub(/"?[[:space:]]*(#.*)?$/, "", repo)
      indent = match($0, /[^ ]/); at = FNR
      next
    }
    repo != "" && $0 !~ /^[[:space:]]*(#.*)?$/ {
      i = match($0, /[^ ]/)
      if (i < indent) { pending(); next }
      if (i == indent && $0 ~ /^[[:space:]]*tag:/) {
        tag = $0
        sub(/^[[:space:]]*tag:[[:space:]]*["\047]?/, "", tag)
        sub(/["\047]?[[:space:]]*(#.*)?$/, "", tag)
        print file ":" at ":" repo ":" tag; repo = ""
      }
    }
    END { pending() }
  ' 2>/dev/null || true

  # Dockerfile base images with an ARG in the reference (FROM …/reqs/tinygo:${TINYGO_VERSION}),
  # the ARG defaults substituted (dockerfile_bases of runtime/lib/common.sh)
  local file base
  while IFS= read -r -d '' file; do
    while IFS= read -r base; do
      case "$base" in
        ghcr.io/brf-tech/runr/reqs/*) base="${base#ghcr.io/brf-tech/runr/reqs/}"; echo "${file}:FROM:${base%@*}" ;;
      esac
    done < <(dockerfile_bases "$file")
  done < <(files | grep -z -E '(^|/)Dockerfile[^/]*$')
}

declare -A seen=() where=()
while IFS= read -r hit; do
  [ -n "$hit" ] || continue
  file="${hit%%:*}"
  rest="${hit#*:}"
  line="${rest%%:*}"
  ref="${rest#*:}"
  seen["$ref"]=$(( ${seen[$ref]:-0} + 1 ))
  # where: the first three places
  if [ "${seen[$ref]}" -le 3 ]; then
    where["$ref"]+="${where[$ref]:+, }${file}:${line}"
  fi
done < <(references)
if [ "${#seen[@]}" -eq 0 ]; then
  echo "✗ no reqs reference found: the search found none of the images the repository uses"
  exit 1
fi

if [ "${1:-}" = "--list" ]; then
  for ref in $(printf '%s\n' "${!seen[@]}" | sort); do
    echo "${MIRROR}/${ref}  (${where[$ref]})"
  done
  exit 0
fi

# ghcr_token IMAGE — an anonymous pull token for ${MIRROR}/IMAGE (one per image and run)
declare -A tokens=()
ghcr_token() {
  local image="$1" body
  if [ -z "${tokens[$image]:-}" ]; then
    body=$(curl -sS --retry 3 --retry-delay 2 --max-time 20 \
      "https://ghcr.io/token?service=ghcr.io&scope=repository:brf-tech/runr/reqs/${image}:pull" 2>/dev/null) || return 1
    tokens[$image]=$(printf '%s' "$body" | sed -n 's/.*"token" *: *"\([^"]*\)".*/\1/p')
  fi
  printf '%s' "${tokens[$image]}"
}

# resolve REF — the HTTP status of the manifest of ${MIRROR}/<image>:<tag> (000: no answer); for a
# Helm block without a tag ("<image>:"), 200 when the image has a tag
resolve() {
  local ref="$1" image tag token
  image="${ref%%:*}"
  tag="${ref#*:}"
  token=$(ghcr_token "$image") || { echo 000; return 0; }
  if [ -z "$tag" ]; then
    local tags
    tags=$(curl -sS --retry 3 --retry-delay 2 --max-time 20 -H "Authorization: Bearer ${token}" \
      "https://ghcr.io/v2/brf-tech/runr/reqs/${image}/tags/list" 2>/dev/null) || { echo 000; return 0; }
    if printf '%s' "$tags" | grep -q '"tags" *: *\[ *"'; then echo 200; else echo 404; fi
    return 0
  fi
  curl -sS -o /dev/null -I -w '%{http_code}' --retry 3 --retry-delay 2 --max-time 20 \
    -H "Authorization: Bearer ${token}" -H "Accept: ${MANIFEST_TYPES}" \
    "https://ghcr.io/v2/brf-tech/runr/reqs/${image}/manifests/${tag}" 2>/dev/null || true
}

# The cache: "<ref> <epoch seconds>" of every reference that resolved
mkdir -p "$(dirname "$CACHE")" 2>/dev/null || true
now=$(date +%s)
declare -A cached=()
if [ -r "$CACHE" ]; then
  while read -r ref at; do
    if [ -n "$ref" ] && [[ "${at:-}" =~ ^[0-9]+$ ]] && [ $((now - at)) -lt "$CACHE_TTL" ]; then
      cached["$ref"]="$at"
    fi
  done < "$CACHE"
fi

missing=0 unreachable=0 checked=0 from_cache=0
declare -A resolved=()
for ref in $(printf '%s\n' "${!seen[@]}" | sort); do
  if [ -n "${cached[$ref]:-}" ]; then
    resolved["$ref"]="${cached[$ref]}"
    from_cache=$((from_cache + 1))
    continue
  fi
  status=$(resolve "$ref")
  checked=$((checked + 1))
  case "$status" in
    200)
      resolved["$ref"]="$now" ;;
    401|403|404)
      echo "✗ ${MIRROR}/${ref} does not resolve anonymously (HTTP ${status}): ${where[$ref]}"
      missing=$((missing + 1)) ;;
    *)
      echo "✗ ${MIRROR}/${ref}: no answer from ghcr.io (HTTP ${status:-000}): ${where[$ref]}"
      unreachable=$((unreachable + 1)) ;;
  esac
done

if [ -w "$(dirname "$CACHE")" ]; then
  for ref in "${!resolved[@]}"; do echo "${ref} ${resolved[$ref]}"; done | sort > "${CACHE}.$$"
  mv -f -- "${CACHE}.$$" "$CACHE"
fi

if [ "$missing" -gt 0 ]; then
  echo ""
  echo "${missing} reqs image(s) are not in the mirror. Add each as \"<image>:<tag>|<Docker Hub source>\""
  echo "to REQS_IMAGES in lib/mirror.sh of ${RUNTIMES_REPO} and run its mirror workflow"
  echo "(Mirror reqs images, images: <image>:<tag>), or reference an image that is mirrored."
fi
if [ "$unreachable" -gt 0 ]; then
  echo ""
  echo "${unreachable} reference(s) could not be checked: ghcr.io did not answer (network, rate limit). Run the check again."
fi
[ "$missing" -eq 0 ] && [ "$unreachable" -eq 0 ] || exit 1

echo "✓ ${#seen[@]} reqs images referenced, all resolve anonymously on ghcr.io (${checked} checked, ${from_cache} from the cache ${CACHE})"
