# syntax=docker/dockerfile:1
#
# The Spin shim RunR installs on region nodes (WebAssembly support, App\Services\Wasm\WasmShim):
# containerd-shim-spin v0.26.0 built with one change in its runwasi library (containerd-shim-wasm
# 1.0.1), versioned v0.26.0-runr.1.
#
#   docker build -t ghcr.io/brf-tech/runr/containerd-shim-spin:v0.26.0-runr.1 runtime/wasm-shim-spin
#   runtime/ci.sh wasm-shim [--push]          (--push: linux/amd64 + linux/arm64)
#   docker build --target test runtime/wasm-shim-spin    the patched executor's unit tests
#
# The change: runwasi's Executor::setup_envs is a no-op for every container since runwasi commit
# 76f436f (2024-09, containerd-shim-spin v0.16.0 and later), so the Linux containers of a Wasm pod
# (Knative's queue-proxy next to the Spin app) run with the shim's own environment instead of the
# one in their OCI spec — queue-proxy exits with "Required key CONTAINER_CONCURRENCY missing value"
# (containerd/runwasi issue #1061). patches/containerd-shim-wasm-1.0.1-linux-container-env.patch is
# the change of containerd/runwasi PR #1183 (commit 814b711804057cfc11550d81d91bea6b299dacb7) on
# the 1.0.1 crate: for a Linux container setup_envs does what libcontainer's default executor does
# (clear the shim's environment, set the spec's); Wasm containers are unchanged. Drop the patch and
# this build once a containerd-shim-spin release depends on a containerd-shim-wasm with that fix.
#
# Pinned inputs, every download checked: the containerd-shim-spin commit of tag v0.26.0 (its
# Cargo.lock, cargo build --locked), the containerd-shim-wasm 1.0.1 crate (the checksum of that
# Cargo.lock), the Rust toolchain of its rust-toolchain.toml (official image by digest, from the
# reqs mirror), the cross-rs musl images upstream builds its release binaries with (by digest),
# protoc and libseccomp as in
# upstream's cross/Dockerfile (sha256). Static musl binaries for amd64 and arm64, cross-compiled on
# an amd64 build platform (no emulation).
#
# The image: /shim/containerd-shim-spin-v2, /shim/SHA256SUMS, /shim/VERSION, /shim/LICENSE and
# /shim/NOTICE (Apache-2.0) on RunR's Alpine base. The wasm-installer DaemonSet copies /shim into
# its pod with an init container and installs the binary on the node (WasmManifests,
# WasmNodeScripts).

ARG RUST_IMAGE=ghcr.io/brf-tech/runr/reqs/rust:1.95.0-slim-bookworm@sha256:d7482085ff5b415f84dba5647ae71606650bdef00db7aeb69f4b3d170c3e4082
ARG CROSS_IMAGE_AMD64=ghcr.io/cross-rs/x86_64-unknown-linux-musl:main@sha256:a5b346562935938c10d99d88adbcb809ec8c01e20f4bdef14584654b876c0c07
ARG CROSS_IMAGE_ARM64=ghcr.io/cross-rs/aarch64-unknown-linux-musl:main@sha256:5ca8072c8ff326c2285221c22b8bf4959b2d85c9fe0a0ad3ed2ec4d023a3b18e
ARG BASE_IMAGE=ghcr.io/brf-tech/runr/reqs/alpine:3.21

FROM --platform=$BUILDPLATFORM ${RUST_IMAGE} AS rust
FROM --platform=$BUILDPLATFORM ${CROSS_IMAGE_AMD64} AS cross-amd64
FROM --platform=$BUILDPLATFORM ${CROSS_IMAGE_ARM64} AS cross-arm64

# ── toolchain: the cross-rs image of the target + Rust + upstream's cross/Dockerfile additions ──
FROM cross-${TARGETARCH} AS toolchain
ARG TARGETARCH
ARG PROTOC_VERSION=25.3
ARG PROTOC_SHA256=f853e691868d0557425ea290bf7ba6384eef2fa9b04c323afab49a770ba9da80
ARG LIBSECCOMP_VERSION=2.5.3
ARG LIBSECCOMP_SHA256=59065c8733364725e9721ba48c3a99bbc52af921daf48df4b1e012fbc7b10a76
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
# the git dependencies (Spin crates, their Azure SDK fork, ...) through the git CLI: libgit2's TLS
# transfers of these large repositories fail intermittently ("SSL error: unknown error"); a
# transfer that stalls is aborted after a minute without progress (the fetch step retries)
ENV RUSTUP_HOME=/usr/local/rustup \
    CARGO_HOME=/usr/local/cargo \
    PATH=/usr/local/cargo/bin:$PATH \
    RUSTUP_TOOLCHAIN=1.95.0 \
    CARGO_NET_GIT_FETCH_WITH_CLI=true \
    CARGO_NET_RETRY=5 \
    GIT_HTTP_LOW_SPEED_LIMIT=1000 \
    GIT_HTTP_LOW_SPEED_TIME=60
