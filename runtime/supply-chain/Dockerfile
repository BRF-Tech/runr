# RunR supply-chain tools image (docs/supply-chain.md): Trivy's image plus syft, cosign and oras,
# driven by runr-supply-chain (one Kubernetes Job per image in the region):
#   pipeline   SBOM (syft: SPDX JSON + CycloneDX) → OCI referrers (oras) → vulnerability scan
#              (trivy) → signature + attestations (cosign, region key, no transparency log)
#   keygen     cosign key pair written straight into a Kubernetes Secret (k8s:// reference)
#   mirror-db  Trivy vulnerability DBs copied into the region registry (offline scanning)
#
# Release binaries are verified against the checksums file of their release.
ARG TRIVY_VERSION=0.58.1

FROM --platform=$BUILDPLATFORM ghcr.io/runrsh/reqs/alpine:3.21 AS tools
ARG TARGETARCH
ARG SYFT_VERSION=1.18.1
ARG COSIGN_VERSION=2.4.1
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
COPY --from=tools /dl/syft /dl/cosign /dl/oras /usr/local/bin/
COPY runr-supply-chain.sh /usr/local/bin/runr-supply-chain
RUN chmod 0755 /usr/local/bin/runr-supply-chain
WORKDIR /tmp
ENTRYPOINT ["/usr/local/bin/runr-supply-chain"]
CMD ["pipeline"]
