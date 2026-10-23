# RunR documentation

Every page of the RunR documentation, grouped by topic. The [repository README](../README.md)
gives the overview and the quick start; [CHANGELOG.md](../CHANGELOG.md) lists the changes per
release.

## Install and operate

| Page | What it covers |
| --- | --- |
| [Production install](install.md) | Installing the control plane on Kubernetes with Helm: requirements, editions and the multi-tenant console image, DNS and the base domain, ingress and TLS, database, Redis and mail with their passwords from Secrets, storage (ReadWriteOnce or ReadWriteMany), high availability, the first administrator and the bootstrap Job, the Horizon dashboard, network policies, a production checklist, backup and restore |
| [Single-host install](../compose/README.md) | Production install with Docker Compose on one machine, including air-gapped hosts: modules, the first administrator, HTTPS through a Caddy on the host, the Horizon dashboard, updates |
| [Helm values](helm-values.md) | Every value of the control-plane chart `brf-tech/runr`, its default and what it does, and what the chart validates |
| [Internal network and access modes](internal-network.md) | How projects call each other inside a region (`<project>.<organization>.runr.internal`, `RUNR_INTERNAL_TOKEN`), allow lists and cross-organization grants, access modes public / IP allow list / internal only, enforce and audit, how existing regions move |
| [Regions](regions.md) | What a region is, cluster requirements, cluster-local services and network isolation, connecting a cluster, every region setting (cert-manager for custom domains, global DNS, secret stores), status and its status block, moving a region to another cluster, changing the base domain, regions in multi-tenant installs, removal |
| [Tenancy](tenancy.md) | Multi-tenant installs: what a tenant is, when to enable tenancy, Platform users and Tenant users, platform administrators, converting a single-tenant installation (`tenancy:setup`, also by the bootstrap Job), tenant lifecycle, opening a tenant as administrator, regions and quotas, organization domains and their moves, tenant schema migrations |
| [Modules and feature toggles](modules.md) | Tenancy, single sign-on (AuthProvider: OAuth, OpenID Connect, SAML, linked accounts) and support tickets (staff view, assignment, tags, attachments), and every feature toggle with its environment variable and Helm value |
| [Upgrading](upgrade.md) | `helm upgrade` of an existing installation, what runs during it and the changes per release; 0.5.10 → 0.6.0: what to decide before, the order of operations, new Helm values, finalizing and rolling back |
| [Versioning](versioning.md) | The `/api/version` endpoint and how Compose, `runtime/ci.sh` and Helm set the version; project versions and rollback with the configuration a deployment ran with |

## Concepts and development

| Page | What it covers |
| --- | --- |
| [Architecture](architecture.md) | Control plane and its gateway routes, hook Jobs, regions and domain moves, project types, triggers, builds, modules, editions and how the components interact |
| [Scaling](scaling.md) | Scale to zero, minimum and maximum scale, and the timed warmer: schedule rows, the organization's time zone, how it is applied on the Knative PodAutoscaler, capacity, billing and the preview API |
| [Development setup](development.md) | Local stack with Docker Compose or Valet, tests and CI checks |

## Functions and runtimes

