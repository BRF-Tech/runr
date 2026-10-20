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
| `CloseStaleSessionsJob` | Every 10 min (`close-stale-sessions`): close sessions open for more than 30 min without a reported end, in the central schema and every tenant schema; the pod's real end later corrects a session it closed too early |

### Go Agent (`runtime/agent/`)

| Goroutine | Purpose |
|-----------|---------|
| `metrics-collector` | 5s Prometheus query, push via WS |
| `session-tracker` | Pod lifecycle → session start/end |
| `event-watcher` | OOMKilled, CrashLoop detection |
| `ws-client` | WebSocket to Reverb, exponential backoff |

**Deployment**: K8s Deployment (1 replica), 100m CPU / 128Mi memory
**Auth**: per-region token in the Secret `observability/runr-agent-token`; RunR stores only its SHA-256 hash (`knative_regions.agent_token_hash`) and rejects connections without a token or with an unknown or wrong one
**URL**: `<base>/ws/agent/<regionId>`, base resolved in this order: region setting `agent_ws_url` → in-cluster gateway `ws://runr-gateway.<runr-namespace>.svc.cluster.local` when the region is the cluster RunR runs in (same `kube-system` namespace UID) → `RUNR_AGENT_WS_URL` → `APP_URL` (http→ws, https→wss)

**Upgrade to 0.3.3**: agents deployed by an older release have no stored token hash and are rejected. With the Helm chart's `bootstrap.enabled: true` (default) the post-upgrade `runr-bootstrap` hook runs `agent:redeploy --missing` itself (time-limited, never fails the upgrade). Otherwise redeploy them once:

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
| `UsageAggregationJob` (`usage-aggregation`) | every 5 minutes; the first hour of a day also the day before | one `usage_records` row per project, deployment, region, scale mode, trigger type and day: sessions, CPU (millicore × ms), memory (MiB × ms), duration, cold starts, GPUs and GPU time (GPU × ms). Always-on deployments (scale to zero off or `min_scale` ≥ 1) count `min_scale` × the size they were deployed with for the time they were running that day (from becoming ready until they were replaced or stopped; today until now) and their sessions are covered by that; every other deployment counts its sessions. Model services: one row per service and day (source `model`, with the creator). Runs in the central context and in every tenant's |
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
only — a recalculation never makes up an always-on reservation from today's configuration.

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
- `deployment_sessions` — session tracking (scale-from-zero)
- `log_retention_configs` — per-region log retention settings

### Metering (core)
- `usage_records` — daily usage per project and model service (central schema and every tenant
  schema; central rows with a `tenant_id` are copies of tenant usage in platform regions)
- `binding_usage_records` — daily binding usage (same placement)
- `sandbox_usage_records` — daily sandbox usage of every organization (central)

### Billing module
- `billing_prices`, `billing_region_settings`, `billing_markups`, `billing_fixed_requests`,
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
- `GET /api/projects/{id}/metrics/summary?period=7d`

### Billing module
- prices, region billing types, bills, usage per project and month, the rollup per owner, fixed
  resource requests, events ([Billing API](billing.md#api-reference))
