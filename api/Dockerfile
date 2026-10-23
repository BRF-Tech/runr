# Development image (docker-compose.yml: the source and vendor/ are mounted). The production
# image is Dockerfile.prod; both run the API with Octane on FrankenPHP (docker/entrypoint.sh:
# octane:start --server=frankenphp), so both start from the FrankenPHP base image, which carries
# the frankenphp binary Octane needs.

# The FrankenPHP base of the runtime stage, from the reqs mirror (REQS_IMAGES of lib/mirror.sh in
# BRF-Tech/runr-runtimes); Dockerfile.prod has the same. It is an exact upstream tag,
# <FrankenPHP version>-php<PHP version>-alpine on PHP_MIN_VERSION or later, or the floating
# 1-php8.3-alpine while upstream has none on it; the guard of the runtime stage refuses an older
# PHP either way.
ARG FRANKENPHP_TAG=1-php8.3-alpine

# ─── Stage 1: Composer dependencies ──────────────────────────────────────────
FROM ghcr.io/brf-tech/runr/reqs/composer:2.7 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
# Copy the modules' composer.json files FIRST so the merge plugin can find them
COPY Modules/Tenancy/composer.json Modules/Tenancy/composer.json
COPY Modules/AuthProvider/composer.json Modules/AuthProvider/composer.json
COPY Modules/Ticket/composer.json Modules/Ticket/composer.json
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-req=ext-pcntl

# ─── Stage 2: Runtime image ───────────────────────────────────────────────────
FROM ghcr.io/brf-tech/runr/reqs/frankenphp:${FRANKENPHP_TAG} AS base

LABEL org.opencontainers.image.source="https://github.com/brf-tech/runr"
LABEL org.opencontainers.image.description="RunR API (development)"

# The oldest PHP the image may ship: the PHP 8.3 release that fixes CVE-2026-109618 and
# CVE-2026-109619 (https://www.php.net/archive/2026.php#2026-10-21-5); Dockerfile.prod has the
# same. The build fails when the base image's PHP is older, or is on another branch than
# PHP_MIN_VERSION (a base on a new PHP branch sets that branch's fixed release here).
ARG PHP_MIN_VERSION=8.3.36
RUN php -r '$min = $argv[1] ?? ""; \
        $branch = implode(".", array_slice(explode(".", $min), 0, 2)) . "."; \
        exit(str_starts_with(PHP_VERSION, $branch) && version_compare(PHP_VERSION, $min, ">=") ? 0 : 1);' \
        "$PHP_MIN_VERSION" \
    || { echo "PHP $(php -r 'echo PHP_VERSION;') of the base image is not PHP_MIN_VERSION=${PHP_MIN_VERSION} or a later release of its branch" >&2; exit 1; }

# PHP extensions
RUN install-php-extensions \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    bcmath \
    pcntl \
    opcache \
    redis

# System packages + helm + kubectl
# git-daemon: git-http-backend of the project git remotes (/git/<org>/<project>.git)
# zstd: organization export packages (docs/org-export.md)
# helm and kubectl are pinned and checked against their published SHA-256 (amd64 / arm64), so every
# build of a release tag gets the same binaries; kubectl 1.35 is within one minor version of the
# Kubernetes versions RunR supports
ARG HELM_VERSION=v3.16.0
ARG KUBECTL_VERSION=v1.35.9
RUN apk add --no-cache git git-daemon sqlite curl libpq zstd \
    && KUBE_ARCH="$(uname -m | sed 's/x86_64/amd64/;s/aarch64/arm64/')" \
    && case "$KUBE_ARCH" in \
         amd64) HELM_SHA=327cfbc7ddc5a3eb644039ceb0cff66394628654c4f5a76bf715ed15b893983b; \
                KUBECTL_SHA=3cfeaf80be482b435b0aa214aff6e0b2c312ee23c0ff20810c75517b6004c6eb ;; \
         arm64) HELM_SHA=fc121590b532d7f2037ae5cdd39d2b56dec96069d8bc613a08965f29a156e84f; \
                KUBECTL_SHA=39c98bca82875d9a9ddfb6f3c5ab17c3f78ea0801230683e538a67d5c053308d ;; \
         *) echo "unsupported architecture $KUBE_ARCH" >&2; exit 1 ;; \
       esac \
    && curl -fsSL --retry 5 --retry-all-errors -o /tmp/helm.tar.gz "https://get.helm.sh/helm-${HELM_VERSION}-linux-${KUBE_ARCH}.tar.gz" \
    && echo "${HELM_SHA}  /tmp/helm.tar.gz" | sha256sum -c - \
    && tar xzf /tmp/helm.tar.gz -C /tmp \
    && mv "/tmp/linux-${KUBE_ARCH}/helm" /usr/local/bin/helm \
    && rm -rf /tmp/linux-* /tmp/helm.tar.gz \
    && curl -fsSL --retry 5 --retry-all-errors -o /usr/local/bin/kubectl "https://dl.k8s.io/release/${KUBECTL_VERSION}/bin/linux/${KUBE_ARCH}/kubectl" \
    && echo "${KUBECTL_SHA}  /usr/local/bin/kubectl" | sha256sum -c - \
    && chmod +x /usr/local/bin/kubectl

# Composer binary (required for dump-autoload in the entrypoint)
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

# PHP config
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php.ini     /usr/local/etc/php/conf.d/app.ini

# Entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Version (git SHA at build time, overridden by CI/CD with tag)
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION}

# Application (vendor/ comes from the api_vendor volume that docker-compose.yml's vendor-init
# fills from the vendor stage)
WORKDIR /var/www/html
COPY . .

# Clear stale caches coming from the host (they may be incompatible with the --no-dev vendor)
RUN rm -f bootstrap/cache/packages.php bootstrap/cache/services.php

# Git identity (FaaS commit operations)
RUN git config --global user.email "runr@runr.sh" \
    && git config --global user.name "RunR"

# Writable directories
RUN mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
