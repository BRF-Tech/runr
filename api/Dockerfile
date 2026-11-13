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

LABEL org.opencontainers.image.title="RunR API (development)" \
      org.opencontainers.image.description="RunR API (development)" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"

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

# System packages + helm
# git-daemon: git-http-backend of the project git remotes (/git/<org>/<project>.git)
# openssh-client: Git runtime sources with an SSH deploy key (docs/runtimes.md#private-sources)
# zstd: organization export packages (docs/org-export.md)
# helm is pinned and checked against its published SHA-256 (amd64 / arm64), so every build of a
# release tag gets the same binary
ARG HELM_VERSION=v4.3.1
RUN apk add --no-cache git git-daemon openssh-client sqlite curl libpq zstd \
    && KUBE_ARCH="$(uname -m | sed 's/x86_64/amd64/;s/aarch64/arm64/')" \
    && case "$KUBE_ARCH" in \
         amd64) HELM_SHA=0403a88ae20913d11b10a2467b50087721fea7581736da6b9d3992f5357fe54b ;; \
         arm64) HELM_SHA=8cf8f66ecbc252ac8f7f06cca68aba88508ddfde3a6a34feb1678ad1694b4f88 ;; \
         *) echo "unsupported architecture $KUBE_ARCH" >&2; exit 1 ;; \
       esac \
    && curl -fsSL --retry 5 --retry-all-errors -o /tmp/helm.tar.gz "https://get.helm.sh/helm-${HELM_VERSION}-linux-${KUBE_ARCH}.tar.gz" \
    && echo "${HELM_SHA}  /tmp/helm.tar.gz" | sha256sum -c - \
    && tar xzf /tmp/helm.tar.gz -C /tmp \
    && mv "/tmp/linux-${KUBE_ARCH}/helm" /usr/local/bin/helm \
    && rm -rf /tmp/linux-* /tmp/helm.tar.gz

# kubectl, for `kubectl exec`: sandbox commands the native client cannot run and the console's
# terminal. kubectl is supported within one minor of its API server and RunR accepts clusters from
# Kubernetes 1.30 on, so the image carries one for every second minor (1.31, 1.33, 1.35 and 1.37:
# each covers the minor below and the one above it) at /usr/local/lib/runr/kubectl/<minor>/kubectl,
# and the API runs the one of each region's minor (App\Services\Sandboxes\Exec\KubectlBinary);
# kubectl on PATH is the newest. Each is its minor's newest patch, checked against its published
# SHA-256 (amd64 / arm64); the dependency bot moves each within its minor and reports a newer
# Kubernetes minor (ci/deps/packages.sh)
ARG KUBECTL_1_31_VERSION=v1.31.14
ARG KUBECTL_1_33_VERSION=v1.33.13
ARG KUBECTL_1_35_VERSION=v1.35.10
ARG KUBECTL_1_37_VERSION=v1.37.2
RUN KUBE_ARCH="$(uname -m | sed 's/x86_64/amd64/;s/aarch64/arm64/')" \
    && case "$KUBE_ARCH" in \
         amd64) set -- \
                  1.31 "$KUBECTL_1_31_VERSION" 8791ec7c8966b61420d55103a5fb948de9f0ca3d7306d789734975ad9704bdb0 \
                  1.33 "$KUBECTL_1_33_VERSION" 316d712726d857c20744c57cb36aa47cfc79bc0af7e82cebf3780244b654c073 \
                  1.35 "$KUBECTL_1_35_VERSION" c49a28f1f4efc4d475ed05f47f2ce8e563bf7bef69ed77fe1b924ea6d83b6327 \
                  1.37 "$KUBECTL_1_37_VERSION" c04487523e7aa7d1493f7808174c899db93d7a9cf57531420cd76b9bd415f8f4 ;; \
         arm64) set -- \
                  1.31 "$KUBECTL_1_31_VERSION" 3abb0c2d7121e1833831f56fd857a93de386e76d14b64baf86220d0afe495209 \
                  1.33 "$KUBECTL_1_33_VERSION" 9fe8aadd5aab9421978c7ac95de6fad304a3c4c0ce9dbf4127579779e0699080 \
                  1.35 "$KUBECTL_1_35_VERSION" 283f1be6a46b3695b625e25030106b8ffe18bd0d3a900f5b65c259ca407f04f5 \
                  1.37 "$KUBECTL_1_37_VERSION" fcc16f63186659d9722014b7e02d5fdd2d4e669cb8c0ab66365d7d3b8ba03509 ;; \
         *) echo "unsupported architecture $KUBE_ARCH" >&2; exit 1 ;; \
       esac \
    && while [ "$#" -ge 3 ]; do \
         case "$2" in "v$1".*) ;; *) echo "kubectl $2 is no $1 release" >&2; exit 1 ;; esac; \
         mkdir -p "/usr/local/lib/runr/kubectl/$1" \
         && curl -fsSL --retry 5 --retry-all-errors -o "/usr/local/lib/runr/kubectl/$1/kubectl" "https://dl.k8s.io/release/$2/bin/linux/${KUBE_ARCH}/kubectl" \
         && echo "$3  /usr/local/lib/runr/kubectl/$1/kubectl" | sha256sum -c - \
         && chmod 0755 "/usr/local/lib/runr/kubectl/$1/kubectl" \
         || exit 1; \
         newest="$1"; shift 3; \
       done \
    && ln -s "/usr/local/lib/runr/kubectl/${newest}/kubectl" /usr/local/bin/kubectl

# Composer binary (required for dump-autoload in the entrypoint)
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer

# PHP config
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php.ini     /usr/local/etc/php/conf.d/app.ini

# Entrypoint
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# runr-kubectl: the bundled kubectl of the server's Kubernetes minor, for the chart's Jobs (the
# bootstrap Job's Secret) and other kubectl calls of this image against a cluster
COPY docker/runr-kubectl /usr/local/bin/runr-kubectl
RUN chmod 0755 /usr/local/bin/runr-kubectl

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
