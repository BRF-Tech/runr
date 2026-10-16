# cmd_eol — EOL report of the runtime images + optional prune
#
# The images are the ones `ci.sh build --push` publishes: ${REGISTRY}/exec-<runtime> for the
# runtimes in RUNTIME_DEFS (REGISTRY defaults to ghcr.io/brf-tech/runr). Their tags are read from that
# registry through the OCI distribution API (GET /v2/<repo>/tags/list with the registry's token
# service), not from Docker Hub — Docker Hub only hosts the upstream base images.
#
# --prune deletes the EOL versions through the GitHub Packages API (GHCR has no tag delete in the
# OCI API): GHCR_TOKEN needs delete:packages; GHCR_OWNER_KIND=users for a user-owned namespace.
# GHCR_USERNAME + GHCR_TOKEN also authenticate the tag listing of private packages.

# "ghcr.io/brf-tech/runr" → "ghcr.io" (Docker Hub's API host is registry-1.docker.io)
registry_api_host() {
  local host="${1%%/*}"
  case "$host" in
    docker.io|index.docker.io) echo "registry-1.docker.io" ;;
    *) echo "$host" ;;
  esac
}

# "ghcr.io/brf-tech/runr" → "brf-tech/runr" ("" for a registry without a namespace)
registry_namespace() {
  local ref="$1"
  if [[ "$ref" == */* ]]; then echo "${ref#*/}"; else echo ""; fi
}

# registry_token <api-host> <repository> — a pull token from the registry's token service
# (WWW-Authenticate: Bearer realm="…",service="…"); empty when the registry asks for none
registry_token() {
  local host="$1" repo="$2" challenge realm service
  challenge=$(curl -s -o /dev/null -D - "https://${host}/v2/" 2>/dev/null | tr -d '\r' | grep -i '^www-authenticate:' || true)
  [[ "$challenge" =~ realm=\"([^\"]+)\" ]] || return 0
  realm="${BASH_REMATCH[1]}"
  service="$host"
  if [[ "$challenge" =~ service=\"([^\"]+)\" ]]; then service="${BASH_REMATCH[1]}"; fi

  local auth=()
  if [ "$host" = "ghcr.io" ] && [ -n "${GHCR_USERNAME:-}" ] && [ -n "${GHCR_TOKEN:-}" ]; then
    auth=(-u "${GHCR_USERNAME}:${GHCR_TOKEN}")
  fi
  curl -sf ${auth[@]+"${auth[@]}"} "${realm}?service=${service}&scope=repository:${repo}:pull" 2>/dev/null \
    | grep -o '"token" *: *"[^"]*"' | head -1 | sed 's/^"token" *: *"//;s/"$//' || true
}

# registry_tags <api-host> <repository> — every tag of the repository, one per line
# (tags/list, following the Link: <…>; rel="next" pages)
registry_tags() {
  local host="$1" repo="$2" token
  token=$(registry_token "$host" "$repo")

  local auth=()
  if [ -n "$token" ]; then auth=(-H "Authorization: Bearer ${token}"); fi

  local path="/v2/${repo}/tags/list?n=1000" pages=0 headers body
  headers=$(mktemp)
  while [ -n "$path" ] && [ "$pages" -lt 20 ]; do
    : > "$headers"
    body=$(curl -sf ${auth[@]+"${auth[@]}"} -D "$headers" "https://${host}${path}" 2>/dev/null) || break
    echo "$body" | grep -o '"tags" *: *\[[^]]*\]' | sed 's/^[^[]*\[//;s/\]$//' | tr ',' '\n' | tr -d '" ' | grep -v '^$' || true
    path=$(tr -d '\r' < "$headers" | grep -i '^link:' | grep -o '<[^>]*>' | head -1 | tr -d '<>' || true)
    pages=$((pages + 1))
  done
  rm -f "$headers"
}

cmd_eol() {
  local prune=0 yes=0
  for arg in "$@"; do
    case "$arg" in
      --prune) prune=1 ;;
      --yes)   yes=1 ;;
    esac
  done

  local host ns
  host=$(registry_api_host "$REGISTRY")
  ns=$(registry_namespace "$REGISTRY")
  local eol_list=()
  local supported_list=()
  local found=0

  echo ""
  echo -e "${BOLD}EOL Check — ${REGISTRY}/exec-*${NC}"
  echo -e "${BOLD}$(date +%Y-%m-%d)${NC}"
  echo ""

  local def runtime rest
  for def in "${RUNTIME_DEFS[@]}"; do
    IFS='|' read -r runtime rest <<< "$def"
    local repo="exec-${runtime}"
    echo -e "${BOLD}── ${REGISTRY}/${repo} ──${NC}"

    local tags
    tags=$(registry_tags "$host" "${ns:+${ns}/}${repo}" | grep -E '^[0-9]+(\.[0-9]+)*$' | sort -V | uniq || true)
    if [ -z "$tags" ]; then
      echo -e "  ${YELLOW}No version tags found${NC}"
      echo ""
      continue
    fi
    found=1

    local tag
    for tag in $tags; do
      local result
      result=$(check_eol "$runtime" "$tag")
      local status="${result%%|*}"
      local eol_date="${result#*|}"
      [ "$status" = "$result" ] && eol_date="?"

      case "$status" in
        supported)
          printf "  %-10s %-14s ${GREEN}%s${NC}\n" "$tag" "$eol_date" "✓ supported"
          supported_list+=("${runtime}:${tag}")
          ;;
        eol)
          printf "  %-10s %-14s ${RED}%s${NC}\n" "$tag" "$eol_date" "✗ EOL"
          eol_list+=("${repo}:${tag}")
          ;;
        *)
          printf "  %-10s %-14s %s\n" "$tag" "?" "? unknown"
          ;;
      esac
    done
    echo ""
  done

  if [ "$found" = "0" ]; then
    echo "No ${REGISTRY}/exec-* image tags found."
    exit 1
  fi

  echo -e "${BOLD}── Summary ──${NC}"
  echo -e "  ${GREEN}Supported: ${#supported_list[@]}${NC}"
  echo -e "  ${RED}EOL: ${#eol_list[@]}${NC}"
  echo ""

  if [ "$prune" = "1" ] && [ "${#eol_list[@]}" -gt 0 ]; then
    prune_eol_tags "$yes" "${eol_list[@]}"
  fi
}

