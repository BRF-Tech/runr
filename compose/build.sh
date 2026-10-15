#!/bin/bash
set -euo pipefail

# ── RunR Production Build Script ─────────────────────────────────────────────
# Builds all images and packs them into a single tar file.
# To move them into an air-gapped environment:
#   1) Run this script on a machine with internet access
#   2) Copy the generated tar file to the target
#   3) On the target: docker load -i runr-images.tar

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
OUTPUT="${SCRIPT_DIR}/runr-images.tar"

# Reverb key — baked into the Vue build
REVERB_KEY="${REVERB_KEY:-runr-prod-key}"

echo "══════════════════════════════════════════════════════════"
echo "  RunR Production Build"
echo "══════════════════════════════════════════════════════════"
echo ""

# ── 1. API image ─────────────────────────────────────────────────────────────
echo "▶ [1/3] Building runr-api:latest ..."
# runr://docs/{page} of the MCP server (docs/mcp.md): the image carries docs/ as resources/docs
mkdir -p "${PROJECT_ROOT}/api/resources/docs" && cp "${PROJECT_ROOT}"/docs/*.md "${PROJECT_ROOT}/api/resources/docs/"
# Dockerfile.prod: FrankenPHP base (Octane's server binary) + vendor/ baked in — the target
# machine is air-gapped, nothing is mounted or downloaded at start (compose/deploy.sh, ci.sh deploy)
docker build \
    --file "${PROJECT_ROOT}/api/Dockerfile.prod" \
    --target base \
    -t runr-api:latest \
    "${PROJECT_ROOT}/api"

# ── 2. Vue image (SPA build + Caddy) ─────────────────────────────────────────
echo "▶ [2/3] Building runr-ui:latest ..."
docker build \
    --build-arg VITE_WS_PROXY=true \
    --build-arg VITE_REVERB_KEY="${REVERB_KEY}" \
    -t runr-ui:latest \
    "${PROJECT_ROOT}/vue"

# ── 3. Base images (postgres, redis) ─────────────────────────────────────────
echo "▶ [3/3] Pulling base images ..."

ensure_image() {
    local img="$1"
    if docker image inspect "$img" &>/dev/null; then
        echo "  ✓ $img (cached)"
    else
        docker pull "$img"
        echo "  ✓ $img"
    fi
}

ensure_image "ghcr.io/runrsh/reqs/postgres:17-alpine"
ensure_image "ghcr.io/runrsh/reqs/redis:7-alpine"

# ── Export ────────────────────────────────────────────────────────────────────
echo ""
echo "▶ Exporting images to ${OUTPUT} ..."
docker save -o "$OUTPUT" \
    runr-api:latest \
    runr-ui:latest \
    ghcr.io/runrsh/reqs/postgres:17-alpine \
    ghcr.io/runrsh/reqs/redis:7-alpine

SIZE=$(du -h "$OUTPUT" | cut -f1)
echo ""
echo "══════════════════════════════════════════════════════════"
echo "  Build complete!"
echo "  File: ${OUTPUT}"
echo "  Size: ${SIZE}"
echo ""
echo "  On the target machine:"
echo "    docker load -i runr-images.tar"
echo "    cp .env.example .env"
echo "    cp api.env.example api.env"
echo "    # edit the .env and api.env files"
echo "    # generate APP_KEY: docker compose run --rm api php artisan key:generate --show"
echo "    docker compose up -d"
echo "══════════════════════════════════════════════════════════"
