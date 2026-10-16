# cmd_mirror_reqs  — Mirror upstream images to $REGISTRY/reqs/ as multi-arch
# cmd_mirror_bases — Mirror runtime base images (dynamic version discovery)
#
# Copies the upstream manifest list as is, using crane copy.
# Downloads no layers (registry-to-registry), only manifest + blob redirect.
# Requires: crane (go-containerregistry)
#
# Upstreams (REQS_UPSTREAMS, tried in order): Docker Hub, then pull-through mirrors of it that
# serve its repositories under their own prefix — mirror.gcr.io/library/alpine,
# public.ecr.aws/docker/library/alpine (official images only). When Docker Hub refuses a copy
# (its anonymous pull limit: 429 TOOMANYREQUESTS), the image is copied from the first mirror that
# has Docker Hub's digest of it, by that digest: `crane digest docker.io/…` is a HEAD request,
# which the pull limit does not count, and a copy by digest is the same image or no copy at all.
#
#   REQS_UPSTREAMS="mirror.gcr.io public.ecr.aws/docker" ci.sh mirror-reqs   no Docker Hub pulls
REQS_UPSTREAMS="${REQS_UPSTREAMS:-docker.io mirror.gcr.io public.ecr.aws/docker}"

# target|source — target = $REGISTRY/reqs/{target}, source = a Docker Hub repository with its tag
# (library/ implied for official images), or tag@digest for an image pinned by digest
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

  # ── Region tracing backend: the images of the pinned collector and Tempo charts
  #    (ManagedTracing::COLLECTOR_IMAGE / TEMPO_IMAGE) ──
  "opentelemetry-collector-contrib:0.162.0|otel/opentelemetry-collector-contrib:0.162.0"
  "tempo:3.1.0|grafana/tempo:3.1.0"

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

  # ── WebAssembly build images (runtime/wasm-*) and the Spin shim's Rust toolchain
  #    (runtime/wasm-shim-spin, pinned by digest) ──
  "tinygo:0.42.0|tinygo/tinygo:0.42.0"
  "node:22-bookworm-slim|node:22-bookworm-slim"
  "python:3.13-slim-bookworm|python:3.13-slim-bookworm"
  "rust:1.98.1-slim-bookworm|rust:1.98.1-slim-bookworm"
  "rust:1.95.0-slim-bookworm|rust:1.95.0-slim-bookworm@sha256:d7482085ff5b415f84dba5647ae71606650bdef00db7aeb69f4b3d170c3e4082"

  # ── CUDA base of hf-serve's GPU image (the pytorch / tensorflow GPU runtimes take NVIDIA's pip wheels) ──
  "pytorch:2.14.1-cuda12.6-cudnn9-runtime|pytorch/pytorch:2.14.1-cuda12.6-cudnn9-runtime"

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

# _crane_copy SOURCE TARGET DRY_RUN — SOURCE (a REQS_IMAGES source) from the first upstream of
# REQS_UPSTREAMS that has it; from a mirror only by Docker Hub's digest
_crane_copy() {
  local source="$1" target="$2" dry_run="$3" path upstream want="" err note=""
  path="$(dockerhub_path "$source")"
  if [ "$dry_run" = "1" ]; then
    echo "  [dry-run] docker.io/${path} → ${target}"
    return 0
  fi
  echo -n "  docker.io/${path} → ${target} ... "

  for upstream in $REQS_UPSTREAMS; do
    if [ "$upstream" = "docker.io" ]; then
      if err=$(crane copy "docker.io/${path}" "${target}" 2>&1); then
        echo -e "${GREEN}ok${NC}${note}"
        return 0
      fi
      note="${note} docker.io: $(_crane_error "$err");"
      continue
    fi

    if [ -z "$want" ] && ! want=$(dockerhub_digest "$path"); then
      echo -e "${RED}fail${NC}${note} no Docker Hub digest to check ${upstream} against"
      return 1
    fi
    if err=$(crane copy "${upstream}/${path%%[:@]*}@${want}" "${target}" 2>&1) \
      && [ "$(crane digest "${target}" 2>/dev/null)" = "$want" ]; then
      echo -e "${GREEN}ok${NC}${note} from ${upstream}, Docker Hub's digest ${want}"
      return 0
    fi
    note="${note} ${upstream}: $(_crane_error "$err");"
  done

  echo -e "${RED}fail${NC}${note}"
  return 1
}

# the last line of crane's error output: the registry's error code and message when it has one
# (TOOMANYREQUESTS: …, NAME_UNKNOWN: …), else the line without its log prefix
_crane_error() {
  local line
  line=$(printf '%s\n' "$1" | tail -n 1 | sed 's/^Error: //')
  if [[ "$line" =~ ([A-Z][A-Z_]{4,}:.*)$ ]]; then
    line="${BASH_REMATCH[1]}"
  fi
  printf '%s\n' "${line:0:200}"
}

# cmd_mirror_reqs [--dry-run] [IMAGE...] — every REQS_IMAGES entry, or the ones named (target
# image:tag or image name)
cmd_mirror_reqs() {
  local dry_run=0 only=()
  for arg in "$@"; do
    case "$arg" in
      --dry-run) dry_run=1 ;;
      -*) echo -e "${RED}unknown argument: ${arg} (--dry-run, image names)${NC}"; exit 1 ;;
      *) only+=("$arg") ;;
    esac
  done

  _require_crane

  echo -e "${BOLD}Upstream → ${REGISTRY}/reqs/ multi-arch mirror${NC} (upstreams: ${REQS_UPSTREAMS})"
  echo ""

  local ok=0 fail=0 entry target source name
  for entry in "${REQS_IMAGES[@]}"; do
    IFS='|' read -r target source <<< "$entry"
    if [ "${#only[@]}" -gt 0 ]; then
      local wanted=0
      for name in "${only[@]}"; do
        if [ "$name" = "$target" ] || [ "$name" = "${target%%:*}" ]; then
          wanted=1
        fi
      done
      [ "$wanted" = "1" ] || continue
    fi
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
    echo -e "${RED}Done. ${ok} ok, ${fail} failed.${NC}"
    return 1
  fi
}

# Runtime base images — discover versions dynamically, then mirror
cmd_mirror_bases() {
  local dry_run=0
  for arg in "$@"; do
    case "$arg" in --dry-run) dry_run=1 ;; esac
  done

  _require_crane

  echo -e "${BOLD}Runtime base images → ${REGISTRY}/reqs/ multi-arch mirror${NC} (upstreams: ${REQS_UPSTREAMS})"
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
    echo -e "${RED}Done. ${ok} ok, ${fail} failed.${NC}"
    return 1
  fi
}
