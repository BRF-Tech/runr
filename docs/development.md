# Development Setup

There are two ways to run RunR locally: **Docker Compose** (recommended) and **Valet** (native on Ubuntu/WSL).

---

## Docker Compose

The only requirement is Docker Desktop, or Docker Engine with the Compose plugin.

### Setup

```bash
bash scripts/devready.sh docker
```

The script:
1. Checks that Docker and Compose are installed
2. Creates the root `.env` from `.env.example` and generates `APP_KEY` in `api/.env.docker`
3. Builds and starts the containers
4. Runs the database migrations and seeders

### Manual setup

```bash
# 1. Create the root .env
cp .env.example .env

# 2. Generate APP_KEY and write it to the APP_KEY= line of api/.env.docker
docker compose run --rm --no-deps api php artisan key:generate --show

# 3. Start the containers
docker compose up --build -d

# 4. Prepare the database
docker compose exec api php artisan migrate --seed
```

The `api`, `horizon`, `scheduler` and `reverb` services also join the external `kind` Docker network so they
can reach a local Kind cluster. Kind creates this network with its first cluster; without Kind, create it once:

```bash
docker network create kind
```

### Quick start

```bash
docker compose up -d --build
docker compose run --rm migrator          # migrations + seed
docker compose run --rm migrator-fresh    # from scratch (migrate:fresh --seed)
```

### Services

| Service      | Port      | Description                  |
|--------------|-----------|------------------------------|
| Vue (Vite)   | 8061      | Frontend dev server          |
| API (PHP)    | 8060      | Laravel API                  |
| Reverb       | 8064      | WebSocket (Pusher protocol)  |
| PostgreSQL   | 8062      | Database                     |
| Redis        | 8063      | Cache + queue                |
| Mailpit      | 8065/8066 | SMTP (8065) / web UI (8066)  |
| Sentry proxy | 8067      | Sentry tunnel (`proxy/`, Go) |

### Feature toggles

Variables in the root `.env` switch the optional modules on and off for the whole stack:

```env
TENANCY_ENABLED=true          # Multi-tenant mode (tenant management, isolation, ...)
TENANCY_ENABLED=false         # Single tenant (the Tenancy module is fully disabled)
AUTH_PROVIDER_ENABLED=true    # External auth providers (AuthProvider module)
TICKET_ENABLED=true           # Support tickets (Ticket module)
```

Restart the containers after changing them:

```bash
docker compose down && docker compose up -d --build
```

**What they do:**
- **API**: `docker/entrypoint.sh` runs `php artisan module:enable|disable Tenancy` (and `AuthProvider`, `Ticket`) based on the value
- **Vue**: passed in as `VITE_TENANCY_ENABLED`, `VITE_AUTH_PROVIDER_ENABLED` and `VITE_TICKET_ENABLED`; a disabled module is left out of the build
- `docker-compose.yml` passes `TENANCY_ENABLED` (default `true`) and `AUTH_PROVIDER_ENABLED` (default `false`)
  to every API container (api, horizon, scheduler, reverb, migrator). `TICKET_ENABLED` reaches only the `vue`
  service (`VITE_TICKET_ENABLED`, default `false`); the API containers keep the entrypoint default and enable the
  Ticket module

### Changing ports

Edit the root `.env`, or override on the command line:

```bash
API_PORT=9000 VUE_PORT=9001 docker compose up -d
```

### Migrations

```bash
# After adding a new migration (incremental)
docker compose run --rm migrator

# Reset the database (migrate:fresh --seed)
docker compose run --rm migrator-fresh
```

The migrator also runs the module migrations (`Modules/*/Database/Migrations`) and, with tenancy enabled,
`php artisan tenancy:migrate` for existing tenant schemas.

Model docblocks (`@property` lines) come from `php artisan ide-helper:models --write --reset`; run it
after a schema change. To have every `migrate` run it, set `IDE_HELPER_POST_MIGRATE=true` in
`api/.env.docker` (or `api/.env`); the hook needs `APP_ENV=local` as well. Without the flag a migrate
leaves the models alone, so the migrator, test containers and CI never rewrite them in the mounted
checkout.

### Code changes and reloading

- **API**: runs on Laravel Octane (FrankenPHP workers), so workers keep code in memory. After PHP changes run
  `docker compose exec api php artisan octane:reload`
