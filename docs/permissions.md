# Roles and permissions

RunR authorizes every API request against a single permission catalog. Users get permissions
through **roles**: their own role in the organization, the roles of the **groups** they belong
to, and **project access** — a role on one project only. API tokens carry a subset of their
owner's permissions. Platform administrators operate the installation itself.

The same model runs in both editions:

| | Single-tenant | Multi-tenant |
| --- | --- | --- |
| Organization | the installation | a tenant |
| Member's own role | stored with RunR's role assignments | the tenant membership (`tenant_users.role`) |
| Custom roles, groups, project access, SSO mappings | installation database | the tenant's schema |
| Billing role and permissions | — | available |
| Platform administrator (`users.is_admin`) | every permission | every permission, in every tenant and in the central context |

The catalog and the built-in roles are defined in one file: `api/config/permissions.php`.
What each API route requires is listed in `api/config/route_permissions.php`.

## Built-in roles

Built-in roles are read-only and exist in every organization.

| Role | Key | For | Permissions |
| --- | --- | --- | --- |
| Owner | `owner` | the people responsible for the organization | every permission of the edition (`*`), plus ownership |
| Admin | `admin` | administrators | every permission of the edition (`*`) |
| Developer | `developer` | building, deploying and operating projects | `projects.view`, `projects.create`, `projects.update`, `projects.delete`, `projects.deploy`, `code.edit`, `env.view`, `env.edit`, `domains.manage`, `connections.manage`, `git.pull`, `git.push`, `git.manage`, `previews.manage`, `shell.exec`, `logs.view`, `metrics.view`, `traces.view`, `replay.manage`, `uptime.manage`, `workflows.view`, `workflows.run`, `workflows.manage`, `events.send`, `sandboxes.view`, `sandboxes.create`, `sandboxes.exec`, `security.view`, `regions.view`, `runtimes.use_custom`, `images.view`, `images.upload`, `images.delete`, `models.view`, `models.deploy`, `bindings.view`, `bindings.manage`, `mcp.servers.manage`, `mcp.servers.call`, `members.view`, `tokens.manage`, `tickets.view`, `tickets.create` |
| Viewer | `viewer` | read-only users | `projects.view`, `git.pull`, `logs.view`, `metrics.view`, `traces.view`, `workflows.view`, `sandboxes.view`, `security.view`, `regions.view`, `images.view`, `models.view`, `bindings.view`, `mcp.servers.call`, `members.view`, `tokens.manage`, `tickets.view`, `tickets.create` |
| Billing | `billing` | finance: bills, prices, fixed resource approvals, stopping projects ([Billing](billing.md)) | `billing.view`, `billing.manage`, `billing.approve`, `projects.stop`, `regions.view`, `bindings.view`, `members.view`, `tokens.manage`, `tickets.view`, `tickets.create` |

"Every permission of the edition": a single-tenant install has no `tenants.residency.manage`;
a multi-tenant install has no `git_providers.manage`,
`runtimes.manage`, `signup.manage` and `tickets.manage` (the platform administrators do that work
there). Roles hold the permissions they list plus the ones those include (see the **Includes**
column of the catalog).

Owner and Admin differ only in ownership:

- only owners (and platform administrators) grant or revoke the Owner role;
- the last owner of an organization can be neither demoted nor removed;
- in multi-tenant installs only tenant owners point a new region at their own monitoring
  endpoints (Prometheus / Loki).

## Permission catalog

