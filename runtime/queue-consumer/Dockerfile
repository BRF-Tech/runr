FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/runr/reqs/golang:1.27-alpine AS builder
ARG TARGETOS=linux
ARG TARGETARCH
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -ldflags="-s -w" -o /queue-consumer .

FROM ghcr.io/brf-tech/runr/reqs/alpine:3.21
# version, revision and created: the release build (ci.sh --push)
LABEL org.opencontainers.image.title="runr-queue-consumer" \
      org.opencontainers.image.description="RunR event gateway and queue consumer: Redis streams to Knative sinks" \
      org.opencontainers.image.licenses="MIT" \
      org.opencontainers.image.vendor="BRF Teknoloji" \
      org.opencontainers.image.url="https://runr.sh" \
      org.opencontainers.image.documentation="https://github.com/BRF-Tech/runr/tree/main/docs" \
      org.opencontainers.image.source="https://github.com/BRF-Tech/runr"
RUN apk add --no-cache ca-certificates
COPY --from=builder /queue-consumer /queue-consumer
EXPOSE 8081
ENTRYPOINT ["/queue-consumer"]
