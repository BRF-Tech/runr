FROM ghcr.io/brf-tech/reqs/golang:1.26-alpine AS builder
WORKDIR /build
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /agent ./cmd/main.go

FROM ghcr.io/brf-tech/reqs/alpine:3.21
RUN apk add --no-cache ca-certificates
COPY --from=builder /agent /agent
ENTRYPOINT ["/agent"]
