# Monitoring and cost

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

  → UsageAggregationJob (5min, tenancy)
    → usage_records (tenant table, with cost)
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

### Tenancy Module (cost system)

| Component | Purpose |
|-----------|---------|
| `CostCalculationService` | Scale-to-zero + custom-scale formulas |
| `CapacityDealService` | Capacity deal CRUD + P&L analysis |
| `UsageAggregationJob` | 5min: sessions + metrics → usage_records |
| `ChargeScheduleJob` (`charge-schedule`) | Daily 02:30: the month's capacity-deal and usage charges; months that ended are closed ([Billing](billing.md#charges)) |
| `capacity-deal-expiry` | Hourly: active capacity deals past their end date become `expired` (no more deal charges, out of the P&L) |

### Cost Formulas

**Scale-to-Zero (per session):**
```
cost = (cpu_mc × duration_ms × cpu_price + memory_mib × duration_ms × memory_price) × multiplier
```

**Custom Scale (per day):**
```
cost = (min_scale × cpu_mc × 86,400,000 × cpu_price + min_scale × memory_mib × 86,400,000 × memory_price) × multiplier
```

**Entry Point Multipliers:**
- HTTP: ×5.0 (ingress + LB + TLS overhead)
- Queue: ×2.0 (queue infrastructure)
- Schedule: ×1.0 (CronJob only)

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

### Tenancy Module (central)
- `cost_configs` — pricing configuration per region
- `capacity_deals` — tenant capacity agreements
- `charges`, `billing_purchases`, `billing_events` — what tenants owe per month, purchases recorded from the billing system, billing events ([Billing](billing.md))

### Tenancy Module (per-tenant schema)
- `usage_records` — daily cost records per project

## Non-Tenant Mode

When `TENANCY_ENABLED=false`:
- Metrics collection + history works normally
- Cost routes are not registered
- Frontend hides cost columns
- SnapshotMetricsJob + DownsampleMetricsJob still run

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

### Tenancy Module
- `GET/POST /api/cost-configs`
- `GET /api/tenants/{id}/billing/summary`
- `GET /api/tenants/{id}/billing/usage`
- `GET/POST/PATCH /api/tenants/{id}/capacity-deals`
- `GET /api/regions/{id}/profit-loss`
- the billing API `/api/billing/…` — charges, usage, plans, purchases, events ([Billing](billing.md#billing-api))
