# Environments and promotion

A project runs in several environments: its own workload — the **production** environment — and
as many others as you add, e.g. `dev` and `staging`. Every environment runs the project's
versions as a workload of its own, with the project's configuration and the environment's
overrides on top, its own secret variables, bindings, custom domains and git branch, and,
optionally, in another region than the project's. A version moves from one environment to the
next by **promotion**: the same code version and image, never built again — in another region the
version built into that region's registry — with the target's own configuration. Promotions into production wait for an approver by default.

Preview environments of pull requests ([Git deploy](git-deploy.md#preview-environments)) are
separate: they are not environments, are not listed or promoted, and do not count towards the
environments of a project.

- [The pipeline](#the-pipeline)
- [How many environments](#how-many-environments)
- [Creating an environment](#creating-an-environment)
- [Configuration](#configuration)
- [Secrets](#secrets)
- [Bindings](#bindings)
- [Custom domains](#domains)
- [Gateway routes](#gateway-routes)
- [Private database connections](#private-database-connections)
- [Another region](#another-region)
- [Deploying to an environment](#deploying-to-an-environment)
- [Git branches](#git-branches)
- [Promotion](#promotion)
- [Approvals](#approvals)
- [Notifications](#notifications)
- [Rollback](#rollback)
- [Durable workflows](#durable-workflows)
- [Stopping, deleting, metering](#stopping-deleting-metering)
- [Region moves and detached regions](#region-moves-and-detached-regions)
- [What runs in the region](#what-runs-in-the-region)
- [Permissions](#permissions)
- [API](#api)
- [Limits](#limits)

## The pipeline

The environments of a project form its pipeline, in order: the ones you added (by `position`,
then by creation) and production last. Promotion goes from an environment to the next one, unless
you name the target.

```
dev  ──promote──▶  staging  ──promote (approval)──▶  production
```

Production is the project itself: its Knative Service is `<slug>`, its deployments, versions,
domains, bindings and secrets are the ones the project always had. It is listed as the environment
`production`, cannot be deleted (delete the project), and takes no overrides — its configuration
*is* the project's configuration.

## How many environments

There is no fixed number. Each environment other than production reserves its size — the
project's configuration with its overrides — in the region it runs in, from its creation on,
deployed or not, exactly like a project does:

- in the region capacity ([Regions](regions.md)): an always-on environment holds
  `(size + queue-proxy + Kata overhead) × min_scale` of the always-on budget, a scale-to-zero one
  one pod of the FaaS overcommit budget;
- in the organization's quota on the region (multi-tenant installs, [Tenancy](tenancy.md)).

An environment that does not fit is refused when it is created or its overrides grow (422 with
the budget that is short). That is how many environments a project may have: as many as the
quotas hold.

## Creating an environment

| Field | Meaning |
| --- | --- |
| `name` | A lowercase DNS label, at most 20 characters (`staging`, `qa-eu`). Not `production` and not a name a resource of the project uses (`trigger`, `qc`, `bindings`, `pr-<n>`, `v<n>`, …). Unique in the project, cannot be changed. |
| `knative_region_id` | The region it runs in; default the project's ([Another region](#another-region)). |
| `git_branch` | A push to this branch deploys the environment ([Git branches](#git-branches)). |
| `auto_deploy` | Whether a push to `git_branch` deploys (default `true`). |
| `requires_approval` | Promotions into it wait for an approver (default `false`; production `true`). |
| `inherit_secrets` | Whether the project's secret variables reach it (default `true`). |
| `position` | Its place in the pipeline. |
| `overrides` | Configuration over the project's ([Configuration](#configuration)). |

Its workload is named `<slug>-<name>` (the slug shortened so the longest derived name fits); a name
whose workload name another project or environment already uses is refused. Its default URL is
`https://<slug>-<name>.<function domain>` of its region. Nothing runs until a version is deployed
or promoted to it.

Console: the project page's environment switcher → **Environments**. CLI: `runr environments
create staging --branch develop --env DEBUG=1 --min-scale 0` ([CLI](cli.md)). MCP:
`environment_create`, `environment_update`.

## Configuration

An environment runs the project's configuration with its overrides on top:

```
project config (Settings: type, runtime, resources, scale, trigger, env vars)
  └─ environment overrides
       └─ a deploy's overrides (deploy_config: cpu, memory, scale, timeout — that deployment only)
```

| Override | Notes |
| --- | --- |
| `env_vars` | Merged with the project's by name; `null` removes a project variable in this environment. |
| `cpu`, `memory_mb` | The container size (also what the environment reserves). |
| `min_scale`, `max_scale`, `scale_to_zero` | Knative scaling. |
| `timeout_seconds` | Request timeout (within the region's limit). |
| `schedule` | The cron schedule of a `schedule` trigger. |
| `queue_stream` | The stream a `queue` trigger reads. Without one, an environment reads `<stream>-<name>`, never production's stream. |
| `scale_schedule` | Its own [timed warmer](scaling.md#environments): rows (`{cron, min_scale}`, in the organization's time zone, never above its `max_scale`, else the project's) replace the project's schedule there; `[]` keeps no containers warm there; no value runs the project's schedule. A change reaches the running workload within a minute, without a deploy. |
| `deploy_strategy` | How its deploys roll out: `strategy`, `blue_green` {…}, `canary` {…} over the project's [deploy strategy](deploy-strategies.md#environments) (only what it sets; also through `…/deploy-strategy` with `environment`). It applies from the next deploy. |

The trigger type, the runtime and the code are the project's: they are part of a version. A
change of the project's configuration reaches every environment with its next deploy; a change of
an environment's overrides reaches it with its next deploy too.

Every environment other than production gets `RUNR_ENVIRONMENT=<name>` in its handler container
(unless the project sets the variable itself).

## Secrets

An environment gets the project's secret variables (unless `inherit_secrets` is off) and its own
over them by name: set `API_KEY` on staging and staging's workload reads its own `API_KEY`, the
other ones the project's. They reach the workload through the environment's own Kubernetes Secret
`<workload>-secret-env` in the environment's region:

- an environment's own value is write-only and pass-through like the project's
  ([Security](security.md)): `PUT …/environments/{environment}/secrets/{name}` writes it straight
  into `<workload>-secret-env` in the environment's region, and RunR's database keeps only the
  name, a version, who changed it when and a keyed fingerprint — never the value. A region that does
  not answer fails the request (503) and nothing changes; an environment on a region without a
  cluster (a demo region) cannot have values of its own (422). A changed value reaches the workload
  with the environment's next deploy;
- an inherited variable whose value RunR wrote into the project's region — its Secret
  `<slug>-secret-env` or the region's OpenBao ([Secret stores](secret-stores.md)) — is copied into
  the environment's Secret with each deploy of the environment, also when the environment runs in
  another region. A value the project's region does not have fails the deploy with its name;
- an inherited reference to a secret store gets the environment's own ExternalSecret.

The environment's own values follow it: when it moves to another region (while nothing runs in it),
when its project moves and it follows the project's region, and when its region moves to a new
cluster ([Moving a region](regions.md#moving-a-region-to-another-cluster)), they are copied to the new cluster first. An organization
export reads them from the region ([Organization export](org-export.md)). Deleting the environment
removes its Secret. `php artisan secrets:move-to-region` has nothing to move for environments: no
release kept their values in the database.

Turn `inherit_secrets` off for an environment that must not see any production credential; it then
has only the secrets you give it.

## Bindings

Bindings are never inherited: an environment has only the bindings attached to it. Attach a
binding to an environment (`POST /projects/{project}/bindings` with `environment`, or the
environment's **Bindings** tab) and its variables go into the environment's Secret
`<workload>-bindings`; the binding's NetworkPolicy admits that environment's pods only
(`runr/environment-id`), so staging's code cannot reach production's database, nor the other way
round. A binding is attached to at most one environment of a project, and it must be in the region
the environment runs in. `GET /projects/{project}/bindings?environment=staging` lists one
environment's attachments. CLI: `runr bindings attach|detach|attachments --env staging`. See
[Bindings](bindings.md).

## Domains

Every environment has its default URL `https://<workload>.<function domain>`. A custom domain is
added to one environment (`POST /projects/{project}/domains` with `environment`) and routes to its
workload; DNS checks and TLS work as for the project ([Custom domains](architecture.md)), with the
DNS targets and the certificates of the region the environment runs in
(`GET /projects/{project}/domains?environment=staging`, `…/domains/ca-certificate?environment=`).
CLI: `runr domains add|ls|verify|tls|ca|rm --env staging`.

When the environment runs in another region from then on (its region is changed while nothing
runs in it, or it follows its project to another region), its custom domains leave the old region
and wait for a new verification, as the project's do when it moves: their DNS target changed, and
an uploaded certificate lived in the old region's Secret only (TLS falls back to none).

## Gateway routes

A route of an [API gateway](api-product.md#routes) leads to the project's own workload or to one of
its environments: `POST /api/gateways/{gateway}/routes` with `project_id`, `path` and
`environment` (a name or id; none or `production`: the project's own workload). The route hands the
request to the environment's Knative Service `<workload>`; authentication (public, API key, JWT),
consumers, plans and the metering of the calls work as on any route. Rules:

- the gateway must be in the region the environment runs in (422 otherwise). Every member has one
  gateway per region: `POST /api/gateways {"knative_region_id": "…"}` (console **Gateway in another
  region**, `runr gateway create REGION`) gives you the one for an environment that runs elsewhere
  than its project;
- the project's own workload and each environment have one route; a path is used once per gateway;
- `gateway_only` on an environment's route takes that environment's own URL away, as it does for
  production; the environment's custom domains keep it from becoming gateway-only;
- an environment's deploy syncs its gateway; the environment's `url` (`GET …/environments`) is its
  gateway URL while it has a route;
- `endpoint_type` stays the project's own workload's: a route to an environment does not change it.

Deleting the environment or the project removes its route and syncs the gateway without it. An
environment with a route keeps its region (see [Another region](#another-region)). CLI:
`runr gateway routes add GATEWAY /path -p shop --env staging`, `runr gateway routes ls|rm`.

## Private database connections

A private connection to a managed PostgreSQL database through Skupper
([regions](regions.md#skupper-and-demo-projects)) belongs to the project's own workload or to one of
its environments: `POST /projects/{project}/private-connections` with `environment`
(`GET …/private-connections?environment=staging` lists one environment's). An environment's
connection lives in the project namespace of the region the environment runs in — its Skupper
Link, Listener and credentials Secret, named so that they never collide with production's — and
only the environment's pods get its variables (`<prefix>DATABASE_URL`, `<prefix>PGHOST`, …), so
staging reaches its own database and never production's. Names and prefixes are unique per
environment; the region must run Skupper. Deleting the environment or the project removes its
connections from the region; a region move installs the Skupper site on the new cluster when a
project or an environment there has a connection. An environment with a connection keeps its
region. Console: the project's **Private connections** tab on the environment selected in the
switcher; CLI: `runr private-connections add|ls|status|rm --env staging`.

## Another region

An environment can run in another region than its project — a staging in the region of the test
team, production in the customer's. The organization's data residency must allow the region
([Supply chain and residency](supply-chain.md)): a region it may not use is refused when the
environment is created and when it is deployed.

A version built into a registry (dependencies, a compile step, WebAssembly) runs from its build
image, which only the nodes of the regions that pull from that registry can start. An environment
whose region's registry does not hold the version's image — another region than the project's, or
a region that moved to a cluster with a registry of its own — runs the same version **built into its
region's registry**: the version's code, built there FROM the same runtime base (the runtime patch
the version was built from, by digest), with the build global projects run in their other regions.
Nothing reaches one region's registry from another.

- The deploy, promotion or git push to the environment is not refused: its deployment waits,
  `pending`, with the message "Waits for v7 to be built into the registry of us-1 …", and the
  environment's running deployment keeps serving. When the build is ready the deploy goes on through
  every check of a deploy; a promotion stays `deploying` meanwhile and ends `deployed` or `failed`
  with its deployment.
- A failed build fails the deployment with "The build of v7 into the registry of us-1 failed: …"
  (and the promotion with it); the next deploy of the version tries again.
- The image is kept per version and region (`version_region_images`, the store global projects'
  copies use too: one build per version and region for both): every later deploy, promotion or
  rollback of the version into that region runs it at once. The newest builds there
  (`registry.retain_versions`) and every one a deployment still runs are kept; the others leave the
  region's registry, and so do those of an environment that is deleted, a pruned version and a
  deleted project.
- A version that is being built again into the region's own registry for the project's own workload
  (a region move, an older version deployed after one) is waited for instead of built twice.
- A version without a build of its own runs the runtime's image with its code in a ConfigMap; a
  runtime image RunR builds from source in the environment's region is waited for while it builds
  there (up to about 17 minutes).
- CaaS projects run their version's image in any region.

A promotion moves the same code version — a built version's image in another region is the same
code built there, not the bytes the source environment ran; the promotion's `image` is the version's
own image. The region of an environment changes only while nothing runs in it and it has no gateway
route or private database connection (they live in the region): remove them, change the region and
add them there again.

## Deploying to an environment

Deploy a version to an environment like to production, with `environment`:

```bash
curl -X POST https://runr.example.com/api/projects/$PROJECT/deploy \
  -H "Authorization: Bearer $TOKEN" -H "Content-Type: application/json" \
  -d '{"version_id": "01J…", "environment": "staging"}'
```

`runr deploy --env staging`, the console's deploy dialog with the environment switcher on
staging, MCP `deploy` with `environment`. Every deploy check applies to the environment as it
runs: its region's status, residency and supply-chain policy, the project's stop, fixed-region
allocations. Only that environment's live deployment is replaced: a deploy to staging never
touches production. It rolls out with the environment's [deploy strategy](deploy-strategies.md#environments)
— the project's unless the environment sets its own: with blue-green or canary the new revision
starts next to the one that serves the environment, and the rollout is promoted, aborted or rolled
back on the environment's own workload (`runr rollout promote --env staging`). A promotion into an
environment follows the target environment's strategy.

`GET /projects/{project}/deployments?environment=staging` lists an environment's deployments
(`?environment=all`: every environment's); without it the list is production's, as before.

## Git branches

Map a branch to an environment (`git_branch`, e.g. `develop` → `staging`) and a push to it — to
the project's RunR remote or the connected GitHub / GitLab / Forgejo repository — deploys that
environment:

- the pushed tree becomes a version of the project (versions are shared by every environment; the
  version list shows the branch it came from);
- its `runr.yaml` is **not** applied to the project's configuration — that is production's. For a
  CaaS project the `runr.yaml`'s `image` becomes the version's image;
- with the environment's `auto_deploy` on it is deployed to the environment; the git deploy is of
  kind `environment` and the provider gets a deployment of the environment's name (GitHub
  Deployments: not a production environment).

The project's deploy branch (Git tab) keeps deploying production; a branch maps to one
environment. See [Git deploy](git-deploy.md).

## Promotion

Promotion takes the version an environment runs — its active deployment's version — to another
environment: the **same code version and the same image**, never built again, with the target's
own configuration. A target in another region whose registry does not hold the image runs the
version built into its region's registry from the same code ([Another region](#another-region)). `GET …/environments/{from}/promote?to=` shows what will change before you
promote:

- `code`: the version (and image) the target runs now and the one it will run;
- `config`: what changes in the target besides the code — its current configuration against what
  its live deployment ran with (field by field; env vars, secrets and binding variables by name
  only: added, removed, changed — never a value);
- `environment_differences`: how the source's configuration differs from the target's, for
  information;
- `requires_approval`.

`POST …/environments/{from}/promote` with `to` (default: the next environment) and an optional
`message` requests it: into an environment without approval it deploys at once (status
`deploying`, then `deployed` when the deployment is active or `failed`); into one that requires
approval it waits (`pending_approval`). The promotion pins the image of the version: if the
version is built again before it is approved, the promotion fails instead of deploying a
different image.

Statuses: `pending_approval` → `approved` → `deploying` → `deployed` | `failed`; `rejected`,
`cancelled` (by the requester), `superseded` (a newer request into the same environment).

Console: **Promote** on the environment switcher. CLI: `runr promote staging` (`--dry-run` shows
the diff). MCP: `promote` (`dry_run`).

## Approvals

Promotions into an environment with `requires_approval` — production by default — wait for an
approver: someone with `environments.approve` on the project who did not request it. The approver
approves (it deploys) or rejects it, with a comment; the requester can cancel it while it waits.

The approvals inbox lists what waits across your projects: `GET /environment-promotions`
(`?status=` other statuses, `all`), the console's **Approvals** page, `runr approvals`, MCP
`promotion_approve` without a promotion id. Each promotion says whether you may approve it.

Turn the approval off for an environment — production included — with
`PATCH …/environments/production {"requires_approval": false}`. Deploying a version directly with
`projects.deploy` (the deploy endpoint, a git push to the deploy branch) is not a promotion and does
not wait for an approval; give the approval-free path only to the people who may deploy production.

## Notifications

A promotion that waits for approval notifies everyone who may approve it — `environments.approve` on
the project, organization-wide or on the project — but the requester, who cannot approve their own.
The requester hears when an approver approves or rejects it, and when its deployment went live or
the promotion failed. A promotion into an environment without approval deploys at once and tells
its requester when it is deployed or failed.

| Event | Who | |
| --- | --- | --- |
| `environment.promotion.pending_approval` | the approvers of the project but the requester | "staging → production of shop waits for your approval" |
| `environment.promotion.approved`, `…rejected` | the requester | with the approver and the comment |
| `environment.promotion.deployed`, `…failed` | the requester | with the reason of a failure |

- **In-app and mail**: a notification (with its live push) and a mail, each unless the user turned
  the **Promotions** type off for that channel (Account → Notifications,
  `PUT /api/account/notification-preferences` with `type: promotion`).
- **Platform events**: the same five events go to the organization's
  [`platform_event` triggers](triggers.md#platform-events) — a function that forwards them to a chat
  or a webhook — with names only, never a configuration value.
- The approvals inbox in the console follows promotions live (WebSocket topic
  `environment.promotion`, [WebSocket events](websocket-events.md)).

A notice that cannot go out is logged; the promotion goes on.

## Rollback

A rollback restores the code **and** the configuration a deployment ran with. See
[Rollback](versioning.md#rollback).

## Durable workflows

Every environment of a [workflow](workflows.md) project is a workflow app of its own: its
definitions are synced from its own deployment, its runs execute against its own workload with its
own signing key (from `<workload>-secret-env`, never production's), its cron triggers fire for it,
and its queue consumer delivers to a bridge of its own. Events belong to an environment by its name
(`POST /api/events` with `environment`; default production; a run's `step.sendEvent` stays in its
environment): a `staging` event starts, resumes and cancels only runs of `staging` workflows. The
workflow API, the MCP workflow tools and `runr workflows --env` take the environment; the console's
Workflows tab follows the environment switcher. Deleting the environment removes its workflows and
runs. See [Workflows → Environments](workflows.md#environments).

## Stopping, deleting, metering

- **Stop** ([Project stop and resume](billing.md#project-stop-and-resume)): every environment's
  workload stops with the project; a resume deploys again the version each one ran — also the
  resume RunR makes itself when a new month lifts a cost-limit stop.
- **Delete an environment**: its workload, ConfigMaps and Secrets leave its region, its custom
  domains, gateway route and private database connections are removed, its bindings detached (the
  bindings stay), its versions built into its region's registry leave it (unless another
  environment runs them there), its workflow app with its definitions and runs and its deployments,
  secrets and open promotions are deleted. Usage records stay. Deleting the project deletes every
  environment.
- **Metering** ([Monitoring](monitoring.md#metering)): a project's usage covers every
  environment; every usage record carries the `environment_id` and the name of its environment
  (null: production), and bills split a project's lines per environment — "vCPU hours (staging,
  eu-1, HTTP)" — with the same totals; a deleted environment keeps its name on the bill
  ([Billing](billing.md#environments)). An environment's deployment never ends another environment's
  time. The region agent reports the sessions of an environment's pods (label
  `runr/environment-of`) with the environment's id, in the region the environment runs in; a
  queue-triggered environment's consumer and the containers a timed warmer keeps warm are its
  records too.
- **Monthly cost limit** ([Billing](billing.md#monthly-cost-limit)): the limit counts the
  project's usage in every environment; reaching it with `stop` stops all of them.
- **Fixed regions** ([Billing](billing.md#what-a-fixed-project-may-use)): everything the project
  runs in a fixed region counts against its allocation there — production, every environment in
  that region with the size of its live deployment, and previews.

## Region moves and detached regions

- **A region moves to another cluster** ([Regions](regions.md#moving-a-region-to-another-cluster)):
  every environment that runs on the region — following its project there, or with the region as
  its own, also when its project runs elsewhere — is deployed again on the new cluster like the
  projects: the version, overrides and config its live deployment ran, through every deploy check of
  the environment. A refused environment is not moved (its deployments end with "Not moved to the
  new cluster of the region: …"). A built version whose image the new cluster's registry does not
  hold is built there first ([Another region](#another-region)); the environment keeps running on
  the old cluster until it is deployed on the new one, and is not moved when the build fails.
  Custom domains are routed on the new cluster (an uploaded certificate is copied from the old one).
  The move's report (`GET …/migration-status`) lists each environment as a row of its own, with
  `environment`.
- **A rollback** deploys them again on the restored cluster — also one the move did not move —
  cancels the builds of the move window, and removes from the cluster it left what the move ran for
  them: their Knative Services, CronJobs, queue consumers, code ConfigMaps, Secrets and builds; of a
  project whose own workload runs elsewhere only its environments' objects.
- **A region detached from an organization** ([Tenancy](tenancy.md)): every environment that runs
  there stops — its deployments end, its workloads leave the region, its custom domains wait for a
  new verification, its gateway route and private database connections there are removed, its
  builds there are forgotten — before its project leaves the region. Its own secret values lived in
  that region only and go with it, like the project's (set them again). An environment that had
  the region as its own follows its project's region from then on; deploy it again once its region
  is set.

## What runs in the region

For an environment `staging` of the project `shop` (production: `shop`):

| Resource | Production | staging |
| --- | --- | --- |
| Knative Service | `shop` | `shop-staging` |
| Code ConfigMap | `shop-v<N>` | `shop-staging-v<N>` |
| CronJob (schedule trigger) | `shop-trigger` | `shop-staging-trigger` |
| Queue consumer (queue trigger) | `shop-qc` | `shop-staging-qc` |
| Secret env vars | `shop-secret-env` | `shop-staging-secret-env` |
| Bindings Secret | `shop-bindings` | `shop-staging-bindings` |
| Build of a version (Job and source ConfigMap) | `build-shop-v<N>` | `rbuild-shop-v<N>` when the region's registry does not hold the version's image ([Another region](#another-region)); image `<registry>/runr-builds/shop:v<N>` |

An environment's workloads and their pods carry `runr/environment-of: <project id>`,
`runr/environment-id` and `runr/environment` instead of `runr/project-id`; its ConfigMaps and
Secrets keep `runr/project-id` too (deleting the project sweeps them).

On the region's [internal network](internal-network.md#model-services-queue-triggers-and-previews)
an environment is `<project>-<environment>.<organization>` (host
`shop-staging.<organization>.runr.internal`): it follows the project's access mode and admits the
project's callers in the same environment.

## Permissions

| Permission | Scope | What it allows |
| --- | --- | --- |
| `environments.manage` | project | create, change and delete environments (env var overrides and environment secrets also need `env.edit`) |
| `environments.promote` | project | request promotions, cancel one's own |
| `environments.approve` | project | approve or reject promotions into environments that require approval (never one's own) |

The Developer role has `environments.manage` and `environments.promote`; Owner and Admin have all
three. Deploying to an environment directly needs `projects.deploy`. See
[Permissions](permissions.md).

## API

{environment} is an environment's name or id; `production` is the project's own workload.

| Method and path | Permission | Body / query |
| --- | --- | --- |
| `GET /api/projects/{project}/environments` | `projects.view` | — the pipeline, each with its live `deployment`, `url`, `region`; `abilities` |
| `POST /api/projects/{project}/environments` | `environments.manage` | `name`, `knative_region_id`, `git_branch`, `auto_deploy`, `requires_approval`, `inherit_secrets`, `position`, `overrides` |
| `GET /api/projects/{project}/environments/{environment}` | `projects.view` | — |
| `PATCH /api/projects/{project}/environments/{environment}` | `environments.manage` | any field of create except `name` |
| `DELETE /api/projects/{project}/environments/{environment}` | `environments.manage` | — |
| `GET /api/projects/{project}/environments/{environment}/secrets` | `env.view` | — names, `scope` `environment` or `project`; an own one with `version`, `fingerprint`, `stored_in` `region` |
| `PUT /api/projects/{project}/environments/{environment}/secrets/{name}` | `env.edit` | `value` (into the region; 503 when it does not answer) |
| `DELETE /api/projects/{project}/environments/{environment}/secrets/{name}` | `env.edit` | — |
| `GET /api/projects/{project}/environments/{environment}/promote` | `projects.view` | `?to=` — what will change |
| `POST /api/projects/{project}/environments/{environment}/promote` | `environments.promote` | `to`, `message` |
| `GET /api/projects/{project}/promotions` | `projects.view` | `?status=`, `?limit=` |
| `POST /api/projects/{project}/promotions/{promotion}/approve` | `environments.approve` | `comment` |
| `POST /api/projects/{project}/promotions/{promotion}/reject` | `environments.approve` | `comment` |
| `POST /api/projects/{project}/promotions/{promotion}/cancel` | requester, or `environments.approve` | — |
| `GET /api/environment-promotions` | `projects.view` on any project | `?status=` (default `pending_approval`, `all`) — the approvals inbox |
| `POST /api/projects/{project}/deploy` | `projects.deploy` | `environment`, `restore_config`, `restore_from_deployment_id` besides `version_id`, `deploy_config`, `strategy` |
| `GET`, `PUT /api/projects/{project}/deploy-strategy` | `projects.view`, `projects.update` + `environments.manage` | `?environment=`, `environment`, `reset` — the environment's [deploy strategy](deploy-strategies.md#environments) |
| `GET /api/projects/{project}/rollout` | `projects.view` | `?environment=` — the environment's newest rollout |
| `POST /api/projects/{project}/deployments/{deployment}/rollback` | `projects.deploy` | `keep_current_config` |
| `GET /api/projects/{project}/deployments` | `projects.view` | `?environment=` (a name, `all`) |
| `POST /api/projects/{project}/bindings`, `POST /api/projects/{project}/domains` | as before | `environment` |
| `GET /api/projects/{project}/bindings`, `GET /api/projects/{project}/domains`, `GET …/domains/ca-certificate` | as before | `?environment=` |
| `POST /api/gateways/{gateway}/routes` | the gateway's owner | `environment` besides `project_id`, `path`, `methods` and the route's authentication (`auth_mode`, `jwt`, `gateway_only`) |
| `POST /api/gateways` | the gateway's owner | `knative_region_id`: your gateway in that region |
| `GET`, `POST /api/projects/{project}/private-connections` | `projects.view`, `connections.manage` | `?environment=`, `environment` |
| the workflow API ([Workflows](workflows.md#environments)), `POST /api/events` | as before | `environment` |

An environment:

```json
{
  "id": "01JC…", "name": "staging", "is_primary": false, "workload_name": "shop-staging",
  "knative_region_id": "01JA…", "own_region": false, "region": {"id": "01JA…", "name": "eu-1"},
  "position": 1, "requires_approval": false, "git_branch": "develop", "auto_deploy": true,
  "inherit_secrets": true,
  "overrides": {"min_scale": 0, "env_vars": {"DEBUG": "1", "FEATURE_X": null}},
  "url": "https://shop-staging.eu-1.example.com", "deploy_strategy": "canary",
  "scale_schedule": [{"cron": "0 9 * * 1-5", "min_scale": 1}, {"cron": "0 18 * * 1-5", "min_scale": 0}],
  "scale_schedule_inherited": true, "warm_scale": {"applied": 1, "applied_at": "…"},
  "deployment": {"id": "01JD…", "status": "active", "config_source": "current", "version": {"id": "01JB…", "number": 7, "label": "v7"}},
  "secrets": [{"name": "API_KEY", "scope": "environment", "source": "region", "stored_in": "region", "version": 2, "fingerprint": "3f9a1c0b77de", "updated_at": "…"}]
}
```

Audit log: `environment.create`, `environment.update`, `environment.delete`,
`environment.secret.set`, `environment.secret.delete`, `environment.promotion.request`,
`environment.promotion.approve`, `environment.promotion.reject`, `environment.promotion.cancel`,
`environment.promotion.deployed`, `environment.promotion.failed`, `deployment.rollback`
([Security](security.md#audit-log)).

Terraform manages environments with `runr_environment` and `runr_environment_secret`, and their
bindings, domains, gateway routes and private connections with `environment` on those resources
([Terraform](terraform.md#environments)).

## Limits

- The region of an environment changes only while nothing runs in it and it has no gateway route or
  private database connection ([Another region](#another-region)).
