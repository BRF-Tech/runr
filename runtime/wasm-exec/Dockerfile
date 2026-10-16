# Build image of the wasm-exec runtime (ghcr.io/brf-tech/runr/exec-wasm-exec:<version>): the toolchains of
# languages that compile to WASI — wasi-sdk (C / C++) and Zig to preview 1 command modules, TinyGo (Go)
# with wasm-tools to WASI 0.2 command components — the Spin CLI of the Spin runtime the shim embeds
# (spin registry push), runr-image-upload (fetches uploaded modules) and runr-wasm-exec, the build
# step. It runs as the build Job of a function version (WasmBuildService); it is never deployed as a
# function.
#
#   docker build -t ghcr.io/brf-tech/runr/exec-wasm-exec:4.1 runtime/wasm-exec     (or: runtime/ci.sh wasm exec)
#
# Release archives are checked against the sha256 their releases publish (wasi-sdk, wasm-tools: the
# GitHub release asset digest; Zig: ziglang.org/download/index.json; Spin: as the other wasm runtimes).
ARG TINYGO_VERSION=0.42.0
ARG IMAGE_UPLOAD_IMAGE=ghcr.io/brf-tech/runr/image-upload:1.0.0

FROM ${IMAGE_UPLOAD_IMAGE} AS image-upload

FROM ghcr.io/brf-tech/runr/reqs/tinygo:${TINYGO_VERSION}

USER root

# The RUN steps only, not the image: a docker-container BuildKit builder gives them
# OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=unix:///dev/otel-grpc.sock, which Spin's telemetry rejects
# ("invalid URI") before the command runs (ci/check-dockerfiles.sh)
ARG OTEL_SDK_DISABLED=true

ARG SPIN_VERSION=4.1.0
ARG SPIN_SHA256_AMD64=02fdfe34a52f8bb41da5ac0a7b52abfbf0534cd57710c3a30d13293f4245e65e
ARG SPIN_SHA256_ARM64=dc19096a4da6038142dc5088f16ec04a18ff8eab08e3afbd587568b578195094
ARG WASI_SDK_VERSION=34
ARG WASI_SDK_SHA256_AMD64=b761e3a0721dbae9c09a0059e5fdb2bf917d1b4a8a7b430fb3b5aafb0984b2c4
ARG WASI_SDK_SHA256_ARM64=f7e243dff54d60bcc576e94d6166b69f410f2500ae4a9ceef34315be10e77971
ARG ZIG_VERSION=0.16.0
ARG ZIG_SHA256_AMD64=70e49664a74374b48b51e6f3fdfbf437f6395d42509050588bd49abe52ba3d00
ARG ZIG_SHA256_ARM64=ea4b09bfb22ec6f6c6ceac57ab63efb6b46e17ab08d21f69f3a48b38e1534f17
# TinyGo's -target=wasip2 embeds the WIT world and makes the component with wasm-tools
ARG WASM_TOOLS_VERSION=1.240.0
ARG WASM_TOOLS_SHA256_AMD64=b6ad301b8ac65e283703d1a5cf79280058a5f5699f8ff1fcaf66dbcf80a9efae
ARG WASM_TOOLS_SHA256_ARM64=e3d497196bf99a31a62c885d2f5c3aa1e4d4a6bc02c1bff735ffa6a4c7aa9c2f
ARG TARGETARCH

