# RunR — Production Install Guide (Docker Compose)

Single-host installation with Docker Compose, including air-gapped environments. The images are
built once from a release checkout (`compose/build.sh`) into one archive; the host that runs RunR
loads this prebuilt archive and needs no internet access.

---

## Requirements

- Docker Engine 24+
- Docker Compose v2
- At least 4 GB RAM, 2 CPUs
- For HTTPS (section 7): a DNS name for the console and [Caddy 2](https://caddyserver.com/docs/install)
  on the host

---

## 1. Choose the modules

On a machine with internet access, check out the release you install and create the Compose
settings file `.env`:

```bash
git clone --branch v0.5.10 https://github.com/BRF-Tech/runr.git
cd runr
cp compose/.env.example compose/.env
```

The optional modules are switched on and off in `compose/.env`. The UI is built with the modules
selected here and the API containers enable the same ones, so set them before you build; after a
change, build and load the images again.

| Variable | Module | Default |
|----------|--------|---------|
| `TENANCY_ENABLED` | Multi-tenancy | `false` |
| `AUTH_PROVIDER_ENABLED` | Sign-in through OAuth / OpenID Connect / SAML providers (SSO) | `false` |
| `TICKET_ENABLED` | Support tickets | `true` |
| `BILLING_ENABLED` | Billing: monthly bills per project for an external invoicing system ([billing](../docs/billing.md)); currency and webhook in `api.env` | `false` |

An environment variable of the same name takes precedence over `.env`, for the build as for
`docker compose`.

---

## 2. Build and load the images

Build all images into one archive:

```bash
REVERB_KEY=<your reverb key> bash compose/build.sh   # → compose/runr-images.tar
```

`REVERB_KEY` is baked into the UI build and must match `REVERB_APP_KEY` in `api.env` (default `runr-prod-key`).

Built on the target machine itself, the images are already in its Docker: continue in the
`compose/` directory with step 3. For another machine (an air-gapped host, for example), copy the
`compose/` directory — `docker-compose.yml`, `Caddyfile`, `.env`, `api.env.example`, the new
`runr-images.tar` and, for HTTPS, `Caddyfile-host` and `Caddyfile-wildcard` — to it and load the
images there:

```bash
docker load -i runr-images.tar
```

Verify the loaded images:

```bash
docker images | grep -E "runr-|postgres|redis"
```

Expected output:

```
runr-api                         latest        ...
runr-ui                          latest        ...
ghcr.io/brf-tech/runr/reqs/postgres     17-alpine     ...
ghcr.io/brf-tech/runr/reqs/redis        7-alpine      ...
```

---

## 3. Configure the environment

In the `compose/` directory (the `docker compose` commands below run there, too):

```bash
cp api.env.example api.env
```

### `.env` — Compose-level settings

| Variable | Description | Default |
|----------|-------------|---------|
| `PORT` | Port the gateway publishes on the host; `127.0.0.1:8080` publishes it on the loopback interface only (HTTPS, section 7) | `80` |
| `DB_NAME` | PostgreSQL database name | `runr` |
| `DB_USER` | PostgreSQL user | `runr` |
| `DB_PASS` | PostgreSQL password | **Change it!** |
| `TENANCY_ENABLED`, `AUTH_PROVIDER_ENABLED`, `TICKET_ENABLED`, `BILLING_ENABLED` | Modules (step 1); the values the images were built with | |

### `api.env` — Laravel application settings

Fields you must change:

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Laravel encryption key (generated below) |
| `APP_URL` | URL the console is reached at |
| `FRONTEND_URL` | Same as `APP_URL` |
| `SANCTUM_STATEFUL_DOMAINS` | Host of `APP_URL`, with the port when it is not the scheme's default |
| `DB_PASSWORD` | Must be **the same** as `DB_PASS` in `.env` |
| `REVERB_APP_KEY` | WebSocket key (random string; the same value as `REVERB_KEY` at build time) |
| `REVERB_APP_SECRET` | WebSocket secret (random string) |

The first administrator account (step 5):

| Variable | Description | Default |
|----------|-------------|---------|
| `ADMIN_USERNAME` | User name | `admin` |
| `ADMIN_EMAIL` | E-mail address | `admin@example.com` |
| `ADMIN_PASSWORD` | Password; empty: the migrator generates one and prints it once | empty |

Billing (only read with `BILLING_ENABLED=true` in `.env`, [billing](../docs/billing.md)):

| Variable | Description | Default |
|----------|-------------|---------|
| `BILLING_CURRENCY` | ISO 4217 currency of every price and bill | `USD` |
| `BILLING_WEBHOOK_URL` | Where billing events are POSTed for your invoicing system; empty: they are kept for `GET /api/billing/events` only | empty |
| `BILLING_WEBHOOK_SECRET` | Signing key of the `X-RunR-Signature` header (at least 16 characters) | empty |

For a host with the address `10.0.0.10` and the default `PORT=80`:

```
APP_URL=http://10.0.0.10
FRONTEND_URL=http://10.0.0.10
SANCTUM_STATEFUL_DOMAINS=10.0.0.10
```

With `PORT=8080` they are `http://10.0.0.10:8080` and `10.0.0.10:8080`. The values for HTTPS are
in section 7.

---

## 4. Generate APP_KEY

```bash
docker compose run --rm api php artisan key:generate --show
```

Paste the printed `base64:...` value into the `APP_KEY=` line of `api.env`.

---

## 5. Start the services and create the administrator

```bash
docker compose up -d
```

On the first start, run the migrator:

```bash
docker compose run --rm migrator
```

It migrates the database and, while the installation has no administrator, creates the first one
from `ADMIN_USERNAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD`. With an empty `ADMIN_PASSWORD` it
generates a password and prints it at the end of its output, only this once:

```
Administrator account created: admin (admin@example.com)
Password (generated, shown only this once): <password>
```

The migrator runs after every update (see [Updating](#updating)); once an administrator exists it
leaves the accounts and their passwords alone.

Check the service status:

```bash
docker compose ps
```

All services should be `running (healthy)`.

---

## 6. Access

Open `APP_URL` in a browser — with the example above `http://10.0.0.10` — and sign in with the
administrator account.

---

## 7. HTTPS

A Caddy on the host terminates TLS and forwards the requests to the gateway, which then listens on
the loopback interface only. Caddy obtains a Let's Encrypt certificate for the console domain
automatically, or serves a certificate you provide (internal CA, air-gapped host). The example
uses `runr.example.com` for the console and `fn.example.com` as the function domain of a region.
A region's function domain is `<region name>.<base domain>` (the base domain is set in
**Settings → Platform → Base Domain**): `fn.example.com` is a region `fn` with the base domain
`example.com`.

1. **DNS**: an `A` record for `runr.example.com` pointing at the host. For the function domain of a
   region on this host (step 5 below), a wildcard record `*.fn.example.com` pointing at the host,
   too.

2. **`.env`**: the gateway on the loopback interface, where Caddy reaches it:

   ```
   PORT=127.0.0.1:8080
   ```

3. **`api.env`**: the console URL, and two proxies in front of the API (Caddy and the gateway), so
   the API records the client's address; the session cookie of the
   [Horizon dashboard](#horizon-dashboard) is then sent over HTTPS only:

   ```
   APP_URL=https://runr.example.com
   FRONTEND_URL=https://runr.example.com
   SANCTUM_STATEFUL_DOMAINS=runr.example.com
   TRUSTED_PROXY_HOPS=2
   SESSION_SECURE_COOKIE=true
   ```

   Apply both files: `docker compose up -d --force-recreate`.

4. **Console certificate**: in `Caddyfile-host`, replace `runr.example.com` with your domain. For
   Let's Encrypt nothing else is needed: the domain must resolve to the host and ports 80 and 443
   must be reachable from the internet. For your own certificate, uncomment the `tls` line and set
   the paths of the certificate chain and its private key.

5. **Function domain** — for a region whose ingress only this host reaches, such as a kind cluster
   created with `compose/kind-setup.sh`. In `Caddyfile-wildcard`:
   - replace `*.fn.example.com` with your wildcard domain;
   - set the `tls` line to a wildcard certificate for it. Let's Encrypt issues wildcard
     certificates only through the DNS challenge, with a Caddy built with your DNS provider's
     module ([DNS challenge](https://caddyserver.com/docs/automatic-https#dns-challenge));
   - replace `172.19.0.5` with the address of the region's Traefik load balancer: the IP address
     the region page lists under **DNS Configuration**, or

     ```bash
     kubectl --context kind-runr -n knative-serving get service traefik \
       -o jsonpath='{.status.loadBalancer.ingress[0].ip}'
     ```

   The wildcard domain is the region's function domain, the one the region page lists under
   **DNS Configuration**; projects are then served at `https://<project>.fn.example.com`.

   A region in another cluster serves its function domain through that cluster's ingress: point
   the wildcard record at it and give the region its certificate under **Function-domain TLS** on
   the region page.

6. **Install the configuration** on the host and reload Caddy:

   ```bash
   sudo cp Caddyfile-host /etc/caddy/Caddyfile                                        # console
   cat Caddyfile-host Caddyfile-wildcard | sudo tee /etc/caddy/Caddyfile >/dev/null   # console + function domain
   sudo systemctl reload caddy
   ```

7. **Check**: `curl -s https://runr.example.com/api/version` prints the version, and the console
   opens at `https://runr.example.com`.

With the Auth Provider module, the callback URL to register with an OAuth or OpenID Connect
provider is `https://runr.example.com/oauth/<provider>/callback`.

---

## Service architecture

```
                    ┌─────────────────┐
                    │   Gateway :80   │
                    │  (Caddy + SPA)  │
                    └──────┬──────────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
   /api/* /mcp /git/*    /ws/*       /* (SPA)
   /oauth/*/callback       │
   /horizon /storage/*     │
              │            │
        ┌─────▼──────┐ ┌───▼────┐
        │    API     │ │ Reverb │
        │  (Octane)  │ │  (WS)  │
        └─────┬──────┘ └────────┘
              │
     ┌────────┼────────┐
     │        │        │
┌────▼───┐ ┌──▼───┐ ┌──▼───────┐
│Horizon │ │Redis │ │PostgreSQL│
│(Queue) │ │      │ │          │
└────────┘ └──────┘ └──────────┘
     │
┌────▼─────┐
│Scheduler │
│ (Cron)   │
└──────────┘
```

---

## Common tasks

### View logs

```bash
docker compose logs -f           # all
docker compose logs -f api       # API only
docker compose logs -f horizon   # queue worker only
```

### Run migrations (after an update)

```bash
docker compose run --rm migrator
```

### Reset the database (WARNING: deletes all data)

```bash
docker compose run --rm -e CONTAINER_ROLE=migrator-fresh migrator
```

The reset database gets a new first administrator from `api.env` (step 5).

### Restart Horizon (after queue configuration changes)

```bash
docker compose restart horizon
```

### Horizon dashboard

Horizon (`<APP_URL>/horizon`, served by the API through the gateway) shows the queues of the whole
installation — running, failed and completed jobs — and retries or deletes jobs. It is open to the
people who operate the installation: platform administrators (multi-tenant edition) and, in the
single-tenant edition, the organization's Owners. The console signs in with API tokens, which a
browser does not send to `/horizon`, so such an administrator opens the dashboard with a
single-use sign-in link:

| From | Command |
| --- | --- |
| The host | `docker compose exec api php artisan runr:horizon-link` (`--user=<name or e-mail>`, default the oldest administrator; `--minutes=<1–60>`, default 5) |
| The API | `curl -X POST https://runr.example.com/api/horizon/link -H "Authorization: Bearer $TOKEN"` with a console session or an unscoped API token of the administrator; the answer is `{"url": …, "expires_at": …}` |

The command builds the link from `APP_URL`. Opening the link signs the browser in to Horizon: the
link works once and only until it expires, and every later Horizon request checks the account
again — removing the administrator flag or the Owner role, or disabling the account, ends the
access at once. Behind HTTPS (section 7) `SESSION_SECURE_COOKIE=true` sends the dashboard's
session cookie over HTTPS only. `POST /api/horizon/link` is recorded in the audit log as
`horizon.link.create`; the link itself is never logged.

### Stop the services

```bash
docker compose down            # stop (data is kept)
docker compose down -v         # stop + delete volumes (WARNING!)
```

---

## Updating

1. Back up the database:
   ```bash
   docker compose exec -T postgres pg_dump -U runr runr > runr-backup.sql
   ```
2. Build the images of the new release (step 2, with the same module toggles in `compose/.env`),
   copy the new `runr-images.tar` to the machine and load the images:
   ```bash
   docker load -i runr-images.tar
   ```
3. Take `docker-compose.yml` and the `Caddyfile` of the new release (new routes such as
   `/oauth/<provider>/callback` and `/horizon` go through the gateway to the API); keep your `.env`
   and `api.env`. `APP_KEY` must stay the same: environment variables and other secrets are stored
   encrypted with it. Settings that are new in `api.env.example` take their defaults until you add
   them to `api.env` — for example `ADMIN_USERNAME`, `ADMIN_EMAIL` and `ADMIN_PASSWORD` (the first
   administrator, created only while the installation has none, step 5), and behind the HTTPS
   proxy `TRUSTED_PROXY_HOPS=2` and `SESSION_SECURE_COOKIE=true` (section 7). The
   [upgrade notes](../docs/upgrade.md) list what each release changes.
4. Stop the application, run the migrations with the new image, start the new release:
   ```bash
   docker compose stop gateway api horizon scheduler reverb
   docker compose run --rm migrator
   docker compose up -d
   ```
   The migrator also migrates the tenant schemas when `TENANCY_ENABLED=true`, so the new release
   never serves requests against an old schema.
5. Bring the existing regions up to the new release (their Knative and Traefik settings, the
   Traefik image, the registry sync; idempotent, `--dry-run` shows the changes first):
   ```bash
   docker compose exec api php artisan region:reconcile
   ```

### Upgrading from 0.4.x

- **Access**: every existing user keeps what they could do. The administrator account becomes Owner,
  every other user of a single-tenant install becomes Admin; tenant members keep their tenant role
  (`member` becomes a custom Member role with the same permissions); API tokens keep working, tokens
  with 0.4.x scopes get the matching permissions. Details: [Roles and permissions](../docs/permissions.md#upgrade-from-04x).
- **Secrets at rest**: the migrations encrypt the stored environment variables (and the other secret
  columns) with `APP_KEY` — see [Security](../docs/security.md). Keep a copy of `APP_KEY` with your
  database backups.
- **Real-time clients**: every broadcast channel is private now; custom WebSocket clients authorize
  through `POST /api/broadcasting/auth` ([Security](../docs/security.md)).

`bash runtime/ci.sh test-upgrade` (add `--tenancy` for a multi-tenant install) runs this procedure
from the previous release (`--from=REF`, default `main`) on PostgreSQL: it creates an administrator,
members, projects with environment variables and a code version, API tokens and settings, upgrades
with the images of the checkout and checks sign-in, access, tokens, the encryption at rest and the
settings.

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| API health check fails | `docker compose logs api`; usually `APP_KEY` is empty |
| Database connection error | Check that the DB passwords in `.env` and `api.env` match |
| WebSocket does not connect | `REVERB_APP_KEY` in `api.env` must equal the `REVERB_KEY` used at build time |
| 502 Bad Gateway | The API container is still starting; wait 30 s or check the logs |
| Port conflict | Change `PORT` in `.env` |
| A module's pages are missing in the console | The UI was built with other module toggles: build and load the images again (step 2) |
| The migrator prints no administrator password | An administrator already exists, or `ADMIN_EMAIL` belongs to another account (the migrator says which) |
