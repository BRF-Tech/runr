# Security: secrets and audit log

This page describes how RunR protects stored secrets, how secret environment variables reach a
workload, how the encryption key is rotated, how RunR trusts the runtime patch feed, what the
audit log records, and how a region keeps its cluster-local services and project pods from other
callers.

- [Encryption at rest](#encryption-at-rest)
- [Where secrets never appear](#where-secrets-never-appear)
- [Real-time channels](#real-time-channels)
- [Password sign-in](#password-sign-in)
- [Sign-in sessions and API tokens](#sign-in-sessions-and-api-tokens)
- [SSO sign-in handoff](#sso-sign-in-handoff)
- [Secret environment variables](#secret-environment-variables)
- [Key rotation](#key-rotation)
- [Runtime patch feed](#runtime-patch-feed)
- [Audit log](#audit-log)
- [Audited events](#audited-events)
- [Retention](#retention)
- [SIEM forwarding](#siem-forwarding)
- [Permissions](#permissions)
- [Region network isolation](#region-network-isolation)

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
| `project_secrets` | all | `value` — only values written before pass-through (or with `RUNR_SECRET_STORAGE=database`), until `secrets:move-to-region` moves them into their region, and on an installation upgraded from 0.5.10 the encrypted copies kept for a rollback until the upgrade is finalized ([Rolling back to 0.5.10](#rolling-back-to-0510)); afterwards new values never reach the database ([Secret environment variables](#pass-through)) |
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
| `secure_settings` | central | `value` (white-label: CAPTCHA secret) |
| `billing_settings` | central and tenant schemas | the billing webhook secret (Billing module) |
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
- the values of secret environment variables — written straight into the project's Kubernetes
  Secret in the region, or into the region's own OpenBao ([Pass-through](#pass-through)); an
  environment's own values into its workload's Secret `<workload>-secret-env` in the environment's
  region ([Environments](environments.md#secrets)); the database keeps a keyed HMAC-SHA256
  fingerprint of each value (`project_secrets.fingerprint`, `environment_secrets.fingerprint`),
  never a plain hash
- the region's OpenBao ([secret stores](secret-stores.md#openbao-in-the-region)): its unseal key
  and its TLS key pair live in Secrets of the region (`runr-openbao-unseal`, `runr-openbao-tls`),
  its root token is revoked after the first configuration, and RunR logs in with a short-lived
  service account token it never stores; the password of the Postgres role OpenBao issues
  [dynamic credentials](bindings.md#dynamic-credentials) with is known to OpenBao only
- references to an organization's secret stores — the value is read in the region by External
  Secrets Operator with the region's identity and never reaches RunR; a store's settings hold
  addresses and roles, no credential ([Secret stores](secret-stores.md))
- the keys cert-manager creates in a region — the region CA's (Secret `runr-region-ca`), the ACME
  account's (`runr-acme-account`) and those of the custom-domain certificates — stay in the
  region's Secrets; RunR stores none of them and hands out only the CA's certificate (its
  download), never a key ([cert-manager](regions.md#cert-manager))

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
  (`client_secret_hint`). Secret environment variables are listed by name, with where the value
  lives, its version and the first 12 hex digits of its keyed fingerprint — never the value.
- **Deploy manifests**: no secret value goes through a deploy. The values are written into the
  region's Secret key by key when they are set; a deploy applies `secretKeyRef` entries, and for
  references an ExternalSecret and its SecretStore, which name a store, a path and a service
  account, not a value or a credential.
- **Broadcasts**: every channel is private ([Real-time channels](#real-time-channels)). As a
  second line of defence the events carry no environment variable values (`env_vars_hidden`, or
  names with masked values and `env_vars_redacted`), and fields with secret names (password,
  token, secret, kubeconfig, private key, access or secret key, credentials) are masked. The
  console loads the values it needs through the API.
- **Git**: the configuration snapshot RunR commits to a project's repository (and mounts into
  the workload as part of the code ConfigMap) lists environment variable names, not values.
- **Queue payloads**: jobs that carry a kubeconfig, a Redis password or registry credentials
  (region setup, region migration and the removal of the projects from the cluster a migration
  rollback leaves, Redis install) are encrypted on the queue.
- **Shell sessions**: the one-time session configuration the API hands to the WebSocket shell
  through Redis is encrypted.
- **Queue consumers and event gateways**: the Redis address and password of a queue-triggered
  project reach its consumer from the Kubernetes Secret `<slug>-qc-redis` (`secretKeyRef`), not as
  values in the Deployment spec. The workflow queue bridge reads its sink URL (it carries the hook
  token) from the same Secret. An event gateway of several triggers reads every credential — Redis,
  its inbox token, broker passwords — from `gateway.json` in the Secret `<slug>-qc-config`, mounted
  read-only ([triggers.md](triggers.md#the-event-gateway)).
- **Trigger secrets**: webhook signing secrets and broker credentials are encrypted at rest
  (`project_triggers.secrets`) and write-only through the API (a trigger shows which secrets are
  set, never their values). Stored webhook requests keep their headers without `Authorization`,
  `Cookie` and `Proxy-Authorization`; their bodies need `replay.manage`.
- **Topic publish tokens**: a function's `RUNR_TOPICS_TOKEN` comes from the project's Secret
  `<slug>-secret-env` (`secretKeyRef`); it is an HMAC token of the region's key and only reaches the
  topics of its organization. The region's publisher gets the key from the Secret `runr-topics`.
- **Workflow signing keys**: a `workflow` project's `RUNR_WORKFLOW_SIGNING_KEY` comes from the
  project's Secret `<slug>-secret-env` (`secretKeyRef`), not as a value in the Knative Service.
- **Hosted MCP servers**: the project's gateway key `RUNR_MCP_GATEWAY_KEY` comes from the Secret
  `<slug>-secret-env` (`secretKeyRef`); the gateway never forwards a client's `Authorization`
  header to the server — it sends the signed caller identity instead ([AI agents](mcp.md#gateway-headers-and-signature)).
- **SSO redirects**: the OAuth / OIDC / SAML callback redirects with a one-time code, never the
  API token ([SSO sign-in handoff](#sso-sign-in-handoff)).
- **The Helm chart's ConfigMap**: the passwords of the database, Redis and the SMTP server
  (`database.*`, `redis.*`, `mail.*`) reach the pods as Secret environment variables
  (`runr-secret` or a Secret of yours), never through `runr-config`, which holds `extraEnv`
  ([install.md](install.md#database-redis-and-mail)).
- **Logs**: a generated password of the first administrator goes to a Secret (`runr-secret`, or
  `runr-admin` with `secret.create: false`), never to the bootstrap Job's log; the Compose migrator
  prints it once on the console that runs it ([First administrator](install.md#first-administrator)).
  The single-use sign-in link of the Horizon dashboard is kept as a hash and never logged.
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

## Password sign-in

The console's sign-in page (field **E-mail or user name**), `runr login --with-password` and
`POST /api/auth/login` (`login`, `password`) take the account's **user name or e-mail address**:

- White space around it is ignored and letter case does not matter: `admin`, `Admin`,
  `admin@example.com` and `Admin@Example.com` name the same account.
- An identifier with `@` is an e-mail address first, then a user name; one without `@` is a user
  name. A user name never contains `@`: registration, the user management and the profile allow
  letters, digits, `-` and `_`, the Helm value `admin.username` lower-case letters, digits, `_`, `.`
  and `-`, and the first-administrator bootstrap creates no account whose `ADMIN_USERNAME` has an
  `@`. An account of an earlier release whose user name contains `@` keeps signing in with it,
  unless it is another account's e-mail address: that address names the other account, and the
  account signs in with its own e-mail address.
- Registration, the user management and the profile refuse a user name or an e-mail address that
  differs from another account's in letter case only, and tenant invitations, SSO sign-ins and the
  organization import name accounts by their e-mail address the same way: the address in any
  letter case is the existing account, never a new one. Accounts that differ in case only (from
  an earlier release) sign in with their exact spelling; another spelling names neither, and an
  invitation or SSO sign-in with it is refused (`email_ambiguous`) instead of creating a third.
- One identifier therefore names one account at most, and the password is checked against that
  account only. A wrong password and an unknown account get the same answer (401, *Invalid
  username/email or password.*) after the same work. The second factor, a disabled account and,
  in the multi-tenant edition, the membership of the tenant whose sign-in page was used are
  checked after the password, for that account.
- **Rate limit.** Sign-in attempts are limited per client address and account
  (`AUTH_RATE_LIMIT_PER_MINUTE`, default 10 a minute). The user name, the e-mail address and their
  spellings share the account's budget, so switching between them does not reset it; an
  identifier that names no account is counted by itself, trimmed and lower-cased.
- **Audit.** Each sign-in is recorded as `auth.login`: a success with the account, a failure with
  the identifier as typed, never the password ([Audited events](#audited-events)).

`php artisan runr:horizon-link --user=<name>` and `php artisan tenancy:setup --admin=<name>` find
the account a user name or e-mail address names the same way.

## Sign-in sessions and API tokens

Every sign-in of a Platform user or a Tenant user (the two choices of the sign-in page in the
multi-tenant edition, [Tenancy](tenancy.md#platform-users-and-tenant-users)) — with a password in
the console or with `runr login --with-password`, through SSO, after a self-service signup or when
accepting an invitation — creates a **sign-in session**: an API token the console sends with every
request. API tokens are the ones users create themselves (Administration → API Tokens, `runr tokens create`, the
`runr-cli@<host>` token of `runr login --with-password`). Both are stored as SHA-256 hashes.
**User menu → Account settings → Active Sessions** lists every token of the user — sign-in sessions and API tokens —
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
In a multi-tenant install the answer also carries `tenants` and `active_tenant`, like the password
sign-in (`POST /api/auth/login`): a sign-in started from a tenant's sign-in page starts the
console in that tenant. The code names the tenant, and the exchange lets in its members and
platform administrators only — anyone else gets 403 and the session the sign-in created is revoked.
The session is not pinned to the tenant: the console switches organizations as after a password
sign-in, and every request is checked against the membership.
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
  **write-only**: they can be added, replaced and removed, but the console and the API show only
  their names, where their values live, their version, when they were last changed and by whom.

A name is either plain or secret, never both. Names follow the same rules as plain variables
(letters, digits and `_`, not starting with a digit; `PORT`, `K_*` and `RUNR_*` are reserved).
A project has at most 100 secret variables, each value at most 32 KiB.

A secret variable is one of three kinds (`source`):

- **a value** (`region`) — RunR writes it straight into the project's region and keeps no copy
  ([Pass-through](#pass-through));
- **a value in the region's OpenBao** (`openbao`) — the same, but the value lives in an OpenBao
  that RunR runs in the region: encrypted at rest by OpenBao, versioned, in OpenBao's audit log,
  and read by the workload with an identity of the project's own. New values of a region go there
  while it runs ([OpenBao in the region](secret-stores.md#openbao-in-the-region));
- **a reference** (`external`) — a path and key in one of the organization's secret stores (Vault /
  OpenBao, AWS Secrets Manager, GCP Secret Manager, Azure Key Vault). External Secrets Operator in
  the region reads it with the region's identity; RunR never sees the value and never gets a
  credential for the store ([Secret stores](secret-stores.md)).

Every kind reaches the container as a `secretKeyRef` env entry of the Knative Service (or the
CronJob of a scheduled project) — the value never appears in the spec:

```yaml
env:
  - name: DATABASE_PASSWORD          # a value: the Secret RunR writes
    valueFrom:
      secretKeyRef:
        name: orders-secret-env
        key: DATABASE_PASSWORD
  - name: STRIPE_KEY                 # a reference: the Secret External Secrets Operator fills
    valueFrom:
      secretKeyRef:
        name: orders-ext-env
        key: STRIPE_KEY
  - name: SMTP_PASSWORD              # a value in the region's OpenBao: synced into <slug>-bao-env
    valueFrom:
      secretKeyRef:
        name: orders-bao-env
        key: SMTP_PASSWORD
```

### Pass-through

RunR's database does not hold the value of a secret variable. A value goes into the Kubernetes
Secret `<project-slug>-secret-env` in the region's project namespace — one key per variable, written
key by key with a JSON merge patch (the Secret is created with the project's labels on the first
write) — and lives there only. The database keeps the metadata: the name, a `version` that counts
the writes of the value, who changed it and when, and a **fingerprint** of the value. (An
installation upgraded from 0.5.10 also keeps an encrypted copy of each value until the upgrade is
finalized, so that 0.5.10 can be installed again: [Rolling back to 0.5.10](#rolling-back-to-0510).)

While the region runs its own OpenBao and takes new values there (`default_for_values`, the
default), a value goes into the KV v2 secret `runr/data/projects/<project id>` of that OpenBao
instead — written key by key as well, read back from there, checked and pruned by every deploy, and
reaching the workload through External Secrets Operator and the Secret `<slug>-bao-env`. Everything
below holds for both places; [OpenBao in the region](secret-stores.md#openbao-in-the-region) has
the details.

The fingerprint is a keyed HMAC-SHA256 of the value (`h1:<64 hex digits>`), never a plain hash, so a
copy of the database does not let anyone try guesses of a short value offline. Its key is
`RUNR_SECRET_FINGERPRINT_KEY` when set, otherwise a key derived from `APP_KEY` (HKDF with its own
context); a fingerprint made with a key of `APP_PREVIOUS_KEYS` still matches after a key rotation.
The API shows its first 12 hex digits (`fingerprint`).

Writing a value:

- the value is written into the region first, then the metadata is saved. A region whose cluster
  does not answer fails the request with `503` (`reason: region_unreachable`) and nothing changes;
- a project without a region cluster (no region, the demo region) cannot keep values: `422`;
- a new value reaches every container that starts afterwards (Kubernetes reads `secretKeyRef`
  entries when a container starts: a scale-up, a cold start from zero, a restart) and every
  container with the next deploy — the answer says `requires_deploy: true`;
- `POST /api/projects` with `config.secret_env_vars` creates the project even when the region does
  not take the values; the answer then carries `secret_env_error` with the reason, and the values
  are set again from the project's environment. `PUT /api/projects/{project}` writes the values
  before it changes anything else, so a region that does not answer fails the whole update.

RunR's own keys of a workload — `RUNR_WORKFLOW_SIGNING_KEY` of a durable workflows project and
`RUNR_MCP_GATEWAY_KEY` of a hosted MCP server — are in the same Secret, but they are RunR's
operating credentials, not the project's secrets: RunR signs the workflow engine's calls and the
gateway signs the caller identity with them, so they stay encrypted in RunR's database
(`workflow_apps`, `mcp_servers`) and every deploy writes them into `<project-slug>-secret-env`.

What a deploy does with the Secret, before it applies the workload:

- it writes RunR's own keys and the values still in the database (written before pass-through, see
  [Upgrade](#upgrade-from-0510-and-earlier));
- it drops the keys of variables removed since the last deploy;
- it checks that every value is there. A value that is not (lost with a cluster, deleted in the
  cluster by hand) fails the deploy with `The values of NAME, … are not in the cluster of region
  "…"`: set them again and deploy;
- a project with nothing left to hold loses the Secret.

Removing a variable removes its metadata at once. Its value leaves the region at once when nothing
of the project runs; while a deployment is live, it stays until the next deploy drops it, so a pod
the running revision starts meanwhile still finds it. Lifecycle of the values Secret:

- stopping a project and deleting a deployment keep it — the region holds the only copy, the next
  deploy reads it;
- deleting the project deletes it; so does detaching the region from an organization (multi-tenant
  edition): the projects lose their region, and their values with it;
- deleting a tenant removes it with every other labelled resource of the tenant
  (`runr/tenant=<slug>`);
- a [preview environment](git-deploy.md) runs in the project's namespace and reads the project's
  Secrets — no copy is made. A fork without the *expose secrets* setting reads none. The Secret
  `<service>-secret-env` that previews of earlier releases had is removed by the preview's next
  deploy;
- a project moved to another region (`PUT /api/projects/{project}` with another
  `knative_region_id`) has its values copied there before the move is saved; the region it leaves
  keeps its copy while workloads there may read it;
- a [region migration](regions.md) copies the values of every project on the region from the
  cluster it leaves to the new one before the projects are deployed there, and a rollback copies
  them back — values set while the region ran on the new cluster included — before the projects
  are deployed on the restored cluster and their workloads leave the new one. The values pass
  through the API's memory only, never the database, a queue or a log. A project whose values do
  not make it (the cluster does not answer) is logged; its deploy then names what is missing.

Reading a value back (`GET …/secrets/{name}/value`) reads it from the region (its Secret or its
OpenBao): `503` when the region does not answer, `409` (`reason: value_missing`) when the value is not there. The answer
carries `matches_fingerprint`: `false` when the region holds another value than the one RunR wrote
(changed in the cluster). A reference cannot be read back through RunR (`422`): its value is in
the store.

### Where the values go: the switch

| Setting | Default | |
|---------|---------|-|
| `RUNR_SECRET_STORAGE` (Helm value `projectSecrets.storage`) | `region` | `region`: pass-through, as above. `database`: the behaviour up to 0.5.10 — new values are kept encrypted with `APP_KEY` in `project_secrets` and written into the region by every deploy (deprecated; for an install that postpones the move) |
| `RUNR_SECRET_FINGERPRINT_KEY` | empty | the fingerprint key; empty derives it from `APP_KEY` |

Values of either kind keep working whatever the setting: a value still in the database is written
into the region by every deploy until `php artisan secrets:move-to-region` moves it.

| Helm value | Default | |
|------------|---------|-|
| `projectSecrets.finalize` | `false` | `true`: the upgrade's region Job (`runr-region-reconcile-<revision>`) runs `secrets:move-to-region --finalize` — the copies kept for a rollback to 0.5.10 are dropped ([Rolling back to 0.5.10](#rolling-back-to-0510)) |

### Upgrade from 0.5.10 and earlier

Values written before pass-through stay encrypted in `project_secrets` after the upgrade and keep
working — every deploy writes them into the region as before; the API lists them with
`stored_in: database`. The Helm chart's region Job (`runr-region-reconcile-<revision>`) moves them into the regions after the upgrade;
by hand:

```sh
kubectl exec deploy/runr-api -- php artisan secrets:move-to-region --dry-run   # what would move
kubectl exec deploy/runr-api -- php artisan secrets:move-to-region
```

Per project, the command writes each value where the region keeps new values — the project's region
Secret, or the region's OpenBao when it runs there and takes them — unless the same value is there
already, reads it back and compares every value by its fingerprint. Only then does the database
value change: until the upgrade is finalized it stays as the encrypted copy for a rollback
(`rollback_copy`, below), afterwards it is cleared. The command walks the central schema and every
tenant schema (`--tenant=<slug>` limits it to one tenant and skips the central schema,
`--project=<id>` to one project), never prints a value, and records a `secrets.moved_to_region`
audit event with the counts. It is idempotent and resumable: a project whose region does not
answer, or that has no region cluster, keeps its values in the database and is reported; run the
command again later. It exits non-zero while values are left. Keep `APP_KEY` (and a copy of it)
until the upgrade is finalized — the values and copies in the database are encrypted with it. See
[Upgrade](upgrade.md#project-secrets-move-into-the-regions).

### Rolling back to 0.5.10

RunR 0.5.10 builds a project's `<slug>-secret-env` from `project_secrets.value` on every deploy: a
variable whose value is only in the region would be written there as an **empty string** once
0.5.10 runs again (`helm rollback`, or the previous chart installed again on the same database).
So an installation upgraded from 0.5.10 keeps, until the upgrade is **finalized**:

- an encrypted copy of every value RunR writes into a region or its OpenBao in
  `project_secrets.value`, marked `rollback_copy` — the column 0.5.10 reads. RunR itself never reads
  the copy while the region holds the value (reading back, deploys, region moves and exports read
  the region); setting a value again replaces the copy with it. The API shows `rollback_copy: true`
  on such a variable;
- `secrets:move-to-region` keeps the moved value as that copy instead of clearing it;
- references to secret stores wait: `PUT …/secrets/{name}` with a `reference` answers `409`
  (`reason: upgrade_not_finalized`), because 0.5.10 could not hand their values to a workload; an
  organization import lists such references as a manual step.

A copy that 0.5.10 changed after a rollback — it writes the value, never the fingerprint — no
longer matches the fingerprint: RunR treats it as a value in the database again (`stored_in:
database`), writes it into the region (or OpenBao) on the next deploy, and `secrets:move-to-region`
moves it as a new version. Variables created on 0.5.10 are moved like any value written before
pass-through.

**Finalizing** — once the upgrade is confirmed:

```sh
helm upgrade runr <chart> -n runr --reuse-values --set projectSecrets.finalize=true
# or
kubectl exec deploy/runr-api -- php artisan secrets:move-to-region --finalize [--dry-run]
```

moves what is still only in the database, checks every value in its region against its fingerprint
(writing it from the copy when the region lost it), drops the copies and records
`secrets.pass_through_finalized`. A region that does not answer leaves its projects' copies and the
installation unfinalized (exit 1): run it again. From then on no value reaches the database and
references can be set — and **0.5.10 cannot be installed again** without its deploys writing empty
values. An installation that never ran a release before pass-through (a fresh install) starts
finalized. Environments, secret stores and the region's OpenBao do not exist in 0.5.10: what they
hold stays in the regions and comes back with the next upgrade.

### API

| Method | Path | |
|--------|------|-|
| `GET` | `/api/projects/{project}/secrets` | the variables (below), `can_view_values`, `storage` (`region` or `database`) |
| `PUT` | `/api/projects/{project}/secrets/{name}` | create or replace: `{"value": "…"}`, or a reference `{"reference": {"store_id": "…", "path": "…", "key": "…"}}` (`key` optional) |
| `DELETE` | `/api/projects/{project}/secrets/{name}` | remove |
| `GET` | `/api/projects/{project}/secrets/{name}/value` | `name`, `value`, `matches_fingerprint` — requires `secrets.view_values` |
| `POST` | `/api/projects/{project}/secrets/refresh` | sync the project's references now and answer like `GET` ([Status](secret-stores.md#status)) |

A variable of the list:

| Field | |
|-------|-|
| `name`, `updated_at`, `updated_by` | the name, when and by whom it was last changed |
| `source` | `region` (a value in the region's Secret), `openbao` (a value in the region's OpenBao) or `external` (a reference) |
| `version` | how many times the value (or reference) was written |
| `stored_in` | values: `region`, `openbao`, or `database` for a value not moved yet |
| `rollback_copy` | values: an encrypted copy is kept in the database for a rollback to 0.5.10 until the upgrade is finalized ([Rolling back to 0.5.10](#rolling-back-to-0510)) |
| `fingerprint` | values: the first 12 hex digits of the keyed fingerprint |
| `reference` | references: `store_id`, `store_name`, `provider`, `path`, `key` |
| `sync` | references: `status` (`pending`, `synced`, `failed`), `message`, `synced_at` |

Errors of the secret endpoints carry `message` and `reason`: `region_unreachable` (503),
`value_missing` and `upgrade_not_finalized` (409), `no_cluster`, `eso_not_installed` and
`unsupported` (422).

Reading a value back requires the `secrets.view_values` permission; of the built-in roles only
Owner and Admin have it. Every read is
recorded in the audit log (`project.secret.reveal`).

The project resource names the secret variables as well: `config.secret_env_keys` (names only;
`null` for a user without `env.view`). `POST /api/projects` and `PUT /api/projects/{project}`
accept secret values in `config.secret_env_vars` — a name → value map where an empty value
removes the variable — so a client such as the `runr` CLI (`runr env set --secret`) changes plain
and secret variables in one request. The same rules apply as for the endpoints above (`env.edit`,
naming, limits, a region cluster for the values), and a name can move between plain and secret in a
single update: remove it on one side and set it on the other. The values never reach RunR's
database (with `storage: region`), the config, a version snapshot or the audit log.

## Key rotation

`APP_KEY` encrypts all secrets at rest. Laravel also tries the keys listed in `APP_PREVIOUS_KEYS`
(comma-separated) when decrypting, so a new key can be introduced without downtime:

1. Generate a new key: `php artisan key:generate --show`.
2. Set the new key as `APP_KEY` and the old one in `APP_PREVIOUS_KEYS`, then roll out. With the
   Helm chart (from BRF's public Helm repository, added as `brf-tech`; `--version` keeps the
   installed chart version):

   ```sh
   helm upgrade runr brf-tech/runr --version <installed version> --reuse-values \
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
   helm upgrade runr brf-tech/runr --version <installed version> --reuse-values --set secret.previousAppKeys=''
   ```

Sign-in sessions and API tokens are hashes, not encrypted values: they stay valid across a
rotation. Values Laravel encrypts on the fly (OAuth state, queued jobs in flight) decrypt with the
previous key during step 2.

## Runtime patch feed

RunR learns about [runtime patches](runtimes.md#runtime-patches) — which patch tags of the runtime
images exist, why each came and until when projects may stay on an older one — from **one signed
feed**: an OCI artifact, by default `ghcr.io/brf-tech/runr/runtime-patches:feed`, whose manifest
has one layer of the media type `application/vnd.runr.patch-feed.v1+json`, signed with cosign.
Nothing of a feed is used before its signature verified with the configured public key
(Helm value `runtimePatches.publicKey`, env `RUNR_PATCH_FEED_PUBLIC_KEY`). The default is the key
BRF-Tech/runr-runtimes signs the feed with (`keys/patch-feed.pub` there: cosign ECDSA P-256, DER
SHA-256 `24d79a49162ffec0b866ab7274a451a566f7e27fdb6804bffb6d9859d4b1c0df`), so the feed works
out of the box; a feed of your own takes your key. An empty key switches the feed off (status
`disabled`) and platform administrators see a banner saying so.

### Schema v1

```json
{
  "schema": 1,
  "generated_at": "2026-10-21T08:00:00Z",
  "patches": [
    {
      "id": "php-8.3-8.3.36-r1", "runtime": "php", "version": "8.3", "tag": "8.3.36-r1",
      "image": "ghcr.io/brf-tech/runr/exec-php", "digest": "sha256:…",
      "level": "zero-day",
      "reason": {"en": "Remote code execution in …", "tr": "… uzaktan kod çalıştırma"},
      "cves": ["CVE-2026-…"], "url": "https://…", "upstream": "PHP 8.3.36",
      "published_at": "2026-10-21T08:00:00Z", "deadline_at": "2026-10-24T08:00:00Z"
    }
  ],
  "platform": [
    {"version": "0.5.11", "level": "zero-day", "reason": {"en": "…", "tr": "…"}, "url": "https://…", "published_at": "2026-10-21T08:00:00Z"}
  ]
}
```

| Field | |
|---|---|
| `patches[].id` | unique; a project's pin names it |
| `runtime`, `version` | the runtime version of the catalog the patch belongs to (`php` `8.3`) |
| `tag`, `image`, `digest` | the immutable tag, the repository without tag, the digest workloads run by: the one the tag resolves to (for a multi-platform image the image index, so nodes that pre-pulled the floating tag already have it) |
| `level` | `routine`, `security`, `critical` or `zero-day` |
| `reason.en` (required), `reason.tr` | why the patch came, shown in the console in the user's language |
| `cves`, `url`, `upstream` | the CVEs it fixes, a link to the advisory, the upstream release |
| `published_at`, `deadline_at` | when it was published; the deadline of a critical / zero-day patch (none in the feed: 14 days for critical, 3 for zero-day after publication) |
| `platform[]` | RunR releases: platform administrators of an older installation see "RunR `version` available (`level`): `reason`" |

A feed with any error — a missing field, an unknown level, a malformed digest, a tag listed twice,
more than 4 MiB — is refused as a whole.

### How RunR reads it

1. Every five minutes (and on **Check now** of the [Patches page](runtimes.md#the-patches-page)),
   one `HEAD` of the feed's tag — read anonymously; a registry that answers 401 with a Bearer
   challenge gets a pull token first. An unchanged digest ends the check.
2. The manifest by that digest — its bytes must hash to it — and its feed layer, which must match
   its own digest.
3. The **signature**: cosign stores the signatures of a digest as the tag
   `sha256-<digest>.sig` of the same repository, an image manifest whose layers are "simple
   signing" payloads (`application/vnd.dev.cosign.simplesigning.v1+json`) with the base64
   signature in the annotation `dev.cosignproject.cosign/signature`. One payload is enough whose
   blob matches its digest, whose signature verifies with the public key (ECDSA P-256 / SHA-256,
   cosign's default, or RSA) and which names the feed's manifest digest
   (`critical.image.docker-manifest-digest`) as a `cosign container image signature`. The API
   verifies it itself (OpenSSL); no transparency-log entry is required — the key is the trust
   anchor.
4. The schema. Then the patches are stored, those the feed no longer lists leave it (and stay in
   the records), each runtime version gets the digest of its newest patch, and new patches are
   announced.

A feed whose signature does not verify (or that has none) is refused with the status
`invalid_signature`, one that fails the schema with `invalid_schema`: it is **ignored, the last
good feed stays in force**, and the platform administrators are told **once per refused digest**
(a critical notification, in-app and by mail, whatever their preferences; type
`runtime_patch_feed`). A registry that cannot be reached sets `unreachable` and changes nothing
else. `GET /api/admin/runtime-patches` and the Patches page show the status, the error, the last
check and the last good read.

### Offline installations

- `runtimePatches.viaRegion` (env `RUNR_PATCH_FEED_VIA_REGION`, a region id or name) reads the feed
  through that region's internal registry instead: the API reaches it through the Kubernetes API
  server, and its ghcr.io pull-through serves `ghcr.io/<repository>` (the region's registry needs
  that upstream; only ghcr.io feeds can be read this way).
- `runtimePatches.feed` points at a mirror in a registry the API reaches. Mirror the signature
  tag with the feed (`sha256-<digest>.sig`): `cosign copy` copies both, and a copy keeps the
  digest, so the signature still verifies.

### Publishing a feed

The feed of RunR's runtime images is published with them. A feed of your own (with your own key
in `runtimePatches.publicKey`):

```bash
cosign generate-key-pair                      # cosign.key (keep it secret), cosign.pub → publicKey
oras push registry.example.com/runr/runtime-patches:feed \
  feed.json:application/vnd.runr.patch-feed.v1+json
cosign sign --key cosign.key --tlog-upload=false \
  registry.example.com/runr/runtime-patches@sha256:<digest printed by oras>
```

cosign 3 stores signatures as OCI referrers in its new bundle format by default; sign with
`--new-bundle-format=false` so the signature is the `.sig` tag RunR reads.

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

In the console: **Administration → Audit log** — filters, a detail drawer with the full event and its
changes, CSV / JSON export, and the retention setting. From the CLI: `runr audit ls|get|export`
with the same filters ([CLI](cli.md#audit-log-runr-audit)).

CSV exports neutralize spreadsheet formulas (cells starting with `=`, `+`, `-`, `@` get a leading
`'`).

## Audited events

Recorded after the API answered, with the outcome of the request:

| Area | Actions |
|------|---------|
| Sign-in | `auth.login` (password, with the user name or the e-mail address; failures with the identifier tried), `auth.logout`, `auth.sso.login` (OAuth / OIDC / SAML), `user.register`, `user.signup.verify` (self-service signup), `user.signup.resend`, `invitation.accept`, `horizon.link.create` (a single-use sign-in link of the [Horizon dashboard](install.md#horizon-dashboard), with its expiry; never the link) |
| Account | `auth.password.change`, `auth.2fa.setup`, `auth.2fa.enable`, `auth.2fa.disable`, `auth.2fa.recovery_codes.regenerate`, `account.profile.update`, `account.avatar.update`, `account.avatar.remove`, `account.disable`, `account.delete`, `account.notification_preferences.update`, `session.revoke`, `session.revoke_all` |
| API tokens | `api_token.create`, `api_token.revoke` |
| Projects | `project.create`, `project.update` (changed fields, env var names), `project.delete`, `project.code.save`, `project.secret.set`, `project.secret.delete`, `project.secret.reveal`, `project.secret.refresh`, `project.owner.transfer` (new and previous owner), `project.stop` (reason; by the system with `system: cost_limit` when a project's [monthly cost limit](billing.md#monthly-cost-limit) stopped it), `project.resume`, `project.rightsizing.apply` (previous and recommended size, the version), `project.rightsizing.dismiss` (snooze days), `project.global.update` (mode, failover, primary, regions), `project.global.sync`, `project.global.failover` (target region; actor `system` when automatic), `project.global.rejoin` (system) |
| Secret stores ([details](secret-stores.md)) | `secret_store.create` (provider, auth), `secret_store.update`, `secret_store.delete`, `secret_store.verify` (region, status), `region.external_secrets.install`, `region.external_secrets.uninstall`, `region.openbao.install`, `region.openbao.update` (`default_for_values`), `region.openbao.uninstall` — settings changes with field names only |
| Workflows | `workflow.declare`, `workflow.archive`, `workflow.sync`, `workflow.update`, `workflow.app.update`, `workflow.signing_key.rotate`, `workflow.signing_key.reveal`, `workflow.run.start`, `workflow.run.cancel`, `workflow.run.retry`, `workflow.run.replay` |
| Deployments | `deployment.create`, `deployment.rollback` (a version older than the latest, or POST …/deployments/{deployment}/rollback), `deployment.delete`, `deployment.status.update`; [deploy strategies](deploy-strategies.md): `project.deploy_strategy.update`, `deployment.rollout.promote`, `deployment.rollout.abort`, `deployment.rollout.rollback`, and with the actor `system` `deployment.rollout.auto_promoted`, `deployment.rollout.auto_rolled_back`, `deployment.rollout.failed`, `deployment.rollout.released` |
| Environments | `environment.create`, `environment.update`, `environment.delete`, `environment.secret.set`, `environment.secret.delete`, `environment.promotion.request`, `environment.promotion.approve`, `environment.promotion.reject`, `environment.promotion.cancel`; recorded by the platform: `environment.promotion.deployed`, `environment.promotion.failed` |
| Triggers and topics ([details](triggers.md)) | `project.trigger.create` (type, name), `project.trigger.update` (field and secret names), `project.trigger.replace` (the names of the new list), `project.trigger.delete`, `project.trigger.event.replay`, `project.trigger.events.replay` (status filter, how many), `topic.create` (name, region), `topic.update`, `topic.delete`, `topic.publish` (event type and id) — never secret values or event bodies |
| Internal network ([details](internal-network.md)) | `project.network.update` (access mode, how many addresses), `project.network.caller.add`, `project.network.caller.remove` |
| Shell | `shell.session.request`, `shell.session.open`, `shell.session.close` (duration, reason) |
| Domains | `domain.create`, `domain.verify`, `domain.tls.update`, `domain.delete` |
| Gateways | `gateway.create`, `gateway.update`, `gateway.route.create`, `gateway.route.update`, `gateway.route.delete`, `gateway.domain.request`, `gateway.domain.verify`, `gateway.domain.remove` |
| API product ([api-product.md](api-product.md)) | `api_consumer.create`, `api_consumer.update`, `api_consumer.delete`, `api_key.create`, `api_key.update`, `api_key.rotate`, `api_key.revoke` (the key's id and prefix, never the key), `api_plan.create`, `api_plan.update`, `api_plan.delete` |
| Private databases | `private_connection.create`, `private_connection.delete`, `region.skupper.site.ensure` |
| Bindings | `binding.create`, `binding.update`, `binding.delete`, `binding.restore`, `binding.credentials.rotate`, `binding.credentials.reveal`, `binding.attach`, `binding.attachment.update`, `binding.detach`, `region.data_service.install`, `region.data_service.uninstall`, `tenant.binding_quota.update`, `binding.purged` (system, after the retention) |
| Regions | `region.create`, `region.update`, `region.delete`, `region.kubeconfig.download`, `region.settings.update`, `region.monitoring.update`, `region.capacity.update`, `region.tls.update`, `region.tls.remove`, `region.knative.*`, `region.components.detect`, `region.workers.update`, `region.redis.test`, `region.redis.configure`, `region.redis.remove`, `region.sandbox.install`, `region.sandbox.uninstall`, `region.sandbox.mapping.update`, `region.wasm.install`, `region.wasm.uninstall`, `region.cert_manager.install`, `region.cert_manager.uninstall`, `region.migration.start`, `region.migration.complete`, `region.migration.rollback`, `region.migration.domain.complete`, `region.registry.setup`, `region.registry.remove`, `region.demo.create`, `region.demo.remove`, `registry.setup.start`, `registry.setup.retry`, `region.global_dns.enable`, `region.global_dns.disable`, `region.global_dns.geoip` (source, URL), `region.global_dns.geoip_reload`, `global_dns.sync` |
| Runtimes | `runtime.create`, `runtime.update`, `runtime.delete`, `runtime.build`, `runtime.test_deploy` |
| Runtime patches ([details](runtimes.md#runtime-patches)) | `runtime_patch.apply` (patch, migration), `runtime_patch.pin` (patch, whether it redeploys), `runtime_patch.unpin`, `runtime_patch.feed.check` (status, digest), `runtime_patch.policy.update` (automatic migration on or off), `runtime_patch.remind` (users told), `runtime_patch.force` (migrations started), `runtime_patch.deadline.update` (the new deadline); recorded where they happen: `runtime_patch.migrate` (RunR at a deadline — actor `system` — or a platform administrator's force: the patch, the deadline's patch), `runtime_patch.rollback` (system: the digest the project went back to, the reason) |
| GPUs and models ([AI](ai.md), [Hugging Face models](huggingface.md)) | `region.gpu.install`, `region.gpu.uninstall`, `region.gpu.settings.update`, `region.model_storage.update`, `tenant.gpu_quota.update`, `model.hub_token.set`, `model.hub_token.remove`, `model.create`, `model.update`, `model.delete`, `model.snapshot.create`, `model.snapshot.delete`, `model.upload.start`, `model.upload.complete`, `model.upload.abort`, `model_service.create`, `model_service.update`, `model_service.delete`, `model_service.deploy`, `model_service.stop`, `project.model.bind`, `project.model.binding.update`, `project.model.unbind` |
| Uploaded images ([details](images.md#audit)) | `image.upload.start`, `image.upload.complete`, `image.upload.abort`, `image.delete` (registry manifest removed or not, what still used it); recorded where they happen: `image.upload.pushed` (digest, kind, size, the digest it replaced), `image.upload.failed` (the region's reason) |
| Sandboxes | `sandbox.create`, `sandbox.delete`, `sandbox.expire` (reaper), `sandbox.exec` (command line), `sandbox.run`, `sandbox.terminal.request`, `sandbox.file.upload`, `sandbox.file.download`, `sandbox.file.delete`, `sandbox.file.mkdir`, `sandbox.process.start`, `sandbox.process.kill`, `sandbox.timeout.update`, `sandbox.network.update`, `sandbox.pause`, `sandbox.resume`, `sandbox.region.update`, `sandbox.pool.create` / `.update` / `.delete`, `sandbox.quota.update` / `.reset` ([Sandbox API](sandbox-api.md#audit)) |
| Supply chain ([details](supply-chain.md#audit)) | `region.supply_chain.update`, `region.signing_key.request`, `region.signing_key.retired_remove`, `region.scanner_db.refresh`, `region.admission.install`, `region.admission.remove`, `security.scan.request`, `tenant.residency.update`; recorded where they happen: `deploy.policy_blocked`, `deploy.policy_warned`, `deploy.policy_overridden`, `residency.denied`, `residency.migration_denied`, `image.signed`, `region.signing_key.generated` / `.rotated` / `.failed`, `region.admission.installed` / `.removed` / `.failed`, `region.scanner_db.refreshed` |
| Users | `user.create`, `user.update`, `user.delete`, `user.2fa.reset`, `user.admin.toggle` |
| Tenants & members | `tenant.create`, `tenant.update`, `tenant.delete`, `tenant.switch`, `tenant.region.assign`, `tenant.region.quota.update`, `tenant.region.remove`, `member.add`, `member.invite`, `member.update` (role), `member.remove`, `member.2fa.reset`, `invitation.resend`, `invitation.cancel` |
| Impersonation | `impersonation.start`, `impersonation.stop`; events in between name the administrator as impersonator |
| Roles & access | `role.create`, `role.update`, `role.delete`, `role.default.update`, `group.create`, `group.update`, `group.delete`, `group.member.add`, `group.member.remove`, `member.role.update`, `project.access.grant`, `project.access.revoke`, `sso.group_mapping.create` / `.update` / `.delete` |
| White-label | `branding.update`, `branding.asset.upload` / `.delete`, `tenant.branding.update`, `tenant.branding.asset.upload` / `.delete`, `signup.settings.update`, `tenant.onboarding.complete` — settings changes with field names only, never secret values |
| Git deploy ([details](git-deploy.md#security)) | `git.settings.update`, `git.redeploy`, `git.repo.connect`, `git.repo.disconnect`, `git.preview.redeploy`, `git.preview.delete`, `git_provider.create` / `.update` / `.delete`; recorded where they happen: `git.push` (pushes to the RunR git remote, refused pushes as `denied`), `git.installation.link`, `git.webhook.rejected` |
| AI agents ([details](mcp.md#audit-and-limits)) | recorded where they happen: `mcp.session.start`, `mcp.session.end`, `mcp.tool.call` (tool, target, argument names — never values — result; the API actions a tool performs carry `auth_method` `mcp:<token id>` and `metadata.mcp_tool`), `oauth.client.register`, `oauth.token.issue`, `oauth.token.revoke`, `mcp.server.call` (hosted servers with **Audit calls** on); routes: `oauth.consent.approve`, `oauth.consent.deny`, `mcp.client.revoke`, `mcp_server.create`, `mcp_server.update`, `mcp_server.delete`, `mcp_server.key.rotate`, `mcp_server.inspect` |
| Settings | `settings.update`, `audit.settings.update` |
| SSO providers | `auth_provider.create`, `auth_provider.update`, `auth_provider.delete`, `auth_provider.toggle`, `social_account.link`, `social_account.unlink` |
| Billing ([details](billing.md#permissions)) | `billing.settings.update` (field names only), `billing.price.create` (region, effective date) / `.delete`, `billing.region.update` (billing type, queue consumer charging), `billing.region.queue_consumers.update` (the organization's own choice), `billing.region.organization.update` (the platform's choice for a tenant: tenant, charge), `billing.markup.create` (region, percent, effective date) / `.delete`, `billing.region_offer.accept` (markup), `billing.bill.payment_url.update`, `billing.bill.paid.update` (paid, date, reference), `billing.tenant_bill.payment_url.update`, `billing.tenant_bill.paid.update`, `billing.fixed_request.create` (resources) / `.cancel` / `.approve` / `.reject` (reason), `billing.cost_limit.update` (amount, action) / `.delete` / `.resume`, `billing.cost_limit.reached` (by the system: month, amount, month to date, action, whether it stopped the project), `billing.event.test`, `billing.event.redeliver` |
| Support | `ticket.status.update`, `ticket.assign`, `ticket.tags.update`, `ticket.admin_reply` |
| Observability | `region.tracing.update`, `region.tracing.install`, `region.tracing.uninstall`, `project.tracing.update`, `project.replay.update`, `replay.replay`, `replay.delete`, `replay.delete_all`, `uptime_check.create` / `.update` / `.delete` / `.run`, `uptime_incident.update`, `load_test.start` / `.cancel`, `status_page.update`, `alert_channel.create` / `.update` / `.delete` / `.test`, `alert_rule.create` / `.update` / `.delete`, `alert_delivery.retry` |
| Demo | `demo.seed`, `demo.remove` |
| Authorization | `authorization.denied` — any other API request answered 403 |
| System | `audit.export`, `audit.retention.pruned`, `secrets.reencrypted`, `secrets.moved_to_region` (values moved, already there, projects, copies kept for a rollback, skipped, failed, left in the database), `secrets.pass_through_finalized` (the same counts and the copies dropped) |

## Retention

A daily job (03:30, scheduler) deletes events older than the retention period and records an
`audit.retention.pruned` event with the number of deleted events. The default is 365 days
(`AUDIT_RETENTION_DAYS`, Helm value `audit.retentionDays`); **Administration → Audit log**
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
| `env.edit` | Owner, Admin, Developer | set, replace and remove secret variables, values and references; sync references now (`POST …/secrets/refresh`) |
| `secrets.view_values` | Owner, Admin | read a secret value back (project scope: a project role that includes it) |
| `secret_stores.view` | Owner, Admin, Developer | the organization's secret stores and the trust they need |
| `secret_stores.manage` | Owner, Admin | register, change, verify and remove secret stores |
| `regions.manage` | Owner, Admin | install and remove External Secrets Operator in a region |
| `audit.view` | Owner, Admin | the audit log of the organization |
| `audit.export` | Owner, Admin | CSV / JSON export |

Custom roles can include any of them. Platform administrators pass every check (unless their API
token is scoped). Inside a tenant the audit log shows that tenant's events only; outside a tenant
(multi-tenant edition) only platform administrators see platform and cross-tenant events. The
retention period is install-wide: `settings.manage` changes it in the single-tenant edition, a
platform administrator outside any tenant in the multi-tenant edition.

## Region network isolation

All projects of a region — every organization's on a shared region — run in its project
namespace. Two things keep a project's service from callers it is not meant for
([regions.md](regions.md#cluster-local-services-and-network-isolation)):

- **Cluster-local services stay inside the cluster.** Queue-trigger sinks and model services are
  cluster-local Knative Services, and every Knative Service has the cluster-local hosts
  `<service>.<namespace>[.svc[.cluster.local]]`. The region Traefik serves these only on its
  private entry point, which the ClusterIP Service `traefik-internal` publishes and the
  LoadBalancer does not: through the region's external address such a host answers `404`.
  Custom domains, gateway routes and a base-domain migration's second address hand requests on
  to a project's public host, so they reach public HTTP projects only.
- **Pods are reached through the route only.** The NetworkPolicy `runr-knative-revisions` admits
  traffic to revision pods only from the Serving namespace's Traefik, activator and autoscaler, and
  `runr-activator` admits the activator's proxy ports only from Traefik and the autoscaler. A pod
  cannot call another project's pods by their address, their revision Services or the activator
  with Knative's routing headers; it calls a service at its cluster-local URL, through Traefik.

- **Projects call each other only when the callee allows it.** Every call on the private entry
  point passes a ForwardAuth (the region agent in authz mode, `runr-internal-auth`): the caller —
  the project whose pod the call comes from, or the project a valid `RUNR_INTERNAL_TOKEN` names
  (its project's name signed with the region's key) — has to be on the callee's allow list, also
  inside one organization. Another organization's project needs a grant, which the platform can
  switch off. The callee gets the verified caller in `X-Runr-Caller`, never the token
  ([internal-network.md](internal-network.md)).
- **Access modes** keep a project off the public internet (`internal`: its Knative Service is
  cluster-local) or answer only the addresses of an IP allow list (`allowlist`), on its public
  host, custom domains and gateway route alike.

Earlier releases served cluster-local hosts on the LoadBalancer's entry point and had no
NetworkPolicy; `region:reconcile` moves existing regions. The NetworkPolicies take effect where the
cluster's network plugin enforces NetworkPolicies. A region of an earlier release gets the
internal network enforcing, like a new one: calls between projects need an allow-list entry from
then on; `audit` (admit and log) is an operator's explicit choice ([Enforce and
audit](internal-network.md#enforce-and-audit)).

The token is a secret of the project: it travels in the project's Secret `<project>-secret-env`
and the workloads read it with `secretKeyRef`. It does not expire; it changes with `APP_KEY`
([Key rotation](#key-rotation): run `region:reconcile` and redeploy the projects afterwards).
