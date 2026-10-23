# Deploy strategies: blue-green and canary

By default a deploy is **direct**: the new revision of a function takes all traffic as soon as it
is ready, and the deployment that served before is torn down. That stays the default. A project
can switch on a **deploy strategy** instead, per function:

| Strategy | What a deploy does |
| --- | --- |
| `direct` (default) | The new revision takes all traffic once it is ready (unchanged behaviour). |
| `blue_green` | The new revision starts next to the one that serves, with 0 % of the traffic and a test URL of its own. A promotion moves all traffic at once — by hand, or by itself once the revision is ready and its health check passes. The previous revision stays warm for a while, so a rollback is instant. |
| `canary` | The new revision gets a growing share of the traffic in steps (10 % → 50 % → 100 % by default). Each step lasts an interval; when the new revision's error rate or p95 latency exceeds a threshold during a step, the rollout rolls back by itself. |

Strategies apply to functions served over HTTP: the `http` and `workflow` triggers — the project's
own workload (production) and each of its [environments](environments.md) (staging, …), which
roll out with the project's settings unless they set their own ([Environments](#environments)).
Queue and schedule functions, preview environments and the deploys a [region move](regions.md)
starts always deploy directly.

## Settings

The strategy is a project setting with options for both strategies; switching between them keeps
the options of the other. Changing it creates no version — it changes how the next deploys roll
out, not what they run.

| Option | Default | Range | Meaning |
| --- | --- | --- | --- |
| `strategy` | `direct` | `direct`, `blue_green`, `canary` | `blue-green` is accepted too |
| `blue_green.auto_promote` | `false` | | Promote by itself once the new revision is ready; `false` waits for a promotion |
| `blue_green.health_path` | none | a path starting with `/` | With `auto_promote`: the path must answer 2xx before the promotion |
| `blue_green.health_checks` | `3` | 1–20 | Consecutive 2xx answers needed (one check every 10 s, at most 5 minutes) |
| `blue_green.keep_previous_minutes` | `15` | 0–1440 | How long the previous revision stays warm after the promotion; `0` releases it at once |
| `canary.steps` | `[10, 50, 100]` | 1–10 strictly increasing percentages, 1–100 | The new revision's share per step; reaching 100 is the promotion (a list that ends below 100 is promoted after its last step) |
| `canary.interval_minutes` | `5` | 1–1440 | Time per step |
| `canary.error_rate_threshold` | `5` | 0–100 (%), or none | Rolls back above this share of the new revision's requests failing |
| `canary.p95_threshold_ms` | none | 1–600000, or none | Rolls back above this p95 latency of the new revision |

Where to set it:

- **Console**: the project's settings, **Deploy strategy**.
- **API**: `GET` / `PUT /api/projects/{project}/deploy-strategy` (below).
- **runr.yaml** — the CLI's `runr deploy` and a [git push](git-deploy.md) apply it; only the keys
  present change, an invalid value is left out with a warning:

  ```yaml
  deploy:
    strategy: canary              # direct | blue-green | canary
    canary:
      steps: [10, 50, 100]
      interval_minutes: 5
      error_rate_threshold: 5
      p95_threshold_ms: 800
    blue_green:
      auto_promote: true
      health_path: /healthz
      health_checks: 3
      keep_previous_minutes: 15
  ```

- **CLI**: `runr strategy set canary --steps 10,50,100 --interval 5m --max-error-rate 5 --max-p95 800ms`
  ([CLI](cli.md)).

One deploy can use another strategy than the one of the environment it deploys to: `strategy` on
`POST /api/projects/{project}/deploy`, `runr deploy --strategy direct` (or `runr rollback
--strategy direct` for an urgent rollback), the `strategy` argument of the MCP tools `deploy` and
`rollback`. The options always come from that environment's settings (production's are the
project's).

### Canary thresholds

A canary step is judged by **the new revision's own requests** — not the project's, of which the
stable revision still serves most — over the time the step has run, from the region's
Prometheus. The first source that has requests of the revision in the window answers:

1. **Span metrics of the revision** (`source: span_metrics`), while **tracing is on for the
   project** and its region's tracing backend accepts spans: the series the
   [alert rules](observability.md) use, narrowed to the revision. The runtime wrapper names the
   revision on every span (`runr.revision`, from `K_REVISION`); the rollout names its revision
   (`<slug>-v<N>-<id>`) and gives its traced containers — container images with their own
   OpenTelemetry SDK too — the resource attribute `runr.revision`. The collector keeps it as a
   span metrics dimension (`runr_revision`, the label RunR queries: `OBS_SPANMETRICS_REVISION_LABEL`).
   A collector installed
   by an earlier release gets that configuration from `region:reconcile` (step `tracing`, the
   collector pod rolls once); until then the next source answers.
