# runtime/

RunR's own region images and the script that builds them and runs the end-to-end tests.

The language runtime images (`exec-python`, `exec-nodejs`, `exec-go`, `exec-php`, `exec-pytorch`,
`exec-tensorflow`, `exec-wasm-*`), the universal wrapper `runr-wrapper`, hf-serve and the reqs
mirror (`ghcr.io/brf-tech/runr/reqs/…`) live in
[BRF-Tech/runr-runtimes](https://github.com/BRF-Tech/runr-runtimes). Its GitHub Actions build,
smoke-test and publish them to `ghcr.io/brf-tech/runr/` under the same names and tags. The
manifests of the built-in catalog are there too (`runtimes/<id>/runtime.yaml`); the API ships them
as `api/resources/runtimes/builtin.json` (see [docs/runtimes.md](../docs/runtimes.md#built-in-catalog)).
The e2e suites here pull the published images (see [Runtime images](#runtime-images)).

| Path | Contents |
|------|----------|
| `agent/` | The region agent `runr-agent` (Go): metrics, pod sessions, events, uptime checks |
| `queue-consumer/` | The queue trigger's Redis Streams consumer (Go) |
| `image-upload/` | The receiver Job of [uploaded images](../docs/images.md) and the transfer agent of [organization exports and imports](../docs/org-export.md) (`transfer`: data dumps and images in and out of a region) (Go, go-containerregistry) |
| `supply-chain/` | The tools image of the [supply-chain](../docs/supply-chain.md) Jobs (syft, trivy, cosign, oras) |
| `wasm-shim-spin/` | RunR's build of the Spin shim that WebAssembly regions install ([docs/wasm.md](../docs/wasm.md)) |
| `nginx-test/` | A small CaaS test image |
| `ci.sh`, `lib/` | Build, release and end-to-end test commands (below) |

## ci.sh

One script to build RunR's own images and run the end-to-end tests.

### Setup

```bash
chmod +x runtime/ci.sh
```

### Commands

#### Build — RunR's own images

```bash
bash runtime/ci.sh queue-consumer          # local build
bash runtime/ci.sh queue-consumer --push   # build + push to $REGISTRY (multi-arch: amd64 + arm64)
```

Every image build of `ci.sh` (local `docker build`, `--push` through buildx, and the release tars
of `build-deploy`) runs with `--pull`: base images come from their registry, never from an older
copy the build host's Docker holds. Each build command exits 0 once its images are built (and
pushed). `runtime/test/build_push_test.sh` checks both without Docker.

The region agent and the queue consumer are tagged with the release's version (`appVersion` of
`helm/Chart.yaml`) and `latest`: the API runs them at its own version. A release publishes every
image of the regions it references by version:

```bash
bash runtime/ci.sh release-images            # the list: agent and queue-consumer at appVersion,
                                             # gateway-auth, image-upload, supply-chain, the Spin shim
bash runtime/ci.sh release-images --push     # builds and pushes each one the registry does not have yet
```

`runtime/test/release_images_test.sh` keeps that list equal to the images the API references
(`api/config`, the Spin shim) and checks that no default of RunR's own images is `:latest`. The
API and UI images (`api`, `ui`, `ui-mt` at `appVersion`) come from the console's release build.

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
| `queue-consumer [--push]` | Build the queue-consumer image (`<appVersion>` and `latest`) |
| `build-agent [--push]` | Build the runr-agent image (`<appVersion>` and `latest`) |
| `release-images [--push [--force]]` | The images of the regions a release references by version; `--push` builds and pushes the missing ones |
| `wasm-shim [--push]` | Build the Spin shim image (`containerd-shim-spin`) |
| `supply-chain [--push]` | Build the supply-chain tools image |
| `image-upload [--push]` | Build the image-upload receiver / org transfer agent |
| `gateway-auth [--push]` | Build the API product's gateway-auth image |
| `test-runtimes`, `test-wasm`, `test-images`, `test-org-transfer`, `test-observability`, `test-triggers`, `test-trigger-sources`, `test-reconcile`, `test-network`, `test-internal-network`, `test-cert-manager`, `test-sandbox-api`, `test-supply-chain`, `test-ai`, `test-bindings`, `test-git-deploy`, `test-mcp`, `test-admission`, `test-runtime-patches`, `test-warmer`, `test-deploy-strategies`, `test-secrets`, `test-environments`, `test-api-product`, `test-region-move`, `test-global-projects` | End-to-end suite of one feature on Kind + Knative (same flags as `test`) |
| `test-tenancy` / `test-authprovider` / `test-ticket` | Module E2E tests on Docker Compose (no Kubernetes) |
| `build-deploy`, `deploy`, `upgrade`, `registry-push`, `deploy-status` | Build the API/UI images and install or upgrade RunR with Helm |

`build`, `wrapper`, `wasm`, `ml-runtimes`, `mirror-reqs`, `mirror-bases` and `eol` moved to
BRF-Tech/runr-runtimes: here they print where to run them and exit 1.

#### Help

```bash
bash runtime/ci.sh help
```

### Runtime images

The e2e suites pull the runtime images they need at the tags pinned in `lib/runtime-images.sh`,
the one place that defines them:

| Pin | Image | Suites |
|-----|-------|--------|
| `WRAPPER_VERSION` | `ghcr.io/brf-tech/runr/wrapper` | `test-runtimes` and every suite that prepares the runtime plugin e2e |
| `WASM_RUNTIME_VERSION` | `ghcr.io/brf-tech/runr/exec-wasm-<lang>` | `test-wasm` (rust, js), `test-images` (exec) |
| `HF_SERVE_VERSION` | `ghcr.io/brf-tech/runr/hf-serve` | `test-ai` |

- **The pins are the product's defaults.** `runtime/test/runtime_images_test.sh` fails while they
  differ from the wrapper of `api/config/executor.php`, hf-serve of `api/config/ai.php` and the Helm
  chart, and the WebAssembly versions of `api/resources/runtimes/builtin.json`.
- **The language and ML runtimes are not pinned.** The e2e regions pull the versions
  `executor:sync` finds in the registry, as installs do.
- **A pulled image is loaded into the Kind nodes for their platform.** A multi-platform image in
  Docker's containerd image store does not go through `kind load docker-image` (`kind_load_image`
  in `lib/test.sh`).

To test a runtime change before it is published, build it in a runr-runtimes checkout (for example
`./ci.sh wrapper` or `./ci.sh wasm rust`). Its local build carries the published name. Then run the
suite here with `RUNTIME_IMAGES_LOCAL=1`, which uses an image this host has instead of pulling it.

### Environment variables

| Variable | Description | Default |
|----------|-------------|---------|
| `REGISTRY` | Image registry namespace of RunR's own images | `ghcr.io/brf-tech/runr` |
| `PLATFORMS` | Build platforms | `linux/amd64,linux/arm64` |
| `BUILDER_NAME` | The docker-container buildx builder of `--push` builds. It is created when missing, named on each build, never made the host's current builder, and removed after the push. | `runr-multiarch` |
| `RUNTIME_IMAGES_REGISTRY` | Where the e2e suites pull the runtime images from | `ghcr.io/brf-tech/runr` |
| `RUNTIME_IMAGES_LOCAL` | `1`: use a runtime image this host has (a local build of BRF-Tech/runr-runtimes) instead of pulling it | `0` |
| `K8S_VERSION` | Kind Kubernetes version (for `test`) | `1.32.0` |
| `CLEANUP` | Delete the cluster after the test (for `test`) | `1` |

### Reqs mirror

Every `ghcr.io/brf-tech/runr/reqs/…` image this repository references must resolve anonymously on
ghcr.io. `ci/check-reqs-mirror.sh` checks this and caches the result for a day. To use a new
third-party image:

1. Add it to `REQS_IMAGES` in `lib/mirror.sh` of BRF-Tech/runr-runtimes.
2. Mirror it there with the "Mirror reqs images" workflow.
3. Reference it here.
