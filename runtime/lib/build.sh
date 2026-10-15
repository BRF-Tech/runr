# ── Multiplatform buildx ─────────────────────────────────────────────────────
PLATFORMS="${PLATFORMS:-linux/amd64,linux/arm64}"
BUILDER_NAME="runr-multiarch"

ensure_buildx() {
  if ! docker buildx inspect "$BUILDER_NAME" >/dev/null 2>&1; then
    echo "Creating buildx builder (${BUILDER_NAME})..."
    docker buildx create --name "$BUILDER_NAME" --driver docker-container --use
    docker buildx inspect --bootstrap "$BUILDER_NAME" >/dev/null
  else
    docker buildx use "$BUILDER_NAME"
  fi
}

cleanup_buildx() {
  if docker buildx inspect "$BUILDER_NAME" >/dev/null 2>&1; then
    echo "Removing buildx builder (${BUILDER_NAME})..."
    docker buildx use default 2>/dev/null || true
    docker buildx rm "$BUILDER_NAME" 2>/dev/null || true
  fi
}

# Build helper — multiplatform push or local single-platform
do_build() {
  local tag="$1" context="$2" push="$3"
  shift 3
  # Remaining args: extra docker build flags (e.g. --build-arg)

  if [ "$push" = "1" ]; then
    ensure_buildx
    docker buildx build \
      --platform "${PLATFORMS}" \
      --provenance=false \
      --sbom=false \
      "$@" \
      -t "${tag}" \
      --output "type=image,push=true,oci-mediatypes=true" \
      "${context}"
  else
    docker build "$@" -t "${tag}" "${context}"
  fi
}

# cmd_build — Discover + EOL check + build images

cmd_build() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  echo "Fetching available versions from Docker Hub..."
  echo ""

  local total=0
  declare -A DISCOVERED

  for def in "${RUNTIME_DEFS[@]}"; do
    IFS='|' read -r runtime repo suffix min_ver depth <<< "$def"

    echo -e "${BOLD}── ${runtime} (>= ${min_ver}, ${depth}) ──${NC}"
    versions=$(discover_versions "$repo" "$suffix" "$min_ver" "$depth")

    if [ -z "$versions" ]; then
      echo -e "  ${YELLOW}Warning: could not fetch versions from Docker Hub, skipping.${NC}"
      echo ""
      continue
    fi

    if [ "$runtime" = "nodejs" ]; then
      versions=$(filter_nodejs_lts "$versions")
    fi

    local active_versions=""
    for ver in $versions; do
      local result
      result=$(check_eol "$runtime" "$ver")
      local status="${result%%|*}"

      if [ "$status" = "eol" ]; then
        echo -e "  ${RED}✗ ${ver} (EOL — skipping build)${NC}"
      else
        echo -e "  ${GREEN}✓ ${ver}${NC}"
        active_versions="${active_versions} ${ver}"
      fi
    done

    DISCOVERED[$runtime]="$active_versions"
    echo ""
  done

  echo "========================================="
  echo "Starting build..."
  echo "========================================="
  echo ""

  for def in "${RUNTIME_DEFS[@]}"; do
    IFS='|' read -r runtime repo suffix min_ver depth <<< "$def"
    versions="${DISCOVERED[$runtime]:-}"
    [ -z "$versions" ] && continue

    for version in $versions; do
      tag="${REGISTRY}/exec-${runtime}:${version}"

      # Local check: do not skip in push mode (multi-arch rebuild is needed)
      if [ "$push" = "0" ] && docker image inspect "${tag}" >/dev/null 2>&1; then
        echo "==> Skipping ${tag} (already exists locally)"
        echo ""
        continue
      fi

      echo "==> Building ${tag}"
      do_build "${tag}" "${SCRIPT_DIR}/${runtime}" "$push" \
        --build-arg "VERSION=${version}"
      total=$((total + 1))
      echo ""
    done
  done

  [ "$push" = "1" ] && cleanup_buildx

  echo "========================================="
  echo "Done. ${total} images built."
  [ "$push" = "0" ] && echo "To push: ci.sh build --push"
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
  local tag="${REGISTRY}/runr-queue-consumer:latest"

  if [ ! -d "$dir" ]; then
    echo -e "${RED}queue-consumer directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── queue-consumer (Redis Stream → Knative sink) ──${NC}"

  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push"

  [ "$push" = "1" ] && cleanup_buildx

  echo ""
  echo -e "${GREEN}Done.${NC}"
  [ "$push" = "0" ] && echo "To push: ci.sh queue-consumer --push"
}

# cmd_build_wrapper — Build the universal runtime wrapper image (runr-wrapper, FROM scratch)
cmd_build_wrapper() {
  local push=0
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
    esac
  done

  local dir="${SCRIPT_DIR}/wrapper"
  local tag="${REGISTRY}/runr-wrapper:${WRAPPER_VERSION:-1.1.0}"

  if [ ! -d "$dir" ]; then
    echo -e "${RED}wrapper directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── runr-wrapper (universal runtime wrapper, exec / persistent / http) ──${NC}"

  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push"

  [ "$push" = "1" ] && cleanup_buildx

  echo ""
  echo -e "${GREEN}Done.${NC}"
  [ "$push" = "0" ] && echo "To push: ci.sh wrapper --push"
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

  [ "$push" = "1" ] && cleanup_buildx

  echo ""
  echo -e "${GREEN}Done.${NC}"
  [ "$push" = "0" ] && echo "Push: ci.sh image-upload --push"
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
  local tag="${REGISTRY}/runr-agent:latest"

  if [ ! -d "$dir" ]; then
    echo -e "${RED}agent directory not found: ${dir}${NC}"
    exit 1
  fi

  echo -e "${BOLD}── runr-agent (Go monitoring agent) ──${NC}"

  echo "==> Building ${tag}"
  do_build "${tag}" "${dir}" "$push"

  [ "$push" = "1" ] && cleanup_buildx

  echo ""
  echo -e "${GREEN}Done.${NC}"
  [ "$push" = "0" ] && echo "To push: ci.sh build-agent --push"
}
