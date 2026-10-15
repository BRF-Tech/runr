#!/usr/bin/env bash
# devready_system.sh — System dependency checks & installs

PHP_VERSION="8.3"
NODE_VERSION="22"

PHP_EXTENSIONS=(
    "php${PHP_VERSION}-cli"
    "php${PHP_VERSION}-common"
    "php${PHP_VERSION}-curl"
    "php${PHP_VERSION}-fpm"
    "php${PHP_VERSION}-gd"
    "php${PHP_VERSION}-igbinary"
    "php${PHP_VERSION}-intl"
    "php${PHP_VERSION}-mbstring"
    "php${PHP_VERSION}-opcache"
    "php${PHP_VERSION}-pgsql"
    "php${PHP_VERSION}-readline"
    "php${PHP_VERSION}-redis"
    "php${PHP_VERSION}-sqlite3"
    "php${PHP_VERSION}-xml"
    "php${PHP_VERSION}-zip"
)

ensure_ondrej_php() {
    if apt-cache policy 2>/dev/null | grep -q "ondrej/php"; then
        ok "Ondrej PHP PPA"
    else
        info "Adding the Ondrej PHP PPA..."
        sudo add-apt-repository -y ppa:ondrej/php
        sudo apt-get update -qq
        ok "Ondrej PHP PPA added"
    fi

    if command -v "php${PHP_VERSION}" &>/dev/null; then
        ok "PHP ${PHP_VERSION} ($(php${PHP_VERSION} -v | head -1 | awk '{print $2}'))"
    else
        info "Installing PHP ${PHP_VERSION}..."
        sudo apt-get install -y -qq "php${PHP_VERSION}" >/dev/null
        ok "PHP ${PHP_VERSION} installed"
    fi
}

ensure_php_extensions() {
    local missing=()
    for ext in "${PHP_EXTENSIONS[@]}"; do
        if ! dpkg -l "$ext" &>/dev/null; then
            missing+=("$ext")
        fi
    done

    if [ ${#missing[@]} -eq 0 ]; then
        ok "PHP extensions (${#PHP_EXTENSIONS[@]} packages)"
    else
        info "Installing missing PHP extensions: ${missing[*]}"
        sudo apt-get install -y -qq "${missing[@]}" >/dev/null
        ok "PHP extensions installed"
    fi
}

ensure_composer() {
    if command -v composer &>/dev/null; then
        ok "Composer ($(composer -V 2>/dev/null | grep -oP '[\d.]+' | head -1))"
        return
    fi

    info "Installing Composer..."
    local tmp
    tmp=$(mktemp)
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer >/dev/null
    sudo mv /tmp/composer /usr/local/bin/composer
    sudo chmod +x /usr/local/bin/composer
    rm -f "$tmp"
    ok "Composer installed"
}

ensure_nvm_node() {
    export NVM_DIR="${NVM_DIR:-$HOME/.nvm}"

    if [ -s "$NVM_DIR/nvm.sh" ]; then
        # shellcheck source=/dev/null
        source "$NVM_DIR/nvm.sh"
        ok "NVM ($(nvm --version 2>/dev/null))"
    else
        info "Installing NVM..."
        curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.3/install.sh | bash >/dev/null 2>&1
        source "$NVM_DIR/nvm.sh"
        ok "NVM installed"
    fi

    if command -v node &>/dev/null && node -v 2>/dev/null | grep -q "^v${NODE_VERSION}"; then
        ok "Node.js ($(node -v))"
    else
        info "Installing Node.js ${NODE_VERSION}..."
        nvm install "$NODE_VERSION" >/dev/null 2>&1
        nvm use "$NODE_VERSION" >/dev/null 2>&1
        nvm alias default "$NODE_VERSION" >/dev/null 2>&1
        ok "Node.js $(node -v) installed"
    fi
}

ensure_redis() {
    if command -v redis-server &>/dev/null; then
        if redis-cli ping &>/dev/null; then
            ok "Redis (running)"
        else
            warn "Redis is installed but not running — sudo service redis-server start"
        fi
    else
        info "Installing Redis..."
        sudo apt-get install -y -qq redis-server >/dev/null
        sudo service redis-server start 2>/dev/null || true
        ok "Redis installed"
    fi
}

ensure_valet() {
    if command -v valet &>/dev/null; then
        ok "Valet ($(valet --version 2>/dev/null | grep -oP '[\d.]+' | head -1))"
    else
        info "Installing Valet..."
        composer global require cpriego/valet-linux 2>/dev/null
        valet install
        ok "Valet installed"
    fi
}