Permissions with scope **project** can also be granted on single projects (see
[Project access](#project-access)). The **Includes** column lists the permissions that always come
with a permission (added when a role or a token is saved and when it is resolved); they chain: for
example `env.edit` includes `env.view`, which includes `projects.view`. A scope marked
single-tenant or multi-tenant exists in that edition only.

### Projects

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `projects.view` | project | — | see projects, code, versions, deployments and gateway routes |
| `projects.create` | organization | — | create projects, clone them, load demo projects |
| `projects.update` | project | `projects.view` | change description, region, resources, scaling and triggers |
| `projects.delete` | project | `projects.view` | delete projects with their workloads |
| `projects.deploy` | project | `projects.view` | deploy versions, stop and delete deployments |
| `projects.manage_access` | project | `projects.view` | give users and groups a role on the project; transfer the project to another owner |
| `projects.stop` | organization | — | stop a project with a reason (its workloads stop, deploys are refused) and resume it ([Project stop and resume](billing.md#project-stop-and-resume)) |
| `code.edit` | project | `projects.view` | save code and files (creates versions) |

### Git & previews

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `git.pull` | project | `projects.view` | clone and fetch the project's git remote, see the Git tab, deploy history and previews |
| `git.push` | project | `git.pull` | push to the project's git remote (a push to the deploy branch creates a version, and deploys it with auto-deploy on); redeploy the deploy branch head |
| `git.manage` | project | `git.pull` | deploy branch, root directory, auto-deploy and force pushes; connect and disconnect a GitHub, GitLab or Forgejo/Gitea repository |
| `previews.manage` | project | `git.pull` | preview settings (on/off, limit, lifetime, overrides, forks); redeploy and delete previews |
| `git_providers.manage` | organization (single-tenant) | — | Settings → Git providers: the GitHub App, GitLab and Forgejo/Gitea instances; in multi-tenant installs platform administrators configure the providers every tenant shares |

See [Git deploy](git-deploy.md).

### Configuration

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `env.view` | project | `projects.view` | see environment variables (others get `env_vars: null`, `env_vars_hidden: true`) |
| `env.edit` | project | `env.view` | add, change and remove environment variables |
| `secrets.view_values` | project | `env.view` | reveal the values of secret variables |
| `domains.manage` | project | `projects.view` | add, verify and remove custom domains and their TLS |
| `connections.manage` | project | `projects.view` | private connections to managed databases |

### Operations

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `shell.exec` | project | `projects.view` | open a shell in a running deployment |
| `logs.view` | project | `projects.view` | read deployment logs |
| `metrics.view` | project | `projects.view` | read live and historical metrics |
| `traces.view` | project | `projects.view` | search and read the project's traces ([Observability](observability.md#traces-tab-and-api)); the logs of a trace also need `logs.view` |
| `replay.manage` | project | `projects.view` | see, replay and delete captured failed requests ([request replay](observability.md#request-replay)) |
| `uptime.manage` | project | `metrics.view` | create, change, run and delete uptime checks, update incidents; reading them is `metrics.view` |
| `alerts.manage` | organization | — | alert channels (with send test), alert rules, alert events and the delivery log with retry |
| `status_page.manage` | organization | — | the organization's public status page |

### Workflows

[Durable workflows](workflows.md).

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `workflows.view` | project | `projects.view` | see workflow definitions, runs, steps and usage; organization-wide also the tenant's events and usage |
| `workflows.run` | project | `workflows.view` | start, cancel, retry and replay runs |
| `workflows.manage` | project | `workflows.view` | sync / declare definitions, pause and resume workflows, set the endpoint override, rotate and reveal the signing key |
| `events.send` | organization | — | send events (`POST /api/events`) |

### Sandboxes

[Sandbox API](sandbox-api.md): isolated sandboxes for untrusted code.

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `sandboxes.view` | organization | — | list and read sandboxes, their activity log, regions, warm pools, the organization's quota, metrics and usage |
| `sandboxes.create` | organization | `sandboxes.view` | create, delete and extend sandboxes, change their network |
| `sandboxes.exec` | organization | `sandboxes.view` | run commands and code, the terminal, files, background processes, keepalive, pause / resume |
| `sandboxes.manage` | organization | `sandboxes.view` | region sandbox settings (with the region's ownership) and warm pools; platform administrators: quotas of every organization and the Prometheus metrics |

### Security

[Software supply chain](supply-chain.md).

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `security.view` | project | `projects.view` | see SBOMs, vulnerability scans, signatures and policy overrides of the project's images; download SBOMs; start a scan |
| `security.override_policy` | project | `security.view`, `projects.deploy` | deploy a version the supply-chain policy gate blocks, with a recorded reason |

### Infrastructure

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `regions.view` | organization | — | see regions, their components and status |
| `regions.manage` | organization | `regions.view` | create, configure, upgrade, migrate and delete regions; Knative, Redis, workers, capacity overcommit factors, function TLS, demo projects, kubeconfig download |
| `sandbox.manage` | organization | `regions.view` | install and configure the region's sandbox runtimes (gVisor, Kata Containers) and WebAssembly support ([WebAssembly](wasm.md)) |
| `registry.manage` | organization | `regions.view` | set up and remove region registries |
| `regions.data_services.manage` | organization | `regions.view` | install, configure and remove the data services of a region: KV Redis, object store, CloudNativePG ([Bindings](bindings.md#region-setup)) |
| `regions.supply_chain.manage` | organization | `regions.view` | a region's SBOM / scan settings, vulnerability DB, policy gate, signing key and admission policy; the organization's policy gate |
| `regions.observability.manage` | organization | `regions.view` | a region's tracing backend: mode (managed, reuse, external, off), install and uninstall, export headers |
| `runtimes.manage` | organization (single-tenant) | — | register and change platform runtimes, enable built-in ones, switch custom runtimes on; in multi-tenant installs platform administrators manage the runtimes every tenant shares |
| `runtimes.use_custom` | organization | — | register the organization's own runtimes and run projects on them, while the platform allows custom runtimes ([Runtimes](runtimes.md#permissions)) |
| `regions.gpu.manage` | organization | `regions.view` | install and remove GPU support (NVIDIA device plugin / GPU Operator), GPU settings (time-slicing, RuntimeClass, gVisor nvproxy) and the model volume of regions ([AI](ai.md#permissions)); platform regions of a multi-tenant install stay with the platform administrators |

### Images

Container images and WebAssembly modules uploaded into region registries ([Images](images.md)).
Running an uploaded image in a CaaS project, or naming one in a runtime version, needs nothing
beyond creating / changing the project or the runtime.

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `images.view` | organization | — | see the organization's uploaded images, their scans and the storage quota; pick them in the CaaS form and the runtime editor |
| `images.upload` | organization | `images.view` | upload docker save / OCI image-layout tars and `.wasm` modules into a region registry, resume and abort uploads |
| `images.delete` | organization | `images.view` | delete uploaded images and their manifests in the region registry |

### Models

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `models.view` | organization | — | browse the Hugging Face Hub, see models, offline copies, model services, their logs and bindings, use the playground ([AI](ai.md#permissions)) |
| `models.deploy` | organization | `models.view` | deploy, change, stop and delete model services; mirror models into regions and upload model files; bind model services to projects (with `projects.update` on the project) |
| `models.manage` | organization | `models.view` | the organization's Hugging Face token; import models from the model volume or an OCI artifact; delete models and offline copies |

### Data

Bindings are resources of the organization ([Bindings](bindings.md)). Attaching, re-prefixing
and detaching on a project needs `env.edit` on that project as well as `bindings.manage`.

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `bindings.view` | organization | — | see KV, object storage, Postgres and external bindings, their attachments, health and usage |
| `bindings.manage` | organization | `bindings.view` | create, change, attach, detach, rotate, delete and restore bindings |
| `bindings.view_credentials` | organization | `bindings.view` | reveal the connection credentials of a binding (audited) |

### AI agents (MCP)

[AI agents](mcp.md). The tools of the RunR MCP server use the permissions above — an agent can
do what its token can do; approving an OAuth client needs `tokens.manage`.

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `mcp.servers.manage` | project | `projects.view` | serve a project as a hosted MCP server, change its access mode, path and audit flag, rotate its gateway key, stop serving it |
| `mcp.servers.call` | project | `projects.view` | call the project's hosted MCP server through the gateway (token and OAuth access modes) and use the inspector |

### Organization

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `members.view` | organization | — | see members, groups and roles |
| `members.invite` | organization | `members.view` | add users and send invitations (with a role the inviter can grant) |
| `members.update` | organization | `members.view` | edit member profiles, reset two-factor authentication |
| `members.remove` | organization | `members.view` | remove members |
| `roles.manage` | organization | `members.view` | create custom roles, assign roles to members and groups, set the default role |
| `groups.manage` | organization | `members.view` | create groups and manage their members |
| `tokens.manage` | organization | — | create and revoke own API tokens |
| `settings.manage` | organization | — | base domain, registration, demo data; single-tenant: the audit log retention; multi-tenant: the tenant's own domain ([Organization domain](tenancy.md#organization-domain)), complete the onboarding wizard of a self-service tenant |
| `branding.manage` | organization | — | the brand ([White-label](white-label.md#1-branding)) — single-tenant the install's, multi-tenant the tenant's own (the platform brand is the platform administrators') |
| `signup.manage` | organization (single-tenant) | — | self-service signup settings (single-tenant; multi-tenant: platform administrators) |
| `sso.manage` | organization | — | sign-in providers and SSO group mapping |
| `audit.view` | organization | — | see the audit log |
| `audit.export` | organization | `audit.view` | export the audit log |
| `tenants.residency.manage` | organization (multi-tenant) | — | data residency of the organization: allowed regions and data locations, default region ([Data residency](supply-chain.md#data-residency)) |

### Billing

The Billing module ([Billing](billing.md)), both editions. In each organization — the installation,
the platform (no active tenant) or a tenant — these permissions reach that organization's billing.

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `billing.view` | organization | — | bills, usage, the rollup per owner, prices, region billing types, fixed resource requests, billing events and settings (without the webhook secret); in a tenant also region offers, markups and the tenant's bills from the platform |
| `billing.manage` | organization | `billing.view` | prices with effective dates, region billing types, markups, accepting region offers, payment links and paid status, the billing webhook, test events and redelivery |
| `billing.approve` | organization | `billing.view` | approve or reject fixed resource requests — the seller of a fixed region |
| `projects.billing` | project | `projects.view` | the project's own bills and its fixed resource requests (the project's owner has this by ownership) |

The platform's bills to tenants and the 0.5.7 billing records need a platform administrator: the
routes are marked `@platform:billing.view` / `@platform:billing.manage`.

### Support

| Permission | Scope | Includes | Allows |
| --- | --- | --- | --- |
| `tickets.view` | organization | — | see own support tickets |
| `tickets.create` | organization | `tickets.view` | open support tickets and reply |
| `tickets.manage` | organization (single-tenant) | `tickets.view` | answer every ticket (single-tenant; in multi-tenant installs the platform administrators answer tickets) |

Permissions are also Laravel Gate abilities: `$user->can('projects.deploy', $project)`,
`Gate::authorize('audit.export')`, or `->can('audit.view')` on a route.

## Custom roles

Users with `roles.manage` create roles of their own in **Settings → Roles**: a name, a
description and a selection in the permission matrix. Rules:

- a custom role holds only permissions its editor holds — nobody hands out more than they have;
- implied permissions are added automatically;
- deleting a custom role moves its members to the default role and removes the group roles,
  project grants and SSO role mappings that used it.

### Default role

New members get the organization's default role: users added on the Users page without a role,
self-registration, invitations without a role and users created by single sign-on. It is set on
the Roles page (`PUT /api/roles/default`); the installation default is `developer`
(`RBAC_DEFAULT_ROLE`, with the Helm chart set through `extraEnv`). The default role is never
Owner.

## Groups

Groups (Settings → Groups) bundle members. A group can have an organization-wide role — every
member gets it in addition to their own role — and access to single projects. Effective
permissions are the union of all roles. Adding someone to a group requires being able to grant the
group's roles.

Memberships have a source: `manual` (added in the console) or `sso` (kept in sync by SSO group
mapping). Adding a member manually makes an SSO membership permanent.

## Project access

The **Access** tab of a project gives a user or a group a role on that project only. Only the
role's project-scoped permissions apply there. Example: a member with the Viewer role
organization-wide and the Developer role on project *checkout* can deploy *checkout* and only view
everything else.

- Members without organization-wide `projects.view` see only the projects they have access to.
- Whoever creates a project without seeing every project gets the Admin role on it.
- `projects.manage_access` allows granting roles on the project — roles whose project permissions
  the granter holds on that project.
- The Owner role is not used for project access.

## API tokens

Personal API tokens (Settings → API Tokens) carry either full access (`*`) or a selection of
permissions:

- a token can only be created with permissions its owner holds (organization-wide or on some
  project);
- at every request the token is limited to what its owner may do at that moment — changing or
  removing a member's role limits their tokens immediately;
- tokens optionally expire (`expires_at`);
- a token created inside a tenant works in that tenant only;
- a token of a platform administrator with a selection of permissions is limited to them: it
  reaches the routes marked `@platform:<permission>` when it carries that permission (the
  platform's tenant bills of the [billing API](billing.md#api-reference)), never the routes marked
  `@platform`;
- a token with only `billing.view` and `billing.manage` is the way to connect an invoicing system
  to the [billing API](billing.md#api-tokens) of its organization;
- tokens RunR issues to MCP clients through OAuth ([AI agents](mcp.md#oauth-21)) are API tokens
  as well — named `<client> (MCP)`, with the permissions and lifetime approved on the consent
  page, revocable here like any other.

`GET /api/tokens` returns the permissions a new token may carry (`available_abilities`).

## SSO group mapping

Sign-in providers (Settings → Auth Providers: OAuth, OpenID Connect and SAML) can manage groups
and roles:

- **Groups claim / attribute** — the claim (OpenID Connect: of the verified ID token or the userinfo
  response; the other OAuth drivers: of the user data the provider returns) or SAML attribute that
  carries the user's groups. When empty, RunR reads `groups`, the Entra ID group claim
  (`http://schemas.microsoft.com/ws/2008/06/identity/claims/groups`) and `memberOf`.
- **Role for new users** — the role users created just in time by this provider get; empty = the
  organization's default role.
- **Group mappings** — external group → group and/or role, for one provider or every provider.
  Matching is case-insensitive.

On every SSO sign-in:

1. Every mapped group follows the IdP: the member joins (source `sso`) when one of their external
   groups maps to it and leaves when none does. Manual memberships stay.
2. When at least one mapping that applies to the provider (its own or one for every provider)
   sets a role, the IdP manages the member's
   organization-wide role: the matching role with the most permissions, else the provider's role
   for new users, else the organization's default role. Owners keep their role.
3. Just-in-time provisioning (`auto_register`): a new account becomes a member with the role for
   new users (or a mapped role). In multi-tenant installs the account is a platform account that
   joins the tenant; an existing account with the same e-mail joins the tenant instead of being
   created again.

## Platform administrators

`users.is_admin` marks the operators of a multi-tenant installation: tenants, quotas, platform
regions and their region mode, platform prices and the bills to tenants, impersonation and
support across tenants. They pass every permission check (`Gate::before`) in every tenant, unless
they use an API token with a selection of permissions. Routes for them only are marked `@platform`
in the route map; routes marked `@platform:<permission>` (the tenant bills of the billing API) also
accept their tokens that carry the permission. Other users have no permissions in the central context (no
active tenant).

Who becomes one — the first administrator account of a Helm or Compose install, the owners and
admins of an installation switched to the multi-tenant edition, accounts promoted in Settings →
Users — is in [Platform administrators](tenancy.md#platform-administrators). They open any tenant
as its administrator, also one they are not a member of; opening and leaving are audit-logged
(`impersonation.start`, `impersonation.stop`) and the changes made meanwhile name them as
impersonator
([Opening a tenant as platform administrator](tenancy.md#opening-a-tenant-as-platform-administrator)).
In the single-tenant edition, platform administrators and Owners open the
[Horizon dashboard](install.md#horizon-dashboard); in the multi-tenant edition, platform
administrators only.

## Enforcement

- `App\Http\Middleware\AuthorizeApiRoute` (api middleware group) checks every authenticated API
  route against `config/route_permissions.php`, after authentication, tenant identification and
  route model binding. A route with `{project}` is checked for that project. A route behind
  `auth:sanctum` without a rule is refused (403).
- Policies refine checks inside controllers: `ProjectPolicy`, `GatewayPolicy` (gateways belong to
  their creator; routing a project through one needs `projects.update` on the project),
  `RolePolicy`, `GroupPolicy`. Updating a project needs `env.edit` for changed environment
  variables and `projects.update` for every other field.
- `Tests\Feature\Rbac\RouteAuthorizationTest` fails when an API route has no rule, when a rule
  names an unknown permission or a route that no longer exists, and when a `@policy` route's
  controller does not authorize.

### For contributors: adding a route or a permission

Every API route behind `auth:sanctum` needs a rule — the API refuses unmapped routes (403) and
`php artisan test tests/Feature/Rbac/RouteAuthorizationTest.php` fails on them, and on rules
whose route no longer exists. Add the route, then one line to `config/route_permissions.php`:

```php
'GET audit-events'      => 'audit.view',
'POST projects/{project}/rollback' => 'projects.deploy',   // checked for {project}
'GET reports'           => 'projects.view:any',            // org-wide or on some project
'GET things'            => 'regions.manage|registry.manage', // any of them
'POST things'           => ['regions.manage', 'registry.manage'], // all of them
'GET status'            => '@authenticated',               // any signed-in user
'GET tenants/{tenant}'  => '@platform',                    // platform administrators only
'GET billing/tenant-bills' => '@platform:billing.view',    // platform administrators; a token must carry billing.view
'PATCH gateways/{gateway}' => '@policy',                   // the controller authorizes (Policy)
```

or put `->can('audit.view')` on the route. Finer checks inside a controller use the same names:
`Gate::authorize('env.edit', $project)`, `$request->user()->can('audit.export')`.

A new permission:

1. one entry in `config/permissions.php` (`group`, `scope` — `project` when it can be granted on
   single projects —, `implies`, `edition` when it exists in one edition only);
2. the built-in roles that should hold it (Owner and Admin hold everything);
3. a label and a hint in `vue/src/locales/en/rbac.js` and `vue/src/locales/tr/rbac.js`
   (`rbac.perm.<name with _>`, `rbac.permHint.<name with _>`) — `PermissionCatalogTest` checks
   both;
4. the row in this page's catalog, and the mock catalog of the UI tests
   (`vue/e2e/fixtures/data.ts`).

In the console, `usePermissionsStore().can('name', projectId)` hides what the user cannot do; the
API decides either way.

## CLI

`runr members`, `runr roles`, `runr groups`, `runr access` and `runr tokens` administer members,
roles (including `runr roles permissions`, the catalog, and `runr roles default`), groups, project
access and API tokens with the permissions listed below
([CLI: access control](cli.md#access-control-members-roles-groups)).

## API

| Endpoint | Requires | |
| --- | --- | --- |
| `GET /api/auth/permissions` | signed in | effective permissions: organization-wide, per project, token limit |
| `GET /api/permissions` | signed in | the catalog of this edition |
| `GET /api/roles` | signed in | built-in and custom roles, default role, which ones the caller can grant |
| `GET /api/roles/{role}` | signed in | one role with its permissions |
| `POST /api/roles`, `PUT/DELETE /api/roles/{role}` | `roles.manage` | custom roles |
| `PUT /api/roles/default` | `roles.manage` | default role |
| `GET /api/members` | `members.view` | members with role and groups |
| `PUT /api/members/{user}/role` | `roles.manage` | a member's own role |
| `GET /api/groups`, `GET /api/groups/{group}` | `members.view` | groups |
| `POST /api/groups`, `PUT/DELETE /api/groups/{group}`, `POST /api/groups/{group}/members`, `DELETE /api/groups/{group}/members/{user}` | `groups.manage` (+ `roles.manage` for the group role) | groups |
| `GET/POST /api/projects/{project}/access`, `DELETE …/access/{assignment}` | `projects.view` / `projects.manage_access` | project access |
| `GET/POST /api/sso/group-mappings`, `PUT/DELETE /api/sso/group-mappings/{mapping}` | `sso.manage` | SSO group mapping |
| `GET/POST /api/tokens`, `DELETE /api/tokens/{token}` | `tokens.manage` | own API tokens |

A refused request answers `403` with the permission it needs:

```json
{ "message": "You do not have permission to do this (requires projects.deploy).", "permissions": ["projects.deploy"] }
```

## Upgrade from 0.4.x

The upgrade migrations give every existing user and token what the 0.4.x role or scope stood for.
0.4.x did not enforce two of them: a tenant `viewer` could change and deploy projects like a
member, and API token scopes were stored but not checked. 0.5.0 enforces both — give a viewer
who works on projects the Member or Developer role, and a scoped token that has to deploy the
scope that allows it ([upgrade.md](upgrade.md#roles-and-api-tokens)).

| Before | After |
| --- | --- |
| Single-tenant: the administrator account (`ADMIN_USERNAME`, else the oldest user) | Owner |
| Single-tenant: every other user (all users could do everything) | Admin |
| Multi-tenant: tenant `owner` | Owner |
| Multi-tenant: tenant `admin` | Admin |
| Multi-tenant: tenant `member` | the custom role **Member** (key `member`), created in every tenant that has members or open invitations with that role — exactly what members could do: the Developer permissions without `models.deploy`, `mcp.servers.manage` and `mcp.servers.call`, plus `settings.manage` and `sso.manage`. A tenant schema the upgrade has not migrated yet resolves `member` as Developer. Move members to Developer to drop the settings and SSO permissions. |
| Multi-tenant: tenant `viewer` | Viewer (read-only — enforced from 0.5.0 on) |
| `users.is_admin` | platform administrator (unchanged) |
| Users created after the upgrade | the organization's default role (`RBAC_DEFAULT_ROLE`, default `developer`) |
| Users the previous version created while the upgrade rolled out (single-tenant) | the default role, from the Helm bootstrap job (`php artisan rbac:assign-missing`) |
| API tokens created without any scope | no permissions (only the routes that need none: own account, notifications); create a new token |
| API tokens with `*` | unchanged |
| API tokens with 0.4.x scopes | translated: `projects:read` → `projects.view`, `logs.view`, `metrics.view`, `git.pull`; `projects:write` → `projects.view`, `projects.create`, `projects.update`, `projects.delete`, `code.edit`, `env.view`, `env.edit`, `domains.manage`, `git.pull`, `git.push`; `deployments:read` → `projects.view`, `logs.view`, `metrics.view`; `deployments:write` → `projects.view`, `projects.deploy`; `settings:read` → `regions.view`; `settings:write` → `regions.view`, `regions.manage`, `settings.manage` |

Clients that still send 0.4.x scope names when creating a token get the same translation.
Migrations: `2026_10_10_000001_create_rbac_tables`, `2026_10_10_000002_assign_roles_to_existing_users`,
`2026_10_10_000003_translate_legacy_token_abilities`, Tenancy `2026_10_10_100001_widen_tenant_user_role`,
AuthProvider `2026_10_10_200001_add_sso_group_mapping`, tenant migrations `0040_create_rbac_tables`,
`0041_create_legacy_member_role`, `0042_add_sso_group_mapping`. The Helm pre-upgrade job runs all
of them (including `tenancy:migrate`) before the new version serves requests.
