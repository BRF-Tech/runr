# Build image of the wasm-js runtime (ghcr.io/runrsh/exec-wasm-js:<version>): Node.js, the Spin CLI
# of the Spin runtime the shim embeds, and the node_modules of the default package.json (Spin JS
# SDK build tools, esbuild) installed once under /opt/runr/js. It runs as the build Job of a
# function version (WasmBuildService); it is never deployed as a function.
#
#   docker build -t ghcr.io/runrsh/exec-wasm-js:4.1 runtime/wasm-js     (or: runtime/ci.sh wasm)
ARG NODE_VERSION=22
FROM node:${NODE_VERSION}-bookworm-slim

ARG SPIN_VERSION=4.1.0
ARG SPIN_SHA256_AMD64=02fdfe34a52f8bb41da5ac0a7b52abfbf0534cd57710c3a30d13293f4245e65e
ARG SPIN_SHA256_ARM64=dc19096a4da6038142dc5088f16ec04a18ff8eab08e3afbd587568b578195094
ARG TARGETARCH

RUN set -eu; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl; \
    rm -rf /var/lib/apt/lists/*; \
    case "${TARGETARCH:-amd64}" in \
      amd64) arch=amd64; sum="$SPIN_SHA256_AMD64" ;; \
      arm64) arch=aarch64; sum="$SPIN_SHA256_ARM64" ;; \
      *) echo "unsupported architecture ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/spin.tar.gz "https://github.com/spinframework/spin/releases/download/v${SPIN_VERSION}/spin-v${SPIN_VERSION}-static-linux-${arch}.tar.gz"; \
    echo "${sum}  /tmp/spin.tar.gz" | sha256sum -c -; \
    mkdir -p /tmp/spin; \
    tar -xzf /tmp/spin.tar.gz -C /tmp/spin; \
    install -m 0755 "$(find /tmp/spin -type f -name spin | head -n 1)" /usr/local/bin/spin; \
    rm -rf /tmp/spin /tmp/spin.tar.gz; \
    spin --version

# npm's cache outside $HOME (the build Job sets HOME=/tmp/home): an install of a project's own
# package.json reuses what is here (--prefer-offline)
ENV npm_config_cache=/opt/runr/js/.npm \
    npm_config_update_notifier=false

WORKDIR /opt/runr/js
COPY package.json build.mjs spin.toml ./
COPY prewarm.js index.js
RUN set -eu; \
    # LF like the build files RunR writes (a build compares package.json with this one)
    sed -i 's/\r$//' package.json build.mjs spin.toml; \
    npm install --no-audit --no-fund; \
    node build.mjs; \
    rm -rf build dist index.js; \
    chmod -R a+rwX /opt/runr

WORKDIR /workspace/code
