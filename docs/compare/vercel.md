# RunR vs Vercel

<!-- description: RunR vs Vercel: git deploys, previews and functions on your own Kubernetes clusters next to Vercel's hosted platform, every Vercel fact sourced. -->

RunR is an open-source (MIT) serverless platform: functions, multi-file projects and containers on
Knative, on Kubernetes clusters you operate. [Vercel](https://vercel.com/docs/git) is a hosted
platform that builds and deploys web applications from git, with a preview deployment for every
pull request and Vercel Functions for the server side.

Both deploy on `git push` and give pull requests their own preview. Vercel runs on Vercel's
infrastructure and has no on-premises product
([private cloud](https://vercel.com/kb/guide/can-i-use-vercel-to-deploy-to-a-private-cloud));
RunR runs on your clusters.

## Differences at a glance

| | RunR | Vercel |
| --- | --- | --- |
| Where it runs | Your Kubernetes clusters (1.30 or newer), each connected as a region ([Regions](../regions.md)) | Vercel's cloud; on Enterprise, Bring Your Own Cloud runs functions as Lambda functions in your AWS account ([BYOC](https://vercel.com/docs/networking/bring-your-own-cloud)) |
| From git | A git remote per project, or GitHub, GitLab and Forgejo / Gitea repositories; a preview environment per pull / merge request ([Git deploy](../git-deploy.md)) | GitHub, GitLab, Bitbucket and more; a preview deployment per branch push and pull request, with framework detection ([Git](https://vercel.com/docs/git), [build presets](https://vercel.com/docs/builds/configure-a-build#framework-preset)) |
| Code it runs | Python, Node.js, Go, PHP, PyTorch, TensorFlow and WebAssembly runtimes, runtime plugins of your own, any OCI image ([Runtimes](../runtimes.md)) | Node.js, Python, Bun, Go, Ruby, Rust, Wasm, Edge and container images; Bun, Go, Ruby, Rust and Container are in beta ([runtimes](https://vercel.com/docs/functions/runtimes)) |
| Environments | dev, staging and production per project; promotion of the same image, with approval ([Environments](../environments.md)) | Local, Preview and Production; custom environments on Pro and Enterprise ([environments](https://vercel.com/docs/deployments/environments#custom-environments)) |
| Rollouts | Blue-green with a test URL; canary in steps that rolls back by itself on error rate or p95 latency ([Deploy strategies](../deploy-strategies.md)) | Rolling Releases on Pro and Enterprise; Instant Rollback on every plan ([Rolling Releases](https://vercel.com/docs/rolling-releases), [Instant Rollback](https://vercel.com/docs/instant-rollback)) |
| Events | HTTP, schedules, queues, webhooks, topics, Kafka, NATS, RabbitMQ, MQTT, Postgres CDC, S3 and IMAP ([Triggers](../triggers.md)) | Cron Jobs; Vercel Queues in beta ([Cron Jobs](https://vercel.com/docs/cron-jobs/usage-and-pricing), [Queues](https://vercel.com/docs/queues)) |
| Workflows | Durable workflows built in, with Python and TypeScript SDKs ([Durable workflows](../workflows.md)) | Vercel Workflows, on the open-source Workflow SDK ([Workflows](https://vercel.com/docs/workflows)) |
| Untrusted code | The Sandbox API: sandboxes under gVisor or Kata on your cluster ([Sandbox API](../sandbox-api.md)) | Vercel Sandbox: Firecracker microVMs ([Sandbox](https://vercel.com/docs/sandbox)) |
| MCP | A built-in MCP server at `/mcp` and hosting for your own MCP servers ([MCP](../mcp.md)) | The Vercel MCP server, and your own MCP server with `mcp-handler` ([Vercel MCP](https://vercel.com/docs/agent-resources/vercel-mcp), [deploy MCP servers](https://vercel.com/docs/mcp/deploy-mcp-servers-to-vercel)) |
| Single sign-on | Google, GitHub, Microsoft, GitLab, OpenID Connect and SAML 2.0 in the open-source code ([Modules](../modules.md)) | SAML SSO on Enterprise, and on Pro as an add-on ([SAML](https://vercel.com/docs/saml)) |
| Infrastructure as code | Terraform / OpenTofu provider `brf-tech/runr`, the `runr` CLI, a GitHub Action ([Terraform provider](../terraform.md)) | Terraform provider `vercel/vercel` ([registry](https://registry.terraform.io/providers/vercel/vercel/latest)) |

## When to choose which

**Vercel** fits when you build a web front end with a framework it supports and want git-based
deployments, previews and functions without running any infrastructure.

**RunR** fits when the back end must run on infrastructure you operate (on premises, a private
cloud, air-gapped regions), when it needs brokers such as Kafka, NATS or RabbitMQ, Postgres change
data or GPUs ([GPU workers](../ai.md)), or when it runs long-lived containers next to functions.
A front end on Vercel and its back end on RunR also work together: RunR projects answer HTTP on
their own URLs and custom domains.

## RunR documentation to read next

- [Production install](../install.md) and [single-host install](../../compose/README.md)
- [Git deploy](../git-deploy.md) and [Environments and promotion](../environments.md)
- [Deploy strategies](../deploy-strategies.md)
- [Triggers](../triggers.md)
- [All comparisons](README.md)

---

The statements about Vercel come from Vercel's documentation, read on 2026-11-03 and linked next to
each one. Vercel is a trademark of Vercel Inc. RunR is not affiliated with or endorsed by Vercel.
