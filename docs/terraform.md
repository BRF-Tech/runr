# Terraform provider

`terraform-provider-runr` manages RunR with Terraform (or OpenTofu): projects with their whole
trigger list, network, deploy strategy and global placement; environments; environment
variables and secret store references; custom domains; deployments; bindings and private
connections; topics; runtime plugins; durable workflows; model services; hosted MCP servers; the
API product of the gateways; secret stores; cost limits; access control and the white-label
brand. It is built on terraform-plugin-framework (protocol 6) and uses the same API client as the
[`runr` CLI](cli.md).

This page is the guide: how the resources fit together, with examples. Every attribute, its
default and the import syntax of each resource are in the generated reference,
[`terraform-provider-runr/docs/`](../terraform-provider-runr/docs/index.md), linked from the
table below. [What the provider does not manage](#what-the-provider-does-not-manage) lists the
actions and reports Terraform leaves to the CLI and the API.

| Kind | Name | Manages |
|---|---|---|
| Resource | [`runr_project`](../terraform-provider-runr/docs/resources/project.md) | a project: type, runtime, region, handler, image, resources, scale, timed warmer, the primary trigger or the whole trigger list (with config and secrets), network, deploy strategy, global placement |
| Resource | [`runr_env_var`](../terraform-provider-runr/docs/resources/env_var.md) | one plain or secret environment variable (sensitive value; secrets write-only, optionally kept out of the state with `value_wo`), or a reference to a value in a secret store |
| Resource | [`runr_environment`](../terraform-provider-runr/docs/resources/environment.md) | an environment of a project (staging, …): branch, approval, region, overrides, timed warmer, deploy strategy; production's approval |
| Resource | [`runr_environment_secret`](../terraform-provider-runr/docs/resources/environment_secret.md) | a secret variable of one environment |
| Resource | [`runr_domain`](../terraform-provider-runr/docs/resources/domain.md) | a custom domain of a project or environment, its TLS mode and the DNS records it needs |
| Resource | [`runr_deployment`](../terraform-provider-runr/docs/resources/deployment.md) | the running deployment of a project or of one of its environments (a version or a caas image) |
| Resource | [`runr_binding`](../terraform-provider-runr/docs/resources/binding.md) | a binding: managed KV, object storage or Postgres in a region, or external credentials |
| Resource | [`runr_binding_attachment`](../terraform-provider-runr/docs/resources/binding_attachment.md) | a binding attached to a project or environment, with an optional env prefix and dynamic credentials |
| Resource | [`runr_private_connection`](../terraform-provider-runr/docs/resources/private_connection.md) | a private connection of a project or environment to a managed PostgreSQL |
| Resource | [`runr_topic`](../terraform-provider-runr/docs/resources/topic.md) | a fan-out topic of the organization |
| Resource | [`runr_runtime`](../terraform-provider-runr/docs/resources/runtime.md) | a runtime plugin registered from a `runtime.yaml` manifest |
| Resource | [`runr_workflow`](../terraform-provider-runr/docs/resources/workflow.md) | a durable workflow declared through the API: triggers, retries, timeout, concurrency, cancellation, paused or active |
| Resource | [`runr_workflow_trigger`](../terraform-provider-runr/docs/resources/workflow_trigger.md) | one trigger (event, cron or queue) of a durable workflow |
| Resource | [`runr_workflow_app`](../terraform-provider-runr/docs/resources/workflow_app.md) | the endpoint the workflow engine calls (a function served outside RunR) |
| Resource | [`runr_model_service`](../terraform-provider-runr/docs/resources/model_service.md) | a Hugging Face model (or a model of the library) served on CPU or GPU in a region |
| Resource | [`runr_mcp_server`](../terraform-provider-runr/docs/resources/mcp_server.md) | a project served as a hosted MCP server behind RunR's gateway |
| Resource | [`runr_gateway`](../terraform-provider-runr/docs/resources/gateway.md) | your API gateway in a region: rate limit, developer page, custom domain |
| Resource | [`runr_gateway_route`](../terraform-provider-runr/docs/resources/gateway_route.md) | a route of a gateway to a project or environment, with its authentication (public, API key, JWT) |
| Resource | [`runr_api_plan`](../terraform-provider-runr/docs/resources/api_plan.md) | rate limits and quotas of the API product |
| Resource | [`runr_api_consumer`](../terraform-provider-runr/docs/resources/api_consumer.md) | a consumer of the API product |
| Resource | [`runr_api_key`](../terraform-provider-runr/docs/resources/api_key.md) | an API key of a consumer |
| Resource | [`runr_secret_store`](../terraform-provider-runr/docs/resources/secret_store.md) | a secret store of the organization (Vault / OpenBao, AWS, GCP, Azure) |
| Resource | [`runr_cost_limit`](../terraform-provider-runr/docs/resources/cost_limit.md) | the monthly cost limit of a pay-as-you-go project |
| Resource | [`runr_role`](../terraform-provider-runr/docs/resources/role.md) | a custom role and its permissions |
| Resource | [`runr_group`](../terraform-provider-runr/docs/resources/group.md) | a group (team): its organization-wide role and members |
| Resource | [`runr_project_access`](../terraform-provider-runr/docs/resources/project_access.md) | a role for a user or group on one project |
| Resource | [`runr_branding`](../terraform-provider-runr/docs/resources/branding.md) | the white-label brand of the installation or of an organization |
| Data source | [`runr_region`](../terraform-provider-runr/docs/data-sources/region.md) | a region by name or id (with neither: the only active region) |
| Data source | [`runr_project`](../terraform-provider-runr/docs/data-sources/project.md) | an existing project by name (or slug) or id, with its triggers, network, deploy strategy and placement |
| Data source | [`runr_secret_store_trust`](../terraform-provider-runr/docs/data-sources/secret_store_trust.md) | what a secret store has to trust for a region (issuer, keys, service account) |
| Data source | [`runr_cost_limit`](../terraform-provider-runr/docs/data-sources/cost_limit.md) | a project's cost limit with this month so far and the forecast |
| Data source | [`runr_runtime_patches`](../terraform-provider-runr/docs/data-sources/runtime_patches.md) | a project's runtime patch state and the patches it can move to |
| Data source | [`runr_rightsizing`](../terraform-provider-runr/docs/data-sources/rightsizing.md) | a project's rightsizing recommendation |

## Install

```hcl
terraform {
  required_providers {
    runr = {
      source  = "runr.sh/brf-tech/runr"
      version = "~> 0.6"
    }
  }
}
```

The provider's address is `runr.sh/brf-tech/runr` (provider name `runr`): runr.sh serves
Terraform's provider registry protocol, so `terraform init` (and OpenTofu's `tofu init`) finds
the releases there and downloads the archive for your platform from
`https://runr.sh/dl/terraform/<tag>/`. Before it installs the archive, `terraform init` checks
its SHA-256 against the release's `SHA256SUMS` and the signature of that file against the RunR
release key (key ID `2588846B241BC095`, fingerprint
`2CA5 13A7 57AE 0C94 BD8A 7EE2 2588 846B 241B C095`, public key at
<https://runr.sh/dl/runr-release-signing.pub.asc>). The lock file `.terraform.lock.hcl` records
the checksums.

A configuration that used the address `brf-tech/runr` changes `source` as above and moves its
state once:
`terraform state replace-provider registry.terraform.io/brf-tech/runr runr.sh/brf-tech/runr`.

To use a build from this repository, build and install it into the local plugin directory
and run `terraform init` as usual:

```sh
make -C terraform-provider-runr install VERSION=0.6.0   # ~/.terraform.d/plugins/runr.sh/brf-tech/runr/0.6.0/<os>_<arch>/
```

During provider development a `dev_overrides` block in `~/.terraformrc` points
`runr.sh/brf-tech/runr` at the directory with the binary (`make -C terraform-provider-runr build`).

### Upgrading from 0.5

Change `version` to `"~> 0.6"` and run `terraform init -upgrade`. A configuration and state of
provider 0.5.10 plan no change: every attribute that is new in 0.6 is optional, and left out it
keeps what RunR has (`triggers`, `network`, `deploy_strategy`, `global`, `scale_schedule`,
`trigger.timezone`, `environment` of attachments and domains, `credentials`, `reference`). Add them
to the configuration when Terraform should own them.

Upgrade the provider before or with the API: provider 0.5 against a RunR 0.6.0 API fails to create
a `runr_domain` in a region that issues certificates unless it sets `tls_mode = "cert-manager"`
(the API starts the domain in `cert-manager`, provider 0.5 expects `none`: "Provider produced
inconsistent result after apply"). Domains that exist keep their mode either way.

## Provider configuration

```hcl
provider "runr" {
  api_url = "https://runr.example.com"
  token   = var.runr_token
}
```

`api_url` and `token` (sensitive) default to `RUNR_API_URL` and `RUNR_TOKEN`, else to a
`runr` CLI context (`context` names one; default: the current context), so on a machine where
`runr login` was run an empty provider block works as is. `poll_interval` (a Go duration,
default `2s`) sets how often builds, deployments and domain checks are polled. The
[provider reference](../terraform-provider-runr/docs/index.md) lists the arguments.

Create the token with exactly what the provider uses:

```sh
runr tokens create terraform --preset terraform
```

The `terraform` preset holds `projects.view`, `regions.view`, `projects.create`,
`projects.update`, `projects.delete`, `env.edit`, `domains.manage` and `projects.deploy`: what
the project, variable, domain and deployment resources use. The other resources need more,
and a token never exceeds its owner's permissions, so add what a configuration uses with
`--ability`:

| Resource | Permissions |
|---|---|
| `runr_binding` | `bindings.manage` |
| `runr_binding_attachment` | `env.edit` on the project, `bindings.manage` |
| `runr_runtime` | `runtimes.manage` (platform runtimes; single-tenant edition, in multi-tenant installs the platform administrators) or `runtimes.use_custom` (the organization's custom runtimes, while the platform allows custom runtimes) |
| `runr_workflow_trigger` | `workflows.manage` on the project |
| `runr_model_service` | `models.deploy` |
| `runr_role` | `roles.manage` |
| `runr_group` | `groups.manage` (plus `roles.manage` when it sets `role`) |
| `runr_project_access` | `projects.manage_access` on the project, `members.view` for a `user` |
| `runr_branding` | `branding.manage` (the `install` scope of a multi-tenant install: platform administrators) |
| `runr_mcp_server` | `mcp.servers.manage` on the project |
| `runr_project` with `network` | `network.manage` on the project (`triggers`, `deploy_strategy` and `global` need `projects.update` of the preset) |
| `runr_env_var` with `reference` | `env.edit` of the preset; `secret_stores.view` to name the store by name |
| `runr_environment` | `environments.manage` on the project (plus `env.edit` when it sets `overrides.env_vars`) |
| `runr_environment_secret` | `env.edit` on the project |
| `runr_private_connection` | `connections.manage` on the project |
| `runr_topic` | `topics.manage` |
| `runr_workflow`, `runr_workflow_app` | `workflows.manage` on the project |
| `runr_gateway`, `runr_gateway_route`, gateway plans, consumers and keys | the gateway's owner (your own gateways need no permission; a route also needs the project) |
| `runr_api_plan`, `runr_api_consumer` of the organization (no `gateway_id`) | `api_products.manage` |
| `runr_secret_store` | `secret_stores.manage` (`data.runr_secret_store_trust`: `secret_stores.view`) |
| `runr_cost_limit` | the project's owner, `projects.billing` on the project or `billing.manage` (the data source: also `billing.view`) |
| `data.runr_rightsizing` | `metrics.view` on the project |

```sh
runr tokens create terraform --preset terraform --ability bindings.manage --ability workflows.manage
```

A refused call fails with the API's message, which names the permission the token lacks.

## A function with variables, a domain and a deployment

```hcl
data "runr_region" "eu" {
  name = "eu-1"
}

resource "runr_project" "api" {
  name      = "orders-api"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id

  resources = { memory_mb = 256, timeout_seconds = 30 }
  scale     = { min = 0, max = 10 }
  trigger   = { type = "http" }
}

resource "runr_env_var" "log_level" {
  project_id = runr_project.api.id
  name       = "LOG_LEVEL"
  value      = "info"
}

resource "runr_env_var" "database_url" {
  project_id = runr_project.api.id
  name       = "DATABASE_URL"
  value      = var.database_url
  secret     = true
}

resource "runr_domain" "api" {
  project_id = runr_project.api.id
  domain     = "api.example.com"
  tls_mode   = "cert-manager"
}

resource "runr_deployment" "api" {
  project_id = runr_project.api.id

  # deploy again when a variable changes
  triggers = {
    log_level    = runr_env_var.log_level.value
    database_url = sha256(runr_env_var.database_url.value)
  }
}

output "url" {
  value = runr_deployment.api.url
}
```

```hcl
# Terraform 1.11 and later: the value never reaches the state; bump value_wo_version to write
# a new one (for example after a rotation).
resource "runr_env_var" "signing_key" {
  project_id       = runr_project.api.id
  name             = "SIGNING_KEY"
  value_wo         = var.signing_key # an ephemeral variable
  value_wo_version = "2026-10"
  secret           = true
}
```

### How the pieces fit

- **Code.** `runr_project` holds the configuration; the code of `faas` and `xaas` projects is
  uploaded as versions with `runr deploy` (CI, see [the GitHub Action](github-action.md)).
  `runr_deployment` deploys the latest version when it is created, or `version_id` when set.
- **Config changes and redeploys.** Every config change (`runr_project`, `runr_env_var`)
  saves a new project version; a running deployment picks it up when it is deployed again.
  Put the values that should cause a redeploy into `runr_deployment.triggers`: any change
  deploys again in place (without a configured `version_id` it deploys the latest version).
- **Containers.** For `caas` projects set `image` on `runr_deployment`; it is written to the
  project config (a new version) and deployed. Let `runr_project` ignore the image so the two
  do not compete:

  ```hcl
  resource "runr_project" "web" {
    name      = "web"
    type      = "caas"
    region_id = data.runr_region.eu.id
    image     = "ghcr.io/acme/web:1.0.0"   # used when the project is created
    ports     = [8080]

    lifecycle {
      ignore_changes = [image]
    }
  }

  resource "runr_deployment" "web" {
    project_id = runr_project.web.id
    image      = "ghcr.io/acme/web:${var.web_version}"
  }
  ```

- **Fields left out** of `runr_project` keep the values the server gives them (defaults, or
  what the console or the CLI set), so Terraform only manages what the configuration names.
  `name`, `type`, `runtime` and a configured `runtime_version` force a new project when they
  change; `region_id` moves the project.
- **Timed warmer.** `scale_schedule` keeps containers warm on a schedule: each row
  `{ cron = "0 9 * * 1-5", min_scale = 3 }` keeps at least `min_scale` containers warm from the
  moment its five-field expression fires — in the organization's time zone
  ([Time zone](scaling.md#timed-warmer), default Europe/Istanbul) — until the next row fires.
  While rows are set they replace `scale.min`; `scale.max` still applies (a row above it is refused at plan time). At most 10 rows; `[]` turns
  the warmer off, leaving the attribute out keeps the project's schedule. RunR applies a changed
  schedule to the running workload within a minute: it needs no `runr_deployment` redeploy.
  `data.runr_project` reads `scale_schedule` and `warm_scale_applied` (the containers kept warm
  now).
- **Trigger.** `trigger` is the project's primary trigger, the first enabled one of its
  [trigger list](triggers.md#the-trigger-list): setting it changes the primary trigger's type
  (`http`, `schedule`, `queue` or `workflow`) and settings and leaves the other triggers as they
  are. `triggers` sets the whole list instead ([Triggers](#triggers)); set one of the two.
  `trigger.type` reads back the primary trigger's type, which can be one only `triggers` sets
  (`webhook`, `kafka`, …).
- **Schedule time zone.** `trigger = { type = "schedule", schedule = "0 2 * * *", timezone =
  "Europe/Berlin" }` runs the schedule in that IANA zone
  ([triggers.md](triggers.md#schedules-and-time-zones)); `timezone = ""` runs it in the
  organization's time zone, which it then follows. Leaving `timezone` out keeps the schedule's
  zone: a new schedule runs in the organization's, one created before RunR 0.6.0 in UTC (the
  upgrade pinned those). `trigger.effective_timezone` (computed) is the zone it runs in; a zone on
  a trigger that is no schedule is refused at plan time. `data.runr_project` reads
  `schedule_timezone` and `schedule_effective_timezone`. The project API's single-trigger field
  behind it is `config.schedule_timezone`.
- **Environment variables** are managed one per `runr_env_var`; parallel changes to one
  project are serialized by the provider. Values are sensitive. Plain variables are part of
  the project config (each change saves a version). With `secret = true` the variable is a
  write-only secret ([secret variables](security.md#secret-environment-variables)): the
  provider writes it through the project's secrets API, RunR lists only its name, and
  Terraform keeps the value it set. A secret removed outside Terraform is created again; one
  turned into a plain variable outside Terraform is replaced by the secret. With `value_wo`
  instead of `value` (a write-only attribute, Terraform 1.11 and later, `secret = true` only)
  the value is not stored in the state or the plan at all; Terraform cannot compare it, so
  change `value_wo_version` to write a new value. With `reference` the variable refers to a
  value in a secret store ([Secret stores and references](#secret-stores-and-references)).
  Exactly one of `value`, `value_wo` and `reference` is set. Like every config change, a new
  value reaches the deployment with the next deploy (`runr_deployment.triggers`); a secret value
  also reaches the containers that start before it, as it goes straight into the project's
  region. On an API without secret variables a secret is stored as a regular variable and the
  provider prints a warning. Reading variables needs `env.view`
  (included in `env.edit`); for a token without it the API hides them, and the provider keeps
  the configured values with a warning instead of showing them as changes.
- **Domains.** Create the records of `verification_*` and `dns_targets` at your DNS provider
  (for example with the DNS provider's Terraform resources); `verify = true` makes the create
  wait until RunR finds them (`verify_timeout`, default `10m`), otherwise run
  `runr domains verify`. `tls_mode` left out is RunR's choice when the domain is created —
  `cert-manager` where the region issues certificates ([cert-manager](regions.md#cert-manager)),
  `none` (the region's default certificate, which does not name the domain) elsewhere — and the
  mode the domain has afterwards is kept. A domain that provider 0.5 created with `none` keeps
  `none` (no change is planned); set `tls_mode = "cert-manager"` to move it.
- **Deployments.** `wait` (default `true`) waits until the deployment serves, at most
  `timeout` (default `10m`). Destroying `runr_deployment` tears the deployment down. A
  deployment that was replaced outside Terraform (for example by `runr deploy`) or failed drops
  out of the state, so the next plan shows it as a deployment to create and the apply deploys
  again.
- **Deploy strategies.** A project with a blue-green or canary
  [deploy strategy](deploy-strategies.md) (`runr_project.deploy_strategy`,
  [below](#deploy-strategy)) rolls a `runr_deployment` out by it while another deployment serves:
  `wait` ends once the new revision is ready and its rollout under way, and the new deployment is
  `running` until the rollout promotes it (`active`). Promotion, abort and rollback stay with
  `runr rollout`, the console or the API; a deployment whose rollout was aborted or rolled back
  drops out of the state like a failed one. The provider deploys with the project's strategy and
  has no per-deploy override.

## Triggers

```hcl
resource "runr_project" "orders" {
  name      = "orders"
  type      = "faas"
  runtime   = "python"
  region_id = data.runr_region.eu.id

  triggers = [
    { type = "http" },
    {
      name   = "nightly"
      type   = "schedule"
      config = jsonencode({ cron = "0 2 * * *", timezone = "Europe/Berlin" })
    },
    {
      name    = "orders"
      type    = "kafka"
      config  = jsonencode({ brokers = ["kafka-1:9092"], topic = "orders", sasl = { mechanism = "plain", username = "orders" } })
      secrets = { sasl_password = var.kafka_password }
    },
    {
      name    = "github"
      type    = "webhook"
      config  = jsonencode({ verify = "github" })
      secrets = { signing_secret = var.github_webhook_secret }
    },
  ]
}

output "github_webhook_url" {
  value = runr_project.orders.triggers[3].url
}
```

- `triggers` is the project's whole [trigger list](triggers.md#the-trigger-list) in order: HTTP
  requests, schedules, the queue, a durable workflow, webhook inboxes, topics, platform events and
  the event sources `kafka`, `nats`, `rabbitmq`, `mqtt`, `cdc` (Postgres logical replication),
  `email` (IMAP) and `s3`. The first enabled trigger is the primary one, which `trigger` reads back.
  Set, the list replaces the project's list (`PUT /api/projects/{project}/triggers`): a trigger
  whose `name` and `type` match one the project has keeps its secrets, inbox URL and stored events,
  the others are created or removed. Left out, the list stays as it is. Name the triggers whose
  secrets or inbox URL must survive a reordering; an unnamed one is named after its type.
- `config` is the type's settings as JSON ([triggers.md](triggers.md) lists them per type). RunR
  stores them normalized — defaults filled in, empty values dropped — and the state keeps the
  configured JSON while RunR's config holds each of its values; a value RunR does not keep (a key the
  type does not know) fails the apply with what RunR stored. Left out, it is RunR's config of the
  trigger.
- `secrets` are the type's write-only secrets (`signing_secret`, `sasl_password`, `password`,
  `token`, `nkey_seed`, `creds`, `url`, `dsn`, `access_key`, `secret_key`): RunR lists only their
  names (`secret_names`), Terraform keeps the values it set and sets one again that was removed
  outside Terraform. A configured map is the trigger's whole set; left out, the trigger's secrets
  stay as they are.
- `id`, `primary`, `runs_on` (`service`, `gateway`, `cronjob`, `disabled`), `url` (a webhook's
  inbox) and `effective_timezone` (a schedule's zone) are computed. Like every trigger change, a
  new list reaches the cluster with the next deploy (`runr_deployment.triggers`).
- Topics are resources of their own: `runr_topic` (name, region, description) is the
  organization's fan-out topic a `topic` trigger subscribes to; a topic with subscribers cannot
  be destroyed. Stored webhook events and their replay are actions (`runr triggers events`,
  `runr triggers replay`).

## Network

```hcl
resource "runr_project" "ledger" {
  # …
  network = {
    access_mode = "internal"                                  # public | allowlist | internal
    callers     = [runr_project.billing.network.internal_name] # "billing.acme"
  }
}
```

`network` is who reaches the project ([internal network](internal-network.md)): `access_mode`
(`public`, the default; `allowlist` with `allowed_cidrs`, IP addresses or CIDR ranges; `internal`,
no public URL) and `callers`, the projects allowed to call it on the region's internal network by
internal name `<project>.<organization>` — another project's `network.internal_name`, or
`data.runr_project.x.internal_name` from another configuration. A configured `callers` is the whole
allow list (`[]` removes every caller); every value left out stays as it is. Changing the network
needs `network.manage`.

## Deploy strategy

```hcl
resource "runr_project" "shop" {
  # …
  deploy_strategy = {
    strategy = "canary"                     # direct | blue_green | canary
    canary = {
      steps                = [10, 50]       # % of the traffic, 1-10 strictly increasing steps
      interval_minutes     = 5
      error_rate_threshold = 2              # roll back above 2 % errors
      p95_threshold_ms     = 800
    }
  }
}
```

`deploy_strategy` sets how a deploy rolls out ([deploy strategies](deploy-strategies.md)):
`strategy` with the options of both strategies, `blue_green` (`auto_promote`, `health_path`,
`health_checks`, `keep_previous_minutes`) and `canary` (`steps`, `interval_minutes`,
`error_rate_threshold`, `p95_threshold_ms`). Values left out stay as they are; a configured
`blue_green` or `canary` block sets its nullable options, so one it leaves out is cleared
(`health_path`: the readiness alone; a threshold: not checked). A strategy other than `direct`
needs an `http` or `workflow` trigger (refused at plan time where the trigger is known). The
environments of the project use these settings unless they set their own
(`runr_environment.deploy_strategy`). Promotion, abort and rollback of a rollout are actions.

## Global projects

```hcl
data "runr_region" "us" {
  name = "us-1"
}

resource "runr_project" "shop" {
  name      = "shop"
  type      = "caas"
  region_id = data.runr_region.eu.id     # the primary region
  image     = "ghcr.io/acme/shop:2.0.0"

  global = {
    regions  = [data.runr_region.eu.id, data.runr_region.us.id]  # HA order, the primary included
    mode     = "ha"                                               # ha | proximity
    failover = "automatic"                                        # automatic | manual
  }
}
```

`global` makes the project a [global project](global-projects.md) in every region of `regions`
(2-8, each with global DNS) behind one host name (`global.hostname`). A project is created global
or regional: adding `global` to a regional project replaces it. `regions`, `mode` and `failover`
change in place (`PUT /api/projects/{project}/global`: a new region gets the active version, a
region left out has its copy removed); a new `region_id` is a switchover — the region's copy must
serve first. Left out, a global project stays as it is. `global.bindings` (computed) says how each
binding is reached from every region (`direct`, `link`, `replicas`). A failover and a sync are
actions (`runr projects global failover|sync`).

## Bindings

```hcl
resource "runr_binding" "orders_db" {
  name              = "orders-db"
  type              = "postgres"          # kv | object | postgres | external
  region_id         = data.runr_region.eu.id
  storage_gb        = 2
  connection_limit  = 10
  statement_timeout = "15s"
}

resource "runr_binding" "payments" {
  name = "payments"
  type = "external"
  values = {
    PAYMENTS_URL   = "https://pay.example.com"
    PAYMENTS_TOKEN = var.payments_token
  }
}

resource "runr_binding_attachment" "orders_db" {
  project_id = runr_project.api.id
  binding_id = runr_binding.orders_db.id
  env_prefix = "ORDERS_"                  # ORDERS_DATABASE_URL, ORDERS_PGHOST, …
}

resource "runr_binding_attachment" "payments" {
  project_id = runr_project.api.id
  binding_id = runr_binding.payments.id
}
```

- `runr_binding` creates the binding and waits until it is provisioned (`wait`, default
  `true`; `timeout`, default `10m`; a dedicated Postgres cluster takes a few minutes). A binding
  that ends in `error` fails the apply and stays in the state as tainted.
- The settings are attributes of their own: `memory_mb` (kv), `quota_gb` (object),
  `storage_gb`, `connection_limit`, `statement_timeout` (postgres), `instances` and `backups`
  (postgres with `plan = "dedicated"`). Settings left out take the API's defaults and are read
  back; a setting that does not apply to the type is refused at plan time. Changing a setting
  updates the binding in place (the storage of a dedicated cluster can only grow); changing
  `name`, `type`, `plan` or `region_id` replaces it.
- Credentials are never stored in the state: the API returns them only to
  `runr bindings credentials` (permission `bindings.view_credentials`, audited). The `values` of
  an external binding are sensitive and write-only; Terraform keeps what it set, and a change
  rolls the attached projects onto the new values.
- Destroying a binding deletes it with retention: it is detached at once and its data kept for
  the retention period (`runr bindings restore` brings it back). The name stays taken until the
  data is purged, so a replacement needs a new `name`.
- `runr_binding_attachment` attaches a binding to a project; the running service rolls onto the
  variables, no redeploy is needed. `env_prefix` changes in place; `env_names` lists the
  variables the project gets. A `kv`, `object` or `postgres` binding attaches to projects of
  its region only, and two attachments of a project cannot produce the same variable.
- `credentials = "dynamic"` gives a Postgres attachment
  [dynamic credentials](bindings.md#dynamic-credentials) (a role per attachment that the region's
  OpenBao rotates; the region needs OpenBao and External Secrets Operator); it changes in place,
  left out it stays as it is (`static` for a new attachment). `environment` attaches the binding to
  one of the project's [environments](#environments).

## Environments

```hcl
resource "runr_environment" "staging" {
  project_id = runr_project.api.id
  name       = "staging"                 # workload <slug>-staging; a new name replaces it
  git_branch = "develop"                 # pushes to develop deploy into staging

  overrides = {                          # the complete set; left out: RunR's stays
    env_vars        = { LOG_LEVEL = "debug" }
    remove_env_vars = ["ANALYTICS_KEY"]  # the project's variable does not reach staging
    min_scale       = 0
    max_scale       = 2
  }

  scale_schedule = []                    # no warm containers in staging

  deploy_strategy = {                    # staging's own, over the project's
    strategy = "canary"
    canary   = { steps = [50, 100], interval_minutes = 2 }
  }
}

resource "runr_environment_secret" "staging_api_key" {
  project_id  = runr_project.api.id
  environment = runr_environment.staging.name
  name        = "API_KEY"
  value       = var.staging_api_key      # write-only, like a secret runr_env_var
}

resource "runr_binding_attachment" "staging_db" {
  project_id  = runr_project.api.id
  binding_id  = runr_binding.staging_db.id
  environment = runr_environment.staging.name
}

resource "runr_domain" "staging" {
  project_id  = runr_project.api.id
  environment = runr_environment.staging.name
  domain      = "staging.example.com"
}

# production is the project's own workload: adopted for its approval and place only
resource "runr_environment" "production" {
  project_id        = runr_project.api.id
  name              = "production"
  requires_approval = true
}
```

- `runr_environment` is one [environment](environments.md) of a project: `region_id` (default the
  project's; it changes only while nothing runs in the environment and it has no gateway route or
  private connection — RunR's refusal shows at apply), `git_branch` (`""`: none), `auto_deploy`,
  `requires_approval`, `inherit_secrets` and `position` change in place; every attribute left out
  keeps what RunR has. `url`, `default_url`, `workload_name` and `own_region` are computed.
- `overrides` are the environment's configuration over the project's, saved as a whole: a
  configured block is the complete set (`{}` overrides nothing), left out RunR's set stays.
  `env_vars` are sensitive; `remove_env_vars` names project variables the environment does not get
  (a Terraform map cannot hold the API's `null`). A token without `env.view` keeps the configured
  `env_vars` with a warning.
- `scale_schedule` is the environment's own [timed warmer](scaling.md#timed-warmer) (`[]`: no warm
  containers there); `deploy_strategy` takes the same values as the project's and makes the
  configured ones the environment's own, so they survive changes of the project's strategy. Set
  `scale_schedule_inherited = true` or `deploy_strategy_inherited = true` to follow the project
  again.
- `name = "production"` adopts the project's own workload for `requires_approval` and `position`
  only (other attributes are refused at plan time); destroying it restores production's defaults
  (approval required, position 1000). Destroying another environment deletes its workload,
  deployments and secrets, detaches its bindings and removes its domains, gateway route and private
  connections.
- An environment's own secret variables are `runr_environment_secret` (`value`, or `value_wo` with
  `value_wo_version`); production's are `runr_env_var` with `secret = true`. A value changed outside
  Terraform (the API reports a new version) is written again.
- Bindings, custom domains, gateway routes and private connections of an environment are the
  usual resources with `environment` set; null or `"production"` is the project's own workload, and
  moving one to another environment replaces it.
- `runr_deployment` with `environment` is the running deployment of an environment (a version, or
  the latest one; `triggers` deploy again). Promotions and approvals are actions: a pipeline runs
  `runr promote` and `runr approvals` ([CLI](cli.md)).

### Private database connections

```hcl
resource "runr_private_connection" "orders" {
  project_id  = runr_project.api.id
  environment = runr_environment.staging.name  # left out: the project's own workload
  name        = "orders"
  env_prefix  = "ORDERS_"                       # ORDERS_DATABASE_URL, ORDERS_PGHOST, …
  routing_key = "pg-orders-staging"
  link_token  = file("${path.module}/orders-access-token.yaml")
  database    = "orders"
  username    = "orders_app"
  password    = var.orders_password
}
```

`runr_private_connection` connects a project, or one of its environments, to a managed PostgreSQL
through Skupper ([private connections](environments.md#private-database-connections)): the link
token (a Skupper v2 AccessToken or Link of the database's site) and the credentials go into a
Secret in the region and never come back from the API. The API cannot change a connection: every
argument replaces it. `link_token_wo` / `password_wo` with `credentials_wo_version` keep the token
and the password out of the state (Terraform 1.11 and later). `host`, `env_names`, `link_kind` and
`status` (`ready`, `pending`, `error`) are computed; global projects cannot use one.

## API product

```hcl
resource "runr_gateway" "eu" {
  region_id      = data.runr_region.eu.id   # your gateway in eu-1 (taken over when it exists)
  rate_limit     = { average = 100, burst = 200 }
  developer_page = true
  custom_domain  = "api.example.com"
}

resource "runr_gateway_route" "orders" {
  gateway_id   = runr_gateway.eu.id
  project_id   = runr_project.orders.id
  path         = "/orders"
  auth_mode    = "key"                      # public | key | jwt
  gateway_only = true                       # the project's own URL stops serving
}

resource "runr_api_plan" "pro" {
  gateway_id      = runr_gateway.eu.id      # left out: a plan of the organization
  name            = "Pro"
  rate_per_second = 10
  quota_per_month = 100000
}

resource "runr_api_consumer" "acme" {
  gateway_id  = runr_gateway.eu.id
  name        = "Acme Ltd"
  external_id = "CUST-1042"                 # the invoicing system's customer number
  plan_id     = runr_api_plan.pro.id
}

resource "runr_api_key" "acme" {
  consumer_id = runr_api_consumer.acme.id
  name        = "production"
  scopes      = [{ route_id = runr_gateway_route.orders.id, methods = ["GET", "POST"] }]
}
```

- `runr_gateway` is your [gateway](api-product.md) in a region (one per user and region): the
  resource takes the existing one or creates it, and sets its IP `rate_limit`, the public
  `developer_page` (`description`, `developer_page_metrics`) and its own `custom_domain`. Publish
  `custom_domain_verification` (TXT) and one of `custom_domain_dns_targets` at your DNS provider,
  then `verify = true` waits for them; a pending domain is never requested again, so its TXT token
  stays. A gateway cannot be deleted: destroying the resource resets what it set and forgets it.
- `runr_gateway_route` leads a path of the gateway to a project or one of its environments, with
  `auth_mode` `public` (default), `key` or `jwt` (`jwt` block: `jwks_url`, `issuer`, `audience`,
  `subject_claim`, `require_consumer`) and `gateway_only`. A new route is created with its
  authentication in one call: a key or JWT route is never public, not even for a moment. `path` and
  `methods` change in place, so the route id that key scopes name stays (a path another route of
  the gateway has fails the apply with the API's 422); a new gateway, project or environment
  replaces it.
- `runr_api_plan` (rate limits and quotas), `runr_api_consumer` (`status` `active` or
  `suspended`, `metadata`) and `runr_api_key` (`scopes`, `plan_id`, `expires_at`) belong to a
  gateway, or with `gateway_id` left out to the organization (`api_products.manage`). Left out,
  a plan's limits, a consumer's contact fields and metadata and a key's scopes, plan and expiry are
  removed; `burst` follows the rate per second when that changes.
- `runr_api_key.key` is in the create answer only, so the state holds it (sensitive); an imported
  key has none. Destroying the resource revokes the key; a key revoked outside Terraform is created
  again. Rotation is an action (`runr gateway keys rotate --overlap`): the resource follows the new
  key, whose value then is not in the state.
- Usage and usage events are reports: `runr gateway usage`, `GET /api/api-usage` and the signed
  webhook events ([usage](api-product.md#usage)).

## Runtime plugins

```hcl
resource "runr_runtime" "q" {
  manifest = file("${path.module}/runtimes/q/runtime.yaml")
  files = {
    for f in fileset("${path.module}/runtimes/q/interpreter", "*") :
    f => file("${path.module}/runtimes/q/interpreter/${f}")
  }
  regions = [data.runr_region.eu.id] # unset: every region
}

resource "runr_project" "hello" {
  name      = "hello-q"
  type      = "xaas"
  runtime   = runr_runtime.q.id
  region_id = data.runr_region.eu.id
}
```

`runr_runtime` registers the manifest ([runtimes](runtimes.md)) with `POST /api/runtimes` — a
platform runtime when the token holds `runtimes.manage`, a custom runtime of the organization
with `runtimes.use_custom` — and RunR builds the runtime image of `dockerfile` and `git`
versions in each region. A changed manifest or file set is an in-place update
(`PUT /api/runtimes/{runtime}`); a changed `id` in the manifest replaces the runtime. An invalid
manifest fails the apply with every problem and its JSON path. Deleting a runtime that projects
still use fails (the API answers 409); delete or move the projects first. `enabled = false`
(default `true`) takes the runtime out of the catalog for new projects and refuses deploys of
its projects.

## Workflows

A [durable workflow](workflows.md) whose code runs in the project is declared through the API
(`PUT /api/projects/{project}/workflows/{name}`) with `runr_workflow`:

```hcl
resource "runr_workflow" "process_order" {
  project_id  = runr_project.orders.id
  name        = "process-order"
  triggers = [
    { event = "order.created", condition = "event.data.total > 0" },
    { queue = "orders" },                       # the event queue/orders of the queue bridge
    { cron = "0 3 * * *", timezone = "Europe/Istanbul" },
  ]
  retries     = 5
  timeout     = "1d"
  concurrency = [{ limit = 1, key = "event.data.customer_id" }]
  idempotency = "event.data.order_id"
  cancel_on   = [{ event = "order.cancelled", expression = "event.data.order_id == async.data.order_id" }]
  active      = true                            # false pauses it (survives syncs)
}

resource "runr_workflow_app" "staging" {        # the engine calls a function served outside RunR
  project_id   = runr_project.orders.id
  environment  = "staging"
  endpoint_url = "https://workflows-staging.example.com/runr"
}
```

- `runr_workflow` declares one definition of the project (or of an `environment`) on its own
  (`PUT …/workflows/{name}`): the project's other definitions are not sent and stay as RunR has
  them, their source too — what the function's SDK reported stays `sdk`. Destroying it archives the
  workflow (`DELETE …/workflows/{name}`; its runs stay). `project_id`, `environment` and `name`
  replace it, the rest changes in place. Left out, `triggers`, `retries` and `active` keep RunR's
  values. A workflow whose definition the function's SDK reports belongs to the code: the provider
  refuses it. (Against an API before 0.6.0, which has only the whole set, the provider declares the
  set again with the definition added, changed or left out.)
- `runr_workflow_trigger` adds one trigger to a definition (an event with an optional `condition`,
  a `cron` with its `timezone`, default UTC, or a queue stream) and declares that definition alone;
  every attribute replaces it. Use it for workflows declared by `runr.workflows.json` or by a
  `runr_workflow` that leaves `triggers` out — never both for one workflow. Event names under `runr/` are reserved for the platform. Its id is
  `<project_id>/<workflow>/<event:NAME | queue:STREAM | cron:EXPRESSION[@TIME_ZONE]>`; a trigger
  removed outside Terraform is added again.
- `runr_workflow_app` sets the endpoint the engine calls for the project or an environment
  (`endpoint_url`; destroy: the function's own URL again). Rotating the signing key and reading it
  are actions (`runr workflows app rotate-key`, `runr workflows app signing-key`).
- Several workflow resources of one project are applied one after another.

## Secret stores and references

```hcl
resource "runr_secret_store" "vault" {
  name = "vault-prod"
  type = "vault"                    # the API's provider: vault (also OpenBao) | aws | gcp | azure
  auth = "jwt"
  settings = {
    server     = "https://vault.example.com:8200"
    role       = "runr"
    auth_mount = "runr-eu"
    mount      = "secret"
    kv_version = "v2"
  }
}

data "runr_secret_store_trust" "vault_eu" {
  store_id  = runr_secret_store.vault.id
  region_id = data.runr_region.eu.id   # issuer, pem_keys, subject, audience for Vault's JWT auth
}

resource "runr_env_var" "db_password" {
  project_id = runr_project.api.id
  name       = "DB_PASSWORD"
  reference = {                        # secret = true is implied
    store = runr_secret_store.vault.id
    path  = "apps/orders"
    key   = "db_password"
  }
}
```

- `runr_secret_store` is one of the organization's [secret stores](secret-stores.md): settings
  only, never a credential — the region signs in with its service account, and
  `data.runr_secret_store_trust` gives what the store's side has to trust for a region. `type` is the
  API's `provider` (`provider` is a meta-argument of Terraform) and replaces the store; `name`,
  `auth`, `settings` (the whole set; checked per provider at plan time) and `refresh_interval`
  change in place. A store that a variable refers to cannot be destroyed; naming the store by its
  `id` makes Terraform create it first and destroy it last.
- `runr_env_var.reference` makes the variable a reference to a value in a store (`runr env ref`):
  exactly one of `value`, `value_wo` and `reference`. RunR never sees the value; External Secrets
  Operator reads it in the project's region with the next deploy. Switching between a value and a
  reference is an in-place change; the sync status is no attribute (`runr env ls`, `runr env sync`).
- The region's External Secrets Operator and its own OpenBao are set up by platform operators
  ([secret stores](secret-stores.md#external-secrets-operator-in-a-region)).

## Cost limit

```hcl
resource "runr_cost_limit" "api" {
  project_id = runr_project.api.id
  amount     = 150          # a month, in the billing currency
  action     = "stop"       # notify | stop
}

data "runr_cost_limit" "api" {
  project_id = runr_project.api.id   # month_to_date, forecast, percent, state, stopped
}
```

`runr_cost_limit` is the [monthly cost limit](billing.md#monthly-cost-limit) of a pay-as-you-go
project (RunR has no organization-wide limit): `amount` (above 0, at most three decimals) and
`action` change in place, `currency` is the billing currency. A fixed-price project is refused
(`cost_limit_not_available`). Destroying it removes the limit; a project the limit stopped stays
stopped until it is resumed (an action: the Billing tab, `POST
/api/projects/{project}/billing/cost-limit/resume`). The month's figures change all the time, so
they are in the data source, not the resource.

## Reports

```hcl
data "runr_runtime_patches" "api" {
  project_id = runr_project.api.id   # state, level, deadline_at, current, latest, pin, patches
}

data "runr_rightsizing" "api" {
  project_id = runr_project.api.id   # status, current and recommended cpu / memory_mb, savings
}
```

`data.runr_runtime_patches` reads a project's [runtime patch](runtimes.md#runtime-patches) state;
applying, pinning and unpinning a patch stay actions (`runr projects patch`). A pin names one of the
five newest patches of a moving feed and RunR itself lifts or sets pins at critical deadlines, so a
declared pin would fight RunR. `data.runr_rightsizing` reads a project's
[rightsizing](monitoring.md#rightsizing) recommendation (or `collecting`); a configuration can take
its `recommended` values into `runr_project.resources` explicitly, while applying or dismissing it
stays with `runr projects rightsizing`.

## Model services

A model service ([Hugging Face models](huggingface.md)): `hub_id` (added to the organization's
library on first use, pinned to the commit `revision` points to) or `model_id` (a model of the
library, e.g. an uploaded one), served in `region_id`.

```hcl
resource "runr_model_service" "embed" {
  hub_id    = "sentence-transformers/all-MiniLM-L6-v2"
  region_id = data.runr_region.eu.id
  min_scale = 1
}

resource "runr_model_service" "chat" {
  name      = "chat"
  hub_id    = "Qwen/Qwen2.5-7B-Instruct"
  region_id = data.runr_region.eu.id
  mode      = "offline"            # the region's copy, no egress
  device    = "gpu"
  engine    = "vllm"
  gpu       = { count = 1, memory_min = 24000 }
  args      = ["--max-model-len", "8192"]
  timeout   = "45m"
}
```

- Settings left out (`mode`, `device`, `engine`, `gpu`, `cpu`, `memory_mb`, `min_scale`,
  `max_scale`, `scale_to_zero`, `timeout_seconds`, `served_name`) are RunR's pick and become
  computed attributes; set them to pin a value. `engine` takes a concrete engine (`vllm`, `tgi`,
  `tei`, `transformers`, `diffusers`, `custom`): leave it out for RunR's pick.
- `image`, `args`, `env_vars` (sensitive, stored encrypted), `port` and `health_path` have no
  default of RunR's: removing one from the configuration resets it to the engine's default.
- A change of a running service is applied with one update that redeploys it; only changed
  settings are sent. `name`, `hub_id`, `revision`, `model_id` and `region_id` replace the
  service.
- `running` (default `true`) deploys the service or keeps it stopped; a service that failed or
  was stopped outside Terraform is deployed again on the next apply. `wait` (default `true`)
  waits until it is `ready`, at most `timeout` (default `30m`). A service that fails is an
  error: a newly created one stays in the state as tainted and is replaced on the next apply, an
  existing one is deployed again.
- `url` is cluster-local; bound projects get it as `RUNR_MODEL_<ALIAS>_URL` (`runr models bind`).
  The other computed attributes (`status`, `openai_base`, …) are in the
  [reference](../terraform-provider-runr/docs/resources/model_service.md).
- Destroying a service that projects still bind fails; remove the bindings first
  (`runr models unbind`).

## Access control and branding

```hcl
resource "runr_role" "release_manager" {
  name        = "Release manager"
  permissions = ["projects.deploy", "logs.view", "metrics.view"]
}

resource "runr_group" "platform" {
  name    = "platform"
  role    = "viewer"                               # organization-wide role of the members
  members = ["grace@example.com", "alan"]          # user ids, e-mail addresses or usernames
}

resource "runr_project_access" "platform_orders" {
  project_id = runr_project.orders.id
  group_id   = runr_group.platform.id              # or user = "grace@example.com"
  role       = runr_role.release_manager.key
}

resource "runr_branding" "brand" {
  product_name   = "Acme Cloud"
  primary_color  = "#0f766e"
  support_url    = "https://help.acme.example"
  logo_light_url = "data:image/svg+xml;base64,${filebase64("${path.module}/logo.svg")}"
  footer_links   = [{ label = "Status", url = "https://status.acme.example" }]
}
```

- **`runr_role`** manages custom roles; the built-in roles (`owner`, `admin`, `developer`,
  `viewer` and `billing`) are part of RunR and refuse an import.
  RunR stores a role with the permissions its permissions imply: `permissions` keeps what you
  wrote, `effective_permissions` lists the expanded set. The key defaults to the slug of
  `name`; a new `key` replaces the role.
- **`runr_group`**: `role` is the members' organization-wide role (unset: the group grants
  project access only). `members` takes user ids, e-mail addresses or usernames and keeps the
  form you wrote; leave it unset to manage the membership elsewhere, set it to `[]` to remove
  every manually added member. Members synced from single sign-on stay with the SSO group
  mapping and are not part of `members`.
- **`runr_project_access`** gives exactly one of `user` or `group_id` a `role` on
  `project_id`. A subject holds one role per project, so a changed role updates the same grant
  in place. The Owner role is given to members directly only.
- **`runr_branding`** is one brand per `scope`: `install` (the installation; multi-tenant:
  platform administrators) or `organization` (multi-tenant: an organization's own brand while
  the platform allows organization brands). The default scope follows the token's user. The
  resource owns the whole brand: attributes left out use the defaults, and destroying it resets
  the brand. Images take `https://` URLs or `data:` URIs (`filebase64()`); an image uploaded in
  the console stays while its attribute is unset. `powered_by` and `tenant_branding_enabled`
  belong to the install scope; `tenant_branding_enabled` left out keeps the console's setting.

## Hosted MCP servers

```hcl
resource "runr_mcp_server" "weather" {
  project_id  = runr_project.weather.id
  access_mode = "oauth"   # public | token (default) | oauth
  audit_calls = true
}

# The gateway key reaches the workload with a deploy.
resource "runr_deployment" "weather" {
  project_id = runr_project.weather.id
  triggers   = { mcp_server = runr_mcp_server.weather.id }
}
```

- `runr_mcp_server` serves a project with the http trigger at
  `https://<console>/mcp/servers/<handle>` ([hosted MCP servers](mcp.md#hosting-mcp-servers));
  `url`, `handle` and `resource_metadata` (the OAuth protected resource metadata, empty for
  `public`) are computed. A project with another trigger is refused.
- `access_mode` (default `token`), `path` (default `/mcp`) and `audit_calls` (default `false`)
  change in place; a new `project_id` replaces the server. Destroying it stops serving the
  project and removes its call log; the project stays.
- Creating the resource for a project that already is an MCP server fails with the import
  command to use instead: `terraform import runr_mcp_server.weather weather` (project name or
  id). A server removed outside Terraform is created again by the next apply.
- The gateway signs requests with a key the workload reads from `RUNR_MCP_GATEWAY_KEY`; it
  arrives with a deploy, hence the server's `id` in `runr_deployment.triggers`. Key rotation
  stays with `runr mcp-server rotate-key`.

## What the provider does not manage

Every piece of configuration a project or an organization declares has a resource or an
attribute. What is left are actions — one-off operations whose result is no desired state — and
reports. Terraform does not run them; the CLI, the console and the API do:

| What | Why it is no resource | Instead |
|---|---|---|
| Promotion between environments, approving or rejecting one | an action that moves a version on; the environments are `runr_environment`, their deployments `runr_deployment` with `environment` | `runr promote`, `runr approvals` |
| Promoting, aborting or rolling back a blue-green / canary rollout | an action on one rollout; the strategy is `deploy_strategy` | `runr rollout promote\|abort\|rollback` |
| Failover and sync of a global project | an action in an outage or after one; the regions, mode and failover setting are `runr_project.global` | `runr projects global failover\|sync` |
| Stored webhook and platform events, replaying them, publishing to a topic | events, not configuration; the triggers and topics are `runr_project.triggers` and `runr_topic` | `runr triggers events\|replay`, `runr topics publish` |
| Rotating an API key, the gateway's usage | rotation is an action (the resource follows the new key); usage is a report | `runr gateway keys rotate`, `runr gateway usage`, `GET /api/api-usage` |
| Applying, pinning or unpinning a runtime patch | a redeploy onto a patch; a pin names one of the five newest patches of a moving feed, and RunR itself lifts or sets pins at critical deadlines, so a declared pin would fight RunR | `runr projects patch apply\|pin\|unpin`; `data.runr_runtime_patches` reads the state |
| Applying or dismissing a rightsizing recommendation | the recommendation is a report of the pods' use; applying it is a config change | `runr projects rightsizing apply\|dismiss`; `data.runr_rightsizing` reads it, and `runr_project.resources` can take its values |
| Resuming a project its cost limit stopped, stopping or resuming a project | an action; the limit itself is `runr_cost_limit` | the console, `POST /api/projects/{project}/billing/cost-limit/resume`, `POST /api/projects/{project}/stop\|resume` |
| Starting, cancelling, retrying or replaying a workflow run, sending an event | runs and events, not configuration; the definitions are `runr_workflow` | `runr workflows run [cancel\|retry\|replay]`, `POST /api/events` |
| Rotating a workflow app's signing key or a hosted MCP server's gateway key, new credentials of a binding | new secrets on demand; the app, the server and the binding are resources | `runr workflows app rotate-key`, `runr mcp-server rotate-key`, `runr bindings rotate` |
| Rolling back to an earlier version, restoring a deleted binding | an action on a deployment or on retained data; `runr_deployment.version_id` names the version that runs | `runr rollback`, `runr bindings restore` |
| Load tests | a run with a report | `runr loadtest run` |
| Organization export and import | a one-off transfer of a whole organization | `runr org export\|import` |
| Syncing secret store references now, verifying a store, revealing a secret value | actions and reads of values RunR does not keep; the stores and references are `runr_secret_store` and `runr_env_var.reference` | `runr env sync`, `POST /api/secret-stores/{store}/verify`, `runr env ls --reveal` |
| Installing cert-manager, External Secrets Operator, OpenBao or a global DNS server in a region | operations on a region's cluster that RunR runs and reconciles (platform operators); the provider manages no regions | `runr regions cert-manager\|openbao\|global-dns`, the region settings in the console (External Secrets Operator: [secret stores](secret-stores.md#external-secrets-operator-in-a-region)) |

## Import

| Resource | Import id |
|---|---|
| `runr_project` | project name or id |
| `runr_env_var` | `<project name or id>/<NAME>` |
| `runr_environment` | `<project name or id>/<environment name>` |
| `runr_environment_secret` | `<project name or id>/<environment>/<NAME>` |
| `runr_domain` | `<project name or id>/<domain>` or `<project>/<environment>/<domain>` |
| `runr_deployment` | `<project name or id>` (the live deployment) or `<project>/<deployment id>` (also of an environment) |
| `runr_binding` | binding name or id |
| `runr_binding_attachment` | `<project name or id>/<binding name or id>` or `<project>/<environment>/<binding>` |
| `runr_private_connection` | `<project name or id>/<connection name or id>` or `<project>/<environment>/<connection>` |
| `runr_topic` | topic name or id |
| `runr_runtime` | runtime id |
| `runr_workflow` | `<project name or id>/<workflow>` or `<project>/<environment>/<workflow>` |
| `runr_workflow_trigger` | `<project name or id>/<workflow>/<event:NAME \| queue:STREAM \| cron:EXPRESSION[@TIME_ZONE]>` |
| `runr_workflow_app` | `<project name or id>` or `<project>/<environment>` |
| `runr_model_service` | service id or name |
| `runr_gateway` | gateway id, or the region's name or id |
| `runr_gateway_route` | `<gateway id or region>/<route id or path>` |
| `runr_api_plan`, `runr_api_consumer` | plan id, consumer id |
| `runr_api_key` | `<consumer id>/<key id>` |
| `runr_secret_store` | store name or id |
| `runr_cost_limit` | project name or id |
| `runr_role` | role key (custom roles only; `runr roles ls` lists the keys) |
| `runr_group` | group id or name (`members` stays unmanaged until set) |
| `runr_project_access` | `<project name or id>/<grant id>` (`runr access ls -p <project>` lists grant ids) |
| `runr_branding` | `install` or `organization` |
| `runr_mcp_server` | project name or id |

```sh
terraform import runr_project.api orders-api
terraform import runr_env_var.log_level orders-api/LOG_LEVEL
terraform import runr_environment.staging orders-api/staging
terraform import runr_environment_secret.staging_api_key orders-api/staging/API_KEY
terraform import runr_domain.api orders-api/api.example.com
terraform import runr_deployment.api orders-api
terraform import runr_binding_attachment.orders_db orders-api/orders-db
terraform import runr_topic.orders orders
terraform import runr_runtime.q q
terraform import runr_model_service.chat chat
terraform import runr_workflow_trigger.nightly 'orders-api/nightly-report/cron:0 3 * * *@Europe/Istanbul'
terraform import runr_gateway.eu eu-1
terraform import runr_gateway_route.orders eu-1/orders
```

Write-only values are not imported, because RunR never returns them: a secret
`runr_env_var` or `runr_environment_secret` is imported without its value, an external
`runr_binding` without its `values`, a `runr_private_connection` without its link token and
credentials, a trigger of `runr_project.triggers` without its `secrets`, a `runr_api_key` without
its key; the next apply writes the values of the configuration (a warning says so; a private
connection takes them into the state without being replaced). An imported `runr_project` holds
RunR's config of each trigger, so the first plan shows the configured `config` JSON as an in-place
update that changes nothing on RunR. The `hub_id` and `revision` of an imported
`runr_model_service` are not part of the service; keep them in the configuration.

## Development

The provider lives in `terraform-provider-runr/` (Go module
`github.com/brf-tech/runr/terraform-provider-runr`; it uses `../cli` through a `replace`
directive).

```sh
cd terraform-provider-runr
make build      # terraform-provider-runr binary
make test       # acceptance-style tests against the in-memory fake API
make docs       # regenerate docs/ from the schema and examples/ (tfplugindocs)
make snapshot   # goreleaser: registry archives, SHA256SUMS and manifest in dist/
```

The tests run real `terraform plan`, `apply`, `import` and `destroy` steps
(terraform-plugin-testing, `resource.UnitTest`) against the provider served in-process and
the fake RunR API of `cli/pkg/runr/runrtest`; they need the `terraform` CLI
(`TF_ACC_TERRAFORM_PATH`, `PATH`, or a download by the test framework) and no RunR
installation. `terraform-registry-manifest.json` declares protocol 6 for the Terraform
Registry; a release signs `SHA256SUMS` with the key in `GPG_FINGERPRINT`.

Upgrades are tested too: `TestUpgradeFrom0510PlansNoChange` applies a configuration with every
resource type of 0.5.10, strips the state to the 0.5.10 schema
(`internal/provider/testdata/provider-0.5.10-schema.json`, the attribute names of
`terraform providers schema -json` of v0.5.10) and expects an empty plan.
`TestUpgradeFromPreviousProviderBinary` does the same with the previous provider itself: set
`RUNR_TF_PREVIOUS_PROVIDER` to a directory with its binary (`git archive v0.5.10
terraform-provider-runr cli | tar -x -C /tmp/prev`, then `go build` in
`/tmp/prev/terraform-provider-runr`); without the variable it is skipped.

The reference in `terraform-provider-runr/docs/` is generated: change a description in the
schema (`internal/provider/*.go`) or an example in `examples/`, then run `make docs`.
