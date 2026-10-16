# ── Shared variables and functions ──
# This file is sourced, so it has no shebang.

REGISTRY="${REGISTRY:-ghcr.io/brf-tech/runr}"
EOL_API="https://endoflife.date/api"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

RUNTIME_DEFS=(
  "python|library/python|-slim|3.10|major.minor"
  "nodejs|library/node|-slim|20.0|major.minor"
  "go|library/golang|-alpine|1.22|major.minor"
  "php|library/php|-cli-alpine|8.2|major.minor"
)

fetch_tags() {
  local repo="$1"
  local page=1
  local all_tags=""

  while true; do
    local url="https://hub.docker.com/v2/repositories/${repo}/tags/?page=${page}&page_size=100"
    local response
    response=$(curl -sf "$url" 2>/dev/null) || break

    local tags
    tags=$(echo "$response" | grep -o '"name":"[^"]*"' | sed 's/"name":"//;s/"//') || true
    [ -z "$tags" ] && break
    all_tags="${all_tags} ${tags}"

    echo "$response" | grep -q '"next":null' && break
    page=$((page + 1))
    [ "$page" -gt 10 ] && break
  done

  echo "$all_tags"
}

version_gte() {
  local lower
  lower=$(printf '%s\n%s' "$1" "$2" | sort -V | head -1)
  [ "$lower" = "$2" ]
}

discover_versions() {
  local repo="$1" suffix="$2" min_ver="$3" depth="$4"
  local raw_tags matched=""

  raw_tags=$(fetch_tags "$repo")

  for tag in $raw_tags; do
    [[ "$tag" == *"${suffix}" ]] || continue
    local ver="${tag%${suffix}}"
    [[ "$ver" =~ ^[0-9]+(\.[0-9]+)*$ ]] || continue

    case "$depth" in
      major)       [[ "$ver" =~ ^[0-9]+$ ]] || continue ;;
      major.minor) [[ "$ver" =~ ^[0-9]+\.[0-9]+$ ]] || continue ;;
    esac

    version_gte "$ver" "$min_ver" || continue
    matched="${matched} ${ver}"
  done

  echo "$matched" | tr ' ' '\n' | grep -v '^$' | sort -V | uniq
}

filter_nodejs_lts() {
  local versions="$1"
  local -A latest_minor

  for ver in $versions; do
    local major="${ver%%.*}"
    (( major % 2 != 0 )) && continue
    if [ -z "${latest_minor[$major]:-}" ] || version_gte "$ver" "${latest_minor[$major]}"; then
      latest_minor[$major]="$ver"
    fi
  done

  for major in $(echo "${!latest_minor[@]}" | tr ' ' '\n' | sort -n); do
    echo "${latest_minor[$major]}"
  done
}

# ── Docker Hub and Dockerfile base images ──

