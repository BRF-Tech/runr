#!/bin/bash
set -euo pipefail

# ── RunR Production Build Script ─────────────────────────────────────────────
# Builds all images and packs them into a single tar file.
# To move them into an air-gapped environment:
#   1) Set the module toggles in compose/.env, run this script on a machine with internet access
#   2) Copy the generated tar file (and compose/ with its .env) to the target
#   3) On the target: docker load -i runr-images.tar

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
OUTPUT="${SCRIPT_DIR}/runr-images.tar"

# Reverb key — baked into the Vue build
REVERB_KEY="${REVERB_KEY:-runr-prod-key}"

# Modules — baked into the Vue build: TENANCY_ENABLED, AUTH_PROVIDER_ENABLED, TICKET_ENABLED, BILLING_ENABLED from the
# environment, else from compose/.env, else the defaults of docker-compose.yml — the values the API
# containers get, so the UI shows the modules the API enables
# shellcheck source-path=SCRIPTDIR source=modules.sh
. "${SCRIPT_DIR}/modules.sh"
runr_modules "${SCRIPT_DIR}/.env"

echo "══════════════════════════════════════════════════════════"
echo "  RunR Production Build"
echo "══════════════════════════════════════════════════════════"
echo ""
echo "  Modules: tenancy=${TENANCY_ENABLED} auth-provider=${AUTH_PROVIDER_ENABLED} ticket=${TICKET_ENABLED} billing=${BILLING_ENABLED}"
echo ""

# ── 1. API image ─────────────────────────────────────────────────────────────
echo "▶ [1/3] Building runr-api:latest ..."
# runr://docs/{page} of the MCP server (docs/mcp.md): the image carries docs/ as resources/docs
mkdir -p "${PROJECT_ROOT}/api/resources/docs" && cp "${PROJECT_ROOT}"/docs/*.md "${PROJECT_ROOT}/api/resources/docs/"
# Dockerfile.prod: FrankenPHP base (Octane's server binary) + vendor/ baked in — the target
# machine is air-gapped, nothing is mounted or downloaded at start (compose/deploy.sh, ci.sh deploy).
# --pull: base images from their registry, not a copy this machine's Docker pulled long ago
docker build \
    --pull \
    --file "${PROJECT_ROOT}/api/Dockerfile.prod" \
    --target base \
    -t runr-api:latest \
    "${PROJECT_ROOT}/api"

# ── 2. Vue image (SPA build + Caddy) ─────────────────────────────────────────
echo "▶ [2/3] Building runr-ui:latest ..."
docker build \
    --pull \
    --build-arg VITE_WS_PROXY=true \
    --build-arg VITE_REVERB_KEY="${REVERB_KEY}" \
    --build-arg VITE_TENANCY_ENABLED="${TENANCY_ENABLED}" \
    --build-arg VITE_AUTH_PROVIDER_ENABLED="${AUTH_PROVIDER_ENABLED}" \
    --build-arg VITE_TICKET_ENABLED="${TICKET_ENABLED}" \
    --build-arg VITE_BILLING_ENABLED="${BILLING_ENABLED}" \
    -t runr-ui:latest \
    "${PROJECT_ROOT}/vue"

# ── 3. Base images (postgres, redis) ─────────────────────────────────────────
echo "▶ [3/3] Pulling base images ..."

# the registry's image, also when this machine's Docker already holds an older pull of the tag
ensure_image() {
    local img="$1"
    docker pull "$img"
    echo "  ✓ $img"
}

ensure_image "ghcr.io/brf-tech/runr/reqs/postgres:17-alpine"
ensure_image "ghcr.io/brf-tech/runr/reqs/redis:7-alpine"

# ── Export ────────────────────────────────────────────────────────────────────
echo ""
echo "▶ Exporting images to ${OUTPUT} ..."
docker save -o "$OUTPUT" \
    runr-api:latest \
    runr-ui:latest \
    ghcr.io/brf-tech/runr/reqs/postgres:17-alpine \
    ghcr.io/brf-tech/runr/reqs/redis:7-alpine

SIZE=$(du -h "$OUTPUT" | cut -f1)
echo ""
echo "══════════════════════════════════════════════════════════"
echo "  Build complete!"
echo "  File: ${OUTPUT}"
echo "  Size: ${SIZE}"
echo ""
echo "  On the target machine (compose/README.md):"
echo "    docker load -i runr-images.tar"
echo "    cp api.env.example api.env"
echo "    # edit .env (the same module toggles as this build) and api.env"
echo "    # generate APP_KEY: docker compose run --rm api php artisan key:generate --show"
echo "    docker compose up -d"
echo "    docker compose run --rm migrator   # migrations + the first administrator"
echo "══════════════════════════════════════════════════════════"
