# runr CLI. `make help` lists the targets.

VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
COMMIT  ?= $(shell git rev-parse --short HEAD 2>/dev/null)
DATE    ?= $(shell date -u +%FT%TZ)
PKG     := github.com/brf-tech/runr/cli/internal/version
LDFLAGS := -s -w -X $(PKG).Version=$(VERSION) -X $(PKG).Commit=$(COMMIT) -X $(PKG).Date=$(DATE)
BIN     ?= bin/runr
PLATFORMS := linux/amd64 linux/arm64 darwin/amd64 darwin/arm64 windows/amd64 windows/arm64

.PHONY: help build install test contract vet fmt check cross snapshot openapi clean

help: ## list the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

build: ## build bin/runr for this machine
	CGO_ENABLED=0 go build -trimpath -ldflags "$(LDFLAGS)" -o $(BIN) .

install: ## install runr into $(GOBIN)
	CGO_ENABLED=0 go install -trimpath -ldflags "$(LDFLAGS)" .

test: ## run all tests (fake API, no cluster needed)
	go test ./...

contract: ## check the endpoints the client uses against testdata/openapi.json
	go test ./pkg/runr -run 'TestContract' -v

vet: ## go vet
	go vet ./...

fmt: ## gofmt the sources
	gofmt -w .

check: vet test ## vet and test

cross: ## build every release platform into bin/
	@for p in $(PLATFORMS); do \
		os=$${p%/*}; arch=$${p#*/}; ext=; [ $$os = windows ] && ext=.exe; \
		echo "bin/runr_$${os}_$${arch}$$ext"; \
		CGO_ENABLED=0 GOOS=$$os GOARCH=$$arch go build -trimpath -ldflags "$(LDFLAGS)" -o bin/runr_$${os}_$${arch}$$ext . || exit 1; \
	done

snapshot: ## goreleaser build of all archives into dist/ (no publishing)
	goreleaser release --snapshot --clean

openapi: ## export the API's OpenAPI document into pkg/runr/testdata/openapi.json
	./scripts/export-openapi.sh pkg/runr/testdata/openapi.json

clean: ## remove build output
	rm -rf bin dist
