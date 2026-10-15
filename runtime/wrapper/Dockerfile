# RunR universal wrapper (runtime contract v1): one static binary, nothing else. Init containers
# run `runr-wrapper install <dest>` to copy it into a pod or a build context — no shell needed.
FROM --platform=$BUILDPLATFORM ghcr.io/runrsh/reqs/golang:1.26-alpine AS builder
ARG TARGETOS=linux
ARG TARGETARCH
WORKDIR /src
COPY go.mod ./
COPY *.go ./
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath -ldflags="-s -w" -o /runr-wrapper .

FROM scratch
COPY --from=builder /runr-wrapper /runr-wrapper
ENTRYPOINT ["/runr-wrapper"]
