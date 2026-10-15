# Durable workflows

RunR runs **durable workflows**: code-first, step-based functions whose progress survives
crashes, redeploys and sleeps of days. A workflow is an ordinary function in a project; every
`step` in it is executed once, memoized, and retried with exponential backoff when it fails.
Workflows sleep (`step.sleep`), wait for events (`step.waitForEvent`), call other workflows
(`step.invoke`) and emit events (`step.sendEvent`).

```python
@wf.workflow("process-order", triggers=[{"event": "order.created"}], retries=4)
def process_order(ctx, step):
    charge = step.run("charge-card", charge_card, ctx.event["data"])
    step.sleep("fraud-check-window", "10m")
    shipped = step.wait_for_event("shipped", "order.shipped", match="data.order_id", timeout="3d")
    if shipped is None:
        step.run("refund", refund, charge)
    return {"shipped": shipped is not None}
```

- SDKs: [`sdk/python`](../sdk/python) (`runr-workflows`), [`sdk/node`](../sdk/node)
  (`@runr/workflows`, TypeScript). Any runtime can implement the language-neutral
  [workflow protocol v1](workflows-protocol.md).
- Examples: [`examples/workflows`](../examples/workflows).
- CLI: `runr workflows` ([CLI](cli.md#durable-workflows)); AI agents: the `workflows_list`,
  `workflow_run_start`, `workflow_runs_list`, `workflow_run_status` and `event_send` tools of
  the RunR MCP server ([AI agents](mcp.md#tools)).

## How it works

Durable execution by **deterministic replay** (the model of Inngest and Temporal's
workflow code):

1. A run is created (API, event, cron tick, queue message, `step.invoke`, replay).
2. An engine worker takes the run's **lease** and POSTs a signed *execute* request to the
   project's function: the trigger event plus the results of every step completed so far.
3. The SDK runs the workflow from the top. A step with a stored result returns it at once; the
   first step without one runs (`step.run`) or is reported (sleep, wait, invoke, send) — and the
   invocation ends with exactly one **op** describing what happened.
4. The engine persists the op and schedules what comes next: the next invocation right away
   (step completed), after a backoff (step failed), at a wake-up time (sleep), when an event
   arrives or a timeout passes (wait), when the child run ends (invoke).
5. When the function returns, the run is completed with its output.

```text
 ┌──────────┐  execute(run, event, steps)   ┌────────────────┐
 │  engine  │ ────────────────────────────▶ │  function+SDK  │
 │ (RunR    │ ◀──────────────────────────── │  replays code, │
 │  API +   │   {op: step_completed …}      │  runs 1 step   │
 │  queue)  │                               └────────────────┘
 └──────────┘
   persists step → next invocation … until run_completed / run_failed
```

Consequences for workflow code:

- **Steps are the unit of durability.** Side effects belong inside `step.run`; code between
  steps runs on every invocation and must be deterministic (no random branching, no reading
  the clock to decide which step comes next).
- **Step ids are stable names.** A repeated id gets a counter suffix (`add`, `add:1`, …) in call
  order. Renaming a step in a deployed workflow makes running instances execute it again.
- **Steps execute at least once.** A result lost to a crash (worker or function) re-runs the
  step on the next invocation; make external effects idempotent (pass the run id or the step id
  as an idempotency key to the payment gateway, for example).
- **One step per invocation.** Steps passed to `Promise.all` run one after another, in call
  order.
- **Outputs are JSON.** A step result goes through JSON; dates become strings.

## Defining workflows

Definitions come from the code (the SDK registry). The engine learns them by **sync**:

- automatically after a deployment of a `workflow`-trigger project becomes active,
- with **Sync definitions** in the Workflows tab or `POST /api/projects/{project}/workflows/sync`
  (a signed *introspect* request to the function),
- from `runr.workflows.json` in the project's latest code version when the function cannot be
  reached during a sync (same format as the introspection answer: a list, or
  `{"workflows": [...]}`),
- declared through the API: `PUT /api/projects/{project}/workflows {"workflows": [...]}` (CLI, IaC).

A workflow that is no longer declared is **archived**: its runs stay visible, it starts no new
runs. A workflow can be **paused** (`PATCH …/workflows/{name} {"is_active": false}`): triggers
and the API start nothing; runs in progress continue. Pausing survives syncs.

### Options

| Option | Default | Meaning |
|--------|---------|---------|
| `triggers` | `[]` (API only) | `{"event": "order.created", "if": "<expr>"}`, `{"cron": "0 9 * * *", "timezone": "Europe/Istanbul"}`, `{"queue": "<stream>"}` |
| `retries` | 3 | retries per step; attempts = retries + 1 (max 20) |
| `timeout` | `30d` | run timeout (max `365d`); an overdue run ends `timed_out` |
| `concurrency` | none | `5`, `{"limit": 5, "key": "event.data.customer_id"}` or a list of one workflow-wide and one keyed limit |
| `idempotency` | none | expression of the trigger event; runs with the same value are deduplicated |
| `cancel_on` | `[]` | `{"event": "order.cancelled", "match": "data.order_id"}` — cancels matching active runs |
| `description` | — | shown in the UI |

Durations: `500ms`, `30s`, `15m`, `2h`, `1d`, `1w`, combinations (`1h30m`), ISO-8601 (`PT1H`)
or a number of seconds.

### Expressions

Trigger `if`, `waitForEvent` `if`, `cancel_on` `if`, concurrency `key` and `idempotency` use a
small side-effect free language: paths (`event.data.order_id`, `async.data.items[0]`,
`event.data["key"]`), literals (`'text'`, `"text"`, numbers, `true`, `false`, `null`),
comparisons (`== != > >= < <=`), `&& || !` and parentheses. `event` is the run's trigger event,
`async` the incoming event. Values compare strictly (`"1" != 1`); a path that does not exist is
`null`. `match: "data.order_id"` is shorthand for `event.data.order_id == async.data.order_id`.

## Starting runs

| Source | How |
|--------|-----|
| API | `POST /api/projects/{project}/workflows/{name}/runs {"data": {...}, "idempotency_key": "...", "test": false}` → `201` new run, `200` existing run with that key |
| Events | `POST /api/events` — every workflow with a matching event trigger starts a run |
| Cron | `{"cron": ...}` triggers: the engine tick starts one run per due minute (`runr/cron` event with `cron`, `timezone`, `scheduled_at`) |
| Queue | a project with trigger `workflow` and a queue stream: each Redis Stream message becomes the event `queue/<stream>` ([queue bridge](#queue-bridge)) |
| Invoke | `step.invoke` starts a child run (`runr/invoke` event with the given data) and waits for its output |
| Replay | a finished run replayed from the start or from a step |

### Events API

`POST /api/events` (Sanctum API token or session; the tenant is the one the request is
identified for — `X-Tenant-ID` selects one of the user's tenants):

```json
{"name": "order.created", "data": {"order_id": "o-1"}, "id": "order-o-1", "ts": 1760000000000}
```

A list of events or `{"events": [...]}` is accepted too (at most 100 per request). For every
event the engine, in this order, cancels runs whose `cancel_on` matches, resumes
`waitForEvent` steps whose `match` / `if` hold, and starts workflows triggered by the name.

- `name`: 1–200 characters of letters, digits and `. _ : / -`; names under `runr/` are
  reserved for the platform.
- `data`: a JSON object (default `{}`); name + data at most 256 KiB.
- `id`: deduplication — an event id received before is answered with the original event and
  not processed again.
- answer: `{"ids": [...], "events": [{"id", "name", "duplicate", "runs": [...], "resumed", "cancelled"}]}`.

`GET /api/events?name=<prefix>&source=&project_id=&from=&to=` lists events, `GET /api/events/{id}`
shows one with the runs it started. `?test=1` on `POST` marks test events (the UI's
**Send test event**). Events emitted by `step.sendEvent` carry `source: workflow` and the project.

Events wait for nobody: a `waitForEvent` matches events that arrive **after** the wait started.

### Queue bridge

A project with trigger **Workflow** and a queue stream gets the regular queue consumer
(`runtime/queue-consumer`) with the engine as its sink:
`POST /api/internal/workflows/queue/{tenant|central}/{project}/{token}` — the 40-hex token is
derived from the project's signing key (rotating the key changes the URL; redeploy). Each
message becomes the event `queue/<stream>` with the message fields as data (a single JSON
field named `data`, `payload`, `body` or `message` is decoded); the stream entry id
deduplicates redeliveries. The consumer's retry and dead-letter handling stay as they are: a
non-2xx answer is retried, then dead-lettered. The sink URL base is
`WORKFLOWS_PUBLIC_API_URL`, else the region's `app_url`, else `APP_URL`.

## Steps

| Step | Op | Run state | Result |
|------|----|-----------|--------|
| `run(id, fn)` | `step_completed` / `step_failed` | stays runnable | `fn`'s value; retried on failure |
| `sleep(id, d)` / `sleepUntil(id, t)` | `sleep` | `sleeping` until the time | — |
| `waitForEvent(id, {event, match, if, timeout})` | `wait_for_event` | `waiting` | the event `{id, name, data, ts}`, `null` on timeout |
| `invoke(id, {workflow, data, timeout})` | `invoke` | `waiting` | the child's output; a failed / cancelled / timed-out child fails the step (`InvokeFailed`, `InvokeTimeout`) |
| `sendEvent(id, events)` | `send_event` | stays runnable | `{"ids": [...]}` |

`waitForEvent` and `invoke` without a timeout wait `7d` (`workflows.defaults.wait_timeout`);
every timeout is capped at the run timeout.

### Retries and errors

- A failing `step.run` is retried with exponential backoff: 2 s × 2^(n-1), at most 10 min,
  ±20 % jitter (`config/workflows.php` → `backoff`). `RetryAfterError` sets the delay
  (duration or time). `NonRetriableError` fails at once.
- A step that failed for good is memoized as failed; the next invocation throws `StepError`
  into the workflow at that step — catch it to compensate. Uncaught, it fails the run.
- An error outside any step, an unreachable function (non-2xx, timeout, `401` signature
  rejection) or a malformed answer counts as a **function attempt**, retried with the same
  backoff up to the workflow's attempts; progress (a step result) resets the count.
- A run whose worker vanished (lease expired) is recovered by the tick as a failed function
  attempt (`WorkerLost`).

### Status of a run

`queued` → `running` (lease held, function invoked) → back to `queued` after each step, or
`sleeping` / `waiting`; ends `completed`, `failed`, `cancelled` or `timed_out`. Steps:
`completed`, `failed`, `retrying` (backoff), `sleeping`, `waiting`, `cancelled`.

## Controlling runs

| Action | API | Effect |
|--------|-----|--------|
| Cancel | `POST /api/projects/{p}/workflow-runs/{run}/cancel` | active run → `cancelled`; open sleeps / waits cancelled; invoked children cancelled; a result arriving later is discarded |
| Retry | `POST …/{run}/retry` | a failed / cancelled / timed-out run in place: completed steps stay, failed and unfinished steps run again with fresh attempts |
| Replay | `POST …/{run}/replay {"from_step": "ship"}` | a **new** run with the same event; without `from_step` every step runs again, with it the memoized steps before that step are copied |
| List / detail | `GET /api/projects/{p}/workflow-runs?status=failed,cancelled&workflow=&source=&q=&from=&to=&per_page=` · `GET …/{run}` | detail carries the event, output, error and every step with input, output, error, attempt history, timings |

## Concurrency and idempotency

- **Concurrency** limits count runs that are executing (`running`). Sleeping and waiting runs
  hold no slot. A run deferred by a full limit stays `queued`; a run leaving `running` hands its
  slot to the oldest queued run, and the tick re-dispatches queued runs as a safety net.
- **Keyed concurrency** (`key: "event.data.customer_id"`) applies the limit per value of the
  expression.
- **Idempotency**: an explicit `idempotency_key` (API) or the definition's `idempotency`
  expression of the trigger event. A second start with the same key answers the existing run.
  Keys are unique per workflow for as long as the run is retained.

## Security

- Every engine request carries an HMAC-SHA256 signature over the request
  ([protocol](workflows-protocol.md#signatures)) with the project's **signing key**. RunR
  injects the key as `RUNR_WORKFLOW_SIGNING_KEY` into the Knative Service of a `workflow`
  project — from the project's Kubernetes Secret `<slug>-secret-env` (`secretKeyRef`), never as a
  value in the Service spec ([security.md](security.md#where-secrets-never-appear)); the SDK answers unsigned, wrongly signed or stale (> 5 min) requests with `401`.
  Only the engine can drive steps, even though the function URL is public.
- The key is stored encrypted and never returned by listings (fingerprint only).
  `POST …/workflows/app/rotate-key` creates a new one; the previous key keeps signing (as a
  second signature) for 24 h (`WORKFLOWS_KEY_ROTATION_GRACE`) so the running revision keeps
  working until the redeploy. `GET …/workflows/app/signing-key` reveals it for functions
  served outside RunR.
- **Endpoint override** (`PATCH …/workflows/app {"endpoint_url"}`): http(s) only, no
  credentials, no loopback / private / link-local / CGNAT addresses (literal or resolved)
  unless `WORKFLOWS_ALLOW_PRIVATE_ENDPOINTS=true`. Redirects are not followed.

### Permissions

Part of the role catalog ([permissions.md](permissions.md), group *Workflows*):

| Permission | Scope | Grants |
|------------|-------|--------|
| `workflows.view` | project | workflows, runs, steps and usage of the project; organization-wide also `GET /api/events` and `GET /api/workflow-usage` |
| `workflows.run` | project | start, cancel, retry, replay runs |
| `workflows.manage` | project | sync / declare definitions, pause / resume, endpoint override, rotate / reveal the signing key |
| `events.send` | organization | `POST /api/events` |

Built-in roles: Owner and Admin hold all four, Developer all four, Viewer `workflows.view`.
The UI hides the actions a user may not take; the API enforces every rule
(`config/route_permissions.php`). The queue bridge sink is authenticated by its signed URL, not
by a user.

## Limits

`config/workflows.php` → `limits` (platform-wide):

| Limit | Default |
|-------|---------|
| event (name + data) | 256 KiB |
| events per request | 100 |
| step output / run output | 1 MiB each (`OutputTooLarge`, not retried) |
| request to the function (event + memoized state) | 8 MiB (`StateTooLarge`) |
| steps per run | 1000 (`TooManySteps`) |
| retries per step | 20 |
| concurrency limit | 1000 |
| run timeout | 365 d |
| workflows per project | 200 |

## Data model

Workflow tables live in the central database and in **every tenant schema** (tenancy: one
schema per tenant), created by one shared schema (`App\Workflows\Database\WorkflowSchema`):
central migration `2026_10_10_000001_create_workflow_tables`, tenant migration
`0040_create_workflow_tables`. Everything a tenant sees is in its own schema.

| Table | Rows |
|-------|------|
| `workflow_apps` | one per project: encrypted signing key (+ previous key in grace), endpoint override, last sync (status, error, SDK) |
| `workflows` | definitions (name unique per project, triggers, config, active / archived, source `sdk` / `manifest` / `api`) |
| `workflow_triggers` | denormalized `event`, `cron` (with last tick) and `cancel` triggers for lookups |
| `workflow_runs` | status, trigger event, output, error, function attempt, idempotency / concurrency keys, parent / replay links, lease, next attempt, timeout |
| `workflow_steps` | per run and step id: type, status, position, attempts and history, input, output, error, wake-up / timeout, waited event + compiled match expression, child run |
| `workflow_events` | received events with dedup id, source, effects (runs started, waits resumed, runs cancelled) |
| `workflow_usage` | step executions and started runs per workflow per day |

## Operations

- **Queue**: engine jobs (`ExecuteWorkflowRunJob`, `SyncWorkflowsJob`) run on
  `WORKFLOWS_QUEUE` (default `default`, processed by Horizon; `WORKFLOWS_QUEUE_CONNECTION`
  picks another queue connection). Delivering a job twice is
  harmless: only the worker holding the run's lease invokes the function. Delays up to 15 min
  are queued; longer sleeps are woken by the tick.
- **Scheduler**: `workflows:tick` every minute (run timeouts, due sleeps, wait / invoke
  timeouts, expired leases, lost dispatches, cron triggers) and `workflows:prune` daily, both
  over the central database and every active tenant.
- **Retention** (`workflows:prune`): finished runs and their steps after 30 days
  (`WORKFLOWS_RUN_RETENTION_DAYS`), events after 7 (`WORKFLOWS_EVENT_RETENTION_DAYS`), usage rows
  after 400 (`WORKFLOWS_USAGE_RETENTION_DAYS`). Active runs are never pruned; an idempotency key
  is free again once its run is pruned.
- **Engine → function**: `WORKFLOWS_REQUEST_TIMEOUT` (300 s, keep it at or below the Knative
  request timeout), `WORKFLOWS_CONNECT_TIMEOUT` (10 s), `WORKFLOWS_VERIFY_TLS`,
  `WORKFLOWS_ENDPOINT_PATH` (path appended to the function URL). The function URL is
  `https://<project slug>.<region function domain>`.
- **Configuration**: the `WORKFLOWS_*` variables are environment variables of the API
  (`config/workflows.php`); none of them has a Helm value of its own — set them via `extraEnv`.

### Usage

Every step execution (each attempt of `step.run`, each sleep, wait, invoke and send) and
every started run is counted per workflow and day in `workflow_usage`:
`GET /api/workflow-usage?from&to` (tenant) and `GET /api/projects/{p}/workflow-usage` return
totals, a daily series and per-workflow sums. The compute of a workflow project is metered
like an HTTP project (its Knative Service sessions, entry point `http`).

## Projects with the `workflow` trigger

A project serves workflows when its trigger type is `workflow` — **Workflow** in the console's
trigger picker, `"config": {"trigger_type": "workflow"}` in the projects API. The trigger types
a project accepts are `http`, `schedule`, `queue` and `workflow`; the `event` trigger type is
refused (422) — events reach workflows through [`POST /api/events`](#events-api). Projects that
already store `event` still list, show and delete. A project that stored `workflow` before
0.5.0 (deployed as a plain HTTP function then) gets its signing key and a definition sync with
its next deployment.

- Deployed as a Knative Service like an HTTP project (scale to zero, sizes, custom domains
  stay HTTP-only), with `RUNR_WORKFLOW_SIGNING_KEY` and `RUNR_WORKFLOW_PROTOCOL=v1` in the
  environment. The runtime needs the `http` trigger in its manifest.
- Optional queue stream: the queue consumer of the project delivers to the engine
  ([queue bridge](#queue-bridge)); requires Redis on the region.
- FaaS / XaaS: the runtime calls the SDK's `handler(event, context)`. Container projects serve
  the SDK themselves (`wf.serve()`, WSGI, `node:http`, fetch handler) on the container port;
  any path works.

## UI

- **Project → Workflows**: definitions with triggers and limits (pause / resume, start run with
  JSON data, idempotency key and the test flag), the function panel (endpoint, override,
  signing key fingerprint / reveal / rotation, sync status, queue bridge), the runs table
  (status / workflow / search filters, pagination, live refresh while runs are active) and the
  run detail: summary, error, trigger event, output and the step timeline with inputs,
  outputs, errors, attempt history, durations, wake-up and wait times, child runs — with
  cancel, retry, replay and replay-from-step.
- **Settings → Events** (tenant level): events with filters, effects and detail (data, started
  runs), usage of the last 30 days, **Send test event**.
