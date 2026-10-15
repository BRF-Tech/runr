# Observability

## Overview

RunR installs its observability stack as part of every Knative Serving installation on a region (`KnativeInstallService::installServing()`); no user action is needed. A region can instead reuse an existing in-cluster Prometheus or Loki (`prometheus_managed` / `loki_managed` = `false`); RunR then skips that install and reads the configured Service.

All components run in the `observability` namespace of the region's cluster.

On top of metrics and logs, RunR provides:

| Feature | Where | Default |
|---------|-------|---------|
| [Tracing backend](#tracing-backend) — OpenTelemetry Collector + Grafana Tempo per region | installed with Knative Serving | on (mode `managed`) |
| [Auto-instrumentation](#auto-instrumentation) — a server span per invocation, language instrumentation of the built-in images | project switch "Tracing" | off |
| [Traces tab and API](#traces-tab-and-api) — search, waterfall, logs of a trace | project → Traces | — |
| [Request replay](#request-replay) — capture of failed requests, replay with a response diff | project switch "Request replay" | off |
| [Uptime checks](#uptime-checks) — synthetic HTTP checks with incidents | project → Uptime | — |
| [Status page](#status-page) — public page per organization | Settings → Status page | private |
| [Alerting](#alerting) — channels, rules, delivery log | Settings → Alerts | — |

---

## Components

### 1. cAdvisor (metrics collector)

**Kind:** DaemonSet — one pod per node
**Image:** `gcr.io/cadvisor/cadvisor:v0.49.2`
**Namespace:** `observability`

| Parameter | Value |
|-----------|-------|
| Housekeeping interval | 1s |
| Enabled metrics | cpu, cpuLoad, memory, oom_event |
| CPU request/limit | 150m / 300m |
| Memory request/limit | 200Mi / 400Mi |

**Details:**
- `--containerd=<socket>` — the containerd socket is set explicitly and detected per distro from the nodes' `containerRuntimeVersion` (`/run/containerd/containerd.sock` for kubeadm/managed Kubernetes, `/run/k3s/containerd/containerd.sock` for k3s/rke2)
- `tolerations: [{operator: Exists}]` — tolerates every taint, including `runr.dev/dedicated`
- `hostPID: true`, `SYS_PTRACE` capability (all others dropped) — needed to read cgroups
- The kube-prometheus-stack kubelet cAdvisor scrape is disabled; this DaemonSet replaces it

**Metrics produced:**
```
container_cpu_usage_seconds_total
container_memory_working_set_bytes
```

---

### 2. Prometheus (metrics store)

**Helm chart:** `prometheus-community/kube-prometheus-stack`
**Release:** `knative`
**Namespace:** `observability`

| Parameter | Value |
|-----------|-------|
| Retention | 15 days |
| Storage | 50Gi PVC (the region's registry StorageClass when set, otherwise the cluster default) |
| AlertManager | Disabled |
| Grafana | Disabled |

**Scrape targets:**
- cAdvisor → PodMonitor CRD (`cadvisor-runr`) — 1s interval
- Knative Serving pods (container port named `metrics`) → `additionalScrapeConfigs` job `knative-serving` — 15s interval

Knative Serving is configured to export Prometheus metrics (`config-observability` ConfigMap, `metrics-protocol: prometheus`).

**Access:** in-cluster ClusterIP Service only — `prometheus_url = http://prometheus-operated.observability:9090`. Prometheus is not exposed outside the cluster (a `prometheus-nodeport` Service left by older installs is deleted).

---

### 3. Prometheus recording rules

**CRD:** `PrometheusRule/runr-metrics`
**Labels:** `release: knative`, `app.kubernetes.io/part-of: runr`

#### runr.instant (2s interval)

| Metric | PromQL |
|--------|--------|
| `runr:cpu_cores:rate2m` | `sum by (namespace, pod, container) (irate(container_cpu_usage_seconds_total{namespace!=""}[30s]))` |
| `runr:memory_mb:current` | `sum by (namespace, pod, container) (container_memory_working_set_bytes{namespace!=""}) / 1048576` |

- `sum by(...)` → collapses duplicate label sets (cAdvisor can report the same container under several label sets)
- `namespace!=""` → filters out non-Kubernetes system containers
- `irate([30s])` → uses the last two samples
- Quick API queries do not use these rules; they query the raw metrics (see Data flow)

#### runr.averages (1m interval)

| Metric | PromQL |
|--------|--------|
| `runr:cpu_cores:avg1h` | `avg by (namespace, pod, container) (avg_over_time(runr:cpu_cores:rate2m[1h]))` |
| `runr:memory_mb:avg1h` | `avg by (namespace, pod, container) (avg_over_time(runr:memory_mb:current[1h]))` |

---

### 4. PodMonitor (scrape configuration)

**CRD:** `PodMonitor/cadvisor-runr`

```
interval:      1s
scrapeTimeout: 1s
honorLabels:   true
```

**relabelings:** `__meta_kubernetes_pod_node_name` → `node`

**metricRelabelings:**
- `container_label_io_kubernetes_pod_namespace` → `namespace`
- `container_label_io_kubernetes_pod_name` → `pod`
- `container_label_io_kubernetes_container_name` → `container`
- empty container name with a pod name → `container="kata"` (cAdvisor cannot see inside Kata VM-isolated containers)
- `container_label_io_kubernetes_pod_name =~ .+` → keep (drops non-pod system cgroups)

---

### 5. Loki Stack (log aggregation)

**Helm chart:** `grafana/loki-stack`
**Release:** `loki-stack`
**Namespace:** `observability`

| Component | Parameter | Value |
|-----------|-----------|-------|
| Loki | Storage | 20Gi PVC (filesystem) |
| Loki | CPU request/limit | 100m / 500m |
| Loki | Memory request/limit | 128Mi / 512Mi |
| Promtail | Tolerations | `[{operator: Exists}]` — every node |
| Promtail | CPU request/limit | 50m / 200m |
| Promtail | Memory request/limit | 64Mi / 128Mi |

**Important:** with `tolerations: [{operator: Exists}]` Promtail also runs on worker nodes tainted `runr.dev/dedicated`; without it, project pod logs on those nodes would not be collected.

**Retention:** Loki's own retention period is effectively unlimited (`876000h`); the compactor runs with deletes enabled so that `LogRetentionService` can remove log streams older than the region's `retention_days` through the Loki delete API (`loki/api/v1/delete`), keeping the last N deployments per project.

**Loki status is tracked in the DB:**
- `loki_status: installing` → install started
- `loki_status: ready` → Loki pod is Running, logs are queryable

---

### 6. Observability HA

**PriorityClass:** `observability-critical`

```
value:            1_000_000
preemptionPolicy: Never
```

Observability pods are not evicted under node memory pressure; with `Never` preemption they do not preempt user pods either.

**PodDisruptionBudgets:**

| PDB | Selector | minAvailable |
|-----|----------|-------------|
| `prometheus-pdb` | `app.kubernetes.io/name: prometheus` | 1 |
| `loki-pdb` | `app: loki` | 1 |

During node drains and rolling upgrades at least one pod of each stays up. Both are single-replica setups; the PDBs protect them from voluntary disruption.

---

## Data flow — metrics

```
Container
    │
    ▼
cAdvisor DaemonSet (housekeeping: 1s)
    │  container_cpu_usage_seconds_total
    │  container_memory_working_set_bytes
    ▼
Prometheus (scrape: 1s, via PodMonitor)
    │
    ├─ [Quick]     last_over_time(rate(...[30s])[30s:5s]) — raw query, no rule dependency
    │
    └─ [Avg / 1h]  Recording rules (eval: 2s)
                   │  runr:cpu_cores:rate2m  (irate[30s])
                   │  runr:memory_mb:current
                   ▼
                   runr:cpu_cores:avg1h  (1m eval)
                   runr:memory_mb:avg1h
    ▼
RunR API — SWR cache (quick: refreshed when older than 1s / full: 60s)
    │  1. Direct HTTP to the in-cluster Service (only when the region is RunR's own cluster)
    │  2. Kubernetes API service proxy of the region's cluster
    ▼
Vue UI (quick metrics poll: 2s, 1h averages poll: 60s)
```

`GET /api/projects/{p}/deployments/{d}/metrics?quick=1` returns the quick set (cpu, memory, rps); without `quick` it adds the 1h averages. Schedule-triggered projects use a 6m window instead of 30s, since their CronJob pods are short-lived.

---

## Data flow — logs

```
Container stdout/stderr
    │
    ▼
Promtail DaemonSet (every node)
    │  tails /var/log/pods/*
    ▼
Loki (push)
    │
    ▼
RunR API (KnativeLogService)
    ├─ 1. Direct HTTP → loki_url (only when the region is RunR's own cluster)
    ├─ 2. Kubernetes API service proxy → loki-stack:3100
    └─ 3. kubectl logs fallback (Loki not ready, unreachable, or no lines on first load)
    │
    ▼
Vue UI (poll: 1s, incremental ?since=<ms>)
```

**Incremental log fetching:** the first load returns a 24-hour window (up to 2000 lines). Later polls send `since=<last_ts_ms>` and receive only newer lines.

**LogQL query:**
```
{namespace="<project-ns>", pod=~"<slug>-.*"}
```
The container filter is applied in the API (`handler`, or `app` for `caas` projects); queue-proxy logs are not shown. For an operator-supplied multi-tenant Loki, `loki_org_id` is sent as `X-Scope-OrgID`.

---

## API access

RunR reads a region's Prometheus and Loki as in-cluster Services on the region's own cluster (`MonitoringEndpoint`). Operator-supplied URLs must have the form `http(s)://<service>.<namespace>.svc[.cluster.local]:<port>[/path]`; the central API never fetches an arbitrary URL.

| Order | Method | When |
|-------|--------|------|
| 1 | Direct HTTP to `<scheme>://<service>.<namespace>.svc:<port>/<path>` | Region is the cluster RunR runs in |
| 2 | Kubernetes API service proxy (`/api/v1/namespaces/{ns}/services/{scheme}:{svc}:{port}/proxy/...`) | Any region; also the fallback when direct HTTP fails |
| 3 | `kubectl logs` | Logs only, when Loki returns nothing usable |

---

## Install flow

Inside `KnativeInstallService::installServing()`, in order:

```
1. configureKnativeMetrics()   → config-observability: metrics-protocol=prometheus

2. installPrometheus()         (prometheus_managed)
   ├─ helm install kube-prometheus-stack
   ├─ deployCAdvisor() → ServiceAccount, ClusterRole, ClusterRoleBinding, DaemonSet, PodMonitor, PrometheusRule
   └─ resolvePrometheusUrl() → http://prometheus-operated.observability:9090
   — or, for a reused Prometheus with Prometheus Operator CRDs:
   applyScrapeResourcesForExternalPrometheus() → cAdvisor, PodMonitors, runr:* rules
     (label app.kubernetes.io/part-of=runr; the operator's Prometheus must select them)

3. installLoki()               (loki_managed)
   └─ helm install loki-stack (Loki + Promtail, tolerations: Exists)

4. applyObservabilityHA()      (when Prometheus or Loki is managed)
   ├─ PriorityClass: observability-critical
   ├─ PDB: prometheus-pdb
   └─ PDB: loki-pdb

5. deployAgent()               → runr-agent (see monitoring.md)

6. DB update
   ├─ prometheus_url = http://prometheus-operated.observability:9090
   ├─ loki_url       = http://loki-stack.observability.svc.cluster.local:3100
   └─ loki_status    = 'installing' (managed) / 'ready' (reused)

7. TracingInstallService::installWithServing()   (observability.tracing.installWithServing)
   └─ OpenTelemetry Collector + Tempo for the region's tracing mode (see Tracing backend);
      a failed tracing install is logged on the Serving component, Serving stays installed
```

`KnativeStatusService` sets `loki_status = 'ready'` once the Loki pod is Running.

`php artisan cluster:patch-observability {regionId?}` re-applies the Prometheus and Loki Helm values on existing regions.

---

## Tracing backend

Every region has a tracing mode (Settings → Tracing, `PUT /api/observability/regions/{region}/tracing`).
Regions without their own setting use `observability.tracing.defaultMode` (Helm).

| Mode | Collector | Tempo | Trace search in RunR |
|------|-----------|-------|----------------------|
| `managed` | installed | installed by RunR (`tempo` release) | yes |
| `reuse` | installed, exports to an existing in-cluster Tempo (`tempo_otlp_endpoint`; `tempo_org_id` sent as `X-Scope-OrgID`) | the operator's; RunR queries `tempo_url` | yes |
| `external` | installed, exports OTLP/HTTP to `export_endpoint` (https) with optional `export_headers` | none | no — the traces are read in the external backend |
| `off` | removed | removed (managed) | no |

Function pods always send to RunR's collector, whatever the mode, so a mode change needs no
redeploy of projects. Export headers (API keys of a hosted backend) are stored encrypted, put into
the Secret `otel-collector-export` and referenced from the collector as environment variables;
the API returns their names only (`export_header_names`).

Status: `installing` → `ready` once the collector (and the managed Tempo) pods are Running and
ready (`observability:run tracing-status`, every minute), or `error` with `status_message`.
Projects can switch tracing on only in a region whose backend is `ready`.

### Components

| Component | Release / chart | Details |
|-----------|-----------------|---------|
| OpenTelemetry Collector | `otel-collector` / `open-telemetry/opentelemetry-collector` chart 0.175.1 (collector 0.161.0, contrib image), Deployment, 1 replica | receivers OTLP gRPC 4317, OTLP HTTP 4318, Zipkin 9411; `memory_limiter`, `batch`, `resource/runr` (adds `runr.region`), Kubernetes attributes preset; requests 100m / 128Mi, limits 500m / 512Mi; PriorityClass `observability-critical` |
| Span metrics | collector `spanmetrics` connector → Prometheus exporter on 8889 | histogram buckets 5 ms – 30 s, dimension `runr.cold_start`, flushed every 15 s; scraped through `PodMonitor/otel-collector-spanmetrics` when the Prometheus Operator CRDs exist |
| Grafana Tempo | `tempo` / `grafana-community/tempo` chart 3.1.0 (Tempo 3.1.0, single binary; repository `https://grafana-community.github.io/helm-charts`, where the chart moved from `grafana/helm-charts`) | OTLP 4317 / 4318, HTTP API 3200, filesystem PVC (`tempoStorage`, default 10Gi, region registry StorageClass when set), retention `tempoRetentionHours` (default 72 h, per region `retention_hours`); requests 100m / 256Mi, limits 1 CPU / 1Gi |

Function pods export to `http://otel-collector.observability.svc.cluster.local:4318`; RunR queries
Tempo at `http://tempo.observability.svc.cluster.local:3200` (managed) through the same
direct / Kubernetes API service proxy chain as Prometheus and Loki ([API access](#api-access)).

### Knative spans

The activator and queue-proxy spans go to the collector as well, so a trace shows the time a
request waited for a cold start. RunR merges into `KnativeServing.spec.config`:

- `observability` (Knative ≥ 1.18): `tracing-protocol: http/protobuf`,
  `tracing-endpoint: http://otel-collector.observability.svc.cluster.local:4318/v1/traces`,
  `tracing-sampling-rate`
- `tracing` (older Knative): `backend: zipkin`,
  `zipkin-endpoint: http://otel-collector.observability.svc.cluster.local:9411/api/v2/spans`, `sample-rate`

The rate is the region's `knative_sample_rate` (default `observability.tracing.knativeSampleRate`,
0.1). Function spans sample at the project's own ratio: the universal wrapper follows a sampled
parent and otherwise decides by trace id, so a Knative hop that did not sample does not switch a
project's tracing off. Mode `off` sets `tracing-protocol: none` / `backend: none`.

### Commands

```
php artisan cluster:install-tracing {regionId?} {--mode=managed|reuse|external|off}
```

installs (or reconfigures) the backend of one region or of every Knative region;
`POST /api/observability/regions/{region}/tracing/install` queues the same install
(`InstallTracingJob`), `DELETE /api/observability/regions/{region}/tracing` removes it.

---

## Auto-instrumentation

Tracing is switched on per project (project → Traces → "Tracing", or
`PUT /api/projects/{p}/observability/tracing` `{enabled, sample_ratio}`) and applies with the next
deploy (`requires_redeploy` in the response). With tracing off the pod spec carries no tracing
variables and the images start as before.

With tracing on, `ObservabilityPodSpec` adds to the function container:

| Variable | Value |
|----------|-------|
| `RUNR_TRACING`, `RUNR_OTEL_AUTO` | `1` |
| `OTEL_SERVICE_NAME`, `RUNR_PROJECT` | the project slug |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | the collector (OTLP/HTTP), `OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf` |
| `OTEL_TRACES_EXPORTER=otlp`, `OTEL_METRICS_EXPORTER=none`, `OTEL_LOGS_EXPORTER=none` | spans only |
| `OTEL_PROPAGATORS` | `tracecontext,baggage` (W3C) |
| `OTEL_TRACES_SAMPLER`, `OTEL_TRACES_SAMPLER_ARG` | `parentbased_traceidratio` behind the wrapper (CaaS: `traceidratio`), the project's ratio (default 1) |
| `OTEL_RESOURCE_ATTRIBUTES` | `runr.project_id`, `runr.project`, `runr.tenant`, `runr.runtime`, `runr.version`, `runr.service_type`, `service.namespace=runr` |
| `OTEL_PYTHON_LOG_CORRELATION=true`, `OTEL_NODE_ENABLED_INSTRUMENTATIONS`, `OTEL_NODE_RESOURCE_DETECTORS` | language settings |

Variables the project sets itself win over these.

### Server span of the universal wrapper

Every code runtime runs behind the [universal wrapper](runtimes.md#the-universal-wrapper) when
tracing or replay is on: custom runtimes already do, built-in http runtimes are fronted by it in
observed http mode (the wrapper listens on `$PORT`, the runtime's server on `$PORT + 1`). The
wrapper creates one SERVER span per invocation, continues an incoming `traceparent`, passes its
own context to the runtime (`traceparent` request header; `TRACEPARENT` for schedule runs) and
returns `traceresponse`. Span attributes:

| Attribute | Value |
|-----------|-------|
| `runr.project`, `runr.revision`, `runr.runtime` | project slug, Knative revision (`K_REVISION`), runtime id |
| `runr.trigger` | `http`, `queue` or `schedule` |
| `runr.cold_start` | `true` for the first invocation of the pod |
| `runr.status` | `ok`, `error` (5xx) or `timeout` |
| `faas.trigger`, `faas.coldstart`, `http.request.method`, `url.path`, `http.response.status_code` | OpenTelemetry semantic conventions |

A 5xx or a timeout marks the span as error and adds an `exception` event with the handler's
error output. Spans are batched (up to 256 per request, every second, queue of 2048) and sent as
OTLP/HTTP JSON; the wrapper uses the Go standard library only.

### Built-in runtimes

| Runtime | Instrumentation (only with `RUNR_OTEL_AUTO=1`) |
|---------|-----------------------------------------------|
| Python | `entrypoint.sh` runs the server under `opentelemetry-instrument` (`opentelemetry-distro`, OTLP/HTTP exporter, instrumentations for urllib, urllib3, requests, httpx, logging, redis, psycopg2, sqlite3, installed in `/app/otel`); the handler runs in the wrapper's trace context, so its outgoing calls become child spans |
| Node.js | `entrypoint.sh` adds `--require @opentelemetry/auto-instrumentations-node/register` to `NODE_OPTIONS`; `OTEL_NODE_ENABLED_INSTRUMENTATIONS` limits it to `http,undici,dns,net,ioredis,redis,pg,mysql2,mongodb` (`OBS_NODE_INSTRUMENTATIONS`) |
| Other runtimes, custom runtimes | the wrapper's server span; their own OpenTelemetry SDK reads the standard `OTEL_*` variables |
| CaaS containers | the `OTEL_*` variables for the container's own SDK |
| WebAssembly functions ([Wasm](wasm.md)) | the `OTEL_*` variables for Spin's own OpenTelemetry export; they run under the runwasi shim, so the wrapper does not front them and request replay does not apply |

The instrumentation packages are part of the images (build argument `OTEL=0` builds without them)
and are loaded only when tracing is on.

### Traces and logs

With tracing on the wrapper writes one access line per invocation to the function's log:

```
[runr-wrapper] trace_id=4bf92f3577b34da6a3ce929d0e0e4736 span_id=00f067aa0ba902b7 sampled=true GET /orders 500 41.3ms cold_start=false
```

Python's logging instrumentation adds `otelTraceID` / `otelSpanID` to log records. The trace
view's "Logs" panel queries Loki for the project's lines that contain the trace id
(`{namespace=…, pod=~"<slug>-.*"} |= "<trace id>"`) from one minute before the trace to five
minutes after it.

---

## Traces tab and API

Project → **Traces** (permission `traces.view`): filters for status (all / error / ok), minimum
duration, path substring and time range (15 min – 7 days); the list shows start time, service
and root span, path, status, duration and cold start; a trace opens as a waterfall (spans indented by parent,
bars on the trace's time axis, errors marked) with the attributes, events, error and resource of
each span, and the trace's log lines.

| Method | Path | |
|--------|------|-|
| GET | `/api/projects/{p}/traces?status=&min_duration_ms=&path=&from=&to=&limit=` | search (TraceQL on the project's `runr.project_id`; `from` / `to` unix seconds, max range `search_max_hours` = 168 h, `limit` ≤ 200) |
| GET | `/api/projects/{p}/traces/{traceId}` | one trace, normalized spans (hex ids, kind, status, attributes, events, resource) |
| GET | `/api/projects/{p}/traces/{traceId}/logs` | log lines with the trace id (also needs `logs.view`) |

Queries run server-side against the region's Tempo; a trace id of another project answers 404.
`409 not_queryable` — the region's mode has no Tempo for RunR (external / off) or it is not
ready; `503` — Tempo did not answer. The three routes answer `Cache-Control: no-store, private`
without an `ETag`: spans keep arriving, so neither the API's response cache nor a proxy keeps a
trace (or its 404) ([API caching](architecture.md#api-caching-and-request-bodies)).

---

## Request replay

Opt-in per project (project → Replay → "Capture failed requests", or
`PUT /api/projects/{p}/observability/replay`), code runtimes with http or queue triggers, applies
with the next deploy. The universal wrapper posts every failed request — HTTP status ≥ 500 or a
timeout — to RunR (`POST /api/internal/observability/replay/{key}`, a per-project HMAC token that
changes whenever capture is switched on again).

| Setting | Default | Limit |
|---------|---------|-------|
| `max_body_bytes` | 65536 | 1 MiB (`max_body_bytes_ceiling`); request and response bodies are cut there (`body_truncated`) |
| `retention_days` | 7 | 30 |
| `redact_headers`, `redact_query` | — | 50 names each, on top of the built-in lists |
| captures per project | 500 (`maxCaptures`) | oldest removed first |
| captures per pod | 60 / minute | |

Redaction happens in the wrapper, before a capture leaves the pod, and again in the API:

- never captured: `Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`,
  `X-Auth-Token`, `X-CSRF-Token`, `X-XSRF-Token`, `X-Amz-Security-Token`,
  `X-Goog-IAP-JWT-Assertion`, hop-by-hop headers, every header whose name ends like a secret
  (the rule of secret environment variables: `…token`, `…secret`, `…password`, `…api_key`,
  `…access_key`, `…credentials`, …) and the project's own list; the capture records their names
  (`redacted_headers`)
- query values of `access_token`, `api_key`, `apikey`, `password`, `secret`, `token` and the
  project's own list are replaced by `REDACTED`
- headers, bodies and replay results are stored encrypted

**Replay** (`POST /api/projects/{p}/replays/{id}/replay`, permission `replay.manage`) re-sends the
request to the project's endpoint (`https://<slug>.<knative domain>`), which serves the current
revision. Redacted headers are not sent; headers needed for the replay (for example a test
`Authorization`) can be given for that replay only (`headers`, never stored), as can a different
`query`. The request carries `X-RunR-Replay: 1` and is never captured again. The response is
compared with the original: status, added / removed / changed headers (volatile ones such as
`Date` ignored) and a line diff of the bodies (JSON pretty-printed first).

**Privacy.** Captures hold request bodies and the headers left after redaction, which can include
personal data. Capture is off by default; switch it on only where storing failed requests is
acceptable, add the project's own sensitive headers and query parameters to the redaction lists,
keep the retention short and delete captures (one, or all of a project) once they are no longer
needed. Captures are removed with their project and after their retention
(`observability:run prune`, daily).

---

## Uptime checks

Project → **Uptime** (`uptime.manage` to change, `metrics.view` to read). A check probes either a
path on the project's endpoint or an absolute `url`.

| Field | Default | |
|-------|---------|-|
| `path` / `url` | `/` | absolute URLs on private addresses are refused unless `uptime.allowPrivateTargets` |
| `method` | `GET` | `GET`, `HEAD`, `POST`; optional `headers` (stored encrypted) |
| `interval_seconds` | 60 | 30 – 3600 (`uptime.minIntervalSeconds`) |
| `timeout_seconds` | 10 | ≤ 60 |
| `expected_status` | `200-399` | `200`, `200,204`, `200-399`, `2xx` |
| `body_regex` | — | RE2-compatible pattern the body must match |
| `locations` | `control_plane` | `control_plane` (the RunR API probes) or `regions` (the runr-agent of each listed region, `region_ids`; empty: the project's region) |
| `failure_threshold` / `recovery_threshold` | 3 / 2 | consecutive results that change the state |

State per location: down after `failure_threshold` consecutive failures, up after
`recovery_threshold` consecutive successes (the first success of a new check is up at once). The
check is down while any location is down and up when every location is up. Down opens an
incident and fires the `uptime_down` alert rules; up resolves the incident and the alert. An
incident's title and message can be edited (`PATCH /api/projects/{p}/uptime-incidents/{id}`) and
appear on the status page.

The control plane probes due checks every 30 seconds (`observability:run uptime`, parallel
requests). Region agents fetch their checks (`GET /api/internal/observability/agent/{region}/uptime-checks`)
and post results (`POST …/uptime-results`) with the agent token; the agent finds RunR's API
through `RUNR_API_URL` or its WebSocket URL, and `RUNR_UPTIME_DISABLED=true` switches its prober
off. The UI shows the uptime % of 24 h / 7 d / 30 d, the average response time, daily bars of
the last 30 days, the latest results and the incidents. Results are kept `resultsRetentionDays` (90).

---

## Status page

One public page per organization (Settings → **Status page**, `status_page.manage`): slug, title,
description, logo, optional custom domain, incident history on / off and its length (≤ 90 days).
Components are the uptime checks with "show on status page" (own display name and order). The
page shows the overall state, each component's state and daily uptime bars, and the incident
history. It uses the organization's brand (product name, colours, logo) when
[white-label](white-label.md) branding is set.

- `https://<console host>/status/<slug>` — no sign-in; only pages switched public
- custom domain — point it (CNAME) at the console host and add it to the console's ingress and
  certificate; the page then answers at `https://<custom domain>/status`
- API: `GET /api/public/status-pages/{slug}`, `GET /api/public/status-pages?domain=<host>`
  (no authentication, 60 requests / minute); the payload contains components and incidents only.
  It answers `Cache-Control: public, max-age=30` (with an `ETag`), so a proxy or CDN in front of
  the console may cache it for 30 seconds

---

## Alerting

Settings → **Alerts** (`alerts.manage`).

### Channels

| Type | Configuration | Sent as |
|------|---------------|---------|
| `email` | up to 20 addresses | mail (`AlertMail`) |
| `slack` | incoming webhook URL | Block Kit message |
| `teams` | incoming webhook / workflow URL | Adaptive Card |
| `telegram` | bot token, chat id | `sendMessage` (HTML) |
| `webhook` | URL, optional signing secret | JSON |

Webhook URLs, bot tokens and signing secrets are stored encrypted and never returned: the API
shows the host of a URL and whether a secret is set. URLs on private addresses are refused unless
`alerts.allowPrivateTargets`. "Send test" delivers a test message at once and records the result
on the channel.

Webhook requests carry `X-RunR-Event` (`alert.firing`, `alert.resolved`, `test`),
`X-RunR-Timestamp`, `X-RunR-Alert-Id` and, with a secret,
`X-RunR-Signature: sha256=<hex HMAC-SHA256(secret, "<timestamp>.<raw body>")>`. The body:

```json
{"event": "alert.firing", "id": "01J…", "type": "error_rate", "title": "…", "text": "…",
 "severity": "critical", "status": "firing", "fields": {"project": "orders", "value": "12.5 %"},
 "url": "https://console.example.com/projects/…", "fired_at": "…", "sent_at": "…"}
```

### Rules

| Type | Fires when | Params |
|------|-----------|--------|
| `resource_threshold` | a region's CPU / memory reservation crosses the built-in 60 % / 80 % levels | `resource` (cpu, memory, any), `min_level` (warning, critical); optional region |
| `uptime_down` | an uptime incident opens (resolves with it) | optional project |
| `error_rate` | share of 5xx / failed invocations above `threshold` % | `threshold`, `window_minutes`, `min_requests` |
| `p95_latency` | 95th percentile duration above `threshold_ms` | `threshold_ms`, `window_minutes`, `min_requests` |
| `cold_start_rate` | share of cold starts above `threshold` % | `threshold`, `window_minutes`, `min_requests` |

The last three read the span metrics of the region's Prometheus (traced projects) every minute
(`observability:run alerts`) and resolve when the value is back under the threshold. Every rule
has a severity (`info`, `warning`, `critical`), a cooldown (default 15 minutes) and the channels
it routes to. Alerts are deduplicated by rule and subject (project, region + resource, uptime
check): a still-firing alert is sent again only after the cooldown; a resolve is always sent.

### Delivery

Every alert creates one delivery per channel, sent by a queued job with up to 4 tries
(`alerts.deliveryTries`) and backoff 30 s / 2 min / 10 min. The delivery log
(`GET /api/observability/alert-deliveries`) shows status, attempts, the last error and the
response code; a failed delivery can be retried (`POST …/alert-deliveries/{id}/retry`). Alert
events (`GET /api/observability/alert-events`) list firing and resolved alerts. Deliveries are
kept 30 days.

---

## MCP tools

The RunR MCP server ([AI agents](mcp.md)) gives agents traces, request replay and uptime
checks: `observability_get` / `observability_update`, `traces_search`, `trace_get` (span tree
and correlated logs), `replays_list`, `replay_get`, `replay_run`, `uptime_checks_list`,
`uptime_check_create`, `uptime_check_update`, `uptime_check_delete`, `uptime_check_run`,
`uptime_check_results`, `uptime_incidents_list`, alert channels (`alert_channels_list`,
`alert_channel_create`, `alert_channel_update`, `alert_channel_delete`, `alert_channel_test`),
alert rules (`alert_rules_list`, `alert_rule_create`, `alert_rule_update`, `alert_rule_delete`),
`alert_events_list`, and the status page (`status_page_get`, `status_page_update`) — through the
routes above, with the same permissions.

## Permissions

| Permission | Scope | Allows |
|------------|-------|--------|
| `traces.view` | project | Traces tab, trace search and detail (trace logs also need `logs.view`) |
| `replay.manage` | project | captured requests: list, read, replay, delete |
| `uptime.manage` | project | create, change, run and delete uptime checks, edit incidents (includes `metrics.view`) |
| `alerts.manage` | organization | alert channels, rules, events, delivery log |
| `status_page.manage` | organization | the public status page |
| `regions.observability.manage` | organization | region tracing mode, install, uninstall (includes `regions.view`) |

Switching tracing or replay capture on for a project needs `projects.update`; reading the region
tracing settings needs `regions.view`. Built-in roles: Developer has `traces.view`,
`replay.manage`, `uptime.manage`; Viewer has `traces.view`; Owner and Admin have all. See
[permissions.md](permissions.md).

## Audit log

Recorded actions (never with secret values; channel changes record the changed field names):
`region.tracing.update`, `region.tracing.install`, `region.tracing.uninstall`,
`project.tracing.update`, `project.replay.update`, `replay.replay`, `replay.delete`,
`replay.delete_all`, `uptime_check.create`, `uptime_check.update`, `uptime_check.delete`,
`uptime_check.run`, `uptime_incident.update`, `status_page.update`, `alert_channel.create`,
`alert_channel.update`, `alert_channel.delete`, `alert_channel.test`, `alert_rule.create`,
`alert_rule.update`, `alert_rule.delete`, `alert_delivery.retry`. The internal ingest routes
(replay captures from function pods, uptime results from region agents) are machine traffic and
are not audited.

## Configuration

Helm values (`observability.*`, rendered into the API's `OBS_*` variables):

| Value | Default | |
|-------|---------|-|
| `tracing.defaultMode` | `managed` | mode of regions without their own setting |
| `tracing.installWithServing` | `true` | install the backend with Knative Serving |
| `tracing.knativeSampleRate` | `0.1` | activator / queue-proxy sampling |
| `tracing.tempoRetentionHours` | `72` | |
| `tracing.tempoStorage` | `10Gi` | Tempo PVC |
| `tracing.collectorChartVersion`, `tracing.tempoChartVersion` | `0.175.1`, `3.1.0` | chart versions (the ones `runtime/ci.sh test-observability` runs); `""` installs the newest |
| `replay.maxBodyBytes`, `replay.retentionDays`, `replay.maxCaptures` | `65536`, `7`, `500` | project defaults / limit |
| `replay.ingestUrl` | `""` | RunR's base URL as seen from region pods; empty: derived from the region's agent URL |
| `uptime.minIntervalSeconds`, `uptime.resultsRetentionDays` | `30`, `90` | |
| `uptime.allowPrivateTargets`, `alerts.allowPrivateTargets` | `false` | allow private addresses for absolute check URLs / alert URLs |
| `statusPage.enabled` | `true` | |
| `alerts.deliveryTries` | `4` | |

Further settings are environment variables of `api/config/observability.php`, set via `extraEnv`
(for example `OBS_TRACE_SEARCH_MAX_HOURS`, `OBS_REPLAY_PER_MINUTE`, `OBS_ALERT_DELIVERY_BACKOFF`,
`OBS_NODE_INSTRUMENTATIONS`, `OBS_SPANMETRICS_*`).

Scheduled tasks (`ObservabilityServiceProvider`): `observability:run uptime` every 30 s,
`observability:run alerts` and `observability:run tracing-status` every minute,
`observability:run prune` daily.

---

## Related files

| File | Responsibility |
|------|----------------|
| `Services/Concerns/KnativeCAdvisorTrait.php` | cAdvisor manifests, PodMonitor, PrometheusRule |
| `Services/Concerns/KnativePrometheusTrait.php` | Prometheus Helm install, ClusterIP URL, scrape resources for a reused Prometheus |
| `Services/Concerns/KnativeLokiTrait.php` | Loki Stack Helm install and values |
| `Services/Concerns/KnativeObservabilityHATrait.php` | PriorityClass + PodDisruptionBudgets |
| `Services/Helpers/ManagedLoki.php` | Loki release/chart constants, delete-API config |
| `Services/Helpers/MonitoringEndpoint.php` | Parse in-cluster Service URLs, direct/proxy URLs |
| `Services/KnativeMetricsService.php` | Prometheus queries, SWR cache, direct/proxy routing |
| `Services/KnativeLokiService.php` | Loki queries, direct/proxy chain |
| `Services/KnativeLogService.php` | Log endpoint orchestration, `since` parameter, kubectl fallback |
| `Services/KnativeStatusService.php` | Loki readiness check (`installing` → `ready`) |
| `Services/LogRetentionService.php` | Per-region log retention via Loki delete requests |
| `vue/src/composables/useDeploymentLogs.js` | Log + metric polling timers (used by `DeploymentLogPanel.vue`) |
| `config/observability.php` | Tracing, replay, uptime, status page and alert settings (`OBS_*`) |
| `Services/Helpers/ManagedTracing.php` | Collector / Tempo releases, Helm values, Knative tracing config |
| `Services/Observability/TracingInstallService.php` | Install, reconfigure, uninstall and status of the tracing backend |
| `Services/Observability/ObservabilityPodSpec.php` | Deploy hook: tracing / replay variables, fronting http runtimes with the wrapper |
| `Services/Observability/Tracing/*` | Tempo client, TraceQL search, trace normalization, trace logs |
| `Services/Observability/Replay/*` | Capture ingest and redaction, replay, response diff |
| `Services/Observability/Uptime/UptimeService.php` | Probes, incident state machine, agent checks, uptime statistics |
| `Services/Observability/StatusPageService.php` | Status page settings, slug / domain lookup, public payload |
| `Services/Observability/Alerts/*` | Alert manager (dedup, cooldown, delivery), rule evaluation, channels |
| `runtime/wrapper/observe.go`, `otel*.go`, `proxy.go`, `replay.go` | Wrapper server span, OTLP export, observed http mode, failed-request capture |
| `runtime/agent/internal/uptime/prober.go` | Uptime checks run by the region agent |
| `vue/src/modules/observability/` | Traces, Replay and Uptime tabs; Alerts, Status page and Tracing settings; public status page |

See also: [monitoring.md](monitoring.md) (runr-agent, metric snapshots, cost), [kata-hypervisors.md](kata-hypervisors.md).
