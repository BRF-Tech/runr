# shellcheck shell=bash
# Downloads of the dependency test bot that survive an upstream's bad minute (the filex bot's
# #1808): sourced by ci/deps/bump-packages.sh (the checksums a pin bump moves). No shebang.
#
# deps_fetch URL OUT — URL into OUT. A transient failure (a timeout, HTTP 408, 429 or 5xx, a refused
# connection) is tried again DEPS_FETCH_RETRIES times (default 4), DEPS_FETCH_RETRY_DELAY seconds
# apart (default 5, curl's own retries: no polling loop). A GitHub release asset
# (https://github.com/OWNER/REPO/releases/download/TAG/NAME) that still answers 5xx or 429, or no
# answer at all, comes once through the GitHub API instead: the release by its tag, the asset by
# its name, Accept: application/octet-stream (GH_TOKEN, when set, raises the API's rate limit). A
# 404 or another 4xx ends at once: the file does not exist. Exit 1 and "download failed: URL (HTTP
# CODE)" on stderr when nothing answered; OUT is then removed. Needs curl and jq.

DEPS_FETCH_RETRIES="${DEPS_FETCH_RETRIES:-4}"
DEPS_FETCH_RETRY_DELAY="${DEPS_FETCH_RETRY_DELAY:-5}"
DEPS_GITHUB_API="${DEPS_GITHUB_API:-https://api.github.com}"

# deps_fetch_curl ARGS… — curl with the retries of a transient failure
deps_fetch_curl() {
  curl -fsSL --retry "$DEPS_FETCH_RETRIES" --retry-delay "$DEPS_FETCH_RETRY_DELAY" --retry-connrefused \
    --connect-timeout 30 --max-time 300 "$@"
}

deps_fetch() {
  local url="$1" out="$2" code path repo rest tag name asset
  local -a auth=()
  code="$(deps_fetch_curl -w '%{http_code}' -o "$out" "$url" 2>/dev/null)" && return 0
  code="${code: -3}"
  case "$url" in
    https://github.com/*/*/releases/download/*/*)
      case "$code" in 4[0-9][0-9]) [ "$code" = 429 ] || { rm -f -- "$out"; echo "download failed: ${url} (HTTP ${code})" >&2; return 1; } ;; esac
      path="${url#https://github.com/}"
      repo="${path%%/releases/download/*}"
      rest="${path#*/releases/download/}"
      tag="${rest%/*}"
      name="${rest##*/}"
      echo "${url}: HTTP ${code:-000} after its retries, the release asset through the GitHub API instead" >&2
      [ -z "${GH_TOKEN:-}" ] || auth=(-H "Authorization: Bearer ${GH_TOKEN}")
      asset="$(deps_fetch_curl ${auth[@]+"${auth[@]}"} -H 'Accept: application/vnd.github+json' \
        "${DEPS_GITHUB_API}/repos/${repo}/releases/tags/${tag}" 2>/dev/null \
        | jq -r --arg n "$name" '[.assets[]? | select(.name == $n) | .url] | first // empty' 2>/dev/null)" || asset=""
      if [[ "$asset" == "${DEPS_GITHUB_API}/repos/${repo}/releases/assets/"* ]] \
        && deps_fetch_curl ${auth[@]+"${auth[@]}"} -H 'Accept: application/octet-stream' -o "$out" "$asset" 2>/dev/null; then
        return 0
      fi ;;
  esac
  rm -f -- "$out"
  echo "download failed: ${url} (HTTP ${code:-000})" >&2
  return 1
}

# deps_release_digest URL — the sha256 GitHub publishes for a release asset (the release's API
# answer, its asset's digest), without its sha256: prefix
deps_release_digest() {
  local url="$1" path repo rest tag name
  local -a auth=()
  [[ "$url" == https://github.com/*/*/releases/download/*/* ]] || return 1
  path="${url#https://github.com/}"
  repo="${path%%/releases/download/*}"
  rest="${path#*/releases/download/}"
  tag="${rest%/*}"
  name="${rest##*/}"
  [ -z "${GH_TOKEN:-}" ] || auth=(-H "Authorization: Bearer ${GH_TOKEN}")
  deps_fetch_curl ${auth[@]+"${auth[@]}"} -H 'Accept: application/vnd.github+json' "${DEPS_GITHUB_API}/repos/${repo}/releases/tags/${tag}" 2>/dev/null \
    | jq -r --arg n "$name" '[.assets[]? | select(.name == $n) | .digest // empty][0] // empty | sub("^sha256:"; "")' 2>/dev/null \
    | grep -E '^[0-9a-f]{64}$'
}
