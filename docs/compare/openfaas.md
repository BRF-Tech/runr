# RunR vs OpenFaaS

<!-- description: RunR vs OpenFaaS: one MIT edition next to OpenFaaS CE, Standard and Enterprise, compared on scale to zero, events, isolation and GPUs, with sources. -->

RunR is an open-source (MIT) serverless platform: functions, multi-file projects and containers on
Knative, on Kubernetes clusters you operate. [OpenFaaS](https://docs.openfaas.com/openfaas-pro/comparison/)
runs functions packaged as container images on Kubernetes, or on a single host with faasd, in a
Community Edition (CE) and the commercial Standard and Enterprise editions.

Both are self-hosted and deploy functions from templates or images. RunR has one edition under the
MIT license; OpenFaaS splits its features between CE and the commercial editions.

## Differences at a glance

| | RunR | OpenFaaS |
| --- | --- | --- |
| License and editions | MIT; every feature in one repository ([License](../../LICENSE)) | CE under an EULA that limits commercial use to one installation per company for up to 60 days, with up to 15 functions; Standard and Enterprise are commercial ([CE EULA](https://github.com/openfaas/faas/blob/master/EULA.md), [editions](https://docs.openfaas.com/openfaas-pro/comparison/)) |
| Where it runs | Your Kubernetes clusters (1.30 or newer); the control plane on Kubernetes with Helm or on one host with Docker Compose ([Regions](../regions.md), [Single-host install](../../compose/README.md)) | Kubernetes, or a single host without Kubernetes with faasd ([faasd](https://github.com/openfaas/faasd), [OpenFaaS Edge](https://docs.openfaas.com/deployment/edge/)) |
| Scale to zero | On by default for every project ([Scaling](../scaling.md)) | Standard and Enterprise ([scale to zero](https://docs.openfaas.com/openfaas-pro/scale-to-zero/)) |
| From source | Web editor, `git push` or the CLI; images are built in the cluster with Kaniko ([Git deploy](../git-deploy.md), [CLI](../cli.md)) | `faas-cli build` builds images from templates with local Docker; the Function Builder API is in Enterprise ([build](https://docs.openfaas.com/cli/build/), [Function Builder](https://docs.openfaas.com/openfaas-pro/builder/)) |
| Events | HTTP, schedules, queues, webhooks, topics, Kafka, NATS, RabbitMQ, MQTT, Postgres CDC, S3 and IMAP, with retries and dead letters ([Triggers](../triggers.md)) | Cron in CE; Kafka, RabbitMQ, Postgres, AWS SQS and SNS and Google Pub/Sub connectors in Standard and Enterprise ([triggers](https://docs.openfaas.com/reference/triggers/), [editions](https://docs.openfaas.com/openfaas-pro/comparison/)) |
| Asynchronous calls | Queue and topic triggers through the event gateway ([Triggers](../triggers.md)) | A JetStream queue-worker in Standard and Enterprise; CE keeps an in-memory queue ([async](https://docs.openfaas.com/reference/async/), [editions](https://docs.openfaas.com/openfaas-pro/comparison/)) |
| Isolation | runc, gVisor or Kata Containers per project type, installed per region from the console ([Kata Containers hypervisors](../kata-hypervisors.md)) | A runtimeClass such as gVisor through Profiles, in Enterprise ([Profiles](https://docs.openfaas.com/reference/profiles/)) |
| GPUs | GPU functions and containers; Hugging Face model services ([GPU workers](../ai.md), [Hugging Face models](../huggingface.md)) | GPUs through Profiles in Standard and Enterprise ([editions](https://docs.openfaas.com/openfaas-pro/comparison/)) |
| Console and teams | A web console; organizations, tenants, roles and single sign-on ([Roles and permissions](../permissions.md), [Modules](../modules.md)) | A dashboard in Standard and Enterprise (CE: the legacy UI); SSO with OIDC, IAM and several namespaces in Enterprise ([editions](https://docs.openfaas.com/openfaas-pro/comparison/)) |
| Configuration as code | `runr.yaml` and the `runr` CLI, the Terraform / OpenTofu provider `brf-tech/runr` ([CLI](../cli.md), [Terraform provider](../terraform.md)) | A stack file (`stack.yaml`) and `faas-cli` ([stack file](https://docs.openfaas.com/reference/yaml/)) |

## When to choose which

**OpenFaaS** fits when you want functions on a single host without Kubernetes (faasd), or when its
commercial editions and their support match what you need.

**RunR** fits when you want every feature — scale to zero, event sources, sandboxing, GPUs, single
sign-on — under the MIT license without an edition boundary, and when functions should sit next to
multi-file projects, containers, durable workflows and the Sandbox API on one platform
([Durable workflows](../workflows.md), [Sandbox API](../sandbox-api.md)).

## RunR documentation to read next

- [Production install](../install.md) and [single-host install](../../compose/README.md)
- [Triggers](../triggers.md) and [Scaling](../scaling.md)
- [runr CLI](../cli.md)
- [All comparisons](README.md)

---

The statements about OpenFaaS come from the OpenFaaS documentation and repositories, read on
2026-11-03 and linked next to each one. OpenFaaS is a trademark of OpenFaaS Ltd. RunR is not
affiliated with or endorsed by OpenFaaS Ltd.
