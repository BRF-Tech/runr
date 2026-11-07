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
BILLING_ENABLED=true          # Bills per project and month (Billing module, docs/billing.md)
```

Restart the containers after changing them:

```bash
docker compose down && docker compose up -d --build
```

**What they do:**
- **API**: `docker/entrypoint.sh` runs `php artisan module:enable|disable Tenancy` (and `AuthProvider`, `Ticket`, `Billing`) based on the value
- **Vue**: passed in as `VITE_TENANCY_ENABLED`, `VITE_AUTH_PROVIDER_ENABLED`, `VITE_TICKET_ENABLED` and `VITE_BILLING_ENABLED`; a disabled module is left out of the build, and the Ticket and Billing screens are also hidden while the API reports the module off (`features` of `GET /api/branding`)
- `docker-compose.yml` passes `TENANCY_ENABLED` (default `true`), `AUTH_PROVIDER_ENABLED` (default `false`) and
  `BILLING_ENABLED` (default `false`) to every API container (api, horizon, scheduler, reverb, migrator).
  `TICKET_ENABLED` reaches only the `vue` service (`VITE_TICKET_ENABLED`, default `false`); the API containers
  keep the entrypoint default and enable the Ticket module

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

Every API route is in one group of the API reference (`/api/docs`), one of `NormalizeApiTags::GROUPS`
(`api/app/Http/Middleware/NormalizeApiTags.php`): a new controller names it with a `@tags <group>`
line at the end of its class comment, or gets an entry in the class map there (a module registers
its own with `NormalizeApiTags::registerTags`). `ApiDocsTest` fails for a route in any other group.

Every operation has a summary: the first paragraph of the controller method's PHPDoc, one line of at
most 80 characters that says what the operation does ("List the project's workflows"), without the
HTTP method, a path or a trailing period; the paragraphs after it are the description. Its
operationId is the route's name, else Scramble's `<controller>.<method>`; a method that serves two
routes gets one per route from their names, and `@operationId` in the PHPDoc sets one by hand.
A route without a sign-in (no `auth:sanctum`: webhooks, public pages, invitations) has
`@unauthenticated` in its method's PHPDoc, so the reference shows it public. `ApiDocsTest` checks
these rules on the test app's document; the `api` job of `ci.yml` builds the document as the image
does, every module enabled, with `sh docker/api-docs.sh <path> --check` (`ApiDocument::problems`)
and runs `ApiDocsTest` again with the modules on.

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

# Build the OpenAPI document /api/docs.json serves (api/resources/api-docs/openapi.json)
docker compose exec api php artisan runr:api-docs

# Scramble's own document, the one the CLI's contract test reads (cd cli && make openapi)
docker compose exec api php artisan scramble:export --path=public/api-docs.json
```

The API reference (`/api/docs`) and its OpenAPI document (`/api/docs.json`) serve the document the
API image builds (`php artisan runr:api-docs`, a stage of `api/Dockerfile.prod`): generating it
reads every API route's controller, requests and resources and takes longer than a request may
run. In the dev stack, without that file, `/api/docs.json` generates the document on each request,
so it shows the code as it is (slowly). A file written with `runr:api-docs` is served as it is
until you delete it (`rm api/resources/api-docs/openapi.json`); git and the image builds never take
it. The page loads the Scalar viewer from the image (`public/api/vendor/scalar/`), in a checkout
without it the same version from jsDelivr.

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
| Go | every `go.mod`: `cli`, `terraform-provider-runr`, `runtime/agent`, `runtime/queue-consumer`, `runtime/image-upload`, `proxy`, `examples/runtimes/q/interpreter` | `gofmt -l . && go vet ./... && go test -race ./...` per module |
| Helm chart | `helm/`, scenarios in `ci/helm-values/` | `bash ci/check-helm-chart.sh` (`helm lint --strict` + `helm template` per scenario, kubeconform against Kubernetes 1.30–1.35; needs helm and kubeconform or docker) |
| Kubernetes e2e (Kind) | `runtime/lib/test*.sh` | `bash runtime/ci.sh test`, and one suite per feature: `test-runtimes`, `test-wasm`, `test-images`, `test-org-transfer`, `test-observability`, `test-triggers`, `test-trigger-sources`, `test-reconcile`, `test-network`, `test-internal-network`, `test-cert-manager`, `test-sandbox-api`, `test-supply-chain`, `test-ai`, `test-bindings`, `test-git-deploy`, `test-mcp`, `test-admission`, `test-runtime-patches`, `test-warmer`, `test-deploy-strategies`, `test-secrets`, `test-environments`, `test-api-product`, `test-region-move`, `test-global-projects` |

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
`memory_limit=3G` (a PHP without `php.ini`, such as the php Docker images, has a default of 128 MB,
which is not enough for the whole suite, and with coverage this process writes a report of more
than 1 GB).