| Page | What it covers |
| --- | --- |
| [Runtimes](runtimes.md) | Runtime plugins (`runtime.yaml`), the execution contract, the universal wrapper, the built-in catalog, runtime patches (levels, apply / pin, deadlines, notifications) and end-of-life reminders |
| [Triggers](triggers.md) | A project's trigger list: HTTP, schedules in a time zone, queues, webhook inbox with signature presets and replay, topics (fan-out), platform events, broker and external sources (Kafka, NATS, RabbitMQ, MQTT, Postgres CDC, S3, e-mail over IMAP); the event gateway's delivery, retries and dead letters; adding a trigger type |
| [WebAssembly](wasm.md) | Wasm functions on the Spin shim (Rust, Go, JavaScript, Python) and `wasm-exec` (WASI modules on Spin's WAGI executor) |
| [Kata Containers hypervisors](kata-hypervisors.md) | Cloud Hypervisor, QEMU, Dragonball and Firecracker: requirements, KVM checks, configuration |
| [Runtime images and `ci.sh`](../runtime/README.md) | What is in `runtime/` and the end-to-end test script on kind |
| [Example runtimes](../examples/runtimes/README.md) | C, Rust, Bash and Q runtime plugins |
| [WebAssembly examples](../examples/wasm/README.md) | One example per Wasm runtime, plus C and Q on `wasm-exec` |

## Deploy

| Page | What it covers |
| --- | --- |
| [Git deploy](git-deploy.md) | The per-project git remote, GitHub App, GitLab and Forgejo / Gitea repositories, preview environments |
| [Environments and promotion](environments.md) | dev / staging / production of a project: configuration overrides, secrets, bindings, domains, gateway routes, private database connections and regions per environment, git branches, promotion with approval, rollback, durable workflows per environment, metering, quotas |
| [runr CLI](cli.md) | Install, `runr.yaml`, deploying, `runr dev` and the full command reference |
| [Deploy strategies](deploy-strategies.md) | Blue-green and canary rollouts per function: test URLs, promotion, the warm previous revision and instant rollback, canary steps and their error-rate / latency thresholds, the API, MCP tools and CLI |
| [Terraform provider](terraform.md) | Managing RunR with Terraform or OpenTofu; per-resource reference in [terraform-provider-runr/docs](../terraform-provider-runr/docs/index.md) |
| [GitHub Action](github-action.md) | Deploying from GitHub Actions and pull request previews; action inputs in [actions/deploy](../actions/deploy/README.md) |
| [Uploaded images](images.md) | `docker save` archives, OCI image tars and `.wasm` modules uploaded into region registries |
| [Global projects](global-projects.md) | One project in several regions behind one global host name: global DNS (a PowerDNS server per region, the zone's delegation), HA and proximity modes, rollout to the regions, health checks and failover, the wildcard certificate (DNS-01), switchover and failover, data across regions (CloudNativePG replica clusters, data links with mutual TLS), gateways and custom domains in every region |
| [API product](api-product.md) | Offering a gateway's API to others: consumers, API keys, JWT routes, plans with rate limits and quotas, usage and usage events for an external invoicing system, gateway-only projects, the gateway's custom domain and developer page |

## Data

| Page | What it covers |
| --- | --- |
| [Bindings](bindings.md) | Managed KV, object storage, Postgres and external credentials attached to projects |
| [Durable workflows](workflows.md) | Step-based workflows: steps, sleeps, events, retries, run history, triggers, one workflow app per environment |
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
| [Roles and permissions](permissions.md) | The permission catalog, built-in and custom roles, groups, project access, SSO group mapping, tokens, platform administrators |
| [Secrets and audit log](security.md) | Encryption at rest, where secrets never appear, secret environment variables (values kept in the region only), key rotation, real-time channels, sign-in sessions and API tokens, SSO handoff, the signed runtime patch feed, the audit log and its events |
| [Secret stores](secret-stores.md) | Secret variables that refer to your Vault / OpenBao, AWS Secrets Manager, GCP Secret Manager or Azure Key Vault, read in the region by External Secrets Operator with the region's identity — no credential handed to RunR; an OpenBao RunR runs in the region for values and dynamic Postgres credentials |
| [Supply chain and data residency](supply-chain.md) | SBOMs, vulnerability scans, signing, policy gate, admission, data residency |
| [Security policy](../SECURITY.md) | Supported versions and how to report a vulnerability |

## Operations

| Page | What it covers |
| --- | --- |
| [Observability](observability.md) | Metrics, logs, tracing, request replay, uptime checks, status pages and alerting |
| [Load tests](load-test.md) | k6 load tests of HTTP projects from inside their region: what a test can choose, the abuse guard, latency / errors / throughput with pods and cold starts, the last runs side by side |
| [Monitoring and cost](monitoring.md) | Metric snapshots, pod sessions and metering (size × pod time), threshold notifications, rightsizing recommendations from the pods' real peaks, and the cost system |
| [Browser shell](web-shell.md) | Terminal access to the pods of a deployment |
| [WebSocket events](websocket-events.md) | Every real-time topic: where it is broadcast and where the console handles it |
| [Organization export and import](org-export.md) | An organization packed into one versioned file (projects, versions and code, configs, bindings and their data, encrypted secrets) and imported again — another organization, another installation, disaster recovery |
| [White-label](white-label.md) | Branding, self-service signup, onboarding |
| [Billing](billing.md) | The Billing module: monthly bills per project paid by its owner, region prices with effective dates, pay-as-you-go and fixed regions with approval, shared regions with markup, environments and previews on the bill, queue consumer charging, the monthly cost limit, rightsizing savings, project stop and resume, the billing API and signed events for your invoicing system |

## Project

| Page | What it covers |
| --- | --- |
| [Contributing](../CONTRIBUTING.md) | Development workflow, checks and commit conventions |
| [Code of Conduct](../CODE_OF_CONDUCT.md) | Community standards |
| [Changelog](../CHANGELOG.md) | Changes per release |
| [License](../LICENSE) | MIT, BRF Teknoloji and RunR contributors |