RUN set -eu; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl xz-utils; \
    rm -rf /var/lib/apt/lists/*; \
    case "${TARGETARCH:-amd64}" in \
      amd64) spin_arch=amd64; spin_sum="$SPIN_SHA256_AMD64"; sdk_arch=x86_64; sdk_sum="$WASI_SDK_SHA256_AMD64"; zig_arch=x86_64; zig_sum="$ZIG_SHA256_AMD64"; wt_arch=x86_64; wt_sum="$WASM_TOOLS_SHA256_AMD64" ;; \
      arm64) spin_arch=aarch64; spin_sum="$SPIN_SHA256_ARM64"; sdk_arch=arm64; sdk_sum="$WASI_SDK_SHA256_ARM64"; zig_arch=aarch64; zig_sum="$ZIG_SHA256_ARM64"; wt_arch=aarch64; wt_sum="$WASM_TOOLS_SHA256_ARM64" ;; \
      *) echo "unsupported architecture ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    curl -fsSL -o /tmp/spin.tar.gz "https://github.com/spinframework/spin/releases/download/v${SPIN_VERSION}/spin-v${SPIN_VERSION}-static-linux-${spin_arch}.tar.gz"; \
    echo "${spin_sum}  /tmp/spin.tar.gz" | sha256sum -c -; \
    mkdir -p /tmp/spin; tar -xzf /tmp/spin.tar.gz -C /tmp/spin; \
    install -m 0755 "$(find /tmp/spin -type f -name spin | head -n 1)" /usr/local/bin/spin; \
    curl -fsSL -o /tmp/wasi-sdk.tar.gz "https://github.com/WebAssembly/wasi-sdk/releases/download/wasi-sdk-${WASI_SDK_VERSION}/wasi-sdk-${WASI_SDK_VERSION}.0-${sdk_arch}-linux.tar.gz"; \
    echo "${sdk_sum}  /tmp/wasi-sdk.tar.gz" | sha256sum -c -; \
    mkdir -p /opt/wasi-sdk; tar -xzf /tmp/wasi-sdk.tar.gz -C /opt/wasi-sdk --strip-components=1; \
    curl -fsSL -o /tmp/zig.tar.xz "https://ziglang.org/download/${ZIG_VERSION}/zig-${zig_arch}-linux-${ZIG_VERSION}.tar.xz"; \
    echo "${zig_sum}  /tmp/zig.tar.xz" | sha256sum -c -; \
    mkdir -p /opt/zig; tar -xJf /tmp/zig.tar.xz -C /opt/zig --strip-components=1; \
    ln -s /opt/zig/zig /usr/local/bin/zig; \
    curl -fsSL -o /tmp/wasm-tools.tar.gz "https://github.com/bytecodealliance/wasm-tools/releases/download/v${WASM_TOOLS_VERSION}/wasm-tools-${WASM_TOOLS_VERSION}-${wt_arch}-linux.tar.gz"; \
    echo "${wt_sum}  /tmp/wasm-tools.tar.gz" | sha256sum -c -; \
    mkdir -p /tmp/wasm-tools; tar -xzf /tmp/wasm-tools.tar.gz -C /tmp/wasm-tools --strip-components=1; \
    install -m 0755 /tmp/wasm-tools/wasm-tools /usr/local/bin/wasm-tools; \
    rm -rf /tmp/spin /tmp/spin.tar.gz /tmp/wasi-sdk.tar.gz /tmp/zig.tar.xz /tmp/wasm-tools /tmp/wasm-tools.tar.gz; \
    spin --version; tinygo version; zig version; wasm-tools --version; /opt/wasi-sdk/bin/clang --version | head -n 1

COPY --from=image-upload /runr-image-upload /usr/local/bin/runr-image-upload
COPY runr-wasm-exec /usr/local/bin/runr-wasm-exec

# Caches outside $HOME (the build Job sets HOME=/tmp/home)
ENV WASI_SDK_PATH=/opt/wasi-sdk \
    GOPATH=/opt/runr/go/path \
    GOCACHE=/opt/runr/go/cache/go-build \
    XDG_CACHE_HOME=/opt/runr/go/cache \
    ZIG_GLOBAL_CACHE_DIR=/opt/runr/zig-cache \
    GOFLAGS=-mod=mod

# The C and Go starters are built once: the toolchains are known to work and the caches are warm
COPY prewarm/ /opt/runr/prewarm/
RUN set -eu; \
    chmod 0755 /usr/local/bin/runr-wasm-exec; \
    for lang in c go; do (cd "/opt/runr/prewarm/${lang}" && runr-wasm-exec build && rm -f main.wasm); done; \
    runr-image-upload version; \
    chmod -R a+rwX /opt/runr

WORKDIR /workspace/code
