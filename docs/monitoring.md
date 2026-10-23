# Monitoring and metering

## Architecture Overview

```
Prometheus (cluster)
  → runr-agent (Go, WS push, 5s)
    → Reverb → AgentMessageHandler
      → metric_snapshots (base app)
      → deployment_sessions (base app)
      → Redis cache (live metrics)

  → SnapshotMetricsJob (1min cron)
    → metric_snapshots (1m granularity)

  → DownsampleMetricsJob (hourly cron)
    → 1m → 5m (after 7 days)
    → 5m → 1h (after 30 days)
    → 1h → 1d (after 90 days)

  → UsageAggregationJob (5min, always)
    → usage_records (central + every tenant schema: quantities, no money)
      → Billing module (optional): prices × quantities → bills
```

## Components

### Base App (always active)

| Component | Purpose |
|-----------|---------|
| `MetricsPersistenceService` | Snapshot + downsample + cleanup |
| `SessionTrackingService` | Session start/end, stale cleanup |
| `LogRetentionService` | Loki log TTL + deployment protection |
| `HistoricalMetricsController` | GET /api/projects/{id}/metrics/history\|summary |
| `SnapshotMetricsJob` | Every 1min: snapshot active deployments |
| `DownsampleMetricsJob` | Hourly: tiered downsampling |
| `LogRetentionJob` | Daily: enforce the per-region log TTL (default 90 days) |
| `CloseStaleSessionsJob` | Every 10 min (`close-stale-sessions`), in the central schema and every tenant schema: close the sessions whose end was never reported. A session the agent reported in its heartbeat ends when it was last seen, once no heartbeat came for 10 min (the agent or the region is gone); a session without any heartbeat (an older agent that sends none) ends at the close, once it is open for more than 30 min. The pod's real end, a repeated start or a heartbeat later corrects or reopens a session closed too early |

### Go Agent (`runtime/agent/`)

| Goroutine | Purpose |
|-----------|---------|
| `metrics-collector` | 5s Prometheus query, push via WS |
| `session-tracker` | Pod lifecycle → session start/end: Knative serving pods (start → removed), the runs of a schedule trigger's CronJob (start → finished) and the pods of preview environments (`runr/preview-of`, a watch of their own; the event names the preview's Service). Events carry the pod's project id (`runr/project-id` / `runr/preview-of`) and organization (`runr/tenant`), which RunR finds the project by: a preview's Service name may have shortened a long slug. Every minute a heartbeat lists the sessions it still sees running (`session_heartbeat`, at most 40 per message); RunR marks them seen and starts the session of a listed pod whose start it never got. No CPU or memory figures: metering counts the deployment's size for the session's time |
| `event-watcher` | OOMKilled, CrashLoop detection |
| `ws-client` | WebSocket to Reverb, exponential backoff |

**Deployment**: K8s Deployment (1 replica), 100m CPU / 128Mi memory
**Auth**: per-region token in the Secret `observability/runr-agent-token`; RunR stores only its SHA-256 hash (`knative_regions.agent_token_hash`) and rejects connections without a token or with an unknown or wrong one
**URL**: `<base>/ws/agent/<regionId>`, base resolved in this order: region setting `agent_ws_url` → in-cluster gateway `ws://runr-gateway.<runr-namespace>.svc.cluster.local` when the region is the cluster RunR runs in (same `kube-system` namespace UID) → `RUNR_AGENT_WS_URL` → `APP_URL` (http→ws, https→wss)

**Upgrade to 0.3.3**: agents deployed by an older release have no stored token hash and are rejected. With the Helm chart's `bootstrap.enabled: true` (default) the Job `runr-region-reconcile-<revision>` of every upgrade runs `agent:redeploy --missing` itself (time-limited, never fails the upgrade). Otherwise redeploy them once:

```
php artisan agent:redeploy --missing        # all active regions without a token hash
php artisan agent:redeploy <regionId>       # one region (also re-resolves the URL)
```

Regions whose cluster runs no agent, or the agent of another region on the same cluster, are skipped unless `--force`.

## Metering

Metering is part of the core and runs on every installation — single-tenant or multi-tenant, with
or without the Billing module. It records **quantities**, never money: the
[Billing module](billing.md) prices them when it is on.