2. **Knative's request metrics of the revision** (`source: knative`): the queue-proxy next to
   every revision pod counts each request by response code class and measures its latency
   (`revision_request_count` / `revision_request_latencies`, or their `kn_` names), labelled
   with the revision — where the region's Prometheus scrapes them. The error rate is the share of
   `5xx` answers.
3. **Neither**: the canary progresses on time only (the console and `runr rollout status` say
   so).

A window needs at least 10 requests of the revision before a threshold is judged; the thresholds
are compared every minute and at the end of each step. A rollback names the revision's value and
the limit (*The new revision's error rate 12.5 % over the last 3 min is above the limit of
5 %.*).

## Environments

Each [environment](environments.md) of a project deploys with the project's settings unless it
sets its own — another strategy, other options, or both; what it does not set follows the
project's, also when the project's settings change later. A deploy to the environment, a
[promotion](environments.md#promotion) into it and a [rollback](versioning.md#rollback) of one of
its deployments roll out with the environment's settings.

- **Its own workload**: an environment's rollout runs on the environment's Knative Service
  (`<slug>-<environment>`, e.g. `shop-staging`) in the environment's region. The new revision
  starts next to the deployment that serves the environment, its test URL is on the environment's
  host (`https://green-shop-staging.…`), the canary thresholds are judged on the environment's own
  traces, and the previous revision kept warm is the environment's.
- **On its own**: each environment has at most one open rollout, next to production's and the
  other environments'. A rollout never touches another environment's traffic or deployments — a
  production rollout's promotion does not end staging's deployment, and a deploy to staging does
  not replace production's rollout.
- **Decided the same way**: promote, abort and roll back an environment's rollout by its new
  deployment (`POST …/deployments/{deployment}/rollout/promote`, …), like production's. `GET
  /api/projects/{project}/rollout?environment=staging` is the environment's newest rollout.
- **Setting it**: in the console, the **Deploy strategy** on the environment's card in the
  Environments tab (or in the Deployments tab while the environment is selected) — it says
  whether the environment follows the project's settings; **Use the project's** goes back. In the
  API, `environment` on `GET` (query) and `PUT /api/projects/{project}/deploy-strategy` (body); a
  `PUT` with `environment` stores what it sends as the environment's own and needs
  `environments.manage` besides `projects.update`; `reset: true` drops what the environment sets.
  The answer has the environment's effective settings with `environment`, `own` (what it sets),
  `inherited` (it sets nothing) and `project` (the project's strategy). In the CLI, `runr strategy
  show|set --env staging` and `runr strategy reset --env staging`. `runr.yaml`'s `deploy:` section
  is the project's.
- What an environment sets is kept with its configuration overrides (`overrides.deploy_strategy`
  of the [environment](environments.md#api): `PATCH …/environments/{environment}` can set it too
  and keeps it when it does not send it). Environments carry `deploy_strategy`, the name of the
  strategy they deploy with. Deleting an environment deletes its rollouts.

## A rollout

A deploy with a strategy, while a deployment serves the project, opens a **rollout**. With
nothing to fall back to — the first deploy, a stopped project, a service that serves nothing on
the region's cluster — the deploy is direct.

1. The new deployment's revision is created with a name (`<slug>-v<N>-<id>`) and a traffic tag;
   the Knative Service's traffic stays with the revision that serves (100 %), the new one gets
   0 %. Its **test URL** is the tag's route: `https://green-<slug>.<function domain>` (blue-green)
   or `https://canary-<slug>.<function domain>` (canary).
2. When the new revision is ready, its deployment is `running` next to the `active` one.
   - Blue-green without `auto_promote` waits for a promotion (`waiting_promotion`).
   - Blue-green with `auto_promote` checks the health path (`progressing`) and promotes itself
     after enough passes in a row; without a health path it promotes at once. Checks that do not
     pass within 5 minutes fail the rollout and the traffic stays.
   - Canary enters its first step (`progressing`) and moves to the next one after each interval.
3. **Promotion**: all traffic to the new revision; the new deployment becomes `active`. A
   blue-green rollout keeps the previous revision routable (0 %, tag `previous`:
   `https://previous-<slug>.<function domain>`) and warm — at least one pod — until `keep_until`;
   its deployment stays `running`. Then the previous revision is released: the service is back to
   Knative's default (all traffic to the latest revision) and the previous deployment is torn
   down. A canary releases the previous revision with its promotion.
4. **Abort** (any time before the promotion): the new revision leaves the traffic split, the
   traffic stays where it was and the new deployment is torn down. A canary step above a
   threshold does the same by itself (`rolled_back`).
5. **Rollback** (after a blue-green promotion, while the previous revision is kept): all traffic
   back to the previous revision at once; it is the `active` deployment again and the new one is
   torn down.

A new deploy while a rollout is open replaces it: the open rollout is aborted (its share goes
back first) and the new deploy rolls out from the same revision that serves. A new deploy while a
promoted rollout keeps its previous revision releases that revision.

### Rollout statuses

| Status | Meaning |
| --- | --- |
| `pending` | The new revision is being created; 0 % of the traffic |
| `progressing` | Canary steps under way, or blue-green health checks before a promotion |
| `waiting_promotion` | Blue-green: ready, waiting for a promotion |
| `promoted` | The new revision has all traffic (`released_at` once the previous one is released) |
| `aborted` | Stopped before the promotion — by a user, a newer deploy or a deployment that ended |
| `rolled_back` | A canary threshold was exceeded, or a user rolled back after the promotion |
| `failed` | The new revision never became ready, or the blue-green health checks did not pass |

### The deployments during a rollout

| Deployment | During the rollout | After the promotion | After abort / rollback |
| --- | --- | --- | --- |
| New ("candidate") | `pending`, then `running` | `active` | torn down (`shut_down`) |
| Previous ("stable") | `active` | `running` while kept warm, then torn down | `active` |

The deployment list shows each deployment's part (`rollout_role`: `candidate`, `stable`,
`previous`). While both revisions run, both are [metered](monitoring.md) and the region needs room
for both. The deployment that serves while a rollout is under way cannot be deleted (409
`rollout_in_progress`); deleting the new deployment aborts its rollout first. A stopped project
ends its rollout.