- **Horizon**: runs `php artisan horizon`; restart it after PHP changes with `docker compose restart horizon`
- **Scheduler**: runs through a file-watcher wrapper (`docker/schedule-watch.sh`) that restarts `schedule:work` when a PHP file changes
- **Vue**: Vite HMR, automatic

`restart: unless-stopped` makes Docker restart Horizon, the scheduler and Reverb if they crash.

### New API routes and permissions

Every authenticated API route needs a rule in `api/config/route_permissions.php` (one line, e.g.
`'POST projects/{project}/rollback' => 'projects.deploy'`); unmapped routes answer 403 and fail
`RouteAuthorizationTest`. New permissions go into `api/config/permissions.php` with labels in
`vue/src/locales/{en,tr}/rbac.js` — see [Roles and permissions](permissions.md#for-contributors-adding-a-route-or-a-permission).

### Useful commands

```bash
# Follow logs
docker compose logs -f

# Follow one service
docker compose logs -f api

# Run an Artisan command
docker compose exec api php artisan <command>

# Stop the containers
docker compose down

# Restart Horizon or the scheduler manually
docker compose restart horizon scheduler

# Update the API docs (Scramble)
docker compose exec api php artisan scramble:export --path=public/api-docs.json
```

---

## HTTPS / WSS with a Valet proxy

A Valet proxy serves the Compose stack over HTTPS + WSS on the `runr.test` domain.

### Setup

```bash
# Proxy the Vue dev server (HTTPS)
valet proxy runr http://localhost:8061 --secure
```

This command:
- Creates a `https://runr.test` → `http://localhost:8061` (Vite dev server) proxy
- Terminates TLS with Valet's CA certificate
- Generates the Nginx config

### Flow

```
Browser
  ├── https://runr.test/...           → Valet → Vite (8061)
  ├── https://runr.test/api/...       → Valet → Vite → API container (80)
  └── wss://runr.test/ws/app/key      → Valet → Vite → Reverb (8080)
```

### How WSS works

1. **Valet** terminates TLS for the incoming HTTPS request and forwards it to `http://localhost:8061`
2. The **Vite dev server** proxies the `/ws` path to the Reverb container (`vite.config.js`); like the
   production gateway it also proxies `/api`, `/storage`, `/git/`, `/mcp`, `/oauth2/` and
   `/.well-known/oauth-*` to the API and `/sentry-tunnel` to the Sentry proxy
3. The **Echo plugin** (`src/plugins/echo.js`) detects HTTPS and sets:
   - `forceTLS: true` → uses `wss://`
   - `wsPath: '/ws'` → goes through the Vite proxy
   - `wsPort: 443` → Valet's HTTPS port

Over plain HTTP (`http://localhost:8061`, without Valet):
- `forceTLS: false` → uses `ws://`
- `wsPath: ''` → connects to Reverb directly
- `wsPort: 8064` → Reverb's published port (`VITE_REVERB_PORT`)

Setting `VITE_WS_PROXY=true` routes plain-HTTP connections through `/ws` as well.

### Nginx WebSocket support

The Valet v4+ `proxy` command adds the WebSocket headers. If the WS connection fails, check the Nginx config
Valet generated:

```bash
cat ~/.config/valet/Nginx/runr.test
```

Make sure the `location` block contains:

```nginx
proxy_set_header Upgrade $http_upgrade;
proxy_set_header Connection "upgrade";
proxy_read_timeout 86400;
```

If not, add them and restart Valet:

```bash
valet restart
```

### Troubleshooting

**The WS connection does not open:**
1. Check DevTools → Network → WS
2. A `wss://runr.test/ws/app/runr-local-key` request should be listed
3. Its status should be 101 (Switching Protocols)

**Is Reverb running:**
```bash
docker compose logs reverb --tail 20
# should show "Starting server on 0.0.0.0:8080"
```

**Send a test event:**
```bash
docker compose exec api php artisan tinker --execute="
event(new \App\Events\PlatformEvent('test.ping', ['ts' => now()->toISOString()]));
echo 'OK';
"
```

---

## Valet (Ubuntu/WSL, native)

Installs PHP 8.3, Redis, Composer, NVM and Valet.

### Setup

```bash
bash scripts/devready.sh valet
```

The script:
1. Installs the Ondrej PHP PPA, PHP 8.3 and its extensions
2. Installs Composer, NVM, Node 22, Redis and Valet
3. Creates `api/.env` (Valet URL `https://runrapi.test`) and generates `APP_KEY`
4. Installs the Composer dependencies
5. Creates the SQLite database and runs migrations + seeders
6. Installs the Vue npm dependencies
7. Creates the Valet link + SSL certificate

### Services

| Service   | URL / command                   |
|-----------|---------------------------------|
| Backend   | https://runrapi.test            |
| Frontend  | `cd vue && npm run dev`         |
| Horizon   | `cd api && php artisan horizon` |

---

## WebSocket architecture

### PlatformEvent

Real-time updates are broadcast with `PlatformEvent` on the `platform` channel.

```php
// Backend: broadcast from anywhere
event(new PlatformEvent('region.updated', ['region' => $data]));
event(new PlatformEvent('project.updated', ['project' => $data]));
```

### Frontend listeners

The `useRealtimeNotifications` composable is mounted in `App.vue` and listens on three channels:

| Channel                    | Event                 | Purpose                                  |
|----------------------------|-----------------------|------------------------------------------|
| `notifications`            | `NotificationCreated` | Notification popup + badge update        |
| `notifications.{user_id}`  | `NotificationCreated` | Notifications of the signed-in user (job results) |
| `platform`                 | `.platform.event`     | Region / project / deployment updates    |

`handlePlatformEvent()` handles a few global topics itself and passes every topic on to the handlers
registered through `useJobEvents`. The full topic map is in [websocket-events.md](websocket-events.md).

### Channels

| Channel                      | Type    | Purpose                   |
|------------------------------|---------|---------------------------|
| `notifications`              | Private | Notifications             |
| `notifications.{user_id}`    | Private | Notifications of one user |
| `platform`                   | Private | Platform-wide changes     |
| `deployment.{project_id}`    | Private | Deployment status         |

Every subscription is authorized by `POST /api/broadcasting/auth` (`api/routes/channels.php`,
[security.md](security.md#real-time-channels)); the Ticket module adds its own channels. With
tenancy enabled, channel names get a tenant prefix (`tenant.{id}.platform`) through
`App\Support\BroadcastChannelResolver`.

---

## Container roles

Selected with the `CONTAINER_ROLE` environment variable (`docker/entrypoint.sh`):

| Role            | Command                                                                  |
|-----------------|--------------------------------------------------------------------------|
| api             | `php artisan migrate --force` + module migrations, `executor:sync` in the background, then `php artisan octane:start --server=frankenphp` (HTTP server, port 80) |
| horizon         | `php artisan horizon`                                                    |
| scheduler       | `sh docker/schedule-watch.sh` (`php artisan schedule:work` + file watch) |
| reverb          | `php artisan reverb:start-shell --host=0.0.0.0 --port=8080`              |
| migrator        | `php artisan migrate --force` + module/tenant migrations + `db:seed`     |
| migrator-fresh  | clears `storage/git`, `php artisan migrate:fresh --force` + module migrations + `db:seed` |
| migrate-only    | migrations + module/tenant migrations without seeding (Helm pre-upgrade job) |

The module migrations of Tenancy run only with `TENANCY_ENABLED=true`; tenant schemas are migrated with
`php artisan tenancy:migrate`.

---

## Environment variables (Reverb)

In `api/.env.docker`:

```env
REVERB_SERVER_HOST=0.0.0.0    # Reverb server bind address (inside the container)
REVERB_SERVER_PORT=8080        # Reverb server port (inside the container)
REVERB_HOST=runr-reverb        # Broadcasting client → Reverb hostname (Docker DNS)
REVERB_PORT=8080               # Broadcasting client → Reverb port
REVERB_SCHEME=http             # Broadcasting client → protocol

REVERB_APP_ID=runr-local
REVERB_APP_KEY=runr-local-key
REVERB_APP_SECRET=runr-local-secret
```

`vue` service in `docker-compose.yml`:

```env
VITE_REVERB_TARGET=http://reverb:8080   # Vite /ws proxy target
VITE_REVERB_KEY=runr-local-key          # Echo client key
```

**Note:** `REVERB_HOST` and `REVERB_SERVER_HOST` serve different purposes:
- `REVERB_SERVER_HOST` → the address the Reverb WS server binds to (`0.0.0.0`)
- `REVERB_HOST` → the Reverb hostname the API's broadcasting driver connects to (`runr-reverb`, an
  alias on the stack's own network: `reverb` also names the Reverb of other stacks on the shared
  `kind` network)

---

## Testing

Every suite runs locally with the commands below; CI runs the same commands
(see [CI jobs](#ci-jobs)).

| Suite | Location | Command |
|---|---|---|
| API (PHPUnit) | `api/tests/` | `cd api && php artisan test` |
| PHP style (Pint) | `api/pint.json` | `cd api && vendor/bin/pint --test` |
| PHP static analysis (Larastan) | `api/phpstan.neon` | `cd api && vendor/bin/phpstan analyse --memory-limit=1G` |
| UI lint (ESLint) | `vue/eslint.config.js` | `cd vue && npm run lint` |
| UI unit (Vitest) | `vue/tests/` | `cd vue && npm test` |
| UI e2e (Playwright) | `vue/e2e/` | `cd vue && npm run test:e2e:mock` / `npm run test:e2e` |
| Go | every `go.mod`: `cli`, `terraform-provider-runr`, `runtime/agent`, `runtime/queue-consumer`, `runtime/wrapper`, `runtime/image-upload`, `proxy`, `examples/runtimes/q/interpreter` | `gofmt -l . && go vet ./... && go test -race ./...` per module |
| Helm chart | `helm/`, scenarios in `ci/helm-values/` | `bash ci/check-helm-chart.sh` (`helm lint --strict` + `helm template` per scenario, kubeconform against Kubernetes 1.30–1.35; needs helm and kubeconform or docker) |
| Kubernetes e2e (Kind) | `runtime/lib/test*.sh` | `bash runtime/ci.sh test`, and one suite per feature: `test-runtimes`, `test-wasm`, `test-images`, `test-observability`, `test-sandbox-api`, `test-supply-chain`, `test-ai`, `test-bindings`, `test-git-deploy`, `test-mcp` |

### API (PHPUnit)

```bash
cd api
composer install
cp .env.example .env && php artisan key:generate   # first run only
php artisan test                                    # in-memory SQLite (phpunit.xml)
php artisan test --filter=SafeRouterTest            # one class or method
```

With Docker Compose: `docker compose exec api php artisan test`.

`phpunit.xml` sets what the suite depends on, whatever `.env` says: in-memory SQLite, the array
cache, mail and session drivers, a synchronous queue, `APP_URL=http://localhost` (forced: the
URLs assertions build do not follow the `.env` copied from `.env.example`) and
`memory_limit=1G` (the CI image has no `php.ini`, and PHP's default 128 MB is not enough for the
whole suite).

Every test gets a throwaway storage directory (`storage_path()`, `tests/TestCase.php`): the
internal git repositories, project remotes and builds tests create never land in the checkout's
`api/storage/`. The suite needs the `git` binary, but no git identity.

Coverage floor: CI runs the suite with [pcov](https://github.com/krakjoe/pcov) and fails when the
line coverage of `app/` (`<source>` in `phpunit.xml`) drops below the `--min` of `test:api` (and
of the GitHub `api` job) — the measured coverage rounded down to whole percent; raise it when tests
add coverage. Locally, with pcov or Xdebug installed:

```bash
php -d memory_limit=1G artisan test --compact --coverage --min=76
```

The `-d memory_limit` is for the `artisan` process, which reads the coverage report after PHPUnit
has finished.

### PHP style and static analysis

```bash
cd api
vendor/bin/pint --test        # list files whose style differs (CI: lint:php)
vendor/bin/pint               # rewrite them
vendor/bin/phpstan analyse --memory-limit=1G   # Larastan level 5 (CI: analyse:php)
```

`api/phpstan-baseline.neon` records the findings that existed when Larastan was
introduced, so only new findings fail. After fixing baseline entries, or after a
large merge, regenerate it on a checkout whose model docblocks match the committed ones
(not right after a migrate with `IDE_HELPER_POST_MIGRATE=true`, see [Migrations](#migrations)):

```bash
cd api && vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline
```

`lint:php` fails on any style difference; run `vendor/bin/pint` on the files you change.

### UI lint and unit tests (ESLint, Vitest)

```bash
cd vue
npm ci
npm run lint            # ESLint 10 + eslint-plugin-vue (flat config)
npm test                # Vitest, jsdom
npm run test:watch      # re-run on change
npm run test:coverage   # coverage/ (text summary, HTML, Cobertura); fails below the floor
```

- Tests live in `vue/tests/`, mirroring `src/` (`api/`, `stores/`, `router/`,
  `composables/`, `components/`, `utils/`, `i18n/`), named `*.spec.js`.
- `tests/helpers.js` provides `withPlugins()` (real en/tr messages + a fresh
  Pinia for `mount`), `createTestI18n()` and `fakeT()` (a `t()` that echoes
  key and params). `src/test-support/helpers.js` holds the fixtures of the
  component specs (`makeI18n()`, `freshPinia()`, runtime catalog, regions, GPU
  status); it is not counted in the coverage.
- Coverage floor: `coverage.thresholds` in `vue/vitest.config.js` (statements,
  branches, functions, lines) — the measured coverage rounded down to whole
  percent. `npm run test:coverage` (CI) fails below it; raise it when tests add
  coverage.
- Mock API modules with `vi.mock('@/api/<module>')`; the API client itself is
  tested with a fake axios adapter (`tests/api/client.spec.js`).
- `tests/i18n/parity.spec.js` fails when a key or `{placeholder}` exists in
  `en` but not in `tr` (or the reverse), for the core catalog and every
  `src/modules/*/locales`, and when two locale part files share a top-level key.
- ESLint: correctness rules are errors; template formatting rules are off;
  unused variables, props without a default and `v-html` are warnings, and
  `npm run lint` fails on any warning (`--max-warnings 0`). An intended
  exception is disabled inline with its reason
  (`<!-- eslint-disable vue/no-v-html -- reason -->`).
- Upper-cased labels (`uppercase`, `.label`, `.section-title`): CSS
  `text-transform` follows the element's language, so under the Turkish locale
  the English terms a message keeps would read KNATİVE, MİB, DOMAİN. Render such
  a text with `<TermText path="key" />` (`src/components/TermText.vue`): the
  words the Turkish message shares with the English one get `lang="en"`.
  `tests/i18n/uppercaseTerms.spec.js` reads every template and fails for an
  upper-cased `t()` text that needs it.

### UI end-to-end tests (Playwright)

```bash
cd vue
npx playwright install chromium   # first run only
npm run test:e2e:mock             # mocked API: starts its own Vite dev servers
npm run test:e2e                  # against a running dev stack
npx playwright show-report        # HTML report of the last run
```

Two modes:

- **Mocked API** (`test:e2e:mock`, projects `mock` and `mock-modules`):
  Playwright starts two Vite dev servers itself (`e2e/vite.config.e2e.ts`,
  ports 4173 and 4174, or `E2E_MOCK_PORT` / `E2E_MODULES_PORT`) and answers
  every `/api` request from an in-memory fake (route interception); a fake
  Reverb socket delivers platform events. Outside CI a server already
  listening on such a port is reused, so give a second checkout its own ports.
  `mock` runs the core build, `mock-modules` a build with the tenancy, ticket
  and auth-provider modules and runs the specs tagged `@modules`. No backend
  needed; this is the mode CI runs on merge requests.
- **Full stack** (`test:e2e`, project `stack`): runs against a running stack,
  e.g. `docker compose up -d` (or `scripts/devready.sh docker`). Settings:
  `E2E_BASE_URL` (default `http://localhost:8061`, the compose `vue`
  service), `E2E_LOGIN` / `E2E_PASSWORD` (defaults `admin@example.com` / `admin123`, the
  administrator the development seed creates with the `ADMIN_PASSWORD` of `api/.env.docker`),
  `E2E_TENANCY=true` to include the
  `@modules` specs when the stack runs with the modules on (`TENANCY_ENABLED`,
  `AUTH_PROVIDER_ENABLED` and `TICKET_ENABLED` true, as in the root `.env.example`; the ticket
  spec opens a ticket in the console, which shows Support Tickets only with `TICKET_ENABLED`). Specs
  that need a Kubernetes cluster or fake-only data (deploy, roll back, region
  wizard, domains) skip themselves in this mode; records a spec creates are
  removed after it.
  - The `stack-setup` project (`e2e/stack.setup.ts`, a dependency of `stack`) signs the account
    in once per run and marks its welcome tour as seen; the workers share that session (the
    login route allows `AUTH_RATE_LIMIT_PER_MINUTE` attempts per IP and login).
  - All workers use the one account: start the stack with a higher per-user API limit, e.g.
    `API_USER_RATE_LIMIT_PER_MINUTE=3000` in the root `.env` (passed to the `api` service; the
    default 300 is sized for a person's console tabs).
  - The project specs (code versions, environment variables, secrets, triggers, create project)
    need a ready region; without a cluster, load the demo data first (Settings → Demo Data →
    Load Demo Data), or set `E2E_SEED_DEMO=true`: the setup then loads it when no region is ready
    (a queued job: Horizon must run). Without a ready region they skip.
  - Run it once against a single-tenant stack (`TENANCY_ENABLED=false`) and once against a
    multi-tenant stack with the modules on and `E2E_TENANCY=true`: on the multi-tenant stack the single-tenant user
    management spec skips (the tenancy module replaces Settings → Users), and the roles specs work
    in the first tenant of the account (the central context has no roles).

Writing a spec — one feature per file, `vue/e2e/<feature>.spec.ts`:

```ts
import { test, expect } from './fixtures/test'

test.describe('Widgets', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API')

  test('creates a widget', async ({ page, auth, seed, mockApi }) => {
    const project = await seed.project('xaas')   // fake row or real API record
    await auth.signIn()                          // session in localStorage
    await page.goto(`/projects/${project.id}`)
    const sent = mockApi.waitFor('POST', '/widgets')
    // ...
  })
})
```

- Always import `test` and `expect` from `./fixtures/test`. Fixtures:
  `e2eMode` (`'mock' | 'stack'`), `isStack`, `modules`, `mockApi` (override
  endpoints with `on()`, assert payloads with `waitFor()` / `calls()`, edit
  data through `db`), `realtime` (`platform(topic, payload)`,
  `notification()`), `auth` (`signIn()`, `loginViaUi()` incl. 2FA,
  `credentials`) and `seed` (`project(type)`, `readyRegion()`, `call()`,
  cleanup in stack mode). Every page starts with the English locale.
- Shared UI steps live in `e2e/helpers/` (`auth.ts`, `seed.ts`, `ui.ts` for
  labelled fields, confirm dialogs and the Monaco editor, `edit.ts`,
  `deploy.ts`); default fake data in `e2e/fixtures/data.ts`.
- Specs that need the tenancy/ticket/auth-provider modules carry the tag:
  `test.describe('Billing', { tag: '@modules' }, () => { ... })`.
- Select by role, label and visible text (or existing `data-tour`
  attributes), not by CSS classes.

### Go

```bash
cd runtime/agent          # likewise every other directory with a go.mod (cli, terraform-provider-runr, …)
gofmt -l .                # must print nothing
go vet ./...
go test -race ./...       # the race detector needs cgo (gcc)
```

Without a local Go toolchain:
`docker run --rm -v "$PWD":/src -w /src/runtime/agent ghcr.io/brf-tech/runr/reqs/golang:1.26-alpine sh -c 'apk add --no-cache build-base && CGO_ENABLED=1 go test -race ./...'`
(the image `test:go` uses; `GOTOOLCHAIN=auto` fetches a newer toolchain for a module that asks for one).
The queue-consumer stream tests use an in-process miniredis; set
`QC_TEST_REDIS=localhost:6379` to run them against a real Redis. The Sentry tunnel's tests
(`proxy/`) need no Redis either: the handlers and the worker run against an in-memory queue, the
Redis queue against a small RESP fake. The Terraform provider's
tests run real plans and applies against the fake API with the `terraform` binary on the `PATH`
(or `TF_ACC_TERRAFORM_PATH`; 1.11 or newer for the write-only attributes); without one, every
test downloads the latest release first.

Line endings: the root `.gitattributes` keeps text files LF in every checkout, also on Windows
with `core.autocrlf=true` (gofmt, the shell scripts and the Linux containers need LF). A checkout
made before it was added is converted once with `git rm --cached -r -q . && git reset --hard`
(commit or stash your changes first).

### Kubernetes e2e (Kind)

`bash runtime/ci.sh test` creates a Kind cluster, starts the API stack with
Docker Compose and runs `php artisan test:e2e` (details in the next section).
When the Docker daemon is remote (docker-in-docker in CI), set
`E2E_DOCKER_ADDR` to the daemon's host name (CI: `docker`): the Kind API
server then listens on all interfaces and the kubeconfig points at that host.
`E2E_API_WAIT` sets how many seconds to wait for the API (default 30).

`bash runtime/ci.sh test-helm-upgrade` checks the Helm upgrade path instead: the chart and images of
`main` in a Kind cluster, data created through that version's API, then `helm upgrade` to the
checkout ([upgrade.md](upgrade.md#testing-the-upgrade-path)).

### Secret scan and dependency audits

```bash
# gitleaks on the files of the current commit (not history); allowlist: .gitleaks.toml
mkdir -p /tmp/runr-tree && git archive HEAD | tar -x -C /tmp/runr-tree
gitleaks dir /tmp/runr-tree --config .gitleaks.toml --redact

cd vue && npm audit --omit=dev --audit-level=high   # shipped bundle (blocking in CI)
cd vue && npm audit --audit-level=high              # incl. build/test tooling (reported)
cd api && composer audit --locked                   # reported
```

### CI jobs

| Job | Runs | Blocking |
|---|---|---|
| `secrets:gitleaks` | MRs, default branch | yes |
| `check:reqs-mirror` (`ci/check-reqs-mirror.sh`: every `ghcr.io/brf-tech/runr/reqs/*` reference is in the mirror list of `runtime/lib/mirror.sh`) | MRs, default branch | yes |
| `check:build-scripts` (`ci/check-dockerfiles.sh`: no Docker Hub base image, `OTEL_SDK_DISABLED=true` before Spin runs in a build step; `runtime/test/*_test.sh`: `runtime/ci.sh` without Docker or a registry) | MRs, default branch | yes |
| `test:api` (PHPUnit with the coverage floor) | MRs / default branch touching `api/**` | yes |
| `lint:php` (Pint) | same as `test:api` | yes |
| `analyse:php` | same as `test:api` | yes |
| `test:ui` (lint without warnings, Vitest with the coverage floor, build) | MRs / default branch touching `vue/**` | yes |
| `test:ui-e2e` (Playwright, mocked API) | MRs / default branch touching `vue/**` | yes |
| `test:ui-e2e:stack` (Playwright, compose stack in dind) | manual, schedule `SCHEDULED_JOB=ui-e2e` | no |
| `lint:helm` (`ci/check-helm-chart.sh`) | MRs / default branch touching `helm/**`, `ci/helm-values/**` | yes |
| `test:go` (gofmt, vet, race tests per `go.mod`) | MRs / default branch touching Go files | yes |
| `audit:npm` | default branch, schedules, MRs touching `vue/package*.json` | production deps: yes; dev tooling: no |
| `audit:composer` | default branch, schedules, MRs touching `api/composer.*` | no |
| `e2e:kind` (`runtime/ci.sh test` in dind) | manual, schedule `SCHEDULED_JOB=e2e` | no |

`.github/workflows/ci.yml` runs the core checks (reqs mirror list, Dockerfiles and build scripts,
API tests + Larastan, UI lint/unit/build, UI e2e in mocked mode, Go) on GitHub pull requests and
pushes to `main`.

---

## E2E tests

The E2E tests run against a Kind cluster plus the Docker Compose stack.

### Running

```bash
# With a Kind cluster (created and deleted automatically)
bash runtime/ci.sh test

# A specific Kubernetes version
bash runtime/ci.sh test --k8s=1.33.0

# External cluster (skips Kind)
bash runtime/ci.sh test --kubeconfig=/path/to/kubeconfig

# Keep the cluster after the run
bash runtime/ci.sh test --no-cleanup

# Skip the sandbox runtime tests
bash runtime/ci.sh test --skip-sandbox
```

Module tests that need no cluster: `ci.sh test-tenancy`, `ci.sh test-authprovider`, `ci.sh test-ticket`
(the last two run single-tenant, like `ci.sh test`). They reset the database of the Compose stack.

Upgrade of a Compose install (`compose/`), no cluster: `ci.sh test-upgrade [--from=REF] [--tenancy]`
builds the API image of the previous release (default `main`) and of the checkout, fills a PostgreSQL
database on the old release and upgrades it the way `compose/README.md` → Updating describes, then
checks sign-in, access, API tokens, environment variables (encrypted at rest, read back unchanged)
and settings. `--from-dir=DIR` takes an unpacked release instead of a git ref; `--keep` leaves the
stack running (Compose project `runr-upgrade`, API on `127.0.0.1:18060`).

### How it works

- **Stack**: `docker compose up -d --build api horizon scheduler reverb mailpit` with `TENANCY_ENABLED=false`
  (the API broadcasts to Reverb, queued mail goes to Mailpit, as in an install);
  an empty `APP_KEY=` line of `api/.env.docker` gets a generated key first, as `scripts/devready.sh` does
  (secrets are encrypted at rest, and the Octane workers resolve the encrypter when they start)
- **API port**: `API_PORT` (default 8060), from `docker-compose.yml`
- **Two runs on one Docker host**: each sets its own `COMPOSE_PROJECT_NAME`, `KIND_LOCK` and ports
  (`API_PORT`, `DB_PORT`, `REDIS_PORT`, `REVERB_PORT`); the run then names the Compose network
  `<project>_default` and the API images `<project>-api` / `<project>-vendor`
  (`RUNR_COMPOSE_NETWORK`, `RUNR_API_IMAGE`, `RUNR_VENDOR_IMAGE`), so neither stack reaches the
  other's database, Redis or images; Mailpit's ports go to free host ports unless
  `MAILPIT_SMTP_PORT` / `MAILPIT_UI_PORT` are set (`docker compose port mailpit 8025` shows the UI's)
- **Slow disks**: `KIND_ETCD_IN_MEMORY=1` keeps etcd's data on the control-plane's tmpfs; for hosts
  where etcd's fsyncs time out (`etcdserver: request timed out`, build Jobs that never start)
- **Kubeconfig**: for Kind, `kind get kubeconfig --internal` (the API services join the `kind` network and reach the control-plane container by name, on Linux hosts too); for an external cluster, `127.0.0.1` is rewritten to `host.docker.internal`. The file is copied into the container with `docker compose cp`
- **Test command**: `docker compose exec api php artisan test:e2e --base-url=http://localhost --kubeconfig=/tmp/kubeconfig`
- **Cleanup**: `docker compose down` + Kind cluster deletion (unless `--no-cleanup`); with `KIND_LOCK`,
  the lock is released only once the cluster is gone (the delete is retried; a cluster that cannot be
  deleted keeps the lock and the run says how to remove both)

### Requirements

- Docker + Compose plugin
- Kind (`go install sigs.k8s.io/kind@latest`), unless an external cluster is used
- `cloud-provider-kind` for Kind LoadBalancer support (downloaded automatically)

---

## Repository layout

```
runr/
├── api/                    Laravel 12 backend
│   ├── Modules/            AuthProvider, Tenancy, Ticket (nwidart/laravel-modules)
│   ├── docker/             entrypoint.sh, schedule-watch.sh, PHP config
│   ├── .env.docker         Env for Docker Compose
│   ├── .env.example        Env template for Valet
│   ├── Dockerfile          Development image (PHP 8.3 on Alpine, kubectl, Helm)
│   └── Dockerfile.prod     Production image (FrankenPHP, PHP 8.3)
├── vue/                    Vue 3 + Vite frontend
├── helm/                   Helm chart
├── runtime/                Runtime images and manifests, universal wrapper, runr-agent,
│                           queue-consumer, image-upload, supply-chain tools, hf-serve, ci.sh
├── cli/                    runr CLI (Go)
├── terraform-provider-runr/ Terraform provider (Go)
├── actions/                GitHub Action (actions/deploy)
├── sdk/                    Workflow SDKs (node, python) and Sandbox SDKs (sandbox/)
├── examples/               Runtime, WebAssembly, AI, MCP and workflow examples
├── proxy/                  Sentry tunnel (Go)
├── compose/                Single-host production install with Docker Compose
├── landing/                Landing page (runr.sh)
├── ci/                     Upstream release checks (Helm charts, Knative)
├── scripts/
│   ├── devready.sh         Main setup script (docker|valet)
│   └── lib/
│       ├── devready_docker.sh   Docker Compose setup
│       ├── devready_app.sh      Valet app setup
│       └── devready_system.sh   Valet system dependencies
├── docs/                   Documentation (this file: docs/development.md)
├── docker-compose.yml      Full development stack
└── .env.example            Feature toggles, port and DB defaults
```
