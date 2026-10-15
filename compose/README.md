# RunR — Production Install Guide (Docker Compose)

Single-host installation with Docker Compose, including air-gapped environments.

---

## Requirements

- Docker Engine 24+
- Docker Compose v2
- At least 4 GB RAM, 2 CPUs

---

## 1. Build and load the images

On a machine with internet access, build all images into one archive:

```bash
REVERB_KEY=<your reverb key> bash compose/build.sh   # → compose/runr-images.tar
```

`REVERB_KEY` is baked into the UI build and must match `REVERB_APP_KEY` in `api.env` (default `runr-prod-key`).

Copy `runr-images.tar` to the target machine and load it:

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
ghcr.io/runrsh/reqs/postgres     17-alpine     ...
ghcr.io/runrsh/reqs/redis        7-alpine      ...
```

---

## 2. Configure the environment

```bash
cp .env.example .env
cp api.env.example api.env
```

### `.env` — Compose-level settings

| Variable | Description | Default |
|----------|-------------|---------|
| `PORT` | Port the gateway publishes | `80` |
| `DB_NAME` | PostgreSQL database name | `runr` |
| `DB_USER` | PostgreSQL user | `runr` |
| `DB_PASS` | PostgreSQL password | **Change it!** |
| `TENANCY_ENABLED` | Multi-tenancy module | `false` |
| `AUTH_PROVIDER_ENABLED` | OAuth/SSO module | `false` |
| `TICKET_ENABLED` | Support ticket module | `false` |

### `api.env` — Laravel application settings

Fields you must change:

| Variable | Description |
|----------|-------------|
| `APP_KEY` | Laravel encryption key (generated below) |
| `APP_URL` | URL the application is reached at (`http://<server-ip>` or `https://example.com`) |
| `DB_PASSWORD` | Must be **the same** as `DB_PASS` in `.env` |
| `REVERB_APP_KEY` | WebSocket key (random string; the same value as `REVERB_KEY` at build time) |
| `REVERB_APP_SECRET` | WebSocket secret (random string) |
| `FRONTEND_URL` | Same as `APP_URL` |
| `SANCTUM_STATEFUL_DOMAINS` | Domain or IP including the port, e.g. `10.0.0.10:8080` |

---

## 3. Generate APP_KEY

```bash
docker compose run --rm api php artisan key:generate --show
```

Paste the printed `base64:...` value into the `APP_KEY=` line of `api.env`.

---

## 4. Start the services

```bash
docker compose up -d
```

On the first start, run the database migrations:

```bash
docker compose run --rm migrator
```

Check the service status:

```bash
docker compose ps
```

All services should be `running (healthy)`.

---

## 5. Access

In a browser:

```
http://<server-ip>:<PORT>
```

With the default port `80`, `http://<server-ip>` is enough.

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
   /api/* /oauth/*       /ws/*       /* (SPA)
   /storage/*              │
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

### Restart Horizon (after queue configuration changes)

```bash
docker compose restart horizon
```

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
2. Copy the new `runr-images.tar` to the machine and load the images:
   ```bash
   docker load -i runr-images.tar
   ```
3. Take `docker-compose.yml` and the `Caddyfile` of the new release (new routes such as `/git/*` and
   `/mcp` go through the gateway); keep your `.env` and `api.env`. `APP_KEY` must stay the same:
   environment variables and other secrets are stored encrypted with it.
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
