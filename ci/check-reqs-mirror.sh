#!/bin/bash
set -euo pipefail

# ── Reqs mirror check ────────────────────────────────────────────────────────
# Every ghcr.io/brf-tech/runr/reqs/<image>:<tag> the repository references — API code and config, the
# Helm chart (templates and values), Compose files, Dockerfiles, CI, runtime scripts, examples,
# tests and docs — must be mirrored by runtime/ci.sh: an entry of REQS_IMAGES
# (runtime/lib/mirror.sh, `ci.sh mirror-reqs`) or a runtime base image that `ci.sh mirror-bases`
# discovers (RUNTIME_DEFS of runtime/lib/common.sh). A reference without a mirror entry is an
# image nobody copies to ghcr.io: nodes and CI runners fail to pull it.
#
#   bash ci/check-reqs-mirror.sh
#
# Also counted: ${REGISTRY}/reqs/… and "/reqs/…" string literals (code that prefixes the
# registry). References with a variable tag (${VERSION}, {version}) are not checked, except a
# Dockerfile FROM whose ARGs have defaults (resolved with them); a Helm
# `repository: ghcr.io/brf-tech/runr/reqs/<image>` takes the `tag:` of its block.
# Needs bash, git, GNU grep and sort -V.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# shellcheck source=/dev/null
source runtime/lib/common.sh
# shellcheck source=/dev/null
source runtime/lib/mirror.sh

declare -A mirrored=()
for entry in "${REQS_IMAGES[@]}"; do
  mirrored["${entry%%|*}"]=1
done

# A runtime base image of mirror-bases: <name>:<major.minor><suffix> from min_ver on (node: the
# even, LTS majors)
is_base_image() {
  local ref="$1" def runtime repo suffix min_ver depth name ver
  for def in "${RUNTIME_DEFS[@]}"; do
    IFS='|' read -r runtime repo suffix min_ver depth <<< "$def"
    name="${repo#library/}"
    [[ "$ref" == "${name}:"*"${suffix}" ]] || continue
    ver="${ref#"${name}:"}"
    ver="${ver%"${suffix}"}"
    [[ "$ver" =~ ^[0-9]+\.[0-9]+$ ]] || continue
    version_gte "$ver" "$min_ver" || continue
    if [ "$runtime" = nodejs ] && (( ${ver%%.*} % 2 != 0 )); then
      continue
    fi
    return 0
  done
  return 1
}

# A mirrored image: a REQS_IMAGES target, a base image, or (Helm block without a tag) any tag
is_mirrored() {
  local ref="$1" target
  case "$ref" in
    *:)
      for target in "${!mirrored[@]}"; do
        [[ "$target" == "$ref"* ]] && return 0
      done
      return 1
      ;;
  esac
  [ -n "${mirrored[$ref]:-}" ] && return 0
  is_base_image "$ref"
}

files() {
  git -c safe.directory="$ROOT" ls-files -z -- . \
    ':!:CHANGELOG.md' ':!:*.lock' ':!:*package-lock.json' ':!:runtime/lib/mirror.sh' ':!:ci/check-reqs-mirror.sh'
}

# file:line:<image>:<tag> for every reference
references() {
  # literal references; a trailing $ or { is a variable tag
  files | xargs -0 grep -I -n -o -E "(ghcr\.io/brf-tech/runr|REGISTRY\}?|[\"'\`])/reqs/[a-z0-9._/-]+:[A-Za-z0-9._-]+[\$\{]?" 2>/dev/null \
    | sed -E 's#^([^:]+:[0-9]+:).*/reqs/#\1#' \
    | grep -v -E '[$\{]$' || true

  # Helm values: repository: ghcr.io/brf-tech/runr/reqs/<image> + the tag: of the same block
  # (a block that ends without a tag: "<image>:", any mirrored tag of the image counts)
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

missing=0
for ref in $(printf '%s\n' "${!seen[@]}" | sort); do
  if ! is_mirrored "$ref"; then
    echo "✗ ${REGISTRY}/reqs/${ref} is not mirrored (${where[$ref]})"
    missing=$((missing + 1))
  fi
done

if [ "$missing" -gt 0 ]; then
  echo ""
  echo "Add each as \"<image>:<tag>|<Docker Hub source>\" to REQS_IMAGES in runtime/lib/mirror.sh"
  echo "(the build:runtime CI job copies it with \`ci.sh mirror-reqs\`), or reference a mirrored image."
  exit 1
fi

echo "✓ ${#seen[@]} reqs images referenced, all mirrored (REQS_IMAGES: ${#REQS_IMAGES[@]}, plus the runtime base images)"
