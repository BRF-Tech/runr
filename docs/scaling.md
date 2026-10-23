# Scaling

How a project's workload scales on its region: scale to zero, the minimum and maximum number of
containers, and the timed warmer that keeps containers warm on a weekly rhythm. Projects of the
`schedule` trigger run as a Kubernetes CronJob and do not scale; everything below applies to the
other triggers, which run as a Knative Service.

## Scale settings

| Setting | API (`config.*`) | Default | Meaning |
| --- | --- | --- | --- |
| Scale to zero | `scale_to_zero` | `true` | Without traffic the workload stops all its containers; the next request starts one (a cold start). Off, at least one container always runs. |
| Minimum scale | `min_scale` | `0` | Containers that keep running without traffic (0–10). With scale to zero off, at least 1. |
| Maximum scale | `max_scale` | `1` | The most containers Knative starts under load (1–1000). |
| Timed warmer | `scale_schedule` | off | Rows of a cron expression and a container count; [see below](#timed-warmer). While set, it replaces the minimum scale. |

`min_scale` may not be above `max_scale`. The API checks the pair on create, on update (with the
value the request leaves unchanged) and on deploy (with the deploy's overrides); Knative would
refuse such a revision.

A scale-to-zero project keeps its maximum scale: it scales to zero when idle and up to
`max_scale` containers under load. In the console the **Zero Scale** mode has its own maximum
input.

Capacity admission counts what the scale settings keep running: an always-on workload
(`min_scale` ≥ 1 or scale to zero off) reserves its size × `max(1, min_scale)` against the region's
real capacity; a scale-to-zero workload counts one container against the region's FaaS overcommit
budget (see [Architecture](architecture.md#region-capacity-and-admission)).

## Timed warmer

The timed warmer keeps containers warm on a schedule, for example during office hours, and lets
the project scale to zero the rest of the week. A cold start never hits the first request of the
morning, and nothing runs at night.

It is a list of up to 10 rows. Each row has a five-field cron expression
(`minute hour day-of-month month day-of-week`) and a container count from 0 to 10, at most the
project's `max_scale`:

```json
"scale_schedule": [
  { "cron": "0 9 * * 1-5",  "min_scale": 3 },
  { "cron": "0 18 * * 1-5", "min_scale": 0 }
]
```

A row means **"from this moment on, at least N containers"** until the next row fires. The
schedule is a step function over the week: the row that fired last decides. The example keeps 3
containers warm on weekdays from 09:00 to 18:00 and none at other times. Two rows firing in the same
minute keep the larger count. A schedule none of whose rows has fired yet keeps none warm.

- **Time zone.** The crons run in the organization's time zone, an organization setting
  (Administration → Regions & settings, inside an organization Regions & domain → **Time zone**;
  `PATCH /api/settings {"timezone": "Europe/Berlin"}`). The default is
  `Europe/Istanbul`. In a multi-tenant install an organization without its own zone uses the
  platform's. Daylight saving time moves the rows with the wall clock. Schedule triggers without a
  zone of their own run in it too ([triggers.md](triggers.md#schedules-and-time-zones)).
- **Cron syntax.** Five fields, as the Kubernetes CronJob controller accepts them: numbers, `*`,
  ranges (`1-5`), steps (`*/15`), lists (`0,30`), month and day names (`JAN`, `MON-FRI`; 7 is
  Sunday too). No `@daily`-style macros, seconds, time zone prefixes or `L` / `W` / `#`. When both
  the day of month and the day of week are restricted, a day matching either one fires, as in
  Vixie cron.
- **Replaces `min_scale`.** While a schedule is set, the minimum scale is ignored; scale to zero
  off still keeps one container, and `max_scale` still caps the workload. A deploy's
  `min_scale` override does not apply while the project has a schedule. Turning the warmer off
  (`"scale_schedule": []` or `null`) makes `min_scale` apply again.
- **No deploy needed.** A saved schedule is applied to the running workload within a minute; a
  deploy is not required. Every deploy also starts the new revision with the value of that moment.
- **Previews** (git deploy) never use the warmer: they scale to zero.

### How it is applied

Every minute the API's scheduler (`timed-warmer`, `php artisan projects:warm` runs it at once)
computes each project's value for the minute and sets it on the Knative **PodAutoscaler** of the
project's latest ready revision, as the annotation `autoscaling.knative.dev/min-scale`. It patches
only when the value differs, reading the PodAutoscalers and Knative Services of a region with one
list call each. Regions that are offline, inactive or demo regions are skipped until they are back.

Setting the value on the PodAutoscaler takes effect without a new revision and without restarting
the running containers. It survives Knative's reconciles and controller restarts, and a lower
value releases the extra containers after Knative's scale-down window. The Revision is never
annotated: its `min-scale` does not reach the existing PodAutoscaler, and changing it restarts the
revision's pods. A new revision gets a fresh PodAutoscaler from the Knative Service template, so:

- every deploy renders the schedule's current value into the template (the new revision starts
  with the right number of containers), and
- once the revision is ready, the value of that moment is applied again to its PodAutoscaler.

While a [blue-green or canary rollout](deploy-strategies.md) splits the traffic, every revision
that takes a share — the one that served and the new one — gets the value, and the new revision
gets it as soon as it is ready.

The project shows the value last applied and when (`warm_scale: {applied, applied_at}` in the
project API, "Warm now" in the console). It is `null` while no warmer runs.

### Environments

Each [environment](environments.md) of a project runs the project's schedule unless it has its
own: `overrides.scale_schedule` of the environment — rows replace the project's schedule there,
`[]` keeps no containers warm there, no value follows the project's (also when the project's
schedule changes later). The warmer applies it the same way, every minute and in the
organization's time zone, to the environment's own workload (`<slug>-<environment>`) in the
environment's region, and to every revision that takes traffic there — both revisions while a
[blue-green or canary rollout](deploy-strategies.md#environments) of the environment splits it. Its
count is checked against the environment's `max_scale` (else the project's), its peak is what
the environment reserves, and its deployments log what they ran with for metering. A schedule
turned off in an environment gives its PodAutoscaler its `min_scale` back once. The environment
shows the schedule it runs (`scale_schedule`, `scale_schedule_inherited`) and what was last
applied there (`warm_scale`) — the project's `warm_scale` stays its own workload's.

### Capacity and billing

- **Admission** counts the schedule's **peak**, the largest count of its rows, as the project's
  `min_scale`. The region capacity, the organization quota and GPU quota hold the containers the
  schedule keeps warm at its busiest time.
- **Fixed-price regions** ([Billing](billing.md#what-a-fixed-project-may-use)): the allocation must
  hold the project's worst case, every container up to `max_scale`. A row's count is never above
  `max_scale`, so the schedule's peak always fits an allocation that fits the project.
- **Pay as you go**: metering counts the warm containers the warmer actually applied, stretch by
  stretch, as a reservation (custom scale), and the sessions that started while it kept none as
  sessions ([Monitoring](monitoring.md#metering)). The console shows an estimate of the next 30
  days under the editor: warm container-hours, vCPU-hours and GB-hours, and the cost at the
  region's prices for those who may see prices. Fixed regions show no estimate.

### Preview

`POST /api/scale-schedule/preview` evaluates a schedule before it is saved, the way the warmer
applies it:

```json
{
  "scale_schedule": [{ "cron": "0 9 * * 1-5", "min_scale": 3 }, { "cron": "0 18 * * 1-5", "min_scale": 0 }],
  "knative_region_id": "01J…", "cpu": "250m", "memory_mb": 256, "scale_to_zero": true, "max_scale": 5
}
```

It answers the organization's zone, the start of the current week, the value now, the peak, a
`timeline` of 7 × 24 cells (Monday first: the most containers kept warm during each hour of this
week) and, in a pay-as-you-go region with billing on, the `estimate`. Rows are validated as on a
project, errors keyed `scale_schedule.N.cron` and `scale_schedule.N.min_scale`.

### Everywhere else

| Where | How |
| --- | --- |
| Console | Scale settings → **Advanced: timed warmer**: rows, a plain-language explanation of each row, the weekly timeline, the estimate |
| `runr.yaml` | `scale.schedule: [{cron, min}]`; `[]` turns it off ([CLI](cli.md#runryaml), [git deploy](git-deploy.md)) |
| CLI | `runr scale <project> --warm "0 9 * * 1-5=3" --warm "0 18 * * 1-5=0"`, `--no-warm` ([CLI](cli.md)) |
| Terraform | `runr_project.scale_schedule` ([Terraform](terraform.md)) |
| MCP | `project_create` / `project_update` take `scale_schedule`, `project_get` shows it ([MCP](mcp.md)) |
| Environments | `overrides.scale_schedule` of an [environment](environments.md#configuration); console: the environment's settings → **Timed warmer** (the project's schedule, one of its own, none); CLI `runr environments update NAME --warm … \| --no-warm \| --project-warm`; MCP `environment_create` / `environment_update` |
