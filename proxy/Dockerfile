FROM ghcr.io/brf-tech/runr/reqs/golang:1.26-alpine AS builder
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY main.go ./
RUN CGO_ENABLED=0 go build -ldflags="-s -w" -o proxy .

FROM ghcr.io/brf-tech/runr/reqs/alpine:3.21
RUN apk add --no-cache ca-certificates
COPY --from=builder /app/proxy /proxy
CMD ["/proxy"]
