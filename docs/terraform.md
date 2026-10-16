# Terraform provider

`terraform-provider-runr` manages RunR projects, environment variables, custom domains,
deployments, bindings, runtime plugins, workflow triggers, model services, hosted MCP servers,
access control and the white-label brand with Terraform (or OpenTofu). It is built on terraform-plugin-framework
(protocol 6) and uses the same API client as the [`runr` CLI](cli.md).

This page is the guide: how the resources fit together, with examples. Every attribute, its
default and the import syntax of each resource are in the generated reference,
[`terraform-provider-runr/docs/`](../terraform-provider-runr/docs/index.md), linked from the
table below.

| Kind | Name | Manages |
|---|---|---|
| Resource | [`runr_project`](../terraform-provider-runr/docs/resources/project.md) | a project: type, runtime, region, handler, image, resources, scale, trigger |
| Resource | [`runr_env_var`](../terraform-provider-runr/docs/resources/env_var.md) | one plain or secret environment variable (sensitive value; secrets write-only, optionally kept out of the state with `value_wo`) |
| Resource | [`runr_domain`](../terraform-provider-runr/docs/resources/domain.md) | a custom domain, its TLS mode and the DNS records it needs |
| Resource | [`runr_deployment`](../terraform-provider-runr/docs/resources/deployment.md) | the running deployment of a project (a version or a caas image) |
| Resource | [`runr_binding`](../terraform-provider-runr/docs/resources/binding.md) | a binding: managed KV, object storage or Postgres in a region, or external credentials |
| Resource | [`runr_binding_attachment`](../terraform-provider-runr/docs/resources/binding_attachment.md) | a binding attached to a project, with an optional env prefix |
| Resource | [`runr_runtime`](../terraform-provider-runr/docs/resources/runtime.md) | a runtime plugin registered from a `runtime.yaml` manifest |
| Resource | [`runr_workflow_trigger`](../terraform-provider-runr/docs/resources/workflow_trigger.md) | one trigger (event, cron or queue) of a durable workflow |
| Resource | [`runr_model_service`](../terraform-provider-runr/docs/resources/model_service.md) | a Hugging Face model (or a model of the library) served on CPU or GPU in a region |
| Resource | [`runr_role`](../terraform-provider-runr/docs/resources/role.md) | a custom role and its permissions |
| Resource | [`runr_group`](../terraform-provider-runr/docs/resources/group.md) | a group (team): its organization-wide role and members |
| Resource | [`runr_project_access`](../terraform-provider-runr/docs/resources/project_access.md) | a role for a user or group on one project |
| Resource | [`runr_branding`](../terraform-provider-runr/docs/resources/branding.md) | the white-label brand of the installation or of an organization |
| Resource | [`runr_mcp_server`](../terraform-provider-runr/docs/resources/mcp_server.md) | a project served as a hosted MCP server behind RunR's gateway |
| Data source | [`runr_region`](../terraform-provider-runr/docs/data-sources/region.md) | a region by name or id (with neither: the only active region) |
| Data source | [`runr_project`](../terraform-provider-runr/docs/data-sources/project.md) | an existing project by name (or slug) or id |

## Install

```hcl
terraform {
  required_providers {
    runr = {
      source  = "brf-tech/runr"
      version = "~> 0.5"
    }
  }
}
```