## API

| Method and path | Permission | What it does |
| --- | --- | --- |
| `GET /api/projects/{project}/deploy-strategy` | `projects.view` | The settings, `eligible` (http / workflow trigger), `trigger_type`, `tracing` (canary thresholds can be judged), `defaults`, `environment`; `?environment=` an environment's, with `own`, `inherited` and `project` ([Environments](#environments)) |
| `PUT /api/projects/{project}/deploy-strategy` | `projects.update` (with `environment` also `environments.manage`) | A partial update: `strategy`, `blue_green` {…}, `canary` {…}; only the keys sent change, `null` clears an optional value. `environment`: what the environment sets of its own; `reset: true` drops it. 422 for an invalid value or a strategy on a queue / schedule function |
| `POST /api/projects/{project}/deploy` | `projects.deploy` | `strategy` (optional) overrides the strategy of the environment it deploys to for this deploy; the answer's `rollout` is the rollout it opened |
| `GET /api/projects/{project}/rollout` | `projects.view` | The newest rollout of the project's own workload, `?environment=` of an environment (`data: null` when it never rolled out) |
| `GET /api/projects/{project}/deployments/{deployment}/rollout` | `projects.view` | The rollout that brought this deployment |
| `POST …/deployments/{deployment}/rollout/promote` | `projects.deploy` | Promote now (`progressing` or `waiting_promotion`); 409 `rollout_not_promotable` |
| `POST …/deployments/{deployment}/rollout/abort` | `projects.deploy` | Abort an open rollout, optional `reason`; 409 `rollout_not_abortable` |
| `POST …/deployments/{deployment}/rollout/rollback` | `projects.deploy` | Back to the kept previous revision, optional `reason`; 409 `rollout_not_rollbackable` |

The actions answer the rollout; 502 `rollout_traffic_failed` when the region's cluster did not
take the new traffic split (nothing changed — try again), 409 `rollout_busy` while another change
of the same rollout runs.

