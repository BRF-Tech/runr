#!/bin/bash
set -euo pipefail

# ── RunR Compose Deploy Script ────────────────────────────────────────────────
# Builds the images locally and ships them to a Docker Compose host over SSH.
# Usage:
#   bash compose/deploy.sh build api|ui|all [VERSION]   # build only, save the tars
#   bash compose/deploy.sh push api|ui|all              # send the saved tars to the server
#   bash compose/deploy.sh api|ui|all [VERSION]         # build + push in one go
#
# Server settings (environment):
#   DEPLOY_SERVER  host name or IP of the Compose host (required for push)
#   DEPLOY_USER    SSH user (default: ubuntu)
#   DEPLOY_DIR     directory of docker-compose.yml on the server (default: /home/$DEPLOY_USER/compose)
#   REVERB_KEY     Reverb app key baked into the UI build (must equal REVERB_APP_KEY)
#   TENANCY_ENABLED, AUTH_PROVIDER_ENABLED, TICKET_ENABLED, BILLING_ENABLED
#                  modules baked into the UI build (default: the server's .env when
#                  DEPLOY_SERVER is set, else compose/.env, then the docker-compose.yml defaults)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
TAR_DIR="$SCRIPT_DIR/.tars"

SERVER="${DEPLOY_SERVER:-}"
SERVER_USER="${DEPLOY_USER:-ubuntu}"
SERVER_DIR="${DEPLOY_DIR:-/home/${SERVER_USER}/compose}"
REVERB_KEY="${REVERB_KEY:-runr-prod-key}"

BOLD='\033[1m'
CYAN='\033[0;36m'
GREEN='\033[0;32m'
RED='\033[0;31m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; exit 1; }
info() { echo -e "  ${CYAN}▸${NC} $1"; }

# ---------- build ----------

build_api() {
  local version="$1"
  info "Building runr-api:latest ..."
  # --pull: base images from their registry, not a copy this machine's Docker pulled long ago
  docker build \
    --pull \
    --file "$PROJECT_ROOT/api/Dockerfile.prod" \
    --build-arg APP_VERSION="$version" \
    -t "runr-api:latest" \
    "$PROJECT_ROOT/api"
  ok "runr-api:latest"

  info "Saving API image to tar..."
  mkdir -p "$TAR_DIR"
  docker save "runr-api:latest" | gzip > "$TAR_DIR/api.tar.gz"
  ok "API tar: $(du -h "$TAR_DIR/api.tar.gz" | cut -f1)"
}

build_ui() {
  # The modules of the server's API containers: TENANCY_ENABLED / AUTH_PROVIDER_ENABLED /
  # TICKET_ENABLED / BILLING_ENABLED from the environment, else from the server's .env (DEPLOY_SERVER set) or
  # compose/.env, else the docker-compose.yml defaults
  local env_file="$SCRIPT_DIR/.env"
  mkdir -p "$TAR_DIR"
  if [ -n "$SERVER" ] && ssh "${SERVER_USER}@${SERVER}" "cat ${SERVER_DIR}/.env" > "$TAR_DIR/server.env" 2>/dev/null; then
    env_file="$TAR_DIR/server.env"
  fi
  # shellcheck source-path=SCRIPTDIR source=modules.sh
  . "$SCRIPT_DIR/modules.sh"
  runr_modules "$env_file"
  info "Building runr-ui:latest (tenancy=${TENANCY_ENABLED} auth-provider=${AUTH_PROVIDER_ENABLED} ticket=${TICKET_ENABLED} billing=${BILLING_ENABLED}) ..."
  docker build \
    --pull \
    --build-arg VITE_WS_PROXY=true \
    --build-arg VITE_REVERB_KEY="${REVERB_KEY}" \
    --build-arg VITE_TENANCY_ENABLED="${TENANCY_ENABLED}" \
    --build-arg VITE_AUTH_PROVIDER_ENABLED="${AUTH_PROVIDER_ENABLED}" \
    --build-arg VITE_TICKET_ENABLED="${TICKET_ENABLED}" \
    --build-arg VITE_BILLING_ENABLED="${BILLING_ENABLED}" \
    -t "runr-ui:latest" \
    "$PROJECT_ROOT/vue"
  ok "runr-ui:latest"

  info "Saving UI image to tar..."
  mkdir -p "$TAR_DIR"
  docker save "runr-ui:latest" | gzip > "$TAR_DIR/ui.tar.gz"
  ok "UI tar: $(du -h "$TAR_DIR/ui.tar.gz" | cut -f1)"
}

# ---------- push ----------