The provider's registry address is `brf-tech/runr` (provider name `runr`). The Terraform
Registry publishes a provider from a repository named `terraform-provider-<name>`, so releases
reach the registry through the mirror repository `brf-tech/terraform-provider-runr`, which
carries `terraform-provider-runr/` of [brf-tech/runr](https://github.com/brf-tech/runr) and its
signed release archives.

To use a build from this repository, build and install it into the local plugin directory
and run `terraform init` as usual:

```sh
make -C terraform-provider-runr install VERSION=0.5.4   # ~/.terraform.d/plugins/registry.terraform.io/brf-tech/runr/0.5.4/<os>_<arch>/
```

During provider development a `dev_overrides` block in `~/.terraformrc` points
`brf-tech/runr` at the directory with the binary (`make -C terraform-provider-runr build`).

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
- **Environment variables** are managed one per `runr_env_var`; parallel changes to one
  project are serialized by the provider. Values are sensitive. Plain variables are part of
  the project config (each change saves a version). With `secret = true` the variable is a
  write-only secret ([secret variables](security.md#secret-environment-variables)): the
  provider writes it through the project's secrets API, RunR lists only its name, and
  Terraform keeps the value it set. A secret removed outside Terraform is created again; one
  turned into a plain variable outside Terraform is replaced by the secret. With `value_wo`
  instead of `value` (a write-only attribute, Terraform 1.11 and later, `secret = true` only)
  the value is not stored in the state or the plan at all; Terraform cannot compare it, so
  change `value_wo_version` to write a new value. Exactly one of `value` and `value_wo` is
  set. Like every config change, a new value reaches the deployment with the next deploy
  (`runr_deployment.triggers`). On an API without secret variables a secret is stored as a
  regular variable and the provider prints a warning. Reading variables needs `env.view`
  (included in `env.edit`); for a token without it the API hides them, and the provider keeps
  the configured values with a warning instead of showing them as changes.
- **Domains.** Create the records of `verification_*` and `dns_targets` at your DNS provider
  (for example with the DNS provider's Terraform resources); `verify = true` makes the create
  wait until RunR finds them (`verify_timeout`, default `10m`), otherwise run
  `runr domains verify`. `tls_mode` defaults to `none` (the region's default).
- **Deployments.** `wait` (default `true`) waits until the deployment serves, at most
  `timeout` (default `10m`). Destroying `runr_deployment` tears the deployment down. A
  deployment that was replaced outside Terraform (for example by `runr deploy`) or failed drops
  out of the state, so the next plan shows it as a deployment to create and the apply deploys
  again.

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

## Workflow triggers

One trigger of a [durable workflow](workflows.md): an event with an optional condition, a
cron schedule, or a queue stream of the project's queue bridge. Set exactly one of `event`,
`cron` and `queue`.

```hcl
resource "runr_workflow_trigger" "large_orders" {
  project_id = runr_project.orders.id
  workflow   = "process-order"
  event      = "order.created"
  condition  = "event.data.total > 1000"
}

resource "runr_workflow_trigger" "nightly" {
  project_id = runr_project.orders.id
  workflow   = "nightly-report"
  cron       = "0 3 * * *"
  timezone   = "Europe/Istanbul"
}

resource "runr_workflow_trigger" "orders_queue" {
  project_id = runr_project.orders.id
  workflow   = "process-order"
  queue      = "orders"
}
```

Event names under `runr/` are reserved for the platform. `condition` applies to event and
queue triggers; each queue message is the event `queue/<stream>`; `timezone` (default UTC)
applies to `cron`. The id is `<project_id>/<workflow>/<event:NAME | queue:STREAM | cron:EXPRESSION[@TIME_ZONE]>`.

Every attribute replaces the trigger when it changes. The resource adds its trigger to the
workflow's definition and declares the project's definitions again through the API
(`PUT /api/projects/{project}/workflows`): the definition's other triggers and options stay as
they are, and several triggers of one project are applied one after another.

The workflow must exist and be declared through the API (`runr workflows declare`) or by
`runr.workflows.json`. A workflow whose definition the function's SDK reports gets its triggers
from the code; the provider refuses to manage those (change them in the code). A trigger
removed outside Terraform is added again by the next apply.

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
  `viewer`, and `billing` in the multi-tenant edition) are part of RunR and refuse an import.
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

## Import

| Resource | Import id |
|---|---|
| `runr_project` | project name or id |
| `runr_env_var` | `<project name or id>/<NAME>` |
| `runr_domain` | `<project name or id>/<domain>` |
| `runr_deployment` | `<project name or id>` (the live deployment) or `<project>/<deployment id>` |
| `runr_binding` | binding name or id |
| `runr_binding_attachment` | `<project name or id>/<binding name or id>` |
| `runr_runtime` | runtime id |
| `runr_workflow_trigger` | `<project name or id>/<workflow>/<event:NAME \| queue:STREAM \| cron:EXPRESSION[@TIME_ZONE]>` |
| `runr_model_service` | service id or name |
| `runr_role` | role key (custom roles only; `runr roles ls` lists the keys) |
| `runr_group` | group id or name (`members` stays unmanaged until set) |
| `runr_project_access` | `<project name or id>/<grant id>` (`runr access ls -p <project>` lists grant ids) |
| `runr_branding` | `install` or `organization` |
| `runr_mcp_server` | project name or id |

```sh
terraform import runr_project.api orders-api
terraform import runr_env_var.log_level orders-api/LOG_LEVEL
terraform import runr_domain.api orders-api/api.example.com
terraform import runr_deployment.api orders-api
terraform import runr_binding_attachment.orders_db orders-api/orders-db
terraform import runr_runtime.q q
terraform import runr_model_service.chat chat
terraform import runr_workflow_trigger.nightly 'orders-api/nightly-report/cron:0 3 * * *@Europe/Istanbul'
```

Write-only values are not imported, because RunR never returns them: a secret
`runr_env_var` is imported with `secret = true` and without its value, an external
`runr_binding` without its `values`; the next apply writes the values of the configuration
(a warning says so). The `hub_id` and `revision` of an imported `runr_model_service` are not
part of the service; keep them in the configuration.

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

The reference in `terraform-provider-runr/docs/` is generated: change a description in the
schema (`internal/provider/*.go`) or an example in `examples/`, then run `make docs`.
