# Security: secrets and audit log

This page describes how RunR protects stored secrets, how secret environment variables reach a
workload, how the encryption key is rotated, and what the audit log records.

- [Encryption at rest](#encryption-at-rest)
- [Where secrets never appear](#where-secrets-never-appear)
- [Real-time channels](#real-time-channels)
- [Sign-in sessions and API tokens](#sign-in-sessions-and-api-tokens)
- [SSO sign-in handoff](#sso-sign-in-handoff)
- [Secret environment variables](#secret-environment-variables)
- [Key rotation](#key-rotation)
- [Audit log](#audit-log)
- [Audited events](#audited-events)
- [Retention](#retention)
- [SIEM forwarding](#siem-forwarding)
- [Permissions](#permissions)

## Encryption at rest

Every database column that holds a secret is encrypted with the application key (`APP_KEY`,
AES-256-CBC with an HMAC, Laravel's encrypter). The list is defined in one place,
`api/app/Support/Secrets/SecretColumns.php`. Tables of schema **all** exist in the central schema
(the only schema of a single-tenant install) and in every tenant schema of the multi-tenant
edition; tables of schema **central** exist in the central schema only.

| Table | Schema | Columns |
|-------|--------|---------|
| `xaas_configs`, `faas_configs`, `container_configs` | all | `env_vars` (all environment variable values) |
| `aaas_configs`, `agent_flow_configs`, `maas_configs` | all | `env_vars` — legacy tables of the AI project types AaaS, AgentFlow and MaaS (retired in 0.4.0; existing rows are read-only) |
| `versions` | all | `config_snapshot` (the configuration of a version, including env var values) |
| `project_secrets` | all | `value` (secret environment variables) |
| `knative_regions` | all | `kubeconfig`, `migration_old_kubeconfig`, `redis_password`, `registry_password`, `registry_storage_config` (S3 access and secret keys) |
| `auth_providers` | all | `client_id`, `client_secret`, `saml_sp_private_key` |
| `social_accounts` | all | `access_token`, `refresh_token` |
| `users` | all | `two_factor_secret`, `two_factor_recovery_codes` |
| `ai_hub_credentials` | all | `token` (Hugging Face access token, [Hugging Face models](huggingface.md)) |
| `ai_model_services` | all | `env_vars` (model service environment) |
| `bindings` | all | `credentials`, `previous_credentials` (KV / object / Postgres / external binding credentials, [Bindings](bindings.md)) |
| `binding_region_services` | all | `secrets` (data service admin credentials: KV Redis password, Garage tokens, external S3 keys) |
| `workflow_apps` | all | `signing_key`, `signing_key_previous` (durable workflows HMAC keys) |
| `alert_channels` | all | `config` (Slack / Teams webhook URLs, Telegram bot tokens, webhook URLs and signing secrets, e-mail addresses) |
| `uptime_checks` | all | `headers` (request headers of a check) |
| `replay_captures` | all | `headers`, `response_headers`, `last_replay`, `body`, `response_body` (captured failed requests, after redaction) |
| `project_git_settings` | all | `preview_env` (env overrides of preview environments) |
| `log_retention_configs` | central | `storage_config` (cold-storage credentials) |
| `sign_in_handoffs` | central | `token` (the API token of an SSO sign-in until its one-time code is exchanged, [SSO sign-in handoff](#sso-sign-in-handoff)) |
| `region_tracing_configs` | central | `export_headers` (headers of an external OTLP endpoint) |
| `sandboxes` | central | `env` (environment of a sandbox, [Sandbox API](sandbox-api.md); in the cluster the Secret `sbx-<id>-env`) |
| `secure_settings` | central | `value` (white-label: billing webhook secret, CAPTCHA secret) |
| `region_supply_chain_settings` | central | `signing_private_key`, `signing_key_password` (the API's copy of a region's cosign key pair; [supply chain](supply-chain.md#signing)) |
| `git_providers` | central | `client_secret`, `private_key`, `webhook_secret` (GitHub App key and secrets, GitLab OAuth application secret, [git deploy](git-deploy.md)) |
| `git_repo_links` | central | `access_token`, `refresh_token`, `webhook_secret` (provider repository connections) |
| `mcp_servers` | central | `signing_key`, `signing_key_previous` (gateway keys of hosted MCP servers; [AI agents](mcp.md#gateway-headers-and-signature)) |

Values that are not stored as reversible secrets:

- passwords — bcrypt hashes
- API tokens and sign-in sessions — SHA-256 hashes (Sanctum)
- tenant invitation tokens — SHA-256 hashes (`tenant_invitations.token`); the accept link in the
  e-mail carries the token, a lookup compares hashes in constant time
- SSO sign-in handoff codes — SHA-256 hashes (`sign_in_handoffs.code_hash`, the API token they
  hand over is encrypted)
- the region agent token — SHA-256 hash (`knative_regions.agent_token_hash`)
- MCP ([AI agents](mcp.md)): OAuth authorization codes (`mcp_oauth_requests.code_hash`), OAuth
  client secrets (`mcp_oauth_clients.secret_hash`) and MCP session ids (`mcp_sessions.id`) —
  SHA-256 hashes; tokens issued through OAuth are Sanctum API tokens (hashes, see above)
- TLS private keys of custom domains and of the function-domain TLS, private database credentials
  and Skupper link tokens — written straight into Kubernetes Secrets in the region, never into
  the database

Upgrades encrypt existing rows. The migration `2026_10_10_000002_encrypt_secret_columns`
(central) and the tenant migration `0041_encrypt_secret_columns` (every tenant schema, run by
`tenancy:migrate` in the Helm pre-upgrade job) change the JSON secret columns to `text` and
encrypt every plaintext value with the current key. Both are idempotent: an encrypted value is
left as it is. A row written in plaintext by an older pod while the upgrade rolls out is still
read correctly and is encrypted on its next save, or by running:

```sh
php artisan secrets:encrypt
```

The command walks the central schema and every tenant schema (`--tenant=<slug>` limits it to one
tenant and skips the central schema), prints how many values it encrypted and re-encrypted, and
records a `secrets.reencrypted` audit event. Its signature is
`secrets:encrypt [--rotate] [--tenant=<slug>]`; `--rotate` also re-encrypts values written with a
previous key ([Key rotation](#key-rotation)).

A value that looks encrypted but does not decrypt with `APP_KEY` or any of `APP_PREVIOUS_KEYS`
is never returned as it is: reading it fails. Keep the key safe and keep a copy — without it the
encrypted columns cannot be read.

## Where secrets never appear

- **API responses**: kubeconfigs, Redis and registry passwords, S3 credentials, the OAuth client
  secret, the SAML SP private key and OAuth tokens of linked accounts are never returned. The
  SSO provider list shows `has_client_secret` and the last four characters of the secret
  (`client_secret_hint`). Secret environment variables are listed by name only.
- **Broadcasts**: every channel is private ([Real-time channels](#real-time-channels)). As a
  second line of defence the events carry no environment variable values (`env_vars_hidden`, or
  names with masked values and `env_vars_redacted`), and fields with secret names (password,
  token, secret, kubeconfig, private key, access or secret key, credentials) are masked. The
  console loads the values it needs through the API.
- **Git**: the configuration snapshot RunR commits to a project's repository (and mounts into
  the workload as part of the code ConfigMap) lists environment variable names, not values.
- **Queue payloads**: jobs that carry a kubeconfig, a Redis password or registry credentials
  (region setup, region migration, Redis install) are encrypted on the queue.
- **Shell sessions**: the one-time session configuration the API hands to the WebSocket shell
  through Redis is encrypted.
- **Queue consumers**: the Redis address and password of a queue-triggered project reach its
  consumer from the Kubernetes Secret `<slug>-qc-redis` (`secretKeyRef`), not as values in the
  Deployment spec. The workflow queue bridge reads its sink URL (it carries the hook token) from
  the same Secret.
- **Workflow signing keys**: a `workflow` project's `RUNR_WORKFLOW_SIGNING_KEY` comes from the
  project's Secret `<slug>-secret-env` (`secretKeyRef`), not as a value in the Knative Service.
- **Hosted MCP servers**: the project's gateway key `RUNR_MCP_GATEWAY_KEY` comes from the Secret
  `<slug>-secret-env` (`secretKeyRef`); the gateway never forwards a client's `Authorization`
  header to the server — it sends the signed caller identity instead ([AI agents](mcp.md#gateway-headers-and-signature)).
- **SSO redirects**: the OAuth / OIDC / SAML callback redirects with a one-time code, never the
  API token ([SSO sign-in handoff](#sso-sign-in-handoff)).
- **Response cache**: cached API responses are kept per user and are only served to a signed-in
  request of the same user; secret endpoints are never cached (`Cache-Control: no-store`).
- **Audit log**: events record which fields changed; values of secret fields and of every
  environment variable are never written — see [Audit log](#audit-log).

## Real-time channels

The console's WebSocket subscriptions (Reverb, Pusher protocol) are private channels. Each
subscription is authorized by `POST /api/broadcasting/auth` with the user's API token (and the
tenant of `X-Tenant-ID`); `routes/channels.php` decides with the user's permissions:

| Channel | Who may subscribe |
|---------|-------------------|
| `platform`, `notifications` | members of the organization; in the multi-tenant edition outside a tenant, platform administrators |
| `notifications.{userId}` | that user (notifications addressed to one user go there) |
| `deployment.{projectId}` | `projects.view` on the project |
| `support-tickets` | `tickets.manage` (or a platform administrator) |
| `support-ticket.{ticketId}` | the ticket's author, `tickets.manage` |
| `support-ticket.{ticketId}.internal` | `tickets.manage` — internal notes never reach the author's channel |

In a tenant the names carry the prefix `tenant.{tenantId}.`; the subscription must come from
that tenant's context and the user must belong to the tenant. Support ticket events of a tenant go
to the prefixed channel and to the unprefixed one (platform administrators follow tickets outside
any tenant); internal notes go to the unprefixed `support-ticket.{ticketId}.internal` only. A
refused subscription answers 403 and is recorded as `authorization.denied`.

**Custom WebSocket clients (upgrade from 0.4.x).** Public channels are gone: a client that
subscribed to `platform`, `notifications`, `deployment.{projectId}` or the ticket channels as
public channels subscribes to them as private channels (`private-<name>` in the Pusher protocol,
`Echo.private(name)` in Laravel Echo) and authorizes every subscription with
`POST /api/broadcasting/auth` (`socket_id`, `channel_name`), sending `Authorization: Bearer <API
token>` and, in a tenant, `X-Tenant-ID`. Notifications addressed to one user arrive on
`notifications.{userId}`, internal ticket notes on `support-ticket.{ticketId}.internal`.

## Sign-in sessions and API tokens

Every sign-in of a Platform user or a Tenant user — with a password in the console or with
`runr login --with-password`, through SSO, after a self-service signup or when accepting an
invitation — creates a **sign-in session**: an API token the console sends with every request.
API tokens are the ones users create themselves (Settings → API Tokens, `runr tokens create`, the
`runr-cli@<host>` token of `runr login --with-password`). Both are stored as SHA-256 hashes.
**Account → Active Sessions** lists every token of the user — sign-in sessions and API tokens —
with its device, address and last use.

- **Lifetime.** A sign-in session ends 30 days after the sign-in (`RUNR_LOGIN_TOKEN_TTL_DAYS`,
  Helm value `auth.loginTokenTtlDays`); the console then asks to sign in again. The lifetime
  counts from the sign-in for every session, also for sessions signed in before the value was set
  or lowered. `0` keeps sessions until they are signed out or revoked. API tokens keep the expiry
  they were created with: none, or the date given. The `runr-cli@<host>` token of
  `runr login --with-password` expires 90 days after the login unless `--token-ttl` sets another
  lifetime ([CLI](cli.md#log-in)).
- **Signing out** (`POST /api/auth/logout`) deletes the session's token. **Revoking** a session
  (`DELETE /api/account/sessions/{id}`) deletes that token, **revoking all others**
  (`POST /api/account/sessions/revoke-all`) every token of the user except the current one; the
  browsers that held them are signed out. Deleting an API token (`DELETE /api/tokens/{id}`,
  `runr logout`) ends it. A deleted token answers 401 from then on.
- **Changing the password** (`POST /api/account/password`) ends the user's other sign-in
  sessions; the session that changed it and the API tokens stay. **Disabling the account** ends
  every token but the current one, which is refused from then on.
- **Deleting a user** — the user's own account deletion (`DELETE /api/account`) or an
  administrator's (`DELETE /api/users/{id}`, in the multi-tenant edition from the platform
  administrators' user list) — deletes every sign-in session and API token of the user in the
  transaction that deletes the account, then the user's MCP server sessions. In the multi-tenant
  edition, the account's memberships go with it, and so do its copies in the organizations it
  belonged to — each organization keeps a copy of its members in its own schema — with the
  user's groups and role assignments there. Removing a member from an organization
  (`DELETE /api/tenants/{tenant}/users/{user}`) deletes the API tokens the member created in that
  organization, which work there only; their sign-in sessions and their tokens of other
  organizations stay. Deleting an organization deletes every API token created in it.
- API tokens cannot be named `api` or `invitation-accept`: these names mark sign-in sessions.
- **Cleanup.** `php artisan tokens:prune` (scheduled daily at 03:15) removes the sign-in sessions
  that ended, API tokens 30 days after they expired — until then the console lists an expired
  token as expired — and the tokens of users that no longer exist. In the multi-tenant edition it
  also removes the tokens created in an organization that no longer exists or whose user is no
  longer its member (a platform administrator's token keeps working in an organization), and the
  organizations' copies, groups and role assignments of users that no longer exist. These are the
  rows user deletions, member removals and organization deletions of releases before 0.5.6 left.

## SSO sign-in handoff

After an OAuth, OIDC or SAML sign-in RunR redirects the browser to
`/oauth/callback?code=<one-time code>` — a random 64-character code, valid for 60 seconds and for
one exchange. The console trades it for the API token with `POST /api/oauth/exchange`
(`{"code": "…"}` → `{"token": "…", "user_id": "…"}`, no authentication, rate-limited like the
other sign-in routes); a used, expired or unknown code answers 422.
The token therefore never appears in a URL, the browser history, a `Referer` header or an access
log. RunR stores the code's SHA-256 only, and the token encrypted until the exchange.

**Custom SSO front-ends (upgrade from 0.4.x).** The callback no longer carries
`?token=…&user_id=…`. A front-end that handles `/oauth/callback` itself reads `code` from the
query string and posts it to `POST /api/oauth/exchange` within 60 seconds; the response carries the
`token` and `user_id` the URL used to.

## Secret environment variables

A project has two kinds of environment variables:

- **plain** variables (the `KEY=value` editor) — stored encrypted, shown in the console and the
  API, set as `value` entries in the container spec;
- **secret** variables (the *Secret variables* section of the project's environment step) —
  stored encrypted and **write-only**: they can be added, replaced and removed, but the console
  and the API show only their names, when they were last changed and by whom.

A name is either plain or secret, never both. Names follow the same rules as plain variables
(letters, digits and `_`, not starting with a digit; `PORT`, `K_*` and `RUNR_*` are reserved).
A project has at most 100 secret variables, each value at most 32 KiB.

On deploy, RunR applies a Kubernetes Secret named `<project-slug>-secret-env` in the region's
project namespace — one key per secret variable — and gives the Knative Service (or the CronJob of
a scheduled project) a `secretKeyRef` env entry per variable:

```yaml
env:
  - name: DATABASE_PASSWORD
    valueFrom:
      secretKeyRef:
        name: orders-secret-env
        key: DATABASE_PASSWORD
```

The value never appears in the Knative Service or CronJob spec. Changes to secret variables take
effect with the next deploy, like changes to plain variables. Lifecycle of the Secret:

- every deploy applies it with the project's current secret variables (removed keys disappear);
- a deploy of a project without secret variables deletes it;
- deleting a deployment or the project deletes it;
- deleting a tenant removes it with every other labelled resource of the tenant
  (`runr/tenant=<slug>`).

API:

| Method | Path | |
|--------|------|-|
| `GET` | `/api/projects/{project}/secrets` | names, `updated_at`, `updated_by`, `can_view_values` |
| `PUT` | `/api/projects/{project}/secrets/{name}` | body `{"value": "…"}` — create or replace |
| `DELETE` | `/api/projects/{project}/secrets/{name}` | remove |
| `GET` | `/api/projects/{project}/secrets/{name}/value` | the value — requires `secrets.view_values` |

Reading a value back requires the `secrets.view_values` permission; of the built-in roles only
Owner and Admin have it. Every read is
recorded in the audit log (`project.secret.reveal`).

The project resource names the secret variables as well: `config.secret_env_keys` (names only;
`null` for a user without `env.view`). `POST /api/projects` and `PUT /api/projects/{project}`
accept secret variables in `config.secret_env_vars` — a name → value map where an empty value
removes the variable — so a client such as the `runr` CLI (`runr env set --secret`) changes plain
and secret variables in one request. The same rules apply as for the endpoints above (`env.edit`,
naming, limits), and a name can move between plain and secret in a single update: remove it on one
side and set it on the other. The values never reach the config, a version snapshot or the audit
log.

## Key rotation

`APP_KEY` encrypts all secrets at rest. Laravel also tries the keys listed in `APP_PREVIOUS_KEYS`
(comma-separated) when decrypting, so a new key can be introduced without downtime:

1. Generate a new key: `php artisan key:generate --show`.
2. Set the new key as `APP_KEY` and the old one in `APP_PREVIOUS_KEYS`, then roll out. With the
   Helm chart (from RunR's private Helm repository, added as `runr`; `--version` keeps the
   installed chart version):

   ```sh
   helm upgrade runr runr/runr --version <installed version> --reuse-values \
     --set secret.appKey='base64:NEW…' \
     --set secret.previousAppKeys='base64:OLD…'
   ```

   With `secret.create=false`, update `APP_KEY` and add `APP_PREVIOUS_KEYS` in your own Secret
   and restart the api, horizon, reverb and scheduler deployments.
3. Re-encrypt everything with the new key:

   ```sh
   kubectl exec deploy/runr-api -- php artisan secrets:encrypt --rotate
   ```

   The command re-encrypts values of the previous keys in the central and every tenant schema
   and records a `secrets.reencrypted` audit event.
4. Remove the previous key and roll out again:

   ```sh
   helm upgrade runr runr/runr --version <installed version> --reuse-values --set secret.previousAppKeys=''
   ```

Sign-in sessions and API tokens are hashes, not encrypted values: they stay valid across a
rotation. Values Laravel encrypts on the fly (OAuth state, queued jobs in flight) decrypt with the
previous key during step 2.

## Audit log

The audit log is append-only. Each event records:

| Field | |
|-------|-|
| `occurred_at` | time, microsecond precision (UTC) |
| `tenant_id` | tenant of a tenant action; empty for platform actions |
| `actor` | `type` (`user`, `api_token`, `system`, `anonymous`), `id`, `name`, `email`, `auth_method` (`session`, `api_token:<id>`, `sso:<provider>`, `saml`, `mcp:<token id>` for calls of the [MCP server](mcp.md)) |
| `actor.impersonator` | the platform administrator, when a platform administrator acts inside a tenant they are not a member of (impersonation) |
| `action` | e.g. `project.update`, `auth.login`, `shell.session.open` |
| `result` | `success`, `denied` (401 / 403 / 429) or `failure` |
| `target` | `type`, `id`, `name` |
| `ip_address`, `user_agent` | the client (the real client IP behind the gateway and ingress) |
| `request_id` | also returned in the `X-Request-ID` response header of every API request |
| `http_method`, `route`, `status_code` | the API request |
| `metadata` | non-secret details: which fields changed, before / after of non-secret fields, added / removed / changed environment variable *names*, a failure reason |

**Changes.** `metadata.changes` lists the records a request created, updated or deleted (up to 25
per event): `model`, `id`, `name`, `event`, the changed `fields`, `before` / `after`
of the non-secret ones, and for environment variables `env_vars` with the names only —
`{"added": ["NEW_FLAG"], "removed": ["OLD_URL"], "changed": ["LOG_LEVEL"]}` (empty lists left
out). Values of environment variables and secret fields are never recorded.

**Storage.** All events live in one table, `audit_events`, in the central schema; tenant events
carry their `tenant_id`. One central trail survives a tenant's deletion (the tenant schema is
dropped), keeps impersonation and cross-tenant administration in the same place as the tenant's
own events, and gives retention and SIEM forwarding a single source. Tenant isolation is enforced
on every read: inside a tenant only that tenant's events are visible.

**Append-only.** The application never changes or deletes an event: updates, deletes and
truncation of the model and its query builder are refused, and the only removal path is the
retention prune. On PostgreSQL a trigger enforces the same in the database: `UPDATE` and
`TRUNCATE` on `audit_events` always fail, `DELETE` only succeeds inside the prune's transaction.

**API** (all `GET` unless noted):

| Path | Ability | |
|------|---------|-|
| `/api/audit-events` | `audit.view` | list, newest first, 25 per page (`per_page` up to 100) |
| `/api/audit-events/{id}` | `audit.view` | one event |
| `/api/audit-events/export?format=csv\|json` | `audit.export` | file download, same filters, up to 100 000 events (`AUDIT_EXPORT_MAX_ROWS`) |
| `/api/audit-settings` | `audit.view` | retention days, SIEM forwarding state |
| `PUT /api/audit-settings` | `settings.manage` (multi-tenant: platform administrator) | `{"retention_days": 365}` |

Filters: `actor` (user id, or part of the name or e-mail), `action` (exact name, `project.*` for a
prefix, comma-separated list), `target_type`, `target_id`, `target` (part of the target name),
`result`, `from` / `to` (ISO 8601), `request_id`, `q` (free text), and for platform
administrators outside a tenant `tenant_id` (`platform` = platform events only).

In the console: **Settings → Audit log** — filters, a detail drawer with the full event and its
changes, CSV / JSON export, and the retention setting. From the CLI: `runr audit ls|get|export`
with the same filters ([CLI](cli.md#audit-log-runr-audit)).

CSV exports neutralize spreadsheet formulas (cells starting with `=`, `+`, `-`, `@` get a leading
`'`).

## Audited events

Recorded after the API answered, with the outcome of the request:

| Area | Actions |
|------|---------|
| Sign-in | `auth.login` (password; failures with the login name tried), `auth.logout`, `auth.sso.login` (OAuth / OIDC / SAML), `user.register`, `user.signup.verify` (self-service signup), `user.signup.resend`, `invitation.accept` |
| Account | `auth.password.change`, `auth.2fa.setup`, `auth.2fa.enable`, `auth.2fa.disable`, `auth.2fa.recovery_codes.regenerate`, `account.profile.update`, `account.avatar.update`, `account.avatar.remove`, `account.disable`, `account.delete`, `account.notification_preferences.update`, `session.revoke`, `session.revoke_all` |
| API tokens | `api_token.create`, `api_token.revoke` |
| Projects | `project.create`, `project.update` (changed fields, env var names), `project.delete`, `project.code.save`, `project.secret.set`, `project.secret.delete`, `project.secret.reveal` |
| Workflows | `workflow.declare`, `workflow.sync`, `workflow.update`, `workflow.app.update`, `workflow.signing_key.rotate`, `workflow.signing_key.reveal`, `workflow.run.start`, `workflow.run.cancel`, `workflow.run.retry`, `workflow.run.replay` |
| Deployments | `deployment.create`, `deployment.rollback` (a version older than the latest), `deployment.delete`, `deployment.status.update` |
| Shell | `shell.session.request`, `shell.session.open`, `shell.session.close` (duration, reason) |
| Domains | `domain.create`, `domain.verify`, `domain.tls.update`, `domain.delete` |
| Gateways | `gateway.update`, `gateway.route.create`, `gateway.route.update`, `gateway.route.delete` |
| Private databases | `private_connection.create`, `private_connection.delete`, `region.skupper.site.ensure` |
| Bindings | `binding.create`, `binding.update`, `binding.delete`, `binding.restore`, `binding.credentials.rotate`, `binding.credentials.reveal`, `binding.attach`, `binding.attachment.update`, `binding.detach`, `region.data_service.install`, `region.data_service.uninstall`, `tenant.binding_quota.update`, `binding.purged` (system, after the retention) |
| Regions | `region.create`, `region.update`, `region.delete`, `region.kubeconfig.download`, `region.settings.update`, `region.capacity.update`, `region.tls.update`, `region.knative.*`, `region.components.detect`, `region.workers.update`, `region.redis.test`, `region.redis.configure`, `region.redis.remove`, `region.sandbox.install`, `region.sandbox.uninstall`, `region.sandbox.mapping.update`, `region.wasm.install`, `region.wasm.uninstall`, `region.migration.start`, `region.migration.complete`, `region.migration.rollback`, `region.registry.remove`, `region.demo.create`, `region.demo.remove`, `registry.setup.start`, `registry.setup.retry` |
| Runtimes | `runtime.create`, `runtime.update`, `runtime.delete`, `runtime.build`, `runtime.test_deploy` |
| GPUs and models ([AI](ai.md), [Hugging Face models](huggingface.md)) | `region.gpu.install`, `region.gpu.uninstall`, `region.gpu.settings.update`, `region.model_storage.update`, `tenant.gpu_quota.update`, `model.hub_token.set`, `model.hub_token.remove`, `model.create`, `model.update`, `model.delete`, `model.snapshot.create`, `model.snapshot.delete`, `model.upload.start`, `model.upload.complete`, `model.upload.abort`, `model_service.create`, `model_service.update`, `model_service.delete`, `model_service.deploy`, `model_service.stop`, `project.model.bind`, `project.model.binding.update`, `project.model.unbind` |
| Uploaded images ([details](images.md#audit)) | `image.upload.start`, `image.upload.complete`, `image.upload.abort`, `image.delete` (registry manifest removed or not, what still used it); recorded where they happen: `image.upload.pushed` (digest, kind, size, the digest it replaced), `image.upload.failed` (the region's reason) |
| Sandboxes | `sandbox.create`, `sandbox.delete`, `sandbox.expire` (reaper), `sandbox.exec` (command line), `sandbox.run`, `sandbox.terminal.request`, `sandbox.file.upload`, `sandbox.file.download`, `sandbox.file.delete`, `sandbox.file.mkdir`, `sandbox.process.start`, `sandbox.process.kill`, `sandbox.timeout.update`, `sandbox.network.update`, `sandbox.pause`, `sandbox.resume`, `sandbox.region.update`, `sandbox.pool.create` / `.update` / `.delete`, `sandbox.quota.update` / `.reset` ([Sandbox API](sandbox-api.md#audit)) |
| Supply chain ([details](supply-chain.md#audit)) | `region.supply_chain.update`, `region.signing_key.request`, `region.signing_key.retired_remove`, `region.scanner_db.refresh`, `region.admission.install`, `region.admission.remove`, `security.scan.request`, `tenant.residency.update`; recorded where they happen: `deploy.policy_blocked`, `deploy.policy_warned`, `deploy.policy_overridden`, `residency.denied`, `residency.migration_denied`, `image.signed`, `region.signing_key.generated` / `.rotated` / `.failed`, `region.admission.installed` / `.removed` / `.failed`, `region.scanner_db.refreshed` |
| Users | `user.create`, `user.update`, `user.delete`, `user.2fa.reset`, `user.admin.toggle` |
| Tenants & members | `tenant.create`, `tenant.update`, `tenant.delete`, `tenant.switch`, `tenant.region.assign`, `tenant.region.quota.update`, `tenant.region.remove`, `member.add`, `member.invite`, `member.update` (role), `member.remove`, `member.2fa.reset`, `invitation.resend`, `invitation.cancel` |
| Impersonation | `impersonation.start`, `impersonation.stop`; events in between name the administrator as impersonator |
| Roles & access | `role.create`, `role.update`, `role.delete`, `role.default.update`, `group.create`, `group.update`, `group.delete`, `group.member.add`, `group.member.remove`, `member.role.update`, `project.access.grant`, `project.access.revoke`, `sso.group_mapping.create` / `.update` / `.delete` |
| White-label | `branding.update`, `branding.asset.upload` / `.delete`, `tenant.branding.update`, `tenant.branding.asset.upload` / `.delete`, `signup.settings.update`, `tenant.onboarding.complete`, `plan.create`, `plan.update`, `plan.delete`, `billing.settings.update` — settings changes with field names only, never secret values |
| Git deploy ([details](git-deploy.md#security)) | `git.settings.update`, `git.redeploy`, `git.repo.connect`, `git.repo.disconnect`, `git.preview.redeploy`, `git.preview.delete`, `git_provider.create` / `.update` / `.delete`; recorded where they happen: `git.push` (pushes to the RunR git remote, refused pushes as `denied`), `git.installation.link`, `git.webhook.rejected` |
| AI agents ([details](mcp.md#audit-and-limits)) | recorded where they happen: `mcp.session.start`, `mcp.session.end`, `mcp.tool.call` (tool, target, argument names — never values — result; the API actions a tool performs carry `auth_method` `mcp:<token id>` and `metadata.mcp_tool`), `oauth.client.register`, `oauth.token.issue`, `oauth.token.revoke`, `mcp.server.call` (hosted servers with **Audit calls** on); routes: `oauth.consent.approve`, `oauth.consent.deny`, `mcp.client.revoke`, `mcp_server.create`, `mcp_server.update`, `mcp_server.delete`, `mcp_server.key.rotate`, `mcp_server.inspect` |
| Settings | `settings.update`, `audit.settings.update` |
| SSO providers | `auth_provider.create`, `auth_provider.update`, `auth_provider.delete`, `auth_provider.toggle`, `social_account.unlink` |
| Billing ([details](billing.md)) | `billing.cost_config.create` / `.update` / `.delete`, `billing.capacity_deal.create` / `.update`, `billing.subscription.update` (plan, interval, external reference), `billing.charge.create`, `billing.charge.update` (external reference), `billing.charge.void`, `billing.purchase.record` (kind, reference, amount, plan), `billing.purchase.void`, `billing.event.test`, `billing.event.redeliver` |
| Support | `ticket.status.update`, `ticket.assign`, `ticket.tags.update`, `ticket.admin_reply` |
| Observability | `region.tracing.update`, `region.tracing.install`, `region.tracing.uninstall`, `project.tracing.update`, `project.replay.update`, `replay.replay`, `replay.delete`, `replay.delete_all`, `uptime_check.create` / `.update` / `.delete` / `.run`, `uptime_incident.update`, `status_page.update`, `alert_channel.create` / `.update` / `.delete` / `.test`, `alert_rule.create` / `.update` / `.delete`, `alert_delivery.retry` |
| Demo | `demo.seed`, `demo.remove` |
| Authorization | `authorization.denied` — any other API request answered 403 |
| System | `audit.export`, `audit.retention.pruned`, `secrets.reencrypted` |

## Retention

A daily job (03:30, scheduler) deletes events older than the retention period and records an
`audit.retention.pruned` event with the number of deleted events. The default is 365 days
(`AUDIT_RETENTION_DAYS`, Helm value `audit.retentionDays`); **Settings → Audit log**
(`PUT /api/audit-settings`) overrides it — `settings.manage` in the single-tenant edition, a
platform administrator outside any tenant in the multi-tenant edition — stored as the platform
setting `audit_retention_days`, up to 3650 days. `0` keeps every event.

## SIEM forwarding

With forwarding on, every event is also written to the `audit` log channel as one JSON line —
by default to the container's standard output, where a log collector (Fluent Bit, Vector,
Promtail, …) picks it up:

```json
{"log_type":"runr.audit","id":"01J…","occurred_at":"2026-10-10T09:12:44.120Z","tenant_id":null,"actor":{"type":"user","id":"1","name":"Admin","email":"admin@example.com","auth_method":"session","impersonator":null},"action":"project.update","result":"success","target":{"type":"project","id":"01J…","name":"orders"},"ip_address":"203.0.113.7","user_agent":"Mozilla/5.0 …","request_id":"01J…","http_method":"PUT","route":"api/projects/{project}","status_code":200,"metadata":{"changes":[…]}}
```

Filter on `log_type = runr.audit`. Enable it with the Helm value `audit.siemForwarding: true`
(env `AUDIT_FORWARD_ENABLED=true`; the Helm chart's `audit` section has exactly two values,
`retentionDays` and `siemForwarding`). `AUDIT_FORWARD_STREAM` changes the stream of the `audit`
channel (default `php://stdout`) and `AUDIT_FORWARD_CHANNEL` the log channel (default `audit`);
with the Helm chart both are set through `extraEnv`, as is `AUDIT_EXPORT_MAX_ROWS`. Forwarded
events contain exactly what the database row contains — no secret values.

## Permissions

The abilities are RBAC permissions ([permissions.md](permissions.md)); the built-in roles grant them as follows:

| Permission | Built-in roles | |
|------------|----------------|-|
| `env.view` | Owner, Admin, Developer | names of secret variables (`GET …/secrets`) |
| `env.edit` | Owner, Admin, Developer | set, replace and remove secret variables |
| `secrets.view_values` | Owner, Admin | read a secret value back (project scope: a project role that includes it) |
| `audit.view` | Owner, Admin | the audit log of the organization |
| `audit.export` | Owner, Admin | CSV / JSON export |

Custom roles can include any of them. Platform administrators pass every check (unless their API
token is scoped). Inside a tenant the audit log shows that tenant's events only; outside a tenant
(multi-tenant edition) only platform administrators see platform and cross-tenant events. The
retention period is install-wide: `settings.manage` changes it in the single-tenant edition, a
platform administrator outside any tenant in the multi-tenant edition.
