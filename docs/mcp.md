# AI agents: the RunR MCP server and hosted MCP servers

RunR speaks the [Model Context Protocol](https://modelcontextprotocol.io) (MCP) in two
directions:

- **The RunR MCP server** at `https://<console>/mcp` lets AI agents — Claude Code, Claude's
  custom connectors, Cursor, VS Code and any other MCP client — operate RunR: create projects,
  write code, deploy and roll back, read logs, metrics and traces, set environment variables,
  run code in isolated sandboxes, manage domains, git deploys and previews, environments and
  promotions, triggers, uptime checks, workflows, bindings and models. What an agent can do is
  exactly what its token may do.
- **Hosted MCP servers**: deploy your own MCP server as a RunR project — from a Python or
  TypeScript starter or as your own image — and RunR serves it at
  `https://<console>/mcp/servers/<name>` behind a gateway that handles authentication (public,
  RunR token or OAuth), forwards the caller's identity and records every call.

```text
 MCP client ──Bearer token / OAuth──▶ /mcp ──▶ tools → the RunR API routes (same checks, same audit)
 MCP client ──(public | token | OAuth)──▶ /mcp/servers/weather ──signed + identity──▶ project "weather"
```

- [Connect a client](#connect-a-client)
- [Authentication](#authentication)
- [Tools](#tools)
- [Resources](#resources)
- [Prompts](#prompts)
- [Protocol](#protocol)
- [Audit and limits](#audit-and-limits)
- [Hosting MCP servers](#hosting-mcp-servers)
- [API](#api)
- [CLI](#cli)
- [Permissions](#permissions)
- [Data model](#data-model)
- [Configuration](#configuration)
- [Deployment notes](#deployment-notes)
- [Testing](#testing)

## Connect a client

The console shows everything below under **Data & Integrations → AI agents (MCP)**: the endpoint URL,
copyable snippets for common clients, a form for a scoped MCP token, the OAuth-connected clients
and the tool catalog with the permission each tool needs.

**With OAuth (one click).** Clients that support remote MCP servers with OAuth need only the URL;
they discover RunR's authorization server, register themselves and open the consent page in the
browser:

```bash
claude mcp add --transport http runr https://runr.example.com/mcp
```

**With an API token.** Create a token in **Data & Integrations → AI agents (MCP)** (or
**Administration → API Tokens**) with the permissions the agent should have, and send it as a
bearer token:

```bash
claude mcp add --transport http runr https://runr.example.com/mcp \
  --header "Authorization: Bearer 12|xxxxxxxxxxxxxxxxxxxxxxxx"
```

Cursor (`~/.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "runr": {
      "url": "https://runr.example.com/mcp",
      "headers": { "Authorization": "Bearer 12|xxxxxxxxxxxxxxxxxxxxxxxx" }
    }
  }
}
```

VS Code (`.vscode/mcp.json`):

```json
{
  "servers": {
    "runr": {
      "type": "http",
      "url": "https://runr.example.com/mcp",
      "headers": { "Authorization": "Bearer ${input:runr-token}" }
    }
  },
  "inputs": [
    { "type": "promptString", "id": "runr-token", "description": "RunR API token", "password": true }
  ]
}
```

Claude Desktop (`claude_desktop_config.json`) through the `mcp-remote` bridge:

```json
{
  "mcpServers": {
    "runr": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://runr.example.com/mcp", "--header", "Authorization:${RUNR_AUTH}"],
      "env": { "RUNR_AUTH": "Bearer 12|xxxxxxxxxxxxxxxxxxxxxxxx" }
    }
  }
}
```

Any other client: Streamable HTTP transport, URL `https://<console>/mcp`, header
`Authorization: Bearer <token>`.

## Authentication

Every request to `/mcp` carries a bearer token. Without one (or with an expired or revoked one)
RunR answers `401` with

```http
WWW-Authenticate: Bearer realm="mcp", resource_metadata="https://runr.example.com/.well-known/oauth-protected-resource/mcp", scope="projects.view logs.view metrics.view regions.view"
```

which is where OAuth-capable clients start. The `scope` is the read-only set a client asks for by
default; the consent page preselects it and the user adds what the agent should do beyond that.
An invalid token adds `error="invalid_token"`.

### API tokens

Any RunR API token works ([API tokens](permissions.md#api-tokens)): full access (`*`) or a
selection of permissions. The token decides what the agent sees:

- `tools/list` shows only the tools whose permissions the token holds (organization-wide or on
  at least one project); a tool that is not listed cannot be called (`-32602 Unknown tool`);
- every tool calls the RunR API routes with the same token, so project access, policies, data
  residency, quotas and validation apply exactly as for any other client — a token with
  `projects.deploy` on one project deploys that project and no other;
- a token never exceeds what its owner may do at the moment it is used;
- in the multi-tenant edition a token works in the organization (tenant) it was created in.

### OAuth 2.1

RunR runs an OAuth 2.1 authorization server for MCP clients (MCP authorization specification,
revision 2025-11-25):

| Step | Endpoint | |
|---|---|---|
| Protected resource metadata (RFC 9728) | `GET /.well-known/oauth-protected-resource/mcp` (also `/.well-known/oauth-protected-resource`) | `resource`, `authorization_servers`, `scopes_supported` (the permission catalog), `bearer_methods_supported` |
| Authorization server metadata (RFC 8414) | `GET /.well-known/oauth-authorization-server` | issuer = the console origin; endpoints below; `code_challenge_methods_supported: ["S256"]` |
| Dynamic client registration (RFC 7591) | `POST /oauth2/register` | public clients (`token_endpoint_auth_method: none`) or confidential ones (`client_secret_post`, `client_secret_basic`, a secret returned once) |
| Authorization | `GET /oauth2/authorize` | authorization code with PKCE `S256` (required; `plain` is refused), `state`, `scope` (permission names, space-separated), `resource` (RFC 8707) |
| Consent | console page `/oauth-consent?request=<id>` | the signed-in user approves or denies |
| Token | `POST /oauth2/token` | `grant_type=authorization_code` with `code`, `redirect_uri`, `client_id`, `code_verifier`, `resource` |
| Revocation (RFC 7009) | `POST /oauth2/revoke` | `token`, client authentication or `client_id` |

Rules:

- **Redirect URIs** are `https` URLs, `http` on a loopback address (`127.0.0.1`, `[::1]`,
  `localhost`, any port — native clients, RFC 8252), or private-use URI schemes of native apps
  (`cursor://…`); never fragments.
- **Consent.** The consent page names the client, its redirect host and what it asks for. The user
  picks the permissions — full access or a selection, preselected with the requested scopes
  the user holds — the organization the token works in (multi-tenant edition) and the
  lifetime: 7, 30, 90, 180 or 365 days, or until revoked (default 30 days). Approving needs
  `tokens.manage`, as creating any API token does.
- **The issued token is a RunR API token** named `<client name> (MCP)` with the approved
  permissions and expiry, pinned to the organization and bound to the resource it was issued
  for: a token issued for `/mcp` is refused by a hosted MCP server and the other way round.
  There are no refresh tokens: when the token expires, the client runs the flow again.
- **Codes** are single-use, valid for 120 seconds and bound to the client, the redirect URI and
  the PKCE challenge; presenting a used code again revokes the token issued for it. An
  authorization request waits 10 minutes for the consent.
- **Cleanup.** `php artisan mcp:prune` (scheduled daily) removes idle sessions, old
  authorization requests, registered clients nobody connected with for 30 days (clients that
  still hold a token stay) and hosted-server calls past their retention.
- **Revoking.** Issued tokens appear in **Administration → API Tokens** and, grouped by client, in
  **Data & Integrations → AI agents (MCP) → Connected clients**; revoking there (or through
  `/oauth2/revoke`) ends the client's access at once.

## Tools

Tools are thin wrappers over RunR API routes. Long-running tools (marked ⏱) answer as an SSE
stream when the client sends `Accept: text/event-stream` — with `notifications/progress` when the
call carries a `progressToken` — and wait at most `MCP_TOOL_MAX_WAIT_SECONDS` (default 600) before returning the
current state. Annotations: **R** `readOnlyHint`, **D** `destructiveHint`, **I**
`idempotentHint`, **O** `openWorldHint`. "a | b" means either permission; "platform administrator
with a" a platform administrator whose token carries a (the API's `@platform:` routes); an empty
cell means every signed-in caller. The billing tools exist while the [Billing
module](billing.md) is on; each calls the billing API with the caller's permissions, so a tool
answers what the console's billing pages would.

| Tool | Does | Permissions | Hints |
|---|---|---|---|
| `whoami` | the user, the organization and the permissions of the connection | | R I |
| `projects_list` | projects with type, runtime, region, trigger, URL and deployment status | `projects.view` | R I |
| `project_get` | one project in detail, with its runtime patch state (`runtime_patch`: state, level, deadline, current and newest tag, pin), the timed warmer's schedule and what it last applied (`warm_scale`) | `projects.view` | R I |
| `project_create` | create an XaaS / FaaS / CaaS project; with `regions` (and `mode`) a [global project](global-projects.md) (optionally with a timed warmer, `scale_schedule`; a schedule's zone with `schedule_timezone`) | `projects.create` | |
| `project_update` | description, region, resources, scaling, timed warmer (`scale_schedule`: `[{cron, min_scale}]`, `[]` or `null` = off), trigger (`schedule_timezone`: an IANA zone, `""` = the organization's) | `projects.update` \| `env.edit` | I |
| `project_global_get` | a global project's regions: host name, mode, failover setting and a move in progress, each copy's status (fenced included), version, health, DNS, triggers, data status; its bindings and how each region reaches them | `projects.view` | R I |
| `project_global_update` | mode, failover, primary (a switchover), regions of a global project (`confirm: true`) | `projects.update` | D I |
| `project_global_failover` | fail a global project over to another region when its primary does not answer: replicas promoted at once, the old primary fenced (`confirm: true`) | `projects.update` | D I |
| `project_delete` | delete a project with its workloads (`confirm_name` = its name or slug) | `projects.delete` | D I |
| `project_cost_limit_get` | billing on: a pay-as-you-go project's [monthly cost limit](billing.md#monthly-cost-limit), this month so far and the month-end forecast; the API also requires the owner, `projects.billing` or `billing.view` | `projects.view` | R I |
| `project_cost_limit_set` | billing on: set (`amount`, `action` notify \| stop), change or remove (`remove: true`) the limit, and `resume: true` a project the limit stopped; a stop limit below this month so far needs `stop_now: true`; the API also requires the owner, `projects.billing` or `billing.manage` | `projects.view` | I |
| `project_billing_get` | billing on: a project's billing as its Billing tab shows it — billing type, payer, fixed allocation and pending request, this month's bill so far split into the project's own usage, its **preview environments** and its queue consumer, the last closed bills and the cost limit; the API also requires the owner, `projects.billing` or `billing.view` | `projects.view` | R I |
| `project_billing_quote` | billing on: the monthly price of fixed resources (`cpu_m`, `memory_mb`, `gpu_count`) in the project's region and what an approval today bills this month | `projects.view` | R I |
| `project_fixed_request` | billing on: request fixed resources for a project in a fixed region (`cpu_m`, `memory_mb`, `gpu_count`, `note`), or withdraw a pending request (`cancel: <id>`); the API also requires the owner, `projects.billing` or `billing.manage` | `projects.view` | |
| `billing_bills_list` | billing on: bills with kind, month, open (estimate) or closed, project, payer, total and paid status — the organization's (`scope: projects`, filters `period`, `status`, `paid`, `project`, `owner`, `kind`), the platform's to the tenant (`from_platform`) or the platform's to its tenants (`to_tenants`, platform administrators; `tenant`) | `billing.view` | R I |
| `billing_bill_get` | billing on: one bill with its line items and their split (`by_component`: the project, `preview` environments, `queue_consumer`, other resources); with `project` through the project's billing, else `scope` as in `billing_bills_list` | `billing.view` \| `projects.view` | R I |
| `billing_usage_get` | billing on: metered usage per project in a month (`period`, `project`): vCPU-, GB- and GPU-hours, sessions and the pay-as-you-go cost | `billing.view` | R I |
| `billing_prices_get` | billing on: the default prices (current, scheduled, past versions); with `region` that region's type, prices and their source, scheduled versions and markup | `billing.view` | R I |
| `billing_price_set` | billing on: a price version of the defaults or an own `region` from `effective_from` (today or later); `prices` lists only what changes, the rest is copied from the version before; a version of the same date is replaced | `billing.manage` | I |
| `billing_regions_list` | billing on: the regions with billing type, allocations, prices and queue consumer charging at every level (region setting, the platform's choice for the tenant, the organization's own, what applies) | `billing.view` | R I |
| `billing_queue_consumers_set` | billing on: [queue consumer charging](billing.md#queue-consumers) — `level: region` (the region owner's setting, `charge` true \| false) or `level: organization` (the organization's own choice, `charge` false \| null) | `billing.manage` | I |
| `billing_region_organizations` | billing on, platform administrators: the tenants a platform region is shared with and the platform's queue consumer choice for each | platform administrator with `billing.view` | R I |
| `billing_region_organization_set` | billing on, platform administrators: the platform's queue consumer choice for one tenant in a platform region (`charge` false \| null), which the platform's bill to it and its own bills follow | platform administrator with `billing.manage` | I |
| `billing_fixed_requests_list` | billing on: fixed resource requests and allocations, pending first (`status`, `project`, `region`) | `billing.view` | R I |
| `billing_fixed_request_decide` | billing on: approve (`note`) or reject (`reason`) a pending fixed resource request | `billing.approve` | |
| `triggers_list` | the project's [triggers](triggers.md) in order: type, config, enabled, primary, where each runs, a schedule's time zone (`effective_timezone`, `timezone_source`), a webhook's inbox URL, which secrets are set (names only), and what the list deploys | `projects.view` | R I |
| `trigger_add` | add a schedule (`cron`, `timezone`: default the organization's), queue, webhook, topic, platform_event, … trigger (`config`, write-only `secrets`, `primary`); effective with the next deploy | `projects.update` | |
| `trigger_update` | rename, enable / disable, make primary, change config keys (merged; `null` removes one) or replace a secret | `projects.update` | I |
| `trigger_remove` | remove a trigger and its stored events; not the last one (`confirm: true`). Both tools return `cleanup` when a cdc trigger's replication slots are left ([CDC cleanup](triggers.md#cdc-cleanup)) | `projects.update` | D I |
| `code_list_files` | files of the latest or a given version, with sizes | `projects.view` | R I |
| `code_read_file` | one file | `projects.view` | R I |
| `code_write_files` ⏱ | write and delete files → a new version; optionally wait for its build | `code.edit` | |
| `versions_list` | versions with build status | `projects.view` | R I |
| `version_get` | one version, its build status and error | `projects.view` | R I |
| `deploy` ⏱ | deploy a version (default the newest), waiting for its build and the rollout; `environment` deploys one of the project's environments; `strategy` for this deploy (default the environment's: the project's unless it sets its own) — a blue-green / canary rollout is answered once its new revision is ready | `projects.deploy` | |
| `rollback` ⏱ | deploy an older version (number, id or `steps_back`) with the configuration it ran with (`keep_current_config`: the configuration of now); `environment`, `strategy` | `projects.deploy` | D |
| `runtime_patch_apply` ⏱ | redeploy the live version onto the newest [runtime patch](runtimes.md#runtime-patches) (a pin is removed) and wait for the rollout | `projects.deploy` | |
| `environments_list` | the project's environments in pipeline order: region, URL, live version, approval, branch | `projects.view` | R I |
| `environment_create` | add an environment (name, region, git branch, approval, overrides — `scale_schedule` its own timed warmer) | `environments.manage` | |
| `environment_update` | change an environment: git branch, approval, auto deploy, overrides merged over its current ones (env vars by name), `scale_schedule` its own timed warmer (`[]` none there, `null` the project's again) | `environments.manage` | I |
| `environment_delete` | delete an environment with its workload (`confirm: true`) | `environments.manage` | D I |
| `promote` ⏱ | promote the version an environment runs (`from`) to the next one or to `to`, `dry_run` shows what changes; waits for approval into production | `environments.promote` | D |
| `promotion_approve` | approve or reject a waiting promotion (`decision`); without an id the waiting ones | `environments.approve` | D |
| `deployments_list` | deployments of a project | `projects.view` | R I |
| `deployment_status` | a deployment (default the current one; `environment`: of that environment) with readiness, and its rollout (status, traffic, step / health / metrics, test URL) | `projects.view` | R I |
| `deployment_stop` | stop and delete a deployment (`confirm: true`) | `projects.deploy` | D I |
| `deployment_promote` | promote a blue-green / canary rollout now: all traffic to the new revision ([deploy strategies](deploy-strategies.md)); default the project's current rollout, `environment` that environment's | `projects.deploy` | D |
| `deployment_abort` | abort an open rollout (the traffic stays), or roll back a promoted one to its kept previous revision (`confirm: true`, `reason`; `environment` that environment's current rollout) | `projects.deploy` | D |
| `logs_tail` | log lines of the current or a given deployment: `lines`, `since`, `contains`, `errors_only` | `logs.view` | R I |
| `metrics_summary` | CPU (cores), memory (MiB) and request metrics of a project, and its sessions with the deployment's size × their pod time (vCPU ms, MiB × ms); `units` names each field's unit | `metrics.view` | R I |
| `rightsizing_list` | rightsizing recommendations: the organization's open ones (largest monthly saving first) or one project's, with the evidence and why there is none ([Rightsizing](monitoring.md#rightsizing)) | `metrics.view` | R I |
| `rightsizing_apply` | the project's recommended CPU and memory into its config as a new version (deploy to run it) | `projects.update` | |
| `env_list` | names of the plain and secret variables (plain values on request); `secret_details`: where each secret lives — a value in the region (`stored_in`) or a reference to a secret store with its sync status | `env.view` | R I |
| `env_set` | set plain or secret variables (effective with the next deploy; secret values go straight into the project's region) | `env.edit` | I |
| `env_unset` | remove variables | `env.edit` | D I |
| `secret_reveal` | the value of one secret variable, read from the region (audited) | `secrets.view_values` | R I |
| `secret_ref_set` | make a secret variable a reference to a value in a secret store: `store` (name or id), `path`, `key` ([secret stores](secret-stores.md)) | `env.edit` + `secret_stores.view` | I |
| `secret_stores_list` | the organization's secret stores: provider, authentication, references, status per region | `secret_stores.view` | R I |
| `network_get` | access mode, internal name and URL, allowed internal callers ([internal-network.md](internal-network.md)) | `projects.view` | R I |
| `network_set` | access mode: `public`, `allowlist` (with `allowed_cidrs`) or `internal` | `network.manage` | D I |
| `network_caller_add` | allow a project (or, by grant, another organization's) to call this one | `network.manage` | I |
| `network_caller_remove` | remove a caller (`confirm: true`) | `network.manage` | D I |
| `domains_list` | custom domains of a project | `projects.view` | R I |
| `domain_add` | add a custom domain | `domains.manage` | |
| `domain_verify` | verify a domain's DNS | `domains.manage` | I |
| `domain_remove` | remove a domain (`confirm: true`) | `domains.manage` | D I |
| `gateways_list` | the caller's API gateways with their routes and authentication ([API product](api-product.md)) | `projects.view` | R I |
| `gateway_route_add` | a route of a gateway to a project or one of its environments, created with its authentication (public, key, jwt with JWKS) and gateway-only in one call — never public for a moment; the answer has its URL | `projects.update` | |
| `gateway_route_update` | a route's authentication (public, key, jwt with JWKS) and gateway-only | `projects.update` | I |
| `api_consumers_list` | consumers of a gateway / the organization | `projects.view` | R I |
| `api_consumer_create` | create a consumer with plan and external id | `projects.update` \| `api_products.manage` | |
| `api_plans_list` | plans (rate limits, quotas) | `projects.view` | R I |
| `api_plan_create` | create a plan | `projects.update` \| `api_products.manage` | |
| `api_key_create` | create a key of a consumer; the key is in the answer once | `projects.update` \| `api_products.manage` | |
| `api_key_rotate` | replace a key, the old one works on for `overlap_minutes` | `projects.update` \| `api_products.manage` | |
| `api_key_revoke` | revoke a key (`confirm: true`) | `projects.update` \| `api_products.manage` | D I |
| `api_usage` | usage per hour / day / month / consumer / key / route: calls let through and refused, and the function's status classes, errors, latency p50 / p95 / p99 and bytes | `projects.view` | R I |
| `regions_list` | regions | `regions.view` | R I |
| `region_status` | one region and its components | `regions.view` | R I |
| `global_dns_status` | the global zone, its servers (with their GeoIP database: source, status) and the delegation records | `regions.view` | R I |
| `runtimes_list` | the runtime catalog; each version with its end of life and newest runtime patch (`latest_patch`: tag, level, published, deadline) | | R I |
| `runtime_get` | a runtime's manifest and starter template | | R I |
| `runtime_register` | register a runtime from a manifest | `runtimes.manage` \| `runtimes.use_custom` | |
| `runtime_patches_get` | a project's runtime patch state and the last five patches of its runtime version with why each came: level, reason, CVEs, link, deadline; its patch migrations | `projects.view` | R I |
| `runtime_patch_pin` | pin a project to one of those patches (`patch` by id or tag; redeploys unless `deploy: false`) or `unpin` it | `projects.deploy` | I |
| `workflows_list` | workflow definitions of a project (`environment`: one environment's workflow app) | `workflows.view` | R I |
| `workflow_run_start` | start a run (`environment`: a workflow of that environment) | `workflows.run` | |
| `workflow_runs_list` | runs, filtered by status (`environment`, `all`) | `workflows.view` | R I |
| `workflow_run_status` | one run with its steps | `workflows.view` | R I |
| `event_send` | send an event to workflows (`environment`: the workflows of that environment; default production) | `events.send` | |
| `topics_list` | the organization's topics with their subscriptions (`topic`: one topic with the subscribing projects) | `projects.view` | R I |
| `topic_publish` | publish an event (any JSON, or text with `content_type`) to a topic: every subscribed project receives it | `events.send` | |
| `bindings_list` | bindings of the organization | `bindings.view` | R I |
| `binding_create` | create a KV, object, Postgres or external binding | `bindings.manage` | |
| `binding_attach` | attach a binding to a project | `bindings.manage` + `env.edit` | |
| `models_search` | search the Hugging Face Hub | `models.view` | R I O |
| `model_services_list` | model services | `models.view` | R I |
| `model_service_deploy` ⏱ | deploy a model service, waiting until it serves | `models.deploy` | |
| `model_invoke` | call a model service through the playground path | `models.view` | R |
| `model_bind` | bind a model service to a project | `models.deploy` + `projects.update` | |
| `security_report` | SBOM, vulnerability counts, signature and policy decision of a version | `security.view` | R I |
| `audit_search` | search the audit log | `audit.view` | R I |
| `org_export_start` | export the organization into a package (download or object binding; members, images, binding data, encrypted secrets optional) — [Organization export](org-export.md) | `org.transfer` (Owner) | |
| `org_export_status` | an export's or import's status, package and report; the latest transfers without an id | `org.transfer` (Owner) | R I |
| `org_import_start` | import a package that lies in an object binding of the organization (RunR reads it, resumable; `resume` continues a read that failed); S3 buckets and files need the CLI or the console | `org.transfer` (Owner) | |
| `org_import_plan` | the dry-run plan of an uploaded or read package: region mapping, renames, admission, KV Redis versions (`skip_binding_data`), run history (`history`), manual steps | `org.transfer` (Owner) | I |
| `org_import_apply` | apply a planned import (`confirm`); applying a failed import again resumes it | `org.transfer` (Owner) | I |
| `mcp_servers_list` | hosted MCP servers | `projects.view` | R I |
| `mcp_server_create` ⏱ | create a hosted MCP server from a template (Python, TypeScript, image) and deploy it | `projects.create` + `mcp.servers.manage` | |
| `mcp_server_configure` | access mode, path, audit flag; rotate the gateway key | `mcp.servers.manage` | I |
| `mcp_server_calls` | the call log of a hosted MCP server | `logs.view` | R I |
| `sandboxes_list` | the organization's sandboxes ([Sandbox API](sandbox-api.md)) | `sandboxes.view` | R I |
| `sandbox_get` | one sandbox: status, image, resources, network, timeout | `sandboxes.view` | R I |
| `sandbox_create` ⏱ | create a sandbox (template or image, region, resources, env — values never returned, network policy, files) and wait until it runs | `sandboxes.create` | |
| `sandbox_exec` | run a command (shell string or argv): exit code, stdout, stderr, `timed_out` | `sandboxes.exec` | O |
| `sandbox_run_code` | run a code snippet in the code interpreter: outputs, results, errors, changed files | `sandboxes.exec` | O |
| `sandbox_files_list` | list a directory | `sandboxes.exec` | R I |
| `sandbox_file_read` | read a file (text, or base64 for binary) | `sandboxes.exec` | R I |
| `sandbox_file_write` | write a file (parents created) | `sandboxes.exec` | I |
| `sandbox_file_delete` | delete a file or directory (`confirm: true`) | `sandboxes.exec` | D I |
| `sandbox_archive_download` | a directory as a base64 tar (`archive_base64`, `sha256`), at most `MCP_TOOL_MAX_ARCHIVE_BYTES` | `sandboxes.exec` | R I |
| `sandbox_archive_upload` | extract a base64 tar into a directory, at most `MCP_TOOL_MAX_ARCHIVE_BYTES` | `sandboxes.exec` | I |
| `sandbox_process_start` | start a background process (servers, long jobs) | `sandboxes.exec` | O |
| `sandbox_processes_list` | background processes | `sandboxes.exec` | R I |
| `sandbox_process_logs` | output of a background process | `sandboxes.exec` | R I |
| `sandbox_process_stop` | stop a background process | `sandboxes.exec` | D I |
| `sandbox_logs` | the sandbox's event log | `sandboxes.view` | R I |
| `sandbox_lifecycle` | `keepalive`, `set_timeout`, `pause`, `resume` | `sandboxes.exec` \| `sandboxes.create` | I |
| `sandbox_network` | change the egress policy | `sandboxes.create` | D I |
| `sandbox_destroy` | end a sandbox (`confirm: true`) | `sandboxes.create` | D I |
| `sandbox_quota` | the organization's sandbox limits and usage, sandbox regions | `sandboxes.view` | R I |
| `wasm_status` | WebAssembly support of a region ([WebAssembly](wasm.md)) | `regions.view` | R I |
| `wasm_install` ⏱ | install or change WebAssembly support on a region's nodes and follow the install | `sandbox.manage` | D I |
| `wasm_uninstall` ⏱ | remove WebAssembly support (`confirm: true`) | `sandbox.manage` | D I |
| `cert_manager_status` | cert-manager of a region: status, the ClusterIssuer of custom domains (ACME, the region CA or the operator's) and why TLS mode cert-manager is not offered ([cert-manager](regions.md#cert-manager)) | `regions.view` | R I |
| `cert_manager_install` ⏱ | set cert-manager up in a region (or reuse the cluster's own), create the default ClusterIssuer and follow the install | `regions.manage` | I |
| `git_status` | the project's git remote (push with an API token), branch, auto-deploy, connected repository, recent push deploys ([Git deploy](git-deploy.md)) | `git.pull` | R I |
| `git_settings_update` | branch, auto-deploy, preview settings (override values never returned) | `git.manage` | I |
| `git_deploys_list` | deployments that came from pushes | `git.pull` | R I |
| `git_redeploy` ⏱ | deploy the branch head again and wait for the rollout | `git.push` | |
| `git_repositories_list` | repositories of a provider the project can connect to | `git.manage` | R I O |
| `git_repo_connect` | connect a GitHub / GitLab / Forgejo repository (tokens never returned) | `git.manage` | O |
| `git_repo_disconnect` | disconnect the repository (`confirm: true`) | `git.manage` | D I |
| `previews_list` | pull request preview environments | `git.pull` | R I |
| `preview_redeploy` | redeploy a preview | `previews.manage` | I |
| `preview_delete` | delete a preview (`confirm: true`) | `previews.manage` | D I |
| `git_providers_list` | the GitHub Apps, GitLab and Forgejo / Gitea instances of Data & Integrations → Git providers (secrets as `has_*` flags) | `git_providers.manage` | R I |
| `observability_get` | the project's tracing and request replay switches ([Observability](observability.md)) | `projects.view` | R I |
| `observability_update` | switch tracing or request replay | `projects.update` \| `replay.manage` | I |
| `traces_search` | traces of a project: time range (`since`, `until`), status, duration, route | `traces.view` | R I |
| `trace_get` | one trace as a span tree, with its logs | `traces.view` | R I |
| `replays_list`, `replay_get` | captured requests | `replay.manage` | R I |
| `replay_run` | send a captured request to the function again (`confirm: true`) | `replay.manage` | D O |
| `webhook_events_list` | events a webhook or platform_event trigger stored, by `status`; `event`: one with the sender's headers and the body | `replay.manage` | R I |
| `webhook_event_replay` | deliver one stored event, or every `failed` (`delivered`, `all`) one, to the function again (`confirm: true`) | `replay.manage` | D |
| `uptime_checks_list`, `uptime_check_results`, `uptime_incidents_list` | uptime checks, their results and incidents | `metrics.view` | R I |
| `uptime_check_create` | create an uptime check | `uptime.manage` | |
| `uptime_check_update` | change an uptime check | `uptime.manage` | I |
| `uptime_check_delete` | delete an uptime check (`confirm: true`) | `uptime.manage` | D I |
| `uptime_check_run` | run an uptime check now | `uptime.manage` | O |
| `load_test_run` ⏱ | run a [load test](load-test.md) of an HTTP project (10 / 50 / 100 users, 30 / 60 / 300 s, GET or POST to a path of its own URL) and wait for p50 / p95 / p99, errors, requests per second, pods and cold starts; the test traffic is metered | `projects.deploy` | O |
| `load_test_get` | a load test (default: the latest) with the last five runs side by side and the day's limit | `metrics.view` | R I |
| `alert_rules_list`, `alert_events_list` | alert rules and fired alerts | `alerts.manage` | R I |
| `alert_channels_list` | alert channels (masked configuration) | `alerts.manage` | R I |
| `alert_channel_create` | email, Slack, Teams, Telegram or webhook channel; secrets go in write-only | `alerts.manage` | |
| `alert_channel_update` | change a channel; a key left out keeps its value | `alerts.manage` | I |
| `alert_channel_delete` | delete a channel (`confirm: true`) | `alerts.manage` | D I |
| `alert_channel_test` | send a test message through a channel | `alerts.manage` | O |
| `alert_rule_create` | error rate, p95 latency, cold start rate, uptime down or resource threshold rule: scope, params, severity, cooldown, channels | `alerts.manage` | |
| `alert_rule_update` | change a rule | `alerts.manage` | I |
| `alert_rule_delete` | delete a rule; its firing alerts close (`confirm: true`) | `alerts.manage` | D I |
| `status_page_get` | the public status page: settings, components and their status | `status_page.manage` | R I |
| `status_page_update` | create or change the status page; fields left out keep their value | `status_page.manage` | I |

Some things stay in the console because they are browser flows or one-time platform setup:
registering a GitHub App or a GitLab application (Data & Integrations → Git providers; agents read them with
`git_providers_list`), the GitHub App installation and GitLab sign-in of a repository connection,
the interactive sandbox terminal, and a region's tracing backend.

Every project tool takes `project` as the project's id, slug or exact name; sandbox tools take
the sandbox id (`sandboxes_list`). Sandboxes are the agent's own workspace: `sandbox_create`, then
`sandbox_file_write` / `sandbox_exec` / `sandbox_run_code`, `sandbox_process_start` for a server,
and `sandbox_destroy` when done — the sandbox's own timeout ends it otherwise.

**Results.** A tool answers with concise structured JSON (`structuredContent`, protocol
2025-06-18 and later) and the same as text. Secret values never appear in a result except from
`secret_reveal`, which needs `secrets.view_values`.

**Errors.** A call the API refuses, arguments that do not match the input schema and failures
inside the tool come back as a tool result with `isError: true` and the reason — the validation
messages, the permission the call needs (`error.permissions`), the problems of the schema check
(`error.problems`) — so the model can correct the call. Destructive tools take an explicit
confirmation (`confirm: true`, `confirm_name`); models are told to ask the user first.

## Resources

| URI | Content |
|---|---|
| `runr://projects/{id}` | the project as JSON |
| `runr://projects/{id}/files/{path}` | a file of the project's latest version |
| `runr://projects/{id}/logs` | recent log lines of the current deployment |
| `runr://docs/{page}` | a page of this documentation (`runr://docs/mcp` is this page) |
| `runr://runtimes/{id}/manifest` | the manifest of a runtime |

`resources/list` lists the documentation pages, the runtime manifests and the projects the
caller can see; `resources/templates/list` the five templates above. Resources follow the same
permissions as the tools (`projects.view`, `logs.view`).

## Prompts

| Prompt | Arguments | |
|---|---|---|
| `deploy_function` | `description`, `runtime` (optional), `project` (optional) | create or update a project, write the code, deploy it and check the result |
| `debug_deployment` | `project`, `deployment` (optional) | read the deployment status, logs and recent versions and find the cause of a failure |
| `create_workflow` | `description`, `runtime` (optional), `project` (optional) | write a durable workflow with the SDK ([Workflows](workflows.md)) and deploy it |

## Protocol

- **Transport**: Streamable HTTP at `/mcp`. `POST` carries JSON-RPC 2.0 messages; `GET` answers
  `405` (the server sends nothing outside a request); `DELETE` with `Mcp-Session-Id` ends the
  session.
- **Revisions**: `2025-11-25` (offered by default), `2025-06-18`, `2025-03-26`. `initialize`
  answers with the client's revision when RunR speaks it, else the newest; later requests may
  send `MCP-Protocol-Version` (an unsupported value answers `400`). JSON-RPC batches are accepted
  in a `2025-03-26` session only.
- **Capabilities**: `tools`, `resources`, `prompts`, `logging`, `completions`.
- **Sessions**: `initialize` returns `Mcp-Session-Id`; every later request sends it. A session
  belongs to the token that opened it, ends after `MCP_SESSION_IDLE_MINUTES` (default 1440)
  without requests, and an unknown or ended session answers `404` — the client initializes
  again. A request without a session id (other than `initialize`) answers `400`.
- **Responses**: plain JSON, or an SSE stream (`text/event-stream`) for long-running tools when the
  client accepts it. The stream carries `notifications/progress` (with a `message` from revision
  2025-03-26) and `notifications/message` log lines at or above the session's level
  (`logging/setLevel`), then the result.
- **Cancellation**: `notifications/cancelled` for a running request stops its waiting loop; the
  action already started (a deployment, a build) continues in RunR.
- **Completions**: `completion/complete` for the `id` of `runr://projects/{id}` (the projects
  the caller can see, by id or name), the `page` of
  `runr://docs/{page}`, the `id` of `runr://runtimes/{id}/manifest` and the `project` and
  `runtime` arguments of the prompts.
- **Origin check**: a request with an `Origin` header is accepted from the console's own origin
  and from `MCP_ALLOWED_ORIGINS` (default `http://localhost:*,http://127.0.0.1:*`, for local
  inspectors); other origins answer `403`. Requests without `Origin` (CLI and desktop clients)
  are not affected.

## Audit and limits

- Every `tools/call` is an audit event `mcp.tool.call`: actor = the token's user (actor type
  `api_token`), `auth_method` `mcp:<token id>`, the tool, the target (usually the project),
  the result (`success`, `failure` for a tool error, `denied` for a hidden tool), the names of
  the arguments — never their values — and the duration.
- The API actions a tool performs are recorded under their own actions (`project.create`,
  `deployment.create`, `project.secret.reveal`, …) with `auth_method` `mcp:<token id>` and
  `metadata.mcp_tool`; they share the request id of the MCP request.
- Sessions: `mcp.session.start` (client name and version, protocol revision) and
  `mcp.session.end`. OAuth: `oauth.client.register`, `oauth.consent.approve`,
  `oauth.consent.deny`, `oauth.token.issue`, `oauth.token.revoke`, `mcp.client.revoke`.
  Hosted servers: `mcp_server.create`, `mcp_server.update`, `mcp_server.delete`,
  `mcp_server.key.rotate`, `mcp_server.inspect` and, for servers that audit their calls,
  `mcp.server.call`.
- **Rate limits**: every request to `/mcp` counts against the `api` limiter of the token's user
  and organization ([config/ratelimit.php](../api/config/ratelimit.php): per user
  `API_USER_RATE_LIMIT_PER_MINUTE`, per tenant `API_RATE_LIMIT_PER_MINUTE`); the API calls a
  tool makes internally do not count a second time. The OAuth endpoints have a per-IP limiter
(`MCP_OAUTH_PER_MINUTE`), hosted servers a per-server one (`MCP_GATEWAY_PER_MINUTE`).
- **Request size**: a body above `MCP_MAX_REQUEST_BYTES` (default 4 MiB) answers `413`; file
  contents returned by tools and resources are capped at `MCP_TOOL_MAX_FILE_BYTES` (default
  512 KiB, the result says when it was truncated) and log lines at `MCP_TOOL_MAX_LOG_LINES`.

## Hosting MCP servers

Any project with the HTTP trigger — XaaS, FaaS or CaaS, any runtime — can be served as an MCP
server. The project's **MCP** tab turns it into one; **Projects → New MCP server** creates one
from a template in one step.

### Templates

| Template | Project | Runtime | Starter |
|---|---|---|---|
| Python | FaaS | `mcp-python` | [`examples/mcp/python`](../examples/mcp/python): FastMCP (official MCP Python SDK), Streamable HTTP at `/mcp` |
| TypeScript | FaaS | `mcp-node` | [`examples/mcp/node`](../examples/mcp/node): official TypeScript SDK, Node runs `server.ts` with type stripping |
| Container image | CaaS | — | your image and port ([Bring your own image](#bring-your-own-image)) |

`mcp-python` and `mcp-node` are runtime plugins ([Runtimes](runtimes.md)) whose starter template
is the server. The API ships their manifests (`api/resources/mcp/templates.json`, bundled from
`examples/mcp/*/runtime.yaml` by `php artisan mcp:templates-bundle`); the first MCP server of a
runtime registers it — the console offers **Enable the runtime** to holders of
`runtimes.manage` (platform runtime) or `runtimes.use_custom` (the organization's custom
runtime, while custom runtimes are allowed). The starters:

- serve Streamable HTTP on `$PORT` at `/mcp`, statelessly (any replica answers any request, so
  Knative may scale them out and to zero);
- answer `401` to requests without a valid gateway signature (`RUNR_MCP_GATEWAY_KEY` is set on
  every hosted server), so the gateway is the only way in;
- expose the caller to their tools (`whoami`).

From an agent: `mcp_server_create` (`template` `python`, `node` or `image`) does the same and
deploys. From the API: `GET /api/mcp-servers/templates`, `POST /api/runtimes` with the
template's `runtime_manifest`, `POST /api/projects`, `POST /api/projects/{project}/files`,
`PUT /api/projects/{project}/mcp-server`, `POST /api/projects/{project}/deploy`.

### Endpoint and access modes

A hosted server is reachable at `https://<console>/mcp/servers/<handle>` — the handle is the
project's slug. The gateway forwards to `<the project's function URL><path>` (path default
`/mcp`, set per server).

| Access mode | Who gets in | Discovery |
|---|---|---|
| `public` | anyone; the identity is anonymous | — |
| `token` (default) | a RunR API token of a user who holds `mcp.servers.call` on the project | `401` with `WWW-Authenticate: Bearer` |
| `oauth` | as `token`, plus tokens RunR's OAuth server issues for this server | `401` with `WWW-Authenticate: Bearer resource_metadata="https://<console>/.well-known/oauth-protected-resource/mcp/servers/<handle>"` |

In `oauth` mode an MCP client connects with the URL alone: it reads the server's protected
resource metadata, which names RunR as the authorization server, and runs the
[OAuth flow](#oauth-21) with `resource` = the server's URL. The consent page names the server
and its project; the issued token carries `mcp.servers.call` only and works for this server
only. Users need `mcp.servers.call` on the project (Owner, Admin, Developer and Viewer have it;
a custom role with only this permission on one project is an "MCP consumer").

```bash
claude mcp add --transport http weather https://runr.example.com/mcp/servers/weather
```

### Gateway headers and signature

The gateway forwards `Content-Type`, `Accept`, `Mcp-Session-Id`, `MCP-Protocol-Version`,
`Last-Event-ID` and `User-Agent`, sets `X-Forwarded-For`, `X-Forwarded-Proto`,
`X-Forwarded-Host` and `X-Request-ID`, never forwards the client's `Authorization` header, and
adds:

| Header | Value |
|---|---|
| `X-RunR-Identity` | base64url (no padding) of JSON `{"auth": "public\|token\|oauth\|console", "user": {"id", "name", "email"} \| null, "tenant": <id> \| null, "token_id": <id> \| null, "client_id": <OAuth client id> \| null, "scopes": [...]}` |
| `X-RunR-Signature` | `t=<unix seconds>,v1=<hex>[,v1=<hex>]` |

`v1 = hex(HMAC-SHA256(RUNR_MCP_GATEWAY_KEY, "<t>." + METHOD + "\n" + PATH + "\n" + hex(SHA-256(body)) + "\n" + X-RunR-Identity))`,
where `PATH` is the URL path without the query string. Accept a request when any `v1` matches and
`|now − t| ≤ 300` seconds; the identity is then trustworthy. `RUNR_MCP_GATEWAY_KEY` reaches the
workload as a secret environment variable (Kubernetes Secret `<slug>-secret-env`,
`secretKeyRef`), never as a value in the Knative Service.

Python:

```python
import hashlib, hmac, time

def verify(key: str, method: str, path: str, body: bytes, identity: str, header: str) -> bool:
    fields = [part.strip().partition("=") for part in header.split(",")]
    t = next((int(v) for k, _, v in fields if k == "t" and v.isdigit()), None)
    signatures = [v.lower() for k, _, v in fields if k == "v1" and v]
    if t is None or not signatures or abs(time.time() - t) > 300:
        return False
    signed = f"{t}.{method}\n{path}\n{hashlib.sha256(body).hexdigest()}\n{identity}".encode()
    expected = hmac.new(key.encode(), signed, hashlib.sha256).hexdigest()
    return any(hmac.compare_digest(expected, s) for s in signatures)
```

TypeScript (Node):

```ts
import { createHash, createHmac, timingSafeEqual } from "node:crypto";

export function verify(key: string, method: string, path: string, body: Buffer, identity: string, header: string): boolean {
  const parts = header.split(",").map((p) => p.trim().split("=", 2));
  const t = Number(parts.find(([k]) => k === "t")?.[1] ?? NaN);
  const signatures = parts.filter(([k, v]) => k === "v1" && v).map(([, v]) => v.toLowerCase());
  if (!Number.isFinite(t) || signatures.length === 0 || Math.abs(Date.now() / 1000 - t) > 300) return false;
  const bodyHash = createHash("sha256").update(body).digest("hex");
  const expected = Buffer.from(createHmac("sha256", key).update(`${t}.${method}\n${path}\n${bodyHash}\n${identity}`).digest("hex"));
  return signatures.some((s) => s.length === expected.length && timingSafeEqual(Buffer.from(s), expected));
}

export const identityOf = (header: string) => JSON.parse(Buffer.from(header, "base64url").toString("utf8"));
```

### Bring your own image

An existing MCP server that speaks Streamable HTTP runs as a CaaS project: **New MCP server →
Container image** (image, port, path), or any CaaS project with the HTTP trigger and its **MCP**
tab. RunR does not change the image: add the signature check above to it so that requests that
bypass the gateway are refused, and read the caller from `X-RunR-Identity`. The starters'
Dockerfiles build them as such images.

### Inspector

The **MCP** tab has a built-in inspector, proxied through the API (the browser never talks to
the function): **Connect** sends `initialize` and `notifications/initialized` through the
gateway with your identity (`auth: console`), then lists tools, resources and prompts, calls a
tool with a form generated from its input schema (or raw JSON arguments), reads resources, gets
prompts and shows every JSON-RPC message sent and received. It needs `mcp.servers.call` on the
project.

### Call log

Every call through the gateway is recorded: time, JSON-RPC method, tool / resource / prompt
name, request id, HTTP status, outcome (`ok`, `error`, `denied`, `unreachable`), error, duration,
the caller and the access mode. The **MCP** tab shows the log (`logs.view`); the project's
deployment logs show the same calls as pod `mcp-gateway`. Entries are kept
`MCP_GATEWAY_CALLS_RETENTION_DAYS` days (default 30). With **Record every call in the audit log**
on (`audit_calls`), every call is also an audit event `mcp.server.call`.

### Gateway key rotation

**Rotate gateway key** in the **MCP** tab (or `mcp_server_configure` with `rotate_key`, or
`runr mcp-server rotate-key`) creates a new gateway key. The new key reaches the workload with
the next deploy. For seven days after the rotation the gateway signs every request with both
keys (two `v1` values), so a deployment that still holds the old key keeps accepting requests;
deploy within that time. The tab asks for a deploy while the deployment runs with the old key;
`runr mcp-server rotate-key` redeploys unless `--no-deploy` is given.

### Streaming through the gateway

SSE responses of a hosted server are streamed to the client as they arrive; a forwarded request
stays open at most `MCP_GATEWAY_TIMEOUT` seconds (default 900). `GET` (server-initiated stream)
and `DELETE` (end a session) are forwarded as they are. Requests per minute and caller (user,
else client IP) for one server are capped by `MCP_GATEWAY_PER_MINUTE` (default 600).

## API

Root (the console origin):

| Method & path | Auth | |
|---|---|---|
| `POST /mcp` | bearer token | MCP JSON-RPC |
| `GET /mcp` | bearer token | `405` |
| `DELETE /mcp` | bearer token + `Mcp-Session-Id` | end the session |
| `GET /.well-known/oauth-protected-resource`, `…/oauth-protected-resource/mcp` | — | protected resource metadata of `/mcp` |
| `GET /.well-known/oauth-protected-resource/mcp/servers/{handle}` | — | protected resource metadata of a hosted server |
| `GET /.well-known/oauth-authorization-server` | — | authorization server metadata |
| `POST /oauth2/register` | — | dynamic client registration |
| `GET /oauth2/authorize` | — | authorization request → consent page |
| `POST /oauth2/token` | client | authorization code + PKCE → token |
| `POST /oauth2/revoke` | client | revoke a token |
| `POST`, `GET`, `DELETE /mcp/servers/{handle}` | per access mode | the hosted server, through the gateway |

Under `/api` (session or API token):

| Method & path | Permission | |
|---|---|---|
| `GET /api/mcp` | signed in | endpoint, server info, protocol revisions, OAuth metadata URLs, token lifetimes |
| `GET /api/mcp/tools` | signed in | the tool catalog: name, title, description, group, permissions, `allowed` for the caller, annotations, input schema |
| `GET /api/mcp/clients` | `tokens.manage` | the caller's OAuth-connected clients with their tokens |
| `DELETE /api/mcp/clients/{client}` | `tokens.manage` | revoke every token of the caller for that client |
| `GET /api/oauth2/requests/{id}` | `tokens.manage` | a pending authorization request (consent page): client, requested scopes, grantable permissions, organization, lifetimes |
| `POST /api/oauth2/requests/{id}/approve` | `tokens.manage` | `{"abilities": [...], "expires_in_days": 30}` → `{"redirect_url": "…?code=…&state=…&iss=…"}` |
| `POST /api/oauth2/requests/{id}/deny` | signed in | → `{"redirect_url": "…?error=access_denied&state=…"}` |
| `GET /api/mcp-servers/templates` | signed in | the templates with runtime availability, manifest and starter files |
| `GET /api/mcp-servers` | `projects.view` (any project) | hosted servers the caller can see |
| `GET /api/projects/{project}/mcp-server` | `projects.view` | the project's server (`404` when it is none) |
| `PUT /api/projects/{project}/mcp-server` | `mcp.servers.manage` | `{"access_mode": "token", "path": "/mcp", "audit_calls": false}` — serve the project (`201`, `access_mode` required) or change it (`200`); `422` without the HTTP trigger |
| `DELETE /api/projects/{project}/mcp-server` | `mcp.servers.manage` | stop serving it |
| `POST /api/projects/{project}/mcp-server/rotate-key` | `mcp.servers.manage` | new gateway key |
| `GET /api/projects/{project}/mcp-server/calls` | `logs.view` | the call log (`page`, `per_page`, `outcome`, `method`) |
| `POST /api/projects/{project}/mcp-server/inspector` | `mcp.servers.call` | `{"message": {JSON-RPC}, "session_id", "protocol_version"}` → `{"status", "session_id", "content_type", "messages", "raw", "duration_ms"}` |

The project resource names its hosted server: `mcp_server: {"handle", "url", "access_mode"}` or
`null`.

## CLI

`runr mcp` connects agents to the RunR MCP server, `runr mcp-server` manages hosted servers
([CLI reference](cli.md#ai-agents-runr-mcp)):

```bash
runr mcp                                         # endpoint + configuration of the common clients
runr mcp --client cursor --auth token            # one client, with an API token
runr mcp token create --preset read --client claude-code
runr mcp clients                                 # OAuth clients connected with your account
runr mcp tools --allowed                         # the tools this token may use

runr mcp-server templates                        # the Python, TypeScript and image starters
runr mcp-server set --access-mode oauth -p weather
runr mcp-server get -p weather --client cursor   # URL and client configuration
runr mcp-server calls -p weather --outcome error
runr mcp-server inspect -p weather --tool hello --args '{"name": "Ada"}'
runr mcp-server rotate-key -p weather --yes
```

## Permissions

The tools of the RunR MCP server use the existing permissions ([Roles and
permissions](permissions.md)); two permissions are for hosted servers (group **AI agents
(MCP)**):

| Permission | Scope | Allows | Built-in roles |
|---|---|---|---|
| `mcp.servers.manage` | project | serve a project as a hosted MCP server, change its access mode, path and audit flag, rotate its gateway key, stop serving it | Owner, Admin, Developer |
| `mcp.servers.call` | project | call the project's hosted MCP server through the gateway in `token` and `oauth` mode; use the inspector | Owner, Admin, Developer, Viewer |

Approving an OAuth client needs `tokens.manage`. Creating a server from a template also needs
`projects.create` (and `runtimes.manage` or `runtimes.use_custom` once, to register the
runtime).

## Data model

Every table is in the central schema, in both editions (tenant rows carry `tenant_id`).

| Table | |
|---|---|
| `mcp_sessions` | MCP sessions: the SHA-256 of the session id, token, user, tenant, protocol revision, client name / version / capabilities, logging level, last request |
| `mcp_oauth_clients` | registered OAuth clients: client id, name, redirect URIs, grant types, authentication method, SHA-256 of the client secret |
| `mcp_oauth_requests` | authorization requests and codes: client, redirect URI, state, requested scopes, resource, PKCE challenge, the approving user and tenant, the granted permissions and lifetime, the SHA-256 of the code and its expiry, the token issued |
| `mcp_servers` | hosted servers: project, tenant, handle, access mode, path, audit flag, gateway key and previous key (encrypted, `App\Casts\Encrypted`), rotation time |
| `mcp_server_calls` | the call log of hosted servers |
| `personal_access_tokens` | `oauth_client_id`, `oauth_resource` of tokens issued through OAuth |

## Configuration

`api/config/mcp.php`; environment variables of the API (Helm: set them via `extraEnv`):

| Variable | Default | |
|---|---|---|
| `MCP_ENABLED` | `true` | the MCP endpoints |
| `MCP_PUBLIC_URL` | the request's origin | console origin used in URLs and metadata |
| `MCP_SERVER_TITLE` | the brand name | `serverInfo.title` |
| `MCP_ALLOWED_ORIGINS` | `http://localhost:*,http://127.0.0.1:*` | browser origins accepted besides the console's |
| `MCP_MAX_REQUEST_BYTES` | 4 MiB | largest request body |
| `MCP_SESSION_IDLE_MINUTES` | 1440 | session idle timeout |
| `MCP_TOOL_MAX_WAIT_SECONDS` | 600 | longest wait of a long-running tool |
| `MCP_TOOL_POLL_INTERVAL_MS` | 2000 | poll interval of long-running tools |
| `MCP_TOOL_MAX_FILE_BYTES` | 512 KiB | largest file content in a result |
| `MCP_TOOL_MAX_LOG_LINES` | 500 | most log lines in a result |
| `MCP_TOOL_MAX_ARCHIVE_BYTES` | 4 MiB | largest tar archive of `sandbox_archive_download` / `_upload` (before base64) |
| `MCP_DOCS_PATH` | `../docs`, else `resources/docs` | Markdown pages of `runr://docs/{page}` |
| `MCP_OAUTH_ENABLED` | `true` | the OAuth authorization server |
| `MCP_OAUTH_OPEN_REGISTRATION` | `true` | dynamic client registration without an initial access token |
| `MCP_OAUTH_PER_MINUTE` | 60 | requests per minute and client IP to `/oauth2/*` |
| `MCP_GATEWAY_PER_MINUTE` | 600 | requests per minute and caller for one hosted server |
| `MCP_GATEWAY_CONNECT_TIMEOUT` | 10 | seconds to connect to a hosted server |
| `MCP_GATEWAY_TIMEOUT` | 900 | longest forwarded request, SSE streams included (seconds) |
| `MCP_GATEWAY_VERIFY_TLS` | `true` | check the TLS certificate of the function URL |
| `MCP_GATEWAY_CALLS_RETENTION_DAYS` | 30 | call log retention (`0` keeps every call) |

## Deployment notes

- **Gateway routes.** The console gateway sends `/mcp`, `/mcp/*`, `/oauth2/*` and
  `/.well-known/oauth-*` to the API and streams them through (`flush_interval -1`): Helm
  `helm/templates/gateway-configmap.yaml`, single host `compose/Caddyfile` (these paths are not
  compressed). A proxy of your own in front of the console must not buffer them either; with
  ingress-nginx set `nginx.ingress.kubernetes.io/proxy-buffering: "off"` and a
  `proxy-read-timeout` of at least `MCP_GATEWAY_TIMEOUT` in the Helm value `ingress.annotations`.
- **Documentation resources.** `runr://docs/{page}` reads `MCP_DOCS_PATH`, else `../docs` next to
  the API (repository checkout; Docker Compose mounts `./docs` at `/var/www/docs`), else
  `resources/docs` inside the API image, which the image builds fill with `docs/*.md`
  (`.gitlab-ci.yml` `build:api`, `compose/build.sh`, `ci.sh build-deploy`).
- **Hosted servers** reach their projects through the function URL
  (`https://<slug>.<function domain>`), like durable workflows do: the API must be able to reach
  the region's function domain.

## Testing

- API: `php artisan test tests/Feature/Mcp` (protocol, sessions, SSE, tools per token scope,
  resources, prompts, OAuth with PKCE and dynamic registration, consent, token pinning and
  revocation, audit events, the hosted gateway in every access mode, the inspector).
- Console: `npm run test` (`vue/tests/modules/mcp`), `npx playwright test e2e/mcp.spec.ts`.
- Kind: `runtime/ci.sh test-mcp` runs an MCP client on the official MCP Python SDK against a
  RunR on Kind (`runtime/lib/test-mcp.sh`, `runtime/lib/mcp_client.py`): the RunR MCP server
  with a full and a read-only token, the OAuth flow end to end, and a hosted Python server
  created with `mcp_server_create` and called through the gateway in token, public and OAuth
  mode.
