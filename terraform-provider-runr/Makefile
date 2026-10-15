# terraform-provider-runr. `make help` lists the targets.

VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null | sed 's/^v//' || echo dev)
OS_ARCH ?= $(shell go env GOOS)_$(shell go env GOARCH)
PLUGIN_DIR := $(HOME)/.terraform.d/plugins/registry.terraform.io/brf-tech/runr/$(VERSION)/$(OS_ARCH)

.PHONY: help build install test testacc vet fmt docs snapshot clean

help: ## list the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

build: ## build the provider binary
	CGO_ENABLED=0 go build -trimpath -ldflags "-s -w -X main.version=$(VERSION)" -o terraform-provider-runr .

install: build ## install into the local plugin directory (filesystem mirror)
	mkdir -p $(PLUGIN_DIR)
	cp terraform-provider-runr $(PLUGIN_DIR)/terraform-provider-runr_v$(VERSION)

test: ## unit and acceptance-style tests against the fake API (needs the terraform CLI)
	go test ./... -count=1

testacc: test ## same suite; kept for the usual provider workflow

vet: ## go vet
	go vet ./...

fmt: ## gofmt + terraform fmt of the examples
	gofmt -w .
	terraform fmt -recursive ./examples/

docs: ## regenerate docs/ from the schema and examples/ (tfplugindocs)
	go generate ./...

snapshot: ## goreleaser build of all archives into dist/ (no publishing, no signing)
	goreleaser release --snapshot --clean --skip=sign

clean: ## remove build output
	rm -rf dist terraform-provider-runr
