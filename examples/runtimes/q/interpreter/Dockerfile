# Q runtime image: the q interpreter on Alpine. The build context is this directory
# (Dockerfile, go.mod, *.go) — RunR builds it in each region from the files uploaded with
# the runtime and caches the image in the region registry.
FROM ghcr.io/brf-tech/reqs/golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod *.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /q .

FROM ghcr.io/brf-tech/reqs/alpine:3.21
COPY --from=build /q /usr/local/bin/q
ENTRYPOINT ["/usr/local/bin/q"]
