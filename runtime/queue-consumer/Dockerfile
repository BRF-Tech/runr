FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/reqs/golang:1.26-alpine AS builder
ARG TARGETOS=linux
ARG TARGETARCH
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY main.go .
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -ldflags="-s -w" -o /queue-consumer .

FROM ghcr.io/brf-tech/reqs/alpine:3.21
RUN apk add --no-cache ca-certificates
COPY --from=builder /queue-consumer /queue-consumer
EXPOSE 8081
ENTRYPOINT ["/queue-consumer"]
