# cmd_mirror_reqs  — Mirror upstream images to $REGISTRY/reqs/ as multi-arch
# cmd_mirror_bases — Mirror runtime base images (dynamic version discovery)
#
# Copies the upstream manifest list as is, using crane copy.
# Downloads no layers (registry-to-registry), only manifest + blob redirect.
# Requires: crane (go-containerregistry)

# target|source — target = $REGISTRY/reqs/{target}, source = docker.io/{source}
#
# Every $REGISTRY/reqs/ image the product, the Helm chart, the Compose files, CI and the e2e
# reference is either here or a runtime base image of cmd_mirror_bases (RUNTIME_DEFS):
# ci/check-reqs-mirror.sh fails otherwise. The build:runtime CI job runs mirror-reqs.
REQS_IMAGES=(
  # ── Utility (Knative pods, init containers, sidecars, CaaS test image) ──
  "alpine:3.20|alpine:3.20"
  "alpine:3.21|alpine:3.21"
  "redis:7-alpine|redis:7-alpine"
  "caddy:2-alpine|caddy:2-alpine"
  "curl:latest|curlimages/curl:latest"
  "busybox:latest|busybox:latest"
  "nginx:1.27-alpine|nginx:1.27-alpine"

  # ── Region ingress: the image of the pinned Traefik chart (KnativeConstants::TRAEFIK_IMAGE) ──
  "traefik:v3.7.4|traefik:v3.7.4"

  # ── Bindings data services (docs/bindings.md) ──
  "garage:v2.1.0|dxflrs/garage:v2.1.0"
  "rclone:1.68.2|rclone/rclone:1.68.2"

  # ── Example runtimes (examples/runtimes) ──
  "bash:5.2|bash:5.2"
  "rust:1.83-alpine|rust:1.83-alpine"

  # ── Compose stacks (docker-compose.yml, compose/), Helm chart ──
  "postgres:17-alpine|postgres:17-alpine"
  "mailpit:latest|axllent/mailpit:latest"

  # ── Dockerfile base images ──
  "frankenphp:1-php8.3-alpine|dunglas/frankenphp:1-php8.3-alpine"
  "composer:latest|composer:latest"
  "composer:2.7|composer:2.7"
  "php:8.3-fpm-alpine|php:8.3-fpm-alpine"
  "node:20-alpine|node:20-alpine"
  "node:22-alpine|node:22-alpine"
  "golang:1.26-alpine|golang:1.26-alpine"

  # ── FaaS kaniko build base images ──
  "python:3.12-slim|python:3.12-slim"
  "node:22-slim|node:22-slim"

  # ── CI images ──
  "docker:27|docker:27"
  "docker:27-dind|docker:27-dind"
  "php:8.2-cli-alpine|php:8.2-cli-alpine"
  "helm:3.15|alpine/helm:3.15"
  "binfmt:latest|tonistiigi/binfmt:latest"

  # ── Test ──
  "kindest-node:v1.32.0|kindest/node:v1.32.0"
)

_require_crane() {
  if ! command -v crane &>/dev/null; then
    echo -e "${RED}crane not found. Install:${NC}"
    echo '  curl -fsSL "https://github.com/google/go-containerregistry/releases/latest/download/go-containerregistry_Linux_x86_64.tar.gz" | sudo tar xz -C /usr/local/bin crane'
    exit 1
  fi
}

_crane_copy() {
  local source="$1" target="$2" dry_run="$3"
  if [ "$dry_run" = "1" ]; then
    echo "  [dry-run] ${source} → ${target}"
    return 0
  fi
  echo -n "  ${source} → ${target} ... "
  if crane copy "docker.io/${source}" "${target}" 2>/dev/null; then
    echo -e "${GREEN}ok${NC}"
    return 0
  else
    echo -e "${RED}fail${NC}"
    return 1
  fi
}

cmd_mirror_reqs() {
  local dry_run=0
  for arg in "$@"; do
    case "$arg" in --dry-run) dry_run=1 ;; esac
  done

  _require_crane

  echo -e "${BOLD}Upstream → ${REGISTRY}/reqs/ multi-arch mirror${NC}"
  echo ""

  local ok=0 fail=0
  for entry in "${REQS_IMAGES[@]}"; do
    IFS='|' read -r target source <<< "$entry"
    if _crane_copy "$source" "${REGISTRY}/reqs/${target}" "$dry_run"; then
      ok=$((ok + 1))
    else
      fail=$((fail + 1))
    fi
  done

  echo ""
  if [ "$fail" = "0" ]; then
    echo -e "${GREEN}Done. ${ok} images mirrored.${NC}"
  else
    echo -e "${YELLOW}Done. ${ok} ok, ${fail} failed.${NC}"
  fi
}

# Runtime base images — discover versions dynamically, then mirror
cmd_mirror_bases() {
  local dry_run=0
  for arg in "$@"; do
    case "$arg" in --dry-run) dry_run=1 ;; esac
  done

  _require_crane

  echo -e "${BOLD}Runtime base images → ${REGISTRY}/reqs/ multi-arch mirror${NC}"
  echo ""

  # Runtime → Docker Hub repo suffix mapping
  local -A SUFFIX_MAP=(
    [python]="-slim"
    [nodejs]="-slim"
    [go]="-alpine"
    [php]="-cli-alpine"
  )
  local -A REPO_MAP=(
    [python]="python"
    [nodejs]="node"
    [go]="golang"
    [php]="php"
  )

  local ok=0 fail=0

  for def in "${RUNTIME_DEFS[@]}"; do
    IFS='|' read -r runtime repo suffix min_ver depth <<< "$def"
    local img_name="${REPO_MAP[$runtime]}"
    local img_suffix="${SUFFIX_MAP[$runtime]}"

    echo -e "${BOLD}── ${runtime} (${img_name}:*${img_suffix}) ──${NC}"
    local versions
    versions=$(discover_versions "$repo" "$suffix" "$min_ver" "$depth")

    if [ "$runtime" = "nodejs" ]; then
      versions=$(filter_nodejs_lts "$versions")
    fi

    for ver in $versions; do
      local tag="${img_name}:${ver}${img_suffix}"
      if _crane_copy "$tag" "${REGISTRY}/reqs/${tag}" "$dry_run"; then
        ok=$((ok + 1))
      else
        fail=$((fail + 1))
      fi
    done
    echo ""
  done

  if [ "$fail" = "0" ]; then
    echo -e "${GREEN}Done. ${ok} base images mirrored.${NC}"
  else
    echo -e "${YELLOW}Done. ${ok} ok, ${fail} failed.${NC}"
  fi
}
