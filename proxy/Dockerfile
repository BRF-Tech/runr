FROM ghcr.io/brf-tech/runr/reqs/golang:1.27-alpine AS builder
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY main.go ./
RUN CGO_ENABLED=0 go build -ldflags="-s -w" -o proxy .

FROM ghcr.io/brf-tech/runr/reqs/alpine:3.21
LABEL org.opencontainers.image.title="runr-sentry-proxy" \
      org.opencontainers.image.description="RunR development stack: the console's Sentry tunnel" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"
RUN apk add --no-cache ca-certificates
COPY --from=builder /app/proxy /proxy
CMD ["/proxy"]
