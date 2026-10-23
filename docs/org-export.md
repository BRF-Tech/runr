# Organization export and import

Pack an organization into one file and create it again from that file — in another
organization of the same installation, on another RunR installation (self-hosted to hosted, one
site to another) or on the same one after a disaster. The package is a versioned, documented
format: what you export is yours, readable without RunR, and importable by any later RunR.

A package holds the organization's projects with **every version and its code**, their configs,
custom domains, bindings and workflows, the organization's roles, groups, alert rules and status
page — and, when you ask for them, the members (for re-invitation), the prebuilt images, the data
of bindings, the run history (deployments, workflow runs, trigger events, usage, bills) and the
secrets, encrypted with a passphrase only you know.

- [How it works](#how-it-works)
- [What a package holds](#what-a-package-holds)
- [Export](#export)
- [Destinations](#destinations)
- [Import](#import)
- [The plan](#the-plan)
- [Secrets](#secrets)
- [Binding data](#binding-data)
- [Images](#images)
- [Run history](#run-history)
- [Members](#members)
- [Deploy](#deploy)
- [Package format](#package-format)
- [Compatibility](#compatibility)
- [Sections](#sections)
- [Permissions](#permissions)
- [Audit](#audit)
- [API](#api)
- [MCP tools](#mcp-tools)
- [CLI](#cli)
- [Configuration](#configuration)
- [Data model](#data-model)
- [Limits and what is not exported](#limits-and-what-is-not-exported)
- [Testing](#testing)

## How it works

```text
Export (source organization)                         Import (target organization)
  POST /api/org-transfers/export                       POST /api/org-transfers/import {filename, size, sha256}
   └─ ExportOrganizationJob (Horizon)                   PUT  …/chunk?offset=N  (raw bytes, 8 MiB, resumable)
       ├─ sections → staging directory                  POST …/complete
       │                                                — or {source: binding | s3}: FetchImportPackageJob
       │                                                  reads it in ranges (resumable, POST …/fetch)
       │    projects: git archive of every version       └─ VerifyImportPackageJob
       │    binding data: transfer pod in the region         unpack, check every entry (size, SHA-256),
       │    images: transfer pod pulls the image             refuse newer schemas → the default plan
       ├─ secrets sealed with the passphrase             POST …/plan   {region_map, renames, passphrase}
       ├─ manifest.json (schema, checksums) + tar + zstd  POST …/apply  {restore_data, images, deploy}
       └─ download / object binding / external S3        └─ ApplyImportJob: sections in order, each
                                                             entry once → report (manual steps)
```

Progress is pushed to the console as `org_transfer.progress` events on the organization's
platform channel ([WebSocket events](websocket-events.md)); the CLI and MCP tools poll
`GET /api/org-transfers/{id}`. A finished job also notifies the member who started it.

Both jobs run on the API's queue. Their files — the package, the export's staging directory, the
import's unpacked copy, dumps on their way to or from a region — live on the storage volume the API,
Horizon and the scheduler share (`storage/app/private/org-transfers/<id>/`), and go away with the
transfer's retention (7 days) or when the transfer is cancelled.

## What a package holds

| Part | In the package | On import |
| --- | --- | --- |
| Organization settings | the default role of new members | set when the role exists |
| Roles and groups | custom roles (permissions), groups with their organization-wide role | created unless one with the same key / name exists |
| Members (optional) | e-mail, name, role, groups — never passwords, 2FA or SSO identities | invited (multi-tenant) or listed as a manual step |
| Projects | name, type, runtime and version pin, region, description, **every column of the service config** (size, scaling, trigger, env vars, …), endpoint type | created in the mapped region under the name or your rename |
| Versions | every version: number, message, changed files, config snapshot, build state, **the code at that version** | committed again with the same numbers |
| Deployment history | the last 50 deployments (version, status, who, when) — a summary | shown in the plan; the version that ran is the one deployed |
| Run history (optional) | every deployment, the workflow runs RunR keeps (with their steps), stored trigger events, usage records and bills — [Run history](#run-history) | history that never acts: final states, never metered, delivered or billed again |
| Secrets | names in clear; values (optional) sealed with your passphrase | written when the passphrase opens them, else a manual step |
| Custom domains | domain, TLS mode | added again, **pending a new DNS verification** |
| Gateways | rate limit, routes (path, methods, strip prefix, authentication, gateway-only) to exported projects | routes on the importing member's gateway, created with their authentication |
| Workflows | definitions (name, triggers, retries, timeouts, concurrency, …), paused state, endpoint override | declared again; run history stays behind |
| Bindings | name, type, plan, settings, region; external values only sealed | provisioned anew (new credentials) and attached with the same prefixes |
| Binding data (optional) | Postgres `pg_dump`, KV keys (`DUMP`), object storage objects | restored once the new binding is ready |
| Images (optional) | OCI archives of the deployed and the latest built version | pushed into the target region's registry |
| Observability | per-project tracing / replay settings, uptime checks (header values sealed) | created; checks without their headers stay off |
| Alerts | channels (e-mail addresses in clear, webhooks / tokens sealed), rules | channels without secrets stay off; rules point at the imported projects |
| Status page | title, slug, description, history settings, custom domain | the organization's page; another slug if the slug is taken on the installation |

Project access (users by e-mail, groups by name) travels with each project and is granted to the
members and groups that exist in the target.

## Export

In the console: **Administration → Export / Import → Export**. Pick the projects (all by default), whether
to add the members, the images, the data of which bindings, and the secrets. Before the export
starts the console shows the **estimate** — projects, versions, the size of their code, every
binding with its measured usage, the number of images (their size is known only once pulled):

```sh
runr org export --estimate --binding-data all --images
runr org export -f acme.tar.zst                                  # projects, configs, code; no data, no secrets
runr org export --binding-data db --secrets --passphrase-file pw.txt --members
runr org export --history                                        # with the run history
runr org export --to binding:backups --prefix dr/nightly --no-wait
```

The export runs in the background; the progress bar follows its phases (organization, bindings,
projects, binding data, images, run history, secrets, packing, destination). A data dump that fails (a binding
that is not ready, a region that does not answer) is skipped and listed in the report — the rest of
the export goes on. Cancelling stops the job between two steps and removes what it wrote.

## Destinations

| Destination | Where the package goes |
| --- | --- |
| `download` (default) | kept on the API's volume and downloaded from `GET /api/org-transfers/{id}/download` (`Range: bytes=N-` resumes; `X-Content-SHA256` header) until it expires |
| `binding` | an object binding of the organization: a transfer pod in the binding's region copies it into the bucket (`<prefix>/<file name>`) with the binding's own key; nothing stays on the API |
| `s3` | any S3-compatible bucket: endpoint, bucket, region, path style, access key and secret key — given for this export only, kept in the job's encrypted queue payload and nowhere else (Signature Version 4, multipart in 64 MiB parts) |

## Import

In the console: **Administration → Export / Import → Import**: pick the `.tar.zst` file; it uploads in
chunks (a reload resumes the open upload of the same file), then RunR verifies it and shows the
plan. Or choose **From an object binding** / **From an S3 bucket**: RunR reads the package where an
export wrote it, without it passing through your machine. With the CLI:

```sh
runr org import acme.tar.zst --plan-only                      # upload, verify, print the plan
runr org import acme.tar.zst --region-map eu-1=eu-west --rename api=api-acme \
    --passphrase-file pw.txt --deploy
runr org import --from binding:backups --key dr/nightly/runr-org-acme-20261021-120000.tar.zst
runr org import --from s3://backups/dr/acme.tar.zst --s3-endpoint https://s3.example.com --plan-only
runr org import --resume 01J…                                 # a read that failed continues
```

### Sources

The same places an export writes to (`POST /api/org-transfers/import` with `source`, status
`fetching`, phase `fetch`, the bytes read in `upload.uploaded_bytes` of `upload.size_bytes`):

| Source | How RunR reads it |
| --- | --- |
| upload (default) | the chunks above |
| `binding` (`binding_id`, `key`) | an object binding of the organization: a **transfer pod** in the binding's region copies the object out of the bucket with the binding's own key (`rclone`) and serves it with its size and SHA-256; RunR downloads it in ranges (`transfer_chunk_bytes`) through the Kubernetes API server's service proxy — the region needs no route to RunR. The pod's emptyDir holds the whole package: raise `RUNR_ORG_TRANSFER_POD_DISK_GB` for packages over 20 GiB |
| `s3` (`endpoint`, `bucket`, `region`, `path_style`, `key`, `access_key_id`, `secret_access_key`) | any S3-compatible bucket: HeadObject for size and ETag, then GetObject in ranges of `fetch_chunk_bytes` (16 MiB) with `If-Match` on the ETag. The credentials are used for this import only: they travel in the fetch job's encrypted queue payload and nowhere else — not in the transfer row, the audit log or a response |

- **Resumable.** The bytes read stay in the import's package file. A failing range is tried again
  a few times; a failure the network or the region caused is retried by the job (3 attempts,
  each continuing after the bytes it has); one that will not pass by itself — no such object,
  access denied, a package larger than `max_package_gb`, a binding that is not an object binding
  — fails the import with the reason. `POST /org-transfers/{id}/fetch` (CLI `--resume ID`, the
  console's **Resume reading**, the MCP tool `org_import_start` with `resume`) continues it; an S3
  source needs its credentials again. A read that stops without a word (a worker restart) is
  marked failed by the hourly expiry after `fetch_stall_minutes` (30) and resumes the same way.
- **The same object.** The bytes held are kept only while the source has the same object: the
  same size and ETag (S3; an object replaced while it is read answers `412` and the read starts
  over) or the same size and SHA-256 (binding — the pod computes it on every attempt).
- **Checked.** Once every byte is there the package is compared with the SHA-256 the region
  computed (binding: a mismatch means it was damaged on the way, it is read again) and the one you
  declared (`sha256`; a mismatch fails the import); then the upload's path takes over —
  verification, the plan. A cancelled import stops its read between two ranges and removes the
  bytes.

Applying creates the sections in order — organization, bindings, projects (versions, secrets,
domains, workflows, attachments, observability, access), triggers and topics, network, environments,
cost limits (Billing module), gateways, alerts and status page, binding data, images, deploy — and records every entry it created (`org_transfer_items`). **Applying again
after a failure resumes**: what was created is not created twice; what failed is tried again. The
report lists every entry (created, existed, skipped, failed, manual) and the manual steps.

## The plan

The dry-run report says, before anything is created:

- **region mapping** per source region: the region with the same id (an import into the same
  installation), else the one with the same name, else the organization's default region, else the
  only one there is — or your choice (`region_map`);
- **slug collisions**: a project whose slug is taken (the same organization, or a project the import
  plans) blocks with a suggested free name (`renames`); the same for binding names
  (`binding_renames`);
- **admission**: region capacity for the project's size, the organization's quota in the region
  (multi-tenant, summed over every project planned into it), data residency, an inactive region;
- **runtimes**: a runtime or runtime version this installation does not offer;
- **bindings**: the data service is not ready in the target region, binding quotas;
- **manual steps**: DNS of custom domains, secrets not in the package (or not unlocked), alert
  channels without their webhook, git provider repositories to connect again, members to invite,
  the gateway address that changes;
- **extensions**: sections of the package this installation does not know (a newer feature) — not
  imported, reported.

Errors block the apply (`422 plan_blocked`); warnings and manual steps do not.

## Secrets

Secret values are **not exported by default**. With **include secrets** the export asks for a
passphrase (at least 12 characters) and seals every secret it collects into `secrets/secrets.enc`:
project secret variables, the values of external bindings, the configuration of alert channels
(webhook URLs with tokens, bot tokens, signing secrets) and the headers of uptime checks. The
envelope:

```json
{"format": "runr-secrets", "alg": "argon2id13+xchacha20poly1305-ietf",
 "opslimit": 3, "memlimit": 67108864, "salt": "…", "nonce": "…", "ciphertext": "…"}
```

The key is derived from the passphrase with Argon2id (salt and cost in the envelope, which is
authenticated), the JSON is sealed with XChaCha20-Poly1305 (libsodium). Nothing secret is ever
written in clear, the passphrase is never stored — not in the transfer row, not in a log; it travels
in the job's encrypted queue payload only. An import without the passphrase (or with a wrong one)
still applies: the secrets are a manual step, alert channels and uptime checks that need them stay
off until they are set.

Where secret values live is behind one interface (`App\Services\OrgTransfer\Secrets\SecretValues`):
the export reads and the import writes through it, so values kept in the region's
`<slug>-secret-env` or in an external secret store need a binding of that interface, not another
package format. References to external secret stores travel in clear (they hold no value); an
import into an organization without the store lists them as a manual step.

## Binding data

The data of each binding is optional; the estimate shows its measured usage first.

| Type | Dump (in the binding's region) | Restore (in the new binding's region) |
| --- | --- | --- |
| Postgres | `pg_dump --format=custom --no-owner --no-privileges` of the binding's database | `pg_restore --no-owner --no-privileges --single-transaction` as the new binding's owner |
| KV | `kv_dump_v1`: per key the hex key without the binding's prefix, its PTTL and the hex `DUMP` payload, read in `SCAN` batches | `RESTORE … REPLACE` under the new binding's prefix (or into its own database) |
| Object storage | a tar of the bucket's objects (`rclone copy`) | the objects copied into the new bucket |

A dump runs in a **transfer pod** in the bindings' data namespace: the transfer agent
(`runtime/image-upload transfer`) and a tool container (the CloudNativePG Postgres image,
`redis-cli`, `rclone`) share an emptyDir; the tool writes the dump, the agent serves it, the API
downloads it in ranges through the Kubernetes API server's service proxy — the region needs no
route to RunR, as for [uploaded images](images.md). Credentials reach the tool as files of a
short-lived Secret, never in the pod spec or on a command line; a random token guards every request
to the agent; the pod runs non-root without a service account token; Job, Service, Secret and its
NetworkPolicy are removed afterwards. A restore is the same in reverse: the agent receives the file
in chunks, checks size and SHA-256, the tool restores it.

The import restores data once the new binding is provisioned (it waits up to 15 minutes); a restore
that fails is reported and retried by applying again. `skip_binding_data` (CLI `--skip-data
BINDING`, the plan's **Leave the data out**) leaves the data of single bindings out; `restore_data:
false` all of it.

**KV data and Redis versions.** KV dumps are Redis `DUMP` payloads, and Redis restores a payload of
its own or an older version only (`RESTORE` refuses a newer RDB format; converting the encoding
back is not possible). Each KV dump records the server's `redis_version` (`redis_version` in
`bindings/data/index.json`); the region's KV service records its version when it is installed and
the plan reads it again when the recorded one is older than the dump's. The plan then shows both
(`data_redis_version`, `target_redis_version` on the binding row) and **blocks** the import:

- `kv_version_older` — the target region's Redis is older than the dump's: *"The data of KV binding
  cache was dumped from Redis 7.4.1; the KV Redis of region eu-west is 7.0.15. … upgrade the
  region's Redis to 7.4.1 or newer, or leave this binding's data out (skip_binding_data)."*
- `kv_version_unknown` — the region's KV Redis does not answer with its version.

A package without a recorded version (written before this check) gets the warning
`kv_version_unrecorded`. The restore checks the version again before it sends anything (the
region's Redis may have changed since the plan).

## Images

With **include images** the export pulls, for every code project, the version it runs and its latest
ready build from the region's registry as OCI image-layout tars (every platform of an index), through
a transfer pod (`RUNR_TRANSFER_PULL`). The import pushes each into the target region's registry under
the name its builds use (`runr-builds/<slug>:v<N>`) and points the version at it — deployable without
a build. Without an image, a version keeps its old image reference and its first deploy builds it
again for the region's registry (the same mechanism as a [region move](regions.md)).

## Run history

With **include run history** (`include_history`, CLI `--history`) the package carries what ran:

```text
extensions/history/history.json        counts per project, the source's retention, when it was exported
extensions/history/<project id>.jsonl  one record per line: deployments, workflow runs (with steps), trigger events
extensions/history/usage.jsonl         usage records, binding usage records, workflow usage
extensions/billing_history/bills.jsonl bills (Billing module)
```

The import brings it as **history that never acts** (the choice `history`, on by default; CLI
`--no-history`, console **Import the run history**). The plan shows per project what the history
holds and what the target's retention leaves out (`plan.history`). The rules:

| Part | Exported | Imported as | Never |
| --- | --- | --- | --- |
| Deployments | every deployment of the exported projects: version, environment, status, who, created / ready / ended, the runtime image, the config source, the platform's message | rows of `deployments` marked `imported_at`, on the imported version of the same number and the environment of the same name; a deployment live in the source ends at the export (`shut_down`), a failed one stays `failed`. Ids sort by their time, so the project's newest deployment stays the newest | live; metered (usage aggregation skips imported deployments); a config snapshot (a rollback to one deploys its version with the config of now). One of an environment or a version the target does not have is left out |
| Workflow runs and their logs | every run RunR keeps (the source's run retention, 30 days by default) with its event, output, error and every step with input, output, error and attempt history | rows of `workflow_runs` / `workflow_steps` of the imported workflow of the same name, parent / replay / child links pointing into the import; a run still active in the source is `cancelled` ("still running when the organization was exported"), its open steps too; no leases or wake-ups | executed, woken, counted as workflow usage. Runs that ended before the target's run retention are left out (they would be pruned at once); runs of archived workflows too |
| Trigger events (the inbox) | the stored events of the projects' triggers: headers, body, status, attempts, replays | events of the imported trigger of the same name and type, kept for the target trigger's retention from their arrival; a delivered or failed event keeps its state, one the source had not delivered becomes `failed` ("replay it to deliver it here") | delivered on their own (replay one, or every failed one). Events past the retention are left out |
| Usage | the organization's usage records of the exported projects — of every project, also deleted ones and model services, when the whole organization is exported — its bindings' usage records and its workflow usage, as metered; never the platform's copies of tenants' usage | `imported_history` (kind `usage`), read-only: `GET /api/org-transfers/{id}/history?kind=usage`, `runr org transfer history ID` | written into `usage_records`: nothing is charged twice and the target's bills start from its own metering |
| Bills (Billing module) | the organization's project and account bills — closed ones with their final line items, totals and payment state, open ones as the estimate of the export; never the platform's bills to tenants (the platform's books) | `imported_history` (kind `bill`), read-only, **only where billing is on**: an installation without the Billing module reports the section as not imported, one with billing off leaves the bills in the package (warning `billing_off`) | written into `billing_bills`: never recalculated, closed, charged or marked paid again |

Each kind of each project is one transaction and one report entry (`history`), so an apply again
resumes without duplicates. Not in the run history: request traces and replays, metrics, logs of
the workloads (they live in the regions' observability stores), uptime results, alert events and
the audit trail (the source keeps its own; an audit trail is not rewritten into another one).

## Members

Members are exported only when asked for, as e-mail, name, role and groups — never credentials,
two-factor secrets or SSO identities. On import (`invite_members`):

- multi-tenant: an existing account joins with the role, everyone else gets an invitation e-mail
  (the Members page's flow);
- single-tenant: listed as a manual step.

The Owner role is never handed out by an import: owners of the source come back as admins.

## Deploy

With **deploy** the import queues a deploy of every imported project: the version that ran in the
source (else its latest ready version), through the deploy path of every deploy — data residency,
the supply-chain policy gate, admission. A version built for another registry is built again first.
Projects that were stopped in the source are not deployed. Custom domains answer once their DNS
points at the new region and they are verified again.

## Package format

`runr-org-<slug>-<YYYYmmdd-HHMMSS>.tar.zst`: a POSIX tar (ustar, PAX headers for long names and
files of 8 GiB and more), compressed with zstd. Regular files only — no links, devices or
directories. `manifest.json` is the **first entry**, so a reader gets it without unpacking the rest.

```text
manifest.json
organization/organization.json      settings, roles, groups, members, regions
organization/observability.json     alert channels (no secrets), alert rules, status page
organization/gateways.json          gateways and routes
bindings/bindings.json              binding definitions (no credentials)
bindings/data/index.json            the dumps: binding id, file, format, bytes
bindings/data/<binding id>.dump|.kvdump|.tar
projects/<project id>/project.json  project, config, versions, domains, secrets (names), workflows, …
projects/<project id>/code/v<N>.tar the project's files at version N
projects/<project id>/images/v<N>.oci.tar
projects/images.json                the images: project, version, file, digest, platforms
secrets/secrets.enc                 sealed secrets (see Secrets)
extensions/history/…                run history (include_history)
extensions/<section>/…              sections of other features
```

```json
{
  "format": "runr-org-export",
  "schema_version": 1,
  "runr_version": "v0.5.11",
  "created_at": "2026-10-21T12:00:00+00:00",
  "source": {"organization": "Acme", "slug": "acme", "tenant_id": null, "edition": "single", "exported_by": "Ada"},
  "options": {"projects": "all", "include_members": false, "include_images": false, "binding_data": 1, "include_secrets": true, "include_history": false},
  "contents": {"roles": 1, "groups": 1, "bindings": 2, "projects": 3, "versions": 12, "binding_data": 1},
  "sections": ["organization", "bindings", "projects", "triggers", "network", "environments", "cost_limits", "gateways", "observability", "binding_data", "images", "deploy"],
  "projects": ["01jabc…"],
  "encryption": {"secrets": {"file": "secrets/secrets.enc", "alg": "argon2id13+xchacha20poly1305-ietf"}},
  "entries": [{"path": "organization/organization.json", "size": 412, "sha256": "…"}]
}
```

The importer accepts nothing the manifest does not list: every entry must be listed once, have the
listed size and SHA-256, and a safe relative path; an entry missing, extra, changed or truncated
fails the verification before anything is created.

## Compatibility

The importer reads packages of **its own schema version and older ones**. A package of a newer
schema is refused with what to do: *"This package was written by a newer RunR (v0.7.0, package
schema 2); this installation reads schema 1 and older. Upgrade RunR first, then import it."* A
package from an older RunR imports with what it has; settings it lacks take their defaults. Config
columns are carried as they are: a column the target does not know is ignored, one the source did
not have keeps its default.

| Schema | RunR | Changes |
| --- | --- | --- |
| 1 | 0.5.11 | first version; later in schema 1 (additive, older importers report them as unknown sections or ignore the fields): `redis_version` of KV dumps, `extensions/history` and `extensions/billing_history` |

## Sections

Each part of a package is a section (`App\Services\OrgTransfer\Sections\TransferSection`): it exports
its entries, adds what it would do to the plan, imports its entries once each. Features that keep
organization data of their own register a section (`SectionRegistry::extend(MySection::class, 35)`)
and write under `extensions/<key>/`; an older importer reports such data as not imported instead of
failing. Features that only add config columns (a deploy strategy, for example) need nothing: config
columns travel as they are.

| Section | Entries | Import |
| --- | --- | --- |
| `triggers` | `extensions/triggers/triggers.json`: each project's trigger list (name, type, config, enabled, position) and the organization's topics; a trigger's secrets in `secrets/secrets.enc` | topics in the mapped regions (one of the same name is kept), then each project's list replaces the one its config gave it; a webhook gets a new inbox URL (manual step) |
| `network` | `extensions/network/network.json`: access mode, allowlist ranges and the allow list as far as it names projects of the package | the access mode and the callers under their new internal names; grants to other organizations' projects and callers outside the package are not carried (manual step) |
| `environments` | `extensions/environments/environments.json`: name, region, position, approval, branch, auto deploy, secrets inheritance, overrides; their secret values in `secrets/secrets.enc` | created again in the mapped region (quota and capacity checks); nothing is deployed |
| `cost_limits` | `extensions/cost_limits/cost_limits.json` (Billing module): amount, currency, action | set again in the target's billing currency; another currency is a manual step |
| `history` | `extensions/history/…`: deployments, workflow runs, trigger events, usage ([Run history](#run-history)) | as history that never acts |
| `billing_history` | `extensions/billing_history/bills.jsonl` (Billing module): the organization's bills | read-only in `imported_history` where billing is on |

## Permissions

Export and import need the **Owner** role of the organization — a package holds the organization's
code, configuration and possibly its data. The API also requires the `org.transfer` permission
(every route, [Permissions](permissions.md)): admins hold it through their role but are refused
(`403 owner_only`); it scopes API tokens. Platform administrators have no export of their own: they
act as an owner of the organization.

One export and one import per organization run at a time, a few per installation (`429
too_many_transfers`).

## Audit

| Action | When |
| --- | --- |
| `org.export.estimate`, `org.export.start` | the estimate, the export started (options, destination type — never the passphrase or credentials) |
| `org.export.completed` / `org.export.failed` | the job's result: contents, size, SHA-256, destination, secrets included |
| `org.import.start`, `org.import.uploaded` | the upload started (or the read from a source: type, bucket / binding and key — never credentials) / completed |
| `org.import.fetch`, `org.import.fetched`, `org.import.fetch_failed` | a read from a source resumed, finished (size, SHA-256), failed (bytes read, the reason) |
| `org.import.rejected` | the package failed verification (code: schema_too_new, checksum_mismatch, …) |
| `org.import.plan`, `org.import.apply` | a plan / apply with the choices (whether a passphrase was given) |
| `org.import.completed` / `org.import.failed` | the result with the counts, the source organization and its RunR version |
| `org.transfer.cancel` | a transfer cancelled or its files removed |

The source organization audits its export, the target its import.

## API

All under `/api`, `auth:sanctum`, the organization of the token / `X-Tenant-ID`.

| Method | Path | Body / query | Answer |
| --- | --- | --- | --- |
| GET | `/org-transfers` | — | 200 `{data: [transfer]}` (latest 20) |
| POST | `/org-transfers/export/estimate` | export options | 200 `{data: estimate}` |
| POST | `/org-transfers/export` | export options + `passphrase`, `destination` | 202 `{data: transfer}` |
| GET | `/org-transfers/{id}` | — | 200 `{data: transfer}` |
| GET | `/org-transfers/{id}/download` | `Range: bytes=N-` | 200 / 206 `application/zstd`; 409 `not_downloadable` |
| DELETE | `/org-transfers/{id}` | — | 204 |
| POST | `/org-transfers/import` | `{filename, size, sha256?}` | 201 `{data: transfer}` (`upload.chunk_size`) |
| POST | `/org-transfers/import` | `{source: {type: binding, binding_id, key} \| {type: s3, endpoint, bucket, region?, path_style?, key, access_key_id, secret_access_key}, sha256?}` | 202 `{data: transfer}` (status `fetching`) |
| POST | `/org-transfers/{id}/fetch` | S3: `{access_key_id, secret_access_key}` | 202 (status `fetching`); 409 `not_resumable`; 422 `s3_credentials` |
| GET | `/org-transfers/{id}/history` | `?kind=usage\|bill&project=&page=&per_page=` | 200 `{data: [record], meta: {total, …}}` — the usage records and bills an import kept |
| PUT | `/org-transfers/{id}/chunk` | `?offset=N`, raw body | 200 `{uploaded_bytes, size_bytes}`; 409 `not_uploading`; 422 `bad_chunk` |
| POST | `/org-transfers/{id}/complete` | — | 202 (verifying → planned / failed); 422 `upload_incomplete` / `checksum_mismatch` |
| POST | `/org-transfers/{id}/plan` | `{region_map, renames, binding_renames, skip_projects, passphrase, restore_data, skip_binding_data, history}` | 200 `{data}` with the plan |
| POST | `/org-transfers/{id}/apply` | the plan's choices + `{images, invite_members, deploy}` | 202; 422 `plan_blocked` |

Export options: `projects` (ids; all when left out), `include_members`, `include_images`,
`binding_data` (`"all"` or binding ids), `include_secrets` + `passphrase`, `include_history`, `destination`
(`{"type": "download"}`, `{"type": "binding", "binding_id", "prefix"}`, `{"type": "s3", "endpoint",
"bucket", "region", "path_style", "access_key_id", "secret_access_key", "prefix"}`).

A transfer: `id`, `kind` (export / import), `status` (queued, running, uploading, fetching,
verifying, planned, applying, completed, failed, cancelled, expired), `phase`, `progress`,
`message`, `options` (never the passphrase or credentials), `estimate`, `destination`, `package`
(file name, size, SHA-256, schema and RunR version, source organization, downloadable), `upload`
(also the bytes read from a source), `source` (an import's: `upload`, or the binding / bucket and
key — never credentials), `plan` (with `history`), `report`, `created_by`, timestamps.

## MCP tools

| Tool | Does |
| --- | --- |
| `org_export_start` | start an export (download or object binding destination) |
| `org_export_status` | an export's or import's status, package and report; the latest transfers without an id |
| `org_import_start` | start an import of a package in an object binding of the organization; `resume` continues a read that failed |
| `org_import_plan` | the dry-run plan of a package for region map, renames, passphrase, `skip_binding_data`, `history` |
| `org_import_apply` | apply a planned import (`confirm: true`) |

A package file is uploaded with the CLI or the console — a binary file does not go through a chat;
one in an object binding is read with `org_import_start`. An S3 bucket of your own needs
credentials, which the CLI and the console take instead of a chat. See [MCP](mcp.md#tools).

## CLI

`runr org export`, `runr org import` (a FILE, `--from binding:NAME|s3://…`, `--resume ID`), `runr
org transfers`, `runr org transfer ID`, `runr org transfer cancel ID`, `runr org transfer history
ID` — see [the CLI reference](cli.md#organization-export-and-import-runr-org).
Passphrases come from `--passphrase-file` or `RUNR_EXPORT_PASSPHRASE` / `RUNR_IMPORT_PASSPHRASE`,
S3 credentials from `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` — never from a flag value.

## Configuration

`config/org_transfer.php`:

| Setting | Env | Default | Meaning |
| --- | --- | --- | --- |
| `path` | `RUNR_ORG_TRANSFER_PATH` | `storage/app/private/org-transfers` | where packages and working copies live (the shared storage volume) |
| `compression` | `RUNR_ORG_TRANSFER_COMPRESSION` | `zstd` | `none` writes a plain tar (debugging; the importer reads both) |
| `zstd_level` | `RUNR_ORG_TRANSFER_ZSTD_LEVEL` | 3 | zstd level |
| `zstd_binary` | `RUNR_ZSTD_BINARY` | `zstd` | the zstd binary when the PHP extension is not loaded (the API image has it) |
| `chunk_bytes` | `RUNR_ORG_TRANSFER_CHUNK_BYTES` | 8 MiB | upload chunk size |
| `max_package_gb` | `RUNR_ORG_TRANSFER_MAX_GB` | 50 | largest package an import takes |
| `retention_hours` | `RUNR_ORG_TRANSFER_RETENTION_HOURS` | 168 | packages and unpacked copies are removed after |
| `upload_ttl_hours` | `RUNR_ORG_TRANSFER_UPLOAD_TTL_HOURS` | 48 | an upload (or a read from a source) not completed within expires |
| `fetch_chunk_bytes` | `RUNR_ORG_TRANSFER_FETCH_CHUNK_BYTES` | 16 MiB | bytes per ranged read of an S3 source |
| `fetch_stall_minutes` | `RUNR_ORG_TRANSFER_FETCH_STALL_MINUTES` | 30 | a read from a source without progress for this long is marked failed (resumable) |
| `max_running_per_organization` | `RUNR_ORG_TRANSFER_MAX_PER_ORG` | 1 | running exports (and imports) per organization |
| `max_running` | `RUNR_ORG_TRANSFER_MAX_RUNNING` | 4 | running transfers per installation |
| `job_timeout` | `RUNR_ORG_TRANSFER_JOB_TIMEOUT` | 4 h | the jobs' timeout; they run on the long queue (connection `redis-long`, Horizon's `supervisor-long`), whose limits follow it: the supervisor's timeout 60 s above it, the queue's `retry_after` 300 s above it |
| `transfer_image` | `RUNR_TRANSFER_IMAGE` | `ghcr.io/brf-tech/runr/image-upload:1.1.0` | the transfer agent; regions without internet pull it through their registry mirror |
| `transfer_timeout` | `RUNR_ORG_TRANSFER_POD_TIMEOUT` | 3 h | one transfer pod |
| `transfer_chunk_bytes` | `RUNR_ORG_TRANSFER_POD_CHUNK_BYTES` | 16 MiB | bytes per request between the API and a transfer pod |
| `transfer_disk_gb` | `RUNR_ORG_TRANSFER_POD_DISK_GB` | 20 | a transfer pod's emptyDir (raise it for larger data) |

Packages are as large as what they hold: size the storage volume (`api.storage.size` in the Helm
chart) for the code, data and images you export, or write exports to an object binding / S3.

## Data model

Central tables (with the owner of each row, like uploaded images), migrations
`2026_10_21_260001_create_org_transfers_tables` and `2026_10_22_520001` (`import_source`) — no
tenant schema migration:

- `org_transfers`: kind, status, phase, progress, message, options, estimate, destination, the
  package (file name, size, SHA-256, schema and RunR version, source), the upload (size, bytes
  received, chunk size, declared SHA-256), plan, report, who, when, expiry; `owner_key` = tenant id
  or `platform`.
- `org_transfer_items`: per import entry the section, its key in the package, the row it created
  and its status — what makes an apply resumable.
- `org_transfers.import_source`: where an import reads its package from (no credentials) and what a
  resumed read checks (size, ETag or SHA-256).

The run history (central `2026_10_22_520002`, tenant `0107`): `deployments.imported_at` and the
table `imported_history` (the usage records and bills an import kept, kind, the imported project,
the record as the source had it) in the organization's schema.

Deleting a tenant deletes its transfers (`TenantCentralTables`); the hourly expiry removes their
files.

## Limits and what is not exported

- Without `include_history` the run history stays with the source and the deployment history
  travels as a summary. With it ([Run history](#run-history)): deployments, workflow runs with
  their steps, trigger events, usage records and bills. Never: metrics, traces, request replays,
  workload logs (they live in the regions' observability stores), uptime results, alert events and
  audit events (each organization keeps its own trail).
- Private database connections (Skupper), git provider links (GitHub App installations, GitLab /
  Forgejo repositories, preview environments), API tokens, MCP OAuth clients and hosted MCP
  servers' call logs, uploaded images that CaaS projects reference (upload them to the target
  region), regions themselves and region settings, billing records: not exported. Git links are a
  manual step in the plan.
- Custom TLS certificates of domains are kept in the region, not in RunR's database: upload them
  again (a manual step).
- Gateways: routes land on the importing member's gateway; the gateway's address changes with it.
  A route keeps its authentication (`auth_mode`, its JWT settings) and "only through the gateway":
  a key or JWT route is created as one, never public. API consumers and keys are not in the
  package, so a key route refuses its calls until the importing organization gives keys out.

## Testing

- `api/tests/Unit/OrgTransfer/`: tar writer / reader, package writer / reader (manifest first,
  checksums, schema refusal, unsafe paths, zstd), the passphrase box, binding dump scripts (the KV
  dump's Redis version, the bucket read of an import).
- `api/tests/Feature/OrgTransfer/`: the API (owners only, upload with resume, checksum, download
  ranges, limits, cancel, expiry), export → import in one process (collisions and renames, versions
  with their code, secrets with and without the passphrase, a restore into an empty organization,
  region mapping, a newer schema refused), binding data / images / destinations against a fake
  region (`OrgTransferDataTest`: KV Redis versions in the plan and the restore), the transfer pods
  against a fake API server, imports from an object binding and from S3 with ranges, resume,
  replaced objects, checksums, cancel and stalls (`OrgImportSourceTest`), the run history with its
  rules — final states, no metering, retention, replay-only events, read-only usage
  (`OrgRunHistoryTest`); `api/tests/Feature/Billing/BillingHistoryTransferTest.php`: bills as
  read-only history, billing off.
- `api/tests/Feature/Mcp/Tools/OrgTransferToolsTest.php` (also `org_import_start`), `cli/internal/cmd/org_test.go`
  (`--from`, `--resume`, `--skip-data`, `--no-history`, `transfer history`),
  `cli/pkg/runr/org_transfer_test.go`, `vue/tests/modules/org-transfer/` (import sources, plan history
  and Redis versions), `vue/e2e/org-export.spec.ts`,
  `runtime/image-upload/transfer_test.go`, `pull_test.go`, `pushready_test.go`.
- Kind: `runtime/ci.sh test-org-transfer` (`test:e2e --suite=org-transfer`): export with Postgres,
  KV and object data, images and the run history, download, chunked import, plan with collisions,
  the history per project and the KV dump's Redis version, apply with data restore and deploy, the
  copies checked (data, project, imported deployments never live), and an export into the object
  binding imported straight from there (source `binding`: fetched, verified, planned). Tenant to
  tenant: `test:e2e-tenancy`.