COPY --from=rust /usr/local/rustup /usr/local/rustup
COPY --from=rust /usr/local/cargo /usr/local/cargo
RUN set -eux; \
    case "${TARGETARCH}" in \
      amd64) target=x86_64-unknown-linux-musl ;; \
      arm64) target=aarch64-unknown-linux-musl ;; \
      *) echo "unsupported architecture ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    echo "${target}" > /rust-target; \
    rustup target add "${target}"; \
    rustc --version
# protoc (prost builds) and a static libseccomp for the target (libcontainer's seccomp support)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates curl gperf pkg-config unzip; \
    rm -rf /var/lib/apt/lists/*; \
    curl -fsSL -o /tmp/protoc.zip "https://github.com/protocolbuffers/protobuf/releases/download/v${PROTOC_VERSION}/protoc-${PROTOC_VERSION}-linux-x86_64.zip"; \
    echo "${PROTOC_SHA256}  /tmp/protoc.zip" | sha256sum -c -; \
    unzip -q /tmp/protoc.zip -d /usr/local bin/protoc 'include/*'; \
    rm /tmp/protoc.zip; \
    curl -fsSL -o /tmp/libseccomp.tar.gz "https://github.com/seccomp/libseccomp/releases/download/v${LIBSECCOMP_VERSION}/libseccomp-${LIBSECCOMP_VERSION}.tar.gz"; \
    echo "${LIBSECCOMP_SHA256}  /tmp/libseccomp.tar.gz" | sha256sum -c -; \
    tar -xzf /tmp/libseccomp.tar.gz -C /tmp; \
    cd "/tmp/libseccomp-${LIBSECCOMP_VERSION}"; \
    ./configure --host="${CROSS_CMAKE_SYSTEM_PROCESSOR}-linux-musl" CC="${CROSS_TOOLCHAIN_PREFIX}gcc" \
      --prefix="${CROSS_SYSROOT}" --disable-shared --enable-static >/dev/null; \
    make -j"$(nproc)" >/dev/null; \
    make install >/dev/null; \
    cd /; \
    rm -rf /tmp/libseccomp*
ENV LIBSECCOMP_LINK_TYPE=static \
    LIBSECCOMP_LIB_PATH=${CROSS_SYSROOT}/lib
# as upstream's cross/Dockerfile: keeps cross-rs' CMake toolchain file from using the musl sysroot
ENV CROSS_SYSROOT=/

# ── sources: containerd-shim-spin v0.26.0 + the patched containerd-shim-wasm 1.0.1 ──
FROM toolchain AS source
ARG SHIM_SPIN_REPO=https://github.com/spinframework/containerd-shim-spin.git
# tag v0.26.0
ARG SHIM_SPIN_COMMIT=922760061d91bacd455c5824b8143dec2a372515
ARG SHIM_WASM_VERSION=1.0.1
# checksum of containerd-shim-wasm 1.0.1 in containerd-shim-spin v0.26.0's Cargo.lock
ARG SHIM_WASM_SHA256=38357d0c7fdb42eea29ad99f1da2488c2a93ad3ca5d0e684121b34ca815c75c0
WORKDIR /src
RUN set -eux; \
    git init -q containerd-shim-spin; \
    cd containerd-shim-spin; \
    git fetch -q --depth 1 "${SHIM_SPIN_REPO}" "${SHIM_SPIN_COMMIT}"; \
    git checkout -q FETCH_HEAD; \
    test "$(git rev-parse HEAD)" = "${SHIM_SPIN_COMMIT}"; \
    curl -fsSL -o /tmp/shim-wasm.crate "https://static.crates.io/crates/containerd-shim-wasm/containerd-shim-wasm-${SHIM_WASM_VERSION}.crate"; \
    echo "${SHIM_WASM_SHA256}  /tmp/shim-wasm.crate" | sha256sum -c -; \
    tar -xzf /tmp/shim-wasm.crate -C /src; \
    mv "/src/containerd-shim-wasm-${SHIM_WASM_VERSION}" /src/containerd-shim-wasm; \
    rm /tmp/shim-wasm.crate
COPY patches/ /src/patches/
RUN set -eux; \
    cd /src/containerd-shim-wasm; \
    git apply --verbose /src/patches/containerd-shim-wasm-1.0.1-linux-container-env.patch; \
    cd /src/containerd-shim-spin; \
    git apply --verbose /src/patches/containerd-shim-spin-v0.26.0-patched-shim-wasm.patch

# ── the shim ──
FROM source AS build
ARG TARGETARCH
ARG VERSION=v0.26.0-runr.1
# the locked dependencies first, retried: crates.io and the git repositories of Cargo.lock
RUN --mount=type=cache,id=runr-shim-spin-registry-${TARGETARCH},target=/usr/local/cargo/registry \
    --mount=type=cache,id=runr-shim-spin-git-${TARGETARCH},target=/usr/local/cargo/git \
    set -eu; \
    cd /src/containerd-shim-spin; \
    for attempt in 1 2 3 4 5; do \
      if cargo fetch --locked; then exit 0; fi; \
      echo "cargo fetch failed (attempt ${attempt}), retrying"; \
      sleep 15; \
    done; \
    exit 1
RUN --mount=type=cache,id=runr-shim-spin-registry-${TARGETARCH},target=/usr/local/cargo/registry \
    --mount=type=cache,id=runr-shim-spin-git-${TARGETARCH},target=/usr/local/cargo/git \
    --mount=type=cache,id=runr-shim-spin-target-${TARGETARCH},target=/src/containerd-shim-spin/target \
    set -eux; \
    target="$(cat /rust-target)"; \
    cd /src/containerd-shim-spin; \
    cargo build --release --locked --target "${target}" -p containerd-shim-spin-v2; \
    mkdir -p /out; \
    cp "target/${target}/release/containerd-shim-spin-v2" /out/; \
    echo "${VERSION}" > /out/VERSION; \
    cd /out; \
    sha256sum containerd-shim-spin-v2 > SHA256SUMS; \
    cat SHA256SUMS

# ── unit tests of the patched executor (docker build --target test) ──
# In runwasi's own workspace at the containerd-shim-wasm/v1.0.1 tag (its Cargo.lock, its toolchain
# and the in-tree test modules the published crate's dev-dependencies do not build any more): the
# patch applied to crates/containerd-shim-wasm, then the executor's tests — among them a static
# busybox `env` run through the validate / setup_envs / exec sequence libcontainer runs.
FROM --platform=$BUILDPLATFORM ${RUST_IMAGE} AS test
# tag containerd-shim-wasm/v1.0.1
ARG RUNWASI_COMMIT=5a8fc8e9ee0df28b0485da7419ee74c72127dfba
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
ENV CARGO_NET_GIT_FETCH_WITH_CLI=true CARGO_NET_RETRY=5
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends busybox-static ca-certificates git libseccomp-dev pkg-config protobuf-compiler; \
    rm -rf /var/lib/apt/lists/*
WORKDIR /src
RUN set -eux; \
    git init -q runwasi; \
    cd runwasi; \
    git fetch -q --depth 1 https://github.com/containerd/runwasi.git "${RUNWASI_COMMIT}"; \
    git checkout -q FETCH_HEAD; \
    test "$(git rev-parse HEAD)" = "${RUNWASI_COMMIT}"; \
    rustup toolchain install "$(sed -n 's/^channel *= *"\(.*\)"/\1/p' rust-toolchain.toml)" --profile minimal --target wasm32-wasip1
COPY patches/containerd-shim-wasm-1.0.1-linux-container-env.patch /src/
RUN cd /src/runwasi && git apply --verbose --directory=crates/containerd-shim-wasm /src/containerd-shim-wasm-1.0.1-linux-container-env.patch
# runwasi's cargo runner (scripts/test-runner.sh) runs the tests through sudo; the build runs as root
RUN --mount=type=cache,id=runr-shim-spin-test-registry,target=/usr/local/cargo/registry \
    --mount=type=cache,id=runr-shim-spin-test-git,target=/usr/local/cargo/git \
    --mount=type=cache,id=runr-shim-spin-test-target,target=/src/runwasi/target \
    cd /src/runwasi && \
    CARGO_TARGET_X86_64_UNKNOWN_LINUX_GNU_RUNNER=env CARGO_TARGET_AARCH64_UNKNOWN_LINUX_GNU_RUNNER=env \
      cargo test --locked -p containerd-shim-wasm --lib sys::container::executor -- --test-threads=1

# ── the image ──
FROM ${BASE_IMAGE}
ARG VERSION=v0.26.0-runr.1
LABEL org.opencontainers.image.title="containerd-shim-spin" \
      org.opencontainers.image.version="${VERSION}" \
      org.opencontainers.image.description="containerd-shim-spin v0.26.0 with a patched containerd-shim-wasm (NOTICE), as RunR installs it on region nodes" \
      org.opencontainers.image.licenses="Apache-2.0" \
      org.opencontainers.image.source="https://github.com/spinframework/containerd-shim-spin" \
      org.opencontainers.image.revision="922760061d91bacd455c5824b8143dec2a372515"
COPY --from=build /out/ /shim/
COPY --chmod=0644 LICENSE NOTICE /shim/
