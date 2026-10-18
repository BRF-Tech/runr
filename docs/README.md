# RunR documentation

Every page of the RunR documentation, grouped by topic. The [repository README](../README.md)
gives the overview and the quick start; [CHANGELOG.md](../CHANGELOG.md) lists the changes per
release.

## Getting started

| Page | What it covers |
| --- | --- |
| [Architecture](architecture.md) | Control plane, regions, project types, triggers, builds and how the components interact |
| [Development setup](development.md) | Local stack with Docker Compose or Valet, kind regions, tests and CI checks |
| [Single-host install](../compose/README.md) | Production install with Docker Compose on one machine, including air-gapped hosts |
| Helm values | Every value of the control-plane chart: `helm show values brf-tech/runr` (chart from BRF's public Helm repository, see the [quick start](../README.md#kubernetes-helm)), validated by the chart's `values.schema.json` |
| [Versioning](versioning.md) | The `/api/version` endpoint and how Compose, `runtime/ci.sh` and Helm set the version |
| [Upgrading](upgrade.md) | `helm upgrade` of an existing installation, what runs during it, the changes per release (0.4.x → 0.5.0, 0.5.0 → 0.5.1, 0.5.1 → 0.5.2) and the upgrade e2e |

## Functions and runtimes

| Page | What it covers |
| --- | --- |
| [Runtimes](runtimes.md) | Runtime plugins (`runtime.yaml`), the execution contract, the universal wrapper, the built-in catalog |
| [WebAssembly](wasm.md) | Wasm functions on the Spin shim (Rust, Go, JavaScript, Python) and `wasm-exec` (WASI modules on Spin's WAGI executor) |
| [Kata Containers hypervisors](kata-hypervisors.md) | Cloud Hypervisor, QEMU, Dragonball and Firecracker: requirements, KVM checks, configuration |
| [Runtime images and `ci.sh`](../runtime/README.md) | What is in `runtime/` and the end-to-end test script on kind |
| [Example runtimes](../examples/runtimes/README.md) | C, Rust, Bash and Q runtime plugins |
| [WebAssembly examples](../examples/wasm/README.md) | One example per Wasm runtime, plus C and Q on `wasm-exec` |

## Deploy

| Page | What it covers |
| --- | --- |
| [Git deploy](git-deploy.md) | The per-project git remote, GitHub App, GitLab and Forgejo / Gitea repositories, preview environments |
| [runr CLI](cli.md) | Install, `runr.yaml`, deploying, `runr dev` and the full command reference |
| [Terraform provider](terraform.md) | Managing RunR with Terraform or OpenTofu; per-resource reference in [terraform-provider-runr/docs](../terraform-provider-runr/docs/index.md) |
| [GitHub Action](github-action.md) | Deploying from GitHub Actions and pull request previews; action inputs in [actions/deploy](../actions/deploy/README.md) |
| [Uploaded images](images.md) | `docker save` archives, OCI image tars and `.wasm` modules uploaded into region registries |

## Data

| Page | What it covers |
| --- | --- |
| [Bindings](bindings.md) | Managed KV, object storage, Postgres and external credentials attached to projects |
| [Durable workflows](workflows.md) | Step-based workflows: steps, sleeps, events, retries, run history, triggers |
| [Workflow protocol v1](workflows-protocol.md) | The HTTP + JSON contract between the workflow engine and a workflow function, for SDK authors |
| [Python workflow SDK](../sdk/python/README.md) | `runr-workflows` |
| [TypeScript workflow SDK](../sdk/node/README.md) | `@runr/workflows` |
| [Workflow examples](../examples/workflows/README.md) | Example workflows in Python and TypeScript |

## AI

| Page | What it covers |
| --- | --- |
| [GPU workers and GPU containers](ai.md) | GPU nodes, GPU support per region, GPU functions and CaaS, quotas and GPU metering |
| [Hugging Face models](huggingface.md) | Model services (vLLM, TGI, TEI, `hf-serve`), offline copies, air-gapped regions, model bindings |
| [AI agents: MCP](mcp.md) | The RunR MCP server, OAuth 2.1 for MCP clients and hosted MCP servers |
| [Sandbox API](sandbox-api.md) | Isolated sandboxes for untrusted code: exec, files, processes, code interpreter, pools, quotas |
| [Python Sandbox SDK](../sdk/sandbox/python/README.md) | `runr-sandbox` |
| [TypeScript Sandbox SDK](../sdk/sandbox/node/README.md) | `@runr/sandbox` |
| [AI examples](../examples/ai/README.md) | GPU CaaS and Hugging Face model calls from Python, Node.js and Go |
| [MCP server examples](../examples/mcp/README.md) | Python and TypeScript starters for hosted MCP servers |

## Security and enterprise

| Page | What it covers |
| --- | --- |
| [Roles and permissions](permissions.md) | The permission catalog, built-in and custom roles, groups, project access, SSO group mapping, tokens |
| [Secrets and audit log](security.md) | Encryption at rest, secret environment variables, key rotation, real-time channels, SSO handoff, audit log |
| [Supply chain and data residency](supply-chain.md) | SBOMs, vulnerability scans, signing, policy gate, admission, data residency |
| [Security policy](../SECURITY.md) | Supported versions and how to report a vulnerability |

## Operations

| Page | What it covers |
| --- | --- |
| [Observability](observability.md) | Metrics, logs, tracing, request replay, uptime checks, status pages and alerting |
| [Monitoring and cost](monitoring.md) | Metric snapshots, pod sessions, threshold notifications and the cost system |
| [Browser shell](web-shell.md) | Terminal access to the pods of a deployment |
| [WebSocket events](websocket-events.md) | Every real-time topic: where it is broadcast and where the console handles it |
| [White-label](white-label.md) | Branding, self-service signup, onboarding, plans |
| [Billing](billing.md) | What RunR calculates (usage, plans, charges per month), the billing API and signed billing events for your invoicing system |

## Project

| Page | What it covers |
| --- | --- |
| [Contributing](../CONTRIBUTING.md) | Development workflow, checks and commit conventions |
| [Code of Conduct](../CODE_OF_CONDUCT.md) | Community standards |
| [Changelog](../CHANGELOG.md) | Changes per release |
| [License](../LICENSE) | MIT, BRF Teknoloji and RunR contributors |
