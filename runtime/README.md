# runtime/

The images RunR runs in its regions and the script that builds and tests them.

| Path | Contents |
|------|----------|
| `python/`, `nodejs/`, `go/`, `php/`, `pytorch/`, `tensorflow/` | Built-in runtimes: `runtime.yaml` manifest, Dockerfile and language wrapper of each runtime image (`exec-<runtime>:<version>`), see [docs/runtimes.md](../docs/runtimes.md) |
| `wasm-rust/`, `wasm-go/`, `wasm-js/`, `wasm-python/`, `wasm-exec/` | WebAssembly runtimes on the Spin shim: manifests and build toolchain images, see [docs/wasm.md](../docs/wasm.md) |
| `wrapper/` | The universal wrapper `runr-wrapper` (Go) for runtimes in `exec` and `persistent` mode |
| `agent/` | The region agent `runr-agent` (Go): metrics, pod sessions, events, uptime checks |
| `queue-consumer/` | The queue trigger's Redis Streams consumer (Go) |
| `image-upload/` | The receiver Job of [uploaded images](../docs/images.md) (Go, go-containerregistry) |
| `supply-chain/` | The tools image of the [supply-chain](../docs/supply-chain.md) Jobs (syft, trivy, cosign, oras) |
| `hf-serve/` | RunR's serving engine for transformers / diffusers [model services](../docs/huggingface.md) |
| `nginx-test/` | A small CaaS test image |
| `ci.sh`, `lib/` | Build, mirror, release and end-to-end test commands (below) |

## ci.sh

One script to build the images, check the runtime images for EOL versions and run the
end-to-end tests.

### Setup

```bash
chmod +x runtime/ci.sh
```

### Commands

#### Build — build the runtime images

```bash
# Local build only (no push)
bash runtime/ci.sh build

# Build + push to $REGISTRY (multi-arch: amd64 + arm64)
bash runtime/ci.sh build --push
```

Discovers the versions of the upstream Docker Hub images (python, node, golang, php), checks them against the
endoflife.date API, skips EOL versions and builds the rest as `$REGISTRY/exec-<runtime>:<version>`.

Every image build of `ci.sh` — local `docker build` and `--push` through buildx, the release tars of
`build-deploy` — runs with `--pull`: base images come from their registry, never from an older copy
the build host's Docker holds. Each build command exits 0 once its images are built (and pushed).
`runtime/test/build_push_test.sh` checks both without Docker.

#### EOL — EOL report and cleanup

```bash
# Show the report (checks the exec-* tags through the Docker Hub API)
bash runtime/ci.sh eol

# Delete EOL tags (asks for confirmation)
DOCKER_HUB_USER=xxx DOCKER_HUB_TOKEN=xxx bash runtime/ci.sh eol --prune

# Delete without confirmation
DOCKER_HUB_USER=xxx DOCKER_HUB_TOKEN=xxx bash runtime/ci.sh eol --prune --yes
```

#### Test image — CaaS test image (nginx-test)

```bash
# Local build
bash runtime/ci.sh test-image

# Build + push
bash runtime/ci.sh test-image --push
```

#### Test — E2E test (Kind cluster + Knative)

```bash
# With the default Kubernetes 1.32.0
bash runtime/ci.sh test

# Another Kubernetes version
bash runtime/ci.sh test --k8s=1.33.0

# Keep the cluster afterwards
bash runtime/ci.sh test --no-cleanup
```

Creates a Kind cluster, starts the Laravel API with Docker Compose and runs `php artisan test:e2e`, which
installs Knative through the API. See [docs/development.md](../docs/development.md#e2e-tests).

#### Other commands

| Command | Purpose |
|---------|---------|
| `queue-consumer [--push]` | Build the queue-consumer image |
| `build-agent [--push]` | Build the runr-agent image |
| `wrapper [--push]` | Build the universal wrapper image (`runr-wrapper`) |
| `wasm [--push]` | Build the toolchain images of the WebAssembly runtimes |
| `ml-runtimes [--push] [--list] [--no-smoke] [pytorch\|tensorflow] [cpu\|gpu]` | Build the PyTorch and TensorFlow runtime images, CPU and `-gpu`, for linux/amd64 (`exec-<runtime>:<X.Y>[-gpu]`, X.Y from the framework pin); local builds are smoke-tested, `--list` prints the image references ([docs/runtimes.md](../docs/runtimes.md#ml-runtimes)) |
| `supply-chain [--push]` | Build the supply-chain tools image |
| `image-upload [--push]` | Build the image-upload receiver |
| `test-runtimes`, `test-wasm`, `test-images`, `test-observability`, `test-reconcile`, `test-sandbox-api`, `test-supply-chain`, `test-ai`, `test-bindings`, `test-git-deploy`, `test-mcp` | End-to-end suite of one feature on Kind + Knative (same flags as `test`) |
| `mirror-reqs [--dry-run] [image...]` / `mirror-bases [--dry-run]` | Mirror upstream and runtime base images to `$REGISTRY/reqs/` (multi-arch); every `reqs/` image the repository references is in one of the two lists (`ci/check-reqs-mirror.sh`). When Docker Hub refuses a copy (anonymous pull limit), the image comes from the next upstream of `REQS_UPSTREAMS` that has Docker Hub's digest of it, copied by that digest (`runtime/test/mirror_test.sh`) |
| `test-tenancy` / `test-authprovider` / `test-ticket` | Module E2E tests on Docker Compose (no Kubernetes) |
| `build-deploy`, `deploy`, `upgrade`, `registry-push`, `deploy-status` | Build the API/UI images and install or upgrade RunR with Helm |

#### Help

```bash
bash runtime/ci.sh help
```

### Environment variables

| Variable | Description | Default |
|----------|-------------|---------|
| `REGISTRY` | Image registry namespace | `ghcr.io/brf-tech/runr` |
| `PLATFORMS` | Build platforms | `linux/amd64,linux/arm64` |
| `BUILDER_NAME` | The docker-container buildx builder of `--push` builds: created when missing, named on each build (never made the host's current builder), removed after the push | `runr-multiarch` |
| `REQS_UPSTREAMS` | Where `mirror-reqs` / `mirror-bases` copy from, in order: Docker Hub, then pull-through mirrors of it (`<mirror>/library/alpine`, …), a mirror only by Docker Hub's digest | `docker.io mirror.gcr.io public.ecr.aws/docker` |
| `DOCKER_HUB_USER` | Docker Hub user (for `eol --prune`) | — |
| `DOCKER_HUB_TOKEN` | Docker Hub token/password (for `eol --prune`) | — |
| `K8S_VERSION` | Kind Kubernetes version (for `test`) | `1.32.0` |
| `CLEANUP` | Delete the cluster after the test (for `test`) | `1` |

### Typical CI/CD flow

```bash
# 1. Build + push the runtime images (EOL versions are skipped)
bash runtime/ci.sh build --push

# 2. Build + push the test image
bash runtime/ci.sh test-image --push

# 3. EOL report
bash runtime/ci.sh eol

# 4. (optional) Delete EOL tags
bash runtime/ci.sh eol --prune --yes

# 5. (optional) E2E test
bash runtime/ci.sh test
```
