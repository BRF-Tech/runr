# ── Multiplatform buildx ─────────────────────────────────────────────────────
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
# The docker-container builder of --push builds: created when missing, named on each build
# (--builder) and removed after the push. It never becomes the host's current builder, so other
# builds on a shared host keep theirs.
BUILDER_NAME="${BUILDER_NAME:-runr-multiarch}"

ensure_buildx() {
  if ! docker buildx inspect "$BUILDER_NAME" >/dev/null 2>&1; then
    echo "Creating buildx builder (${BUILDER_NAME})..."
    docker buildx create --name "$BUILDER_NAME" --driver docker-container
    docker buildx inspect --bootstrap "$BUILDER_NAME" >/dev/null
  fi
}

cleanup_buildx() {
  if docker buildx inspect "$BUILDER_NAME" >/dev/null 2>&1; then
    echo "Removing buildx builder (${BUILDER_NAME})..."
    docker buildx rm "$BUILDER_NAME" 2>/dev/null || true
  fi
}

# Build helper — multiplatform push or local single-platform. --pull: base images come from their
# registry, never from a copy the build host's Docker already holds (an old pull of a
# ghcr.io/brf-tech/runr/reqs tag would otherwise be built on); a caller's --pull=false, after it, wins.
do_build() {
  local tag="$1" context="$2" push="$3"
  shift 3
  # Remaining args: extra docker build flags (e.g. --build-arg)

  if [ "$push" = "1" ]; then
    ensure_buildx
    docker buildx build \
      --builder "${BUILDER_NAME}" \
      --pull \
      --platform "${PLATFORMS}" \
      --provenance=false \
      --sbom=false \
      "$@" \
      -t "${tag}" \
      --output "type=image,push=true,oci-mediatypes=true" \
      "${context}"
  else
    docker build --pull "$@" -t "${tag}" "${context}"
  fi
}

# The release's version: helm/Chart.yaml appVersion (RELEASE_VERSION overrides it). The region agent
# and the event gateway / queue consumer are published at it, like the API and UI images: the API
# runs them at its own version (App\Support\Images\ReleaseImages, config/knative.php)
release_version() {
  if [ -n "${RELEASE_VERSION:-}" ]; then
    echo "$RELEASE_VERSION"
    return 0
  fi
  sed -n 's/^appVersion:[[:space:]]*"\{0,1\}\([^"[:space:]]*\)"\{0,1\}[[:space:]]*$/\1/p' "${SCRIPT_DIR}/../helm/Chart.yaml" | head -n 1
}

# The pinned versions of the images that have their own (config/api_product.php, config/images.php,
# config/org_transfer.php; the Dockerfiles' ARG VERSION)
gateway_auth_version() { echo "${GATEWAY_AUTH_VERSION:-1.0.0}"; }
image_upload_version() { echo "${IMAGE_UPLOAD_VERSION:-1.1.0}"; }

# cmd_build_queue_consumer — Build the event gateway / queue consumer image: <release version> and latest
cmd_build_queue_consumer() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/queue-consumer"
  local tag
  tag="${REGISTRY}/queue-consumer:$(release_version)"

  if [ ! -d "$dir" ]; then
    echo -e "${RED}queue-consumer directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── queue-consumer (Redis Stream → Knative sink) ──${NC}"

  echo "==> Building ${tag} (and :latest)"
  do_build "${tag}" "${dir}" "$push" -t "${REGISTRY}/queue-consumer:latest"

  if [ "$push" = "1" ]; then
    cleanup_buildx
  fi

  echo ""
  echo -e "${GREEN}Done.${NC}"
  if [ "$push" = "0" ]; then
    echo "To push: ci.sh queue-consumer --push"
  fi
}

# cmd_build_image_upload — Build the image upload receiver (runr-image-upload, FROM scratch):
# the region side of uploaded images (docs/images.md), the transfer agent of organization exports
# and imports (docs/org-export.md, since 1.1.0) and the module fetcher of wasm-exec builds
cmd_build_image_upload() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/image-upload"
  local version
  version="$(image_upload_version)"
  local tag="${REGISTRY}/image-upload:${version}"

  echo -e "${BOLD}── runr-image-upload (uploaded images: receiver, push, wasm fetch; org transfer agent) ──${NC}"
  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push" --build-arg "VERSION=${version}"

  if [ "$push" = "1" ]; then
    cleanup_buildx
  fi

  echo ""
  echo -e "${GREEN}Done.${NC}"
  if [ "$push" = "0" ]; then
    echo "Push: ci.sh image-upload --push"
  fi
  return 0
}

