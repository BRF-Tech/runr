# ─── Stage 1: Build ───────────────────────────────────────────────────────────
# On the build host's platform: its output is static files, the same for every image platform, so
# an image for another platform (docker buildx --platform) never runs npm, Vite or the compressors
# under emulation
FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/runr/reqs/node:22-alpine AS builder

WORKDIR /app

# Önce dependency dosyaları (cache layer)
COPY package.json package-lock.json ./
RUN npm ci --prefer-offline

# Build-time env (production: VITE_WS_PROXY=true)
ARG VITE_API_URL
ARG VITE_WS_PROXY
ARG VITE_REVERB_KEY
ARG VITE_TENANCY_ENABLED
ARG VITE_AUTH_PROVIDER_ENABLED
ARG VITE_TICKET_ENABLED
# Billing module UI (pages stay hidden while the API reports billing off: official images set true)
ARG VITE_BILLING_ENABLED

# Kaynak kod
COPY . .
ENV NODE_OPTIONS="--max-old-space-size=4096"
RUN npm run build

# Pre-compressed copies of the build, which Caddy serves as they are (Caddyfile: file_server
# precompressed) instead of compressing each response: on the fly the 4.9 MB main bundle took 4.4 s
# with zstd. Every text file above 1 KiB gets a .br (Brotli, quality 11) and a .gz (gzip -9) next to
# it. No Zstandard copy: every browser that takes zstd takes Brotli too, which is smaller. Not
# branding-defaults.js: the container writes it at start, and a copy would keep the build's.
RUN apk add --no-cache brotli gzip \
    && find dist -type f -size +1k \
         \( -name '*.js' -o -name '*.mjs' -o -name '*.css' -o -name '*.html' -o -name '*.svg' \
            -o -name '*.json' -o -name '*.map' -o -name '*.txt' -o -name '*.ttf' -o -name '*.wasm' \) \
         ! -path dist/branding-defaults.js -print0 \
       | xargs -0 -r -n 8 -P "$(nproc)" sh -c \
           'for f; do brotli -q 11 -f -k -- "$f" && gzip -9 -n -f -k -- "$f" || exit 1; done' sh

# ─── Stage 2: Serve ───────────────────────────────────────────────────────────
FROM ghcr.io/brf-tech/runr/reqs/caddy:2-alpine

# RunR's own labels in place of the Caddy base's (the release build adds version, revision and
# created; org.opencontainers.image.source links the ghcr package to the repository)
LABEL org.opencontainers.image.title="RunR UI" \
      org.opencontainers.image.description="RunR UI: the console served by Caddy" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.url="https://runr.sh" \
      org.opencontainers.image.documentation="https://github.com/BRF-Tech/runr/tree/main/docs" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"

COPY --from=builder /app/dist /srv
COPY Caddyfile /etc/caddy/Caddyfile
# At start: /srv/branding-defaults.js from the BRANDING_* env (the platform's default theme and
# colours before the first paint), then Caddy
COPY docker-entrypoint.sh /usr/local/bin/runr-ui-entrypoint
RUN chmod +x /usr/local/bin/runr-ui-entrypoint

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/runr-ui-entrypoint"]
CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile"]