# dockerhub_path SOURCE — the Docker Hub repository path of an image: alpine:3.21 →
# library/alpine:3.21; tinygo/tinygo:0.42.0 and a tag@digest stay as they are
dockerhub_path() {
  case "${1%%[:@]*}" in
    */*) echo "$1" ;;
    *) echo "library/$1" ;;
  esac
}

# dockerhub_digest PATH — Docker Hub's digest of a repository path (library/alpine:3.21; a
# path@sha256:… is its own digest), read with a HEAD request, which the anonymous pull limit does
# not count: crane when it is installed, otherwise curl against the registry API
dockerhub_digest() {
  local path="$1" repo ref token digest
  case "$path" in
    *@sha256:*) echo "${path##*@}"; return 0 ;;
  esac
  if command -v crane >/dev/null 2>&1; then
    crane digest "docker.io/${path}" 2>/dev/null
    return
  fi
  repo="${path%:*}"
  ref="${path##*:}"
  token=$(curl -fsSL "https://auth.docker.io/token?service=registry.docker.io&scope=repository:${repo}:pull" 2>/dev/null \
    | sed -n 's/.*"token":"\([^"]*\)".*/\1/p') || return 1
  digest=$(curl -fsSI -H "Authorization: Bearer ${token}" \
    -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json' \
    "https://registry-1.docker.io/v2/${repo}/manifests/${ref}" 2>/dev/null \
    | tr -d '\r' | awk -F': ' 'tolower($1) == "docker-content-digest" { print $2 }')
  [ -n "$digest" ] && echo "$digest"
}

# dockerfile_bases DOCKERFILE [NAME=VALUE...] — the base image of every stage of a Dockerfile,
# ${NAME} replaced by the build argument given or by the default of its ARG before the first FROM.
# Stage names, scratch and references that stay variable (cross-${TARGETARCH}) are left out.
dockerfile_bases() {
  local file="$1"
  shift
  awk -v overrides="$*" '
    BEGIN {
      n = split(overrides, kv, " ")
      for (i = 1; i <= n; i++) {
        eq = index(kv[i], "=")
        if (eq) given[substr(kv[i], 1, eq - 1)] = substr(kv[i], eq + 1)
      }
    }
    function subst(s,   out, m, name, val) {
      out = ""
      while (match(s, /[$]([{][A-Za-z_][A-Za-z0-9_]*[}]|[A-Za-z_][A-Za-z0-9_]*)/)) {
        m = substr(s, RSTART, RLENGTH)
        name = m
        gsub(/[${}]/, "", name)
        if (name in given) val = given[name]
        else if (name in args) val = args[name]
        else { unresolved = 1; val = m }
        out = out substr(s, 1, RSTART - 1) val
        s = substr(s, RSTART + RLENGTH)
      }
      return out s
    }
    toupper($1) == "ARG" && !from {
      eq = index($2, "=")
      if (eq) {
        val = substr($2, eq + 1)
        gsub(/^["\047]|["\047]$/, "", val)
        args[substr($2, 1, eq - 1)] = val
      }
    }
    toupper($1) == "FROM" {
      from = 1
      i = 2
      while ($i ~ /^--/) i++
      unresolved = 0
      ref = subst($i)
      if (!unresolved && ref != "scratch" && !(tolower(ref) in stages)) print ref
      if (tolower($(i + 1)) == "as") stages[tolower($(i + 2))] = 1
    }
  ' "$file"
}

# ── Git version: the tag if there is one, otherwise main-<hash> ──
resolve_app_version() {
  local root="${1:-$(git rev-parse --show-toplevel 2>/dev/null || echo .)}"
  local tag
  tag=$(git -C "$root" describe --tags --exact-match HEAD 2>/dev/null || true)
  if [ -n "$tag" ]; then
    echo "$tag"
  else
    echo "main-$(git -C "$root" rev-parse --short HEAD)"
  fi
}

is_past_date() {
  local date_str="$1"
  local today
  today=$(date +%Y-%m-%d)
  [[ "$date_str" < "$today" || "$date_str" == "$today" ]]
}

check_eol() {
  local runtime="$1" tag="$2"

  local cycle
  cycle=$(echo "$tag" | grep -oE '^[0-9]+\.[0-9]+') || true

  if [ -n "$cycle" ]; then
    local response
    response=$(curl -sf "${EOL_API}/${runtime}/${cycle}.json" 2>/dev/null) || true
    if [ -n "$response" ]; then
      parse_eol_response "$response"
      return
    fi
  fi

  local major="${tag%%.*}"
  local response
  response=$(curl -sf "${EOL_API}/${runtime}/${major}.json" 2>/dev/null) || true
  if [ -n "$response" ]; then
    parse_eol_response "$response"
    return
  fi

  echo "unknown"
}

parse_eol_response() {
  local response="$1"
  local eol_field
  eol_field=$(echo "$response" | grep -o '"eol":[^,}]*' | head -1 | sed 's/"eol"://') || true

  if [[ "$eol_field" =~ ^\"[0-9]{4}-[0-9]{2}-[0-9]{2}\" ]]; then
    local eol_date
    eol_date=$(echo "$eol_field" | tr -d '"')
    if is_past_date "$eol_date"; then
      echo "eol|${eol_date}"
    else
      echo "supported|${eol_date}"
    fi
  elif [ "$eol_field" = "true" ]; then
    echo "eol|true"
  elif [ "$eol_field" = "false" ]; then
    echo "supported|false"
  else
    echo "unknown"
  fi
}