# cmd_build_gateway_auth — Build the API product service of the regions (runr-gateway-auth, FROM
# scratch): Traefik forwardAuth for key / JWT gateway routes, plan limits, usage reports (docs/api-product.md)
cmd_build_gateway_auth() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/gateway-auth"
  local version
  version="$(gateway_auth_version)"
  local tag="${REGISTRY}/gateway-auth:${version}"

  echo -e "${BOLD}── runr-gateway-auth (API keys, JWT, limits, usage) ──${NC}"
  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push" --build-arg "VERSION=${version}"

  if [ "$push" = "1" ]; then
    cleanup_buildx
  fi

  echo ""
  echo -e "${GREEN}Done.${NC}"
  if [ "$push" = "0" ]; then
    echo "Push: ci.sh gateway-auth --push"
  fi
  return 0
}

# cmd_build_agent — Build runr-agent (Go monitoring agent, also the internal network's authz):
# <release version> and latest
cmd_build_agent() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/agent"
  local tag
  tag="${REGISTRY}/agent:$(release_version)"

  if [ ! -d "$dir" ]; then
    echo -e "${RED}agent directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── runr-agent (Go monitoring agent) ──${NC}"

  echo "==> Building ${tag} (and :latest)"
  do_build "${tag}" "${dir}" "$push" -t "${REGISTRY}/agent:latest"

  if [ "$push" = "1" ]; then
    cleanup_buildx
  fi

  echo ""
  echo -e "${GREEN}Done.${NC}"
  if [ "$push" = "0" ]; then
    echo "To push: ci.sh build-agent --push"
  fi
}

# The images of the regions a release references by version — the defaults of config/ and
# App\Services\Wasm\WasmShim: "<command> <reference>" per line. The API and UI images (api, ui,
# ui-mt at the same version) come from the release build of the console; runtime/test/
# release_images_test.sh keeps this list equal to what the API references.
release_images() {
  local v
  v="$(release_version)"
  echo "build-agent ${REGISTRY}/agent:${v}"
  echo "queue-consumer ${REGISTRY}/queue-consumer:${v}"
  echo "gateway-auth ${REGISTRY}/gateway-auth:$(gateway_auth_version)"
  echo "image-upload ${REGISTRY}/image-upload:$(image_upload_version)"
  echo "supply-chain $(supply_chain_image_tag)"
  echo "wasm-shim $(wasm_shim_image)"
}

# cmd_release_images — the images of the regions a release publishes (release_images):
#   ci.sh release-images            the list (--list)
#   ci.sh release-images --push     builds and pushes each one the registry does not have yet
#                                   (docker buildx imagetools inspect); --force: every one
cmd_release_images() {
  local push=0 force=0
  for arg in "$@"; do
    case "$arg" in
      --list) ;;
      --push) push=1 ;;
      --force) force=1 ;;
      *) echo -e "${RED}unknown argument: ${arg} (--list, --push, --force)${NC}"; exit 1 ;;
    esac
  done
  if [ -z "$(release_version)" ]; then
    echo -e "${RED}no appVersion in helm/Chart.yaml (or RELEASE_VERSION)${NC}"
    exit 1
  fi

  if [ "$push" = "0" ]; then
    release_images | awk '{ print $2 }'
    return 0
  fi

  local cmd ref
  while read -r cmd ref; do
    if [ "$force" = "0" ] && docker buildx imagetools inspect "$ref" >/dev/null 2>&1; then
      echo "✓ ${ref} is on the registry"
      continue
    fi
    case "$cmd" in
      build-agent)    cmd_build_agent --push ;;
      queue-consumer) cmd_build_queue_consumer --push ;;
      gateway-auth)   cmd_build_gateway_auth --push ;;
      image-upload)   cmd_build_image_upload --push ;;
      supply-chain)   cmd_build_supply_chain --push ;;
      wasm-shim)      cmd_build_wasm_shim --push ;;
    esac
  done < <(release_images)
}
