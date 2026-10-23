# Load tests

A load test sends a fixed load to an HTTP project from inside the project's region and reports
how the function held up: latency percentiles, the error rate and the requests per second, next to
how far the project scaled and how many cold starts the load caused. It runs [k6](https://k6.io)
with a script RunR generates — there are no user scripts.

Project → **Load test** (console), `POST /api/projects/{project}/load-tests` (API),
`runr loadtest` ([CLI](cli.md#load-tests)), `load_test_run` / `load_test_get` ([MCP](mcp.md)).

> **Test traffic is metered.** The requests of a load test reach the project's deployment like any
> other traffic: its sessions, CPU, memory and cold starts are metered and billed (pay as you go)
> exactly the same way.

## What a test can choose

| Field | Values | |
|-------|--------|-|
| `host` | the project's function host (default) or one of its **active** custom domains | never a URL of the caller: the request is built from host + path |
| `path` | `/…`, with an optional query, at most 1024 characters | no scheme, host, whitespace or fragment |
| `method` | `GET` (default) or `POST` | |
| `body`, `content_type` | POST only, at most 4096 bytes; content type default `application/json` | sent with every request |
| `users` | 10, 50 or 100 | concurrent virtual users, each sending the request back to back |
| `duration_seconds` | 30, 60 or 300 | |

The installation can lower the largest choices (`observability.loadTest.maxUsers`,
`maxDurationSeconds`).

## Abuse guard

- **Only the project's own URL.** The target host is the project's function host
  (`<slug>.<region domain>`) or a custom domain of the project whose ownership was verified and
  that is routed on the region (status `active`, [custom domains](cli.md#custom-domains)). The
  k6 pod resolves that host to the region's own ingress (see below) and does not follow
  redirects.
- **One test per project at a time** (409 `load_test_running`). A test past its deadline (its
  duration plus the start time) no longer holds the project.
- **A daily limit per organization** (`observability.loadTest.dailyLimit`, default 20; 429
  `daily_limit_reached`). In a multi-tenant install every organization counts its own tests.
- **A pod quota per region** (`observability.loadTest.maxConcurrentPerRegion`, default 5): the
  ResourceQuota of the load-test namespace. A test that does not get a pod within a minute fails.
- The project must be HTTP-triggered, deployed (an active deployment) and not stopped; its region
  must have a cluster connection, be active and not moving.

## How it runs

1. The API checks the guard, stores the test (`pending`) and broadcasts it (`load_test.updated`).
2. `StartLoadTestJob` prepares the region's load-test namespace `runr-loadtest` (once, then at most
   every ten minutes again), reads the ClusterIP of the region's Traefik, writes the generated
   script into a ConfigMap and creates the k6 Job — the test is `running`.
3. `CheckLoadTestJob` follows the Job every 5 seconds. When k6 is done it reads the results line
   from the pod's log, adds the scaling figures of the test window and removes the Job and its
   ConfigMap — the test is `completed` (or `failed` with the reason). The one who started it gets a
   notification (`load_test.completed` / `load_test.failed`).

The namespace `runr-loadtest` (`observability.loadTest` → `OBS_LOADTEST_NAMESPACE`):

| Object | |
|--------|-|
| Namespace labels | Pod Security Admission `restricted` (enforced) |
| NetworkPolicy `runr-loadtest-default-deny` | every pod: no ingress, no egress |
| NetworkPolicy `runr-loadtest-egress` | the k6 pods may reach the region's Traefik pods (Knative Serving namespace) and nothing else — no DNS, no internet, no other service |
| ResourceQuota `runr-loadtest-quota` | `count/pods` = `maxConcurrentPerRegion` |

The Job `lt-<id>`: one pod (`backoffLimit: 0`), `activeDeadlineSeconds` = duration + 180 s,
`ttlSecondsAfterFinished: 600`, no service account token, no secrets, the non-root k6 user, a
read-only root file system, CPU 250m–1 and memory 256–512 MiB. Its image is
`ghcr.io/brf-tech/runr/reqs/k6:1.8.2` (the reqs mirror of `docker.io/grafana/k6`;
`observability.loadTest.image` points it elsewhere).

The script runs the `constant-vus` executor with the chosen users and duration, maps the target
host to the Traefik ClusterIP (k6 option `hosts`), sends the requests over HTTPS
(`OBS_LOADTEST_TARGET_SCHEME`) without verifying the certificate — the address is the region's
own ingress, not a name looked up anywhere — and prints its results with `handleSummary` as one
line `RUNR_LOADTEST_SUMMARY {json}`. So the test measures the function behind the region's
ingress, not the internet between a client and the region. The project's own NetworkPolicies are
not involved: the requests enter the project namespace through Traefik like any other request.

### When the region goes

- **Uninstalling Knative Serving** from the region (**Uninstall Serving**, or the full
  `uninstall-knative`, [Regions](regions.md#knative-operator-and-serving)) removes the namespace with its
  policies, its quota and the Jobs still running in it: the ingress the tests target goes with
  Serving. The region's running tests of the organization that uninstalls end as `failed`
  (*Knative Serving was uninstalled from the region*); those of other organizations end at their
  next check, when their Job is gone. The next test after a new install applies the namespace
  again. A namespace of that name that RunR did not create (without
  `app.kubernetes.io/managed-by: runr` and `app.kubernetes.io/component: load-test`) stays.
- **The end of a tenant's share of a platform region** (detach) removes that tenant's Jobs and
  their ConfigMaps from the namespace (label `runr.dev/tenant`) and ends its tests there as
  `failed` (*The organization no longer uses the region*). The namespace stays for the others.

## Results

| Field | Source |
|-------|--------|
| `latency_ms` `p50` / `p95` / `p99` (and `avg`, `min`, `max`) | k6 `http_req_duration` |
| `error_rate`, `failed_requests` | k6 `http_req_failed`: responses outside 200–399, timeouts (30 s), connection errors |
| `rps`, `requests` | k6 `http_reqs` |
| `data_received`, `data_sent` | bytes |
| `cold_starts` | the function sessions the region's agent reported as cold starts in the window — what `usage_records.cold_start_count` adds up per day |
| `pods_peak`, `pods_source` | the peak number of the project's function pods in the window, from the region's Prometheus (cAdvisor's working-set series of the revision pods); without Prometheus, the pods with a session in the window (`pods_source: sessions`) |
| `window` | when the k6 container ran |

The tab shows the latest run and the **last five runs side by side** (the best value of each row
marked), so a change can be compared with the runs before it. A run whose k6 ended without
results (a script error, a pod that could not start) fails with the end of k6's log.

## API

| Route | Permission | |
|-------|------------|-|
| `GET /api/projects/{p}/load-tests?limit=10` | `metrics.view` | latest tests, newest first; `meta`: `users`, `durations`, `methods`, `targets`, `daily_limit`, `used_today`, `max_body_bytes`, `enabled` |
| `POST /api/projects/{p}/load-tests` | `projects.deploy` | start a test (202) |
| `GET /api/projects/{p}/load-tests/{id}` | `metrics.view` | one test |
| `POST /api/projects/{p}/load-tests/{id}/cancel` | `projects.deploy` | stop a pending or running test |

### Errors

| Status | `code` | |
|--------|--------|-|
| 403 | `load_tests_disabled` | `observability.loadTest.enabled` is off |
| 409 | `load_test_running` (`load_test_id`), `project_stopped`, `region_unavailable`, `ingress_missing` | |
| 422 | `not_http`, `not_deployed`, `host_not_allowed` (`errors.host`), validation errors | |
| 429 | `daily_limit_reached` (`daily_limit`) | |

## Permissions

Running and stopping a test needs `projects.deploy` on the project — it sends metered traffic to
the deployment; reading tests and results needs `metrics.view`. The MCP tools and the CLI follow
the same permissions. Audit log: `load_test.start` (host, method, path, users, duration and whether
a body was sent — never the body) and `load_test.cancel`.

## Configuration

Helm values `observability.loadTest.*` ([helm-values.md](helm-values.md#observability)):

| Value | Variable | Default | |
|-------|----------|---------|-|
| `enabled` | `OBS_LOADTEST_ENABLED` | `true` | |
| `dailyLimit` | `OBS_LOADTEST_DAILY_LIMIT` | `20` | tests per organization and day; `0`: none |
| `maxUsers` | `OBS_LOADTEST_MAX_USERS` | `100` | |
| `maxDurationSeconds` | `OBS_LOADTEST_MAX_DURATION` | `300` | |
| `maxConcurrentPerRegion` | `OBS_LOADTEST_MAX_CONCURRENT_PER_REGION` | `5` | |
| `image` | `OBS_LOADTEST_IMAGE` | `""` | empty: `ghcr.io/brf-tech/runr/reqs/k6:1.8.2` |

Set with `extraEnv`: `OBS_LOADTEST_NAMESPACE` (`runr-loadtest`), `OBS_LOADTEST_TARGET_SCHEME`
(`https`), `OBS_LOADTEST_MAX_BODY_BYTES` (`4096`), `OBS_LOADTEST_START_GRACE_SECONDS` (`180`),
`OBS_LOADTEST_TTL_SECONDS` (`600`), `OBS_LOADTEST_CPU_REQUEST` / `_LIMIT` (`250m` / `1`),
`OBS_LOADTEST_MEMORY_REQUEST` / `_LIMIT` (`256Mi` / `512Mi`).

## Related files

| File | |
|------|-|
| `api/app/Http/Controllers/Api/Observability/LoadTestController.php` | API |
| `api/app/Services/Observability/LoadTest/LoadTestService.php` | guard, start, follow, cancel, broadcasts |
| `api/app/Services/Observability/LoadTest/LoadTestRunner.php` | namespace, ConfigMap, Job, its state and log |
| `api/app/Services/Observability/LoadTest/LoadTestManifests.php` | Kubernetes objects and the k6 script |
| `api/app/Services/Observability/LoadTest/LoadTestResults.php` | the results line, cold starts and pods of the window |
| `api/app/Jobs/LoadTests/*` | `StartLoadTestJob`, `CheckLoadTestJob` |
| `vue/src/modules/observability/components/LoadTestTab.vue` | the Load test tab |
| `cli/internal/cmd/loadtest.go` | `runr loadtest` |