| Component | When | Records |
|---|---|---|
| `UsageAggregationJob` (`usage-aggregation`) | every 5 minutes; the first hour of a day also the day before | one `usage_records` row per project, deployment, region, scale mode, trigger type and day: sessions, CPU (millicore × ms), memory (MiB × ms), duration, cold starts, GPUs and GPU time (GPU × ms). Always-on deployments (scale to zero off or `min_scale` ≥ 1) count `min_scale` × the size they were deployed with for the time they were running that day (from becoming ready until they were replaced or stopped; today until now) and their sessions are covered by that; every other deployment (scale to zero, a schedule trigger's runs) counts its sessions — one pod each, from its start until it is removed or its run finishes — at the size it was deployed with (CPU, memory and GPUs × the session's time), each day for the part of the session that ran on it (a session still running until now; it counts as a session and a cold start on the day it started), under the deployment's trigger type. A queue trigger's consumer is a row of its own (source `queue_consumer`: 50m / 64 MiB for the time the deployment was running), so is each preview environment (source `preview`: its sessions at the project's size; `deployment_id` carries the preview's id). A deployment the [timed warmer](scaling.md#timed-warmer) ran counts the containers the warmer kept warm in each stretch of that time (what it applied, `deployments.sizing.warm_log`), and the sessions that started while it kept none. A project's day covers the deployments of every environment ([Environments](environments.md)); each row carries the `environment_id` of its deployment (empty: production), and a deployment is only replaced by a newer one of the same environment. Model services: one row per service and day (source `model`, with the creator). Runs in the central context and in every tenant's |
| Copies for the platform | same job, tenant contexts | the usage of tenant projects, model services and bindings in **platform** regions is copied to the central `usage_records` / `binding_usage_records` with the tenant's id, so the platform's bills to tenants are calculated from central data |
| `BindingsMaintenanceJob` (`measure`) | hourly | `binding_usage_records`: storage GB-hours and instance hours per binding and day, with the binding's creator ([Bindings](bindings.md#usage-and-billing)) |
| `MeterSandboxUsageJob` | every 5 minutes | central `sandbox_usage_records` per organization, region, creator and day: sandbox seconds, CPU and memory × time ([Sandbox API](sandbox-api.md#quotas-and-usage)) |
| `models:meter` | every minute | `ai_model_service_usage`: replica, CPU, memory and GPU time of model services, aggregated into `usage_records` by the job above |

Every run calculates the day again from what ran on it: each session and each second of a
reservation is counted once however often a project is redeployed, moved to another region or
switched between scale modes and triggers, and running it again gives the same totals. A deployment
records the size it was applied with (`deployments.sizing`) and when it stopped running
(`deployments.ended_at`), so a resize or a first deploy after midnight never changes the day
before — the first-hour run for yesterday uses only the deployments that ran yesterday, with their
size. A recalculated day replaces the rows of the project's existing deployments (and, inside a
tenant, their central copies); the rows of a deployment that was deleted since stay as they were
metered — except a 0.5.7 always-on row (billed as 24 hours) on a day other deployments of the
project ran in its region: it keeps only the part of the day none of them ran, so a redeploy's
deleted old deployment is not counted twice. A 0.5.7 scale-to-zero row of a deleted deployment
stays as it was metered, with its sessions: in the rare case of three deploys of a project on one
day whose middle deployment was deleted, sessions of that row can be counted again in the
recalculated rows of the other two. RunR leaves these rows as they are. Deployments applied before 0.5.8 have no recorded size: a day counts each of them with
the scale mode and size of its own record of that day (what 0.5.7 metered it with); without one, on
today and yesterday with its project's current configuration, on an earlier day with its sessions
only — a recalculation never makes up an always-on reservation from today's configuration. A
0.5.7 scale-to-zero record holds no size: the sessions of such a deployment count with its
project's current configuration and the deployment's own overrides.

`php artisan usage:recalculate FROM [TO]` (Y-m-d, `TO` defaults to today, at most 93 days)
calculates days again the same way, in the central context and in every tenant's, and copies
tenants' usage in platform regions centrally again. Closed bills do not change; open bills read
the new rows.

The cost columns of these tables (`raw_cost`, `final_cost`, `multiplier`) are no longer filled
(0, multiplier 1); `currency` is the installation's `BILLING_CURRENCY`. Rows written by earlier
releases keep their old values. The API does not answer these columns: `GET /api/bindings/{id}/usage`
and `GET /api/sandbox-usage` return quantities, and what usage costs comes from the Billing module
(`GET /api/billing/usage`, the bills). Usage records are kept: they are the input of every bill.

### From metering to money

With billing on, a bill line is quantity × the region's price in effect on the record's day:

```
vCPU-hours = cpu (millicore × ms) / 3.6e9          GB-hours = memory (MiB × ms) / (1024 × 3.6e6)
GPU-hours  = GPU time (GPU × ms) / 3.6e6

project usage cost = (vCPU-hours × cpu_vcpu_hour + GB-hours × memory_gb_hour + GPU-hours × gpu_hour)
                     × multiplier of the trigger type (HTTP, queue, schedule)
                     × (1 + markup %)            (a tenant billing a shared platform region)
```

Bindings are priced per GB-hour of storage (and instance hour of a dedicated Postgres), sandboxes
per vCPU-hour and GB-hour with multiplier 1. Fixed regions bill approved resources per day instead
of the project's own usage; the usage is still metered and shown to administrators next to the
fixed price ([Metering and the fixed bill](billing.md#metering-and-the-fixed-bill)).

## Rightsizing

RunR turns what every running project's pods really used into a size recommendation: "this
function requests 512 MB but used at most 140 MB in the last 14 days → 256 MB is enough" — with
what that saves per month when billing is on — applied with one click. A project that was killed
for memory, throttled or worked close to its memory limit gets a larger size recommended instead.
Nothing changes until a member applies a recommendation, and the new size runs only after the
next deploy.

### Where the peaks come from

The metric snapshots cannot tell what one pod needed — a 1-minute snapshot is the sum over all
pods of a project and after 7 days only averages are kept ([Storage Tiers](#storage-tiers)) — and
metering records reserved sizes and session time, not use. So every night the `rightsizing` job
reads the day before from the region's Prometheus, which keeps 15 days, in one query per project
and day evaluated at the end of the day, and keeps one row per project and day in
`resource_peaks`:

| Value | Source (per pod, the highest of the project's pods) |
|---|---|
| `memory_max_mb`, `memory_p95_mb` | the highest and the 95th percentile of `container_memory_working_set_bytes` over the day |
| `cpu_max_m`, `cpu_p95_m` | the same of the CPU use over 1-minute rates of `container_cpu_usage_seconds_total`, in millicores |
| `throttled_ratio` | throttled ÷ all CFS periods of the day (`container_cpu_cfs_*`) |
| `oom_kills` | cAdvisor's OOM events, or restarts after an `OOMKilled` termination (kube-state-metrics) — the higher of the two |
| `samples`, `pods` | memory samples and distinct pods behind the day |
| `cpu_m`, `memory_mb`, `trigger_type` | the size of the deployment that ran that day (`deployments.sizing`) |

Only the project's own workload container is read — `handler` of code projects, `app` of CaaS — in
the revision pods of its Knative Service (`<slug>-<generation>-deployment-…`) or the pods of its
CronJob (`<slug>-trigger-…`); the queue-proxy, the queue consumer and preview environments are left
out. Pods in a Kata VM report no container of their own and get no recommendation.

The first run after an upgrade reads back the whole window (14 days); a missed night is caught up
by the next one. A day no deployment of the project ran is recorded without a query. A region
whose Prometheus does not answer is skipped for the rest of the run and read again the next night.
Rows older than 30 days are deleted.

### How a recommendation is made

After reading, every running project is compared with its last 14 days (`RightsizingService`):

- **Eligible:** a project with a live deployment, not stopped, without GPUs, in a region that is
  not a demo and has cluster access.
- **Enough data:** at least 3 days with readings, and a scale-to-zero workload at least 20
  sessions (or pods) in the window. Until then the project's state is `collecting`.
- **Smaller:** memory = the highest working set × 1.3, CPU = the 95th percentile × 1.3, each
  rounded up to the next size step — memory 128, 256, 512 MB, 1, 2, 4, 8, 16, 32, 64 GB; CPU 125m,
  250m, 500m, 1, 2, 4, 8, 16, 32 cores (the instance size presets and the powers of two between
  them). A resource is recommended smaller only when that step is below its current size; a
  resource without readings keeps its size. 140 MB × 1.3 = 182 MB → 256 MB.
- **Larger:** an OOM kill, a memory peak at 90 % of the size or 25 % of the CPU periods
  throttled — counted only on days the project ran with its current size — recommend the next step
  up (or more, when the peaks call for more).
- **Admission:** the recommended size is checked like a manual edit (region capacity, the
  allocation of a [fixed-price project](billing.md#what-a-fixed-project-may-use)); a size that would
  be refused is shown with the reason (`blocked`) and cannot be applied.
- **What it frees:** the difference per pod × the pods an always-on workload keeps running
  (one pod of a scale-to-zero workload), and the change of its worst case up to `max_scale`. With
  the Billing module the recommendation also carries the monthly difference
  ([Rightsizing in billing](billing.md#rightsizing)); without it the resources are shown alone.

A project has at most one recommendation, recalculated every night. One that no longer holds is
removed while it is open; a project resized by hand since a recommendation was calculated does not
show it (stale) until the next run calculates it again.

### Applying, dismissing, snoozing

- **Apply** puts the recommended size into the project's config through the same checks and the
  same path as a manual edit and saves a new version (`Rightsizing: memory 512 MB → 256 MB, CPU
  500m → 125m`). Like every config change it reaches the workload with the next deploy; until
  then the project page reminds to deploy.
- **Dismiss** hides a recommendation while the recommended size stays the same; a different size
  shows it again.
- **Snooze** hides it for a number of days (the console: 30).

Both are audited (`project.rightsizing.apply`, `project.rightsizing.dismiss`).

| Where | What |
|---|---|
| Console | a card under the project's header; **/rightsizing** lists the organization's open recommendations, largest monthly saving first, linked from the projects page |
| API | `GET /api/rightsizing` (`metrics.view`, the projects a member may read), `GET /api/projects/{id}/rightsizing` (`metrics.view`), `POST /api/projects/{id}/rightsizing/apply` and `/dismiss` with `snooze_days` (`projects.update`) |
| CLI | `runr projects rightsizing [NAME]`, `runr projects rightsizing apply NAME`, `runr projects rightsizing dismiss NAME [--snooze DAYS]` ([CLI](cli.md#rightsizing)) |
| MCP | `rightsizing_list`, `rightsizing_apply` ([MCP](mcp.md#tools)) |

`GET /api/projects/{id}/rightsizing` answers `status` — `open`, `snoozed`, `dismissed`, `applied`
(with the recommendation in `data`), `collecting` (`collecting`: days and sessions so far and
needed), `right_sized` or `not_eligible` (`reason`: `gpu`, `stopped`, `not_running`,
`unsupported`, `no_metrics`, `disabled`) — and `pending_deploy` for an applied size the running
deployment does not have yet. Applying answers 404 `rightsizing_none` without an open
recommendation, 409 `rightsizing_stale` when the project changed size since, and 422 with the
validation error or the admission code when the size is refused.

### Configuration

Environment variables of the API (Helm: `extraEnv`; Docker Compose: `api.env`):

| Variable | Default | |
|---|---|---|
| `RIGHTSIZING_ENABLED` | `true` | `false`: no peaks are read and no recommendation is calculated |
| `RIGHTSIZING_WINDOW_DAYS` | `14` | the days a recommendation looks back on (at most what Prometheus keeps: 15) |
| `RIGHTSIZING_MIN_DAYS`, `RIGHTSIZING_MIN_SESSIONS` | `3`, `20` | too little data below these (sessions: scale-to-zero workloads) |
| `RIGHTSIZING_MEMORY_MARGIN`, `RIGHTSIZING_CPU_MARGIN` | `1.3`, `1.3` | the safety margin on the observed peak |
| `RIGHTSIZING_MEMORY_BASIS`, `RIGHTSIZING_CPU_BASIS` | `max`, `p95` | which peak the size follows (`max` or `p95`) |
| `RIGHTSIZING_MEMORY_NEAR_LIMIT` | `0.9` | a peak at this share of the memory size asks for more |
| `RIGHTSIZING_CPU_THROTTLED_RATIO` | `0.25` | this share of throttled CPU periods asks for more |
| `RIGHTSIZING_SNOOZE_DAYS` | `30` | a snooze without a number of days |
| `RIGHTSIZING_RETENTION_DAYS` | `30` | days of `resource_peaks` kept (at least the window) |

The size steps are in `config/rightsizing.php`. The job runs daily at 02:10 (`rightsizing`, after
the first-hour metering of the day before), in the central context and in every tenant's.
`php artisan rightsizing:run` runs it now; `--no-collect` only calculates again from the peaks
already read, e.g. after changing a margin.

## Storage Tiers

All tiers live in PostgreSQL (`metric_snapshots`); `DownsampleMetricsJob` rolls rows up hourly
(`MetricsDownsampleTrait`).

| Tier | Age | Granularity |
|------|-----|-------------|
| Hot | 0–7 days | 1m |
| Warm | 7–30 days | 5m (downsampled) |
| Cold | 30–90 days | 1h (downsampled) |
| Archive | 90+ days | 1d (downsampled) |

## Database Tables

### Base App
- `metric_snapshots` — time-series metric storage
- `deployment_sessions` — session tracking (scale-to-zero pods, scheduled runs and preview pods: `preview_environment_id`, no deployment)
- `log_retention_configs` — per-region log retention settings

### Rightsizing (core)
- `resource_peaks` — per project and day: the highest and 95th percentile memory and CPU of one pod,
  OOM kills, throttling, with the size that ran (central schema and every tenant schema)
- `rightsizing_recommendations` — the current recommendation of a project, its evidence, savings
  and the members' decision (same placement)

### Metering (core)
- `usage_records` — daily usage per project and model service (central schema and every tenant
  schema; central rows with a `tenant_id` are copies of tenant usage in platform regions)
- `binding_usage_records` — daily binding usage (same placement)
- `sandbox_usage_records` — daily sandbox usage of every organization (central)

### Billing module
- `billing_prices`, `billing_region_settings`, `billing_region_overrides`, `billing_tenant_region_overrides`, `billing_markups`, `billing_fixed_requests`,
  `billing_bills`, `billing_events`, `billing_settings` ([Billing](billing.md#scheduled-jobs-and-tables))

## Single-tenant installations

Metering, snapshots and downsampling work the same without tenancy: the usage of every project is
recorded in the central schema. Prices and bills appear only with the Billing module
([Billing without tenancy](billing.md#billing-without-tenancy)).

## Cluster Patch

To apply PVC permission fixes to existing clusters:
```bash
docker compose exec api php artisan cluster:patch-observability
# or for a specific region:
docker compose exec api php artisan cluster:patch-observability {regionId}
```

## API Endpoints

### Base App
- `GET /api/projects/{id}/metrics/history?from=&to=&granularity=`
- `GET /api/projects/{id}/metrics/summary?period=7d` — recorded metrics, and the sessions that
  started in the period and ended. Their `total_cpu_ms` (CPU milliseconds: 1 vCPU for 1 s = 1000)
  and `total_memory_mb_ms` (MiB × ms) are what metering counts for them: the deployment's size ×
  the sessions' pod time. The console's metrics history shows the averages in cores, MiB and
  requests per second and the sessions' size × time in vCPU-hours and GiB-hours; its live bar
  shows the current use (cores, MiB / GiB), the use × time since the deployment started (core- and
  MiB-minutes, core- and GiB-hours) and the averages of the last hour. `runr metrics` prints the
  same units, the sessions' size × time as vCPU time and MiB- or GiB-hours. The MCP tool
  `metrics_summary` names each field's unit (`units`)
- `GET /api/rightsizing`, `GET /api/projects/{id}/rightsizing`,
  `POST /api/projects/{id}/rightsizing/apply`, `POST /api/projects/{id}/rightsizing/dismiss`
  ([Rightsizing](#rightsizing))

### Billing module
- prices, region billing types, bills, usage per project and month, the rollup per owner, fixed
  resource requests, events ([Billing API](billing.md#api-reference))
