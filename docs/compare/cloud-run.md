# RunR vs Google Cloud Run

<!-- description: RunR vs Google Cloud Run: open-source serverless on your own Kubernetes clusters next to Google's managed platform, every Cloud Run fact linked to its source. -->

RunR is an open-source (MIT) serverless platform: functions, multi-file projects and containers on
Knative, on Kubernetes clusters you operate. [Google Cloud Run](https://docs.cloud.google.com/run/docs/overview/what-is-cloud-run)
is a fully managed Google Cloud platform that runs code, functions or containers as services, jobs,
worker pools and (in Preview) instances.

Both run request-driven containers that scale to zero and shift traffic between revisions. The
main difference is where they run and who operates them: Cloud Run runs in Google Cloud regions
and Google operates it; RunR runs on your clusters and you operate it.

## Differences at a glance

| | RunR | Google Cloud Run |
| --- | --- | --- |
| Where it runs | Your Kubernetes clusters (1.30 or newer), each connected as a region; bare metal, a private cloud or managed Kubernetes ([Regions](../regions.md)) | Google Cloud regions; each Cloud Run resource resides in one region ([locations](https://docs.cloud.google.com/run/docs/locations)) |
| Who operates it | You: the control plane installs with Helm or Docker Compose ([Production install](../install.md), [Single-host install](../../compose/README.md)) | Google: a fully managed platform ([what is Cloud Run](https://docs.cloud.google.com/run/docs/overview/what-is-cloud-run)) |
| From source | Web editor, `git push` or the CLI; images are built in the cluster with Kaniko ([Git deploy](../git-deploy.md), [CLI](../cli.md)) | `gcloud run deploy --source` builds with Google Cloud's buildpacks and Cloud Build, or with your Dockerfile ([deploying from source](https://docs.cloud.google.com/run/docs/deploying-source-code)) |
| Scale to zero | On by default for every project, with minimum and maximum scale and a timed warmer ([Scaling](../scaling.md)) | Services scale to zero by default; minimum instances default to 0 ([autoscaling](https://docs.cloud.google.com/run/docs/about-instance-autoscaling), [minimum instances](https://docs.cloud.google.com/run/docs/configuring/min-instances)) |
| Rollouts | Blue-green with a test URL; canary in steps that rolls back by itself on error rate or p95 latency ([Deploy strategies](../deploy-strategies.md)) | Traffic split by percentage between revisions, gradual rollout and rollback ([rollouts and traffic migration](https://docs.cloud.google.com/run/docs/rollouts-rollbacks-traffic-migration)) |
| Isolation | runc, gVisor or Kata Containers per project type, set up per region from the console ([Kata Containers hypervisors](../kata-hypervisors.md)) | First generation based on gVisor; second generation a microVM ([execution environments](https://docs.cloud.google.com/run/docs/about-execution-environments)) |
| Events | HTTP, schedules, queues, webhooks, topics, Kafka, NATS, RabbitMQ, MQTT, Postgres CDC, S3 and IMAP, with retries and dead letters ([Triggers](../triggers.md)) | Eventarc events, Pub/Sub push subscriptions and Cloud Scheduler ([Eventarc](https://docs.cloud.google.com/run/docs/triggering/trigger-with-events), [Pub/Sub](https://docs.cloud.google.com/run/docs/triggering/pubsub-push), [Cloud Scheduler](https://docs.cloud.google.com/run/docs/triggering/using-scheduler)) |
| Several regions | Global projects: one host name, HA or proximity mode, failover between regions ([Global projects](../global-projects.md)) | A global external Application Load Balancer with serverless NEGs, or multi-region services with automated failover ([multiple regions](https://docs.cloud.google.com/run/docs/multiple-regions)) |
| GPUs | GPU functions and containers on the GPU nodes of your clusters; Hugging Face model services ([GPU workers](../ai.md), [Hugging Face models](../huggingface.md)) | NVIDIA L4 and RTX PRO 6000 Blackwell, one GPU per instance, in selected regions ([GPU](https://docs.cloud.google.com/run/docs/configuring/services/gpu)) |
| Workflows | Durable workflows built in, with Python and TypeScript SDKs ([Durable workflows](../workflows.md)) | Google Cloud Workflows, a separate orchestration service that calls Cloud Run over HTTP ([Workflows](https://docs.cloud.google.com/workflows/docs/overview)) |
| Trusted images | SBOMs, scans, cosign signatures, a policy gate and signed-image admission ([Supply chain](../supply-chain.md)) | Binary Authorization deploys only trusted container images ([Binary Authorization](https://docs.cloud.google.com/run/docs/securing/binary-authorization)) |
| Infrastructure as code | Terraform / OpenTofu provider `brf-tech/runr`, the `runr` CLI, a GitHub Action ([Terraform provider](../terraform.md)) | Terraform resource `google_cloud_run_v2_service` of the google provider ([registry](https://registry.terraform.io/providers/hashicorp/google/latest/docs/resources/cloud_run_v2_service)) |

Cloud Run's v1 Admin API Service resource is compatible with the Knative Serving API
([Cloud Run APIs](https://docs.cloud.google.com/run/docs/reference/about-apis)); RunR deploys its
projects as Knative Services on Knative Serving ([Architecture](../architecture.md)).

## When to choose which

**Google Cloud Run** fits when your workloads already live on Google Cloud, you want Google to run
the platform, and you want its regions, GPUs and Google Cloud event sources without operating
clusters.

**RunR** fits when code, data and logs must stay on infrastructure you operate (on premises, a
private cloud, air-gapped regions), when you already run Kubernetes and want one platform across
your clusters, or when many teams share it with organizations, roles and single sign-on
([Roles and permissions](../permissions.md), [Modules](../modules.md)).

## RunR documentation to read next

- [Production install](../install.md) and [single-host install](../../compose/README.md)
- [Regions](../regions.md): what a cluster needs and how to connect it
- [Triggers](../triggers.md) and [Scaling](../scaling.md)
- [Deploy strategies](../deploy-strategies.md) and [Global projects](../global-projects.md)
- [All comparisons](README.md)

---

The statements about Google Cloud Run come from Google's documentation, read on 2026-11-03 and
linked next to each one. Google Cloud and Cloud Run are trademarks of Google LLC. RunR is not
affiliated with or endorsed by Google.
