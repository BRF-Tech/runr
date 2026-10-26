# ─── Stage 1: Build ───────────────────────────────────────────────────────────
FROM ghcr.io/brf-tech/runr/reqs/node:20-alpine AS builder

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

EXPOSE 80

CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile"]
