# Upgrading RunR

A RunR installation is upgraded in place with `helm upgrade` of its release. The database,
the API volume, the generated secrets and every region keep running. Never uninstall and install
again: `helm uninstall` deletes the chart's PersistentVolumeClaims (database, API volume) and the
generated `runr-secret`, whose `APP_KEY` encrypts the secrets in the database.

## Procedure

1. **Back up the database.** With the chart's PostgreSQL:

   ```sh
   kubectl -n runr exec deploy/runr-postgres -- pg_dump -U runr -Fc runr > runr-$(date +%F).dump
   ```

2. **Keep `APP_KEY`.** The chart reads it from the existing `runr-secret` on every upgrade
   (`lookup`), so nothing has to be done for an install the chart created. Do not set
   `secret.appKey` for the first time in the same upgrade (see [Key rotation](security.md#key-rotation)
   for changing it). Tools that render the chart without cluster access (`helm template`, some
   GitOps controllers) cannot read the Secret: give them `secret.appKey` with the current key, or
   manage the Secret yourself (`secret.create=false`).

3. **Upgrade the release** with the new chart and its images. The chart comes from BRF's
   public Helm repository, added as `brf-tech` at install
   ([Kubernetes (Helm)](../README.md#kubernetes-helm)); `--version` and the two image tags pin
   the release:

   ```sh
   helm repo update brf-tech
   helm upgrade runr brf-tech/runr --version <version> -n runr --reuse-values \
     --set api.image.tag=<version> --set ui.image.tag=<version>
   ```

   `--reuse-values` keeps the values of the running release; keys the new chart added take their
   defaults. `--reset-then-reuse-values` does the same but starts from the new chart's
   `values.yaml`. Values files work as before (`-f values.yaml`); new settings such as
   `branding.*` can be added in the same upgrade.

4. **Check** `GET /api/version` and `kubectl -n runr logs job/runr-bootstrap`.

What runs during the upgrade, in order:

| Step | When | What |
| --- | --- | --- |
| `runr-migrate-upgrade-<revision>` Job | pre-upgrade hook, before any Deployment changes | `php artisan migrate` (and `tenancy:migrate`) with the **new** API image and the module switches of the new values (`TENANCY_ENABLED`, `AUTH_PROVIDER_ENABLED`, `TICKET_ENABLED`, `BILLING_ENABLED`, `BILLING_CURRENCY` as explicit env: the ConfigMap is applied after this hook); the previous version keeps serving meanwhile |
| Deployments | after the hook | api, horizon, scheduler, reverb, ui and gateway roll to the new images |
| `runr-bootstrap` Job | post-upgrade hook | `agent:redeploy --missing`, `images:move-registry` (stored image references, idempotent), `region:reconcile` (region-side changes of the new version, idempotent), `rbac:assign-missing`, with tenancy `tenancy:migrate` (the tenant migrations of the modules switched on, a safety net), in the multi-tenant edition `tenancy:setup --force` (converts an installation that ran single-tenant into its first tenant, otherwise does nothing; `bootstrap.tenancySetup`), the first administrator when none exists |

Functions keep serving throughout: their traffic goes through each region's ingress, not through
the console. Existing Knative Services, CronJobs and sandboxes are not redeployed by the upgrade;
they pick up the new version's manifests on their next deploy. `region:reconcile` changes only
what a release lists below (e.g. the image namespace of 0.5.1).

Between the migration job and the end of the rollout the previous version still serves the
console — 27 seconds in the e2e run below (migration job 9 s, then the new pods ready). Do not
edit projects, deploy or change regions in that time: the previous version cannot read values the
migration has just encrypted, shows env vars as empty and would save that. Its region status
checks fail in that window and the new version's next check reports the region again.

## 0.5.7 → 0.5.8

The Helm chart's production settings and fixes of single sign-on, multi-tenancy, support tickets
and regions. Values of earlier releases, `--reuse-values` included, keep working. Check before the
upgrade:

- **Back up the database first** (step 1 of the [procedure](#procedure)) and keep the dump: this
  upgrade cannot be rolled back with migrations, going back to 0.5.7 is restoring that backup
  ([Rolling back](#rolling-back)).
- **Multi-tenant edition with `--reuse-values`:** add `--set ui.image.multiTenantSuffix=-mt`
  ([Multi-tenant console image](#multi-tenant-console-image)).
- **Inactive and maintenance regions stop serving** after the upgrade; set the regions that should
  keep answering requests active first ([Region status takes effect](#region-status-takes-effect)).
- **SSO providers:** a provider registered with an `http://` callback URL needs the `https://` one
  ([HTTPS URLs](#https-urls-behind-the-ingress-controller)); a Google provider with a Hosted Domain
  admits accounts of that domain only ([Single sign-on](#single-sign-on)).
- **Passwords in `extraEnv`:** move them to the new password values
  ([Passwords](#passwords-of-an-external-database-redis-and-smtp-server)).
- **Switching to the multi-tenant edition** in this upgrade converts the installation into its
  first tenant: give the upgrade `--timeout 20m` ([Multi-tenant installs](#multi-tenant-installs)).
- **Docker Compose:** take the new `Caddyfile` and the new `api.env.example` settings
  ([Docker Compose](#docker-compose)).
- **Billing is a new module, off by default.** The plans and the billing of 0.5.7 are removed;
  their data is archived and converted. Upgrade after the 0.5.7 `charge-schedule` closed the last
  month (the 1st, 02:30), adapt your billing system to the new API and set `BILLING_CURRENCY` to the
  currency of your 0.5.7 cost configuration in this upgrade, also if billing stays off for now
  ([Billing](#billing)).

### HTTPS URLs behind the ingress controller

The gateway keeps the `X-Forwarded-Proto` of the ingress controller instead of overwriting it with
its own `http`, so the API builds `https` URLs:

| URL | Before | Now |
| --- | --- | --- |
| SSO callback of an OAuth / OpenID Connect provider (`/oauth/<provider>/callback`), sent to the provider and shown in the provider settings | `http://<domain>/…` | `https://<domain>/…` |
| MCP server and OAuth metadata (`/mcp`, `/.well-known/oauth-*`) | `http://<domain>/…` | `https://<domain>/…` |

Where a provider has the `http://` callback URL registered, register the `https://` URL (providers
compare it exactly; Google never accepted the `http://` one). SAML service provider URLs (entity
ID, ACS, metadata) are built from `APP_URL`, which is `https://<domain>` already: they stay as they
are and the identity providers need no change.

### The API's disruption budget

`api.pdb.enabled` creates the API's PodDisruptionBudget only with a ReadWriteMany API volume
(`api.storage.accessModes`, new). With the ReadWriteOnce volume of every earlier install the API
pods cannot leave the volume's node, and the budget held a drain of that node forever: the upgrade
removes it, and `helm status` notes it. The API pods stay colocated with the volume as before.

### Replica counts with an autoscaler

With `gateway.hpa`, `api.hpa` or `ui.hpa` on, the chart leaves the replica count of that Deployment
to the HorizontalPodAutoscaler: an upgrade keeps the count the HPA set instead of resetting it to
`*.replicas`.

### Passwords of an external database, Redis and SMTP server

`database.password` / `database.existingSecret`, `redis.password` / `redis.existingSecret` and
`mail.password` / `mail.existingSecret` (with `mail.username`, `mail.encryption`, `redis.username`)
bring these passwords from Secrets. An install that has them in `extraEnv` (`REDIS_PASSWORD`,
`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`) keeps working; move them in the same upgrade and
remove them from `extraEnv`, which lands in the ConfigMap `runr-config`:

```sh
helm upgrade runr brf-tech/runr --version 0.5.8 -n runr --reuse-values \
  --set redis.password='<redis password>' \
  --set mail.username=runr@example.com --set mail.password='<smtp password>' --set mail.encryption=tls \
  --set extraEnv=null
```

(`--set extraEnv=null` removes every `extraEnv` entry: keep others, e.g. `DB_SSLMODE`, with
`--set extraEnv.DB_SSLMODE=require`.) An install with `secret.create: false` sets
`*.existingSecret` instead. An install that used `secret.create: false` only to give an external
database's password can keep doing so.

### Horizon dashboard

`/horizon` answered 403 to everyone in production. Platform administrators and, in the
single-tenant edition, Owners open it with a single-use link: `kubectl -n runr exec deploy/runr-api -- php artisan
runr:horizon-link`, or `POST /api/horizon/link` ([install.md](install.md#horizon-dashboard)). The
Compose gateway routes `/horizon` too ([compose/README.md](../compose/README.md#horizon-dashboard)).

### CORS

The API allows the console's own origin (`https://<domain>`) and `cors.allowedOrigins`; the
development origins (`localhost:5173`, `runr.test`, …) it allowed in production are gone. The
console is same-origin and needs none of them. Browser pages on other origins that call the API
need `cors.allowedOrigins`.

### Multi-tenant console image

With `edition: multi-tenant` the chart runs the multi-tenant console,
`ghcr.io/brf-tech/runr/ui:<tag>-mt` (`ui.image.multiTenantSuffix`, default now `-mt`; with the tag
`latest` the chart's release, `<appVersion>-mt`). Before, the empty default suffix ran the
single-tenant console, without the tenant and SSO pages. `--reuse-values` keeps the empty suffix
of the earlier release, so add it to the upgrade:

```sh
helm upgrade runr brf-tech/runr --version 0.5.8 -n runr --reuse-values \
  --set api.image.tag=0.5.8 --set ui.image.tag=0.5.8 --set ui.image.multiTenantSuffix=-mt
```

A UI image of your own that carries the modules under its plain tag keeps
`ui.image.multiTenantSuffix: ""`. Single-tenant installs are not affected.

### Ticket module

`ticket.enabled` (new, default `true`) sets `TICKET_ENABLED` of the API; `true` is the module state
of every earlier install, also for reused values without the key. Installs deployed with
`runtime/ci.sh deploy` without `--ticket` carry `ticket.enabled=false`: their API now disables the
Ticket module, as their console already did. The console shows the ticket pages when its UI image
is built with `VITE_TICKET_ENABLED=true` ([UI build flags](modules.md#ui-build-flags)). Ticket
attachments move into per-organization directories during the upgrade
([Multi-tenant installs](#multi-tenant-installs)).

### Region status takes effect

An `inactive` or `maintenance` region kept serving its functions: the route of its status block
never matched. After the upgrade, `region:reconcile` in the bootstrap Job applies the block to every
region with Knative Serving ready that is not `active` — in a multi-tenant install the
organizations' own regions too: requests to its function domain, its project custom domains and
the domains of its Knative Services answer `404 region_inactive` or `503 maintenance`; the project
pods keep running ([Region status](regions.md#region-status)). A region created with
`is_active: false` showed `active` and served; the upgrade's migrations (central and tenant
schemas) give it the status `inactive`. List the regions and their status before the upgrade and
set the ones that should keep serving active:

```sh
runr regions ls
runr regions set REGION --set status=active
```

### Request timeouts of the regions

`region:reconcile` sets the region Traefik's read and write timeouts to the longest
`request_timeout` of the regions on the cluster plus 10 seconds and raises Knative's maximum
revision timeout to it, so a `request_timeout` above 120 seconds takes effect. Traefik's pods on
each region cluster roll once during the bootstrap Job ([Request timeout](regions.md#request-timeout)).

### Single sign-on

- A Google provider with a **Hosted Domain** sends it to Google and refuses accounts of another
  domain or of none (personal accounts); before, any Google account signed in. Clear the field to
  admit every Google account.
- An address the provider marks unverified (`email_verified: false`) no longer signs in to the
  account with that e-mail address; accounts linked before keep signing in.
- *Generic OIDC* providers work (every sign-in failed). Each sign-in button names its provider, so
  several providers of one driver can be enabled; OpenID Connect users are identified per provider
  and GitLab users per self-managed instance, and accounts linked earlier keep working
  ([At sign-in](modules.md#at-sign-in)).
- `GET /api/oauth/{driver}/link` links an external account to the signed-in user
  ([Linked accounts](modules.md#linked-accounts)); a client that used it for a sign-in URL calls
  `GET /api/oauth/{driver}/redirect`.
- Multi-tenant: an SSO sign-in started on a tenant's sign-in page starts the console in that
  tenant and admits the tenant's members and platform administrators only.
  `POST /api/oauth/exchange` answers like the password sign-in, with `tenants` and `active_tenant`
  next to the token; a front end of your own that handles `/oauth/callback` starts in
  `active_tenant` ([SSO sign-in handoff](security.md#sso-sign-in-handoff)).

### Multi-tenant installs

- **Platform administrators.** An installation that switches from single-tenant to the
  multi-tenant edition gets its owners and admins as platform administrators: a Tenancy migration
  promotes them when the installation has none ([Platform administrators](tenancy.md#platform-administrators)).
- **Conversion by the bootstrap Job.** In the multi-tenant edition the bootstrap Job runs
  `php artisan tenancy:setup --force` before its administrator check (deadline 1200 seconds instead
  of 720). An installation that ran single-tenant becomes its first tenant (`Default`, the slug of
  the git remote owner); fresh, converted and multi-tenant installations are left as they are.
  Give the upgrade that switches the edition `--timeout 20m`, and read the result with
  `kubectl -n runr logs job/runr-bootstrap`. `--set bootstrap.tenancySetup=false` leaves the
  conversion to you, to see its plan first or choose the name and slug
  ([Converting a single-organization installation](tenancy.md#converting-a-single-organization-installation)).
  From the conversion day the organization's projects are metered and billed in the tenant only,
  and the tenant needs a billing webhook of its own ([Billing after the conversion](tenancy.md#billing-after-the-conversion)).
- **Ticket attachments** move from `support-attachments/<ticket id>/` to
  `support-attachments/<tenant id>/<ticket id>/` during the upgrade.
- **Regions an organization added** are marked as its own (`owner_tenant_id`): the organization
  CPU/RAM ceiling no longer applies to them, and its projects, previews and sandboxes there are
  limited by the region's capacity only.
- **Platform regions reach the organizations' copies.** Status (active, inactive, maintenance),
  migration state and the status checks' results are synced to every organization the region is
  assigned to, so their deploys respect a region in maintenance. Ending a region assignment or
  deleting a platform region removes the organization's copy and tears down its projects there.
- `tenancy:migrate --fresh` drops and recreates the tenant's schema only (it dropped the central
  tables too) and asks for confirmation in production unless `--force`.
- New organizations get the tenant migrations of every module (the Ticket module's waited for the
  next upgrade).
- **Open as administrator** works again for platform administrators, audit-logged
  ([Opening a tenant as platform administrator](tenancy.md#opening-a-tenant-as-platform-administrator)).
- **Organization domains move.** A domain changed after the upgrade keeps the functions answering
  on the previous one and on the new one until DNS of the new `<region>.<domain>` answers, or until
  **Complete now** in the Organization domain card ([Organization domain](tenancy.md#organization-domain));
  domains changed before the upgrade need nothing. An organization's own domain can no longer be
  the platform's base domain, and detaching a region from an organization keeps the platform's own
  domain entry on the region.
- **`region_mode`** (managed or infinite organization quotas) is taken from platform
  administrators only; the value anyone else sends on region create, update or setup is ignored.

### Docker Compose

Update from a checkout of the release tag ([Updating](../compose/README.md#updating)), and:

- take the new `Caddyfile`: it routes `/oauth/<provider>/callback` to the API and the console's own
  `/oauth/callback` page to the console (the API answered that page with 404, so SSO sign-ins
  failed), `/horizon` to the API, and keeps the `X-Forwarded-Proto` and
  `X-Forwarded-For` of a proxy on a private address, so behind the HTTPS proxy of
  [section 7](../compose/README.md#7-https) the API builds `https` URLs;
- new in `api.env.example`: `ADMIN_USERNAME`, `ADMIN_EMAIL`, `ADMIN_PASSWORD` (the migrator creates
  the first administrator while the installation has none; an installation with accounts gets no
  new one), `TRUSTED_PROXY_HOPS` (`2` behind the host proxy) and `SESSION_SECURE_COOKIE=true`
  behind HTTPS;
- `compose/build.sh` builds the UI with the module toggles of `compose/.env`: the next build shows
  the modules the API already runs (`TENANCY_ENABLED`, `AUTH_PROVIDER_ENABLED`, `TICKET_ENABLED`,
  and the new `BILLING_ENABLED`, default `false`);
- new in `api.env.example`: `BILLING_CURRENCY`, `BILLING_WEBHOOK_URL`, `BILLING_WEBHOOK_SECRET`
  (read with `BILLING_ENABLED=true`, [Billing](#billing)).

### Billing

0.5.7 calculated charges per tenant from usage, plans, capacity deals and purchases, in the
multi-tenant edition only. 0.5.8 replaces all of it with the [Billing module](billing.md): one bill
per project and month paid by the project's owner, region prices with effective dates, pay-as-you-go
and fixed regions, shared regions with markup, in both editions. The module is **off by default**
(`billing.enabled`, `BILLING_ENABLED`). The upgrade carries the data forward without losing any:

1. **Before the upgrade** (multi-tenant installs with charges): let the 0.5.7 `charge-schedule`
   close the last month — it runs on the 1st at 02:30. A charge still open at the upgrade is archived
   but not converted (step 4). Note the currency of your cost configuration (Settings → Billing →
   Configuration) and set **`billing.currency`** (`BILLING_CURRENCY`) to it in this upgrade, also
   when billing stays off for now: the upgrade imports the prices as plain numbers in that currency
   (step 3). Take the database backup of the [procedure](#procedure).
2. **Archive.** A Tenancy migration copies every row of `plans`, `tenant_subscriptions`,
   `billing_purchases` (credits, prepaid plan terms, add-ons), `charges`, `capacity_deals` and
   `cost_configs` into `billing_legacy_archive` — one row per record with its table, id, tenant and
   all its columns as JSON — and then drops those tables. The settings `plans_enabled`,
   `billing_store_url` and `billing_usage_thresholds` are removed. `billing_events` stays: its events
   remain readable in the new event log.
3. **Prices.** The Billing module's central migrations run at every start, whether the module is on
   or not (its tenant migrations run with `tenancy:migrate` in every tenant schema). They seed the
   platform's default prices ([default values](billing.md#price-fields)) and turn every active cost
   configuration of the archive into a price version effective from `2000-01-01`: the global
   configuration becomes the platform's default prices, a region's configuration that region's
   prices. Units are converted:

   | 0.5.7 cost configuration | 0.5.8 price |
   |---|---|
   | `cpu_price_per_mc_ms` | `cpu_vcpu_hour` = value × 3 600 000 000 |
   | `memory_price_per_mib_ms` | `memory_gb_hour` = value × 1024 × 3 600 000 |
   | `gpu_price_per_unit_ms` | `gpu_hour` = value × 3 600 000 |
   | `cpu_price_per_core_month`, `memory_price_per_gb_month` | `cpu_vcpu_month`, `memory_gb_month` |
   | `kv_price_per_gb_hour`, `object_price_per_gb_hour`, `postgres_price_per_gb_hour`, `postgres_instance_price_per_hour` | `kv_gb_hour`, `object_gb_hour`, `postgres_gb_hour`, `postgres_instance_hour` |
   | `multiplier_http`, `multiplier_queue`, `multiplier_schedule` | the same multipliers |
   | — | `gpu_month`: the default value |

   A price of `0` in 0.5.7 was a column nobody had set (the columns defaulted to 0), so it becomes
   the default value, not "free" — a missing price is never zero in 0.5.8. Review the imported
   prices in Settings → Billing → Regions & prices and set `0` again where you mean free. Each
   imported version notes the 0.5.7 currency. Prices carry no currency of their own: every number is
   read in `BILLING_CURRENCY`. When the 0.5.7 currency differs from it, the import logs a warning
   (`Billing: the 0.5.7 prices were in EUR, BILLING_CURRENCY is USD …` in the migration job's log),
   and Settings → Billing shows it on top (`warnings` of `GET /api/billing/settings`, code
   `legacy_currency_mismatch`) until `BILLING_CURRENCY` is set to that currency.
4. **Charges.** Every **closed** charge becomes part of a closed **tenant bill** (`kind: tenant`,
   `source: legacy`), one per tenant, month and currency: its line items are the charges' line items
   (each with the charge id and kind in `metadata`), a negative "Credit applied" line when credit was
   drawn, and the total is what was due. These bills stand next to the tenant's 0.5.8 bills: a
   converted charge of the upgrade month does not take the place of that month's tenant bill. `external_ref` values become the bill's
   `payment_reference`; the bills are not marked paid — mark them with
   `PUT /api/billing/tenant-bills/{bill}/paid` if your system settled them. **Void** charges and
   charges that were still **open** are not converted; they stay in the archive.
5. **Webhook.** The billing webhook URL and secret saved in Settings → Plans & Billing are copied to
   the platform's billing settings (Settings → Billing → Webhook). The Helm values
   `billing.webhookUrl` and `billing.webhookSecret` keep working.
6. **Removed values and variables.** `plans.enabled`, `billing.usageThresholds` and
   `billing.storeUrl` are no longer read (reused values that still carry them are ignored), nor are
   `PLANS_ENABLED`, `BILLING_USAGE_THRESHOLDS`, `BILLING_STORE_URL` and `BILLING_DEFAULT_CURRENCY`.
   Set **`billing.currency`** (`BILLING_CURRENCY`) to the currency your prices are in — every price
   and bill of 0.5.8 is in this one currency.
7. **Region assignments** of 0.5.7 stay active (`tenant_regions.status = active`); only regions
   assigned while billing is on wait for the tenant's acceptance (with billing off, offers are
   active). Tenants start with a default markup of 0 %
   ([Shared regions with billing](tenancy.md#shared-regions-with-billing)).
8. **Project owners.** Every project gets an owner: the user who created it (`created_by`).
   Projects created by the system (demo projects) have none until one is set with
   `PUT /api/projects/{project}/owner`; their bills have no owner ("Unassigned" in the rollup). A
   new owner must be a member of the project's organization who sees the project through their own
   role or project access — a platform administrator is not made a tenant project's payer through
   their access to every organization — and the open bill of the month goes to whoever owns the
   project when the month closes ([Who pays](billing.md#who-pays)).
9. **Metering in the core.** Usage is now recorded on single-tenant installations too, from the
   upgrade on (there is no history before it). In multi-tenant installs the tenant schemas already
   held usage records; every metering run copies the running month's usage of tenant projects in
   platform regions to the central database (during the first two days of a month also the month
   before), so the tenant bill of the upgrade month covers the whole month. Metering now counts
   each deployment once with what it ran: deployments record the size they were applied with and
   when they stopped running (`deployments.sizing`, `deployments.ended_at`, new columns in the
   central and every tenant schema), always-on workloads count the time they ran instead of 24
   hours, and a redeploy no longer counts the day's sessions again. The first runs after the
   upgrade recalculate today and yesterday; earlier days of the month keep their 0.5.7 rows (in
   tenants: 0.5.7 metered tenant projects only), where a project redeployed on a day had that day's
   sessions (or its always-on reservation) counted once per deployment. To recalculate them before
   the month's bills close, run once after the upgrade with the first day of the month (at most 93
   days at a time):

   ```bash
   kubectl -n runr exec deploy/runr-api -- php artisan usage:recalculate 2026-10-01
   ```

   Deployments applied before the upgrade have no recorded size. A recalculated day counts each of
   them with the scale mode and size of its own 0.5.7 row of that day — scale to zero: its
   sessions; always on: the reserved size and `min_scale` 0.5.7 recorded, for the time it ran
   instead of 24 hours — never with today's configuration. A deployment without a row of the day
   (it ran for less than one 5-minute 0.5.7 run, or 0.5.7 did not meter the project) gets no
   always-on reservation, only its sessions. Today and yesterday, the days the metering job
   calculates after the upgrade, such a deployment counts with its project's current configuration
   until it is redeployed. The 0.5.7 always-on row of a deployment deleted since keeps only the part
   of the day the project's other deployments in that region did not run
   ([Metering](monitoring.md#metering)).
10. **Your billing system.** The 0.5.7 billing API is gone. What it read and wrote:

    | 0.5.7 | 0.5.8 |
    |---|---|
    | `GET /api/billing/charges`, `GET /api/billing/charges/{charge}` | `GET /api/billing/bills` (project and account bills of the organization), `GET /api/billing/tenant-bills` (the platform's bills to tenants) |
    | `PATCH /api/billing/charges/{charge}` `{external_ref}` | `PUT /api/billing/bills/{bill}/paid` `{paid, paid_at, payment_reference}`, `PUT /api/billing/bills/{bill}/payment-url` |
    | `GET /api/billing/tenants/{tenant}/usage` | `GET /api/billing/usage` (per project) |
    | `billing.period.closed` (per tenant and month) | `bill.closed` (per bill) |
    | plans, subscriptions, purchases, credits, add-ons, one-off charges, void | — (RunR calculates from region prices only) |

    Tokens with `billing.view` / `billing.manage` keep working.
11. **Reading the 0.5.7 records.** With the Billing module on, platform administrators read the
    archive with `GET /api/billing/legacy-records?source=charges&tenant=<id or slug>` (`source`:
    `charges`, `plans`, `tenant_subscriptions`, `billing_purchases`, `capacity_deals`,
    `cost_configs`). Without it, the rows are in the table:

    ```sql
    SELECT source_table, row_id, tenant_id, data FROM billing_legacy_archive WHERE source_table = 'charges' ORDER BY id;
    ```

12. **Switching billing on.** `--set billing.enabled=true --set billing.currency=EUR` (Compose:
    `BILLING_ENABLED=true` in `compose/.env` and a rebuild of the images). The pre-upgrade migration
    job creates the billing tables of every tenant schema — it takes the switch from the new values,
    not from the previous release's ConfigMap — and the bootstrap Job runs `tenancy:migrate` once
    more after it. This holds for the upgrade to 0.5.8 and for any later upgrade that switches
    billing (or tickets) on. Then check the prices, set the billing
    type of each region (all are `payg` until you change them) and connect your invoicing system
    ([Turning billing on](billing.md#turning-billing-on)).

The console's Settings → Plans & Billing, Subscription, Charges and the old Billing page are gone;
with the module on, Settings → Billing, Settings → My bills and the project's Billing tab replace
them.

### Rolling back

There is no way back from 0.5.8 with migrations: the upgrade archives and drops the 0.5.7 billing
tables, and the bills of 0.5.8 are the books. `php artisan migrate:rollback` across these
migrations stops with `RunR 0.5.8 cannot be rolled back with migrate:rollback … restore the database
backup taken before the upgrade` instead of half-undoing them. To return to 0.5.7:

1. stop everything that writes to the database:
   `kubectl -n runr scale deploy runr-api runr-horizon runr-scheduler runr-reverb --replicas=0`;
2. restore the dump of step 1 of the [procedure](#procedure), taken right before the upgrade:

   ```sh
   kubectl -n runr exec -i deploy/runr-postgres -- pg_restore -U runr -d runr --clean --if-exists < runr-<date>.dump
   ```

3. `helm rollback runr <revision of 0.5.7> -n runr`: the 0.5.7 pods start on the restored database.

With Docker Compose: `docker compose stop api horizon scheduler reverb`, restore the dump into the
`postgres` service the same way, then deploy the 0.5.7 checkout again.

Everything written after the backup — new projects, deployments, usage, bills — is lost with the
restore; roll back soon after the upgrade or not at all.

### API changes

- `GET /api/bindings/{id}/usage` and `GET /api/sandbox-usage` answer quantities only: `final_cost`,
  `raw_cost` and `currency` are gone. Metering no longer calculates money (every new record had
  `0`); what usage costs comes from the [Billing module](billing.md): `GET /api/billing/usage` and
  the bills — bindings and sandboxes are on the account bills (`GET /api/billing/bills?kind=account`).
  `runr bindings get --usage` and `runr sandbox usage` no longer show a COST column.
- Deleting a tenant keeps its project and account bills in the central `billing_archived_bills`,
  read with `GET /api/billing/archived-bills` (platform administrators); when they cannot be copied
  the tenant stays `delete_failed`, also with `?force=1`
  ([Bills of deleted tenants](billing.md#bills-of-deleted-tenants)).
- `PUT /api/knative-regions/{id}/settings` no longer takes `namespace`, which was never read.
- `PUT /api/knative-regions/{id}` with `kubeconfig` checks it like a new region's; it has to reach
  the same cluster.
- `POST /api/knative-regions/{id}/workers` takes an empty list, which releases every node.
- `GET /api/knative-regions/{id}/knative-status` and `GET …/nodes` answer the error of an
  unreachable cluster and keep the kubeconfig; their answers have no `kubeconfig_cleared`.
- The Redis mode of a region is `in_cluster`.
- `GET /api/settings` returns `assignable_regions` to platform administrators only, and
  `tenant_base_domain` and `platform_base_domain` inside an organization.

### Further changes

- Reverb logs every connection and message only with `app.debug: "true"` or `app.env: local`.
- `reverb.replicas` above 1 works: the replicas share broadcasts over Redis.
- `api.port`, `ui.port` and `reverb.port` are the ports the processes listen on (the defaults are
  unchanged).
- With `secret.create: false` and no `ADMIN_PASSWORD` in the Secret, a new install's generated
  administrator password goes to the Secret `runr-admin` instead of the bootstrap Job's log.
- `values.schema.json` validates `images.*` and `supplyChain.*`, the keys the templates read.
- The chart requires Kubernetes 1.23 or later (`kubeVersion`).
- `database.driver` other than `pgsql` stops the upgrade with a message: RunR's pods share one
  PostgreSQL server.
- New, off by default: `networkPolicy.enabled`, `ingress.tls.mode: secret`, `gateway.affinity` /
  `api.affinity` / `ui.affinity`, `database.postgres.storageClassName`.
- A changed base domain migrates the regions that follow it: both domains are served until DNS of
  the new one answers or the migration is completed by hand
  ([Changing the base domain](regions.md#changing-the-base-domain)).
- Region installs after the upgrade use the pinned Prometheus (kube-prometheus-stack 92.10.1) and
  Loki (loki-stack 2.10.3) charts and, without the chart repository, the newest pinned Knative
  version the cluster supports; installed regions keep their versions.
- A removed tracing backend stays removed: the removal sets the region's tracing mode to `off`.

## 0.5.1 → 0.5.2

### Tracing backend

`region:reconcile` (step `tracing`) brings the tracing backend of every region to this release
where tracing is enabled, and installs nothing anywhere else:

| Region | The step |
| --- | --- |
| Tracing mode `managed` (its own setting) | installs or updates the OpenTelemetry Collector and Grafana Tempo |
| Tracing mode `reuse` or `external` | installs or updates the collector |
| Tracing mode `off` | does nothing |
| No tracing setting of its own — Knative Serving installed by 0.4.x, or with `installWithServing: false` | does what an install of Knative Serving does now: the platform's `observability.tracing.defaultMode` (default `managed`) while `observability.tracing.installWithServing` is `true` (the default); nothing otherwise |

It installs a missing backend and updates one whose chart version or image differs from this
release's: chart `open-telemetry/opentelemetry-collector` 0.176.0 with
`ghcr.io/brf-tech/reqs/opentelemetry-collector-contrib:0.162.0`, chart `grafana-community/tempo`
3.2.0 with `ghcr.io/brf-tech/reqs/tempo:3.1.0` — RunR's mirrors of the Docker Hub images 0.5.0
and 0.5.1 pulled directly (`otel/opentelemetry-collector-contrib`, `grafana/tempo`), so regions
no longer depend on Docker Hub's anonymous pull limit for them. It runs what
`php artisan cluster:install-tracing` runs: a Helm upgrade (or install) of the `otel-collector`
and `tempo` releases with their values rendered anew, the span-metrics PodMonitor and Knative's
tracing configuration. The collector and Tempo pods roll once — spans sent while the collector
restarts are dropped, Tempo keeps its traces on its volume — and the region's tracing status is
`installing` until they run. Regions on one cluster share the backend: the first region of the
cluster with tracing enabled decides it.

Tempo chart 3.2.0 applies the retention (`observability.tracing.tempoRetentionHours`, default
72 hours, or the region's own) where Tempo deletes old traces; with chart 3.1.0 the setting did
not reach that part of Tempo, which kept traces for its default of 14 days (the chart's upgrade
notes). After the update traces older than the retention are removed: raise it before the
upgrade to keep them longer.

Before the upgrade:

- `php artisan region:reconcile --dry-run` lists what the step would install or update.
- To keep tracing off on regions without their own setting, set
  `observability.tracing.installWithServing=false` (or `defaultMode=off`) in the upgrade; for one
  region, set its tracing mode to `off` in the console.
- Regions that pull from a registry of your own: mirror the two images there and set
  `observability.tracing.collectorImage` / `tempoImage` (the same versions).

`observability.tracing.collectorChartVersion` and `tempoChartVersion` are empty by default (the
versions above). The values the charts of 0.5.0 and 0.5.1 set, `0.175.1` and `3.1.0`, which
`--reuse-values` keeps, count as empty; set another version to pin one.

### Image namespace ghcr.io/brf-tech/runr

RunR's images are published under `ghcr.io/brf-tech/runr`, so the organization's other packages
never share a name with RunR's. The tags and digests are the ones 0.5.1 published directly under
`ghcr.io/brf-tech` (and releases up to 0.5.0 under `ghcr.io/runrsh`); the `runr-` prefix of the
component images is gone:

| Up to 0.5.0 | 0.5.1 | 0.5.2 |
| --- | --- | --- |
| `ghcr.io/runrsh/reqs/<image>` | `ghcr.io/brf-tech/reqs/<image>` | `ghcr.io/brf-tech/runr/reqs/<image>` |
| `ghcr.io/runrsh/exec-<runtime>` | `ghcr.io/brf-tech/exec-<runtime>` | `ghcr.io/brf-tech/runr/exec-<runtime>` |
| `ghcr.io/runrsh/runr-wrapper` | `ghcr.io/brf-tech/runr-wrapper` | `ghcr.io/brf-tech/runr/wrapper` |
| `ghcr.io/runrsh/runr-agent` | `ghcr.io/brf-tech/runr-agent` | `ghcr.io/brf-tech/runr/agent` |
| `ghcr.io/runrsh/runr-queue-consumer` | `ghcr.io/brf-tech/runr-queue-consumer` | `ghcr.io/brf-tech/runr/queue-consumer` |
| `ghcr.io/runrsh/runr-supply-chain` | `ghcr.io/brf-tech/runr-supply-chain` | `ghcr.io/brf-tech/runr/supply-chain` |
| `ghcr.io/runrsh/hf-serve`, `image-upload`, `containerd-shim-spin` | `ghcr.io/brf-tech/hf-serve`, `image-upload`, `containerd-shim-spin` | `ghcr.io/brf-tech/runr/hf-serve`, `image-upload`, `containerd-shim-spin` |
| `ghcr.io/runrsh/runr/api`, `runr/ui` | `ghcr.io/brf-tech/runr/api`, `runr/ui` | the same as 0.5.1 |

The upgrade moves every reference an existing installation holds, from 0.5.1 and from earlier
releases alike, the way the [0.5.1 move](#image-namespace-ghcriobrf-tech) did: Helm values
(template helper `runr.image`), the database (migration `move_images_to_runr_namespace` and tenant
migration `0055`, then `php artisan images:move-registry` in the bootstrap job), the region
workloads, the region Traefik and the WebAssembly installer (`region:reconcile`: `image-registry`,
`traefik-image`, `wasm-sandbox`). Only RunR's own packages move: another package directly under
`ghcr.io/brf-tech`, a mirror of your own and the region registry's `<registry>/ghcr.io/…` path
stay as they are. The system images policy passes `ghcr.io/brf-tech/runr/**` (`region:reconcile`,
`admission-policy`, before the workloads move) and keeps passing `ghcr.io/brf-tech/**` and
`ghcr.io/runrsh/**` for the pods that keep running with an image of the earlier names.

Two kinds of region workloads move their pod template but keep their running pods, because a
restart costs more than the move; a pod created later — on a new node, or after the pod was deleted
or evicted — starts from `ghcr.io/brf-tech/runr`:

| Workload | Why its pods stay | How it moves |
| --- | --- | --- |
| Node installers of Kata (`kata-installer`, Firecracker's `kata-devmapper`) and gVisor (`gvisor-installer`) in `runr-sandbox` | A new pod re-runs the install on its node; kata-deploy's cleanup and reinstall restart the container runtime | One patch: the new images and the update strategy `OnDelete`, so the DaemonSet controller replaces no pod. A gVisor install, a Kata install that re-runs kata-deploy and Kata's Firecracker setup apply `RollingUpdate` again; Kata's drop-in update keeps `OnDelete` |
| The region's queue Redis (`runr-redis`) | Its streams, consumer groups and the KV bindings' data live in memory only: a new pod starts empty | The Deployment is paused, its ReplicaSet gets the new image (the running pod stays), then the Deployment gets it and is resumed in one patch: the Deployment controller keeps that ReplicaSet and starts no pod. A run that stops half-way leaves it paused (annotation `runr.dev/image-move-paused`); the next run finishes the move |

`region:reconcile` reports them as `moved`, with what keeps running; such a pod runs its image from
the earlier name until it is replaced. A Redis Deployment paused by someone else, or in the middle
of a rollout, is listed as `kept:` and moves at the first run after it is resumed or the rollout is
over.

`php artisan images:move-registry --dry-run` and `php artisan region:reconcile --dry-run` show
what moves before the upgrade's bootstrap job does it.

A registry of your own that holds copies of RunR's images under their paths (an air-gapped
registry, the CLI's `RUNR_RUNTIME_REGISTRY`) needs them under the new paths: copy them from
`ghcr.io/brf-tech/runr`. Image settings that point into such a registry stay as they are.

## 0.5.0 → 0.5.1

### Image namespace ghcr.io/brf-tech

0.5.2 moves these references on to `ghcr.io/brf-tech/runr`
([Image namespace ghcr.io/brf-tech/runr](#image-namespace-ghcriobrf-techrunr)); an upgrade from
0.5.0 straight to 0.5.2 moves them there in one step.

RunR's images are published under `ghcr.io/brf-tech` — the same image names, tags and digests
that releases up to 0.5.0 published under `ghcr.io/runrsh`. The upgrade moves every reference an
existing installation holds (also one that comes from 0.4.x); only the exact prefix `ghcr.io/runrsh/`
moves, references into other registries — a mirror of your own, the region registry's
`<registry>/ghcr.io/…` path — stay as they are:

| Where | What moves | How |
| --- | --- | --- |
| Helm values | Image values under `ghcr.io/runrsh/` — set at install, or the previous chart's defaults that `--reuse-values` keeps — render as the same image under `ghcr.io/brf-tech/`: API, UI, gateway, PostgreSQL, Redis, Mailpit, init containers, `ai.images`, `bindings.object.image`, `supplyChain.toolsImage`, `images.uploadImage`, `knative.traefikImage` | template helper `runr.image` |
| Database | Runtime versions; runtime plugins (manifest, `runtime.yaml` as submitted and uploaded Dockerfiles — their region builds stay valid); CaaS images and version snapshots; region, binding and supply-chain settings; model services; sandboxes; security reports — the central schema and every tenant schema | migration `move_images_to_brf_tech_registry` and tenant migration `0054`; the bootstrap job runs `php artisan images:move-registry` once more for rows the previous version's pods wrote during the rollout |
| Region workloads | Every workload RunR deployed: the region agent, queue consumers, the registry and its node mirror, Loki, Prometheus, binding data services, image pre-pull, CronJobs and the Knative Services of functions, CaaS projects, model services and hosted MCP servers (the wrapper init container and runtime images included) | `region:reconcile` (`image-registry`): one patch per workload; a Knative Service gets a new revision that takes the traffic once it is ready, the previous revision serves until then; the other workloads roll once, apart from the sandbox node installers and the queue Redis, which keep their running pods (below) |
| Region Traefik | `ghcr.io/runrsh/reqs/traefik` of any version | `region:reconcile` (`traefik-image`): Helm upgrade of the `traefik` release with the same version |
| WebAssembly | The shim image of the `wasm-installer` | `region:reconcile` (`wasm-sandbox`) re-runs the installer: the same binaries, no container runtime restart |
| Admission policy | The system images policy passes `ghcr.io/brf-tech/**` | `region:reconcile` (`admission-policy`), before the workloads move |

The node installers of Kata (with its devmapper) and gVisor — a new pod re-runs the install on its
node, and kata-deploy's cleanup restarts the container runtime — and the region's queue Redis
(`runr-redis`), whose streams live in memory, move their pod template but keep their running pods
(`region:reconcile` does not count the image namespace alone as a Kata change); how is described
under [Image namespace ghcr.io/brf-tech/runr](#image-namespace-ghcriobrf-techrunr). The system
images policy keeps passing `ghcr.io/runrsh` for those pods.

In the console's namespace the chart's PostgreSQL, Redis, gateway and Mailpit roll once with their
moved images, together with the new version's API and UI: PostgreSQL is unavailable for a few
seconds (an API pod that starts meanwhile restarts until it is back) and Redis starts empty — the
usual rule of the rollout window applies, do not deploy or edit projects until it is over.

`php artisan images:move-registry --dry-run` and `php artisan region:reconcile --dry-run` show
what moves before the upgrade's bootstrap job does it.

Outside the installation:

- **CLI**: `runr` downloads and update checks use the `brf-tech/runr` releases; with Go:
  `go install github.com/brf-tech/runr/cli@latest`.
- **GitHub Action**: `uses: brf-tech/runr/actions/deploy@<tag>`.
- **Terraform**: the provider's address is `brf-tech/runr`. Change `source` in
  `required_providers` and move the state once:
  `terraform state replace-provider registry.terraform.io/runrsh/runr registry.terraform.io/brf-tech/runr`.

## 0.4.x → 0.5.0

### Roles and API tokens

0.4.x had no permission checks for single-tenant users. The upgrade gives every existing user a
role (migration `assign_roles_to_existing_users`):

| 0.4.x | 0.5.0 |
| --- | --- |
| Single-tenant: the administrator account (`ADMIN_USERNAME`, else the oldest user) | Owner |
| Single-tenant: every other user | Admin |
| Multi-tenant: tenant `owner` / `admin` | Owner / Admin |
| Multi-tenant: tenant `member` | the custom role Member (what members could do) |
| Multi-tenant: tenant `viewer` | Viewer (read-only) |

Two things 0.4.x stored but did not enforce are enforced now:

- **Tenant viewers** could change and deploy projects in 0.4.x although the role was named
  Viewer. 0.5.0 keeps them read-only. Give a viewer who needs to work on projects the Member or
  Developer role.
- **API token scopes.** Tokens with `*` keep full access (within their user's role). Tokens with
  0.4.x scopes get those scopes as permissions (`projects:read` → `projects.view`, `logs.view`,
  `metrics.view`, `git.pull`, …; the full list is in [permissions.md](permissions.md#upgrade-from-04x)),
  so a token created with `projects:read` reads but no longer deploys. A token created without
  any scope has no permissions; create a new one with the scopes it needs.

Users the previous version created while the upgrade rolled out get the default role from the
bootstrap job (`php artisan rbac:assign-missing`, safe to run again).

### Secrets at rest

The migration `encrypt_secret_columns` encrypts env vars, version snapshots, region kubeconfigs,
registry and Redis passwords, webhook secrets and the other columns listed in
[security.md](security.md#encryption-at-rest) with `APP_KEY`. Values still in plaintext stay
readable; `kubectl -n runr exec deploy/runr-api -- php artisan secrets:encrypt` encrypts anything
written in plaintext later (it prints `Encrypted 0 plaintext value(s)` when there is nothing
left). On large installs the migration job's 300-second deadline applies to the whole migration
run.

Copies outside the database are not rewritten: the git history of each project on the API
volume (commits of 0.4.x contain `.config.json` with the env values), the code ConfigMaps of
deployed versions and the env of running Knative Services keep their plain values until those
versions are deployed again.

### Secret env vars

0.4.x had plain env vars only; they stay plain after the upgrade and keep their values. To turn
one into a secret, move it in one request and deploy:

```sh
curl -X PUT https://runr.example.com/api/projects/<id> -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"config":{"env_vars":{"PLAIN_VAR":"…"},"secret_env_vars":{"API_TOKEN":"…"}}}'
```

The deploy writes the Kubernetes Secret `<project-slug>-secret-env` in the region and the
Knative Service reads the variable from it (`secretKeyRef`); the value no longer appears in the
Service, the version or the API's project resource ([security.md](security.md#secret-environment-variables)).

### White-label

`branding.*` values apply at the upgrade that sets them (no rebuild): product and company name,
logos (URLs, `data:` URIs or ConfigMap / Secret references), colours (one base colour or the full
`300`–`900` scale) and the login texts — [white-label.md](white-label.md). `GET /api/branding`
shows the result.

### Billing (multi-tenant)

This billing was replaced again in 0.5.8 ([0.5.7 → 0.5.8: Billing](#billing)).

0.5.0 calculates what each tenant owes and leaves invoicing to your billing system, which reads
the amounts through the [billing API](billing.md) and receives signed billing events. The upgrade
migration `turn_payment_records_into_charges` turns the 0.4.x payment records into **charges**:

| 0.4.x payment record | 0.5.0 charge |
| --- | --- |
| `invoice_id` (the billing service's invoice) | `external_ref` |
| capacity-deal record / `Plan …` / `Usage …` record / other | `kind` `capacity_deal` / `plan` / `usage` / `manual` |
| `cancelled` | `void` |
| any other status, period over | `closed`: `total` and `amount_due` = the invoiced total, else the record amount |
| any other status, period running | `open` |

The payment fields (Pay link, due date, paid and refunded amounts) are not carried over — your
billing system holds them. The 0.4.x billing service integration is replaced as follows:

| 0.4.x | 0.5.0 |
| --- | --- |
| RunR created invoices at `billing.gatewayUrl` (`POST /billing/invoices`), polled their status and requested refunds | your system reads closed charges (`GET /api/billing/charges?status=closed`, or the `billing.period.closed` event) and invoices them; refunds happen in your system |
| `billing.gatewayUrl`, `billing.gatewayToken` | not used any more; create an API token with `billing.view` or `billing.manage` for your system ([billing.md](billing.md#api-tokens)) |
| `billing.webhookSecret` signed payment-status calls to `POST /api/billing/webhook` | `billing.webhookSecret` signs the billing events RunR sends to `billing.webhookUrl` — same header format (`X-RunR-Signature`, `X-RunR-Timestamp`) |
| `GET/POST/PATCH /api/tenants/{tenant}/payments` | `GET /api/billing/charges?tenant=…`, `POST /api/billing/tenants/{tenant}/charges`, `PATCH /api/billing/charges/{charge}` |
| `GET /api/tenant/payments` (the tenant's invoices) | `GET /api/tenant/billing` (the tenant's charges, credit and purchases) |

Set `billing.webhookUrl` (and keep or set `billing.webhookSecret`) to receive events; without a
URL the events are kept for `GET /api/billing/events`.

### Region Traefik image

The region Traefik runs `ghcr.io/brf-tech/reqs/traefik:v3.7.4`, RunR's mirror of the
`docker.io/traefik` image its pinned chart runs, so its replicas — and a node that schedules one
later — no longer pull from Docker Hub and its anonymous pull limit. `region:reconcile` moves the
Traefik of existing regions that runs the same Traefik version from Docker Hub (a Helm upgrade
of the `traefik` release with its values reused and only the image set; the Traefik pods roll
once, one at a time) and leaves a Traefik of another version alone. To pull it from a registry
of your own, set `knative.traefikImage` (the same Traefik version) before the upgrade.

### Kata Containers

Of RunR's Kata drop-ins only Dragonball's changed: on regions whose Kata install includes
Dragonball, `region:reconcile` writes the new Dragonball drop-in to the nodes (no kata-deploy run,
no restart); regions with the other hypervisors only keep theirs (from 0.4.1, whose guests still
boot systemd, every drop-in is written once). Dragonball guests get 1 vCPU + the pod's CPU limits
from 0.5.0 on ([kata-hypervisors.md](kata-hypervisors.md#guest-size)); a Dragonball function gets
them at its next deploy.

## Testing the upgrade path

`runtime/ci.sh test-helm-upgrade` repeats the upgrade on a Kind cluster (`runtime/ci.sh
test-upgrade` does the same for a Docker Compose install): it installs the chart and images of
`main` (`--from=<ref>`; the API and UI images of that ref's chart values at its `appVersion`,
built from that ref when they are not public) with the chart defaults, creates data through the old API —
the administrator and a second user, the same cluster as a region (registry, Knative Serving,
Traefik, the region agent), a Python function with two env vars, a full-access and a
`projects:read` token — and runs `helm upgrade --reuse-values` (`--values-mode=reset`:
`--reset-then-reuse-values`) to the checkout's chart and images with a test brand
(`Acme Cloud`, logos as `data:` URIs, a primary `300`–`900` scale) while a prober calls the
function every second. It then checks:

- the pre-upgrade migration succeeded, no migration is pending, `/api/version` answers (it reports
  the chart's `appVersion`) and every deployment runs the new images;
- both users sign in; the administrator is Owner, the other user Admin and still lists projects,
  regions and users;
- env vars are encrypted in the database (`eyJpdiI6…`), the plaintext value is in no row, the
  region kubeconfig is encrypted, the API returns the values, `secrets:encrypt` finds nothing;
- the `*` token works; the `projects:read` token reads projects, gets env values hidden and a 403
  on deploy; its stored scopes are translated;
- the function answered every probe during the upgrade and answers afterwards with its env;
- a variable moved to secret plus a new secret land in `<slug>-secret-env`, the Knative Service
  references them and contains no value, the function reads them after the redeploy;
- the region reports Knative Serving ready and its agent reconnected to the new Reverb;
- `/api/branding` returns the brand (names, palette, logos) and serves the logo from its
  `data:` URI;
- nothing of `ghcr.io/runrsh` is left (the image namespace up to 0.5.0): no workload of the
  cluster — control plane and region, Deployments, StatefulSets, DaemonSets, CronJobs, Knative
  Services — references it, no running pod apart from the ones `region:reconcile` keeps running on
  purpose (the sandbox node installers and the queue Redis), and no database row of the central and
  the tenant schemas (a CaaS project and, multi-tenant, a tenant-schema row of a `ghcr.io/runrsh`
  image are created before the upgrade); the region agent runs `ghcr.io/brf-tech/runr/agent`.

`--edition=multi-tenant` installs `edition: multi-tenant` (UI images with the `-mt` suffix) and,
instead of the region and the function, creates a tenant that the platform administrator owns with
a tenant admin, member and viewer. After the upgrade every one of them signs in to the tenant,
`GET /api/members` shows owner / admin / member / viewer, the tenant has the custom role Member and
the member's permissions include `settings.manage`, `sso.manage` and `projects.deploy`, the viewer
lists projects but gets a 403 on `POST /api/projects`, and `secrets:encrypt` finds no plaintext in
the central or the tenant schema.

Measured on a 20-core host with Kind 1.32 (main = 0.4.4 → this release, `--reuse-values`): the
upgrade's migration job took 9 s, the new pods were ready 27 s after it started, the bootstrap
job (agent check, `region:reconcile` with every step up to date, `rbac:assign-missing`) 6 s; the
prober's 38 requests during the upgrade all answered 200.
