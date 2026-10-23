# Triggers

A trigger is what invokes a function: an HTTP request, a schedule, a message on a queue, a
webhook from another service, an event on a topic, an event of RunR itself. A project has a
**list** of triggers — any combination of the types below — and every event reaches the same
deployed function.

| Type | What invokes the function | Runs on | Needs |
| --- | --- | --- | --- |
| `http` | Requests to the project's URL (subdomain, gateway route, custom domains) | the Knative Service (public) | — |
| `schedule` | A cron schedule in a time zone (the organization's unless it names one, [below](#schedules-and-time-zones)) | the CronJob when it is the only trigger, the event gateway otherwise | — |
| `queue` | Messages of a Redis Stream, one consumer group per project | the event gateway | Redis on the region |
| `topic` | Events of an organization topic; every subscribed function gets each event | the event gateway | Redis on the region |
| `webhook` | Requests to a stable public inbox URL, signature-checked, stored, replayable | the event gateway (via the inbox) | — |
| `platform_event` | RunR events: deployments, alerts, runtime patches, cost limits, promotions between environments | the event gateway (via the inbox) | — |
| `workflow` | [Durable workflows](workflows.md) (the engine) | the Knative Service (public) | its only trigger |
| `kafka`, `nats`, `rabbitmq`, `mqtt` | Messages of a broker ([below](#broker-and-external-sources)) | the event gateway | the broker's address and credentials |
| `cdc` | Row changes of Postgres tables (logical replication) | the event gateway | a role with `REPLICATION` |
| `s3` | Objects created, changed or deleted in a bucket of any S3 API | the event gateway | the bucket's keys |
| `email` | Messages arriving in an IMAP mailbox | the event gateway | the mailbox password |

A change of the trigger list reaches the cluster with the project's next deploy.

A [global project](global-projects.md#triggers) may have any trigger list: HTTP and workflow
triggers answer in every region, every other trigger runs in its primary region only (the CronJob
and the event gateway of the other regions wait on standby) and moves with the primary on a
switchover or failover.

## The trigger list

Every trigger has a `name` (unique in the project, `[a-z0-9-]`, it names consumer groups, gateway
entries and metrics), a `type`, its `config`, write-only `secrets` and an `enabled` switch. The
order matters once: the **first enabled trigger is the primary one**. The single-trigger fields
of the project config — `config.trigger_type`, `config.schedule`, `config.queue_stream`, what
API clients, the Terraform provider and runr.yaml's `trigger:` used before trigger lists —
mirror the primary trigger, in both directions:

- setting `config.trigger_type` (and `schedule` / `queue_stream`) through the project API changes
  the primary trigger's type and settings; the other triggers stay;
- changing the list writes its primary trigger back into those fields.

Every existing project got one trigger from its config with the upgrade (tenant migration
`0077_create_trigger_tables`).

Limits: 20 triggers per project; one `http` trigger; `workflow` only alone; a project keeps at
least one trigger (disable it instead of removing it).

## What a project runs

`App\Triggers\TriggerPlan` decides it from the enabled triggers:

| Enabled triggers | Workload |
| --- | --- |
| a `schedule` alone | CronJob `<slug>-trigger`, as schedule projects always ran (the handler runs in `schedule` mode) |
| anything with `http` or `workflow` | public Knative Service `<slug>`; every other trigger through the event gateway |
| only gateway triggers (queue, topic, webhook, platform events, several schedules, broker sources …) | cluster-local Knative Service; the function runs in `queue` mode (`RUNR_TRIGGER=queue`) |
| none (all disabled) | cluster-local Knative Service nothing calls |

**Schedules next to other triggers.** A schedule that is not the project's only trigger does not
start a CronJob pod: the event gateway delivers each tick as the CloudEvent
`dev.runr.schedule.tick` (`ce-id` `<trigger>-<unix time of the tick>`, data
`{"schedule": "<cron>", "timezone": "<zone>", "scheduled_time": "<RFC 3339, UTC>",
"local_time": "<RFC 3339 in the zone>"}`) to the same service as every other event — one warm
workload, no per-tick pod, no image pull. A tick that comes while the previous one is still being
delivered is skipped. A project whose only trigger is a schedule keeps its CronJob.

The runtime must support what the list needs (runtime.yaml `triggers`, [runtimes.md](runtimes.md)):
`http` next to an HTTP trigger (gateway events then arrive as HTTP requests of the public
service), `queue` for an event-only project, `schedule` for the CronJob.

### Schedules and time zones

A schedule runs in a time zone: `config.timezone`, an IANA name (`Europe/Berlin`,
`America/New_York`, `UTC`; the backward-compatible names PHP knows, such as `Europe/Kiev`, too).
Without one it runs in the **organization's time zone** — the zone of the
[timed warmer](scaling.md#timed-warmer) (Administration → Regions & settings, inside an organization Regions &
domain → **Time zone**; `PATCH /api/settings
{"timezone": …}`; `Europe/Istanbul` by default, in a multi-tenant install the platform's zone for
an organization without its own) — and follows it.

- **Where it applies.** A schedule alone renders its CronJob with `spec.timeZone`; a schedule next
  to other triggers gives the gateway's schedule source the zone (the gateway image carries the
  zone database). Both use the cron parser of the CronJob controller, so both fire at the same
  wall-clock times, daylight saving changes included.
- **Following the organization.** The deploy renders the organization's zone of the moment and
  marks what follows it (the CronJob's annotation `runr.dev/timezone-from: organization`, the
  gateway entry's `source.timezone_from`). When the organization's zone changes, RunR patches
  those CronJobs and the schedule entries of the deployed gateway configs at once (the gateway pod
  restarts on its new config) — nothing else of a deployed trigger list changes there. A schedule
  with a zone of its own keeps it.
- **The API** returns `config.timezone` as set and, on every schedule trigger,
  `effective_timezone` (the zone it runs in) and `timezone_source` (`trigger` or
  `organization`). `runr triggers ls` shows the zone next to the cron, the console's trigger
  editor has a time zone field (empty: the organization's).
- **The single-trigger fields** of the project API (`POST` / `PUT /api/projects`, Terraform's
  `runr_project.trigger`, the MCP tools `project_create` / `project_update`) take
  `config.schedule_timezone` — the zone of the project's schedule trigger, `null` or `""` for the
  organization's — and return it with `config.schedule_effective_timezone`
  ([terraform.md](terraform.md)).
- **Upgrade.** Schedules from before time zones ran in UTC and keep running there: the upgrade
  sets `timezone: UTC` on them (migration `2026_10_22_200001`, tenant migration `0090`). New
  schedules without a zone run in the organization's.

## The event gateway

The project's event gateway is the Deployment `<slug>-qc` (image
`ghcr.io/brf-tech/runr/queue-consumer`, `runtime/queue-consumer`; the name is the queue consumer's
of earlier versions). It runs one **source** per gateway trigger and delivers every event the
same way:

- **CloudEvents over HTTP** (binary mode) to the function's cluster-local URL: `ce-specversion`,
  `ce-id`, `ce-type`, `ce-source`, `ce-time`, `ce-subject`, one `ce-<extension>` per extension,
  `Content-Type` = the event's data content type, `X-RunR-Delivery-Attempt`, `X-RunR-Trigger`; the
  body is the event data.
- **2xx = delivered.** A redirect is not followed and is not an acknowledgement.
- **Retries** with exponential backoff (`retry_backoff_seconds` doubled per attempt, capped at
  `retry_backoff_max_seconds`), at most `max_retries` retries.
- **Dead letter** after the last retry: queue and topic triggers copy the message to a
  dead-letter stream (`<stream>-dlq`; topic subscriptions `<topic stream>.<group>-dlq`) with
  `runr.dlq.*` metadata; webhook and platform events become `failed` (and can be replayed).
- **Per-trigger concurrency**: at most `concurrency` deliveries of a trigger at once (a slot is
  held during the request, not during a backoff).
- **At least once**: a source acknowledges an event only once it is delivered or dead-lettered;
  a gateway that stops mid-delivery leaves it to be delivered again (Redis pending entries,
  inbox leases). Functions should treat `ce-id` as an idempotency key.
- **Metrics** on port 8081: `GET /metrics` (Prometheus text: `runr_gateway_deliveries_total`,
  `runr_gateway_failed_attempts_total`, `runr_gateway_retries_total`, `runr_gateway_in_flight`,
  `runr_gateway_delivery_seconds`, `runr_gateway_source_restarts_total`, per `trigger` and
  `type`), `GET /stats` (JSON), `GET /healthz`.

Delivery settings, per gateway trigger (`config.delivery`; defaults in `config/triggers.php`):

| Key | Default | Range |
| --- | --- | --- |
| `concurrency` | 4 | 1–32 |
| `max_retries` | 5 | 0–20 |
| `retry_backoff_seconds` | 2 | 1–300 |
| `retry_backoff_max_seconds` | 300 | 1–3600 |

The delivery timeout is the project's `timeout_seconds`.

**Configuration and secrets.** A project whose only gateway trigger is one `queue` trigger with
the default settings keeps the queue consumer's env-only form of earlier versions (`STREAM`,
`GROUP`, `SINK_URL`, Redis credentials from the Secret `<slug>-qc-redis`) — unchanged manifests
for queue projects ([legacy env mode](#legacy-env-mode)). Every other gateway reads
`gateway.json` from the Secret `<slug>-qc-config` (mounted read-only at `/etc/runr/gateway`):
Redis credentials, the inbox token, broker passwords are there and never in the Deployment spec
([security.md](security.md)). The Service `<slug>-qc` (port 8081) lets the API nudge the gateway
when its inbox has new events; without a nudge an inbox source asks the API every 15 seconds
(`TRIGGERS_INBOX_POLL_SECONDS`).

**Capacity.** The gateway pod (50m CPU, 64 MiB) counts in the project's reservation like the queue
consumer always did ([billing.md](billing.md)). The region's topic publisher `runr-topics` (50m
CPU, 64 MiB) is a region component, not a project's: like gateway-auth and the internal-network
authz it counts as a platform pod — the region status check subtracts its requests from the region
capacity ([architecture.md](architecture.md#region-capacity-and-admission)). It tolerates
dedicated workers and prefers the other nodes.

### Legacy env mode

The env-only form is the gateway image without `GATEWAY_CONFIG`: the queue consumer's environment
(`REDIS_ADDRESS`, `REDIS_PASSWORD`, `STREAM`, `GROUP`, `CONSUMER`, `SINK_URL`, `DLQ_STREAM`,
`DLQ_MAXLEN`, `MAX_RETRIES`, `RETRY_BACKOFF`, `RETRY_BACKOFF_MAX`, `DELIVERY_TIMEOUT`,
`BATCH_SIZE`, `CONCURRENCY`, `CLAIM_MIN_IDLE`) becomes one redis-stream trigger. What the queue
consumer guaranteed holds:

- an entry is acknowledged only once delivered (2xx) or dead-lettered;
- the dead-letter write and the acknowledgement are one `MULTI` / `EXEC` (`XADD` to `<STREAM>-dlq`
  with the `runr.dlq.*` fields, `XACK`) — an entry is never copied to the dead letter twice by a
  gateway that stopped in between, and never acknowledged without its record (the same holds for
  queue triggers and topic subscriptions in `gateway.json`, whose dead letter is on the same Redis);
- without `SINK_URL` it delivers to `http://localhost:8080`, the queue consumer's default (and logs
  it).

What differs from the queue consumer, by design:

- retries run in the gateway with the same backoff; Redis' delivery count still counts them across
  a restart (an entry that used up its retries before a restart is dead-lettered at once);
- another consumer's pending entry (a pod that was restarted or evicted mid-delivery) is claimed
  once idle for `CLAIM_MIN_IDLE` (default 30s), where the queue consumer claimed it once idle for
  its next backoff; the gateway keeps the entries it is delivering fresh, so a long retry is not
  claimed away;
- `CONCURRENCY` (default 1) delivers several entries at once; `GET /stats` keeps the queue
  consumer's keys and adds `/metrics`.

**Internal calls.** The function is a Knative Service on the region's
[internal network](internal-network.md): the gateway calls it as the project itself. Both forms
read the project's `RUNR_INTERNAL_TOKEN` from its Secret `<slug>-secret-env` and send it in
`X-Runr-Internal-Token` with every delivery attempt; without the token (a gateway of an earlier
release) the region's authz knows the gateway by its pod's address, whose labels name the project
(`runr/project-id`, `runr.dev/internal-name`).

## Webhook inbox

A `webhook` trigger has a stable public URL:

```
https://<console>/api/hooks/<context>/<key>
```

(`<context>`: the organization; the base is `TRIGGERS_WEBHOOK_BASE_URL`, else the git webhook base,
else the console URL). The URL stays the same while the trigger exists — changing its settings
does not change it.

On `POST` (or `PUT`) RunR:

1. checks the body size (`max_body_kb`, at most `TRIGGERS_WEBHOOK_MAX_BODY_KB`, 1 MiB) → `413`;
2. verifies the signature with the trigger's preset → `401`;
3. drops a delivery id it already stored → `200 {"status": "duplicate"}` (nothing is delivered twice);
4. stores the request (headers without `Authorization` / `Cookie`, the raw body) →
   `200 {"id": "…", "status": "stored"}`. The sender is done here.

The gateway then delivers the stored event as `dev.runr.webhook.received` (`ce-source`
`/webhooks/<trigger>`, `ce-subject` the sender's event — GitHub event, Stripe / Slack event type,
`ce-runrdelivery` the delivery id, `ce-runrheaders` the sender's headers as JSON, `Content-Type`
and body as sent). An unknown or disabled inbox answers `404`, a stopped project `409`.

| `config.verify` | Signature | Delivery id (dedupe) |
| --- | --- | --- |
| `github` | `X-Hub-Signature-256: sha256=<hex HMAC-SHA256 of the body>` | `X-GitHub-Delivery` |
| `stripe` | `Stripe-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<body>">`, within `tolerance_seconds` (300) | the event's `id` |
| `slack` | `X-Slack-Signature: v0=<hex HMAC-SHA256 of "v0:<X-Slack-Request-Timestamp>:<body>">`, within `tolerance_seconds`; the `url_verification` handshake is answered | the event's `event_id` |
| `hmac` | `config.hmac.header` (default `X-Signature`): HMAC of the body with `algorithm` `sha256` / `sha1` / `sha512`, `encoding` `hex` / `base64`, after an optional `prefix` (e.g. `sha256=`) | `config.delivery_id_header` |
| `none` | no check — keep the URL secret | `config.delivery_id_header` |

The signing secret is the write-only `secrets.signing_secret` (required unless `none`).
`config.delivery_id_header` names the delivery id header for any preset.

**Retention and replay.** Stored events stay for `retention_days` (default 7,
`TRIGGERS_EVENT_RETENTION_DAYS`; at most `TRIGGERS_EVENT_RETENTION_MAX_DAYS`, 30) and are pruned
daily (`triggers:prune`). The console's inbox viewer, the API, the CLI (`runr triggers events`,
`runr triggers replay`) and the MCP tools list them, show headers and body, and **replay** one
event or every failed one: the event is pending again and delivered with fresh retries
(`ce-runrreplay` counts the replays). Viewing bodies and replaying need `replay.manage`.

## Topics

A topic fans an event out to every subscribed function. Topics belong to the organization and
live on a region's Redis as the stream `runr.topic.<name>` (with the organization suffix of queue
streams in a multi-tenant install). A queue is one consumer group on one stream; a topic gives
**every subscribing trigger a consumer group of its own** (`sub.<project>.<trigger>`), so each gets
every event.

- Create topics in the console (Topics), with `POST /api/topics` or `runr topics create`
  (`topics.manage`); a region needs Redis. A subscribed topic is not deleted (`409`).
- Subscribe a project with a `topic` trigger: `config.topic`, `config.start_from` `latest`
  (default: events published from now on) or `earliest` (what the stream still holds). The
  project must be in the topic's region.
- Each topic keeps its last `TRIGGERS_TOPIC_MAX_LEN` (10 000) events; an event is at most
  `TRIGGERS_TOPIC_MAX_BYTES` (256 KiB).

Subscribers get `ce-type` as published (default `dev.runr.topic.message`), `ce-source`
`/topics/<name>`, `ce-runrpublisher` the publishing project (`/projects/<id>`, `/runr` for the API).

### Publishing

- **From a function.** In a region where its organization has a topic, a function gets
  `RUNR_TOPICS_URL` (the region's publisher, `runr-topics`) and `RUNR_TOPICS_TOKEN` (its publish
  token, from the project's secret env Secret). The SDK helpers wrap the call:

  ```python
  from runr_workflows import publish
  publish("orders.created", {"order": 17}, type="shop.order.created")
  ```

  ```ts
  import { publish } from '@runr/workflows'
  await publish('orders.created', { order: 17 }, { type: 'shop.order.created' })
  ```

  or plain HTTP: `POST $RUNR_TOPICS_URL/v1/topics/<name>/events` with
  `Authorization: Bearer $RUNR_TOPICS_TOKEN`, the data as body, its `Content-Type`, optional
  `ce-type` / `ce-subject` / `ce-id` → `202 {"id", "stream_id"}`. A token only reaches its
  organization's topics. A function deployed before its region's first topic gets the variables
  with its next deploy.
- **From outside.** `POST /api/topics/<topic>/publish` `{"data": …, "type"?, "subject"?, "id"?,
  "content_type"?}` (`events.send`), `runr topics publish <topic> --data '{"order":17}'`, the MCP
  tool `topic_publish`, or the console's test publish.

The region's publisher (Deployment + Service `runr-topics`, the gateway image in publisher mode) is
applied with the first topic of the region and by deploys that hand out its URL.

## Platform events

A `platform_event` trigger sends RunR's own events to the function:

| Event | When | CloudEvent type |
| --- | --- | --- |
| `deployment.finished` | a deployment became active | `dev.runr.deployment.finished` |
| `deployment.failed` | a deployment failed | `dev.runr.deployment.failed` |
| `alert.fired` | an alert rule fired (and each reminder after its cooldown) | `dev.runr.alert.fired` |
| `alert.resolved` | an alert resolved | `dev.runr.alert.resolved` |
| `runtime.patch_available` | a patch of a project's runtime was published | `dev.runr.runtime.patch_available` |
| `cost.limit_reached` | a project or the organization reached its cost limit | `dev.runr.cost.limit_reached` |
| `environment.promotion.pending_approval` | a promotion between environments waits for an approver ([Environments](environments.md#notifications)) | `dev.runr.environment.promotion.pending_approval` |
| `environment.promotion.approved` | a promotion was approved | `dev.runr.environment.promotion.approved` |
| `environment.promotion.rejected` | a promotion was rejected | `dev.runr.environment.promotion.rejected` |
| `environment.promotion.deployed` | a promotion's deployment went live in the target environment | `dev.runr.environment.promotion.deployed` |
| `environment.promotion.failed` | a promotion failed (its deployment failed or was refused) | `dev.runr.environment.promotion.failed` |

`config.events` lists the events (`["*"]` for all), `config.projects` optionally limits them to
events about those projects (default: every project of the organization). The data is JSON:
`{"event", "project_id", "occurred_at", "data": {…}}`; `ce-subject` is the project id. A promotion's
data is `{promotion_id, project_name, version_id, version, source_environment, target_environment,
status, message, requested_by, decided_by, decision_comment, deployment_id, status_message}` —
names, never a configuration value; forward it to a chat or an incident tool from the function. Each
occurrence is delivered once (`ce-runrdelivery`), stored like a webhook event and replayable.
Features raise events with `app(App\Triggers\PlatformEvents::class)->dispatch($event, $data,
$projectId, $occurrenceKey)`.

## Broker and external sources

The event gateway also reads systems outside RunR. Each source keeps its credentials in the
trigger's write-only `secrets`; they reach only the gateway's config Secret. Every source
acknowledges (commits, acks) an event only once it is delivered or dead-lettered — a gateway that
stops before leaves it to the broker to redeliver — and gives each event a stable `ce-id` where the
system has one. The console renders their settings from the type's fields; the CLI sets them with
`runr triggers add --type <type> --set key=value --secret key=value`.

| Type | Reads | Order and guarantee | Secrets |
| --- | --- | --- | --- |
| `kafka` | a topic through a consumer group (`group_id`, default `runr.<project>.<trigger>`) | in order per partition (one group member per `concurrency`), offset committed after delivery; at least once | `sasl_password` |
| `nats` | subjects; with `stream` a JetStream durable pull consumer (`durable`, default `runr-<project>-<trigger>`), else a core NATS queue group | JetStream: ack after delivery, "in progress" while retries run, at least once; core NATS: at most once | `password`, `token`, `nkey_seed` or `creds` (`auth.mode`) |
| `rabbitmq` | a queue (`declare: true` declares it durable with `bindings`) | manual acks, requeued when the gateway stops first; `prefetch` default 2 × concurrency; at least once | `url` (`amqp[s]://user:password@host/vhost`) |
| `mqtt` | a topic filter, QoS 1 by default, persistent session (`clean_session: false`), optional `$share/<shared_group>/…` | acks after delivery (QoS 1 / 2: at least once; QoS 0: at most once); MQTT has no stable message id, so a redelivery gets a new `ce-id` | `password` |
| `cdc` | Postgres logical replication (pgoutput) of a `publication` through a slot (`slot`, default `runr_<project>_<trigger>`; `create_publication` with `tables`, `create_slot`) | strictly in commit order, one delivery at a time; the slot advances once every change of a transaction is delivered | `dsn` (a role with `REPLICATION`) |
| `s3` | a bucket prefix on any S3 API (the region's object store, MinIO, AWS S3 …), listed every `poll_interval` (30s); a bucket of the region's object store is reached once its binding is attached to the project (the data namespace admits the pods of attached projects only, [Bindings](bindings.md)) | `created` / `updated` (etag changed) / `deleted` (opt-in) per change; a key's state advances once its event is delivered; `start_from: latest` takes the first listing as baseline, `checkpoint_key` keeps the listing in the bucket across restarts | `access_key`, `secret_key` |
| `email` | an IMAP mailbox (`host`, `port` 993, `tls` implicit / starttls / none, `mailbox` INBOX), unseen messages every `poll_interval` | the message is marked seen, moved (`move_to`) or deleted (`after`) once delivered | `password` |

Events:

| Type | `ce-type` | `ce-source` | `ce-subject` | data |
| --- | --- | --- | --- | --- |
| `kafka` | `dev.runr.kafka.message` | `/kafka/<topic>` | the key | the value as sent (`ce-kafkapartition`, `ce-kafkaoffset`, `ce-kafkaheaders`) |
| `nats` | `dev.runr.nats.message` | `/nats/<subject>` | the subject | the payload (`ce-natsheaders`) |
| `rabbitmq` | `dev.runr.rabbitmq.message` | `/rabbitmq/<queue>` | the routing key | the body with its content type (`ce-rabbitexchange`, `ce-rabbitheaders`) |
| `mqtt` | `dev.runr.mqtt.message` | `/mqtt/<topic>` | the topic | the payload (`ce-mqttqos`, `ce-mqttretained`) |
| `cdc` | `dev.runr.cdc.insert` / `update` / `delete` | `/cdc/<database>/<schema>.<table>` | `<schema>.<table>` | JSON `{op, schema, table, lsn, commit_time, xid, new, old}` (values as Postgres text) |
| `s3` | `dev.runr.s3.object.created` / `updated` / `deleted` | `/s3/<bucket>` | the key | JSON `{bucket, key, etag, size, last_modified, event}` |
| `email` | `dev.runr.email.received` | `/email/<user>/<mailbox>` | the Subject | JSON `{message_id, from, to, cc, reply_to, subject, date, text, html, headers, attachments, truncated}` (`raw_base64` with `include_raw`) |

Notes:

- `tls` (`enabled`, `ca_pem`, `insecure_skip_verify`) configures the Kafka, NATS, RabbitMQ
  (`amqps://`) and MQTT (`ssl://`, `wss://`) clients; IMAP uses the system roots.
- When a `cdc` trigger goes, RunR drops its replication slot and the publication it created
  ([CDC cleanup](#cdc-cleanup)).
- `cdc` forces `concurrency: 1`; Kafka keeps the order per partition with one group member per
  concurrency step.
- The gateway pod connects out to the systems: network policies of the project namespace and the
  systems' own allow lists must let it.

### CDC cleanup

Postgres keeps WAL for a replication slot until it is read, so a slot nobody reads fills the
database's disk. When a `cdc` trigger goes, RunR drops what it left on the database:

| When | What is dropped |
| --- | --- |
| the trigger is removed (`DELETE`, a `PUT` / runr.yaml list without it, the single-trigger fields retyping it) | its slots and the publication it created |
| it is renamed, or its `slot`, database (`dsn`) or created publication changes | what it no longer reads |
| its project is deleted | every cdc trigger's slots and publications |
| an environment is deleted, the project renamed | the default slots of the old workload name |

The slots are the trigger's own `slot`, or the default `runr_<workload>_<trigger>` of the
project's workload and of each environment's. A publication is dropped only when the trigger had
`create_publication` and the publication carries RunR's mark (the gateway comments a publication it
creates: `Created by RunR …`); one that existed before stays.

- **Not while it is read.** A trigger change reaches the gateway with the next deploy, so RunR
  waits while a deployed gateway config still lists the slot (it looks every 10 minutes, for up to
  7 days), and never drops a slot another cdc trigger of the organization reads.
- **From the region.** The drop runs where the gateway ran: a one-shot pod of the gateway image
  (`GATEWAY_MODE=cdc-cleanup`) in the project namespace, labelled as the project's pod, with the
  DSN in a short-lived Secret. A slot still in use by another connection is waited for up to a
  minute; an unreachable database or a busy slot is tried three times.
- **The outcome.** The API's answer to the change lists what RunR drops (`cleanup`: trigger,
  slots, publication, message), and `runr triggers rm` / `update` / `runr deploy` and the console
  print it. When it is done the user who made the change (else the project's owner) gets a
  notification — what was dropped, or what is left with the SQL to run on the database, e.g.
  `SELECT pg_drop_replication_slot('runr_shop_orders'); DROP PUBLICATION IF EXISTS "runr_orders";`.

## API

| Method and path | Permission | |
| --- | --- | --- |
| `GET /api/trigger-types` | signed in | the registered types, platform events, webhook presets |
| `GET /api/projects/{project}/triggers` | `projects.view` | the list and what it deploys (`plan`) |
| `POST /api/projects/{project}/triggers` | `projects.update` | `{type, name?, enabled?, position?, config?, secrets?}` |
| `PUT /api/projects/{project}/triggers` | `projects.update` | `{triggers: [...]}` replaces the list; a trigger with the same name and type keeps its secrets, inbox URL and events |
| `PATCH /api/projects/{project}/triggers/{trigger}` | `projects.update` | `{name?, enabled?, position?, config?, secrets?}`; `position: 0` makes it primary; secrets are merged (`""` removes one) |
| `DELETE /api/projects/{project}/triggers/{trigger}` | `projects.update` | |
| `GET /api/projects/{project}/triggers/{trigger}/events` | `replay.manage` | `?status=&per_page=` |
| `GET /api/projects/{project}/triggers/{trigger}/events/{event}` | `replay.manage` | headers and body |
| `POST /api/projects/{project}/triggers/{trigger}/events/{event}/replay` | `replay.manage` | |
| `POST /api/projects/{project}/triggers/{trigger}/events/replay` | `replay.manage` | `{status: failed\|delivered\|all, ids?}` |
| `GET /api/topics`, `GET /api/topics/{topic}` | `projects.view` (any project) | |
| `POST /api/topics`, `PATCH`, `DELETE /api/topics/{topic}` | `topics.manage` | |
| `POST /api/topics/{topic}/publish` | `events.send` | |

`{trigger}` is the trigger's id or name, `{topic}` the topic's id or name. The project resource
(`GET /api/projects/{project}`) carries `triggers` too; secrets are never returned, only which are
set. A schedule trigger carries `effective_timezone` and `timezone_source`
([time zones](#schedules-and-time-zones)). The answer of a `PUT`, `PATCH` or `DELETE` that left a
cdc trigger's slots behind carries `cleanup: [{trigger, type, slots, publication, status,
message}]` ([CDC cleanup](#cdc-cleanup)).

## runr.yaml

```yaml
triggers:
  - type: http
  - type: schedule
    name: nightly
    cron: "0 3 * * *"        # or schedule:
    timezone: Europe/Berlin  # default: the organization's time zone
  - type: queue
    stream: orders           # or queue:
    delivery: { concurrency: 8, max_retries: 3 }
  - type: webhook
    name: github
    verify: github           # the signing secret is set in RunR, not here
  - type: topic
    topic: orders.created
  - type: platform_event
    events: [deployment.failed, alert.fired]
  - type: kafka
    brokers: [kafka-1:9092, kafka-2:9092]
    topic: orders
    sasl: { mechanism: scram-sha-512, username: orders-fn }   # sasl_password: set in RunR
  - type: s3
    endpoint: s3.example.com
    bucket: uploads
    prefix: incoming/        # access_key / secret_key: set in RunR
```

`triggers:` replaces the whole list on push and `runr deploy` and wins over the single
`trigger:` block; `type`, `name`, `enabled` are trigger fields, every other key goes into the
trigger's config. A trigger with the same name and type as an existing one keeps its secrets and
inbox URL. See [cli.md](cli.md#runryaml).

The CLI checks the entries with the API's own rules before it sends them: `php artisan
triggers:schema` derives a JSON Schema of a `triggers:` entry from the trigger types' validation
rules (`api/resources/triggers/triggers.schema.json`; a test fails when the file and the rules
differ), and the CLI embeds the same file (a test fails when the copies differ). What the schema
cannot express — rule objects other than cron and time zone, rules that depend on other fields or
on install settings — is left to the API, so the CLI is never stricter than the API. What the API
ignores is a warning, not an error: `trigger:` next to `triggers:` (`triggers:` is used), a
`secrets` key (not sent), keys a type does not have.

## Adding a trigger type

A new type is two pieces (the broker sources were added this way); run `php artisan
triggers:schema` afterwards and copy the file to `cli/internal/projectfile/triggers.schema.json`:

- **API**: a subclass of `App\Triggers\TriggerType` (or `Types\GatewayTriggerType` for the common
  delivery settings) — `key()`, `rules()` / `secretRules()` (validation of `config.*` /
  `secrets.*`), `normalize()`, `validate()`, `requiresRedis()`, `gatewayEntry()` (the Go source
  kind and its settings, rendered into `gateway.json`), `fields()` (the console's generic form for
  the type; labels in `lang/<locale>/triggers.php` `fields.<type>.<key>`), `present()`,
  `entryPoint()`, `deleted()` / `changed()` (what a removed or changed trigger leaves outside
  RunR, as the cdc type's slots) — registered
  in `App\Providers\TriggerServiceProvider` (or any provider:
  `app(TriggerTypes::class)->register(...)`). Credentials go into `secrets` and from there only
  into the gateway's config Secret.
- **Gateway**: a Go package under `runtime/queue-consumer/sources/<kind>` implementing

  ```go
  type Source interface {
      Name() string
      Run(ctx context.Context, emit func(context.Context, source.Event) error) error
  }
  ```

  registered in `init()` with `source.Register("<kind>", factory)` and blank-imported in
  `main.go`. A source reads its broker and calls `emit` per event; `emit` blocks until the event
  is delivered or dead-lettered (`source.Settled(err)` → acknowledge / commit), so retries,
  backoff, dead letters, concurrency, signing and metrics stay in the core.