Every test gets a throwaway storage directory (`storage_path()`, `tests/TestCase.php`): the
internal git repositories, project remotes and builds tests create never land in the checkout's
`api/storage/`. The suite needs the `git` binary, but no git identity.

Coverage floor: CI runs the suite with [pcov](https://github.com/krakjoe/pcov) and fails when the
line coverage of `app/` (`<source>` in `phpunit.xml`) drops below the `--min` of the `api` job of
`.github/workflows/ci.yml` — the measured coverage rounded down to whole percent; raise it in both
places when tests add coverage. Locally, with pcov or Xdebug installed:

```bash
php -d memory_limit=2G artisan test --compact --coverage --min=76
```

The `-d memory_limit` is for the `artisan` process, which reads the coverage report after PHPUnit
has finished; PHPUnit's own process, which writes it, takes its limit from `phpunit.xml`.

### PHP style and static analysis

```bash
cd api
vendor/bin/pint --test        # list files whose style differs (CI: the api job)
vendor/bin/pint               # rewrite them
vendor/bin/phpstan analyse --memory-limit=1G   # Larastan level 5 (CI: the api job)
```

`api/phpstan-baseline.neon` records the findings that existed when Larastan was
introduced, so only new findings fail. After fixing baseline entries, or after a
large merge, regenerate it on a checkout whose model docblocks match the committed ones
(not right after a migrate with `IDE_HELPER_POST_MIGRATE=true`, see [Migrations](#migrations)):

```bash
cd api && vendor/bin/phpstan analyse --memory-limit=1G --generate-baseline
```

CI fails on any style difference; run `vendor/bin/pint` on the files you change.

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
  needed; this is the mode CI runs on pull requests.
- **Full stack** (`test:e2e`, project `stack`): runs against a running stack,
  e.g. `docker compose up -d` (or `scripts/devready.sh docker`). Settings:
  `E2E_BASE_URL` (default `http://localhost:8061`, the compose `vue`
  service), `E2E_LOGIN` / `E2E_PASSWORD` (defaults `admin@example.com` / `admin123`, the
  administrator the development seed creates with the `ADMIN_PASSWORD` of `api/.env.docker`),
  `E2E_USERNAME` (the user name of that account for the user name sign-in spec; default `admin`,
  and with another `E2E_LOGIN` that spec is skipped unless it is set), `E2E_TENANCY=true` to include the
  `@modules` specs when the stack runs with the modules on (`TENANCY_ENABLED`,
  `AUTH_PROVIDER_ENABLED` and `TICKET_ENABLED` true, as in the root `.env.example`; the ticket
  spec opens a ticket in the console, which shows Support Tickets only with `TICKET_ENABLED`). Specs
  that need a Kubernetes cluster or fake-only data (deploy, roll back, region
  wizard, domains) skip themselves in this mode; records a spec creates are
  removed after it.
  - The `stack-setup` project (`e2e/stack.setup.ts`, a dependency of `stack`) signs the account
    in once per run and marks its welcome tour as seen; the workers share that session (the
    login route allows `AUTH_RATE_LIMIT_PER_MINUTE` attempts per IP and account, whether it is
    named by its user name or its e-mail address).
  - All workers use the one account: start the stack with a higher per-user API limit, e.g.
    `API_USER_RATE_LIMIT_PER_MINUTE=3000` in the root `.env` (passed to the `api` service; the
    default 300 is sized for a person's console tabs).
  - The project specs (code versions, environment variables, secrets, triggers, create project)
    need a ready region; without a cluster, load the demo data first
    (Administration → Regions & settings → Demo Data → Load Demo Data), or set
    `E2E_SEED_DEMO=true`: the setup then loads it when no region is ready (a queued job: Horizon
    must run). Without a ready region they skip.
  - Run it once against a single-tenant stack (`TENANCY_ENABLED=false`) and once against a
    multi-tenant stack with the modules on and `E2E_TENANCY=true`: on the multi-tenant stack the single-tenant user
    management spec skips (the tenancy module replaces Administration → Users), and the roles specs work
    in the first tenant of the account (the central context has no roles).

The main create flows against the real stack (real API, real database, real websocket):

```bash
docker compose up -d                      # the stack with its reverb service
cd vue
E2E_SEED_DEMO=true npm run test:e2e:create-flows
npx playwright test --project=mock create-flows mcp   # the same flows on the mocked API
```

`e2e/create-flows.spec.ts` creates a project with the wizard, a hosted MCP server from an image, a
clone from a project page, and switches between code versions. It also checks that a project
created elsewhere while the projects list loads stays in the list, and that deleting a project
keeps its page until the projects list opens. It holds API answers back
(`network.hold()`) so that the orderings a slow API produces happen on every run. For example, the
`project.created` broadcast arrives before the answer that created the project. On the stack the
broadcasts come from the API over Reverb, so the stack must run the `reverb` service. It needs a
ready region, as the other project specs do. The projects it creates are removed after each test.

Late and out-of-order answers: a fast local run never shows them, so the specs force them.

- **E2E, both modes:** `network.hold(method, path)` (fixture `network`, `e2e/fixtures/network.ts`)
  holds the answers to matching requests until `release()`:

  ```ts
  const saved = await network.hold('PUT', '/projects/:id/mcp-server')
  await page.getByTestId('mcp-create-submit').click()
  const { params } = await saved.arrived   // the API handled the request; the page has no answer yet
  // ...a websocket event, a second click, a filter change, then assert the state in between
  saved.release()
  ```

  In mock mode the fake API handles the request at once and holds its answer
  (`mockApi.hold()`; `mockApi.delay(method, path, ms)` answers late). In stack mode the real API
  answers, and the browser gets the answer at `release()`.
- **Negative checks after a late answer:** `settle(page)` (`e2e/helpers/timing.ts`) lets the page
  process what already arrived. `expect()` retries until a check passes, so a "not shown" check
  could otherwise pass before the late answer is processed.
- **Waiting for a real broadcast (stack mode):** `recordBroadcasts(page)` (same file, called
  before `page.goto`) records the websocket messages the page receives;
  `broadcasts.waitFor('project.deleted')` resolves once one has arrived. Release a held answer
  only after that.
- **Unit specs:** `deferred()` (`tests/helpers.js`) is a promise the spec settles by hand. Return it
  from a mocked API call, act (change a prop, click, emit an event), then resolve it.
- **In the console:** loaders that can be overtaken use `useLatest()`
  (`src/composables/useLatest.js`):
  - `begin()` returns a check that is false once a newer call started or the component is gone;
  - `cancel()` drops the calls in flight after an action's own answer.
- **Lists that broadcasts or actions change in place** use a change journal
  (`src/utils/changeJournal.js`). Examples: the projects list, a project's versions and
  deployments, the models page lists.
  - Each change (insert, update, removal) is applied to the list and recorded.
  - A reload takes `mark()` before its request and stores `replay(answer, mark)`. The changes
    made while it was in flight are applied to its answer, which may have been read before them.

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
  `credentials`), `seed` (`project(type)`, `readyRegion()`, `call()`,
  cleanup in stack mode) and `network` (`hold(method, path)`, late answers in
  both modes, above). Every page starts with the English locale.
- Shared UI steps live in `e2e/helpers/` (`auth.ts`, `seed.ts`, `ui.ts` for
  labelled fields, confirm dialogs and the Monaco editor, `edit.ts`,
  `deploy.ts`); default fake data in `e2e/fixtures/data.ts`.
- Specs that need the tenancy/ticket/auth-provider/billing modules carry the tag:
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
(CI's `go` job runs Go 1.26.9; `GOTOOLCHAIN=auto` fetches a newer toolchain for a module that asks for one).
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
When the Docker daemon is remote (e.g. docker-in-docker), set
`E2E_DOCKER_ADDR` to the daemon's host name (e.g. `docker`): the Kind API
server then listens on all interfaces and the kubeconfig points at that host.
CI runs the suite on the runner's own Docker daemon (`e2e.yml`, [CI jobs](#ci-jobs)).
`E2E_API_WAIT` sets how many seconds to wait for the API (default 30). `TEST_E2E_EXTRA_ARGS=--ml`
(or `RUNR_E2E_ML=1`) adds a function of each ML runtime offered, the CPU build of its default
version, that answers only when a matrix product gives its sum: off by default, as the two images
(0.4 and 0.5 GiB compressed) would share the e2e region registry's 1 GiB volume with the rest of the
run. BRF-Tech/runr-runtimes' language bot runs it with one ML runtime offered.

`bash runtime/ci.sh test-helm-upgrade` checks the Helm upgrade path instead: the chart and images of
`main` in a Kind cluster, data created through that version's API, then `helm upgrade` to the
checkout ([upgrade.md](upgrade.md#testing-the-upgrade-path)).

`bash runtime/ci.sh test-region-move` runs on two Kind clusters: the e2e region moves from the
first to the second and is rolled back ([Moving a region to another
cluster](regions.md#moving-a-region-to-another-cluster)). Besides the rebuilds, the report and the
restored cluster state, it checks the projects' secret values: a value in the region's Secret, one
in the region's OpenBao and a reference to a secret store are copied to the second cluster, values
set there come back with the rollback, and the rollback leaves nothing of them on the second
cluster or in its OpenBao (`runtime/lib/test-region-move.sh` lists every case).

### Dependency test bot

`.github/workflows/deps-test.yml` tests the new versions `upstream-releases.yml` files issues for,
and moves them onto `next` when they pass. `next` is the development branch of the private
development repository (origin). The bot never writes this repository: its `main` is the release
mirror and moves only at releases (the workflow's `GITHUB_TOKEN` can only read contents).

It runs on Saturdays at 06:41 UTC, after that day's release check, and by hand: **Actions →
Dependency tests → Run workflow**, or `gh workflow run deps-test.yml -f issue=8`. A run takes every
open issue `deps: <chart> <version> released` or `Knative 1.<minor> released: update
KNATIVE_K8S_COMPAT` that has no label yet. Of several open issues of one dependency it tests the
newest version and closes the older ones as superseded. `Knative 1.<minor> is not in
KNATIVE_K8S_COMPAT` only gets the label `needs attention`: a person adds the minor's row and its
minimum Kubernetes version.

For each version:

1. **The bump**, on a clone of `next`: `ci/deps-bump.sh <dep> <version>` (the charts and their
   pins are the rows of `ci/check-helm-releases.sh`). It first renders the pinned and the new chart
   with RunR's values (`ci/deps/helm-values.php`, from the code the install runs) on Kubernetes
   1.30 and 1.35. Then it moves the pin, every test and doc that names the pinned version as the
   current one, and adds a line under `[Unreleased]` → `Changed` of `CHANGELOG.md`. Lines that
   record the past keep their version: a RunR release or a date next to them, a measurement, the
   changelog, `docs/upgrade.md`. The commit is `fix(regions): <chart> chart <version>` (Kata:
   `fix(kata): kata-deploy <version>`), with no trailer.
2. **The suites** of the dependency, each Kind suite on a runner of its own (`kind-suite.yml`),
   PHPUnit through `api-tests.yml`, and `runtime/test` with the reqs mirror check:

   | Dependency | Suites besides PHPUnit and `runtime/test` |
   |---|---|
   | `traefik` | `ci.sh test`, `ci.sh test-network`, `ci.sh test-helm-upgrade --from=<last release tag>` (region:reconcile moves the region Traefik, step `traefik-chart`) |
   | `kube-prometheus-stack`, `loki-stack` | `ci.sh test` (a new region installs it), `ci.sh test-observability`, `ci.sh test-helm-upgrade --from=<last release tag> --after-upgrade=cluster:patch-observability,{region}` |
   | `zot` | `ci.sh test`, `ci.sh test-helm-upgrade --from=<last release tag> --after-upgrade=registry:upgrade-zot,{region}` |
   | `kata-deploy` | `ci.sh test-sandbox-api` with Kata (`SANDBOX_KATA=1`, `RUNR_E2E_SANDBOX_CASES=kata`), `ci.sh test-helm-upgrade --from=<last release tag> --kata` (region:reconcile runs the new kata-deploy on a region with Kata, step `kata-sandbox`) |
   | Knative | `ci.sh test` on the reqs mirror's Kind node of the oldest Kubernetes the new minor supports |

   After an e2e suite, `ci/deps/after-e2e.sh` checks that the region runs the new version, and the
   upgrade suites check the moved release (`--expect-release`): a green suite has exercised it.
   The Kata suites need hardware virtualization: `kind-suite.yml` opens the runner's `/dev/kvm`
   and fails with "Kata cannot run on a GitHub-hosted runner" where it does not work, so a
   kata-deploy issue then needs a person (and a host with KVM).
3. **Green**: the bump lands on `next` as one commit on top of the `next` it was tested on, a
   fast-forward (`ci/deps-land.sh`, never forced). The issue gets the run, the commit and the
   label `deps: passed`, and is closed: "fixed on next by <sha>, ships with the next release". It
   is closed then, not at the release, because the issue's work is done and every later version
   gets an issue of its own. When `next` moved meanwhile (another bump of the same run landed, or
   someone pushed), the bot runs again for that issue on the new `next`, up to three runs in a
   row; then the next weekly run tries.
4. **Red**: the label `needs attention` and a comment with the failing suites, their errors and
   the run. `next` is not touched. The version waits until someone runs the workflow with the
   issue's number (which takes the label off and tests it again), or a newer release of the
   dependency supersedes the issue.

A bump that is not mechanical goes to `needs attention` before any suite runs: the new chart
refuses RunR's values or no longer has a value RunR sets, renders other objects, changes its CRDs
(Helm never upgrades a chart's CRDs), is a new major release or has another `kubeVersion`; a
Traefik chart with a new app version (RunR pins its image from the reqs mirror, which
BRF-Tech/runr-runtimes publishes first); a kata-deploy chart whose objects changed beyond its
version (RunR renders kata-deploy's DaemonSet itself); a pin the console or the landing page
names; a Knative minor without its unreleased row, or without a Kind node image of a Kubernetes it
supports. The comment says which.

Besides its `GITHUB_TOKEN` (which gets `issues: write` for the comments and labels and
`actions: write` to start itself again), the workflow needs a write deploy key of the origin: the
bump job clones `next` with it and the landing pushes to it over SSH.

- The repository variable `GITLAB_RUNR_REMOTE`: the origin's SSH URL. No file of this repository
  names it; `ci/deps/ssh-key.sh` takes only an SSH URL (`git@HOST:GROUP/PROJECT.git`) of the host
  it pins.
- The secret `GITLAB_RUNR_PUSH_KEY`: the deploy key's private key (ed25519, OpenSSH format).
  `next`'s protection lets the key push and refuses a force push; `main` takes no deploy key. A
  write deploy key may also write any branch the origin does not protect, so the landing pushes
  exactly `HEAD:refs/heads/next`, a plain fast-forward, and `runtime/test/ci_workflows_test.sh`
  fails on any other refspec, a second push, a tag, a deletion or a force.
- The repository variable `GITLAB_KNOWN_HOSTS`: the host's key line (`HOST ssh-ed25519 AAAA…`).
  ssh trusts no other host key, and `ci/deps/ssh-key.sh` refuses a line of another host or with
  another fingerprint than the published one it pins
  (`SHA256:eUXGGm1YGsMAS7vkcx6JOJdOGHPem5gQp4taiCfCLB8`): a changed variable cannot send the push
  elsewhere.

`ci/deps/ssh-key.sh` writes them for the two steps that use them, to files of mode 0600 in the
runner's temporary directory (`IdentitiesOnly`, no agent, `StrictHostKeyChecking=yes`); a step that
always runs removes them, and nothing prints the key.

The bot's commits carry `RunR deps bot <deps-bot@runr.sh>` as author and committer
(`DEPS_BOT_NAME`, `DEPS_BOT_EMAIL` of the workflow). They never reach this repository themselves:
a release here is made from the release's tree, in commits of the maintainer, and the release's
attribution check runs on those, so it accepts the bot's commits as they are.

A value RunR passes a chart and the chart does not read is found the same way: changed alone, it
renders nothing new (`ci/deps/dead-values.php`). The bump sends a chart that stops reading one of
them to a person, and its report lists the ones that wait for a condition.

Locally, in a clean clone of `next` with `composer install` done in `api/`:
`bash ci/deps-bump.sh traefik 42.3.1` makes the change the bot would commit (`git diff`), and its
report says what it compared; `--no-render` leaves out the chart rendering.

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

GitHub Actions is the repository's CI; the workflows live in `.github/workflows/`, and
`runtime/test/ci_workflows_test.sh` (run by the `build-scripts` job) keeps them so.

| Workflow → job | Runs | When | Blocking |
|---|---|---|---|
| `ci.yml` → `gitleaks` | gitleaks on the files of the commit (not its history), allowlist `.gitleaks.toml` | every pull request, pushes to `main`, by hand | yes |
| `ci.yml` → `reqs-mirror` | `ci/check-reqs-mirror.sh`: every `ghcr.io/brf-tech/runr/reqs/*` reference resolves anonymously on ghcr.io; BRF-Tech/runr-runtimes publishes the mirror | same | yes |
| `ci.yml` → `build-scripts` | `ci/check-dockerfiles.sh` (no Docker Hub base image, `OTEL_SDK_DISABLED=true` before Spin runs in a build step, every image labels `org.opencontainers.image.source`); `ci/check-caddyfiles.sh` (`vue/Caddyfile` and `compose/Caddyfile` validate, index.html for every console route is never cached, the hashed build files for a year, a missing build file answers 404); `runtime/test/*_test.sh` (`runtime/ci.sh` without Docker or a registry, the pinned runtime images, the release workflow, these workflows) | same | yes |
| `ci.yml` → `helm` | `ci/check-helm-chart.sh` (helm 3.16, kubeconform) | same | yes |
| `ci.yml` → `api` | Pint, PHPUnit with the coverage floor, Larastan on PHP 8.2 (8.2.35 or later); the API reference as the image builds it, every module enabled (`api/docker/api-docs.sh --check`, `ApiDocsTest`); the steps are `api-tests.yml` | same | yes |
| `ci.yml` → `ui` | lint without warnings, Vitest with the coverage floor (report: artifact `ui-coverage`), build | same | yes |
| `ci.yml` → `ui-e2e` | Playwright, mocked API (report: artifact `playwright-report`) | same | yes |
| `ci.yml` → `go` | gofmt, vet, race tests with coverage per `go.mod` | same | yes |
| `ci.yml` → `sdk-node` | typecheck and Vitest of `sdk/node` | same | yes |
| `audit.yml` → `npm` | `npm audit` of `vue/` | pushes to `main`, daily, pull requests touching `vue/package*.json` or `api/composer.*`, by hand | production deps: yes; dev tooling: no |
| `audit.yml` → `composer` | `composer audit --locked` of `api/` | same | no |
| `e2e.yml` → `kind` | `runtime/ci.sh test` (Kind, Knative and the Compose stack on the runner's Docker); the steps are `kind-suite.yml` | weekly, by hand | no |
| `e2e.yml` → `ui-stack` | Playwright against the dev stack (`docker compose`, project `stack`, single-tenant, `E2E_SEED_DEMO=true`) | weekly, by hand | no |
| `upstream-releases.yml` → `check` | `ci/check-knative-releases.sh`, `ci/check-helm-releases.sh`; an issue per finding | daily, by hand | no |
| `deps-test.yml` | the [dependency test bot](#dependency-test-bot): each new version of those issues moved on `next`, tested, landed or held | weekly, by hand | no |
| `api-tests.yml`, `kind-suite.yml` | reusable workflows (`on: workflow_call`): the API checks of `ci.yml` and one `runtime/ci.sh` Kind suite of `e2e.yml`, also run on a dependency bump by `deps-test.yml` | when called | — |
| `release-images.yml` | the release images (below) | release tag `v<version>`, by hand | — |

By hand: Actions → the workflow → **Run workflow**, or `gh workflow run e2e.yml -f suite=kind -f
k8s=1.35.0` (`suite`: `all`, `kind`, `ui-stack`; `workers`: `1` for a runner short of disk). The
version is a `kindest-node` tag of the reqs mirror: `1.32.0` (the default, Knative 1.20), `1.34.3`
(Knative 1.23), `1.35.0` (Knative 1.24).

The two e2e suites run on standard GitHub-hosted runners (4 vCPUs, 16 GB, the runner's own
Docker). `ci.sh test` needs neither `/dev/kvm` nor a GPU: its sandbox cases run the gVisor
RuntimeClass on the runc handler (the Kind nodes have no gVisor). The suites CI does not run need
a host of their own: `test-sandbox-api --sandbox-kata` (`/dev/kvm` inside the Kind nodes),
`test-ai` with its GPU case, and the per-feature suites of [Testing](#testing) (`test-runtimes`,
`test-wasm`, …, `test-region-move`, `test-global-projects`).

`upstream-releases.yml` files an issue once per title (`deps: <chart> <version> released`,
`Knative 1.<minor> released: …`): a title an issue already has, open or closed, is not filed again.
`ci/check-helm-releases.sh` reads the version of each chart from the constant the code pins it in
and reports only a newer one; a constant it cannot read fails the check.
`bash ci/check-knative-releases.sh` makes the table change of a released Knative minor in a
checkout.

Only the release workflow builds and pushes images. The images on `ghcr.io/brf-tech/runr` come from
`.github/workflows/release-images.yml` on the public repository's release tag `v<version>`, with
that repository's own `GITHUB_TOKEN`: `api`, `ui` and `ui-mt` at the version (and `latest`), and
the region images of `runtime/ci.sh release-images` the registry does not have yet. Each platform
(linux/amd64, linux/arm64) builds on a runner of its own and is pushed by digest
(`PUSH_BY_DIGEST`), `runtime/ci.sh compose-index` tags the multi-arch indexes, and the last job
pulls every tag without credentials. Every image and index carries
`org.opencontainers.image.source=https://github.com/BRF-Tech/runr`, which links its package to
the repository. The run's artifact `runr-<version>-tars` holds the amd64 `docker save` tars of
`api`, `ui` and `ui-mt` (the images pushed, byte for byte), the chart and `SHA256SUMS`, for
installations that load the images from files.

---

## E2E tests

The E2E tests run against a Kind cluster plus the Docker Compose stack.

### Running

```bash
# With a Kind cluster (created and deleted automatically)
bash runtime/ci.sh test

# A specific Kubernetes version
bash runtime/ci.sh test --k8s=1.35.0

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
  where etcd's fsyncs time out (`etcdserver: request timed out`, build Jobs that never start).
  `test-ai` sets it by default, since its region setup installs several Helm charts while the
  suite's images fill the disk; `KIND_ETCD_IN_MEMORY=0` puts etcd back on the disk
- **Little free disk**: every node holds the images the region pre-pulls (5–7 GB per node with the
  built-in runtimes, measured in WSL 2). `KIND_WORKERS=1` runs one worker (the suites select it as
  the dedicated project worker) and a control-plane without its NoSchedule taint for the platform
  pods; `KIND_WORKERS=0` runs the control-plane alone (no dedicated worker: the suites skip the
  worker selection) — one node less, for a two-cluster suite such as `test-global-projects`, or
  `test-ai`'s CPU cases (`RUNR_E2E_GPU=0`), on a host short of disk; `KIND_PRELOAD=0` leaves out
  loading images into the nodes, which then pull only what they run. `test-ai` with the GPU case
  adds up what the run writes (the suite's images' sizes per Kind node, each node's own data, the
  Compose stack, the preloaded images unless `KIND_PRELOAD=0`, `RUNR_E2E_GPU_RESERVE_GB` for the
  rest) and does not start when Docker's root has less free; its `kind load` archives go to
  `RUNR_E2E_TMPDIR` (default `/var/tmp`) instead of a RAM-backed `/tmp` ([docs/ai.md](ai.md#testing))
- **Single AI cases, a GPU that needs a reset**: `RUNR_E2E_AI_CASES=ai-gpu-caas runtime/ci.sh test-ai`
  runs only the named cases (comma-separated, `test:e2e --ai-cases`) and what they need
  (`ai-binding` → `ai-offline-cpu` → `ai-mirror` → `ai-model`); the GPU case alone pulls and loads
  no hf-serve image. The GPU case ends at once with "host GPU needs a reset (…)" when
  `nvidia-smi --query-gpu=pstate` reports "GPU requires reset" / ERR! or dmesg (where readable) has
  new Xid 119 / 154 lines: before the run (the GPU case alone does not start then) and, every
  `RUNR_E2E_GPU_WATCH_SECONDS` (default 10), while the suite waits for an allocatable
  `nvidia.com/gpu` — instead of after 1200 s
- **Single global-projects cases**: `RUNR_E2E_GLOBAL_CASES=gp-postgres-replica,gp-uploaded-image
  runtime/ci.sh test-global-projects` runs only the named cases (comma-separated,
  `test:e2e --global-cases`), the setup (zone, region B with its Zot registry, the DNS servers, the
  first project and its rollout) and the cases they build on (`gp-data-failover` →
  `gp-switchover` → `gp-postgres-replica` → `gp-p2-project`, `gp-data-services`)
- **Kubeconfig**: for Kind, `kind get kubeconfig --internal` (the API services join the `kind` network and reach the control-plane container by name, on Linux hosts too); for an external cluster, `127.0.0.1` is rewritten to `host.docker.internal`. The file is copied into the container with `docker compose cp`
- **Test command**: `docker compose exec api php artisan test:e2e --base-url=http://localhost --kubeconfig=/tmp/kubeconfig`
- **Cleanup**: `docker compose down` + Kind cluster deletion (unless `--no-cleanup`); with `KIND_LOCK`,
  the lock is released only once the cluster is gone (the delete is retried; a cluster that cannot be
  deleted keeps the lock and the run says how to remove both). A run stopped from outside cleans up
  the same way: on TERM or HUP right away, also in the middle of a step; on INT (Ctrl-C) once the
  running command has stopped. It removes only what it created — its Kind cluster, its Compose
  project, the Kind lock when it holds it, Cloud Provider KIND when no other Kind cluster uses it —
  so a run stopped while it waits for the lock leaves the lock holder alone. When the run's output
  is gone by then (a closed terminal, a log pipe stopped with the run), the cleanup writes to
  `${TMPDIR:-/tmp}/<cluster>-cleanup.log`

### Requirements

- Docker + Compose plugin
- Kind v0.31.0 or later (`go install sigs.k8s.io/kind@latest`), unless an external cluster is used
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
├── runtime/                runr-agent, queue-consumer, image-upload, supply-chain tools,
│                           Spin shim, nginx-test, ci.sh + the e2e harness (the runtime
│                           images: BRF-Tech/runr-runtimes)
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
