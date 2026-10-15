#!/usr/bin/env bash
# devready_app.sh — Application setup (env, deps, db, valet)

VALET_SITE_NAME="runrapi"

setup_api_env() {
    local env_file="$ROOT_DIR/api/.env"
    local example_file="$ROOT_DIR/api/.env.example"

    if [ -f "$env_file" ]; then
        ok ".env exists"
        return
    fi

    if [ ! -f "$example_file" ]; then
        fail ".env.example not found"
        return
    fi

    cp "$example_file" "$env_file"

    # APP_ENV=local, APP_DEBUG=true
    sed -i 's/^APP_ENV=.*/APP_ENV=local/' "$env_file"
    sed -i 's/^APP_DEBUG=.*/APP_DEBUG=true/' "$env_file"
    sed -i "s|^APP_URL=.*|APP_URL=https://${VALET_SITE_NAME}.test|" "$env_file"

    # Build settings: host.docker.internal for Kind pods → Valet
    sed -i 's|^BUILD_SERVER_URL=.*|BUILD_SERVER_URL=https://host.docker.internal/api|' "$env_file"
    sed -i "s|^BUILD_CALLBACK_HOST=.*|BUILD_CALLBACK_HOST=${VALET_SITE_NAME}.test|" "$env_file"
    sed -i 's|^BUILD_INSECURE_TLS=.*|BUILD_INSECURE_TLS=1|' "$env_file"

    # Generate APP_KEY
    (cd "$ROOT_DIR/api" && php artisan key:generate --quiet)

    ok ".env created (APP_KEY generated)"
}

setup_api_deps() {
    if [ -d "$ROOT_DIR/api/vendor" ]; then
        ok "Composer dependencies present"
    else
        info "composer install..."
        (cd "$ROOT_DIR/api" && composer install --quiet --no-interaction)
        ok "Composer dependencies installed"
    fi
}

setup_api_database() {
    local db_file="$ROOT_DIR/api/database/database.sqlite"

    if [ -f "$db_file" ]; then
        ok "SQLite database exists"
    else
        touch "$db_file"
        ok "SQLite database created"
    fi

    info "migrate + seed..."
    (cd "$ROOT_DIR/api" && php artisan migrate --force --quiet)
    (cd "$ROOT_DIR/api" && php artisan db:seed --force --quiet)
    ok "Database migrate + seed done"
}

setup_vue_deps() {
    if [ -d "$ROOT_DIR/vue/node_modules" ]; then
        ok "npm dependencies present"
    else
        info "npm install..."
        (cd "$ROOT_DIR/vue" && npm install --silent 2>/dev/null)
        ok "npm dependencies installed"
    fi
}

setup_valet_link() {
    # Valet link check
    if valet links 2>/dev/null | grep -q "$VALET_SITE_NAME"; then
        local has_ssl
        has_ssl=$(valet links 2>/dev/null | grep "$VALET_SITE_NAME" | grep -c "X" || true)
        if [ "$has_ssl" -gt 0 ]; then
            ok "Valet link: https://${VALET_SITE_NAME}.test (SSL)"
        else
            info "Enabling Valet SSL..."
            (cd "$ROOT_DIR/api" && valet secure "$VALET_SITE_NAME" 2>/dev/null)
            ok "Valet link: https://${VALET_SITE_NAME}.test (SSL)"
        fi
    else
        info "Creating the Valet link..."
        (cd "$ROOT_DIR/api" && valet link "$VALET_SITE_NAME")
        (cd "$ROOT_DIR/api" && valet secure "$VALET_SITE_NAME" 2>/dev/null)
        ok "Valet link: https://${VALET_SITE_NAME}.test (SSL)"
    fi

    # PHP version pin
    local php_ver
    php_ver=$(valet links 2>/dev/null | grep "$VALET_SITE_NAME" | grep -oP '\d\.\d' | head -1)
    if [ "$php_ver" = "8.3" ]; then
        ok "Valet PHP 8.3 pinned"
    else
        (cd "$ROOT_DIR/api" && valet use "php${PHP_VERSION}" 2>/dev/null || true)
        ok "Valet PHP ${PHP_VERSION} pinned"
    fi
}
