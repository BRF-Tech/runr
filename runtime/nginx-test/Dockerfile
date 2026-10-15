FROM --platform=$BUILDPLATFORM ghcr.io/brf-tech/reqs/golang:1.26-alpine AS builder
ARG TARGETOS=linux
ARG TARGETARCH
WORKDIR /app
COPY main.go .
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -o /echo-server main.go

FROM ghcr.io/brf-tech/reqs/alpine:3.20
COPY --from=builder /echo-server /echo-server
EXPOSE 80
CMD ["/echo-server"]
