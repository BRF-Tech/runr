# Upgrading RunR

A RunR installation is upgraded in place with `helm upgrade` of its release. The database,
the API volume, the generated secrets and every region keep running. Never uninstall and install
again: `helm uninstall` deletes the chart's PersistentVolumeClaims (database, API volume) and the
generated `runr-secret`, whose `APP_KEY` encrypts the secrets in the database.

## Procedure

1. **Back up the database.** With the chart's PostgreSQL:

   ```sh
   kubectl -n runr exec deploy/runr-postgres -- pg_dump -U runr -Fc runr > runr-$(date +%F).dump
   ```

2. **Keep `APP_KEY`.** The chart reads it from the existing `runr-secret` on every upgrade
   (`lookup`), so nothing has to be done for an install the chart created. Do not set
   `secret.appKey` for the first time in the same upgrade (see [Key rotation](security.md#key-rotation)
   for changing it). Tools that render the chart without cluster access (`helm template`, some
   GitOps controllers) cannot read the Secret: give them `secret.appKey` with the current key, or
   manage the Secret yourself (`secret.create=false`).

3. **Upgrade the release** with the new chart and its images:

   ```sh
   helm upgrade runr ./helm -n runr --reuse-values \
     --set api.image.tag=<version> --set ui.image.tag=<version>
   ```

   `--reuse-values` keeps the values of the running release; keys the new chart added take their
   defaults. `--reset-then-reuse-values` does the same but starts from the new chart's
   `values.yaml`. Values files work as before (`-f values.yaml`); new settings such as
   `branding.*` can be added in the same upgrade.

4. **Check** `GET /api/version` and `kubectl -n runr logs job/runr-bootstrap`.

What runs during the upgrade, in order:

| Step | When | What |
| --- | --- | --- |
| `runr-migrate-upgrade-<revision>` Job | pre-upgrade hook, before any Deployment changes | `php artisan migrate` (and `tenancy:migrate`) with the **new** API image; the previous version keeps serving meanwhile |
| Deployments | after the hook | api, horizon, scheduler, reverb, ui and gateway roll to the new images |
| `runr-bootstrap` Job | post-upgrade hook | `agent:redeploy --missing`, `images:move-registry` (stored image references, idempotent), `region:reconcile` (region-side changes of the new version, idempotent), `rbac:assign-missing`, the first administrator when none exists |

Functions keep serving throughout: their traffic goes through each region's ingress, not through
the console. Existing Knative Services, CronJobs and sandboxes are not redeployed by the upgrade;
they pick up the new version's manifests on their next deploy. `region:reconcile` changes only
what a release lists below (e.g. the image namespace of 0.5.1).

Between the migration job and the end of the rollout the previous version still serves the
console — 27 seconds in the e2e run below (migration job 9 s, then the new pods ready). Do not
edit projects, deploy or change regions in that time: the previous version cannot read values the
migration has just encrypted, shows env vars as empty and would save that. Its region status
checks fail in that window and the new version's next check reports the region again.

## 0.5.1 → 0.5.2

### Tracing backend

`region:reconcile` (step `tracing`) brings the tracing backend of every region to this release
where tracing is enabled, and installs nothing anywhere else:

| Region | The step |
| --- | --- |
| Tracing mode `managed` (its own setting) | installs or updates the OpenTelemetry Collector and Grafana Tempo |
| Tracing mode `reuse` or `external` | installs or updates the collector |
| Tracing mode `off` | does nothing |
| No tracing setting of its own — Knative Serving installed by 0.4.x, or with `installWithServing: false` | does what an install of Knative Serving does now: the platform's `observability.tracing.defaultMode` (default `managed`) while `observability.tracing.installWithServing` is `true` (the default); nothing otherwise |

It installs a missing backend and updates one whose chart version or image differs from this
release's: chart `open-telemetry/opentelemetry-collector` 0.176.0 with
`ghcr.io/brf-tech/reqs/opentelemetry-collector-contrib:0.162.0`, chart `grafana-community/tempo`
3.2.0 with `ghcr.io/brf-tech/reqs/tempo:3.1.0` — RunR's mirrors of the Docker Hub images 0.5.0
and 0.5.1 pulled directly (`otel/opentelemetry-collector-contrib`, `grafana/tempo`), so regions
no longer depend on Docker Hub's anonymous pull limit for them. It runs what
`php artisan cluster:install-tracing` runs: a Helm upgrade (or install) of the `otel-collector`
and `tempo` releases with their values rendered anew, the span-metrics PodMonitor and Knative's
tracing configuration. The collector and Tempo pods roll once — spans sent while the collector
restarts are dropped, Tempo keeps its traces on its volume — and the region's tracing status is
`installing` until they run. Regions on one cluster share the backend: the first region of the
cluster with tracing enabled decides it.

Tempo chart 3.2.0 applies the retention (`observability.tracing.tempoRetentionHours`, default
72 hours, or the region's own) where Tempo deletes old traces; with chart 3.1.0 the setting did
not reach that part of Tempo, which kept traces for its default of 14 days (the chart's upgrade
notes). After the update traces older than the retention are removed: raise it before the
upgrade to keep them longer.

Before the upgrade:

- `php artisan region:reconcile --dry-run` lists what the step would install or update.
- To keep tracing off on regions without their own setting, set
  `observability.tracing.installWithServing=false` (or `defaultMode=off`) in the upgrade; for one
  region, set its tracing mode to `off` in the console.
- Regions that pull from a registry of your own: mirror the two images there and set
  `observability.tracing.collectorImage` / `tempoImage` (the same versions).

`observability.tracing.collectorChartVersion` and `tempoChartVersion` are empty by default (the
versions above). The values the charts of 0.5.0 and 0.5.1 set, `0.175.1` and `3.1.0`, which
`--reuse-values` keeps, count as empty; set another version to pin one.

### Image namespace ghcr.io/brf-tech/runr

RunR's images are published under `ghcr.io/brf-tech/runr`, so the organization's other packages
never share a name with RunR's. The tags and digests are the ones 0.5.1 published directly under
`ghcr.io/brf-tech` (and releases up to 0.5.0 under `ghcr.io/runrsh`); the `runr-` prefix of the
component images is gone:

| Up to 0.5.0 | 0.5.1 | 0.5.2 |
| --- | --- | --- |
| `ghcr.io/runrsh/reqs/<image>` | `ghcr.io/brf-tech/reqs/<image>` | `ghcr.io/brf-tech/runr/reqs/<image>` |
| `ghcr.io/runrsh/exec-<runtime>` | `ghcr.io/brf-tech/exec-<runtime>` | `ghcr.io/brf-tech/runr/exec-<runtime>` |
| `ghcr.io/runrsh/runr-wrapper` | `ghcr.io/brf-tech/runr-wrapper` | `ghcr.io/brf-tech/runr/wrapper` |
| `ghcr.io/runrsh/runr-agent` | `ghcr.io/brf-tech/runr-agent` | `ghcr.io/brf-tech/runr/agent` |
| `ghcr.io/runrsh/runr-queue-consumer` | `ghcr.io/brf-tech/runr-queue-consumer` | `ghcr.io/brf-tech/runr/queue-consumer` |
| `ghcr.io/runrsh/runr-supply-chain` | `ghcr.io/brf-tech/runr-supply-chain` | `ghcr.io/brf-tech/runr/supply-chain` |
| `ghcr.io/runrsh/hf-serve`, `image-upload`, `containerd-shim-spin` | `ghcr.io/brf-tech/hf-serve`, `image-upload`, `containerd-shim-spin` | `ghcr.io/brf-tech/runr/hf-serve`, `image-upload`, `containerd-shim-spin` |
| `ghcr.io/runrsh/runr/api`, `runr/ui` | `ghcr.io/brf-tech/runr/api`, `runr/ui` | the same as 0.5.1 |

The upgrade moves every reference an existing installation holds, from 0.5.1 and from earlier
releases alike, the way the [0.5.1 move](#image-namespace-ghcriobrf-tech) did: Helm values
(template helper `runr.image`), the database (migration `move_images_to_runr_namespace` and tenant
migration `0055`, then `php artisan images:move-registry` in the bootstrap job), the region
workloads, the region Traefik and the WebAssembly installer (`region:reconcile`: `image-registry`,
`traefik-image`, `wasm-sandbox`). Only RunR's own packages move: another package directly under
`ghcr.io/brf-tech`, a mirror of your own and the region registry's `<registry>/ghcr.io/…` path
stay as they are. The system images policy passes `ghcr.io/brf-tech/runr/**` (`region:reconcile`,
`admission-policy`, before the workloads move) and keeps passing `ghcr.io/brf-tech/**` and
`ghcr.io/runrsh/**` for the workloads that keep their pods: the node installers of Kata (with its
devmapper) and gVisor and the region's queue Redis move with the next change of their
installation, as in 0.5.1; their images stay pullable under the earlier names.

`php artisan images:move-registry --dry-run` and `php artisan region:reconcile --dry-run` show
what moves before the upgrade's bootstrap job does it.

A registry of your own that holds copies of RunR's images under their paths (an air-gapped
registry, the CLI's `RUNR_RUNTIME_REGISTRY`) needs them under the new paths: copy them from
`ghcr.io/brf-tech/runr`. Image settings that point into such a registry stay as they are.

## 0.5.0 → 0.5.1

### Image namespace ghcr.io/brf-tech

0.5.2 moves these references on to `ghcr.io/brf-tech/runr`
([Image namespace ghcr.io/brf-tech/runr](#image-namespace-ghcriobrf-techrunr)); an upgrade from
0.5.0 straight to 0.5.2 moves them there in one step.

RunR's repository is [github.com/brf-tech/runr](https://github.com/brf-tech/runr) and its images
are published under `ghcr.io/brf-tech` — the same image names, tags and digests that releases up
to 0.5.0 published under `ghcr.io/runrsh`. The upgrade moves every reference an existing
installation holds (also one that comes from 0.4.x); only the exact prefix `ghcr.io/runrsh/`
moves, references into other registries — a mirror of your own, the region registry's
`<registry>/ghcr.io/…` path — stay as they are:

| Where | What moves | How |
| --- | --- | --- |
| Helm values | Image values under `ghcr.io/runrsh/` — set at install, or the previous chart's defaults that `--reuse-values` keeps — render as the same image under `ghcr.io/brf-tech/`: API, UI, gateway, PostgreSQL, Redis, Mailpit, init containers, `ai.images`, `bindings.object.image`, `supplyChain.toolsImage`, `images.uploadImage`, `knative.traefikImage` | template helper `runr.image` |
| Database | Runtime versions; runtime plugins (manifest, `runtime.yaml` as submitted and uploaded Dockerfiles — their region builds stay valid); CaaS images and version snapshots; region, binding and supply-chain settings; model services; sandboxes; security reports — the central schema and every tenant schema | migration `move_images_to_brf_tech_registry` and tenant migration `0054`; the bootstrap job runs `php artisan images:move-registry` once more for rows the previous version's pods wrote during the rollout |
| Region workloads | Every workload RunR deployed: the region agent, queue consumers, the registry and its node mirror, Loki, Prometheus, binding data services, image pre-pull, CronJobs and the Knative Services of functions, CaaS projects, model services and hosted MCP servers (the wrapper init container and runtime images included) | `region:reconcile` (`image-registry`): one patch per workload; a Knative Service gets a new revision that takes the traffic once it is ready, the previous revision serves until then; the other workloads roll once |
| Region Traefik | `ghcr.io/runrsh/reqs/traefik` of any version | `region:reconcile` (`traefik-image`): Helm upgrade of the `traefik` release with the same version |
| WebAssembly | The shim image of the `wasm-installer` | `region:reconcile` (`wasm-sandbox`) re-runs the installer: the same binaries, no container runtime restart |
| Admission policy | The system images policy passes `ghcr.io/brf-tech/**` | `region:reconcile` (`admission-policy`), before the workloads move |

Two kinds of region workloads keep running as they are, because a restart costs more than the
move: the node installers of Kata (with its devmapper) and gVisor — a restart re-runs the install
on every node, and kata-deploy's cleanup restarts the container runtime — move with the next
change of that installation (`region:reconcile` does not count the image namespace alone as a
Kata change); the region's queue Redis (`runr-redis`), whose streams live in memory, moves when it
is installed again. `region:reconcile` lists them as `kept:`; their images stay pullable under
`ghcr.io/runrsh`, and the system images policy keeps passing that namespace.

In the console's namespace the chart's PostgreSQL, Redis, gateway and Mailpit roll once with their
moved images, together with the new version's API and UI: PostgreSQL is unavailable for a few
seconds (an API pod that starts meanwhile restarts until it is back) and Redis starts empty — the
usual rule of the rollout window applies, do not deploy or edit projects until it is over.

`php artisan images:move-registry --dry-run` and `php artisan region:reconcile --dry-run` show
what moves before the upgrade's bootstrap job does it.

Outside the installation:

- **CLI**: `runr` downloads and update checks use the `brf-tech/runr` releases; with Go:
  `go install github.com/brf-tech/runr/cli@latest`.
- **GitHub Action**: `uses: brf-tech/runr/actions/deploy@<tag>`.
- **Terraform**: the provider's address is `brf-tech/runr`. Change `source` in
  `required_providers` and move the state once:
  `terraform state replace-provider registry.terraform.io/runrsh/runr registry.terraform.io/brf-tech/runr`.

## 0.4.x → 0.5.0

### Roles and API tokens

0.4.x had no permission checks for single-tenant users. The upgrade gives every existing user a
role (migration `assign_roles_to_existing_users`):

| 0.4.x | 0.5.0 |
| --- | --- |
| Single-tenant: the administrator account (`ADMIN_USERNAME`, else the oldest user) | Owner |
| Single-tenant: every other user | Admin |
| Multi-tenant: tenant `owner` / `admin` | Owner / Admin |
| Multi-tenant: tenant `member` | the custom role Member (what members could do) |
| Multi-tenant: tenant `viewer` | Viewer (read-only) |

Two things 0.4.x stored but did not enforce are enforced now:

- **Tenant viewers** could change and deploy projects in 0.4.x although the role was named
  Viewer. 0.5.0 keeps them read-only. Give a viewer who needs to work on projects the Member or
  Developer role.
- **API token scopes.** Tokens with `*` keep full access (within their user's role). Tokens with
  0.4.x scopes get those scopes as permissions (`projects:read` → `projects.view`, `logs.view`,
  `metrics.view`, `git.pull`, …; the full list is in [permissions.md](permissions.md#upgrade-from-04x)),
  so a token created with `projects:read` reads but no longer deploys. A token created without
  any scope has no permissions; create a new one with the scopes it needs.

Users the previous version created while the upgrade rolled out get the default role from the
bootstrap job (`php artisan rbac:assign-missing`, safe to run again).

### Secrets at rest

The migration `encrypt_secret_columns` encrypts env vars, version snapshots, region kubeconfigs,
registry and Redis passwords, webhook secrets and the other columns listed in
[security.md](security.md#encryption-at-rest) with `APP_KEY`. Values still in plaintext stay
readable; `kubectl -n runr exec deploy/runr-api -- php artisan secrets:encrypt` encrypts anything
written in plaintext later (it prints `Encrypted 0 plaintext value(s)` when there is nothing
left). On large installs the migration job's 300-second deadline applies to the whole migration
run.

Copies outside the database are not rewritten: the git history of each project on the API
volume (commits of 0.4.x contain `.config.json` with the env values), the code ConfigMaps of
deployed versions and the env of running Knative Services keep their plain values until those
versions are deployed again.

### Secret env vars

0.4.x had plain env vars only; they stay plain after the upgrade and keep their values. To turn
one into a secret, move it in one request and deploy:

```sh
curl -X PUT https://runr.example.com/api/projects/<id> -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"config":{"env_vars":{"PLAIN_VAR":"…"},"secret_env_vars":{"API_TOKEN":"…"}}}'
```

The deploy writes the Kubernetes Secret `<project-slug>-secret-env` in the region and the
Knative Service reads the variable from it (`secretKeyRef`); the value no longer appears in the
Service, the version or the API's project resource ([security.md](security.md#secret-environment-variables)).

### White-label

`branding.*` values apply at the upgrade that sets them (no rebuild): product and company name,
logos (URLs, `data:` URIs or ConfigMap / Secret references), colours (one base colour or the full
`300`–`900` scale) and the login texts — [white-label.md](white-label.md). `GET /api/branding`
shows the result.

### Billing (multi-tenant)

0.5.0 calculates what each tenant owes and leaves invoicing to your billing system, which reads
the amounts through the [billing API](billing.md) and receives signed billing events. The upgrade
migration `turn_payment_records_into_charges` turns the 0.4.x payment records into **charges**:

| 0.4.x payment record | 0.5.0 charge |
| --- | --- |
| `invoice_id` (the billing service's invoice) | `external_ref` |
| capacity-deal record / `Plan …` / `Usage …` record / other | `kind` `capacity_deal` / `plan` / `usage` / `manual` |
| `cancelled` | `void` |
| any other status, period over | `closed`: `total` and `amount_due` = the invoiced total, else the record amount |
| any other status, period running | `open` |

The payment fields (Pay link, due date, paid and refunded amounts) are not carried over — your
billing system holds them. The 0.4.x billing service integration is replaced as follows:

| 0.4.x | 0.5.0 |
| --- | --- |
| RunR created invoices at `billing.gatewayUrl` (`POST /billing/invoices`), polled their status and requested refunds | your system reads closed charges (`GET /api/billing/charges?status=closed`, or the `billing.period.closed` event) and invoices them; refunds happen in your system |
| `billing.gatewayUrl`, `billing.gatewayToken` | not used any more; create an API token with `billing.view` or `billing.manage` for your system ([billing.md](billing.md#setup)) |
| `billing.webhookSecret` signed payment-status calls to `POST /api/billing/webhook` | `billing.webhookSecret` signs the billing events RunR sends to `billing.webhookUrl` — same header format (`X-RunR-Signature`, `X-RunR-Timestamp`) |
| `GET/POST/PATCH /api/tenants/{tenant}/payments` | `GET /api/billing/charges?tenant=…`, `POST /api/billing/tenants/{tenant}/charges`, `PATCH /api/billing/charges/{charge}` |
| `GET /api/tenant/payments` (the tenant's invoices) | `GET /api/tenant/billing` (the tenant's charges, credit and purchases) |

Set `billing.webhookUrl` (and keep or set `billing.webhookSecret`) to receive events; without a
URL the events are kept for `GET /api/billing/events`.

### Region Traefik image

The region Traefik runs `ghcr.io/brf-tech/reqs/traefik:v3.7.4`, RunR's mirror of the
`docker.io/traefik` image its pinned chart runs, so its replicas — and a node that schedules one
later — no longer pull from Docker Hub and its anonymous pull limit. `region:reconcile` moves the
Traefik of existing regions that runs the same Traefik version from Docker Hub (a Helm upgrade
of the `traefik` release with its values reused and only the image set; the Traefik pods roll
once, one at a time) and leaves a Traefik of another version alone. To pull it from a registry
of your own, set `knative.traefikImage` (the same Traefik version) before the upgrade.

### Kata Containers

Of RunR's Kata drop-ins only Dragonball's changed: on regions whose Kata install includes
Dragonball, `region:reconcile` writes the new Dragonball drop-in to the nodes (no kata-deploy run,
no restart); regions with the other hypervisors only keep theirs (from 0.4.1, whose guests still
boot systemd, every drop-in is written once). Dragonball guests get 1 vCPU + the pod's CPU limits
from 0.5.0 on ([kata-hypervisors.md](kata-hypervisors.md#guest-size)); a Dragonball function gets
them at its next deploy.

## Testing the upgrade path

`runtime/ci.sh test-helm-upgrade` repeats the upgrade on a Kind cluster (`runtime/ci.sh
test-upgrade` does the same for a Docker Compose install): it installs the chart and images of
`main` (`--from=<ref>`; the API and UI images of that ref's chart values at its `appVersion`,
built from that ref when they are not public) with the chart defaults, creates data through the old API —
the administrator and a second user, the same cluster as a region (registry, Knative Serving,
Traefik, the region agent), a Python function with two env vars, a full-access and a
`projects:read` token — and runs `helm upgrade --reuse-values` (`--values-mode=reset`:
`--reset-then-reuse-values`) to the checkout's chart and images with a test brand
(`Acme Cloud`, logos as `data:` URIs, a primary `300`–`900` scale) while a prober calls the
function every second. It then checks:

- the pre-upgrade migration succeeded, no migration is pending, `/api/version` answers (it reports
  the chart's `appVersion`) and every deployment runs the new images;
- both users sign in; the administrator is Owner, the other user Admin and still lists projects,
  regions and users;
- env vars are encrypted in the database (`eyJpdiI6…`), the plaintext value is in no row, the
  region kubeconfig is encrypted, the API returns the values, `secrets:encrypt` finds nothing;
- the `*` token works; the `projects:read` token reads projects, gets env values hidden and a 403
  on deploy; its stored scopes are translated;
- the function answered every probe during the upgrade and answers afterwards with its env;
- a variable moved to secret plus a new secret land in `<slug>-secret-env`, the Knative Service
  references them and contains no value, the function reads them after the redeploy;
- the region reports Knative Serving ready and its agent reconnected to the new Reverb;
- `/api/branding` returns the brand (names, palette, logos) and serves the logo from its
  `data:` URI;
- nothing of `ghcr.io/runrsh` is left (the image namespace up to 0.5.0): no workload of the
  cluster — control plane and region, Deployments, StatefulSets, DaemonSets, CronJobs, Knative
  Services — and no running pod references it apart from the ones `region:reconcile` keeps on
  purpose, and no database row of the central and the tenant schemas does (a CaaS project and,
  multi-tenant, a tenant-schema row of a `ghcr.io/runrsh` image are created before the upgrade);
  the region agent runs `ghcr.io/brf-tech/runr-agent`.

`--edition=multi-tenant` installs `edition: multi-tenant` (UI images with the `-mt` suffix) and,
instead of the region and the function, creates a tenant that the platform administrator owns with
a tenant admin, member and viewer. After the upgrade every one of them signs in to the tenant,
`GET /api/members` shows owner / admin / member / viewer, the tenant has the custom role Member and
the member's permissions include `settings.manage`, `sso.manage` and `projects.deploy`, the viewer
lists projects but gets a 403 on `POST /api/projects`, and `secrets:encrypt` finds no plaintext in
the central or the tenant schema.

Measured on a 20-core host with Kind 1.32 (main = 0.4.4 → this release, `--reuse-values`): the
upgrade's migration job took 9 s, the new pods were ready 27 s after it started, the bootstrap
job (agent check, `region:reconcile` with every step up to date, `rbac:assign-missing`) 6 s; the
prober's 38 requests during the upgrade all answered 200.
