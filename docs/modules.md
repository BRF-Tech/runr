# Modules and feature toggles

Most of RunR is always there. Four parts are **modules** that an installation switches on or off
as a whole — Tenancy, AuthProvider, Ticket and Billing — and several features of the core have a
**toggle** of their own. This page lists all of them and describes the modules.

- [Modules](#modules)
- [UI build flags](#ui-build-flags)
- [Toggle reference](#toggle-reference)
- [Tenancy](#tenancy)
- [AuthProvider](#authprovider)
- [Ticket](#ticket)
- [Billing](#billing)

## Modules

A module lives in `api/Modules/<Module>` (nwidart/laravel-modules) with its routes, migrations,
jobs and console commands, and in `vue/src/modules/<module>` with its screens. The core does not
depend on it: a module replaces core classes through container bindings and adds its screens
through the console's module registry ([Architecture](architecture.md#modules)).

| Module | Adds | Switched by |
|---|---|---|
| Tenancy | tenants with their own schema, members, quotas, domain and brand; the multi-tenant edition ([Tenancy](tenancy.md)) | `TENANCY_ENABLED` |
| AuthProvider | sign-in with Google, GitHub, Microsoft, GitLab, OpenID Connect and SAML 2.0 identity providers, linked accounts, SSO group mapping ([AuthProvider](#authprovider)) | `AUTH_PROVIDER_ENABLED` |
| Ticket | support tickets between users and the operator | `TICKET_ENABLED` |
| Billing | a monthly bill per project (payer: the owner) and, with tenancy, per tenant, from region prices; fixed resource requests with approval; payment links and paid status written by an external invoicing system ([Billing](#billing)) | `BILLING_ENABLED` |

At every start the API container (`api/docker/entrypoint.sh`) — and the Helm bootstrap job —
runs `php artisan module:enable <Module>` or `module:disable <Module>` from these variables; the
result is stored in `api/modules_statuses.json`. A disabled module registers no routes, jobs or
commands. Set a module's variable to the same value for every API container: api, horizon,
scheduler, reverb and the migration job (the Helm chart does this through its ConfigMap).

Migrations: AuthProvider's, Ticket's and Billing's central tables are created at every start
whether the module is on or not; Tenancy's central tables and the tenant schemas are migrated only
with `TENANCY_ENABLED=true`. The tenant schemas get the Billing tables while the module is on
(`tenancy:migrate`, run by the migration job of every upgrade).

## UI build flags

The console decides at **build time** which module screens it contains. A module's screens are
in the build only when the UI was built with its flag set to `true`:

| Module | UI build flag | Screens |
|---|---|---|
| Tenancy | `VITE_TENANCY_ENABLED` | tenant switcher, Platform user / Tenant user sign-in, Settings → Tenants, Users, Resource Usage, the Organization domain card, the bar of **Open as administrator**, invitation page |
| AuthProvider | `VITE_AUTH_PROVIDER_ENABLED` | Settings → Auth Providers with SSO group mapping, Linked accounts in User Settings, the page that completes an SSO sign-in (`/oauth/callback`) |
| Ticket | `VITE_TICKET_ENABLED` | Settings → Support Tickets |
| Billing | `VITE_BILLING_ENABLED` | Settings → Billing and My bills, the project's Billing tab, the billing type in the region wizard and the region's Billing tab, region offers and markup of a tenant |

The screens of the core features (sandboxes, MCP, git deploy, supply chain, observability,
bindings, images, workflows, white-label, roles, audit log) are always part of the build. A module
that is on in the API but missing from the UI build has no screens in the console; its API works
as usual.

The Ticket and Billing screens are also hidden **at run time** when the API reports the module off:
the console reads `features.tickets` and `features.billing` of `GET /api/branding` at start. The
published UI images (both editions) are therefore built with `VITE_TICKET_ENABLED=true` and
`VITE_BILLING_ENABLED=true`, and one image serves installations with and without these modules.

Build the UI image with the flags of your modules (`vue/Dockerfile`):

```bash
docker build -t registry.example.com/runr/ui:0.5.8-mt \
  --build-arg VITE_WS_PROXY=true \
  --build-arg VITE_REVERB_KEY=runr-key \
  --build-arg VITE_TENANCY_ENABLED=true \
  --build-arg VITE_AUTH_PROVIDER_ENABLED=true \
  --build-arg VITE_TICKET_ENABLED=true \
  --build-arg VITE_BILLING_ENABLED=true \
  vue/
```

`VITE_REVERB_KEY` must equal the API's `REVERB_APP_KEY` (`runr-key` in the Helm chart). With
Helm, `ui.image.repository` and `ui.image.tag` select the UI image. `edition: multi-tenant` appends
`ui.image.multiTenantSuffix` (default `-mt`) to the tag and runs the published multi-tenant UI,
`<version>-mt`, built with the Tenancy and AuthProvider screens; with
`repository: registry.example.com/runr/ui` and `tag: "0.5.8"` it runs the image built above. A
multi-tenant UI of your own under its plain tag needs `multiTenantSuffix: ""`. In the
single-tenant edition the tag is used as it is — with `authProvider.enabled: true` or the Ticket
screens, point `ui.image.tag` at a build with those flags
([Edition and modules](install.md#edition-and-modules)). Docker Compose builds and installs:
[Installation](install.md).

## Toggle reference

| Module or feature | API environment | Helm value | UI build flag | Default | Documentation |
|---|---|---|---|---|---|
| Tenancy module | `TENANCY_ENABLED` | `edition: multi-tenant`, or `tenancy.enabled` | `VITE_TENANCY_ENABLED` | off | [Tenancy](tenancy.md) |
| AuthProvider module | `AUTH_PROVIDER_ENABLED` | `edition: multi-tenant`, or `authProvider.enabled` | `VITE_AUTH_PROVIDER_ENABLED` | off | [AuthProvider](#authprovider) |
| Ticket module | `TICKET_ENABLED` | `ticket.enabled` | `VITE_TICKET_ENABLED` | on in the API; screens only in a UI built with the flag, hidden while the API reports it off | [Ticket](#ticket) |
| Billing module | `BILLING_ENABLED` | `billing.enabled` | `VITE_BILLING_ENABLED` (on in the published images) | off; screens shown while the API reports it on | [Billing](billing.md) |
| Sandbox API | `SANDBOX_API_ENABLED` | `sandboxApi.enabled` | — | on | [Sandbox API](sandbox-api.md#configuration) |
| MCP server (`/mcp`) | `MCP_ENABLED` | set with `extraEnv` | — | on | [AI agents](mcp.md#configuration) |
| OAuth authorization server for MCP clients (`/oauth2/*`) | `MCP_OAUTH_ENABLED` | set with `extraEnv` | — | on | [OAuth 2.1](mcp.md#oauth-21) |
| Git deploy (project git remotes, provider webhooks, previews) | `GIT_DEPLOY_ENABLED` | `gitDeploy.enabled` | — | on | [Git deploy](git-deploy.md#configuration-and-helm-values) |
| Supply chain (SBOM, scan, signing after builds) | `RUNR_SUPPLY_CHAIN_ENABLED` | `supplyChain.enabled` | — | on | [Supply chain](supply-chain.md#configuration) |
| Audit log recording | `AUDIT_ENABLED` | set with `extraEnv` | — | on | [Audit log](security.md#audit-log) |
| SIEM forwarding of audit events | `AUDIT_FORWARD_ENABLED` | `audit.siemForwarding` | — | off | [SIEM forwarding](security.md#siem-forwarding) |
| Public status pages | `OBS_STATUS_PAGE_ENABLED` | `observability.statusPage.enabled` | — | on | [Status page](observability.md#status-page) |
| Self-service registration | setting `registration_enabled` | — (Settings → Platform or Signup) | — | on | [Self-service signup](white-label.md#2-self-service-signup) |
| Custom runtimes of organizations | setting `custom_runtimes_enabled` | — (Settings → Platform) | — | off | [Runtimes](runtimes.md#permissions) |

"Set with `extraEnv`": the chart has no value of its own; add the variable to `extraEnv`, which
puts it into the ConfigMap every API container and the hook jobs read:

```yaml
extraEnv:
  MCP_ENABLED: "false"
  AUDIT_ENABLED: "false"
```

Every other value of the chart: [Helm values](helm-values.md).

## Tenancy

Tenants — separate organizations on one installation, each with its own PostgreSQL schema,
members, roles, quotas, domain and brand. The multi-tenant edition (`edition: multi-tenant`)
switches it on together with AuthProvider; `php artisan tenancy:setup` converts a single-tenant
installation into its first tenant. Everything about it: [Tenancy](tenancy.md).

## AuthProvider

Sign-in through external identity providers, next to the password sign-in: Google, GitHub,
Microsoft and GitLab over OAuth 2.0 (drivers `google`, `github`, `microsoft`, `gitlab`; Laravel
Socialite), any OpenID Connect identity provider (driver `oidc`) and SAML 2.0 identity providers
(driver `saml`; RunR is the service provider, onelogin/php-saml). Users link further external
accounts to their account themselves ([Linked accounts](#linked-accounts)).

### Enabling it

- **Single-tenant**: Helm `authProvider.enabled: true` (or `AUTH_PROVIDER_ENABLED=true` for every
  API container) and a UI built with `VITE_AUTH_PROVIDER_ENABLED=true`. Members with `sso.manage`
  (Owner and Admin) add providers in Settings → Auth Providers.
- **Multi-tenant**: on with the edition. Every organization has its own providers:
  - the platform's providers — Settings → Auth Providers in the central context (platform
    administrators) — appear on the **Platform user** sign-in;
  - a tenant's providers — Settings → Auth Providers inside the tenant (`sso.manage`, Owner and
    Admin) — appear on the **Tenant user** sign-in page of that tenant's slug and are stored in the
    tenant's schema.

The sign-in page lists the enabled providers of the organization
(`GET /api/auth-providers/discover`, `?tenant=<slug>` for a tenant).

### Provider fields

Every provider:

| Field | |
|---|---|
| Display Name | the label on the sign-in page |
| Order | position on the sign-in page (`display_order`, lower first) |
| Enabled | only enabled providers are offered and accepted |
| Auto Register | create an account for a person who has none yet ([At sign-in](#at-sign-in)) |
| Groups claim / attribute | the claim of the provider's user data, or the SAML attribute, that carries the user's groups ([SSO group mapping](permissions.md#sso-group-mapping)) |
| Role for new users | the role of accounts this provider creates; empty: the organization's default role. Any role but Owner that you can grant |

Per driver:

| Driver | What you enter | Scopes |
|---|---|---|
| Google | Client ID, Client Secret of an OAuth client (type *Web application*); optional **Hosted Domain** — only accounts of this Google Workspace domain sign in: RunR asks Google for the domain (`hd`) and refuses an account whose `hd` claim is another domain or missing | `openid, email, profile` |
| GitHub | Client ID, Client Secret of an OAuth App | `user:email` |
| Microsoft | Client ID (application ID), Client Secret of an app registration; **Tenant ID** — the Microsoft Entra directory: `common` (default), `organizations` or a directory ID | `openid, email, profile` |
| GitLab | Client ID (application ID), Client Secret; **Instance URL** of a self-managed GitLab (empty: gitlab.com) | `read_user` |
| OpenID Connect | Client ID, Client Secret of a confidential client with the authorization code flow; **Discovery URL** — `https://<issuer>/.well-known/openid-configuration` (the issuer URL works too); optional **Issuer** — the discovery document must name this issuer ([OpenID Connect](#openid-connect)) | `openid, email, profile` |
| SAML 2.0 | **IdP Entity ID**; **IdP SSO URL** (HTTP-Redirect binding); **IdP Signing Certificate** (PEM, or the base64 body from the IdP metadata); optional **Email Attribute** and **Name Attribute**; optional **Sign AuthnRequests** with an SP certificate and its unencrypted PEM private key | — |

The form suggests the scopes above when you pick a driver; configured scopes add to the driver's
own (`openid` is always requested from an OpenID Connect provider). The client secret and the SP
private key are write-only: they are stored encrypted, the API answers `has_client_secret`,
`client_secret_hint` and `has_saml_sp_private_key` instead, and an empty field on edit keeps the
stored value. The driver of a saved provider does not change.

Several providers of one driver can be enabled — two OpenID Connect identity providers, a
gitlab.com and a self-managed GitLab, two Google clients. The sign-in page shows a button for each,
and a sign-in uses the provider whose button was chosen from the redirect to the callback
(`?provider=<id>`; without it, the driver's first enabled provider by Order, then name).

SAML attributes, when the fields are empty: the e-mail comes from `email`, `mail`, the
`…/claims/emailaddress` claim, `urn:oid:0.9.2342.19200300.100.1.3` or the NameID when it is an
e-mail address; the name from `displayName`, `name`, `cn`, the `…/claims/displayname` claim,
`urn:oid:2.16.840.1.113730.3.1.241` or given name + surname. `NameID` as the attribute name uses
the NameID.

### OpenID Connect

RunR reads the identity provider's discovery document (cached for an hour) and signs users in with
the authorization code flow: PKCE (`S256`), `state` and `nonce`. The client authenticates at the
token endpoint with `client_secret_post` when the discovery document offers it, else with
`client_secret_basic`.

The ID token is verified before RunR uses anything in it:

- its signature, with the keys the identity provider publishes (`jwks_uri`, cached for five
  minutes; a token signed with a key ID RunR does not know yet makes it fetch the keys again) —
  RS256/384/512, ES256/384, EdDSA, or HS256/384/512 with the client secret;
- `iss` is the discovery document's issuer, `aud` contains the client ID, and `azp` is the client
  ID when the token carries one or several audiences;
- `exp` (required), `iat` and `nbf`, with 60 seconds of clock skew;
- `nonce` is the one of this sign-in.

The claims of the userinfo endpoint (when the discovery document names one; it must answer for
the same `sub`) complete the ID token's. From them RunR takes the e-mail address (`email`), the
name (`name`, else `given_name` + `family_name`, else `preferred_username`), the avatar (`picture`)
and the groups for [SSO group mapping](permissions.md#sso-group-mapping) (**Groups claim /
attribute**: any claim of the ID token or the userinfo response). An external user is identified by
`sub` per provider.

For a tenant's provider the discovery document, keys, token and userinfo endpoints must be on
public addresses: for a tenant RunR calls no loopback, private, link-local or cluster-internal host
and follows no redirects.

### URLs for the identity provider

**OAuth and OpenID Connect (Google, GitHub, Microsoft, GitLab, OpenID Connect)** — register this
redirect (callback) URL with the OAuth client or at the identity provider; the provider form shows
it as **Redirect URL**:

```text
https://<console host>/oauth/<driver>/callback        # google, github, microsoft, gitlab, oidc
```

It is built from the host the console is opened at, and it is the same for every provider of the
driver, the platform and every tenant: the encrypted, five-minute `state` parameter names the
sign-in, whose organization, provider, PKCE verifier and nonce RunR keeps on the server — each state
completes one sign-in. After the sign-in RunR redirects the browser to
`<FRONTEND_URL>/oauth/callback?code=<one-time code>`, which the console trades for the session
([SSO sign-in handoff](security.md#sso-sign-in-handoff)); a sign-in from a tenant's sign-in page
starts the console in that tenant.

**SAML 2.0** — once the provider is saved, its form shows the service provider details (the API
returns them as `saml_sp`):

| | URL |
|---|---|
| SP Entity ID (Audience) | `<base>/api/saml/<provider id>/metadata` |
| ACS URL (HTTP-POST) | `<base>/api/saml/<provider id>/acs` |
| SP Metadata URL | `<base>/api/saml/<provider id>/metadata` |
| Login URL | `<base>/api/saml/<provider id>/login` |

`<base>` is `SAML_SP_BASE_URL` (Helm: `extraEnv`), else `APP_URL` (Helm: `https://<domain>`). For a
tenant's provider every URL carries `?tenant=<tenant slug>`. Give the IdP the metadata URL or the
entity ID and ACS URL. The IdP must send a NameID and sign the assertion with the configured
certificate; requests are signed with RSA-SHA256 when **Sign AuthnRequests** is on.

Sign-in is SP-initiated: the provider's button on the sign-in page (or the login URL) sends the
browser to the IdP with an AuthnRequest, and the ACS accepts only a response to an AuthnRequest
RunR issued for that provider in the last 10 minutes, once, addressed to its ACS and audience and
within its validity window.

### At sign-in

For every OAuth, OpenID Connect and SAML sign-in RunR resolves the account in this order:

1. an account already linked to this external user: that account. Google, GitHub and Microsoft
   users are identified across the provider; OpenID Connect and SAML users per provider, GitLab
   users per self-managed instance (users of gitlab.com across it);
2. an account with the e-mail address the provider returns: RunR links it and signs it in (a
   provider that returns no e-mail address is refused, and so is an address the provider marks as
   not verified, `email_verified: false`);
3. with **Auto Register**: a new account (username from the address, a random password) that
   becomes a member with the provider's role for new users or the organization's default role. In
   a multi-tenant install the account is a platform account that joins the tenant, and an existing
   account with the same e-mail joins the tenant instead of being created again;
4. otherwise the sign-in is refused.

Then SSO group mapping updates the member's groups and role
([SSO group mapping](permissions.md#sso-group-mapping)), and RunR creates the sign-in session.
OAuth, OpenID Connect and SAML sign-ins are recorded in the audit log as `auth.sso.login` with
their result. A sign-in the provider does not complete (cancelled, an invalid code or ID token, a
Google account outside the hosted domain) returns to the sign-in page with the reason.

### Linked accounts

**User Settings → Linked accounts** lists the external accounts a user signs in with and offers
the organization's enabled OAuth and OpenID Connect providers: **Link <provider>** opens the
provider's sign-in, which returns to User Settings, where the console confirms the link. Only
the user who started the link can confirm it — in the same organization, within five minutes — so
a link URL that reaches someone else links nothing. The external account may have another e-mail
address than the user; one already linked to another user is refused. A SAML identity is linked by
signing in with it.

**Unlink** removes a link; a later sign-in with that external account links it again when its
e-mail address is the user's. Links and unlinks are audited (`social_account.link`,
`social_account.unlink`).

### API

| Endpoint | Requires | |
|---|---|---|
| `GET /api/auth-providers`, `POST /api/auth-providers`, `PUT/DELETE /api/auth-providers/{id}`, `PATCH /api/auth-providers/{id}/toggle` | `sso.manage` | the organization's providers |
| `GET/POST /api/sso/group-mappings`, `PUT/DELETE /api/sso/group-mappings/{mapping}` | `sso.manage` | SSO group mapping |
| `GET /api/social-accounts`, `DELETE /api/social-accounts/{id}` | signed in | own linked accounts (OpenID Connect and SAML accounts name their provider: `auth_provider_id`, `provider_name`) |
| `GET /api/social-accounts/providers` | signed in | providers the user can link |
| `GET /api/oauth/{driver}/link` | signed in | `{redirect_url}` of a link to the signed-in user (`?provider=<id>`) |
| `POST /api/oauth/link/complete` | signed in | confirms a link with the `code` the provider's callback hands to User Settings (`?sso_link=<code>`): `201` linked, `200` already linked to this user, `409` linked to another user, `422` unknown, used or expired code |
| `GET /api/auth-providers/discover` | public | enabled providers (`?tenant=<slug>`; an unknown tenant has none) |
| `GET /api/oauth/{driver}/redirect` | public | `{redirect_url}` of the provider (`?provider=<id>`, `?tenant=<slug>`); `404` for an unknown, disabled or other organization's provider |
| `GET /oauth/{driver}/callback` | public (the provider's redirect) | completes the OAuth / OpenID Connect sign-in or link |
| `POST /api/oauth/exchange` | public, one-time code | the session token of an SSO sign-in (multi-tenant: also `tenants` and `active_tenant`, the tenant whose sign-in page it started from) |
| `GET /api/saml/{id}/metadata`, `GET /api/saml/{id}/login`, `POST /api/saml/{id}/acs` | public | SAML service provider (`?tenant=<slug>`); `login` answers `{redirect_url}` to JSON requests and a redirect to browsers |

## Ticket

Support tickets between the people who use RunR and the people who run it: a ticket has a
subject, a description, a category (`faas`, `xaas`, `caas`, `deployment`, `quota`, `region`,
`account`, `other`), a priority (`low`, `medium`, `high`, `critical`), a status (`open`,
`in_progress`, `resolved`, `closed`) and a conversation of replies with attachments, plus internal
notes, an assignee and tags for the support staff.

### Enabling it

The API enables the module unless `TICKET_ENABLED=false` (Helm: `ticket.enabled: false`). The
console shows it as Settings → **Support Tickets** when the UI was built with
`VITE_TICKET_ENABLED=true` and the API reports the module on (`features.tickets` of
`GET /api/branding`; [UI build flags](#ui-build-flags)).

### Who does what

| | Single-tenant | Multi-tenant |
|---|---|---|
| See tickets (`tickets.view`) | own tickets | every ticket of the tenant |
| Open tickets and reply (`tickets.create`) | own tickets | every ticket of the tenant |
| Support staff: answer, change the status, assign, tag, write internal notes | members whose role holds `tickets.manage` (Owner, Admin and custom roles that grant it) | platform administrators, for every tenant |
| Notified of new tickets | the support staff | the support staff |

Every built-in role holds `tickets.view` and `tickets.create`
([Roles and permissions](permissions.md#support)). Only the support staff change a ticket's
status; the author gets a notification when it changes.

### Using it

- **Users** open Settings → Support Tickets → *New ticket*: category, priority, subject,
  description and up to three attachments. While they type the subject, open tickets with a
  similar subject are suggested. A ticket's conversation shows the staff's replies and whether
  the other side has read the latest message; users reply with text and attachments.
- **Support staff** get the staff view in the same tab: every ticket, with statistics per status,
  searchable by subject. In a multi-tenant install a platform administrator outside any tenant
  sees the tickets of every tenant, with the tenant of each ticket, and inside a tenant that
  tenant's tickets. A ticket shows the conversation and the internal notes; staff change its
  status, reply as **Support Team**, write an internal note that its author never sees, assign the
  ticket and edit its tags ([Assignment and tags](#assignment-and-tags)).
- **Support staff through the API** (`/api/admin/support-tickets`): list with filters (`status`,
  `category`, `assigned_to`, `search`), statistics, status, assignment, tags, replies and internal
  notes (`is_internal: true`). In the central context of a multi-tenant install a ticket is
  addressed with `?tenant_id=<tenant id>`.

Limits: subject up to 255 characters, description and replies up to 5,000; up to 3 attachments per
message, JPEG, PNG, GIF, WebP or PDF, 5 MB each; 10 new tickets and 30 replies per minute. Tickets
and replies are stored in the organization's database (a tenant's schema in a multi-tenant
install), attachments on the API volume ([Attachments](#attachments)).

Updates arrive in real time on the ticket channels
([WebSocket events](websocket-events.md#other-channels), who may subscribe:
[Real-time channels](security.md#real-time-channels)); status changes, assignments, tag changes
and staff replies are audited ([Audited events](security.md#audited-events)).

### Assignment and tags

In the staff view a ticket's header has an **Assign to** list and the ticket's **tags**:

- **Assign to** offers the support staff (`GET /api/admin/support-tickets/staff`: the members
  holding `tickets.manage`, or the platform administrators in a multi-tenant install); the empty
  choice unassigns. API: `PATCH /api/admin/support-tickets/{id}/assign` with
  `{"assigned_to": <user id> | null}`. The staff list filters by assignee with
  `GET /api/admin/support-tickets?assigned_to=<user id>`.
- **Tags**: up to 10 per ticket, 30 characters each; type a tag and press Enter, click × to remove
  one. API: `PATCH /api/admin/support-tickets/{id}/tags` with `{"tags": ["billing"]}` (`null`
  clears them).

Both are recorded in the audit log (`ticket.assign`, `ticket.tags.update`).

### Attachments

Files are stored on the API's default filesystem disk (`FILESYSTEM_DISK`, `storage/app/private`
for `local`, on the API volume); the type is checked on the content:

| Install | Directory |
|---|---|
| single-tenant | `support-attachments/<ticket id>/` |
| multi-tenant | `support-attachments/<tenant id>/<ticket id>/` |

A download (`GET /api/support-tickets/{id}/attachments/{filename}`, staff:
`GET /api/admin/support-tickets/{id}/attachments/{filename}`) serves only a file recorded on that
ticket — the ticket's own files and its replies' — of the organization the request runs in. The
files of internal notes are served to support staff only. Any other name answers 404.

Upgrading a multi-tenant install moves each tenant's files that earlier releases stored in the
shared `support-attachments/<ticket id>/` into the tenant's directory (a tenant migration of the
Ticket module, run by `php artisan tenancy:migrate`); only the files recorded on the tenant's own
tickets and replies move. Converting a single-tenant install with `php artisan tenancy:setup`
moves its files into the new tenant's directory the same way
([Converting a single-organization installation](tenancy.md#converting-a-single-organization-installation)).
Deleting a tenant deletes its directory.

### API

| Endpoint | Requires | |
|---|---|---|
| `GET /api/support-tickets` (`status`, `category`), `GET /api/support-tickets/stats` | `tickets.view` | tickets (own, or the tenant's) and counts per status |
| `GET /api/support-tickets/suggestions?subject=` | `tickets.view` | open tickets with a similar subject |
| `POST /api/support-tickets` (multipart: `subject`, `description`, `category`, `priority`, `attachments[]`) | `tickets.create` | open a ticket |
| `GET /api/support-tickets/{id}`, `POST /api/support-tickets/{id}/read` | `tickets.view` | a ticket without internal notes; mark it read |
| `POST /api/support-tickets/{id}/replies` (`message`, `attachments[]`) | `tickets.create` | reply |
| `GET /api/support-tickets/{id}/attachments/{filename}` | `tickets.view` | download an attachment of the ticket or its replies |
| `GET /api/admin/support-tickets`, `GET /api/admin/support-tickets/stats`, `GET /api/admin/support-tickets/{id}` | `tickets.manage` (multi-tenant: platform administrators) | every ticket, with internal notes |
| `GET /api/admin/support-tickets/staff` | as above | the support staff, the assignee choices (`id`, `name`, `email`) |
| `PATCH /api/admin/support-tickets/{id}/status` `{status}`, `…/assign` `{assigned_to}`, `…/tags` `{tags}` | as above | status, assignee (a user id or `null`), up to 10 tags of 30 characters |
| `POST /api/admin/support-tickets/{id}/replies` (`message`, `is_internal`, `attachments[]`), `POST …/{id}/read`, `GET …/{id}/attachments/{filename}` | as above | staff reply or internal note, mark read, download |

## Billing

The Billing module calculates what projects cost and who pays: one bill per project and calendar
month, paid by the project's owner, from the prices of the project's region. Each region is either
**pay as you go** (metered vCPU-hours, GB-hours, GPU-hours, binding storage, sandboxes) or **fixed**
(a project requests resources, the region's seller approves, the monthly price is prorated by day
and the project cannot use more). With tenancy the platform bills each tenant for the shared
platform regions, and each tenant bills its projects with a markup it chooses when it accepts a
region offer. RunR only calculates: an external invoicing system reads bills and usage through the
API, receives signed events and writes back a payment link and the paid status of each bill.

### Enabling it

`BILLING_ENABLED=true` (Helm `billing.enabled: true`, Compose `BILLING_ENABLED=true` in
`compose/.env`), the currency with `BILLING_CURRENCY` (`billing.currency`, default `USD`). The
console shows Settings → **Billing** and **My bills** and the project's **Billing** tab while the
API reports billing on. With the module off, metering keeps recording usage and no money is
calculated.

Everything else — bills, prices and their effective dates, fixed resources, region offers and
markup, project stop and resume, the API, the events and the permissions — is in
[Billing](billing.md).
