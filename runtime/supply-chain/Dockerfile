# RunR supply-chain tools image (docs/supply-chain.md): Trivy's image plus syft, cosign and oras,
# driven by runr-supply-chain (one Kubernetes Job per image in the region):
#   pipeline   SBOM (syft: SPDX JSON + CycloneDX) → OCI referrers (oras) → vulnerability scan
#              (trivy) → signature + attestations (cosign, region key, no transparency log)
#   keygen     cosign key pair written straight into a Kubernetes Secret (k8s:// reference)
#   mirror-db  Trivy vulnerability DBs copied into the region registry (offline scanning)
#
# Release binaries are verified against the checksums file of their release.
ARG TRIVY_VERSION=0.76.2

FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/runr/reqs/alpine:3.21 AS tools
ARG TARGETARCH
ARG SYFT_VERSION=1.18.1
ARG COSIGN_VERSION=3.1.4
ARG ORAS_VERSION=1.2.2
WORKDIR /dl
RUN set -eux; \
    arch="${TARGETARCH:-amd64}"; \
    gh=https://github.com; \
    wget -q "${gh}/anchore/syft/releases/download/v${SYFT_VERSION}/syft_${SYFT_VERSION}_linux_${arch}.tar.gz"; \
    wget -q "${gh}/anchore/syft/releases/download/v${SYFT_VERSION}/syft_${SYFT_VERSION}_checksums.txt"; \
    grep " syft_${SYFT_VERSION}_linux_${arch}.tar.gz\$" "syft_${SYFT_VERSION}_checksums.txt" | sha256sum -c -; \
    tar -xzf "syft_${SYFT_VERSION}_linux_${arch}.tar.gz" syft; \
    wget -q "${gh}/sigstore/cosign/releases/download/v${COSIGN_VERSION}/cosign-linux-${arch}"; \
    wget -q "${gh}/sigstore/cosign/releases/download/v${COSIGN_VERSION}/cosign_checksums.txt"; \
    grep " cosign-linux-${arch}\$" cosign_checksums.txt | sha256sum -c -; \
    mv "cosign-linux-${arch}" cosign; \
    wget -q "${gh}/oras-project/oras/releases/download/v${ORAS_VERSION}/oras_${ORAS_VERSION}_linux_${arch}.tar.gz"; \
    wget -q "${gh}/oras-project/oras/releases/download/v${ORAS_VERSION}/oras_${ORAS_VERSION}_checksums.txt"; \
    grep " oras_${ORAS_VERSION}_linux_${arch}.tar.gz\$" "oras_${ORAS_VERSION}_checksums.txt" | sha256sum -c -; \
    tar -xzf "oras_${ORAS_VERSION}_linux_${arch}.tar.gz" oras; \
    chmod 0755 syft cosign oras

FROM ghcr.io/aquasecurity/trivy:${TRIVY_VERSION}
# RunR's own labels in place of the Trivy base's (version, revision and created: the release build,
# ci.sh --push). The tools keep their own licenses (Apache-2.0); MIT is runr-supply-chain's.
LABEL org.opencontainers.image.title="runr-supply-chain" \
      org.opencontainers.image.description="RunR supply-chain tools: Trivy with syft and cosign and oras driven by runr-supply-chain" \
      org.opencontainers.image.licenses="MIT AND Apache-2.0" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.url="https://runr.sh" \
      org.opencontainers.image.documentation="https://github.com/BRF-Tech/runr/tree/main/docs" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"
COPY --from=tools /dl/syft /dl/cosign /dl/oras /usr/local/bin/
COPY runr-supply-chain.sh /usr/local/bin/runr-supply-chain
RUN chmod 0755 /usr/local/bin/runr-supply-chain
WORKDIR /tmp
ENTRYPOINT ["/usr/local/bin/runr-supply-chain"]
CMD ["pipeline"]
