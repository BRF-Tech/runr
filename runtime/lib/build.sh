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

# cmd_build_queue_consumer — Build queue-consumer sidecar image
cmd_build_queue_consumer() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/queue-consumer"
  local tag="${REGISTRY}/queue-consumer:latest"

  if [ ! -d "$dir" ]; then
    echo -e "${RED}queue-consumer directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── queue-consumer (Redis Stream → Knative sink) ──${NC}"

  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push"

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
# the region side of uploaded images (docs/images.md) and the module fetcher of wasm-exec builds
cmd_build_image_upload() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/image-upload"
  local version="${IMAGE_UPLOAD_VERSION:-1.0.0}"
  local tag="${REGISTRY}/image-upload:${version}"

  echo -e "${BOLD}── runr-image-upload (uploaded images: receiver, push, wasm fetch) ──${NC}"
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

# cmd_build_agent — Build runr-agent (Go monitoring agent) image
cmd_build_agent() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/agent"
  local tag="${REGISTRY}/agent:latest"

  if [ ! -d "$dir" ]; then
    echo -e "${RED}agent directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── runr-agent (Go monitoring agent) ──${NC}"

  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push"

  if [ "$push" = "1" ]; then
    cleanup_buildx
  fi

  echo ""
  echo -e "${GREEN}Done.${NC}"
  if [ "$push" = "0" ]; then
    echo "To push: ci.sh build-agent --push"
  fi
}
