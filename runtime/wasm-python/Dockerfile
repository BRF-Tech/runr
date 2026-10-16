# Build image of the wasm-python runtime (ghcr.io/brf-tech/runr/exec-wasm-python:<version>): Python with
# componentize-py and the Spin Python SDK, and the Spin CLI of the Spin runtime the shim embeds.
# It runs as the build Job of a function version (WasmBuildService); it is never deployed as a
# function.
#
#   docker build -t ghcr.io/brf-tech/runr/exec-wasm-python:4.1 runtime/wasm-python     (or: runtime/ci.sh wasm)
ARG PYTHON_VERSION=3.13
FROM ghcr.io/brf-tech/runr/reqs/python:${PYTHON_VERSION}-slim-bookworm

# The RUN steps only, not the image: a docker-container BuildKit builder gives them
# OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=unix:///dev/otel-grpc.sock, which Spin's telemetry rejects
# ("invalid URI") before the command runs (ci/check-dockerfiles.sh)
ARG OTEL_SDK_DISABLED=true

ARG SPIN_VERSION=4.1.0
ARG SPIN_SHA256_AMD64=02fdfe34a52f8bb41da5ac0a7b52abfbf0534cd57710c3a30d13293f4245e65e
ARG SPIN_SHA256_ARM64=dc19096a4da6038142dc5088f16ec04a18ff8eab08e3afbd587568b578195094
ARG COMPONENTIZE_PY_VERSION=0.25.1
ARG SPIN_SDK_VERSION=5.0.0
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
    spin --version; \
    pip install --no-cache-dir "componentize-py==${COMPONENTIZE_PY_VERSION}" "spin-sdk==${SPIN_SDK_VERSION}"

ENV PIP_DISABLE_PIP_VERSION_CHECK=1 \
    PYTHONDONTWRITEBYTECODE=1

WORKDIR /opt/runr/python
COPY spin.toml ./
COPY prewarm.py app.py
RUN set -eu; \
    mkdir -p /tmp/runr-deps; \
    spin build; \
    rm -f app.wasm; \
    chmod -R a+rwX /opt/runr

WORKDIR /workspace/code
