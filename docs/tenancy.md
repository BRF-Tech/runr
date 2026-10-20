# Tenancy (multi-tenant installs)

The Tenancy module turns one RunR installation into many **tenants**: separate organizations
that share the control plane and the regions, each with its own members, roles, projects, data,
quotas and settings. It is what the **multi-tenant edition** switches on.

Throughout the documentation, an **organization** is the unit that roles, members, quotas and
settings belong to: the installation itself in a single-tenant install, a tenant in a multi-tenant
install ([Roles and permissions](permissions.md)).

- [What a tenant is](#what-a-tenant-is)
- [When to use tenancy](#when-to-use-tenancy)
- [Editions](#editions)
- [Platform users and Tenant users](#platform-users-and-tenant-users)
- [Platform administrators](#platform-administrators)
- [Enabling tenancy](#enabling-tenancy)
- [Converting a single-organization installation](#converting-a-single-organization-installation)
- [Tenant lifecycle](#tenant-lifecycle)
- [Members, roles and invitations](#members-roles-and-invitations)
- [Selecting a tenant](#selecting-a-tenant)
- [Opening a tenant as platform administrator](#opening-a-tenant-as-platform-administrator)
- [Regions and quotas](#regions-and-quotas)
- [Domain and branding per tenant](#domain-and-branding-per-tenant)
- [Data and backups](#data-and-backups)
- [Tenant schema migrations](#tenant-schema-migrations)
- [Artisan commands](#artisan-commands)
- [API](#api)

## What a tenant is

A tenant has a name, a **slug** (`acme`, used on the sign-in page, in project names and in git
paths) and an id (`GET /api/tenant/current` lists the caller's tenants with their ids). Each
tenant gets:

| | |
|---|---|
| Database | its own PostgreSQL schema `tenant_<tenant id>`; requests in the tenant run with `search_path = tenant_<tenant id>, public` (`PostgreSQLSchemaManagerWithPublic`) |
| Data in that schema | projects, versions, deployments, environment variables and secrets, custom domains, bindings, durable workflows, model services, git deploy settings, supply-chain reports, uptime checks, alerts and the status page, metric snapshots, roles, groups, project access, sign-in providers and SSO group mappings, support tickets, the tenant's settings |
| Code | the git repositories of its projects under `storage/git/<tenant slug>/` on the API volume |
| Members | accounts with a role in the tenant (central table `tenant_users`); one account can belong to several tenants, each tenant keeps a copy of its members in its schema |
| API tokens | a token created in the tenant works in that tenant only ([API tokens](permissions.md#api-tokens)) |
| Regions | the platform regions a platform administrator assigned to it, with a CPU, memory and GPU quota on each, and regions of its own ([Regions and quotas](#regions-and-quotas)) |
| Real-time channels | WebSocket channel names prefixed `tenant.{id}.` ([Real-time channels](security.md#real-time-channels)) |

On the clusters, tenants share each region's project namespace. Project names carry the tenant
slug as a suffix (`checkout-acme`), every workload of a tenant is labelled `runr/tenant: <tenant
slug>`, and Redis queue streams get the same suffix (`<stream>-<tenant slug>`). Sandbox API sandboxes run in a namespace per
organization ([Sandbox API](sandbox-api.md)).

## When to use tenancy

Use the multi-tenant edition when one installation serves several teams or customers on shared
clusters and they need to be kept apart:

- separate members, roles, projects and data per team or customer;
- a CPU, memory and GPU quota per tenant on every region;
- a brand, a base domain and a sign-in configuration (SSO providers, group mapping) per tenant;
- self-service signup where every new account gets an organization of its own
  ([Tenant per signup](white-label.md#23-tenant-per-signup-and-onboarding-multi-tenant));
- with the Billing module, a bill per tenant for the shared platform regions and a bill per project
  inside each tenant, with the tenant's markup ([Billing](billing.md#billing-with-tenancy)).

A single-tenant install is enough when one company or team runs RunR: roles, groups and project
access separate people and projects inside the one organization
([Roles and permissions](permissions.md)), and the AuthProvider module adds SSO without tenancy
([Modules](modules.md#authprovider)).

## Editions

| | Single-tenant | Multi-tenant |
|---|---|---|
| Helm | `edition: single-tenant` (default) | `edition: multi-tenant` |
| Tenancy module (`TENANCY_ENABLED`) | off | on |
| AuthProvider module (`AUTH_PROVIDER_ENABLED`) | off unless `authProvider.enabled` | on |
| UI image | `ui.image.repository:ui.image.tag` | the same with `ui.image.multiTenantSuffix` (default `-mt`) appended to the tag: the published multi-tenant UI, built with the Tenancy and AuthProvider screens ([UI build flags](modules.md#ui-build-flags)) |
| Organizations | the installation | the tenants |
| Database | PostgreSQL | PostgreSQL, one schema per tenant |
| Operated by | Owners and Admins of the installation | platform administrators; each tenant by its Owners and Admins |

Permissions that exist in one edition only: a multi-tenant install has `tenants.residency.manage`,
and leaves `git_providers.manage`, `runtimes.manage`, `signup.manage` and `tickets.manage` to the
platform administrators
([Built-in roles](permissions.md#built-in-roles)).

## Platform users and Tenant users

The sign-in page of a multi-tenant console offers two choices:

- **Platform user** — sign in with the account alone. A platform administrator starts in the
  **central** context (no tenant; the tenant switcher shows *Central*), any other account in the
  first of its tenants by name.
- **Tenant user** — enter the tenant's slug first (checked with `GET /api/tenant/check/{slug}`),
  then sign in. The page shows the tenant's brand and its sign-in providers, only members of the
  tenant (and platform administrators) get in, and the console starts in that tenant — with the
  password and with the tenant's SSO providers alike (the SSO sign-in's one-time code names the
  tenant, [SSO sign-in handoff](security.md#sso-sign-in-handoff)).

Both choices use the same accounts; they decide where the session starts and which brand and
providers the sign-in page shows. The terms appear only in a UI built with the Tenancy screens.

Who does what:

| | Platform administrator (`users.is_admin`) | Tenant Owner / Admin |
|---|---|---|
| Tenants | create, rename, delete every tenant; open any tenant as its administrator ([Opening a tenant as platform administrator](#opening-a-tenant-as-platform-administrator)); manage the members of any tenant | — |
| Regions | platform regions, their mode (managed or infinite), assignment to tenants, quotas | regions of the tenant's own clusters (`regions.manage`) |
| Shared configuration | runtimes and git providers every tenant uses, signup settings, the platform brand, platform prices and the tenants' bills ([Billing](billing.md)) | the tenant's own prices, markup on shared regions, accepting region offers, its projects' bills (`billing.manage`) |
| Members | every account (Settings → Users in the central context), the platform administrator flag | the tenant's members, invitations, roles, groups, project access |
| Sign-in | the platform's providers (Platform user sign-in) | the tenant's providers and SSO group mapping (`sso.manage`) |
| Branding, domain | whether tenants may brand their console (`branding.tenantOverrides`), the platform's base domain | the tenant's brand (`branding.manage`) and its own domain (`settings.manage`) |
| Support tickets | answer every tenant's tickets | open tickets and reply ([Ticket](modules.md#ticket)) |

Platform administrators pass every permission check in every tenant and in the central context
([Platform administrators](permissions.md#platform-administrators)). Owner and Admin differ only
in ownership: only owners grant the Owner role, and the last owner of a tenant can be neither
demoted nor removed. Other members hold what their roles grant.

## Platform administrators

Accounts with `users.is_admin` are the platform administrators: they operate the installation and
every tenant in it, and the support queue of every tenant ([Ticket](modules.md#ticket)).

- The first administrator account is a platform administrator: `admin.username` of a Helm install,
  created by the bootstrap job, and `ADMIN_USERNAME` of a Compose install, created by the migrator
  ([compose/README.md](../compose/README.md#5-start-the-services-and-create-the-administrator)) —
  both while the installation has no platform administrator.
- When an installation that ran single-tenant switches to the multi-tenant edition, its owners and
  admins — the accounts with the Owner or Admin role of the installation — become platform
  administrators: the upgrade's Tenancy migrations promote them when the installation has no
  platform administrator yet, and `tenancy:setup` promotes them as well
  ([Converting a single-organization installation](#converting-a-single-organization-installation)).
- Platform administrators make further accounts platform administrators in Settings → Users
  (central context) or with `POST /api/users/{user}/toggle-admin`. `php artisan tenancy:setup
  --admin=<username or e-mail>` does it from the API container, also when no platform
  administrator is left.

## Enabling tenancy

Choose the edition when you install RunR ([Installation](install.md)). With Helm:

```yaml
# values-multi-tenant.yaml
edition: multi-tenant              # Tenancy + AuthProvider modules, the multi-tenant UI
```

In the multi-tenant edition the chart appends `ui.image.multiTenantSuffix` (default `-mt`) to the
UI tag: with `ui.image.tag: "0.5.9"` it runs `ghcr.io/brf-tech/runr/ui:0.5.9-mt`, with the default
(empty) tag the chart's release, `<appVersion>-mt`, as with the tag `latest`, which has no
multi-tenant variant. A UI image of your own that carries the
modules under its plain tag needs `ui.image.multiTenantSuffix: ""`
([Edition and modules](install.md#edition-and-modules)).

The API reads `TENANCY_ENABLED` and `AUTH_PROVIDER_ENABLED` from the chart; the UI shows the
tenant switcher, the sign-in choices and the Tenants, Users and Resource Usage settings only when it
was built with `VITE_TENANCY_ENABLED=true` ([UI build flags](modules.md#ui-build-flags)). Every
value of the chart: [Helm values](helm-values.md).

What the install does:

1. The migration job creates the central tables of the module (`tenants`, `tenant_users`,
   `tenant_regions`, `tenant_invitations`, …) in the `public` schema. On every later upgrade it
   also applies new tenant migrations to every tenant (`tenancy:migrate`) before the new version
   serves requests.
2. The bootstrap job runs `tenancy:setup --force`, which finds nothing to convert on a fresh
   installation, and creates the administrator account (`admin.username`, `admin.email`) as a
   platform administrator.
3. Sign in as a **Platform user**, open Settings → Tenants and create the first tenant, then
   assign it a region (Tenants → (tenant) → Regions).

Other installs set `TENANCY_ENABLED=true` and `AUTH_PROVIDER_ENABLED=true` for every API
container (api, horizon, scheduler, reverb, migrator) and build the UI with the matching flags
([Modules](modules.md)).

An installation that already runs single-tenant switches the edition the same way and then
converts its data into its first tenant with `tenancy:setup`
([Converting a single-organization installation](#converting-a-single-organization-installation)).

## Converting a single-organization installation

`php artisan tenancy:setup` turns an installation that ran single-tenant into the first tenant of
a multi-tenant one. The `public` schema keeps every row it had; the organization's data is copied
into the new tenant's schema, and nothing is deleted (only an open bill that would not cover a
single day any more goes, [Billing after the conversion](#billing-after-the-conversion)).

### Procedure

1. Back up the database and the API storage volume (`storage/`, with the git repositories)
   ([Data and backups](#data-and-backups)).
2. Switch the installation to the multi-tenant edition:

   ```bash
   helm upgrade runr brf-tech/runr --version <installed version> -n runr --reuse-values \
     --set edition=multi-tenant --set ui.image.multiTenantSuffix=-mt --timeout 20m
   ```

   `--reuse-values` keeps the empty `ui.image.multiTenantSuffix` of an installation set up before
   0.5.8, which would run the single-tenant console: the `--set` gives it the multi-tenant UI
   image (a UI image of your own that carries the modules keeps `""`,
   [Multi-tenant console image](upgrade.md#multi-tenant-console-image)).

   The upgrade runs the Tenancy migrations; the owners and admins of the installation become
   platform administrators ([Platform administrators](#platform-administrators)). Its bootstrap
   Job then converts the installation with the defaults — `php artisan tenancy:setup --force`:
   tenant name `Default`, the slug of the git remote owner — before it looks for an administrator
   (`bootstrap.tenancySetup`, default `true`). The conversion gets up to 8 minutes, hence the longer
   `--timeout`; one that stops (an error, the time limit) continues on the next run. Read what it
   did in the Job's log and go on with step 5:

   ```bash
   kubectl -n runr logs job/runr-bootstrap
   ```

   To see the plan first or choose the tenant's name and slug, add
   `--set bootstrap.tenancySetup=false` to the upgrade and run steps 3 and 4 yourself.

   Docker Compose: `TENANCY_ENABLED=true` and `AUTH_PROVIDER_ENABLED=true` in `.env`, then build and
   deploy again, which builds the UI with the Tenancy screens and runs the Tenancy migrations
   ([compose/README.md](../compose/README.md)); then steps 3 and 4.
3. See what the conversion will do — this changes nothing:

   ```bash
   kubectl -n runr exec deploy/runr-api -- php artisan tenancy:setup --dry-run
   ```

   It lists the tenant to create, every member with the role it gets, the new platform
   administrators, the regions and their quotas, each table with the rows it copies (and the
   columns that stay central, if any), the central rows that get the tenant's id, the API tokens
   bound to it, the git directories that move, the queue-triggered projects with their new stream
   names and the CaaS projects that run an image uploaded as the platform. The tables are read from
   a temporary schema, migrated like the tenant's and dropped again.
4. Convert:

   ```bash
   kubectl -n runr exec deploy/runr-api -- php artisan tenancy:setup --force
   ```

5. Redeploy the queue-triggered projects the command lists and point their producers at the new
   stream names (`<stream>-<tenant slug>`).
6. CaaS projects the command lists as running an image uploaded as the platform keep running and
   redeploying with it. To change such a project's image settings, upload the image in the tenant
   (Settings → Images, [Uploaded images](images.md)) and select it.

With Docker Compose run the commands as `docker compose exec api php artisan tenancy:setup …`.

| Option | Default | |
|---|---|---|
| `--name` | `Default` | name of the tenant |
| `--slug` | the git remote owner (`GIT_REMOTE_OWNER`, `runr`) | the tenant's slug; the default keeps the URLs of existing git remotes (`/git/<owner>/<project>.git`) |
| `--dry-run` | | show the plan, change nothing |
| `--no-promote` | | do not make the installation's owners and admins platform administrators |
| `--admin=<account>` | | also make these accounts (username or e-mail) platform administrators; repeatable |
| `--force` | | run in production without asking |

### What the conversion does

| Step | |
|---|---|
| Schema | creates `tenant_<tenant id>` and runs every tenant migration of every module ([Tenant schema migrations](#tenant-schema-migrations)) |
| Members | every account becomes a member with its role in the installation (built-in or custom); an account without one gets Owner if it is a platform administrator, else the default role. The tenant always gets an owner |
| Accounts | the members' synced copies in the tenant's schema |
| Platform administrators | owners and admins become platform administrators (unless `--no-promote`) |
| Regions | every region is assigned to the tenant with its capacity as quota (at most the organization ceiling) and synced into its schema |
| Data | every table of the tenant's schema receives its rows from the `public` schema, parents before children, with the ids they had: projects, versions, deployments and configuration, secrets, domains, bindings, git deploy settings, workflows, observability, AI models, custom roles, groups, group roles and project access, SSO providers and linked accounts, notifications, support tickets. Identity sequences continue after the copied ids |
| Tenant settings | the default role of new members; platform settings (base domain, registration, …) stay central and apply to the tenant |
| Central rows | sandboxes and warm pools, git installations and repository links, MCP servers, calls, sessions and sign-ins, and public status page lookups get the tenant's id. API tokens work in the tenant, so a promoted administrator's CI token keeps acting in it. Sign-in sessions stay unbound: members land in the tenant, platform administrators in the central context and switch into it. Uploaded images stay platform uploads, which the platform runtimes use |
| Billing | from the conversion day the organization's projects, model services and bindings are metered and billed in the tenant only ([Billing after the conversion](#billing-after-the-conversion)) |
| Git | `storage/git` → `storage/git/<tenant slug>` (the repositories with their history; commit ids stay valid), `storage/git-remotes/_` → `storage/git-remotes/<tenant slug>` |
| Attachments | support ticket attachments move into the tenant's directory, `support-attachments/<tenant id>/` ([Ticket](modules.md#ticket)) |

Projects keep their ids, names, cluster names and URLs; running functions keep running.

### Billing after the conversion

The `public` schema keeps the organization's rows, so without care its projects would be metered
and billed twice — centrally and in the tenant. The conversion makes the tenant the only place, from
the day it runs (the conversion day):

| | |
|---|---|
| Metering | the central metering skips the copied projects and model services; they are metered in the tenant |
| Central bills | the open bills of the copied projects end the day before the conversion day and close with the next run of `billing-close`; a bill that would start on the conversion day is removed. No new central bill is made for them. Usage the copied model services and bindings had before the conversion day stays on the central account bills, later usage is the tenant's |
| Fixed allocations | the central ones end the day before; the tenant has the copies and bills them from the conversion day. The platform bills the tenant for allocations in its regions from the conversion day, like for any tenant ([Billing with tenancy](billing.md#billing-with-tenancy)) |
| Tenant bills | the tenant bills from the conversion day (`bills_from`); the copied open bills are replaced by the tenant's own (with ids of their own), closed bills are copied as history |
| Webhook | the platform's webhook settings and event log stay central and are not copied: set the tenant's own webhook (inside the tenant: Settings → Billing → Webhook) for the bills of its projects. An API token of the installation now works in the tenant and reads the tenant's bills; the closing central bills of the conversion month are read with a platform administrator's token |

This step runs whether billing is on or off; on an installation that never used billing it only
records what was converted. A conversion that an earlier release finished gets this step once, on
the next `tenancy:setup` — the bootstrap Job of the upgrade — with that day as conversion day.

### Running it again

Every step records its completion in the central setting `tenancy_setup`. A conversion that
stopped (an error, a restarted pod) continues where it stopped when the command runs again; a
table is copied in one transaction and rows are inserted with "insert or ignore". A finished
conversion never runs again — it would bring back members, rows and files the tenant has removed
since. On a fresh installation (no projects and no organization roles) and on an installation that
already has tenants the command changes nothing, says so and exits successfully, so the Helm
chart's bootstrap Job runs it on every install and upgrade of the multi-tenant edition
(`bootstrap.tenancySetup`). `--admin` works in every case.

## Tenant lifecycle

| Status | Meaning |
|---|---|
| `active` | normal operation |
| `deleting` | the deletion job is tearing the tenant down; reads keep working, writes answer 409 (switching to another tenant, signing out and the own account stay possible) |
| `delete_failed` | the teardown left something behind; the tenant and its schema are kept, writes keep answering 409, `status_message` says what, and the deletion can be started again |

**Create.** Three ways:

- Settings → Tenants (platform administrators): a name and a slug — letters, digits, `-` and `_`,
  at most 100 characters, unique (`POST /api/tenants`). RunR creates the schema, runs every
  module's tenant migrations ([Tenant schema migrations](#tenant-schema-migrations)) and makes
  the creator the tenant's Owner. Give the customer's account the Owner role
  once it is a member ([Members](#members-roles-and-invitations)).
- `php artisan tenancy:create-tenant "<name>" <slug> --owner-email=<e-mail of an existing
  account>` ([Artisan commands](#artisan-commands)).
- Self-service signup: with **Tenant per signup** on (Helm `signup.tenantAutoCreate`, default on),
  every signup gets a tenant — named from the form's workspace name, or after the account — with
  the new account as its Owner, and the onboarding wizard picks the region
  ([Tenant per signup and onboarding](white-label.md#23-tenant-per-signup-and-onboarding-multi-tenant)).

A new tenant has no platform region until one is assigned (by a platform administrator, or by the
onboarding wizard of a self-service tenant). With the Billing module on, an assignment by a
platform administrator is an offer the tenant accepts first ([Shared regions with
billing](#shared-regions-with-billing)).

**Rename.** `PATCH /api/tenants/{tenant}` changes the name. The slug stays.

**Delete.** Settings → Tenants → Delete (`DELETE /api/tenants/{tenant}`, answers 202) sets the
status to `deleting` and queues `DeleteTenantJob`, which:

1. removes every project with its workloads — Knative Service, CronJob, queue consumer, code
   ConfigMaps, git and build directories, registry images — and the tenant's bindings with their
   data, at once (no retention);
2. on every region the tenant used, removes its domain entries and whatever is still labelled
   `runr/tenant=<tenant slug>` in the project namespace (Knative Services, CronJobs, Deployments,
   ConfigMaps, Jobs, Secrets) and the projects' build Jobs;
3. uninstalls the runr-agent of the tenant's own regions;
4. removes what the tenant runs or stores through central tables: the pods of its sandboxes and
   warm pools, uploads in progress, its uploaded images with their registry manifests, and the
   registry images of its custom runtimes;
5. only when nothing was left behind: copies the tenant's project and account bills into the
   central archive ([Bills of deleted tenants](billing.md#bills-of-deleted-tenants)), then drops
   the tenant's schema, deletes `storage/git/<tenant slug>/`, its support ticket attachments (`support-attachments/<tenant id>/`), its branding
   override and assets, its rows in the central tables — sandboxes, warm pools and sandbox quota,
   custom runtimes, git installations and repository links, MCP servers with their calls and
   sessions, uploaded images and uploads, status page lookups, the settings of its own regions, its
   security policy and notifications — and the tenant with its memberships, quotas and the API
   tokens created in it.

When something is left behind the tenant becomes `delete_failed`; **Retry** runs the deletion
again, **Force delete** (`DELETE /api/tenants/{tenant}?force=1`) drops the tenant regardless of
what is left in the regions, but never without its bills: when they cannot be archived the tenant
stays `delete_failed` (`bills not archived: …`), forced or not. A running deletion is not started twice; one whose worker stopped can be started again after the
job's 30-minute timeout. The console is told through the `tenant.deleted` and
`tenant.delete_failed` events ([WebSocket events](websocket-events.md#13-tenants-tenancy-module)).
The audit log, the platform's bills to the tenant and the usage copied to the central database
for them are kept after the deletion, and so are the bills the tenant made for its projects and
users: platform administrators read them with `GET /api/billing/archived-bills?tenant=<id or slug>`
([Bills of deleted tenants](billing.md#bills-of-deleted-tenants)). The tenant's fixed allocations
end on the day of the deletion, also the platform's copies, so its last bill from the platform ends
there ([Fixed resources](billing.md#what-a-fixed-project-may-use)).

## Members, roles and invitations

Inside a tenant, Settings → Users lists the members and the open invitations.

- **Add** (`members.invite`): enter an e-mail address and a role. An existing account becomes a
  member at once. For any other address RunR e-mails an invitation; its link
  (`<console>/invitations/<token>/accept`, built from `FRONTEND_URL`) is valid for 7 days.
  Accepting it creates the account (name, password of at least 8 characters) or asks for the
  existing account's password, adds the membership and signs the user in. **Resend** issues a new
  link (the previous one stops working), **Cancel** withdraws the invitation. Invitations are sent
  through the installation's mail settings.
- **Role**: any role of the tenant except Owner that the inviting member could grant; without one
  the tenant's default role ([Default role](permissions.md#default-role)).
- **Change a role** (`roles.manage`): built-in or custom roles; only owners grant Owner, nobody
  changes their own role, and the last owner keeps the role.
- **Remove** (`members.remove`): the account stays, its membership, groups and project access in
  the tenant go, and so do the API tokens it created there. Nobody removes themselves or the last
  owner; a member whose role the remover could not grant (an owner, for an admin) stays.
- **Reset two-factor authentication** (`members.update`).

Platform administrators manage the members of every tenant (Settings → Tenants → (tenant)).
Custom roles, groups, project access and SSO group mapping are kept per tenant
([Roles and permissions](permissions.md)). The CLI does the same with `runr members add EMAIL
--role ROLE`, `runr members set-role` and `runr members rm` in the tenant of its token
([CLI: access control](cli.md#access-control-members-roles-groups)).

## Selecting a tenant

### Console

The tenant switcher in the header lists the account's tenants (recent ones first, with a search);
platform administrators see every tenant and **Central**, a tenant they are not a member of marked
*as administrator* ([Opening a tenant as platform administrator](#opening-a-tenant-as-platform-administrator)).
The console sends the selected tenant's id as `X-Tenant-ID` with every request.

### CLI

The CLI works in the organization of its API token: a token created in a tenant works in that
tenant only.

```sh
# Console: select the tenant, then Settings → API Tokens → create a token
echo "$TOKEN" | runr login https://runr.example.com --token-stdin --name acme
runr --context acme projects ls
```

`runr login --with-password` creates its token where the account's requests land without a
tenant header: in the tenant of the account's most recently created or changed membership
(`POST /api/tenant/switch` marks one), for a platform administrator in the central context. Use
one context per tenant (`--name`). Platform administrators address other tenants by their id
where a command offers it: `--tenant` of `runr bindings quota`, `runr models gpu-quota set`,
`runr residency policy` and `runr audit`, the organization argument of `runr sandbox quotas set`
and `reset` ([CLI](cli.md)).

`runr whoami` names the tenant the context works in (`Organization: Acme (acme)`; `organization`
in its JSON output); a platform administrator outside any tenant sees `platform (no
organization)`.

### API

IdentifyTenant picks the tenant of every authenticated request:

1. a token created in a tenant: that tenant (an `X-Tenant-ID` naming another tenant answers 403);
2. the `X-Tenant-ID` header: that tenant, when the account is a member (else 403);
3. otherwise the tenant of the account's most recently created or changed membership.

Platform administrators work in the central context unless their token was created in a tenant or
they send `X-Tenant-ID` (any tenant). Responses carry `Vary: X-Tenant-ID`.

```bash
curl -sS "$API/tenant/current" -H "Authorization: Bearer $TOKEN"
# {"tenant": {"id": "…", "name": "Acme", "slug": "acme", …}, "role": "owner", "mode": "tenant",
#  "impersonating": false,
#  "tenants": [{"id": "…", "name": "Acme", "slug": "acme", "role": "owner", "member": true}]}

curl -sS "$API/projects" -H "Authorization: Bearer $TOKEN" -H "X-Tenant-ID: $TENANT_ID"
```

## Opening a tenant as platform administrator

Platform administrators work inside any tenant, also one they are not a member of: they pick it in
the tenant switcher, or **Open as administrator** in Settings → Tenants. The console then sends the
tenant's `X-Tenant-ID` with every request and shows a bar — "You are viewing &lt;tenant&gt; as
platform administrator" — with a button that stops and returns to the platform.

Opening and leaving are audit-logged (`impersonation.start`, `impersonation.stop`), and every
change made meanwhile is recorded in the tenant with the administrator as impersonator
([Audit log](security.md#audit-log)). Stopping — or switching to Central or to another tenant —
ends it and returns to the central context. While the tenant is being deleted, leaving it stays
possible.

API: `POST /api/impersonate/{tenant}` starts (platform administrators only); `POST
/api/impersonate/stop` with the tenant's `X-Tenant-ID` (or `tenant_id` in the body) ends it.
Nothing is kept on the server between the two: the tenant context comes from `X-Tenant-ID`.
`GET /api/tenant/current` and `POST /api/tenant/switch` report `impersonating` (a platform
administrator in a tenant they are not a member of), and `GET /api/tenant/current` reports
`member` for each tenant in `tenants`.

## Regions and quotas

Platform administrators assign platform regions to tenants in Settings → Tenants → (tenant) →
Regions (`POST /api/tenants/{tenant}/regions` with `region_id`, `cpu_quota_m`, `memory_quota_mb`).
Of the platform regions, a tenant sees and deploys to its assigned ones only.

- **Quotas** are shares of the region capacity: the quotas of all tenants on a region add up to at
  most its capacity (and the organization ceiling `quota.cpu` / `quota.memoryGiB`, when set). `0`
  means no limit for that resource. With an organization ceiling the quota may be left out; it
  then defaults to the ceiling, bounded by what is still free. Admission splits a quota into an
  always-on and a FaaS overcommit budget
  ([Organization quotas](architecture.md#region-capacity-and-admission)).
- **Region mode** (a platform region's setting): `managed` (default) — tenants work within their
  quotas; `infinite` — no CPU, memory or GPU quota applies. Both modes need the assignment: a
  tenant without it does not see the region. Only platform administrators set it: the region
  create, update and setup requests (`POST /api/knative-regions`, `PUT
  /api/knative-regions/{region}`, `POST /api/registry-setup/start`) take `region_mode` from them
  and ignore it from anyone else.
- **GPU quota** per tenant and region: `PUT /api/tenants/{tenant}/regions/{region}/gpu-quota`
  ([GPU quota](ai.md#gpu-quota)). **Binding limits**: `PUT /api/tenants/{tenant}/binding-quotas`
  ([Bindings: quotas](bindings.md#quotas)). **Sandbox quotas**:
  [Sandbox API](sandbox-api.md#quotas-and-usage). **Data residency**:
  [allowed regions and data locations](supply-chain.md#data-residency).
- **Region copies.** A tenant sees an assigned platform region as a copy in its schema — name,
  capacity, overcommit factors, status (active, inactive, maintenance), installation and migration
  state — without the kubeconfig, which stays central. The copy follows the platform region —
  every change, whether made in the console or by the platform's status checks (installation,
  cluster and DNS status): a region in maintenance or inactive refuses the tenant's new projects
  and deploys as it does on the platform.
- **Removing a region** from a tenant tears down the tenant's projects there in the background:
  their workloads are removed, their deployments shut down and their API gateways removed from the
  region; the projects stay, without a region, and the region disappears from the tenant's list.
  Assigning the region again before the cleanup has run keeps everything.
- **Deleting a platform region** removes it from every tenant it was assigned to; their projects
  stay, without a region.
- **Own regions**: members with `regions.manage` (or `sandbox.manage`, `registry.manage`) register
  a cluster of the tenant as a region. It lives in the tenant's schema with its kubeconfig, belongs
  to the tenant (`owner_tenant_id`) and only that tenant sees it. No tenant quota applies to it,
  only the region's capacity, and the organization ceiling (`quota.cpu`, `quota.memoryGiB`) is not
  applied to its project namespace. Platform regions are read-only for tenant members. An own
  region set inactive or to maintenance gets its status block like a platform region, and
  `region:reconcile` keeps that block as the region's status wants it
  ([Regions: status](regions.md#region-status)).

### Shared regions with billing

With the [Billing module](billing.md) on, sharing a platform region with a tenant needs the tenant's
consent, because the tenant pays the platform for what its projects use there:

1. **Offer.** A new assignment (`POST /api/tenants/{tenant}/regions`) is `pending` (`share_status`
   in the tenant's region list). The region is not copied into the tenant yet: the tenant's users
   do not see it and cannot create projects in it. The quotas of the assignment are already set.
2. **Notification.** The tenant's members whose role grants `billing.manage` get an in-app
   notification and an e-mail; the platform's billing webhook gets `region_share.offered`.
3. **Acceptance.** A tenant administrator opens Settings → Billing → Region offers: the region, its
   billing type (`payg` or `fixed`, chosen by the platform), the platform's prices with their
   scheduled changes, and the **markup** — prefilled with the tenant's default markup. They may
   change it and accept (`POST /api/billing/region-offers/{region}/accept`). The region is copied
   into the tenant and active for its users from then on; the platform gets `region_share.accepted`.
4. **Billing.** The platform bills the tenant monthly at its own prices for what the tenant's
   projects use (payg) or hold (approved fixed resources) in the region. The tenant bills its
   projects at the platform price × (1 + markup %) and approves its users' fixed resource requests
   itself.

**Markup.** The tenant's default markup (Settings → Billing → Region offers, or
`POST /api/billing/markups` with `region_id: null`) prefills new offers; each accepted region has its
own markup. A change is a new version with an effective date: the old percentage applies until that
day ([Effective dates](billing.md#effective-dates)).

Assignments made before billing was switched on (the upgrade from 0.5.7 included), and the region a
self-service tenant picks in the onboarding wizard, are active right away. Acceptance is only asked
for while billing is on: with billing off, a new assignment is active at once and offers still
pending become active within five minutes (`tenant-region-shares-activate`), so the tenant's users
can use them. When billing is switched on again they stay active and are billed with the tenant's
default markup until it sets one for the region. Removing the assignment works as for any region.
The tenant's own regions need no offer: the tenant sets their type and prices itself.

Settings → Resource Usage (`GET /api/resource-usage`) shows platform administrators every
region's capacity, the quotas allocated on it and what each tenant uses. The platform regions
available for assignment, with their capacity and the quotas allocated on them, reach platform
administrators only (`assignable_regions` of `GET /api/settings`). Regions themselves:
[Regions](regions.md).

## Domain and branding per tenant

### Organization domain

By default a tenant's functions answer under the platform's base domain (`<project>.<region
name>.<platform domain>`). Members with `settings.manage` give the tenant a domain of its own in
the **Organization domain** card of Settings (inside the tenant), or with `PATCH /api/settings`
`{"base_domain": "apps.acme.example"}` in the tenant; its functions then answer at
`<project>.<region name>.apps.acme.example`. The domain is a host name with at least two labels,
and the platform's own base domain cannot be a tenant's domain.

RunR adds `<region name>.<domain>` to the Knative domain configuration of every platform region
assigned to the tenant, for the workloads labelled `runr/tenant: <tenant slug>`. The tenant's own
regions take `<region name>.<domain>` as their function domain. Point a wildcard DNS record
`*.<region name>.<domain>` at each region's ingress address.

**Changing the domain** — a first one, or another one — moves the tenant's functions the way a
changed platform base domain moves a region ([Regions: changing the base domain](regions.md#changing-the-base-domain)):

1. The functions keep their URLs on the domain they answer on now (the tenant's previous domain,
   or the platform's for a first one) and answer on `<project>.<region name>.<new domain>` too —
   on a platform region through the tenant's own alias proxy in the project namespace
   (`runr-domain-alias-t<hash of the tenant id>`, labelled `runr/tenant`, which answers only for
   the tenant's services), on an own region through the region's alias (`runr-domain-alias`).
2. Once DNS of `<region name>.<new domain>` answers the region's external address, the new domain
   takes over on that region: the Knative domain entry of the new domain is added, the old entry
   removed, the alias removed. The function URLs change to the new domain. RunR checks DNS every
   five minutes, on the platform regions and on the tenant's own.
3. Where DNS cannot be checked from RunR (a proxy in front), complete the move by hand: **Complete
   now** in the Organization domain card, which shows the move and the regions still waiting for
   DNS, or `PATCH /api/settings` `{"complete_domain_migration": true}` in the tenant.

Setting the previous domain back before the move completed rolls it back (the alias goes, nothing
else changes). Clearing the domain returns the functions to the platform domain at once — it
answers already — and removes the tenant's entries. A region whose Knative Serving is not ready
yet gets the current domain when Serving comes up. The platform's own entries
(`<region name>.<platform domain>`) are never touched by a tenant's domain.

Inside a tenant `GET /api/settings` returns `tenant_base_domain` (the tenant's own, `null` without
one), `platform_base_domain`, `base_domain` (the one in effect) and `tenant_domain_migration`
(`null`, or the move in progress: `from` — `null` for the platform's domain —, `to`, `started_at`
and the `regions` still answering on the previous domain too).

### Branding

When the platform allows it (Helm `branding.tenantOverrides`, default on), members with
`branding.manage` set the tenant's own brand in Settings → Organization Branding; the Tenant user
sign-in page and the tenant's console use it
([White-label: precedence](white-label.md#12-precedence)).

## Data and backups

| Where | What |
|---|---|
| `public` schema (central) | accounts, tenants, memberships with the tenant role (`tenant_users`), invitations, region assignments and quotas (`tenant_regions`), binding limits, platform regions with their kubeconfigs, API tokens (with the tenant they were created in), runtimes and runtime builds (a custom runtime with the tenant's id), git providers, brands, signup settings, the platform's prices, bills to tenants and the usage copied for them ([Billing](billing.md)), the audit log, and the rows of tenant data the platform needs across tenants, each with the tenant's id: sandboxes and warm pools, git installations and repository links, MCP servers and sessions, uploaded images (owner: the tenant, or the platform), public status page lookups |
| `tenant_<tenant id>` schemas | everything listed under [What a tenant is](#what-a-tenant-is), plus the tenant's own regions with their kubeconfigs, and with the Billing module its prices, markups, fixed resource requests and its projects' bills |
| API storage volume (`runr-api-storage` in Helm) | `storage/git/<tenant slug>/` per tenant, support ticket attachments (`support-attachments/<tenant id>/<ticket id>/`), uploaded brand assets |

Back up:

- the whole PostgreSQL database — every schema (`pg_dump` of the database without `--schema`
  includes the `public` schema and all `tenant_*` schemas);
- the API storage volume;
- `APP_KEY` (and `APP_PREVIOUS_KEYS`): kubeconfigs, secrets and credentials in every schema are
  encrypted with it ([Encryption at rest](security.md#encryption-at-rest)). A restore needs the
  same key.

## Tenant schema migrations

A tenant's schema is migrated with the Tenancy module's tenant migrations and those other modules
register — the Ticket module's, when it is on. Every path that creates or migrates a tenant uses
the same set: Settings → Tenants, self-service signup, `tenancy:create-tenant`, `tenancy:migrate`
and `tenancy:setup`.

```bash
php artisan tenancy:migrate                                    # pending tenant migrations of every tenant
php artisan tenancy:migrate --tenant=<slug>                    # one tenant
php artisan tenancy:migrate --tenant=<slug> --fresh --force    # empty one tenant's schema and migrate it again
```

The Helm migration job runs `tenancy:migrate` on every upgrade before the new version serves
requests ([Upgrading](upgrade.md)). `--fresh` drops and recreates the selected tenants' schemas
only — the `public` schema and the other tenants stay — and asks for confirmation in production
unless `--force` is given; `--seed` seeds afterwards.

## Artisan commands

Run them in an API pod (`kubectl -n runr exec deploy/runr-api -- php artisan …`) or the API
container. They need `TENANCY_ENABLED=true`.

| Command | Does |
|---|---|
| `tenancy:create-tenant <name> <slug> [--owner-email=<e-mail>]` | creates a tenant with its schema and runs every module's tenant migrations; the account with that e-mail becomes its Owner |
| `tenancy:migrate [--tenant=<slug>] [--fresh] [--seed] [--force]` | applies pending tenant migrations to every tenant or one; `--fresh` recreates the schema of the selected tenants ([Tenant schema migrations](#tenant-schema-migrations)); the Helm migration job runs it on every upgrade |
| `tenancy:setup [--name=] [--slug=] [--dry-run] [--no-promote] [--admin=<account>] [--force]` | converts a single-tenant installation into its first tenant; `--admin` makes accounts platform administrators ([Converting a single-organization installation](#converting-a-single-organization-installation)) |

`region:reconcile` brings the tenants' own regions up to date together with the platform regions
(the cluster steps for active ones, the status block for every one with Knative Serving ready),
and `tokens:prune` (daily) also removes the tokens of tenants that no longer exist or whose user is
no longer a member ([Sign-in sessions and API tokens](security.md#sign-in-sessions-and-api-tokens)).

## API

All under `/api`. Route permissions are in `api/config/route_permissions.php`; *platform* means
platform administrators only.

| Endpoint | Requires | |
|---|---|---|
| `GET /tenant/current` | signed in | current tenant, role, `mode` (`tenant` or `central`), `impersonating`, the account's tenants with `member` (platform administrators: every tenant) |
| `POST /tenant/switch` `{tenant_id}` | signed in; a member of the tenant or a platform administrator | marks the membership as the most recent one; `impersonating` |
| `GET /tenant/check/{slug}` | public | whether a tenant exists (name, slug) |
| `POST /impersonate/{tenant}`, `POST /impersonate/stop` | platform | open a tenant as its administrator, leave it (audit-logged; [Opening a tenant as platform administrator](#opening-a-tenant-as-platform-administrator)) |
| `GET /settings`, `PATCH /settings` `{base_domain, complete_domain_migration}` | signed in; `settings.manage` to change | inside a tenant: `tenant_base_domain`, `platform_base_domain`, `base_domain`, `tenant_domain_migration`; for platform administrators `assignable_regions` ([Organization domain](#organization-domain)) |
| `GET/POST /tenants`, `GET/PATCH/DELETE /tenants/{tenant}` | platform | list (with member and region counts), create, show, rename, delete (`?force=1`) |
| `GET/POST /tenants/{tenant}/regions`, `PATCH/DELETE /tenants/{tenant}/regions/{region}` | platform | region assignments and quotas (`capacity` holds each quota's budgets, `share_status` is `active` or `pending` — an offer the tenant has not accepted, [Shared regions with billing](#shared-regions-with-billing)) |
| `PUT /tenants/{tenant}/regions/{region}/gpu-quota` | platform | GPU quota |
| `GET/PUT /tenants/{tenant}/binding-quotas` | platform | binding limits |
| `GET /resource-usage` | platform | regions, allocated quotas, use per tenant |
| `POST /users/{user}/toggle-admin` | platform | platform administrator flag of an account |
| `GET /tenants/{tenant}/users` | `members.view` | members and open invitations |
| `POST /tenants/{tenant}/users` `{email, role}` | `members.invite` | add an account or invite an address (`action`: `added` / `invited`) |
| `POST /tenants/{tenant}/users/search` `{email}` | `members.invite` | whether an account or an open invitation exists |
| `PATCH /tenants/{tenant}/users/{user}` `{role}` | `roles.manage` | change a member's role |
| `DELETE /tenants/{tenant}/users/{user}` | `members.remove` | remove a member |
| `POST /tenants/{tenant}/users/{user}/reset-2fa` | `members.update` | reset two-factor authentication |
| `POST /tenants/{tenant}/invitations/{invitation}/resend`, `DELETE …/invitations/{invitation}` | `members.invite` | resend, cancel |
| `GET /invitations/{token}`, `POST /invitations/{token}/accept` | public (the token) | preview and accept an invitation |

The member routes work for the tenant the request runs in; platform administrators use them for
any tenant. Prices, region offers, markup and bills: [Billing](billing.md#api-reference).