# prune_eol_tags <yes> <repo:tag>… — delete the EOL versions of ghcr.io/<owner>[/<path>]/<repo>.
# The GitHub package of ghcr.io/brf-tech/runr/exec-python is "runr/exec-python" of the owner
# brf-tech. A package version is one manifest: it is deleted only when every tag it carries is EOL.
prune_eol_tags() {
  local yes="$1"
  shift
  local host ns owner path=""
  host=$(registry_api_host "$REGISTRY")
  ns=$(registry_namespace "$REGISTRY")
  owner="${ns%%/*}"
  [[ "$ns" == */* ]] && path="${ns#*/}/"

  if [ "$host" != "ghcr.io" ] || [ -z "$ns" ]; then
    echo -e "${RED}--prune supports GHCR (ghcr.io/<owner>) only; delete the EOL tags of ${REGISTRY} in that registry.${NC}"
    exit 1
  fi
  : "${GHCR_TOKEN:?Set GHCR_TOKEN (a GitHub token with delete:packages)}"
  if ! command -v jq >/dev/null 2>&1; then
    echo -e "${RED}--prune needs jq.${NC}"
    exit 1
  fi

  if [ "$yes" = "0" ]; then
    echo -e "${YELLOW}These tags will be deleted:${NC}"
    local e
    for e in "$@"; do echo "  - ${REGISTRY}/${e}"; done
    echo ""
    read -rp "Continue? [y/N] " confirm
    [[ "$confirm" =~ ^[yY]$ ]] || { echo "Cancelled."; exit 0; }
  fi

  local api="https://api.github.com/${GHCR_OWNER_KIND:-orgs}/${owner}/packages/container"
  local gh=(-H "Authorization: Bearer ${GHCR_TOKEN}" -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28")
  local eol=" $* "
  local entry
  for entry in "$@"; do
    local repo="${entry%%:*}" tag="${entry#*:}"
    local package="${path}${repo}"
    package="${package//\//%2F}"
    echo -n "  Deleting ${REGISTRY}/${repo}:${tag}... "

    # The package version carrying the tag: "<id> <tag,tag,…>"
    local match="" page=1 versions
    while [ -z "$match" ] && [ "$page" -le 20 ]; do
      versions=$(curl -sf "${gh[@]}" "${api}/${package}/versions?per_page=100&page=${page}" 2>/dev/null) || break
      [ "$(echo "$versions" | jq 'length')" = "0" ] && break
      match=$(echo "$versions" | jq -r --arg t "$tag" '.[] | select((.metadata.container.tags // []) | index($t)) | "\(.id) \(.metadata.container.tags | join(","))"' | head -1)
      page=$((page + 1))
    done
    if [ -z "$match" ]; then
      echo -e "${YELLOW}not found${NC}"
      continue
    fi

    local id="${match%% *}" other keep=""
    for other in $(echo "${match#* }" | tr ',' ' '); do
      [[ "$eol" == *" ${repo}:${other} "* ]] || keep="${keep:+${keep}, }${other}"
    done
    if [ -n "$keep" ]; then
      echo -e "${YELLOW}kept (the image is also tagged ${keep})${NC}"
      continue
    fi

    local status_code
    status_code=$(curl -s -o /dev/null -w "%{http_code}" -X DELETE "${gh[@]}" "${api}/${package}/versions/${id}") || true
    if [ "$status_code" = "204" ]; then
      echo -e "${GREEN}done${NC}"
    else
      echo -e "${RED}failed (HTTP ${status_code})${NC}"
    fi
  done
}