sync_compose_files() {
  info "Syncing compose config files..."
  scp "$SCRIPT_DIR/docker-compose.yml" "${SERVER_USER}@${SERVER}:${SERVER_DIR}/docker-compose.yml"
  scp "$SCRIPT_DIR/Caddyfile"          "${SERVER_USER}@${SERVER}:${SERVER_DIR}/Caddyfile"
  ok "Compose files synced"
}

push_and_restart() {
  local tars=("$@")

  [ -n "$SERVER" ] || fail "Set DEPLOY_SERVER to the Compose host to deploy to"

  for tar in "${tars[@]}"; do
    [ -f "$tar" ] || fail "Tar not found: $tar — run 'build' first"
  done

  sync_compose_files

  info "Uploading to ${SERVER}..."
  scp "${tars[@]}" "${SERVER_USER}@${SERVER}:/tmp/"
  ok "Upload complete"

  for tar in "${tars[@]}"; do
    local fname
    fname=$(basename "$tar")
    info "Loading ${fname} on server..."
    ssh "${SERVER_USER}@${SERVER}" "docker load < /tmp/${fname} && rm -f /tmp/${fname}"
    ok "${fname} loaded"
  done

  info "Restarting services..."
  local services=""
  for tar in "${tars[@]}"; do
    local fname
    fname=$(basename "$tar")
    [[ "$fname" == "api.tar.gz" ]] && services="$services api horizon scheduler reverb"
    [[ "$fname" == "ui.tar.gz" ]]  && services="$services gateway"
  done
  ssh "${SERVER_USER}@${SERVER}" "cd ${SERVER_DIR} && docker compose up -d --force-recreate $services"
  ok "Services restarted:$services"
}

verify_version() {
  echo ""
  info "Verifying version on server..."
  sleep 5
  local ver
  ver=$(ssh "${SERVER_USER}@${SERVER}" "cd ${SERVER_DIR} && docker compose exec -T api curl -s http://localhost/api/version" 2>/dev/null || echo '{"version":"?"}')
  echo -e "  ${BOLD}Server version:${NC} ${CYAN}${ver}${NC}"
  echo -e "\n${GREEN}${BOLD}Done!${NC}\n"
}

# ---------- main ----------

ACTION="${1:-}"
TARGET="${2:-all}"
VERSION="${3:-$(git -C "$PROJECT_ROOT" rev-parse --short HEAD)}"

# Shorthand: a first argument of api|ui|all means action=full
case "$ACTION" in
  api|ui|all)
    TARGET="$ACTION"
    VERSION="${2:-$(git -C "$PROJECT_ROOT" rev-parse --short HEAD)}"
    ACTION="full"
    ;;
  build|push|full) ;;
  *) fail "Usage:
  $0 build api|ui|all [VERSION]   — build only, save the tars
  $0 push api|ui|all              — send the saved tars to the server
  $0 api|ui|all [VERSION]         — build + push in one go" ;;
esac

if [[ "$VERSION" == v* ]]; then
  DISPLAY_V="$VERSION"
else
  DISPLAY_V="main-$VERSION"
fi

echo -e "\n${BOLD}RunR Deploy${NC}"
echo -e "  action  : ${CYAN}${ACTION}${NC}"
echo -e "  target  : ${CYAN}${TARGET}${NC}"
echo -e "  version : ${CYAN}${DISPLAY_V}${NC}"
[ "$ACTION" != "build" ] && echo -e "  server  : ${SERVER}"
echo ""

case "$ACTION" in
  build)
    case "$TARGET" in
      api) build_api "$VERSION" ;;
      ui)  build_ui ;;
      all) build_api "$VERSION"; build_ui ;;
    esac
    echo -e "\n${GREEN}Tars ready: ${TAR_DIR}/${NC}"
    echo -e "  ${BOLD}To push:${NC} DEPLOY_SERVER=<host> bash compose/deploy.sh push $TARGET"
    ;;
  push)
    case "$TARGET" in
      api) push_and_restart "$TAR_DIR/api.tar.gz" ;;
      ui)  push_and_restart "$TAR_DIR/ui.tar.gz" ;;
      all) push_and_restart "$TAR_DIR/api.tar.gz" "$TAR_DIR/ui.tar.gz" ;;
    esac
    verify_version
    ;;
  full)
    case "$TARGET" in
      api) build_api "$VERSION"; push_and_restart "$TAR_DIR/api.tar.gz" ;;
      ui)  build_ui; push_and_restart "$TAR_DIR/ui.tar.gz" ;;
      all) build_api "$VERSION"; build_ui; push_and_restart "$TAR_DIR/api.tar.gz" "$TAR_DIR/ui.tar.gz" ;;
    esac
    verify_version
    ;;
esac
