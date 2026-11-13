FROM ghcr.io/brf-tech/runr/reqs/golang:1.27-alpine AS builder
WORKDIR /build
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /agent ./cmd/main.go

FROM ghcr.io/brf-tech/runr/reqs/alpine:3.21
# version, revision and created: the release build (ci.sh --push)
LABEL org.opencontainers.image.title="runr-agent" \
      org.opencontainers.image.description="RunR region agent: monitoring and the internal network's authorization" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.url="https://runr.sh" \
      org.opencontainers.image.documentation="https://github.com/BRF-Tech/runr/tree/main/docs" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"
RUN apk add --no-cache ca-certificates
COPY --from=builder /agent /agent
ENTRYPOINT ["/agent"]
