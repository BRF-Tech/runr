# ─── Stage 1: Build ───────────────────────────────────────────────────────────
FROM ghcr.io/brf-tech/reqs/node:20-alpine AS builder

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

# Kaynak kod
COPY . .
ENV NODE_OPTIONS="--max-old-space-size=4096"
RUN npm run build

# ─── Stage 2: Serve ───────────────────────────────────────────────────────────
FROM ghcr.io/brf-tech/reqs/caddy:2-alpine

LABEL org.opencontainers.image.description="RunR UI"

COPY --from=builder /app/dist /srv
COPY Caddyfile /etc/caddy/Caddyfile

EXPOSE 80

CMD ["caddy", "run", "--config", "/etc/caddy/Caddyfile"]
