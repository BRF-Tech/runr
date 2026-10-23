# Bindings

Bindings give functions managed data services without handling credentials: a **KV** store on
Redis, **object storage** (S3), a **Postgres** database, or **external** credentials you bring.
A binding belongs to the organization; you attach it to one or more of its projects and RunR
injects the connection details as environment variables.

```text
project "orders-api" ──attach (prefix ORDERS_)──▶ binding "orders-db" (postgres, shared)
project "reports"    ──attach (no prefix)──────▶ binding "orders-db"
                                                  binding "cache"     (kv)
```

- [Binding types](#binding-types)
- [Environment variables](#environment-variables)
- [Examples](#examples)
- [Lifecycle](#lifecycle)
- [Global projects](#global-projects)
- [Dynamic credentials](#dynamic-credentials)
- [Region setup](#region-setup)
- [Quotas](#quotas)
- [Usage and billing](#usage-and-billing)
- [Permissions](#permissions)
- [How it works](#how-it-works)
- [API](#api)
- [Configuration (Helm)](#configuration-helm)
- [Audit](#audit)
- [Testing](#testing)

## Binding types

| Type | What you get | Runs on |
| --- | --- | --- |
| `kv` | a Redis user that may only use keys and channels starting with its prefix | the region's KV Redis |
| `object` | a bucket with a size quota and an access key allowed on that bucket only | the region's object store (Garage) or an external S3 endpoint |
| `postgres` (plan `shared`) | a database and a role that owns it, with a connection limit and a statement timeout | the region's shared CloudNativePG cluster |
| `postgres` (plan `dedicated`) | a Postgres cluster of its own (1–3 instances, its own volume, optional backups) | CloudNativePG in the region |
| `external` | the values you enter (URL, token, …), injected as they are | anywhere |

A binding of type `kv`, `object` or `postgres` lives in one region; it can be attached to
projects of that region. External bindings can be attached to any project. A
[global project](global-projects.md#data) runs in several regions: a managed binding of one of its
regions is reached from the others through the binding's data link, and a dedicated Postgres
binding gets a replica cluster in every region of the project.

### Settings

| Type | Setting | Default | Meaning |
| --- | --- | --- | --- |
| `kv` | `memory_mb` | 64 | memory the binding's keys may use; above it the user can only read and delete |
| `object` | `quota_gb` | 5 | bucket quota (enforced by the object store) |
| `postgres` | `storage_gb` | 1 (shared), 5 (dedicated) | shared: size limit, above it new sessions are read-only; dedicated: volume size per instance (can only grow) |
| `postgres` | `connection_limit` | 20 | connections of the role (and the database) |
| `postgres` | `statement_timeout` | `30s` | `statement_timeout` of the role (`ms`, `s` or `min`) |
| `postgres` (dedicated) | `instances` | 1 | 1 primary + replicas, up to 3 |
| `postgres` (dedicated) | `backups` | off | daily base backup + WAL archive to the region object store, kept 7 days |

Every role also gets `idle_in_transaction_session_timeout = 60s`.

## Environment variables

| Type | Variables |
| --- | --- |
| `kv` | `REDIS_URL`, `KV_PREFIX` |
| `object` | `S3_ENDPOINT`, `S3_BUCKET`, `S3_ACCESS_KEY_ID`, `S3_SECRET_ACCESS_KEY`, `S3_REGION`, `S3_FORCE_PATH_STYLE` |
| `postgres` | `DATABASE_URL`, `PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`, `PGSSLMODE` |
| `external` | the keys of the binding's values |

An attachment can carry an **env prefix** (upper case, ends with `_`): with `ORDERS_` the
project gets `ORDERS_DATABASE_URL`, `ORDERS_PGHOST`, … — that is how one project uses two
bindings of the same type. Two attachments of a project cannot produce the same variable name;
the Bindings tab previews the names while you type the prefix.

`DATABASE_URL` connects with `sslmode=require` (CloudNativePG serves TLS). `S3_FORCE_PATH_STYLE`
is `true` for Garage — configure your S3 client for path-style addressing.

A Postgres attachment with [dynamic credentials](#dynamic-credentials) gets `PGUSER`,
`PGPASSWORD` and `DATABASE_URL` from a user of its own that the region's OpenBao issues, not the
binding's.

A dedicated Postgres binding of a [global project](global-projects.md#postgres-across-regions)
adds `DATABASE_URL_RO` and `PGHOST_RO` in every region — the region's own replica, for reads —
while `DATABASE_URL` and `PGHOST` name the primary (in the other regions through the data link).
The other managed bindings of a global project name the data link's host
(`runr-link-<binding>.runr-data.svc.cluster.local`) in the regions other than their own.

Precedence: the project's own variables — plain ones and secret variables (Secret
`<project>-secret-env`, `secretKeyRef` entries) — are explicit `env` entries of the container and
win over binding variables with the same name, which come through `envFrom` (Secret
`<project>-bindings`). RunR-managed variables (`RUNR_*`) are never overridden by a binding.

## Examples

All examples use the variables without a prefix. With a prefix, read `ORDERS_DATABASE_URL`
instead of `DATABASE_URL` and so on.

### Python

```python
# requirements.txt: redis, psycopg[binary], boto3
import os
import boto3
import psycopg
import redis
from botocore.config import Config

kv = redis.Redis.from_url(os.environ["REDIS_URL"])
prefix = os.environ["KV_PREFIX"]

s3 = boto3.client(
    "s3",
    endpoint_url=os.environ["S3_ENDPOINT"],
    aws_access_key_id=os.environ["S3_ACCESS_KEY_ID"],
    aws_secret_access_key=os.environ["S3_SECRET_ACCESS_KEY"],
    region_name=os.environ["S3_REGION"],
    config=Config(s3={"addressing_style": "path"}),
)
bucket = os.environ["S3_BUCKET"]


def handler(event, context):
    hits = kv.incr(prefix + "hits")
    s3.put_object(Bucket=bucket, Key="last.txt", Body=str(hits).encode())
    with psycopg.connect(os.environ["DATABASE_URL"]) as db:
        db.execute("CREATE TABLE IF NOT EXISTS visits (at timestamptz DEFAULT now())")
        db.execute("INSERT INTO visits DEFAULT VALUES")
        total = db.execute("SELECT count(*) FROM visits").fetchone()[0]
    return {"statusCode": 200, "body": {"hits": hits, "visits": total}}
```

### Node.js

```javascript
// package.json: "ioredis", "pg", "@aws-sdk/client-s3"
const Redis = require('ioredis')
const { Pool } = require('pg')
const { S3Client, PutObjectCommand } = require('@aws-sdk/client-s3')

const kv = new Redis(process.env.REDIS_URL)
const prefix = process.env.KV_PREFIX
const db = new Pool({ connectionString: process.env.DATABASE_URL, max: 5 })
const s3 = new S3Client({
  endpoint: process.env.S3_ENDPOINT,
  region: process.env.S3_REGION,
  forcePathStyle: process.env.S3_FORCE_PATH_STYLE === 'true',
  credentials: {
    accessKeyId: process.env.S3_ACCESS_KEY_ID,
    secretAccessKey: process.env.S3_SECRET_ACCESS_KEY,
  },
})

exports.handler = async () => {
  const hits = await kv.incr(`${prefix}hits`)
  await s3.send(new PutObjectCommand({ Bucket: process.env.S3_BUCKET, Key: 'last.txt', Body: String(hits) }))
  const { rows } = await db.query('SELECT now() AS at')
  return { statusCode: 200, body: { hits, at: rows[0].at } }
}
```

Keep `max` of the pool below the binding's `connection_limit` divided by the number of
function instances you expect.

### Go

```go
// go.mod: github.com/redis/go-redis/v9, github.com/jackc/pgx/v5, github.com/aws/aws-sdk-go-v2/service/s3
package main

import (
	"context"
	"os"
	"strings"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/credentials"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/jackc/pgx/v5/pgxpool"
	"github.com/redis/go-redis/v9"
)

func Handler(event interface{}, ctx interface{}) (interface{}, error) {
	c := context.Background()

	opt, _ := redis.ParseURL(os.Getenv("REDIS_URL"))
	kv := redis.NewClient(opt)
	hits, err := kv.Incr(c, os.Getenv("KV_PREFIX")+"hits").Result()
	if err != nil {
		return nil, err
	}

	pool, err := pgxpool.New(c, os.Getenv("DATABASE_URL"))
	if err != nil {
		return nil, err
	}
	defer pool.Close()
	var n int
	_ = pool.QueryRow(c, "SELECT 1").Scan(&n)

	client := s3.New(s3.Options{
		BaseEndpoint: aws.String(os.Getenv("S3_ENDPOINT")),
		Region:       os.Getenv("S3_REGION"),
		UsePathStyle: os.Getenv("S3_FORCE_PATH_STYLE") == "true",
		Credentials:  credentials.NewStaticCredentialsProvider(os.Getenv("S3_ACCESS_KEY_ID"), os.Getenv("S3_SECRET_ACCESS_KEY"), ""),
	})
	_, err = client.PutObject(c, &s3.PutObjectInput{
		Bucket: aws.String(os.Getenv("S3_BUCKET")),
		Key:    aws.String("last.txt"),
		Body:   strings.NewReader("hello"),
	})

	return map[string]interface{}{"statusCode": 200, "body": map[string]interface{}{"hits": hits}}, err
}
```

### KV prefix rules

A KV user may run every command on keys and channels under its prefix except administrative
and dangerous commands (`CONFIG`, `FLUSHALL`, `FLUSHDB`, `KEYS`, `DEBUG`, …), `SELECT`,
`FUNCTION`, `SCRIPT FLUSH|KILL` and the commands that would list names of other bindings:
`SCAN`, `RANDOMKEY`, `PUBSUB CHANNELS|NUMSUB|NUMPAT|SHARDCHANNELS|SHARDNUMSUB`. Keep your own
index (a set under the prefix) when you need to enumerate keys. Lua scripts run with the same
key permissions. `INFO` stays allowed for client libraries' ready checks.

## Lifecycle

```text
create ──▶ provisioning ──▶ ready ⇄ rotating
                              │ delete (type the name)
                              ▼
                          deleting ──restore──▶ ready
                              │ retention ends
                              ▼
                           purged (data and resources gone; usage records stay)
```

### Create and attach

- **Project → Bindings → Create binding**: creates a binding in the project's region and
  attaches it in one step (optionally with an env prefix).
- **Project → Bindings → Attach existing**: attaches a binding the organization already has.
- **Data & Integrations → Data**: every binding of the organization, its projects, status and usage.

Provisioning runs in the background (seconds; a dedicated Postgres cluster takes a few
minutes). Attaching works right away: the variables arrive when the binding is ready.

When the attached set of a project changes, RunR updates the project's Secret
`<project>-bindings` and rolls its running Knative Service to a new revision (a new
`runr.dev/bindings-hash` annotation); scheduled functions read the Secret on their next run.
Nothing needs to be redeployed.

A tenant with a [data residency](supply-chain.md#data-residency) policy creates, restores and
attaches `kv`, `object` and `postgres` bindings only in its allowed regions and data locations;
anything else answers 422 (`region_not_allowed`) and is recorded as `residency.denied`.
External bindings have no region and are not restricted.

### Environments

A binding is attached to the project's own workload (production) or to one of its environments
([Environments](environments.md#bindings)): `POST /projects/{project}/bindings` with
`environment` (a name or id). An environment gets only the bindings attached to it — nothing is
inherited from production — in its own Secret `<workload>-bindings`, and the binding's
NetworkPolicy admits the pods of that environment (`runr/environment-id`) next to those of the
projects it is attached to (`runr/project-id`). A binding is attached to at most one environment of
a project and must be in the region the environment runs in. `GET /projects/{project}/bindings`
lists every attachment with its `environment`; `?environment=` one environment's, with what can
still be attached in its region. CLI: `runr bindings attach|detach|attachments --env staging`
([CLI](cli.md)); console: the environment's **Bindings** tab.

### Detach

Detaching removes the variables from the project (rolling restart) and the project from the
binding's NetworkPolicy. The binding and its data stay. A dynamic attachment's users are revoked
([Dynamic credentials](#dynamic-credentials)).

### Rotate credentials

**Rotate** issues new credentials and rolls every attached project onto them:

| Type | Rotation |
| --- | --- |
| `kv` | a second password is added to the user; the old one is removed after the grace period (`rotationGraceSeconds`, default 600 s) |
| `object` | a second key is created and allowed on the bucket; the old key is deleted after the grace period |
| `postgres` shared | `ALTER ROLE … PASSWORD`; sessions already open stay open |
| `postgres` dedicated | the owner Secret changes, CloudNativePG applies the new password to the role |
| `external` | change the values (Data & Integrations → Data → Edit); attached projects roll onto them |

On a region Redis without ACL support and on external S3 endpoints without an admin API the
credentials are the region's own; rotation then re-reads them.

### Delete, retention and restore

Deleting asks you to type the binding name. The binding is detached from every project at once,
its access is revoked (KV user disabled and its connections closed, bucket key denied, Postgres
role `NOLOGIN`, a dedicated cluster hibernated) and its data is kept for
`retentionDays` (default 7). Until then **Restore** brings it back (attach it again afterwards).
After the retention the hourly purge deletes the data and the resources: KV keys under the
prefix and the user, the bucket with its objects and keys, the database and role
(`DROP DATABASE … WITH (FORCE)`), or the dedicated cluster with its volumes.

### Status, health and usage

The binding list shows the lifecycle status. **Status** (`GET /api/bindings/{id}/status`)
checks the binding on its data service: the Redis user, the bucket (objects, bytes, quota),
the Postgres cluster (ready instances, phase). Usage is measured hourly — KV memory of the
binding's keys, bucket size, database size — and shown next to the binding's limit.

## Global projects

A [global project](global-projects.md#data) runs a copy in each of its regions; its bindings are
reached from all of them:

| Binding | Every region of the global project |
| --- | --- |
| `external`, `object` on an external S3 endpoint | as it is |
| `kv`, `object` (managed store), `postgres` `shared` | the binding stays in its region; the other regions connect to the binding's **data link** (a forwarder in their data namespace, mutual TLS to the binding region's Traefik) with the same credentials — unavailable while that region is down |
| `postgres` `dedicated` | a CloudNativePG **replica cluster** per region (reads: `DATABASE_URL_RO`), writes to the primary; a switchover or failover of the project promotes the replica of the new primary, which becomes the binding's region |

Rules: the binding must be in one of the project's regions, a dedicated one in its primary; a
dedicated binding replicated for a global project is attached to no other project; dynamic
credentials are refused for global projects (OpenBao's connection and users live in one region).
Detaching, deleting the binding, or a region leaving the project removes its replica clusters and
link objects there. Details, status and failover: [global-projects.md](global-projects.md#data).

## Dynamic credentials

A Postgres attachment can get **a Postgres user of its own** instead of the binding's user and
password: issued by the region's [OpenBao](secret-stores.md#openbao-in-the-region) for a limited
time, renewed in the region, and revoked when the attachment goes. The binding's own password never
reaches the project.

```text
binding "orders-db" (postgres) ── role b_orders_db_… (owns the database)
   ▲ IN ROLE                                  ▲ GRANT … WITH ADMIN OPTION
user v-…-runr-<binding>-<project>-… ◀── OpenBao database engine, connection runr-<binding id>
   (a week, renewed every day)              (as role runr_bao_…, password known to OpenBao only)
   ▼ ExternalSecret <slug>-pgdyn-<…> (VaultDynamicSecret generator, the project's identity)
ORDERS_PGUSER, ORDERS_PGPASSWORD, ORDERS_DATABASE_URL ──envFrom──▶ workload
ORDERS_PGHOST, ORDERS_PGPORT, ORDERS_PGDATABASE, ORDERS_PGSSLMODE  (Secret <slug>-bindings)
```

Requirements: a `postgres` binding (shared or dedicated) that is `ready`, and the project's region
running External Secrets Operator and OpenBao. Otherwise the attach answers `422` with the reason.

Attach with `"credentials": "dynamic"`, or switch an attachment either way with `PATCH` (the env
prefix stays unless it is sent):

```bash
curl -X POST https://runr.example.com/api/projects/$PROJECT/bindings \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"binding_id":"01J…","env_prefix":"ORDERS_","credentials":"dynamic"}'

curl -X PATCH https://runr.example.com/api/projects/$PROJECT/bindings/$ATTACHMENT \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"credentials":"static"}'
```

The attachment shows `credential_mode` (`static` or `dynamic`); the CLI takes
`runr bindings attach BINDING --credentials dynamic`; on a binding that is already attached the
same command switches it (`--credentials static` back) ([CLI](cli.md#bindings-data-services-for-projects)).

What RunR sets up:

- **Per binding, once** — a Postgres role `runr_bao_<…>` with `LOGIN CREATEROLE` that may grant the
  binding's role (`GRANT <binding role> TO runr_bao_<…> WITH ADMIN OPTION`), created by a short-lived
  operations pod in the region with a random password, which RunR hands to OpenBao once as the
  connection `runr-<binding id>` (plugin `postgresql-database-plugin`, `sslmode=require`) and
  OpenBao replaces right away (`rotate-root`): afterwards only OpenBao knows it.
- **Per attachment** — an OpenBao role `runr-<binding id>-<project id>`: each user it issues is
  created `IN ROLE <binding role>` with `VALID UNTIL` its expiry, and its sessions switch to the
  binding's role (`ALTER ROLE … SET role`), so the objects they create belong to the binding's role
  like the static user's. Revoking a user reassigns what it still owns to the binding's role, drops
  what is left and drops the user.
- **The project's policy** in OpenBao (`runr-project-<project id>`) may read the credentials of its
  dynamic attachments — no other project's.
- **In the region**, for each dynamic attachment: a `VaultDynamicSecret` generator
  (`runr-pgdyn-<attachment id>`) that logs in as the project's service account
  (`runr-bao-<project id>`), and an ExternalSecret `<slug>-pgdyn-<…>` that fills the Secret of that
  name with `<prefix>PGUSER`, `<prefix>PGPASSWORD` and `<prefix>DATABASE_URL`. The workload reads it
  through `envFrom` after `<slug>-bindings`, which keeps `<prefix>PGHOST`, `<prefix>PGPORT`,
  `<prefix>PGDATABASE` and `<prefix>PGSSLMODE` but no user, password or URL of a dynamic attachment.

| Setting (env of the API, [Helm `extraEnv`](helm-values.md)) | Default | |
|---|---|---|
| `RUNR_OPENBAO_DB_TTL` | `168h` | how long an issued user lives |
| `RUNR_OPENBAO_DB_MAX_TTL` | `720h` | the longest a user can live |
| `RUNR_OPENBAO_DB_REFRESH` | `24h` | how often the region asks for a new user |

**Renewals.** Every `RUNR_OPENBAO_DB_REFRESH` External Secrets Operator gets a new user and rewrites
the Secret; a container reads it when it starts. `secrets:sync-status` (every two minutes) sees the
renewal and rolls the project's Knative Service to a new revision (template annotation
`runr.dev/pgdyn-version`), so the pods move to the new user; scheduled functions read the Secret on
their next run. The previous user lives on until its own TTL ends, long enough for the old pods to
drain.

**Detach and switching back.** Detaching a dynamic attachment, or switching it to `static`, revokes
every user OpenBao issued for it (they are dropped in Postgres), deletes its OpenBao role and takes
it out of the project's policy; the next bindings sync removes its generator and ExternalSecret.
Deleting the binding removes its OpenBao connection too (purge).

The binding's own credentials stay as they are: [rotating](#rotate-credentials) them does not touch
the dynamic users.

## Region setup

Region data services are installed per region in **Administration → Regions & settings → Knative Regions → (region) → Data
services** by users with `regions.data_services.manage`. They run in the namespace
`runr-data` (`bindings.namespace`) behind NetworkPolicies. A region runs no data service until
one is installed there: creating a `kv`, `object` or `postgres` binding is refused until the
matching service is ready in its region (Postgres backups also need the object store);
`external` bindings need none.

The managed KV Redis, Garage and the CloudNativePG clusters keep their data on volumes. They
tolerate the dedicated-worker taint `runr.dev/dedicated=projects:NoSchedule` and prefer the nodes
that are not dedicated workers: a node-bound volume keeps a pod on the node it was provisioned
on, and that node may be selected as a dedicated worker later
([architecture.md](architecture.md#dedicated-workers)).

### KV

| Mode | What runs | Notes |
| --- | --- | --- |
| `managed` (default) | `runr-kv`: Redis 7, admin password, ACL file and AOF on a volume, `maxmemory` with `noeviction` | binding users survive restarts (`ACL SAVE`); a full instance refuses writes rather than evicting another binding's keys |
| `region` | the region's own Redis (Region → Redis) | needs a Redis password; with ACL support one user per binding (re-applied every 10 minutes, the region Redis keeps users in memory only), without ACL support a logical database (1–15) per binding with the region password |

The managed KV Redis is separate from the queue-trigger Redis on purpose: queue consumers and
the region demo may use that one without a password, which would give every pod full access to
binding data.

### Object storage

| Mode | Provider | Bucket-scoped keys |
| --- | --- | --- |
| `managed` | Garage in the data namespace (`runr-objects`) | yes |
| `external` | `garage`: an external Garage — S3 endpoint, admin endpoint and admin token | yes |
| `external` | `s3`: any S3 endpoint (AWS S3, Ceph RGW, MinIO, Wasabi, …) — endpoint, region, access key, secret, path style, bucket prefix | no: functions use the configured key (shown as *shared key*) |

Why Garage for the managed store:

| | Garage | SeaweedFS | MinIO |
| --- | --- | --- | --- |
| Footprint | one Rust binary, tens of MB of RAM | master, volume, filer and S3 gateway | one binary, more memory |
| Single node | replication factor 1, one StatefulSet | possible, several components | yes |
| Per-bucket keys + quotas | native admin API | identity configuration through the shell | admin API |
| License | AGPL-3.0, used unmodified as a separate service | Apache-2.0 | AGPL-3.0; community releases ship as source only |

RunR only talks to Garage's S3 and admin APIs; RunR itself stays MIT. Garage runs on one node
with LMDB metadata; its volume (`storage`, default 20Gi) is the capacity of the layout RunR
applies on the first start.

### Postgres

Installing Postgres in a region installs the **CloudNativePG** operator (Helm chart
`cnpg/cloudnative-pg`, version `bindings.postgres.cnpgChartVersion`, namespace `cnpg-system`)
and the shared cluster `runr-pg-shared` (instances and storage configurable; `max_connections
200`). PUBLIC may not connect to the cluster's default databases; each binding's database
allows its own role only.

Backups (shared cluster: region setting; dedicated: per binding) go to the bucket
`runr-pg-backups` of the region object store: a daily base backup and the WAL archive
(barman-cloud), retention `backupRetention` (default `7d`). Install the object store first.

Dedicated clusters `pg-<binding>` are created per binding: instances, storage per instance,
connection limit (the operator manages the owner role), statement timeout.

Uninstalling a data service is refused while bindings of any organization use it.

## Quotas

Limits per organization (per tenant in the multi-tenant edition), counted from what bindings may
grow to — their settings, not today's usage:

| Limit | Counts | Default |
| --- | --- | --- |
| `max_bindings` | bindings of every type (deleted ones until they are purged) | 20 |
| `kv_mb` | Σ `memory_mb` of KV bindings | 1024 |
| `object_gb` | Σ `quota_gb` of object bindings | 100 |
| `postgres_gb` | Σ `storage_gb` of Postgres bindings (dedicated: × instances, × regions of a [global project](global-projects.md#postgres-across-regions) it replicates to) | 50 |
| `dedicated_postgres` | dedicated Postgres clusters | 2 |

`0` means unlimited. Defaults come from the Helm values (`bindings.quotas`); in the multi-tenant
edition platform administrators set per-tenant limits in **Administration → Tenants → (tenant) →
Regions → Binding limits** (`GET/PUT /api/tenants/{tenant}/binding-quotas`). `GET /api/bindings/quota`
shows the organization's limits, claimed amounts and measured bytes.

Size limits are enforced as policy where the data service allows it: Garage bucket quotas, the
KV user switched to read + delete above `memory_mb`, new Postgres sessions read-only above
`storage_gb` (shared), the volume size (dedicated).

## Usage and billing

The hourly measurement books daily usage records per binding:

- **storage GB-hours** — measured size in GiB × hours since the previous measurement (at most
  2 hours, gaps are not billed backwards);
- **instance hours** — running instances × hours, for dedicated Postgres clusters.

A dedicated Postgres binding of a global project adds its replica clusters to its record: their
running instances to the instance hours, their copies of the data to the storage GB-hours.

Each record also names the binding's creator. Metering records quantities only; with the
[Billing module](billing.md) on, the region's prices turn them into money:

| Price | Applies to |
| --- | --- |
| `kv_gb_hour` | KV storage GB-hours |
| `object_gb_hour` | object storage GB-hours |
| `postgres_gb_hour` | Postgres storage GB-hours |
| `postgres_instance_hour` | dedicated Postgres instance hours |

A binding belongs to no single project, so its usage is billed on the **account bill** of the user
who created it, one line per binding and price ("Binding: orders-db (Postgres, dedicated)"), by
usage also in a fixed region ([Account bills](billing.md#account-bills)).
`GET /api/bindings/{id}/usage` answers the daily quantities (`record_date`, `storage_gb_hours`,
`instance_hours`, `peak_bytes`); what they cost is on those bills.

## Permissions

| Permission | Allows |
| --- | --- |
| `bindings.view` | see bindings, their attachments, health and usage |
| `bindings.manage` | create, change, attach, detach, rotate, delete and restore bindings |
| `bindings.view_credentials` | reveal a binding's connection values (audited) |
| `regions.data_services.manage` | install, configure and remove a region's data services |

Attaching, re-prefixing and detaching on a project also needs `env.edit` on that project:
an attached binding's credentials are readable by the project's code. Built-in roles: Owner
and Admin hold all four; Developer holds `bindings.view` and `bindings.manage`; Viewer and
Billing hold `bindings.view`. See [Permissions](permissions.md).

Credentials are stored encrypted and are never part of API responses; the Secret in the region
and `GET /api/bindings/{id}/credentials` are the only places they appear.

## How it works

```text
 project namespace (functions)                  data namespace runr-data
 ┌──────────────────────────────┐               ┌───────────────────────────────────────┐
 │ Knative Service orders-api   │               │ NetworkPolicy default deny            │
 │   envFrom: Secret            │  allowed by   │ NetworkPolicy runr-binding-orders-db… │
 │   orders-api-bindings ───────┼──────────────▶│   from: runr/project-id in […]        │
 │ Secret orders-api-bindings   │  the policy   │ runr-kv (Redis), runr-objects (Garage)│
 │   ORDERS_DATABASE_URL=…      │               │ runr-pg-shared / pg-<binding> (CNPG)  │
 └──────────────────────────────┘               └───────────────────────────────────────┘
```

- **Secret per project** — `<project>-bindings` in the region's project namespace holds every
  variable of the project's attachments. The function container references it with
  `envFrom.secretRef` (optional, so a pod starts while the Secret is written); the hook is the
  one line in the container spec builder (`BindingEnv::apply`).
- **Rolling restart** — attach, detach, rotation: the Secret is rewritten and the Knative
  Service's template annotation `runr.dev/bindings-hash` changes, which is a new revision.
- **NetworkPolicies** — the data namespace denies all ingress except from its own pods
  (operations, replication), the CloudNativePG operator and the region's OpenBao to the Postgres
  port (it creates the users of [dynamic credentials](#dynamic-credentials)). Each binding adds a policy that lets
  pods of its attached projects reach its service port (6379, 3900, 5432). Isolation between
  bindings on a shared service comes from their credentials (ACL users, bucket keys, roles). A
  CNI without NetworkPolicy support (for example plain Flannel) does not enforce the policies.
- **Operations inside the region** — user, bucket, role and size operations run as short-lived
  pods in the data namespace (`redis-cli`, `psql`, `rclone`, the Garage admin API). Their
  credentials are mounted from a short-lived Secret, never placed in the pod spec or on a
  command line; pod and Secret are deleted afterwards.
- **Background work** — provisioning, rotation, suspend/resume and settings changes are queued
  jobs. Scheduled: usage measurement (hourly), purge of expired deletions (hourly), KV ACL
  reconcile on region Redis (every 10 minutes).

## API

| Method | Path | Permission |
| --- | --- | --- |
| GET | `/api/bindings` (`?type=`, `?knative_region_id=`) | `bindings.view` |
| GET | `/api/bindings/types` | `bindings.view` |
| GET | `/api/bindings/quota` | `bindings.view` |
| POST | `/api/bindings` | `bindings.manage` |
| GET | `/api/bindings/{id}` | `bindings.view` |
| PATCH | `/api/bindings/{id}` (`settings`, external: `values`) | `bindings.manage` |
| DELETE | `/api/bindings/{id}` (body `confirm: <name>`) | `bindings.manage` |
| POST | `/api/bindings/{id}/restore` | `bindings.manage` |
| POST | `/api/bindings/{id}/rotate` | `bindings.manage` |
| GET | `/api/bindings/{id}/status` | `bindings.view` |
| GET | `/api/bindings/{id}/credentials` | `bindings.view_credentials` |
| GET | `/api/bindings/{id}/usage` (`?days=30`; quantities, [Usage and billing](#usage-and-billing)) | `bindings.view` |
| GET | `/api/projects/{project}/bindings` | `projects.view` |
| POST | `/api/projects/{project}/bindings` (`binding_id` or `binding`, `env_prefix`, `credentials`: `static` \| `dynamic`) | `env.edit` + `bindings.manage` |
| PATCH | `/api/projects/{project}/bindings/{attachment}` (`env_prefix`, `credentials`) | `env.edit` + `bindings.manage` |
| DELETE | `/api/projects/{project}/bindings/{attachment}` | `env.edit` + `bindings.manage` |
| GET | `/api/knative-regions/{region}/data-services` (`?live=1`) | `regions.view` or `bindings.view` |
| POST | `/api/knative-regions/{region}/data-services/{kv\|object\|postgres}` | `regions.data_services.manage` |
| DELETE | `/api/knative-regions/{region}/data-services/{kv\|object\|postgres}` | `regions.data_services.manage` |
| GET / PUT | `/api/tenants/{tenant}/binding-quotas` | platform administrator |

The CLI covers the same calls (`runr bindings`, see [CLI](cli.md#bindings-data-services-for-projects));
AI agents use the `bindings_list`, `binding_create` and `binding_attach` tools of the RunR MCP
server ([AI agents](mcp.md#tools)).

Create a binding:

```bash
curl -X POST https://runr.example.com/api/bindings \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"orders-db","type":"postgres","plan":"shared","knative_region_id":"01J…",
       "settings":{"storage_gb":2,"connection_limit":10,"statement_timeout":"15s"}}'
```

Create and attach in one call (the binding lives in the project's region):

```bash
curl -X POST https://runr.example.com/api/projects/$PROJECT/bindings \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"env_prefix":"CACHE_","binding":{"name":"cache","type":"kv","settings":{"memory_mb":128}}}'
```

External binding:

```bash
curl -X POST https://runr.example.com/api/bindings \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"name":"payments","type":"external","values":{"PAYMENTS_URL":"https://pay.example.com","PAYMENTS_TOKEN":"…"}}'
```

Install the region object store with an external S3 endpoint:

```bash
curl -X POST https://runr.example.com/api/knative-regions/$REGION/data-services/object \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"mode":"external","provider":"s3","endpoint":"https://s3.eu-central-1.amazonaws.com",
       "region":"eu-central-1","vendor":"AWS","path_style":false,"bucket_prefix":"acme-",
       "access_key_id":"AKIA…","secret_access_key":"…"}'
```

Credentials sent here are stored encrypted with the region service and never put into the
queued installation job.

## Configuration (Helm)

```yaml
bindings:
  namespace: runr-data
  retentionDays: 7
  rotationGraceSeconds: 600
  storageClass: ""
  quotas:
    maxBindings: 20
    kvMb: 1024
    objectGb: 100
    postgresGb: 50
    dedicatedPostgres: 2
  kv:
    maxmemoryMb: 512
    storage: 2Gi
  object:
    image: ghcr.io/brf-tech/runr/reqs/garage:v2.1.0
    storage: 20Gi
    s3Region: garage
  postgres:
    cnpgChartVersion: "0.26.0"
    image: ghcr.io/cloudnative-pg/postgresql:17.5
    sharedInstances: 1
    sharedStorage: 10Gi
    backupRetention: 7d
```

The values become `BINDINGS_*` environment variables of the API (`api/config/bindings.php`).
The settings without a Helm value of their own are set via `extraEnv`: `BINDINGS_KV_IMAGE`
(the managed KV Redis image), `BINDINGS_OBJECT_META_STORAGE` (Garage metadata volume, `1Gi`),
`BINDINGS_CNPG_CHART_REPO` and `BINDINGS_CNPG_NAMESPACE` (`cnpg-system`),
`BINDINGS_POSTGRES_BACKUP_SCHEDULE` (`0 0 3 * * *`), `BINDINGS_OPS_TIMEOUT` (180 s per
operation pod), `BINDINGS_OPS_TOOLBOX_IMAGE`, `BINDINGS_OBJECT_CLIENT_IMAGE` and
`BINDINGS_POLL_INTERVAL` (3 s).
Images of the data services and their operations (`garage`, `rclone`, `python:3.12-slim`,
`redis:7-alpine`) are mirrored under `ghcr.io/brf-tech/runr/reqs/` (the mirror workflow of BRF-Tech/runr-runtimes);
regions with a registry mirror pull Docker Hub names through it.

## Audit

The audit log ([Security](security.md)) records every binding request — with its result
(success, denied, failure), actor, tenant and target, never with credential values:

| Action | Request |
| --- | --- |
| `binding.create` | `POST /api/bindings` |
| `binding.update` | `PATCH /api/bindings/{id}` (limits; external: values changed, not the values) |
| `binding.delete` | `DELETE /api/bindings/{id}` (purge date in the metadata) |
| `binding.restore` | `POST /api/bindings/{id}/restore` |
| `binding.credentials.rotate` | `POST /api/bindings/{id}/rotate` |
| `binding.credentials.reveal` | `GET /api/bindings/{id}/credentials` (the response is `Cache-Control: no-store`) |
| `binding.attach` | `POST /api/projects/{project}/bindings` (binding, env prefix, created in the same call) |
| `binding.attachment.update` | `PATCH /api/projects/{project}/bindings/{attachment}` (a switch of `credential_mode` among the changed fields) |
| `binding.detach` | `DELETE /api/projects/{project}/bindings/{attachment}` |
| `region.data_service.install` | `POST /api/knative-regions/{region}/data-services/{service}` |
| `region.data_service.uninstall` | `DELETE /api/knative-regions/{region}/data-services/{service}` |
| `tenant.binding_quota.update` | `PUT /api/tenants/{tenant}/binding-quotas` |
| `binding.purged` | the hourly purge after the retention (actor `system`) |

Credentials (`bindings.credentials`, `bindings.previous_credentials`,
`binding_region_services.secrets`) are secret columns encrypted at rest (`App\Casts\Encrypted`)
and part of the key rotation of `php artisan secrets:encrypt`. Listeners can also subscribe to
the domain event `App\Events\BindingAudited` (created, attached, detached, rotated, deleted,
restored, purged, data service installed / removed).

## Testing

- API: `php artisan test --filter=Binding` (`api/tests/Feature/Bindings`, `api/tests/Unit/Bindings`)
  and the data service tests; operations in the region are faked (`Tests\Concerns\FakesBindingOps`).
- UI: `npm test` in `vue/` (Bindings tab, Data page, region Data services) and
  `npx playwright test e2e/bindings.spec.ts --project=mock` (mocked API).
- Kind: `runtime/ci.sh test-bindings` installs the data services on a Kind cluster, creates one
  binding of each type, deploys a function that writes and reads each one, rotates, detaches,
  measures and purges.
