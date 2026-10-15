#!/usr/bin/env bash
# devready.sh — RunR developer environment setup
# Usage: bash scripts/devready.sh [valet|docker]

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

BOLD='\033[1m'
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m'

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
info() { echo -e "  $1"; }

main_valet() {
    source "$SCRIPT_DIR/lib/devready_system.sh"
    source "$SCRIPT_DIR/lib/devready_app.sh"

    echo -e "${BOLD}RunR Developer Environment Setup (Valet)${NC}"
    echo ""

    echo -e "${BOLD}── System ──${NC}"
    ensure_ondrej_php
    ensure_php_extensions
    ensure_composer
    ensure_nvm_node
    ensure_redis
    ensure_valet

    echo ""
    echo -e "${BOLD}── Application ──${NC}"
    setup_api_env
    setup_api_deps
    setup_api_database
    setup_vue_deps
    setup_valet_link

    echo ""
    echo -e "${BOLD}── Ready ──${NC}"
    ok "Backend  : https://runrapi.test"
    ok "Frontend : npm run dev (cd vue/)"
    ok "Horizon  : php artisan horizon (cd api/)"
    echo ""
}

main_docker() {
    source "$SCRIPT_DIR/lib/devready_docker.sh"

    echo -e "${BOLD}RunR Developer Environment Setup (Docker Compose)${NC}"
    echo ""

    echo -e "${BOLD}── System ──${NC}"
    ensure_docker

    echo ""
    echo -e "${BOLD}── Environment ──${NC}"
    setup_root_env
    setup_docker_api_env

    echo ""
    echo -e "${BOLD}── Containers ──${NC}"
    docker_compose_up
    docker_compose_migrate

    docker_summary
}

show_usage() {
    echo -e "${BOLD}RunR Developer Environment Setup${NC}"
    echo ""
    echo "Usage: bash scripts/devready.sh [mode]"
    echo ""
    echo "Modes:"
    echo "  valet   — PHP/Redis/Valet local setup (Ubuntu/WSL)"
    echo "  docker  — Docker Compose full stack"
    echo ""
}

cd "$ROOT_DIR"

case "${1:-}" in
    valet)  main_valet ;;
    docker) main_docker ;;
    *)      show_usage ;;
esac
