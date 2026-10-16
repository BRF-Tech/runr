# Build image of the wasm-go runtime (ghcr.io/brf-tech/runr/exec-wasm-go:<version>): TinyGo (with its Go
# toolchain), the Spin CLI of the Spin runtime the shim embeds, and the Spin Go SDK in the module
# cache with TinyGo's build cache warm. It runs as the build Job of a function version
# (WasmBuildService); it is never deployed as a function.
#
#   docker build -t ghcr.io/brf-tech/runr/exec-wasm-go:4.1 runtime/wasm-go     (or: runtime/ci.sh wasm)
ARG TINYGO_VERSION=0.42.0
FROM ghcr.io/brf-tech/runr/reqs/tinygo:${TINYGO_VERSION}

USER root

# The RUN steps only, not the image: a docker-container BuildKit builder gives them
# OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=unix:///dev/otel-grpc.sock, which Spin's telemetry rejects
# ("invalid URI") before the command runs (ci/check-dockerfiles.sh)
ARG OTEL_SDK_DISABLED=true

ARG SPIN_VERSION=4.1.0
ARG SPIN_SHA256_AMD64=02fdfe34a52f8bb41da5ac0a7b52abfbf0534cd57710c3a30d13293f4245e65e
ARG SPIN_SHA256_ARM64=dc19096a4da6038142dc5088f16ec04a18ff8eab08e3afbd587568b578195094
ARG TARGETARCH

RUN set -eu; \
    if ! command -v curl >/dev/null 2>&1; then \
      apt-get update; apt-get install -y --no-install-recommends ca-certificates curl; rm -rf /var/lib/apt/lists/*; \
    fi; \
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
    spin --version; \
    tinygo version

# Caches outside $HOME (the build Job sets HOME=/tmp/home): module cache, Go and TinyGo build caches
ENV GOPATH=/opt/runr/go/path \
    GOCACHE=/opt/runr/go/cache/go-build \
    XDG_CACHE_HOME=/opt/runr/go/cache \
    GOFLAGS=-mod=mod

WORKDIR /opt/runr/go
COPY go.mod spin.toml ./
COPY prewarm.go main.go
RUN set -eu; \
    go mod tidy; \
    tinygo build -target=wasip1 -gc=leaking -buildmode=c-shared -no-debug -o /tmp/prewarm.wasm .; \
    rm -f /tmp/prewarm.wasm; \
    chmod -R a+rwX /opt/runr

WORKDIR /workspace/code
