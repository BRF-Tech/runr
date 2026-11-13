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

# The repository RunR's images are built from. As the org.opencontainers.image.source label of an
# image and as an annotation of its multi-arch index it links the image's ghcr package to the
# repository (the Dockerfiles carry the label too, so a plain docker build has it).
RUNR_SOURCE_URL="https://github.com/BRF-Tech/runr"

# oci_label_args TAG — the --label flags of a push build, one per line, on top of the Dockerfile's
# (title, description, licenses, vendor): the source, the version (TAG's tag), the commit the
# checkout is at (revision) and its time (created: SOURCE_DATE_EPOCH, else the commit's time). They
# replace what a base image brings (FrankenPHP's, Caddy's, Trivy's version, revision and date).
oci_label_args() {
  local tag="$1" root revision epoch created
  root="${SCRIPT_DIR}/.."
  revision="$(git -C "$root" rev-parse HEAD 2>/dev/null || true)"
  epoch="${SOURCE_DATE_EPOCH:-$(git -C "$root" log -1 --format=%ct 2>/dev/null || true)}"
  echo "--label=org.opencontainers.image.source=${RUNR_SOURCE_URL}"
  echo "--label=org.opencontainers.image.version=${tag##*:}"
  if [ -n "$revision" ]; then
    echo "--label=org.opencontainers.image.revision=${revision}"
  fi
  if [ -n "$epoch" ]; then
    created="$(date -u -d "@${epoch}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || date -u -r "${epoch}" +%Y-%m-%dT%H:%M:%SZ)"
    echo "--label=org.opencontainers.image.created=${created}"
  fi
}

# Build helper — multiplatform push or local single-platform. --pull: base images come from their
# registry, never from a copy the build host's Docker already holds (an old pull of a
# ghcr.io/brf-tech/runr/reqs tag would otherwise be built on); a caller's --pull=false, after it, wins.
# A push carries the OCI labels (oci_label_args) and the source as an annotation of the index (of
# the image itself on a single platform).
do_build() {
  local tag="$1" context="$2" push="$3"
  shift 3
  # Remaining args: extra docker build flags (e.g. --build-arg)

  if [ "$push" = "1" ]; then
    ensure_buildx
    local labels=() annotation="manifest"
    mapfile -t labels < <(oci_label_args "${tag}")
    if [ -n "${PUSH_BY_DIGEST:-}" ]; then
      push_by_digest "${tag}" "${context}" "${labels[@]}" "$@"
      return
    fi
    case "${PLATFORMS}" in *,*) annotation="index" ;; esac
    docker buildx build \
      --builder "${BUILDER_NAME}" \
      --pull \
      --platform "${PLATFORMS}" \
      --provenance=false \
      --sbom=false \
      "${labels[@]}" \
      --annotation "${annotation}:org.opencontainers.image.source=${RUNR_SOURCE_URL}" \
      "$@" \
      -t "${tag}" \
      --output "type=image,push=true,oci-mediatypes=true" \
      "${context}"
  else
    docker build --pull "$@" -t "${tag}" "${context}"
  fi
}

# PUSH_BY_DIGEST=FILE: a --push build pushes its image (the platforms of PLATFORMS) by digest only,
# with no tag, and appends "<repository>@<digest> <tag>…" to FILE: the build's tag and its extra
# -t ones. The release workflow (.github/workflows/release-images.yml) builds each platform on a
# runner of its own this way, without emulation; compose-index then tags the multi-arch indexes.
push_by_digest() {
  local tag="$1" context="$2"
  shift 2
  local tags=("$tag") args=() meta digest
  while [ "$#" -gt 0 ]; do
    case "$1" in
      -t|--tag) tags+=("$2"); shift 2 ;;
      *) args+=("$1"); shift ;;
    esac
  done
  meta="$(mktemp)"
  docker buildx build \
    --builder "${BUILDER_NAME}" \
    --pull \
    --platform "${PLATFORMS}" \
    --provenance=false \
    --sbom=false \
    ${args[@]+"${args[@]}"} \
    --metadata-file "${meta}" \
    --output "type=image,name=${tag%:*},push-by-digest=true,name-canonical=true,push=true,oci-mediatypes=true" \
    "${context}"
  digest="$(sed -n 's/.*"containerimage\.digest"[[:space:]]*:[[:space:]]*"\(sha256:[0-9a-f]\{64\}\)".*/\1/p' "${meta}" | head -n 1)"
  rm -f "${meta}"
  if [ -z "$digest" ]; then
    echo -e "${RED}${tag}: the build reported no digest${NC}"
    return 1
  fi
  echo "${tag%:*}@${digest} ${tags[*]}" >> "${PUSH_BY_DIGEST}"
  echo "==> ${tag%:*}@${digest} (${PLATFORMS}), for ${tags[*]}"
}

