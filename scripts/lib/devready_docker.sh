#!/usr/bin/env bash
# devready_docker.sh — Docker Compose setup

ensure_docker() {
    if command -v docker &>/dev/null; then
        ok "Docker ($(docker --version | grep -oP '[\d.]+' | head -1))"
    else
        fail "Docker is not installed — https://docs.docker.com/get-docker/"
        exit 1
    fi

    if docker compose version &>/dev/null; then
        ok "Docker Compose ($(docker compose version --short))"
    else
        fail "Docker Compose not found"
        exit 1
    fi
}

setup_root_env() {
    local env_file="$ROOT_DIR/.env"
    local example_file="$ROOT_DIR/.env.example"

    if [ -f "$env_file" ]; then
        ok ".env exists (root directory)"
        return
    fi

    if [ ! -f "$example_file" ]; then
        fail ".env.example not found (root directory)"
        return
    fi

    cp "$example_file" "$env_file"
    ok ".env created (root directory)"
}

setup_docker_api_env() {
    local env_file="$ROOT_DIR/api/.env.docker"

    if [ ! -f "$env_file" ]; then
        fail "api/.env.docker not found"
        return
    fi

    # Generate APP_KEY if it is missing or empty
    if grep -q '^APP_KEY=$' "$env_file" || ! grep -q '^APP_KEY=' "$env_file"; then
        local key
        key=$(docker compose run --rm --no-deps api php artisan key:generate --show 2>/dev/null)
        if [ -n "$key" ]; then
            sed -i "s|^APP_KEY=.*|APP_KEY=${key}|" "$env_file"
            ok "APP_KEY generated"
        else
            warn "Could not generate APP_KEY — try again after the first docker compose up"
        fi
    else
        ok "APP_KEY present"
    fi
}

docker_compose_up() {
    info "docker compose up --build -d ..."
    docker compose -f "$ROOT_DIR/docker-compose.yml" up --build -d
    ok "Containers started"
}

docker_compose_migrate() {
    info "migrate + seed..."
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec api php artisan migrate --force --quiet
    docker compose -f "$ROOT_DIR/docker-compose.yml" exec api php artisan db:seed --force --quiet
    ok "Database migrate + seed done"
}

docker_summary() {
    local api_port="${API_PORT:-8060}"
    local vue_port="${VUE_PORT:-8061}"

    # Read from .env if it exists
    if [ -f "$ROOT_DIR/.env" ]; then
        api_port=$(grep '^API_PORT=' "$ROOT_DIR/.env" | cut -d= -f2 || echo "8060")
        vue_port=$(grep '^VUE_PORT=' "$ROOT_DIR/.env" | cut -d= -f2 || echo "8061")
        api_port="${api_port:-8060}"
        vue_port="${vue_port:-8061}"
    fi

    echo ""
    echo -e "${BOLD}── Ready (Docker Compose) ──${NC}"
    ok "API      : http://localhost:${api_port}"
    ok "Frontend : http://localhost:${vue_port}"
    ok "Horizon  : http://localhost:${api_port}/horizon"
    echo ""
    echo -e "  Logs   : docker compose logs -f"
    echo -e "  Stop   : docker compose down"
    echo ""
}
