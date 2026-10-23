# RunR image upload receiver (docs/images.md): one static binary, nothing else.
#   serve   the region side of an upload — chunks forwarded by the RunR API through the Kubernetes
#           API server, then verification (size, SHA-256) and the push into the region registry
#           (docker save / OCI layout tars incl. multi-platform indexes, .wasm modules)
#   transfer  the transfer agent of an organization export / import (docs/org-export.md): serves
#           the files a tool container produced (pg_dump, redis-cli, rclone) or an image it pulls
#           as an OCI layout tar, ranges included; or receives files in chunks, verifies them and
#           hands them to the tool (in/.ready)
#   fetch   the WebAssembly module of an OCI artifact (copied into the wasm-exec build image)
#
#   docker build -t ghcr.io/brf-tech/runr/image-upload:1.1.0 runtime/image-upload   (or: runtime/ci.sh image-upload)
FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/runr/reqs/golang:1.26-alpine AS builder
ARG TARGETOS=linux
ARG TARGETARCH
ARG VERSION=1.1.0
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY *.go ./
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath -ldflags="-s -w -X main.version=${VERSION}" -o /runr-image-upload .

FROM scratch
COPY --from=builder /runr-image-upload /runr-image-upload
USER 65532:65532
EXPOSE 8080
ENTRYPOINT ["/runr-image-upload"]
CMD ["serve"]
