# RunR vs Knative

<!-- description: RunR vs Knative: what RunR adds on top of Knative Serving (console, builds, rollouts, triggers, isolation), with sources from knative.dev. -->

RunR is an open-source (MIT) serverless platform: functions, multi-file projects and containers on
Kubernetes clusters you operate. [Knative](https://knative.dev/docs/serving/) is a CNCF Graduated
project ([CNCF](https://www.cncf.io/projects/knative/)) under the Apache-2.0 license
([LICENSE](https://github.com/knative/serving/blob/main/LICENSE)) that adds serverless building
blocks to Kubernetes: Serving, Eventing and Functions.

RunR is built on Knative Serving: it installs Knative Serving on every cluster it connects and
deploys projects as Knative Services ([Regions](../regions.md), [Architecture](../architecture.md)).
The question is not one or the other, but whether you assemble a platform around Knative yourself
or use RunR's.

## Differences at a glance

| | RunR | Knative on its own |
| --- | --- | --- |
| What you get | A console, an API, a CLI and a Terraform provider for projects, builds, triggers, environments, logs, metrics and traces ([Architecture](../architecture.md)) | Kubernetes resources and the `kn` CLI for services and event sources ([kn](https://knative.dev/docs/client/)) |
| Install | Connect a cluster as a region; RunR installs Knative Serving through the Knative Operator, with Traefik as the ingress, a registry and the observability stack ([Regions](../regions.md)) | Install Serving on a Kubernetes cluster with a networking layer such as Kourier, Contour or Istio ([install Serving](https://knative.dev/docs/install/yaml-install/serving/install-serving-with-yaml/)) |
| From source | Web editor, `git push` or the CLI; images are built in the cluster with Kaniko ([Git deploy](../git-deploy.md)) | Serving runs container images built elsewhere ([Knative Build, deprecated for Tekton](https://github.com/knative/build)); the `func` CLI builds function images ([building functions](https://knative.dev/docs/functions/building-functions/)) |
| Scale to zero | Knative's, on by default for every project, plus a timed warmer ([Scaling](../scaling.md)) | On by default with the Knative Pod Autoscaler ([scale to zero](https://knative.dev/docs/serving/autoscaling/scale-to-zero/)) |
| Rollouts | Blue-green with a test URL; canary in steps that rolls back by itself on error rate or p95 latency ([Deploy strategies](../deploy-strategies.md)) | Traffic split over Revisions and a `rollout-duration` that shifts traffic to the latest Revision gradually ([traffic management](https://knative.dev/docs/serving/traffic-management/), [gradual rollout](https://knative.dev/docs/serving/rolling-out-latest-revision/)) |
| Events | RunR's event gateway: schedules, queues, webhooks, topics, Kafka, NATS, RabbitMQ, MQTT, Postgres CDC, S3 and IMAP ([Triggers](../triggers.md)) | Knative Eventing sources such as APIServerSource, Kafka, PingSource and RabbitMQ ([sources](https://knative.dev/docs/eventing/sources/)) |
| Isolation | runc, gVisor or Kata Containers per project type, installed and mapped per region from the console ([Kata Containers hypervisors](../kata-hypervisors.md)) | `runtimeClassName` behind a Serving feature flag, with the RuntimeClasses your cluster provides ([feature flags](https://knative.dev/docs/serving/configuration/feature-flags/)) |
| Beyond serving | Durable workflows, bindings, the Sandbox API, GPU and Hugging Face model services, MCP ([Durable workflows](../workflows.md), [Sandbox API](../sandbox-api.md), [MCP](../mcp.md)) | Knative Functions with templates for Node.js, Python, Go, Quarkus, Rust, Spring Boot and TypeScript ([Functions](https://knative.dev/docs/functions/)) |

## When to choose which

**Knative on its own** fits when a platform team wants the building blocks and composes them with
its own tooling (GitOps, Tekton pipelines, a service mesh), or needs Knative Eventing's brokers and
sources.

**RunR** fits when you want that platform ready: developers deploy from a console, `git push` or
the CLI, and the builds, rollouts, triggers and observability are already in place, with
organizations, tenants, roles, groups, scoped tokens, single sign-on and an audit log for the
teams that share it ([Roles and permissions](../permissions.md), [Tenancy](../tenancy.md)). RunR
keeps Knative Serving underneath, so its scale-to-zero and revision model stay the same.

## RunR documentation to read next

- [Regions](../regions.md): the Knative and Kubernetes versions RunR offers and what it installs
- [Architecture](../architecture.md)
- [Production install](../install.md)
- [Triggers](../triggers.md) and [Deploy strategies](../deploy-strategies.md)
- [All comparisons](README.md)

---

The statements about Knative come from knative.dev, the Knative repositories and cncf.io, read on
2026-11-03 and linked next to each one. Knative is a CNCF project. RunR is not affiliated with or
endorsed by the Knative project or the CNCF.
