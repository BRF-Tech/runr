FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/runr/reqs/golang:1.26-alpine AS builder
ARG TARGETOS=linux
ARG TARGETARCH
WORKDIR /app
COPY main.go .
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -o /echo-server main.go

FROM ghcr.io/brf-tech/runr/reqs/alpine:3.20
LABEL org.opencontainers.image.title="nginx-test" \
      org.opencontainers.image.description="RunR CaaS test image: an HTTP echo server for the end-to-end tests" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.url="https://runr.sh" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"
COPY --from=builder /echo-server /echo-server
EXPOSE 80
CMD ["/echo-server"]
