# RunR gateway-auth (docs/api-product.md): one static binary, nothing else. The region Traefik asks
# it (forwardAuth) before every call of a gateway route that needs an API key or a JWT; it limits
# and counts the calls and reports them to RunR.
#
#   docker build -t ghcr.io/brf-tech/runr/gateway-auth:1.1.0 runtime/gateway-auth   (or: runtime/ci.sh gateway-auth)
FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/runr/reqs/golang:1.27-alpine AS builder
ARG TARGETOS=linux
ARG TARGETARCH
ARG VERSION=1.1.0
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY *.go ./
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath -ldflags="-s -w -X main.version=${VERSION}" -o /runr-gateway-auth .

FROM scratch
# version, revision and created: the release build (ci.sh --push)
LABEL org.opencontainers.image.title="runr-gateway-auth" \
      org.opencontainers.image.description="RunR API product service of the regions (API keys and JWT routes with limits and usage reports)" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.url="https://runr.sh" \
      org.opencontainers.image.documentation="https://github.com/BRF-Tech/runr/tree/main/docs" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"
# CA roots: JWKS of token issuers and RunR's URL are often https
COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=builder /runr-gateway-auth /runr-gateway-auth
USER 65532:65532
EXPOSE 8080
ENTRYPOINT ["/runr-gateway-auth"]
