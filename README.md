# RunR

**RunR is a self-hosted serverless platform on Kubernetes and Knative.** Write a function in the
browser, `git push` a project or bring a container image or WebAssembly module, and RunR builds
it and runs it on your own clusters with request-based autoscaling and scale-to-zero. Every
change is a version you can deploy, compare and roll back.

RunR is software you install, not a hosted service: the control plane and every region run on
infrastructure you operate — bare metal, a private cloud or managed Kubernetes, connected or
air-gapped. It is developed by [BRF Teknoloji](https://brf.sh) and released as open source under
the [MIT License](LICENSE).

- [Features](#features)
- [Measured cold starts](#measured-cold-starts)
- [Architecture](#architecture)
- [Quick start](#quick-start)
- [Repository layout](#repository-layout)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)

## Features

### Functions and runtimes

- **Three project types**: XaaS (a single-file function edited in the built-in Monaco editor),
  FaaS (a multi-file project with dependencies, built in the cluster when needed) and CaaS (any
  container image with port, command, arguments and environment).
- **Triggers**: HTTP (Knative Service), schedule (CronJob), queue (Redis Streams consumer with
  retries and a dead-letter stream) and durable workflows.
- **Pluggable runtimes**: every language is a plugin — a `runtime.yaml` manifest plus an image.
  Python, Node.js, Go, PHP, PyTorch and TensorFlow ship built in; platform administrators and
  organizations register their own (C, Rust, Bash and Q examples included), in `http`, `exec`
  or `persistent` mode behind RunR's universal wrapper. `runr dev` runs them locally on the same
  images ([runtimes](docs/runtimes.md)).
- **WebAssembly**: Rust, Go (TinyGo), JavaScript / TypeScript and Python components on the Spin
  shim of runwasi, installed per region from the console ([WebAssembly](docs/wasm.md)).
- **wasm-exec**: any WASI preview 1 command module — C / C++, Zig, Go, a `build.sh` or an
  uploaded `.wasm` — on Spin's WAGI executor ([wasm-exec](docs/wasm.md#wasm-exec)).
- **Sandboxed workloads**: map each project type of a region to runc, **gVisor** or **Kata
  Containers** (Cloud Hypervisor, QEMU, Dragonball, Firecracker); Kata is offered only on nodes
  with hardware virtualization ([Kata hypervisors](docs/kata-hypervisors.md)).

### Deploy

- **Versions and deployments**: every code or configuration change is a version backed by a
  per-project git repository; deploy any ready version, watch the rollout live, roll back.
- **Git push and providers**: every project has its own git remote (`git push runr main`), or
  connects to a repository on GitHub (GitHub App), GitLab or Forgejo / Gitea with push-to-deploy
  and commit statuses ([git deploy](docs/git-deploy.md)).
- **Preview environments** for every pull / merge request, with their own URL, scale-to-zero and
  automatic cleanup.
- **`runr` CLI**: scaffold, run locally, deploy and operate every RunR feature from a terminal or
  CI ([CLI](docs/cli.md)).
- **Terraform provider** for projects, variables, domains, deployments, bindings, runtimes,
  workflow triggers, model services, hosted MCP servers, access control and branding
  ([Terraform](docs/terraform.md)).
- **GitHub Action** that deploys on push and runs preview environments for pull requests
  ([GitHub Action](docs/github-action.md)).
- **Uploads**: `docker save` archives, OCI image tars and `.wasm` modules pushed into a region's
  registry in resumable chunks — also for regions without internet access
  ([uploaded images](docs/images.md)).
- **Networking**: per-project subdomains, path-based API gateways, custom domains with uploaded
  or cert-manager certificates, private PostgreSQL connections through Skupper.

### Data

- **Bindings**: managed KV (Redis), object storage (S3 on Garage or an external endpoint),
  Postgres (CloudNativePG) and external credentials, attached to projects and injected as
  environment variables, with rotation, retention and restore ([bindings](docs/bindings.md)).
- **Durable workflows**: code-first, step-based functions whose progress survives crashes,
  redeploys and sleeps of days — retries, timers, events, child workflows — with Python and
  TypeScript SDKs and a run history in the console ([workflows](docs/workflows.md)).

### AI

- **GPU workers**: RunR detects NVIDIA GPU nodes, installs the device plugin or GPU Operator
  and schedules GPU functions and containers there, scaling to zero and metered by GPU time
  ([AI](docs/ai.md)).
- **GPU CaaS**: any container image with a GPU count, product and memory requirement.
- **Hugging Face models**: one-click model services (vLLM, TGI, TEI, `hf-serve` or your own
  image) online or fully offline, a playground, and model bindings for functions in any language
  ([Hugging Face](docs/huggingface.md)).
- **MCP server**: a built-in Model Context Protocol server at `/mcp` lets agents such as Claude
  Code operate RunR within the permissions of their token, with one-click OAuth 2.1
  ([MCP](docs/mcp.md)).
- **MCP hosting**: your own MCP servers — Python and TypeScript starters or any image — run as
  projects behind a gateway with public, token or OAuth access, a call log and an inspector.
- **Sandbox API**: isolated, short-lived sandboxes for code that AI agents write — exec, files,
  background processes, a code interpreter, warm pools and quotas, with Python and TypeScript
  SDKs ([Sandbox API](docs/sandbox-api.md)).

### Security and enterprise

- **RBAC**: one permission catalog, built-in and custom roles, groups, per-project access and
  scoped API tokens ([permissions](docs/permissions.md)).
- **SSO**: Google, GitHub, Microsoft, GitLab, OpenID Connect and SAML 2.0, with SSO group
  mapping to roles; optional two-factor authentication.
- **Audit log**: append-only record of sign-ins, changes, deploys, shell sessions and denials,
  with export and SIEM forwarding ([security](docs/security.md)).
- **Secrets at rest**: every stored secret is encrypted with key rotation; secret environment
  variables reach workloads only through Kubernetes Secrets.
- **Supply chain**: SBOMs, vulnerability scans (also offline), cosign signatures, a policy gate
  before deploy and signed-image admission in the region ([supply chain](docs/supply-chain.md)).
- **Data residency**: organizations pinned to allowed regions and data locations.

### Operations

- **Multi-region**: register any Kubernetes cluster as a region; RunR installs Knative Serving,
  Traefik, an in-cluster Zot registry, Redis, Prometheus, Loki and its region agent, keeps them
  up to date with `region:reconcile` and migrates a region to a new cluster.
- **Observability**: metrics and logs per project, OpenTelemetry tracing with Tempo, request
  replay, uptime checks, public status pages and alerting ([observability](docs/observability.md)).
- **Live console**: real-time updates over WebSockets, a browser shell into running pods, guided
  tours, English and Turkish.
- **Multi-tenant edition**: one PostgreSQL schema per tenant, per-region quotas, usage metering
  with configurable prices and monthly charges per tenant, read by your own invoicing system
  through the billing API and signed billing events ([billing](docs/billing.md)).
- **Support tickets** with attachments, assignment and real-time updates.
- **White-label**: your brand, self-service signup, optional plans with limits and trial days
  ([white-label](docs/white-label.md)).

## Measured cold starts

Measured on RunR 0.5.0, 2026-10-15: single-node k3s v1.34.11 on a test VM (8 vCPU Broadwell,
nested KVM, 15.6 GiB), Knative 1.21.3, gVisor 20260921.0, Kata Containers 3.28.0; function
250m CPU / 256 MiB (container 500m / 512 MiB, WebAssembly 250m / 128 MiB), image on the node,
RunR's default scale-to-zero; HTTPS from inside the VM through Traefik, a new connection per
request. Cold start: from zero replicas to the first full HTTPS response, median (p90), n=12 each.
Warm request: median of 100 requests.

| Sandbox runtime | Function | Cold start | Warm request |
| --- | --- | --- | --- |
| runc | Python | 1.50 s (2.14) | 9 ms |
| runc | Node.js | 1.62 s (2.21) | 9 ms |
| runc | Go | 1.54 s (1.87) | 9 ms |
| runc | PHP | 1.65 s (2.48) | 9 ms |
| runc | Container image (CaaS) | 1.57 s (2.02) | 9 ms |
| gVisor | Python | 1.97 s (2.64) | 12 ms |
| Kata Containers + Cloud Hypervisor | Python | 3.11 s (3.25) | 10 ms |
| Kata Containers + Firecracker | Python | 3.21 s (3.47) | 10 ms |
| Kata Containers + QEMU | Python | 3.89 s (4.45) | 10 ms |
| Kata Containers + Dragonball | Python | 3.95 s (4.04) | 11 ms |
| WebAssembly (Spin shim) | Rust | 1.54 s (1.84) | 9 ms |
| WebAssembly (Spin shim) | JavaScript | 2.12 s (2.58) | 11 ms |

Kata was measured with Python only; Firecracker's root filesystem is on containerd's devmapper
snapshotter. Sandbox API, create to first command (Python 3.14, 500m / 512 MiB, n=10 per class):
cold 2.01 s under gVisor, 2.99 s under Kata Firecracker, 4.01 s under Kata Cloud Hypervisor;
0.28–0.36 s from a warm pool of 2.

## Architecture

```
 Browser, CLI, MCP clients ──► runr-gateway (Caddy)
               ├─ /                  → runr-ui      Vue 3 SPA
               ├─ /api               → runr-api     Laravel 12 on Octane (FrankenPHP)
               ├─ /git               → runr-api     git smart HTTP remote of every project
               ├─ /mcp, /oauth2      → runr-api     MCP server, hosted MCP servers, OAuth 2.1
               └─ /ws                → runr-reverb  WebSockets: UI events, browser shell, region agents
             runr-horizon (queue workers) · runr-scheduler · PostgreSQL · Redis
                     │  Kubernetes API + Helm (kubeconfig)          ▲ WebSocket
                     ▼                                              │
 Region (any Kubernetes cluster)                                    │
   Knative Serving + Traefik · project Knative Services / CronJobs / queue consumers
   Zot registry · Redis · gVisor / Kata Containers / Spin shim · GPU device plugin
   Prometheus · Loki · OpenTelemetry Collector + Tempo · data services · runr-agent ─┘
```

The control plane (API, UI, workers, WebSocket server, database) manages one or more regions.
Builds, deploys, supply-chain runs and region installs run as queued jobs and report progress to
the console in real time; the agent in each region streams metrics, pod sessions and Kubernetes
events back. See [docs/architecture.md](docs/architecture.md) for the full picture.

## Quick start

### Local development (Docker Compose)

Requirements: Docker with the Compose plugin.

```bash
git clone https://github.com/brf-tech/runr.git
cd runr
bash scripts/devready.sh docker
```

The script creates `.env`, generates the application key, builds and starts the stack and runs
the migrations and seeders. Open the console at <http://localhost:8061> (API on port 8060) and
sign in as `admin` with the `ADMIN_PASSWORD` from `api/.env.docker`.

### A local region with kind

To deploy functions you need a Kubernetes cluster as a region. For local work:

```bash
bash compose/kind-setup.sh          # kind cluster (1 control plane + 2 workers) + cloud-provider-kind
```

Then add a region in the console (**Settings → Add Region**), upload the generated kubeconfig
(`compose/runr-kubeconfig.yaml`) and let RunR install Knative Serving and the region components.
Details: [docs/development.md](docs/development.md).

### Kubernetes (Helm)

The Helm chart `runr` is published in BRF's public Helm repository; no credentials are needed.
Add the repository once, then install the chart. `domain` is the only required value:

```bash
helm repo add brf-tech https://helm.brf.sh
helm upgrade --install runr brf-tech/runr \
  --namespace runr --create-namespace \
  --set domain=runr.example.com
```

To install a specific release, pin the chart version and the API and UI image tags to it:

```bash
helm upgrade --install runr brf-tech/runr --version 0.5.7 \
  --namespace runr --create-namespace \
  --set domain=runr.example.com \
  --set api.image.tag=0.5.7 --set ui.image.tag=0.5.7
```

The chart installs the gateway, UI, API, Horizon, scheduler, Reverb and — unless you point it at
external services — PostgreSQL, Redis and Mailpit, plus migration and bootstrap hooks that create
the first administrator. `helm status runr -n runr` prints how to read the generated password.
Set `edition=multi-tenant` for the Tenancy and Auth Provider modules. All values are documented in
the chart's `values.yaml` (`helm show values brf-tech/runr`) and validated by its `values.schema.json`.
Existing installations are upgraded in place with `helm upgrade`: [docs/upgrade.md](docs/upgrade.md).

### Single host (Docker Compose with prebuilt images)

[compose/README.md](compose/README.md) describes a production install on one machine, including
air-gapped hosts.

### Your first deployment from the CLI

```bash
runr login https://runr.example.com
runr init hello --runtime python
cd hello
runr dev          # http://localhost:8080, restarts on every change
runr deploy       # builds, deploys and prints the URL
```

Install the CLI with `curl -fsSL https://runr.sh/install.sh | sh` (Linux, macOS) or download it
for your platform from [runr.sh/dl](https://runr.sh/dl/); see [docs/cli.md](docs/cli.md#install).

## Repository layout

| Path | Contents |
| --- | --- |
| [`api/`](api) | Laravel 12 API (PHP 8.3); optional modules in `api/Modules` (AuthProvider, Tenancy, Ticket) |
| [`vue/`](vue) | Vue 3 + Vite console (Pinia, Tailwind CSS, Monaco, xterm.js) |
| [`helm/`](helm) | Helm chart `runr` for the control plane |
| [`runtime/`](runtime) | Runtime images and manifests, the universal wrapper, the region agent, the queue consumer, the image-upload receiver, supply-chain tools, `ci.sh` |
| [`cli/`](cli) | The `runr` CLI (Go) |
| [`terraform-provider-runr/`](terraform-provider-runr) | Terraform provider (Go) and its reference docs |
| [`actions/`](actions) | GitHub Action `actions/deploy` |
| [`sdk/`](sdk) | Workflow SDKs for Python and TypeScript, Sandbox SDKs in `sdk/sandbox` |
| [`examples/`](examples) | Runtime plugins, WebAssembly, AI, MCP server and workflow examples |
| [`proxy/`](proxy) | Sentry tunnel (Go) for the development stack |
| [`compose/`](compose) | Single-host Docker Compose installation and kind helpers |
| [`scripts/`](scripts) | Development environment setup (`devready.sh`) |
| [`ci/`](ci) | Release checks for pinned Helm charts and Knative versions |
| [`landing/`](landing) | Website [runr.sh](https://runr.sh) |
| [`docs/`](docs) | Documentation |

## Documentation

Start with the [documentation index](docs/README.md), which lists every page by topic. Good
first reads: [architecture](docs/architecture.md), [development setup](docs/development.md),
[runtimes](docs/runtimes.md), [CLI](docs/cli.md) and [roles and permissions](docs/permissions.md).
Changes per release are in [CHANGELOG.md](CHANGELOG.md).

## Contributing

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md) for the development workflow,
checks and commit conventions, and [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Please report
security issues privately as described in [SECURITY.md](SECURITY.md).

## License

RunR is released under the [MIT License](LICENSE).
Copyright (c) 2026 BRF Teknoloji and RunR contributors.