A rollout carries the `environment` it rolls out (`production`: the project's own workload) and
its `environment_id`, its `strategy`, `status`, `traffic` (`candidate` / `stable` percentages),
`revision` and `stable_revision`, `test_url` and `previous_url`, the `options` it runs with,
`step` (canary: index, count, percent, next percent, start and end), `health` (blue-green checks:
passes, required, last answer, deadline), `metrics` (canary: `tracing`, `source` —
`span_metrics`, `knative` or `null` —, the `revision` measured, error rate, p95, request volume
and window, the thresholds), `keep_until`, `promoted_at`, `released_at`, `finished_at`,
`next_check_at`, `reason`, its `events` (created, ready, step, health, promoted, aborted,
rolled_back, failed, released, traffic_failed — with time, traffic and actor) and `can`
(`promote`, `abort`, `rollback`). Deployments carry `rollout` and `rollout_role`; projects carry
`deploy_strategy` (the strategy's name).

Changes are pushed live on the `platform` channel: `deployment.rollout` with the rollout and
`deployment.status` for the other deployment's status ([WebSocket events](websocket-events.md)).

## MCP and CLI

- MCP tools ([details](mcp.md)): `deployment_promote`, `deployment_abort` (an open rollout is
  aborted; after a promotion, while the previous revision is kept, it rolls back), and
  `deployment_status` shows the rollout; with `environment` they act on that environment's
  current rollout. `deploy` and `rollback` take `strategy` and stop waiting once the new revision
  is ready and its rollout under way.
- CLI ([details](cli.md#deploy-strategies)): `runr strategy show|set|reset [--env NAME]`, `runr
  rollout status|promote|abort|rollback [--env NAME]`, `runr deploy [--env NAME] --strategy …
  [--wait-rollout]`.

## Audit

The settings and a user's actions are audited by their route: `project.deploy_strategy.update`,
`deployment.rollout.promote`, `deployment.rollout.abort`, `deployment.rollout.rollback`; a deploy
that opened a rollout records it on `deployment.create` (`rollout_id`). What a rollout does by
itself is recorded with the actor `system`: `deployment.rollout.auto_promoted`,
`deployment.rollout.auto_rolled_back`, `deployment.rollout.failed`,
`deployment.rollout.released` ([audit log](security.md)).

## How it works

- **Traffic**: the deploy's apply names the new revision (`spec.template.metadata.name`) and
  writes `spec.traffic`; promotions, steps, aborts and releases change only `spec.traffic` (merge
  patch). A direct deploy of a project that rolled out before writes Knative's default traffic
  block back, so nothing a rollout pinned stays.
- **Revision and image retention**: revisions named in `spec.traffic` are routed, so Knative's
  revision garbage collection never removes the revisions a rollout holds, and
  [registry retention](images.md) keeps the images of every revision the service's traffic names
  (both `spec.traffic` and `status.traffic`), with or without a share.
- **Keeping the previous revision warm**: its PodAutoscaler gets `autoscaling.knative.dev/min-scale:
  1` for the keep window; a revision that leaves the routes scales to zero again whatever the
  annotation says. A scheduler that keeps functions warm should warm the revisions that **receive
  traffic** — during a canary both, during a blue-green wait the one that serves, not the latest
  ready one (`App\Services\Rollouts\RolloutTraffic::revisionsWithTraffic()` reads them from the
  service's `status.traffic`).
- **Health checks** go through the region's Kubernetes API proxy to the Knative activator,
  addressed to the new revision by the `Knative-Serving-Revision` header: no DNS or public route
  from RunR to the function is needed, and a revision scaled to zero is started.
- **Timers**: each rollout's next look is a queued job at its `next_check_at`
  (`AdvanceRolloutJob`); a scheduled job every minute (`deploy-rollouts`) catches one that was
  lost and ends rollouts whose deployments ended.
- **Test URLs** are Knative tag routes on the region's ingress, as public as the function's own
  host.

Operator settings (environment of the API and the queue workers):

| Variable | Default | Meaning |
| --- | --- | --- |
| `RUNR_ROLLOUT_HEALTH_INTERVAL` | `10` | Seconds between two blue-green health checks |
| `RUNR_ROLLOUT_HEALTH_TIMEOUT` | `10` | Seconds one health check may take |
| `RUNR_ROLLOUT_HEALTH_DEADLINE` | `300` | Seconds the checks have to pass after the revision is ready |
| `RUNR_ROLLOUT_METRICS_INTERVAL` | `60` | Seconds between two threshold checks of a canary step |
| `RUNR_ROLLOUT_MIN_REQUESTS` | `10` | Requests a canary window needs before a threshold is judged |