# platform_digests REF — "<os>/<arch> <digest>" of each platform image of REF (an index: its
# entries; a single image: itself, its platform from its config)
platform_digests() {
  local ref="$1" raw
  raw="$(docker buildx imagetools inspect --raw "$ref")"
  if jq -e '.manifests' >/dev/null <<<"$raw"; then
    jq -r '.manifests[] | select(.platform.architecture != "unknown") | "\(.platform.os)/\(.platform.architecture) \(.digest)"' <<<"$raw"
  else
    echo "$(docker buildx imagetools inspect --format '{{json .Image}}' "$ref" | jq -r '"\(.os)/\(.architecture)"') ${ref##*@}"
  fi
}

# cmd_compose_index FILE… — `ci.sh compose-index FILE…`: the multi-arch tags of the images that
# per-platform pushes (PUSH_BY_DIGEST) recorded in FILE…. The lines with the same tags are one
# image: its platform images (of a pushed index, its entries), one per platform of PLATFORMS, go
# into one index under each of the tags. The index is annotated with the OCI labels of its first
# platform image (title, description, licenses … show on the package page) and always with the
# source, which links the package to the repository. Each tag is checked afterwards: its index
# names exactly those platform images.
cmd_compose_index() {
  if [ "$#" = 0 ]; then
    echo -e "${RED}usage: ci.sh compose-index FILE…${NC}"
    exit 1
  fi
  local tags refs ref repo want p first args annotations got t
  while read -r tags; do
    [ -n "$tags" ] || continue
    refs="$(awk -v want="$tags" '{ ref = $1; $1 = ""; sub(/^ /, ""); if ($0 == want) print ref }' "$@")"
    repo="${refs%%@*}"
    want=""
    for ref in $refs; do
      [ "${ref%%@*}" = "$repo" ] || { echo -e "${RED}${tags}: images of two repositories (${repo}, ${ref%%@*})${NC}"; exit 1; }
      want+="$(platform_digests "$ref")"$'\n'
    done
    want="$(sort <<<"$want" | grep .)"
    if [ -n "$(cut -d' ' -f1 <<<"$want" | uniq -d)" ]; then
      echo -e "${RED}${tags}: two images of one platform: ${want}${NC}"
      exit 1
    fi
    for p in ${PLATFORMS//,/ }; do
      grep -q "^${p} " <<<"$want" || { echo -e "${RED}${tags}: no ${p} image (${want//$'\n'/, })${NC}"; exit 1; }
    done

    first="$(head -n 1 <<<"$want" | cut -d' ' -f2)"
    annotations=()
    mapfile -t annotations < <(docker buildx imagetools inspect --format '{{json .Image}}' "${repo}@${first}" \
      | jq -r '.config.Labels // {} | to_entries[]
          | select((.key | startswith("org.opencontainers.image.")) and .key != "org.opencontainers.image.source")
          | "--annotation=index:\(.key)=\(.value)"')
    annotations+=("--annotation=index:org.opencontainers.image.source=${RUNR_SOURCE_URL}")
    args=()
    for t in $tags; do args+=(-t "$t"); done
    while read -r p ref; do args+=("${repo}@${ref}"); done <<<"$want"
    echo "==> ${tags} ($(cut -d' ' -f1 <<<"$want" | paste -sd, -))"
    docker buildx imagetools create "${annotations[@]}" "${args[@]}"

    for t in $tags; do
      got="$(docker buildx imagetools inspect --raw "$t" \
        | jq -r '.manifests[] | "\(.platform.os)/\(.platform.architecture) \(.digest)"' | sort)"
      if [ "$got" != "$want" ]; then
        echo -e "${RED}✗ ${t}: its index is ${got//$'\n'/, }, not ${want//$'\n'/, }${NC}"
        exit 1
      fi
      echo "✓ ${t}: ${got//$'\n'/, }"
    done
  done < <(awk '{ $1 = ""; sub(/^ /, ""); if (!seen[$0]++) print }' "$@")
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
gateway_auth_version() { echo "${GATEWAY_AUTH_VERSION:-1.1.0}"; }
image_upload_version() { echo "${IMAGE_UPLOAD_VERSION:-1.2.0}"; }

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
