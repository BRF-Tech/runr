# runr CLI

`runr` is the command line of RunR. It logs in to a RunR API, scaffolds projects, runs them
locally in Docker on the same runtime images the cluster uses, and deploys them: upload the
code as a new version, deploy it, wait until it serves, print the URL.

```sh
runr login https://runr.example.com
runr init hello --runtime python
cd hello
runr dev          # http://localhost:8080, restarts on every change
runr deploy       # https://hello.<region>.<domain>
```

- [Install](#install)
- [Log in](#log-in)
- [Projects and runr.yaml](#projects-and-runryaml)
- [Deploying](#deploying)
- [Environment variables and secrets](#environment-variables-and-secrets)
- [Local development: runr dev](#local-development-runr-dev)
- [Command reference](#command-reference)
- [Git deploy: runr git](#git-deploy-runr-git)
- [Runtimes and runtime plugins](#runtimes-and-runtime-plugins)
- [Images](#images)
- [Durable workflows](#durable-workflows)
- [Bindings: data services for projects](#bindings-data-services-for-projects)
- [Models and model services](#models-and-model-services)
- [Sandboxes](#sandboxes)
- [Observability: traces, metrics, uptime and alerts](#observability-traces-metrics-uptime-and-alerts)
- [AI agents: runr mcp](#ai-agents-runr-mcp)
- [Supply chain: runr security](#supply-chain-runr-security)
- [Data residency: runr residency](#data-residency-runr-residency)
- [Access control: members, roles, groups](#access-control-members-roles-groups)
- [Audit log: runr audit](#audit-log-runr-audit)
- [Regions](#regions)
- [Output, errors and exit codes](#output-errors-and-exit-codes)
- [CI/CD](#cicd)
- [Configuration reference](#configuration-reference)
- [Building the CLI](#building-the-cli)

## Install

Download the archive for your platform from the
[releases](https://github.com/brf-tech/runr/releases) (Linux, macOS and Windows on amd64 and
arm64; static binaries; `runr_<os>_<arch>.tar.gz`, `.zip` for Windows), check it against
`checksums.txt` and put `runr` on your `PATH`:

```sh
curl -fsSLO https://github.com/brf-tech/runr/releases/latest/download/runr_linux_amd64.tar.gz
curl -fsSLO https://github.com/brf-tech/runr/releases/latest/download/checksums.txt
grep ' runr_linux_amd64.tar.gz$' checksums.txt | sha256sum -c
tar -xzf runr_linux_amd64.tar.gz runr && sudo mv runr /usr/local/bin/
runr version
```

With Go installed: `go install github.com/brf-tech/runr/cli@latest` (the binary is named `cli`;
rename it to `runr`), or build from the repository with `make -C cli build` (`cli/bin/runr`).

Shell completion: `runr completion bash|zsh|fish|powershell`, e.g.
`runr completion bash > /etc/bash_completion.d/runr`.

## Log in

```sh
runr login https://runr.example.com            # prompts for an API token
echo "$TOKEN" | runr login https://runr.example.com --token-stdin
runr login https://runr.example.com --with-password --username ada
```

- **API token** (default): create one in the console (Settings → API Tokens) or with
  `runr tokens create`, then paste it at the prompt, pipe it with `--token-stdin` or set
  `RUNR_TOKEN`.
- **Username and password** (`--with-password`): the CLI signs in with `--username` and the
  password from a hidden prompt or `--password-stdin` (asking for a two-factor code when the
  account has one, or taking `--otp`), creates an API token named `runr-cli@<host>` (or
  `--token-name`) for this machine and ends the sign-in session. `runr logout` revokes that
  token again (`--keep-token` keeps it).

The URL may be the console URL or its API root (`…/api`); without an argument `runr login`
takes `RUNR_API_URL` or asks. The login is stored as a **context** — API URL, token and user —
in `config.yaml` in the OS config directory (`$XDG_CONFIG_HOME/runr/` or `~/.config/runr/` on
Linux, `~/Library/Application Support/runr/` on macOS, `%AppData%\runr\` on Windows) with
`0600` permissions. `RUNR_CONFIG` points to another file.

```sh
runr whoami                  # user, API, context, role and the token's permissions
runr context ls              # all contexts, * marks the current one
runr context use staging
runr --context prod projects ls
runr context rm old
runr logout                  # removes the current context
```

Each login gets its own context, named after the API host unless `--name` is given.
`RUNR_API_URL` and `RUNR_TOKEN` override the context's values (CI needs no login at all);
`RUNR_CONTEXT` selects a context like `--context`.

### Permissions and token scopes

RunR authorizes every API call with role-based access control: a user's role grants
permissions (`projects.deploy`, `code.edit`, `env.edit`, `logs.view`, …), and an API token
can be limited to a subset of them (its scopes). `runr whoami` and `runr login` show the
role, whether the token is scoped and its effective permissions.

When the API refuses a call (HTTP 403), `runr` names the permission it needs and exits with
code 3:

```text
Error: GET /projects/…/logs: 403 You do not have permission to do this (requires logs.view).
The token lacks permission logs.view. Use a token with that scope (runr tokens create NAME --ability logs.view) or ask an administrator for a role that grants it.
```

Create scoped tokens with `--ability` (repeatable) or a preset; a token never exceeds the
permissions of its owner:

| Preset | Scopes | For |
|---|---|---|
| `deploy` | `projects.view`, `regions.view`, `projects.update`, `env.edit`, `code.edit`, `projects.deploy` | `runr deploy` of existing projects (CI) |
| `deploy-create` | `deploy` + `projects.create` | CI that creates the project on the first deploy |
| `terraform` | `projects.view`, `regions.view`, `projects.create`, `projects.update`, `projects.delete`, `env.edit`, `domains.manage`, `projects.deploy` | the Terraform provider |

```sh
runr tokens create github-actions --preset deploy --expires 90d
runr tokens create log-reader --ability logs.view
runr tokens ls
runr tokens rm 42
```

Other commands need: `runr logs` → `logs.view`; `runr env set|unset` → `env.edit` (or
`projects.update`) and `projects.deploy` for the redeploy (or `--no-deploy`); `runr scale` →
`projects.update` and `projects.deploy`; `runr domains add|verify|tls|rm` → `domains.manage`;
`runr projects delete` → `projects.delete`; `runr tokens …` → `tokens.manage`. Reading
(`projects ls|get`, `status`, `versions ls`) needs `projects.view`, `regions ls` needs
`regions.view`. `runr env ls --reveal` needs `secrets.view_values`. The command groups for git
deploy, runtimes, images, workflows, bindings, models, sandboxes, observability, AI agents, the
supply chain, access control and the audit log name the permissions they need at the end of
their sections below.

Tokens created before 0.5.0 with the old scopes (`projects:read`, `projects:write`,
`deployments:read`, `deployments:write`, `settings:read`, `settings:write`) keep working: the
upgrade translates them into the permissions above.

Variable values are visible with `env.view` only (`env.edit` includes it); without it the API
returns the project config without them. `runr env set|unset` then stops with exit code 3
instead of writing a partial set, and `runr deploy` leaves the variables alone and says that
the `env` of `runr.yaml` was not applied.

## Projects and runr.yaml

A RunR project is one of three types:

| Type | What runs | Code |
|---|---|---|
| `faas` | a function: `handler: main.handler` → `def handler(event, context)` in `main.py` | a flat set of files (handler file, helpers, `requirements.txt`, …) |
| `xaas` | a single-file script with a handler function | one file (`file:`) |
| `caas` | a container image | the image (`image:` or `--image`) |

Each save of code or config is a **version** (`v1`, `v2`, …); a deployment runs one version.
Rolling back means deploying an earlier version.

`runr init [dir]` writes a project directory: `runr.yaml`, a starter handler for the runtime
and a `.gitignore`. The project is named after the directory unless `--name` is given;
`--force` overwrites existing files.

```sh
runr init hello                                  # faas, python
runr init api --runtime nodejs --region eu-1
runr init job --type xaas --runtime php
runr init web --type caas --image ghcr.io/acme/web:1.0
runr init hello-q --runtime q                    # a runtime plugin
```

For a runtime plugin the starter files come from the platform's runtime catalog
(`GET /runtimes/{id}/template`), so `runr init` needs a login for those; a FaaS project gets the
runtime's default handler (`handler.file`.`handler.function`, e.g. `lib.handle` for `wasm-rust`).

### runr.yaml

`runr.yaml` (or `runr.yml`) describes the project. Commands find it in the working directory
or a parent directory; `--dir` points elsewhere. Unknown keys are rejected.

```yaml
name: orders-api          # project name; its slug names the service and the URL
type: faas                # faas | xaas | caas
runtime: python           # faas/xaas: python, nodejs, go, php, tensorflow, pytorch, wasm-*, a runtime plugin
runtime_version: "3.13"   # optional; default: the platform's default version
region: eu-1              # region name or id; optional when one region is available
description: Order intake

handler: main.handler     # faas: file.function (main.handler → main.py, def handler)
                          # xaas: the function name (default handler)
# file: main.py           # xaas: the script

# image: ghcr.io/acme/web:1.4.2    # caas
# command: [/bin/server]           # caas: entrypoint override
# args: [--port, "8080"]           # caas
# ports: [8080]                    # caas: the first port serves HTTP (default 80)

resources:
  cpu: 250m               # Kubernetes CPU quantity
  memory_mb: 256          # at least 64
  timeout_seconds: 30

scale:
  min: 0                  # 0-10
  max: 10                 # 1-1000
  to_zero: true           # scale to zero instances when idle

trigger:
  type: http              # http (default) | schedule | queue
  # schedule: "*/5 * * * *"  # schedule: five-field cron expression
  # queue: orders            # queue: Redis stream name (the region needs Redis)

env:                      # non-secret variables (secrets: runr env set --secret)
  LOG_LEVEL: info

ignore: ["*.md", "tests"] # extra files that are not uploaded

dev:                      # runr dev
  port: 8080
  env:
    LOG_LEVEL: debug
```

| Key | Types | Default | Notes |
|---|---|---|---|
| `name` | all | (required) | The slug (lower case, `-` for other characters) must start with a letter, at most 44 characters. |
| `type` | all | (required) | `faas`, `xaas`, `caas`; fixed after creation. |
| `runtime` | faas, xaas | (required) | Fixed after creation. `runr runtimes ls` lists the platform's runtimes: the built-in ones and [runtime plugins](runtimes.md). |
| `runtime_version` | faas, xaas | platform default | |
| `region` | all | the only active region | Changing it moves the project. |
| `description` | all | | |
| `handler` | faas, xaas | `main.handler` / `handler` | |
| `file` | xaas | `main.<ext>` | |
| `image`, `command`, `args`, `ports` | caas | | `image` is required when the project is created. |
| `resources.cpu`, `.memory_mb`, `.timeout_seconds` | all | platform defaults | Checked against the region's capacity. |
| `scale.min`, `.max`, `.to_zero` | all | platform defaults | |
| `trigger.type`, `.schedule`, `.queue` | all | `http` | `http`, `schedule` or `queue`; one trigger per project. The `workflow` trigger of [durable workflows](workflows.md) is set in the console or through the API. |
| `env` | all | | Merged into the project's variables: listed keys are set, others stay. |
| `ignore` | faas | | Glob patterns; see below. |
| `dev.port`, `dev.env` | faas, xaas, caas | `8080` | Local only. |

Only the keys you write are applied: a key left out keeps the value the project has on the
server (set in the console, by Terraform or by another tool).

### Which files are uploaded

A `faas` project uploads every file of the project directory except:

- `.git`, `.hg`, `.svn`, `.runr`, `.idea`, `.vscode`, `node_modules`, `vendor`,
  `__pycache__`, `*.pyc`, `.venv`, `venv`, `.env`, `.env.*`, `.gitignore`, `.DS_Store`,
  `Thumbs.db`, `runr.yaml` / `runr.yml` and `.runrignore`;
- the patterns of `ignore:` in `runr.yaml` and of a `.runrignore` file (one pattern per line,
  `#` comments). A pattern without `/` matches any path element (`tests`, `*.md`); with `/`
  it matches the path (`docs/*.md`).

The files form a flat set (each becomes a key of the project's ConfigMap): subdirectories are
refused with the list of offending files. Files must be UTF-8 text and not empty, and names
plus contents stay below 1,000,000 bytes. Dependencies are declared, not uploaded:
`requirements.txt` (Python), `package.json` (Node.js), `composer.json` (PHP), `go.mod` (Go);
RunR installs them in a build when the version is saved. An `xaas` project uploads its one
script.

## Deploying

```sh
runr deploy                         # project of ./runr.yaml
runr deploy ./services/api -m "fix: timeout"
runr deploy --project web --image ghcr.io/acme/web:1.4.3
runr deploy --version v7            # deploy a saved version again
runr deploy --no-wait --output-env "$GITHUB_OUTPUT"
```

`runr deploy`:

1. finds the project by name (or `--project`); if it does not exist, creates it from
   `runr.yaml` in its region;
2. applies config changes of `runr.yaml` (resources, scale, trigger, env, handler, image, …)
   — only fields that differ are sent, as one new version;
3. saves the code as a new version (unchanged code reuses the latest version); caas projects
   deploy their latest version, `--image` writes a new image first;
4. waits for the build when the runtime compiles (Go) or dependencies are installed;
5. deploys the version and waits until it is ready (`--no-wait` returns at once,
   `--timeout` bounds the wait, default 10 minutes);
6. prints the URL (HTTP trigger).

The type and runtime of an existing project cannot change; `runr deploy` refuses a
`runr.yaml` that disagrees with the server.

Related commands:

```sh
runr status                 # project, live deployment, URL
runr versions ls            # * marks the deployed version
runr rollback v6            # deploy an earlier version
runr logs -f                # follow the live deployment's logs
runr shell                  # a shell in a pod of the live deployment
runr open                   # open the URL (--print prints it)
runr scale --min 1 --max 20 # change the scale range and redeploy
```

`runr rollback` runs the earlier version's code with the current config (variables,
resources, scale). For caas projects the image is part of the config, so rollback writes the
version's image, command and arguments back as a new version and deploys that.

`runr shell` opens a shell in a pod of the live deployment (`--deployment ID`: another active
one) through RunR's [web shell](web-shell.md), the console's Shell tab: a service scaled to zero
is woken and kept running while the session is open. It behaves like `runr sandbox terminal`:
on a terminal every key goes to the shell and the size follows the window, piped stdin is typed
input ending with Ctrl+D, `-o json` prints the one-time session instead of attaching. It needs
`shell.exec` on the project.

### Custom domains

```sh
runr domains add api.example.com     # prints the TXT and CNAME/A records to create
runr domains verify api.example.com  # checks DNS and activates the domain
runr domains tls api.example.com --mode cert-manager
runr domains tls api.example.com --mode custom --cert fullchain.pem --key privkey.pem
runr domains rm api.example.com --yes
```

## Environment variables and secrets

```sh
runr env ls                              # values masked; --show-values prints plain values
runr env set LOG_LEVEL=debug REGION=eu
runr env set --from-file .env.production
runr env set --secret STRIPE_KEY         # hidden prompt, or the value from stdin
printf %s "$DATABASE_URL" | runr env set --secret DATABASE_URL
runr env set --secret --from-file .env.secrets --no-deploy
runr env ls --reveal                     # secret values too (secrets.view_values, audited)
runr env unset LOG_LEVEL STRIPE_KEY      # plain and secret alike
```

A project has two kinds of variables ([details](security.md#secret-environment-variables)):

- **plain** variables are part of the project config: a change saves a new version;
- **secret** variables (`--secret`) are stored write-only. RunR lists their names, when they
  were last changed and by whom, never their values; they never appear in the config, a
  version or the audit log. On deploy they reach the function through a Kubernetes Secret
  (`secretKeyRef`), not as values in the service spec.

Either kind takes effect with the next deploy, so `runr env set` and `runr env unset` redeploy
the live version afterwards (`--no-deploy` only saves; the next deployment picks the change
up). Names are shell identifiers; `PORT`, `K_SERVICE`, `K_CONFIGURATION`, `K_REVISION` and the
`RUNR_*` variables of the runtime are set by RunR and refused.

A name is plain or secret, never both. Setting a plain name with `--secret` (or a secret name
without it) moves the variable in one update. How the CLI writes the change:

| Change | API call |
|---|---|
| secret variables only (set or unset) | `PUT` / `DELETE /projects/{project}/secrets/{name}`, one per variable; no new version |
| plain variables, or names that move between plain and secret | one `PUT /projects/{project}` with `config.env_vars` and, for the moves, `config.secret_env_vars` |

Secret values are at most 32 KiB, a project has at most 100 secret variables; the CLI checks
both before it sends anything. With `--secret` and a single `KEY` without `=VALUE`, the value
comes from a hidden prompt on a terminal or from stdin (one trailing newline removed), so it
stays out of the shell history.

`runr env ls` shows secret values as `(write-only)`. `--reveal` reads them back with
`GET /projects/{project}/secrets/{name}/value`: that needs the `secrets.view_values`
permission (of the built-in roles only Owner and Admin have it) and every read is recorded in
the audit log as `project.secret.reveal`. Without the permission the command stops with exit
code 3 before it reads anything. `--reveal` also prints plain values.

On an API without secret variables the CLI stores a `--secret` value as a plain variable and
prints a warning. `runr.yaml` holds plain variables only; keys set with the CLI and not listed
in `runr.yaml` are kept by `runr deploy`.

## Local development: runr dev

```sh
runr dev                    # http://localhost:8080
runr dev --port 9000 -e DEBUG=1
runr dev --print            # show the docker commands instead of running them
```

`runr dev` runs the project in Docker the way the cluster runs it:

- **Same image and wrapper.** The runtime image of the project's runtime and version — the
  image the platform's runtime catalog names when you are logged in (the version defaults to
  the platform's default), else `ghcr.io/brf-tech/runr/exec-<runtime>:<version>` with the CLI's
  default version — started with its own entrypoint (the HTTP server that calls your
  handler).
- **Same environment.** The project directory is mounted read-only and copied to
  `/app/code` (the cluster mounts the code there from a ConfigMap); `RUNR_MODE`,
  `RUNR_HANDLER`, `RUNR_FILENAME` and `PORT` are set like the Knative Service sets them, plus
  `env` and `dev.env` of `runr.yaml` and `--env`.
- **Same dependency install.** When the dependency file is present it is installed with the
  build's command (`pip install --target`, `npm install --production`, `composer install`)
  into a cache in the user cache directory, mounted where the build puts it
  (`/app/vendor` with `PYTHONPATH`, `/app/code/node_modules`, `/app/code/vendor`). It is
  installed again only when the file changes. Go modules and build caches are kept between
  runs.
- **Hot reload.** The directory is watched; a change restarts the container (handlers are
  loaded at start), a change of `runr.yaml` is applied, a change of the dependency file
  installs again.
- **caas projects** run their image with the first port (`ports:`) published.
- **Runtimes.** Built-in runtimes (python, nodejs, go, php, tensorflow, pytorch) run on their
  runtime image as described above; projects of [runtime plugins](runtimes.md) run the way their
  manifest tells the cluster to run them (next section). WebAssembly runtimes (`wasm-rust`,
  `wasm-go`, `wasm-js`, `wasm-python`, `wasm-exec`) run as components on the region's
  WebAssembly shim (Spin), not in a container: `runr dev` names the shim and points to
  `runr deploy` for them.

The function is served on `127.0.0.1` only. Stop with Ctrl+C.

### Runtime plugins in runr dev

For a project whose runtime is a plugin, `runr dev` reads the plugin's manifest from the
platform's catalog (`GET /runtimes/{id}`, so it needs a login) and builds the same container the
cluster runs:

| In the cluster | In `runr dev` |
|---|---|
| The runtime version's `image` | the same image |
| A `dockerfile` or `git` version, built by RunR in each region | built locally with `docker build` from the files uploaded with the runtime (or the Git URL, `ref` and `path`), tagged `runr-dev-runtime-<id>:<version>-<digest>`; an unchanged source reuses the image |
| exec and persistent runtimes: the universal wrapper (`runr-wrapper`) as entrypoint, copied into the pod by an init container (`runr-wrapper install /runr/runr-wrapper`) | the same install runs from the wrapper image (`ghcr.io/brf-tech/runr/wrapper:1.1.0`, or `RUNR_WRAPPER_IMAGE`) into the cache directory, which is mounted read-only at `/runr`; the container's entrypoint is `/runr/runr-wrapper` |
| http runtimes: `run.command` (or the image's entrypoint) serves `$PORT` | the same command as entrypoint, no wrapper |
| The code as a ConfigMap at `/app/code` | the files `runr deploy` uploads (FaaS: the flat file set after the ignore rules; XaaS: the script), copied into the cache and mounted read-only at `/app/code` |
| Compiled runtimes (`build`) and FaaS projects with the dependency file: a Kaniko build of a two-stage Dockerfile, wrapper baked in at `/runr/runr-wrapper` | the same Dockerfile, built locally with `docker build` on every start (Docker's layer cache keeps unchanged steps); the image carries code, build output, dependencies and wrapper |
| `RUNR_TRIGGER`, `RUNR_HANDLER`, `RUNR_FILENAME`, `RUNR_CODE_DIR`, `RUNR_RUNTIME`, `RUNR_RUNTIME_VERSION`, `RUNR_TIMEOUT`, `run.env`, `RUNR_WRAPPER_MODE`, `RUNR_COMMAND`, `RUNR_INPUT` / `RUNR_WORKERS` | the same variables with the same placeholders resolved; the project's variables (`env`, `dev.env`, `--env`) win over them, `PORT` is `8080` |

The handler file follows the manifest: `handler.file` and the first of `extensions` (an XaaS
project's `file:` and a FaaS project's `handler: file.function` choose another one), and the
project's trigger must be among the runtime's `triggers`. Like for built-in runtimes the dev
server always serves HTTP: a queue project's wrapper gets `RUNR_TRIGGER=queue`, so a queue
delivery sent with `runr dev invoke --trigger queue` reaches the handler as the queue consumer
delivers it; a schedule project's server listens like an http project's. A change of a file
restarts the container (and rebuilds a built function), a change of `runr.yaml` is applied. When
a build fails, the error is printed and the session waits for the next change (`--no-watch`
stops with the error).

`runr dev --print` shows every step: the wrapper install, the image builds and the `docker run`
command, and for built functions the generated Dockerfile (`-o json`: `docker_prepare`,
`docker_run`, `dockerfile`).

### Triggers: runr dev invoke

The dev server always speaks HTTP, also for functions with a schedule or queue trigger.
`runr dev invoke` sends an event the way the trigger delivers it:

```sh
runr dev invoke --event '{"name":"Ada"}'                  # http: POST with the event
runr dev invoke -X GET --path /health                       # http without a body
runr dev invoke --trigger queue --event '{"order_id":42}'   # like the queue consumer
runr dev invoke --trigger schedule                          # like the scheduler
runr dev invoke --event @event.json -o json
```

| Trigger | Request |
|---|---|
| `http` | the event as JSON body (`POST`; `GET` without an event; `-X/--method` sets another), `--path` (default `/`), `-H/--header` (repeatable) |
| `queue` | `POST` of the stream message as JSON with CloudEvent headers (`ce-type: dev.runr.queue.message`, `ce-source: /redisstream/<queue>/…`, `ce-id`, `ce-time`, `X-RunR-Delivery-Attempt`) — what the queue consumer sends |
| `schedule` | `POST {"trigger":"schedule"}` (or `--event`) with `X-RunR-Trigger: schedule`. Runtime plugins: exec and persistent runtimes run one tick through the wrapper inside the session container (`docker exec -e RUNR_TRIGGER=schedule <container> /runr/runr-wrapper`, what the CronJob runs; its output is the answer, a failed tick answers 500); http runtimes get the wrapper's schedule CloudEvent (`ce-type: dev.runr.schedule.tick`, `ce-source: /runr/schedule`) |

The trigger defaults to the one in `runr.yaml`; the running session is found through its
state file in the cache directory, `--url` addresses another server.

Requirements: Docker (the `docker` CLI on `PATH`, or `RUNR_DOCKER`). Images the CLI names
itself (runtime images without a login, the Composer builder of PHP, the wrapper) come from
`ghcr.io/brf-tech/runr`; `RUNR_RUNTIME_REGISTRY` points to a mirror.

## Command reference

Every command, grouped like the sections of this page; `runr <command> --help` lists all flags.
Most groups without a subcommand run their `ls`, `get` or `status` command (`runr env` is
`runr env ls`, `runr git` is `runr git status`, `runr mcp-server` is `runr mcp-server get`);
`[ls]` and `[get]` below mark them.

| Command | What it does |
|---|---|
| `runr login [url]` | Save an API URL and token as a context (`--token-stdin`, `--with-password`, `--username`, `--password-stdin`, `--otp`, `--token-name`, `--name`) |
| `runr logout` | Remove the current context and revoke the token `login --with-password` created (`--keep-token`) |
| `runr whoami` | User, API, context, role and token permissions |
| `runr context ls\|use\|rm\|current` | Manage contexts |
| `runr tokens ls\|create\|rm` | API tokens (`--ability`, `--preset`, `--expires`) |
| `runr init [dir]` | Scaffold a project (`--name`, `--type`, `--runtime`, `--runtime-version`, `--region`, `--image`, `--force`) |
| `runr deploy [path]` | Create/sync, upload, build, deploy, wait (`--image`, `--version`, `--region`, `-m`, `--no-wait`, `--timeout`, `--output-env`, `-f`) |
| `runr status` | Project, live deployment and URL |
| `runr projects ls\|get\|delete` | List, show, delete projects (`delete --yes` without a prompt) |
| `runr versions ls` | Versions, newest first |
| `runr rollback VERSION` | Deploy an earlier version (`v3`, `3` or an id; `--no-wait`, `--timeout`) |
| `runr logs [-f]` | Logs of the live deployment (`--deployment`) |
| `runr shell` | A shell in a pod of the live deployment through the web shell (`--deployment`; `-o json` prints the one-time session) |
| `runr scale` | `--min`, `--max`, `--to-zero`, then redeploy (`--no-deploy`, `--timeout`) |
| `runr open` | Open the project URL (`--print`) |
| `runr domains ls\|add\|verify\|tls\|rm` | Custom domains, their DNS records and certificates (`tls --mode none\|cert-manager\|custom`, `--cert`, `--key`; `rm --yes`) |
| `runr regions ls` | Regions, their status and whether queues (Redis) are available |
| `runr regions get [REGION]` | A region: cluster, Knative, Redis, registry, worker nodes, capacity, settings |
| `runr regions create NAME --kubeconfig FILE` | Connect a cluster as a region (`--node`, `--inactive`, `--redis`, `--redis-address`, `--redis-password-file`, `--prometheus-url`, `--loki-url`, `--registry internal\|external`, `--registry-storage`, `--storage-class`, `--registry-size`, `--registry-s3`, `--registry-address`, `--registry-credentials`, `--retry`, `--wait`) |
| `runr regions check [NAME] --kubeconfig FILE` | Kubeconfig, Kubernetes version, worker nodes, StorageClasses and the namespace of a cluster before `create` |
| `runr regions set REGION` | Status, settings or kubeconfig of a region (`--set status\|request_timeout\|agent_ws_url\|cert_manager_cluster_issuer\|ingress_trusted_ips=…`, `--kubeconfig`) |
| `runr regions rm REGION` | Remove a region from RunR (`--yes`) |
| `runr regions status [REGION]` | Check the cluster and its Knative installation now (`--refresh`) |
| `runr regions kubeconfig REGION` | Save the region's kubeconfig with mode 0600 (`-f`, `--force`; `--stdout` prints it) |
| `runr regions nodes [ls]\|set REGION` | Worker nodes and the ones projects run on (`set NODE…`, `--all`, `--yes`) |
| `runr regions knative [status]\|install\|uninstall\|detect\|versions\|namespace` | Knative operator and Serving of a region (`[all\|operator\|serving]`, `--namespace`, `--version`, `--remove-traefik`, `--wait`, `--yes`) |
| `runr regions redis [status]\|test\|set\|rm` | The Redis of queue triggers (`--mode`, `--address`, `--password-file`, `--wait`, `--yes`) |
| `runr regions registry [status]\|rm\|setup-status\|test` | The region's image registry, the setup of a region created with `--registry`, checks of a StorageClass, bucket or external registry (`--wait`, `--yes`) |
| `runr regions migration [status]\|start\|complete\|rollback` | Move a region to another cluster (`--kubeconfig`, `--data-location`, `--wait`, `--yes`) |
| `runr regions function-tls [get]\|set` | The certificate of the region's function domains (`--mode console\|custom`, `--cert`, `--key`, `--replace-existing`) |
| `runr regions skupper [status]\|site` | Skupper for private database connections; create the project namespace's site |
| `runr regions capacity [get]\|set` | Capacity budgets and FaaS overcommit factors (`--set faas_cpu_overcommit=…`) |
| `runr regions demo [status]\|install\|rm` | Demo projects of a region (`--wait`, `--yes`) |
| `runr regions wasm [status]\|install\|uninstall` | WebAssembly support: shims, nodes, eligibility (`--no-cluster`, `--shim`, `--node`, `--wait`, `--yes`) |
| `runr env ls\|set\|unset` | Plain and secret environment variables (`--secret`, `--from-file`, `--no-deploy`, `--timeout`, `--show-values`, `--reveal`) |
| `runr dev [path]` | Run locally in Docker with hot reload, built-in runtimes and runtime plugins (`--port`, `-e`, `--no-watch`, `--print`) |
| `runr dev invoke` | Send an http, queue or schedule event to the dev server (`--trigger`, `--event`, `-X`, `--path`, `-H`, `--url`) |
| `runr git [status]` | Remote, settings, connected repository and the last git deploys (`git status --deploys N`) |
| `runr git remote [NAME]` | The project's git remote; `--add` adds it to the local repository (`--force`, `--print`) |
| `runr git deploys` | Git deploy history (`--kind production\|preview`, `--limit`) |
| `runr git redeploy` | Deploy the head of the deploy branch again (`--wait`, `--timeout`) |
| `runr git set` | Deploy branch, root directory, auto-deploy, force pushes, preview settings (`--branch`, `--root-dir`, `--auto-deploy`, `--previews`, `--preview-env`, …) |
| `runr git connect` | Connect a GitHub, GitLab or Forgejo/Gitea repository (`--provider`, `--repo`, `--installation`, `--token-stdin`, `--oauth`, `--branch`, `--root-dir`, `--previews`) |
| `runr git repos\|installations` | Repositories the credentials can deploy from; linked GitHub App installations |
| `runr git disconnect` | Disconnect the repository (`--yes`) |
| `runr git previews [ls]\|get\|redeploy\|rm` | Preview environments of pull and merge requests (`--all`, `--wait`, `--yes`); also `runr previews` |
| `runr git providers [ls]\|get\|create\|update\|rm` | GitHub Apps, GitLab and Forgejo/Gitea instances of the installation (secrets from files or stdin) |
| `runr runtimes [ls]` | Runtimes (built-in, plugins, Wasm) and versions: kind, run mode, end-of-life date, image (`--all`, `--region`) |
| `runr runtimes get RUNTIME` | Run mode, handler, triggers, regions, versions and files of a runtime (`--manifest` prints the manifest) |
| `runr runtimes register -f FILE` | Register a runtime plugin from a manifest (`--files`, `--region`, `--wait`) |
| `runr runtimes update RUNTIME` | Change manifest and files, regions, enabled (`-f`, `--files`, `--region`, `--all-regions`, `--enable`, `--disable`, `--wait`) |
| `runr runtimes rm RUNTIME` | Delete a registered runtime (`--yes`) |
| `runr runtimes validate -f FILE` | Check a manifest without registering it (`--files`) |
| `runr runtimes template RUNTIME` | Starter files (`--type`, `--handler`, `--out`, `--force`) |
| `runr runtimes builds\|build RUNTIME` | Region image builds of dockerfile and git versions; `build` rebuilds (`--region`, `--version`, `--wait`) |
| `runr runtimes test-deploy RUNTIME` | Deploy the starter template as a test project (`--region`, `--version`) |
| `runr images push FILE` | Upload a `docker save` / OCI layout tar or a `.wasm` into a region registry (`--region`, `--name repo:tag`, `--format`) |
| `runr images ls\|get\|rm` | List uploads (`--region`, `--kind image\|wasm\|spin`); show one; delete one (`--region`, `--yes`) |
| `runr images uploads [ls]\|rm` | Unfinished uploads; abort them (`--yes`) |
| `runr workflows ls\|get` | Workflow definitions: triggers, options, state and run counts |
| `runr workflows run NAME` | Start a run (`--event`, `--idempotency-key`, `--test`, `--wait`, `--timeout`) |
| `runr workflows run get\|cancel\|retry\|replay RUN_ID` | Inspect and control a run (`--from-step`, `--wait`, `--yes`); also as `runr workflows runs …` |
| `runr workflows runs [NAME]` | Newest runs (`--status`, `--source`, `--search`, `--since`, `--from`, `--to`, `--limit`) |
| `runr workflows enable\|disable NAME` | Resume or pause a workflow (aliases `resume`, `pause`) |
| `runr workflows sync` | Read the definitions from the deployed function |
| `runr workflows declare -f FILE` | Declare the definitions through the API (JSON or YAML; `--yes`) |
| `runr workflows events send\|ls\|get` | Send events (`--data`, `--id`, `--ts`, `--test`), list them (`--name`, `--source`, `--since`, `--from`, `--to`, `--limit`), show one |
| `runr workflows app [get]\|set\|rotate-key\|signing-key` | The function the engine drives: endpoint override (`--endpoint`, `--reset-endpoint`), key rotation with a redeploy (`--no-deploy`), the signing key (`--env`) |
| `runr workflows usage` | Step executions and started runs per workflow (`--from`, `--to`; `-p` for one project) |
| `runr bindings ls\|types\|quota` | Bindings of the organization (`--type`, `--region`, `--deleted`), binding types and limits (`quota --tenant`) |
| `runr bindings quota set --tenant ID` | An organization's binding limits; platform administrators (`--max-bindings`, `--kv-mb`, `--object-gb`, `--postgres-gb`, `--dedicated-postgres`, `--unset`) |
| `runr bindings get BINDING` | A binding, its live health and attached projects (`--usage`, `--days`) |
| `runr bindings create NAME` | Create a binding (`--type kv\|s3\|postgres\|external`, `--plan`, `--region`, `--set`, `--config`, `--value`, `--from-file`, `--attach`, `--env-prefix`, `--wait`) |
| `runr bindings update BINDING` | Change limits (`--set`, `--config`) or an external binding's variables (`--value`, `--from-file`) |
| `runr bindings attach\|detach BINDING` | Attach a binding to the project (`--env-prefix`) or detach it |
| `runr bindings attachments` | Bindings attached to the project and those that can be attached |
| `runr bindings rotate\|rm\|restore BINDING` | New credentials (`--wait`), delete with retention, restore (`--yes` for rotate and rm) |
| `runr bindings credentials BINDING` | The binding's connection values (audited; `--env-prefix`) |
| `runr regions data-services [ls]\|install\|uninstall REGION` | A region's data services kv, object, postgres (`--live`, settings flags, `--credentials`, `--wait`) |
| `runr models search\|info\|add\|ls\|get\|rm\|engines` | Hugging Face Hub search and the organization's model library |
| `runr models hub-token [status]\|set\|rm` | The organization's Hugging Face token (`--token-stdin`, `--validate`) |
| `runr models snapshot\|snapshots MODEL` | Offline copies of a model per region (`--region`, `--no-wait`, `--timeout`) |
| `runr models snapshots rm\|logs MODEL SNAPSHOT` | Remove an offline copy, read its Job logs (`--yes`) |
| `runr models upload DIR` | Chunked upload of a model folder into a region (`--model`, `--task`, `--region`, `--resume`, `--no-checksum`) |
| `runr models deploy MODEL` | Create and deploy a model service, wait until it is ready (settings flags, `--no-start`, `--no-wait`, `--timeout`) |
| `runr models services [ls]\|get\|update\|start\|stop\|logs\|bindings\|rm` | Manage model services |
| `runr models services security SERVICE` | Supply-chain report of the service's image (`--sbom`, `--sbom-format`, `--fail-on`, `--all-findings`) |
| `runr models invoke SERVICE` | Send one request to a model service (`--path`, `-X/--method`, `-d/--data`) |
| `runr models bind\|unbind\|bindings` | The model services a project uses (`--alias`, `--mount-weights`) |
| `runr models gpu-quota [set]` | The GPU quota on a region; `set` for platform administrators (`--region`, `--tenant`, `--gpus`, `--unlimited`) |
| `runr regions gpu [status]\|install\|uninstall\|set` | GPU support of a region: device plugin or GPU operator, nodes, allocation, settings (`--refresh`, `--mode`, `--time-slicing`, `--runtime-class`, `--gpu-node`, `--gvisor-nvproxy`, `--wait`, `--yes`) |
| `runr regions model-storage [get]\|set` | The claim model weights live on (`--storage-class`, `--size`, `--access-mode`) |
| `runr sandbox create` | Create a sandbox, wait until it runs, print its id (`--runtime`, `--image`, `--ttl`, `--allow`, `-e`, `--class`, `--region`, `--no-wait`, …) |
| `runr sandbox exec ID -- CMD` | Run a command with streamed output; exits with its exit code (`--cwd`, `-e`, `--timeout`, `-i`; the exec WebSocket for `-i` on a terminal, `--ws` / `--ws=false` to choose; `-t` / `-it` on a terminal) |
| `runr sandbox run [FILE\|-]` | Run code with the code interpreter, in a throw-away sandbox unless `--sandbox` (`--lang`, `--code`, `--keep`, `--outputs`) |
| `runr sandbox cp SRC DST` | Copy files or directories into or out of a sandbox (`ID:/path`, `-` for stdin/stdout; `--timeout`) |
| `runr sandbox ls\|get\|files\|logs\|rm` | List, show, browse, read the activity log of and remove sandboxes (`logs --pod`, `rm --yes`, `--all`) |
| `runr sandbox files mkdir\|rm ID PATH…` | Create directories with their parents; remove files and directories (`-r`, `-f`) |
| `runr sandbox start ID -- CMD` | Start a background process and print its id (`--cwd`, `-e`, `-f` to stream it) |
| `runr sandbox ps ID` | Background processes of a sandbox: pid, status, exit code, command |
| `runr sandbox logs ID [PROCESS]` | The activity log, or a process's output (`--tail`, `-f` streams until it ends and exits with its code) |
| `runr sandbox kill ID PROCESS` | Send a signal to a process (`-s/--signal`, default `TERM`) |
| `runr sandbox terminal ID` | Interactive shell through the web shell's WebSocket (aliases `shell`, `attach`; `-o json` prints the one-time session) |
| `runr sandbox pause\|resume\|keepalive ID` | Freeze and resume a sandbox, reset its idle timer |
| `runr sandbox extend ID --ttl D` | End the sandbox a given time from now (alias `timeout`) |
| `runr sandbox network ID` | Show or replace the network of a sandbox (`--allow HOST[:PORT]`, `--none`) |
| `runr sandbox regions [ls]\|get\|set` | Sandbox settings of the regions (`--enable`, `--disable`, `--allowed-class`, `--all-classes`, `--default-class`, `--allow-runc`, `--default-ttl`, `--max-ttl`, `--max-cpu`, `--max-memory`, `--max-disk`, `--unset`) |
| `runr sandbox pools ls\|create\|update\|rm` | Warm pools (`--region`, `--runtime`, `--size`, `--memory`, `--class`, `--enable`, `--disable`) |
| `runr sandbox quotas [get]\|ls\|set\|reset` | Sandbox limits and usage (`--max-concurrent`, `--max-cpu`, `--max-memory`, `--max-time`, `--max-pool-size`, `--allowed-class`, `--unset`) |
| `runr sandbox usage\|metrics` | Daily usage and cost (`--from`, `--to`); active and warm sandboxes, pool hit rate, start times (`--window`, `--region`, `--all-organizations`; `--prometheus` prints the scrape format) |
| `runr regions sandbox [status]\|shims [REGION]` | Kata Containers and gVisor on a region, the project type mapping; the Kata hypervisors the nodes can run and their `/dev/kvm` (`--recheck`) |
| `runr regions sandbox install\|uninstall REGION kata\|gvisor` | Install or remove a sandbox runtime on the nodes (`--hypervisor`, `--devmapper-pool-gb`, `--wait`, `--timeout`, `--yes`) |
| `runr regions sandbox map REGION` | The runtime class of each project type and of image builds (`--faas`, `--xaas`, `--caas`, `--build`) |
| `runr traces [ls]` | Search the project's traces (`--since`, `--until`, `--status`, `--min-duration`, `--path`, `--limit`) |
| `runr traces get TRACE_ID` | A trace as a waterfall (`--logs` adds its log lines) |
| `runr traces status\|enable\|disable` | Tracing and replay switches; `enable` / `disable` redeploy (`--sample-ratio`, `--no-deploy`) |
| `runr traces replay ls\|get\|run\|rm\|enable\|disable` | Captured failed requests and their replay with a diff (`-H`, `--query`, `--all`) |
| `runr metrics [history\|live]` | Recorded metrics: a summary of the last `--period` (`7d`, `30d`, `90d`); the snapshots (`--since`, `--from`, `--to`, `--granularity`); the live deployment's usage (`--quick`, `--deployment`, `--watch`) |
| `runr uptime ls\|create\|update\|rm\|results\|run` | Uptime checks (`--path`, `--url`, `--interval`, `--expect`, `--region`, `--status-page`) |
| `runr uptime incidents [update]` | Incidents of the checks (`--open`, `--title`, `--message`) |
| `runr alerts channels ls\|create\|update\|rm\|test` | Alert channels: email, slack, teams, telegram, webhook (secrets write-only) |
| `runr alerts rules ls\|create\|update\|rm` | Alert rules (`--type`, `--param`, `--severity`, `--cooldown`, `--channel`) |
| `runr alerts events\|deliveries [retry]` | Fired alerts and the delivery log (`--firing`, `--failed`, `--channel`) |
| `runr status-page [get]\|set` | The organization's public status page (`--slug`, `--title`, `--public`, `--show`, `--hide`) |
| `runr regions tracing [get]\|set\|install\|rm REGION` | A region's tracing backend: mode, Tempo, OTLP export, sampling, retention (`--mode`, `--export-headers`, `--install`, `--wait`) |
| `runr mcp [config]` | Endpoint of the RunR MCP server and the configuration of Claude Code, Claude Desktop, Cursor and VS Code (`--client`, `--auth oauth\|token`, `--token`, `--token-stdin`) |
| `runr mcp token create [NAME]` | An API token for an AI agent, printed once with its client configuration (`--preset read\|deploy\|full`, `--group`, `--scope`, `--expires`, `--client`) |
| `runr mcp clients [ls]\|revoke CLIENT` | OAuth clients connected with your account; revoke their tokens (`--yes`) |
| `runr mcp tools` | The tool catalog: permissions and whether the token may use each tool (`--group`, `--search`, `--allowed`) |
| `runr mcp-server [get]\|ls\|templates` | The project's hosted MCP server and its client configuration (`--client`), every hosted server, the starters |
| `runr mcp-server set` | Serve the project as an MCP server or change it, then redeploy for the gateway key (`--access-mode`, `--path`, `--audit-calls`, `--no-deploy`) |
| `runr mcp-server rotate-key\|rm` | New gateway key with a redeploy (`--no-deploy`); stop serving the project (`--yes`) |
| `runr mcp-server calls` | The gateway's call log (`--outcome`, `--method`, `--limit`) |
| `runr mcp-server inspect` | Talk to the server through the gateway: list tools, call one, send any message (`--tool`, `--args`, `--method`, `--params`, `--message`) |
| `runr security [PROJECT]` | Supply-chain report: versions, findings, deploy policy (`--version`, `--live`, `--scan --wait`, `--sbom`, `--fail-on`) |
| `runr regions supply-chain [get]\|set REGION` | A region's scans, scanner DB, policy gate, signing, admission policy, CaaS image policy and data location (`--set KEY=VALUE`, `--trusted-key`) |
| `runr regions supply-chain signing-key rotate\|forget-retired`, `scanner-db refresh`, `admission install\|uninstall\|preview`, `public-key`, `runtime-images [sbom]` | Actions of a region's supply chain (`--wait`, `--timeout`, `--yes`, `--mode`, `--file`, `--format`) |
| `runr residency` | Regions the organization may deploy to and their data locations |
| `runr residency policy\|set` | An organization's residency and policy gate on a multi-tenant installation (`--tenant`, `--allow-region`, `--allow-location`, `--default-region`, `--enforce`, `--gate-mode`, `--gate-max-critical`, `--gate-max-high`, `--gate-require-scan`, `--unset`) |
| `runr members ls\|set-role\|add\|rm` | Members, their role and groups; add (`--role`, single-tenant: `--name`, `--username`, `--password-stdin`; alias `invite`) and remove members |
| `runr roles ls\|get\|create\|update\|rm\|default\|permissions` | Built-in and custom roles (`--permission`, `--add`, `--remove`, `--from-file`), the default role, the permission catalog |
| `runr groups ls\|get\|create\|update\|rm\|add-member\|rm-member` | Groups, their role (`--role`) and members |
| `runr access ls\|grant\|revoke` | Project access of a user or group (`--group`, `--role`) |
| `runr audit ls\|get\|export` | Audit log: filters, one event, CSV / JSON export |
| `runr audit settings [set]` | The audit log's retention and forwarding; change the retention (`--retention-days`) |
| `runr version` | CLI and API versions (`--client`, `--check-update`) |
| `runr completion bash\|zsh\|fish\|powershell` | Shell completion script |

Global flags: `-p/--project` (name or id; default `RUNR_PROJECT`, else `runr.yaml`),
`--dir` (project directory), `--context`, `-o/--output table|json|yaml`, `--debug`,
`-q/--quiet`.

## Git deploy: runr git

Every project has a git remote on the console domain, and a project can be connected to a
GitHub, GitLab or Forgejo/Gitea repository instead, whose pull and merge requests get preview
environments ([git deploy](git-deploy.md)). `runr git` works with both.

```sh
runr git                                    # remote, settings, repository, the last deploys
runr git remote --add                       # git remote add runr https://<console>/git/<org>/<project>.git
git push runr main                          # a new version, deployed
runr git deploys --kind preview             # deploy history: commit, branch, status, version
runr git redeploy --wait                    # the head of the deploy branch again
runr git set --branch release --root-dir services/api --auto-deploy=false

runr git connect --provider github --repo acme/orders --previews
echo "$GITLAB_TOKEN" | runr git connect --provider gitlab --repo acme/orders --token-stdin
runr git previews                           # open pull requests and their URLs
runr git previews redeploy 12 --wait
runr git previews rm 12 --yes
runr git disconnect --yes
```

### The RunR remote

`runr git remote` prints the remote URL of the project; `--add [NAME]` adds it to the git
repository of the project directory (`git remote add NAME URL`, `NAME` default `runr`), and
`--force` points an existing remote of that name to it (`git remote set-url`); `--print` shows
the git command instead of running it (`RUNR_GIT` names another git binary). git asks for
credentials on the first push: any user name, and as the password an
[API token](#permissions-and-token-scopes) with `git.push` (`git.pull` to clone and fetch only):

```sh
runr tokens create git --ability git.push --expires 90d
git config --global credential.https://console.example.com.helper store
```

A push to the deploy branch (default `main`) creates a version and, with auto-deploy on,
deploys it once it is built; pushes to other branches deploy nothing. A project connected to a
provider repository keeps its RunR remote read-only (clone and fetch work); `runr git remote`
says so.

### Status, history and settings

| Command | What it does |
|---|---|
| `runr git [status]` | Remote, deploy branch and its head, root directory, auto-deploy, force pushes, the connected repository, preview settings, what you may do, and the last deploys (`runr git status --deploys N`, default 5, `0` for none) |
| `runr git deploys` | The deploy history, newest first: pushes (production) and pull/merge request commits (preview) with commit, branch, status, version and author (`--kind production\|preview`, `--limit` up to 100); the reason of a failed or skipped deploy follows the table |
| `runr git redeploy` | Deploys the head of the deploy branch again; `--wait` waits until it ended: exit code 6 when it failed or a rule (the policy gate, the quota) skipped it, 7 after `--timeout` (default 10 minutes) |
| `runr git set` | Changes only the settings given: `--branch`, `--root-dir` (monorepos, `""` for the repository root), `--auto-deploy`, `--force-push`; previews: `--previews`, `--preview-limit`, `--preview-ttl` (hours without a commit), `--preview-env KEY=VALUE` (merged; `--unset-preview-env KEY`, `--clear-preview-env`), `--fork-previews`, `--fork-secrets` |

Boolean settings take `=false` to turn them off (`--auto-deploy=false`).

### Connecting a repository

`runr git connect --provider P --repo OWNER/NAME` connects the project. `--provider` is a
provider's id, its name, or `github`, `gitlab`, `forgejo` (also `gitea`) when the installation
has one provider of that type (`runr git providers` lists them for administrators).

- **GitHub** connects through the organization's installation of the provider's GitHub App:
  the only linked installation is used, `--installation ID` picks one of several
  (`runr git installations` lists them). Without one, the command opens GitHub's installation
  page, prints its URL and stops (exit code 1); run it again after installing.
- **GitLab and Forgejo/Gitea** connect with an access token, read from stdin with
  `--token-stdin` or from a hidden prompt — never from an argument; `--debug` traces redact
  it. `runr git connect --provider gitlab --oauth` opens the GitLab sign-in instead (when the
  provider has an OAuth application); the project's Git tab in the console then lists the
  repositories to connect.
- `runr git repos --provider P [--search S]` lists what the credentials can deploy from
  (same credential flags).
- `--branch`, `--root-dir` and `--previews` set the deploy branch, the root directory and
  previews right after connecting.

A project connects to one repository; a second `connect` stops with exit code 5 until
`runr git disconnect` (asks for confirmation, `--yes` in scripts) removed the connection, its
webhook and its previews. The versions stay.

### Previews

With previews on (`runr git set --previews`), every open pull or merge request gets its own
service `<project>-pr-<number>` with its own URL, rebuilt on every commit and deleted when the
request is closed or merged (or after `--preview-ttl` hours without a commit).

| Command | What it does |
|---|---|
| `runr git previews [ls]` | Previews with request number, branch, status, commit, author, last deploy and URL (`--all`: deleted ones too); also `runr previews` |
| `runr git previews get PREVIEW` | One preview: title, branches, commit, status and reason, service, URL, request link, expiry |
| `runr git previews redeploy PREVIEW` | Deploys a ready or failed preview again; `--wait` waits until it is ready: exit code 6 when it fails, 7 after `--timeout` (default 10 minutes) |
| `runr git previews rm PREVIEW` | Deletes the preview now (`--yes`); a new commit of the request creates it again |

`PREVIEW` is the request number (`12`, `#12`, `pr-12`), the preview's id or its branch.

### Git providers

Git providers are the GitHub Apps, GitLab instances and Forgejo / Gitea instances projects
connect through (Settings → Git providers):

```sh
runr git providers                                         # name, type, URL, connect modes, projects
runr git providers create --type github --name acme-github --app-id 123456 --app-slug acme-runr \
  --client-id Iv1.abc --client-secret-file client-secret.txt --private-key-file app.pem \
  --webhook-secret-file webhook-secret.txt
runr git providers create --type gitlab --name gitlab --client-id gl-app --client-secret-file -
runr git providers create --type forgejo --name codeberg --base-url https://codeberg.org
runr git providers get acme-github                         # settings and the URLs to enter at the provider
runr git providers update acme-github --private-key-file new-app.pem
runr git providers update codeberg --disable
runr git providers rm codeberg --yes
```

- **GitHub** needs all of `--app-id`, `--app-slug`, `--client-id`, `--client-secret-file`,
  `--private-key-file` (the App's PEM key) and `--webhook-secret-file` (16 characters or more).
  **GitLab** is gitlab.com or `--base-url`; `--client-id` with `--client-secret-file` enable the
  sign-in, else projects connect with an access token. **Forgejo / Gitea** needs `--base-url`.
  `--api-url` sets an API address that differs from the base URL (GitHub Enterprise, a GitLab
  behind a proxy).
- Secrets are read from files or stdin (`-`, one of them per call), never from arguments; RunR
  stores them encrypted, answers only whether one is stored, and `--debug` traces redact them.
  `update` sends only the flags given: a secret file replaces the stored secret, a secret left
  out stays; the type cannot change.
- `create` and `get` print the URLs to enter at the provider (the webhook and setup URLs of a
  GitHub App, the callback URL of a GitLab application).
- `rm` asks for confirmation (`--yes`) and is refused while projects are connected through the
  provider (exit code 5); `--disable` stops new connections instead (`--enable` allows them
  again).

### Permissions

| Command | Needs |
|---|---|
| `runr git [status]`, `git deploys`, `git installations`, `git previews [ls]\|get` | `git.pull` |
| `runr git redeploy` (and `git push` to the remote) | `git.push` |
| `runr git set`, `git connect\|disconnect\|repos` | `git.manage`; preview settings (`git set --previews`, `--preview-*`, `--fork-*`, `connect --previews`) also `previews.manage` |
| `runr git previews redeploy\|rm` | `previews.manage` |
| `runr git providers …` | `git_providers.manage` (single-tenant installations; on a multi-tenant installation platform administrators manage the providers) |

## Runtimes and runtime plugins

```sh
runr runtimes ls                          # every runtime version: kind, run mode, image
runr runtimes ls --all --region eu-1      # disabled ones too, only those offered in eu-1
runr runtimes get q                       # run mode, handler, triggers, versions, files
runr runtimes get q --manifest > runtime.yaml
runr runtimes validate -f runtime.yaml --files interpreter/
runr runtimes register -f runtime.yaml --files interpreter/ --wait
runr runtimes update q -f runtime.yaml --files interpreter/
runr runtimes update q --disable          # or --enable, --region eu-1, --all-regions
runr runtimes template q --type faas --out ./qfn
runr runtimes builds q                    # region image builds of dockerfile / git versions
runr runtimes build q --region eu-1 --wait
runr runtimes test-deploy q --region eu-1
runr runtimes rm q --yes
```

The catalog lists the built-in runtimes and the [runtime plugins](runtimes.md) registered from a
`runtime.yaml` manifest. `runr runtimes ls` shows each version with its kind (`built-in`,
`plugin`, `wasm`), run mode (`http`, `exec`, `persistent`), end-of-life date and image (versions
RunR builds per region show `(built in the region)`). `get` adds the manifest's details, `-o json`
the normalized manifest, `--manifest` prints the manifest as it was submitted.

`register -f runtime.yaml` (`-f -` reads stdin) registers a runtime: a platform runtime with
`runtimes.manage`, a custom runtime of your organization with `runtimes.use_custom`. `--files
DIR` uploads every file of the directory with it — the Dockerfile and build context of
`dockerfile` versions (flat names, at most 900 KB). `--region` (repeatable) offers the runtime in
those regions only. RunR then builds the image of `dockerfile` and `git` versions in each region;
`--wait` waits for those builds: exit code 6 when one fails, 7 after `--timeout` (default 15
minutes; `runr runtimes build --wait` alike). `update` replaces the
manifest and files (the id cannot change), sets the regions or enables / disables the runtime —
built-in runtimes accept `--region`, `--all-regions`, `--enable` and `--disable` only. `rm` is
refused (exit code 5) while projects use the runtime.

`validate -f` runs the platform's checks (JSON Schema and rules) without registering: an invalid
manifest prints every problem with its JSON path, e.g. `/run/command  is required in exec mode`,
and exits with code 5. `template` prints the starter files of a runtime (`--type xaas|faas`,
`--handler`) or writes them with `--out DIR`. `test-deploy` creates an XaaS project
`rt-<id>-<random>` from the starter template in a region and deploys it once its version is ready.

### Permissions

| Command | Needs |
|---|---|
| `runr runtimes [ls]\|get\|template` | a login |
| `runr runtimes register\|update\|rm\|validate\|builds\|build\|test-deploy` | `runtimes.manage` for platform runtimes (single-tenant installations; on a multi-tenant installation platform administrators), `runtimes.use_custom` for custom runtimes of your organization while the platform allows them |

## Images

`runr images` uploads container images and WebAssembly modules into a region's registry
through the RunR API, also for regions without access to a public registry
([Images](images.md)).

```sh
docker save myapp:1.0 -o myapp.tar
runr images push myapp.tar --region eu-1 --name myapp:1.0   # chunks, then the region pushes it
runr images push q.wasm --name q-interpreter:1.0              # a WebAssembly module
runr images ls --region eu-1                                  # name, kind, platforms, size, scan, digest; the quota
runr images get myapp:1.0 --region eu-1                       # references, digest, platforms, layers, scan
runr images rm myapp:1.0 --region eu-1 --yes
runr images uploads                                           # unfinished uploads; uploads rm ID --yes aborts one
```

`push` hashes the file (SHA-256, checked in the region), resumes an unfinished upload of the same
file, sends chunks of the upload's chunk size (8 MiB by default) and waits until the region
pushed the image; the result is printed with its digest reference (`-o json` for scripts).
`--format auto|docker|oci|wasm` overrides the detection from the file, `--region` defaults to the
only active region, and the name defaults to the file name with the tag `latest`. `ls` filters
with `--region` and `--kind image|wasm|spin`; `get` shows one image: its `image_ref` (the digest
in the region registry, what a CaaS project or a runtime version runs), `tag_ref`, media type,
platforms, layers, size, the uploaded file and the scan (`-o json` for scripts). `get` and `rm`
take a name (`repository:tag`, `:latest` when the tag is left out) or an id (`--region` when the
name is uploaded in several regions); `rm` asks for confirmation (`--yes`). `uploads [ls]` lists
the uploads that have not finished (bytes received, status) and `uploads rm ID… --yes` aborts them
and frees the region's receiver; `push` of the same file resumes one instead. Uploaded images run as
CaaS images, as runtime versions (`upload:`) and as wasm-exec modules.

### Permissions

| Command | Needs |
|---|---|
| `runr images ls\|get` | `images.view` |
| `runr images push`, `images uploads [ls]\|rm` | `images.upload` |
| `runr images rm` | `images.delete` |

## Durable workflows

`runr workflows` works with the [durable workflows](workflows.md) of a project: the
definitions the engine knows, their runs and the organization's events.

```sh
runr workflows ls                                    # definitions, triggers, run counts
runr workflows get process-order                     # one definition with its options
runr workflows run process-order --event @order.json --wait
runr workflows runs --status failed,timed_out --since 24h
runr workflows run get 01J…                          # event, output, error and every step
runr workflows run replay 01J… --from-step ship
runr workflows events send order.created --data '{"order_id":"o-1"}' --id order-o-1
```

### Definitions

`runr workflows ls` lists the project's workflows (archived ones last) with their state
(`active`, `paused`, `archived`), triggers, run counts per status, the last run and where
the definition came from (`sdk`, `manifest`, `api`). `runr workflows get NAME` shows one
definition: triggers, retries, timeout, concurrency limits, idempotency and `cancel_on`.
Workflows are addressed by name; an id works too.

```sh
runr workflows disable process-order    # pause: triggers and the API start no runs
runr workflows enable process-order     # resume (aliases: pause, resume)
runr workflows sync                     # read the definitions from the deployed function
runr workflows declare -f runr.workflows.json
```

- **Pausing** keeps runs in progress going and survives syncs.
- **`sync`** sends the function a signed introspect request; when the function cannot answer,
  `runr.workflows.json` of the latest version is used. RunR syncs by itself after a deployment
  of a project with the `workflow` trigger becomes active.
- **`declare -f FILE`** sets the definitions through the API: JSON or YAML, a list of
  definitions or `{"workflows": [...]}` — the format of `runr.workflows.json`. Workflows the
  file does not list are archived (their runs stay); `runr` asks before archiving, `--yes`
  confirms in scripts. A function that serves the SDK reports its definitions again at the next
  sync, so declare definitions for functions that do not serve the SDK's introspection.

### Runs

```sh
runr workflows run NAME [--event JSON|@file|-] [--idempotency-key KEY] [--test] [--wait] [--timeout 10m]
```

`--event` is the trigger event's data, a JSON object (inline, `@file.json`, or `-` for stdin).
A second start with the same `--idempotency-key` answers the first run instead of starting
another. `--wait` follows the run (`queued` → `running` → …) until it ends and prints it with
its output; a run that ends `failed`, `cancelled` or `timed_out` exits with code 6, a run still
active after `--timeout` with code 7.

| Command | What it does |
|---|---|
| `runr workflows runs [NAME]` | Newest runs of the project or of one workflow (`--status a,b`, `--source`, `--search`, `--since 24h`, `--from`, `--to`, `--limit` up to 100) |
| `runr workflows run get RUN_ID` | The run with its event, output, error and the step timeline (attempts, durations, errors) |
| `runr workflows run cancel RUN_ID` | Cancels an active run: open sleeps and waits, invoked child runs too (`--yes`) |
| `runr workflows run retry RUN_ID` | A failed, cancelled or timed-out run again in place: completed steps stay, failed and unfinished steps run again (`--wait`) |
| `runr workflows run replay RUN_ID` | A **new** run with the same event; `--from-step STEP` keeps the memoized steps before that step (`--wait`) |

`runr workflows runs get|cancel|retry|replay RUN_ID` are the same commands. Cancelling a
finished run, retrying an active one or replaying an active one is refused by the API (exit
code 5).

### Events

Events belong to the organization. Every workflow with a matching event trigger starts a run,
waiting `waitForEvent` steps resume, and `cancel_on` cancels matching runs.

```sh
runr workflows events send order.created --data @order.json --id order-o-1
runr workflows events send order.created --test          # a test event
runr workflows events ls --name order. --since 1h        # -p limits the list to a project
runr workflows events get 01J…                            # data and the runs it started
```

`--id` deduplicates: an id sent before answers the original event and starts nothing.
`--ts` sets the event time (Unix milliseconds). Names are 1–200 letters, digits and
`. _ : / -`; names under `runr/` are reserved for the platform.

### The workflow app: endpoint and signing key

```sh
runr workflows app                                  # endpoint, key fingerprint, last sync, queue bridge
runr workflows app set --endpoint https://workflows.example.com/api/runr
runr workflows app set --reset-endpoint             # back to the project's function
runr workflows app rotate-key                       # new key; the live version is deployed again
runr workflows app signing-key --env >> .env        # RUNR_WORKFLOW_SIGNING_KEY=… for a function outside RunR
```

The engine calls the project's function and signs every request with the project's signing
key. `runr workflows app [get]` shows the URL it calls, the key's fingerprint (never the key),
until when the previous key still signs after a rotation, the last definition sync with the SDK
it reported, and the queue bridge. `set --endpoint URL` makes the engine call a function served
outside RunR (http or https, no credentials in the URL, no loopback or private address unless the
installation allows them, no redirects); `--reset-endpoint` goes back to the project's function.

`rotate-key` creates a new key; the previous one keeps signing for the grace period (24 hours by
default). A workflow project on RunR gets the new key with a deployment, so runr deploys the live
version again (`--no-deploy` leaves that to you, `--timeout` bounds the wait); a function at an
endpoint override needs the new key from `signing-key`. `signing-key` prints the key on stdout
(`--env`: as `RUNR_WORKFLOW_SIGNING_KEY=…`, `-o json`: with the variable name and fingerprint);
it is secret, and `--debug` traces leave its body out.

### Usage

```sh
runr workflows usage                                # the organization, the last 30 days
runr workflows usage -p orders --from 2026-10-01 --to 2026-10-15
```

`runr workflows usage` shows the step executions (each attempt, sleep, wait, invoke and send)
and started runs between `--from` and `--to` (default: the last 30 days) per workflow, for the
organization or, with `-p`, one project; `-o json` adds the daily series.

### Permissions

| Command | Needs |
|---|---|
| `runr workflows ls\|get\|runs\|usage\|app [get]`, `run get`, `events ls\|get` | `workflows.view` |
| `runr workflows run`, `run cancel\|retry\|replay` | `workflows.run` |
| `runr workflows enable\|disable\|sync\|declare`, `app set\|rotate-key\|signing-key` | `workflows.manage` (`rotate-key`'s redeploy also `projects.deploy`) |
| `runr workflows events send` | `events.send` (organization-wide) |

## Bindings: data services for projects

A **binding** is a data service of the organization — a `kv` store on Redis, an `s3` (object
storage) bucket, a `postgres` database — or `external` credentials you bring. Attached to a
project, its connection details reach the functions as environment variables; attaching,
detaching and rotating roll the running service, nothing needs a redeploy. See
[Bindings](bindings.md) for the types, their variables and how they are isolated.

```sh
runr bindings types                                    # variables, plans, defaults and limits per type
runr bindings create cache --type kv --set memory_mb=128 --wait
runr bindings create orders-db --type postgres --set storage_gb=2 --set statement_timeout=15s
runr bindings create reports --type postgres --plan dedicated --set instances=2 --set backups=true
runr bindings create payments --type external --value PAYMENTS_URL=https://pay.example.com --from-file payments.env

runr bindings attach orders-db --env-prefix ORDERS_     # ORDERS_DATABASE_URL, ORDERS_PGHOST, …
runr bindings attach cache -p reports
runr bindings attachments                               # what the project of runr.yaml has attached
runr bindings detach cache -p reports

runr bindings ls                                        # every binding, its region, status and projects
runr bindings get orders-db --usage                     # health on the data service, daily usage
runr bindings update cache --set memory_mb=256
runr bindings rotate orders-db --yes --wait             # new credentials, attached projects roll onto them
runr bindings credentials orders-db                     # the values (secret; the reveal is audited)
runr bindings rm orders-db --yes                        # detached at once, data kept for the retention period
runr bindings ls --deleted
runr bindings restore orders-db
```

- **Types.** `kv` (also `redis`), `s3` (the API's `object`; also `object`), `postgres` (also
  `pg`) and `external`. `kv`, `s3` and `postgres` bindings live in a region (`--region`,
  default the only active region) and can be attached to projects of that region; external
  bindings have no region and can be attached to any project.
- **Settings** go with `--set KEY=VALUE` (repeatable; integers and `true`/`false` are sent
  typed) or `--config` with a JSON object (inline, `@file.json` or `-` for stdin; `--set` wins):
  `memory_mb` (kv), `quota_gb` (s3), `storage_gb`, `connection_limit`, `statement_timeout`
  (postgres) and `instances`, `backups` (postgres with `--plan dedicated`). The API applies
  its defaults to everything left out.
- **External bindings** take their variables from `--value KEY=VALUE` and `--from-file`
  (dotenv). `runr bindings update` with `--value`/`--from-file` replaces them; attached projects
  roll onto the new values.
- **Create and attach in one step.** `--attach` creates the binding in the region of the
  project (`-p`, `RUNR_PROJECT` or `runr.yaml`) and attaches it, optionally with `--env-prefix`.
- **Prefixes.** `--env-prefix ORDERS_` (upper case, ends with `_`) puts `ORDERS_` in front of
  every variable, so a project can use two bindings of the same type. `runr bindings attach` on
  a binding that is already attached changes its prefix; two attachments of a project cannot
  produce the same variable (exit code 5).
- **Provisioning** runs in the background; `--wait` (create, rotate) polls until the binding is
  `ready` and exits with code 6 when it ends in `error`, code 7 after `--timeout` (default 10
  minutes).
- **Deleting** asks for confirmation (`--yes` in scripts). The binding is detached from every
  project at once and its data kept for the retention period (`runr bindings types` prints it);
  `runr bindings ls --deleted` lists the deleted bindings with their purge date and
  `runr bindings restore` brings one back (attach it again afterwards). A deleted binding's name
  stays taken until it is purged.
- **Credentials** are never part of other answers. `runr bindings credentials` prints them
  (without an attachment's prefix; `--env-prefix` adds one) with a note on stderr that they are
  secret; the API records every reveal in the audit log. `--debug` traces never contain them.
- **Data residency.** In an organization with a residency policy, creating, restoring and
  attaching bindings outside the allowed regions is refused (exit code 5, `region_not_allowed`).
- **Limits.** `runr bindings quota` shows the organization's limits (bindings, KV memory, object
  storage, shared Postgres, dedicated Postgres clusters; 0 is unlimited) next to what its
  bindings claim. On an installation with several organizations, platform administrators read
  another organization's limits with `--tenant ID` (its overrides next to the installation's
  defaults) and change them with `runr bindings quota set --tenant ID` (`--max-bindings`,
  `--kv-mb`, `--object-gb`, `--postgres-gb`, `--dedicated-postgres`; `--unset NAME` goes back to
  the default).

Bindings and projects are named by name or id.

### Data services of a region

The bindings of a region run on its data services: `kv` (managed KV on Redis, or the region's
own Redis), `object` (Garage, or an external S3 or Garage endpoint) and `postgres`
(CloudNativePG with a shared cluster). Region administrators install them with
`runr regions data-services`:

```sh
runr regions data-services eu-1 --live                       # status, mode, bindings, health
runr regions data-services install eu-1 kv --maxmemory-mb 512 --wait
runr regions data-services install eu-1 postgres --instances 3 --storage 50Gi --backups --wait
runr regions data-services install eu-1 object --mode external --provider s3 \
  --endpoint https://s3.example.com --s3-region eu-west-1 --path-style --credentials s3.env
runr regions data-services uninstall eu-1 kv --yes
```

| Service | Settings |
|---|---|
| `kv` | `--mode managed\|region` (the region's Redis), `--maxmemory-mb` (64–65536), `--storage` |
| `object` | `--mode managed\|external`, `--storage`, `--bucket-prefix`; external: `--provider garage\|s3`, `--endpoint`, `--s3-region`, `--path-style`, `--vendor`, `--admin-endpoint` (Garage) |
| `postgres` | `--instances` (1–5), `--storage`, `--backups` (to the region's object storage) |

`install` also reconfigures an installed service. Installs and uninstalls run in the
background: `--wait` follows them and exits with code 6 when one ends in `error`, 7 after
`--timeout` (default 15 minutes). A flag of another service is refused (exit code 2), and so is a
size that is not `512Mi`, `10Gi` or `1Ti` style. The credentials of an external object store —
`ADMIN_TOKEN` (Garage) or `ACCESS_KEY_ID` and `SECRET_ACCESS_KEY` (S3) — come from
`--credentials FILE` or `--credentials -` (stdin) as `KEY=VALUE` lines, never from arguments;
the API stores them encrypted, never returns them, and `--debug` traces redact them. An
uninstall is refused while bindings use the service (exit code 5); a region without a cluster
connection runs no data services.

### Permissions

| Command | Needs |
|---|---|
| `runr bindings ls\|types\|quota\|get` | `bindings.view` |
| `runr bindings quota --tenant`, `quota set` | a platform administrator (installations with several organizations) |
| `runr bindings create\|update\|rotate\|rm\|restore` | `bindings.manage` |
| `runr bindings attach\|detach` | `env.edit` on the project and `bindings.manage` |
| `runr bindings attachments` | `projects.view` (the attachable bindings also need `bindings.view`) |
| `runr bindings credentials` | `bindings.view_credentials` |
| `runr regions data-services [ls]` | `regions.view` or `bindings.view` |
| `runr regions data-services install\|uninstall` | `regions.data_services.manage` |

## Models and model services

`runr models` works with the organization's model library, model services and the models a
project uses ([Hugging Face models](huggingface.md), [AI and GPUs](ai.md)).

```sh
runr models search minilm --task sentence-similarity        # the Hugging Face Hub
runr models info meta-llama/Llama-3.1-8B-Instruct --region eu-1
runr models deploy sentence-transformers/all-MiniLM-L6-v2   # library + service + deploy + wait
runr models services                                         # status and internal URLs
runr models invoke all-minilm-l6-v2 --data '{"model": "all-minilm-l6-v2", "input": ["hello"]}'
runr models bind all-minilm-l6-v2 --alias EMBED -p orders-api
runr deploy                                                   # the project gets RUNR_MODEL_EMBED_*
```

### Library

| Command | What it does |
|---|---|
| `runr models search [QUERY]` | Searches the Hub through the API (`--task`, `--library`, `--sort downloads\|likes\|trending\|updated`, `--limit`, `--cursor` for the next page). |
| `runr models info HF_ID` | The Hub model with its files, license, gated flag, the engines that serve it and RunR's suggested settings (`--revision`, `--region` sizes the suggestion for that region's GPUs). |
| `runr models add HF_ID` | Adds a Hub model, pinned to the commit `--revision` (default `main`) points to; `--name` overrides the name. |
| `runr models add --source upload --name N --task T` | An empty model to upload files into (air-gapped regions). |
| `runr models add --source pvc --path P --region R --name N --task T` | Imports a folder of the region's model volume (`imports/<tenant>/P`); `--source oci --reference REF` imports an OCI artifact. Imports need `models.manage`. |
| `runr models ls` / `get MODEL` | The library with sources, sizes, offline copies per region and services; `get` shows one model (name, id or Hub id). |
| `runr models rm MODEL --yes` | Removes a model and its offline copies; refused (exit code 5) while model services use it. |
| `runr models engines` | The serving engines (`vllm`, `tgi`, `tei`, `transformers`, `diffusers`, `custom`), their devices, API and tasks. |
| `runr models hub-token [status]\|set\|rm` | The organization's Hugging Face token for gated and private models. `set` reads it from a prompt or `--token-stdin` (never from an argument) and `--validate` checks it with the Hub; only its hint (`hf_…1234`) is ever shown, and `--debug` traces redact it. |

### Offline copies and uploads

`runr models snapshot MODEL --region R` mirrors a Hub model's pinned commit into the region's
model volume (offline mode, closed circuit) and waits until the copy is ready (`--no-wait`
returns at once, `--timeout`, default 1 h); a failed mirror runs again. `runr models snapshots
MODEL` lists the copies per region.

```sh
runr models snapshots logs minilm eu-1        # logs of the mirror or import Job of a copy
runr models snapshots rm minilm eu-1 --yes    # remove the copy from the region's model volume
```

`runr models snapshots rm|logs MODEL SNAPSHOT` take the copy's id or its region. A copy that
running offline model services use is not removed (exit code 5).

For regions without internet access, `runr models upload DIR` uploads a model folder through
the RunR API:

```sh
runr models upload ./all-MiniLM-L6-v2 --model minilm --task feature-extraction --region eu-1
runr models deploy minilm --mode offline --region eu-1
```

1. Every file of `DIR` is listed with its relative path (subfolders such as `1_Pooling/`
   included; `.git` and `.cache` are skipped, empty files are reported and left out), its size
   and its sha256 (`--no-checksum` skips the checksums).
2. `--model` names a model of source `upload`; an unknown name is created with `--task` (and
   `--library`).
3. The upload starts a receiver in the region; the CLI waits until it accepts chunks
   (`--timeout`, default 10 minutes).
4. Each file is sent in chunks of the upload's `chunk_size` (8 MiB by default) as raw bytes,
   `PUT …/uploads/{upload}/chunk?path=&offset=`; a chunk that fails on the network or with
   502/503/504 is sent again (chunks may be repeated). Progress goes to stderr per file and every
   10 %.
5. Complete has the region verify every size and checksum and turns the files into the model's
   offline copy in that region.

An upload that fails stays open for 24 hours: the CLI prints its id, and `--resume ID` continues
it, sending only what did not arrive. Ctrl+C aborts the upload and removes its receiver.

### Model services

`runr models deploy MODEL` creates a model service and deploys it. `MODEL` is a model of the
library (name or id) or a Hub id, which is added to the library on first use. RunR picks the
engine, device, size and scaling unless flags set them:

| Flag | Description |
|---|---|
| `--name` | service name (default: from the model's name) |
| `--region` | region (name or id; default: the only active region) |
| `--revision` | Hub branch, tag or commit of a Hub model |
| `--mode online\|offline` | from the Hub (cached in the region) or the region's offline copy (no egress) |
| `--device cpu\|gpu`, `--engine` | device and engine (`auto` = RunR's pick) |
| `--gpu N`, `--gpu-type`, `--gpu-memory MiB` | GPUs (imply `--device gpu`) |
| `--cpu`, `--memory-mb`, `--request-timeout` | resources and request timeout (seconds) |
| `--min-scale`, `--max-scale`, `--scale-to-zero` | autoscaling (`--min-scale 1` keeps one instance warm) |
| `--image`, `--arg` (repeatable), `--port`, `--health-path` | engine image, extra arguments, a custom image's port and readiness path |
| `-e KEY=VALUE` (repeatable) | extra environment variables (stored encrypted; they replace the service's set) |
| `--served-name` | the OpenAI `model` name |
| `--no-start` | create the service stopped |

The command waits until the service is `ready` (`waiting_snapshot` and `deploying` before):
exit code 6 when it fails, 7 after `--timeout` (default 30 minutes; large models take a while
the first time). `--no-wait` (or `--wait=false`) returns once it is created.

| Command | What it does |
|---|---|
| `runr models services [ls]` | Services with model, region, engine, device, mode, status and URL (`--region`). |
| `runr models services get SERVICE` | One service: status and message, engine, GPU, resources, scaling, URL, OpenAI base, the invoke path. |
| `runr models services update SERVICE` | Changes settings with the flags of `deploy`; a running service is redeployed with them and the command waits again. |
| `runr models services start\|stop SERVICE` | Deploys a stopped or failed service / stops it (the settings stay). |
| `runr models services logs SERVICE` | The last lines of the engine container of up to five pods (`--tail`, default 200). |
| `runr models services bindings SERVICE` | The projects that use the service. |
| `runr models services rm SERVICE --yes` | Tears the service down; refused (exit code 5) while projects bind it. |
| `runr models services security SERVICE` | The supply-chain report of the service's image (the engine's or its own): SBOM, signature and vulnerabilities with the findings (`--all-findings`), scanned when the region scans external images; `--sbom FILE` (or `-`) downloads the SBOM (`--sbom-format spdx-json\|cyclonedx-json`), `--fail-on critical\|high\|medium\|low` exits 6 on findings of that severity or higher. |
| `runr models invoke SERVICE` | One request through the API (it wakes a service scaled to zero): `--path` (default: the service's playground path, e.g. `/v1/embeddings`), `--method GET\|POST`, `--data` JSON inline, `@file.json` or `-`. The answer goes to stdout, `status content-type (ms)` to stderr; `-o json` prints `{status, duration_ms, content_type, body}`. Exit code 1 when the model answers 4xx/5xx, 8 when the service has no ready revision. |

### Models of a project

`runr models bind SERVICE` binds a model service of the project's region to the project
(`-p`, `RUNR_PROJECT` or `runr.yaml`) under an alias (`--alias`, default from the service
name). On the project's next deploy its containers get `RUNR_MODEL_<ALIAS>_URL`, `_MODEL`,
`_API`, `_TASK` and `_OPENAI_BASE`; `--mount-weights` also mounts the model's offline copy
read-only (`RUNR_MODEL_<ALIAS>_PATH`, needs a ready copy in the region). `runr models bindings`
lists the project's bindings with their variables and the services of its region it can bind;
`runr models unbind SERVICE|ALIAS` removes one. Bindings apply with the next `runr deploy`.

### GPU quota

```sh
runr models gpu-quota --region eu-1           # quota, GPUs in use, remaining
runr models gpu-quota set --tenant 01J… --region eu-1 --gpus 4
```

- `runr models gpu-quota` shows how many GPUs the organization's GPU projects and model services
  may reserve on a region (count × max(1, min scale)), how many they hold and how many remain;
  `unlimited` when no quota applies.
- `runr models gpu-quota set` changes an organization's quota on a region: `--gpus N` (0 allows no
  GPUs) or `--unlimited`. It is for platform administrators of a multi-tenant installation;
  `--tenant` is the organization's id.

### GPU support and model storage of a region

```sh
runr regions gpu eu-1 --refresh                       # mode, status, GPUs, nodes, settings
runr regions gpu install eu-1 --mode device-plugin --time-slicing 4 --wait
runr regions gpu install eu-1 --mode operator --gpu-node gpu-1 --wait --yes
runr regions gpu set eu-1 --gvisor-nvproxy --runtime-class nvidia
runr regions gpu uninstall eu-1 --yes
runr regions model-storage eu-1                       # the claim model weights live on
runr regions model-storage set eu-1 --storage-class longhorn --size 500Gi --access-mode ReadWriteMany
```

`runr regions gpu [status] [REGION]` shows a region's GPU support (`none`, `device-plugin`,
`operator` or `external`, with its status), its GPUs (total, allocatable, allocated, available),
the GPU nodes (product, driver, CUDA, whether the device plugin runs there) and the settings;
`--refresh` reads the nodes from the cluster now. `install REGION --mode device-plugin` installs
the NVIDIA device plugin on nodes that already have the driver and container toolkit; `--mode
operator` installs the NVIDIA GPU operator, which also installs the driver and the toolkit
(`--driver=false`, `--toolkit=false` keep the nodes' own) and restarts the container runtime of the
GPU nodes, so it asks first (`--yes`). Both take `--time-slicing N` (pods sharing one GPU, 1–16),
`--runtime-class auto|nvidia|none`, `--gpu-node NODE` (repeatable; default: detected
automatically) and `--restrict-to-gpu-nodes[=false]`. `set REGION` changes those settings
(`--no-time-slicing`, `--auto-gpu-nodes`) and `--gvisor-nvproxy[=false]` (GPUs for
gVisor-sandboxed projects); a change the installed chart needs is applied in the background.
`uninstall REGION` removes what RunR installed and asks first. Installs, removals and applied
settings take `--wait` (exit code 6 when they fail) and `--timeout` (default 30 minutes).

`runr regions model-storage [get] [REGION]` shows the volume claim model services keep their
weights on (status, storage class, size, access mode); `model-storage set REGION` changes
`--storage-class`, `--size` (`200`, `200Gi` or `2Ti`) and `--access-mode
ReadWriteMany|ReadWriteOnce`. Once the claim exists only a larger size applies (exit code 5
otherwise).

### Permissions

| Command | Needs |
|---|---|
| `runr models search\|info\|ls\|get\|engines\|snapshots`, `snapshots logs`, `services [ls]\|get\|logs\|bindings\|security`, `invoke`, `hub-token [status]`, `gpu-quota` | `models.view` |
| `runr models add` (Hub models and uploads), `deploy`, `upload`, `snapshot`, `services update\|start\|stop\|rm` | `models.deploy` |
| `runr models rm`, `add --source pvc\|oci` (imports), `snapshots rm`, `hub-token set\|rm` | `models.manage` |
| `runr models bind\|unbind` | `projects.update` on the project and `models.deploy` |
| `runr models bindings` | `projects.view` on the project and `models.view` |
| `runr models gpu-quota set` | a platform administrator |
| `runr regions gpu [status]`, `regions model-storage [get]` | `regions.view` |
| `runr regions gpu install\|uninstall\|set`, `regions model-storage set` | `regions.gpu.manage` on a region your organization may change |

Region lookups (`--region`, the default region) need `regions.view`.

## Sandboxes

`runr sandbox` drives the [Sandbox API](sandbox-api.md): short-lived isolated pods (a Kata
Containers micro-VM or gVisor) of your organization for untrusted code — what an AI agent
writes, a build step, a notebook cell. Create one, run commands and code in it with streamed
output, copy files in and out, remove it again.

```sh
SBX=$(runr sandbox create --runtime python --ttl 30m \
  --allow pypi.org:443 --allow files.pythonhosted.org:443)
runr sandbox cp ./data $SBX:/workspace/data
runr sandbox exec $SBX 'pip install --user pandas && python3 -u train.py'
runr sandbox cp $SBX:/output ./results
runr sandbox rm $SBX --yes

runr sandbox run analysis.py --outputs ./charts     # a throw-away sandbox for one run
```

- **`create`** waits until the sandbox runs and prints its id on stdout (progress on stderr),
  so `SBX=$(runr sandbox create)` works in scripts; `-o json` prints the whole sandbox.
  Options: `--runtime` / `--version` (a runtime of the catalog, default `python`) or
  `--image` (any image with `/bin/sh`, and `tar` for `cp`), `--cpu`, `--memory`, `--disk`,
  `--ttl` (how long it may live), `--idle-timeout` (`0` = never), `--allow HOST[:PORT]`
  (repeatable egress allowlist: addresses, CIDRs or host names; without it the sandbox has no
  network), `-e KEY=VALUE` (environment of every command, stored encrypted — `get` shows the
  names only), `--label KEY=VALUE`, `--region`, `--class auto|kata|gvisor|<class>`,
  `--writable-root`, `--pids-limit`, `--start-timeout` (default 2 minutes), `--no-wait`.
  A sandbox that cannot start ends `create` with exit code 6, one still starting after
  `--start-timeout` with exit code 7.
- **`exec ID [--] CMD [ARG…]`** runs a command and streams its stdout and stderr to runr's
  stdout and stderr as they are written (Server-Sent Events). runr exits with the command's
  exit code — 137 when `--timeout` (default 60 s, up to 1 hour) killed it, with a warning.
  One argument runs through `/bin/sh -c` (pipes and `&&` work); several are the argv. Flags
  go before the sandbox id: `--cwd`, `-e KEY=VALUE`, `--timeout`, `-i` (send runr's stdin to
  the command). Ctrl+C stops the command in the sandbox (exit code 130). Two transports carry
  the command: Server-Sent Events send stdin up front (read to its end) and stream the output;
  the [exec WebSocket](sandbox-api.md#exec) sends runr's stdin while the command runs (an
  interactive program reads each line as it is typed) and closes it at its end. `-i` on a
  terminal uses the WebSocket, everything else Server-Sent Events; `--ws` picks the WebSocket
  (also for piped stdin, which then streams instead of being sent up front) and `--ws=false`
  Server-Sent Events. Output, exit codes, `--timeout`, Ctrl+C and `-o json` work the same over
  both.
- **`exec -t`** runs the command on a terminal (the exec WebSocket with a TTY), so shells,
  REPLs, editors and pagers work as in a terminal window: `runr sandbox exec -it $SBX -- bash`.
  The terminal merges the command's stderr into its output. With `-it` on a terminal, runr's
  terminal is raw while the command runs — every key goes to the command as typed, Ctrl+C and
  Ctrl+D included — the size follows the window (`SIGWINCH` on Linux and macOS, the console
  size on Windows), and the terminal is restored when the command ends, also after an error,
  `SIGTERM` or `SIGHUP`. runr exits with the command's exit code. `TERM` is `xterm-256color`
  unless `-e TERM=…` sets it. `-t` without `-i` gives the command a terminal without input
  (its input ends at once); piped stdin with `-it` is sent as typed and ends with Ctrl+D. `-t`
  does not combine with `--ws=false` or `-o json|yaml`.
- **`run [FILE|-]`** runs code with the code interpreter: the language comes from `--lang` or
  the file extension (`.py`, `.js`, `.ts`, `.sh`, `.php`, `.rb`, `.go`), else the sandbox's
  runtime; `--code '…'` instead of a file. Without `--sandbox ID` a throw-away sandbox of the
  language's runtime is created (the `create` options apply) and removed afterwards, also
  after Ctrl+C; `--keep` leaves it running. After the run, the files it changed under
  `/workspace` and `/output` are listed, and `--outputs DIR` saves the images it wrote to
  `/output` (`RUNR_OUTPUT_DIR`, e.g. matplotlib charts). runr exits with the program's exit
  code.
- **`cp SRC DST`**: one side is `ID:/path` (a relative path is relative to `/workspace`;
  `ID:` alone is `/workspace/`). A directory's contents are copied into the destination
  directory, which is created when needed (transferred as one tar); a file is written to the
  destination path, or into it when that is an existing directory or ends with `/`. `-` is
  stdin or stdout (a file's bytes, or a tar of a directory). Writable places in a sandbox are
  `/workspace`, `/output`, `/tmp` and `/home/sandbox`; each transfer is limited to 50 MiB.
  Links and special files are not copied (a warning names them).
- **`ls`** lists the active sandboxes (`--status active|running|paused|pending|ended|all`,
  default `active`; `--region`, `--runtime`, `--limit`), **`get ID`** shows one (class, region, resources,
  network, environment names, labels, expiry), **`files ID [PATH]`** lists a directory,
  **`logs ID`** shows the activity log, **`rm ID… --yes`** (or `--all`) removes sandboxes.
- **`files mkdir ID PATH…`** creates directories with their parents; **`files rm ID PATH…`**
  removes files, links and empty directories, `-r` directories with their contents, and `-f`
  passes over a path that does not exist (otherwise exit code 4). A relative path is relative
  to `/workspace`; `/` cannot be removed. `-o json` prints the paths (`path`, `deleted`).
- **`logs ID --pod`** adds the Kubernetes pod of a running sandbox to the activity log: its
  node, phase and conditions, the cluster's events about it and the last lines of its log —
  where to look when a sandbox does not start. `-o json` prints `{"events", "pod"}`; an ended
  sandbox has no pod.

With `-o json|yaml`, `exec` and `run` collect the output and print one result
(`exit_code`, `stdout`, `stderr` — `stdout_base64` / `stderr_base64` for bytes that are not
UTF-8 —, `timed_out`, `duration_ms`, `truncated`; `run` adds `files_changed` and `outputs`
and wraps it as `{"sandbox_id", "kept", "result"}`); the exit code is passed on as well.

### Background processes

```sh
P=$(runr sandbox start $SBX -- python3 -m http.server 8000)   # prints the process id
runr sandbox start --cwd /workspace/app -e NODE_ENV=production $SBX 'npm run build'
runr sandbox ps $SBX                                          # id, pid, status, exit code, command
runr sandbox logs $SBX $P                                     # the last 64 KiB of stdout and stderr
runr sandbox logs $SBX $P -f                                  # stream until the process ends
runr sandbox kill $SBX $P --signal INT
```

- **`start [flags] ID [--] CMD [ARG…]`** starts a command in the background of a running
  sandbox and prints its id on stdout (`-o json`: the process). Like `exec`, one argument runs
  through `/bin/sh -c` and several are the argv; flags (`--cwd`, `-e KEY=VALUE`) go before the
  sandbox id. `-f` streams the output right away and exits with the process's exit code.
- **`ps ID`** lists the sandbox's processes, newest first, with their state read from the
  sandbox (`running`, `exited`, `killed`).
- **`logs ID PROCESS`** prints a process's output: stdout to stdout, stderr to stderr, the last
  `--tail` bytes (default 65536, up to 4 MiB). With `-f` the output is streamed as it is written
  (Server-Sent Events) until the process ends, and runr exits with its exit code — 137 when it
  was killed; Ctrl+C stops following and leaves the process running. `-o json` prints the output
  as one result. `logs ID` alone is still the sandbox's activity log.
- **`kill ID PROCESS`** sends `--signal` (default `TERM`; also `KILL`, `INT`, `HUP`, `QUIT`,
  `USR1`, `USR2`, `STOP`, `CONT`, with or without `SIG`) and prints the process's state.

### Terminal

```sh
runr sandbox terminal $SBX                          # an interactive shell; leave with exit or Ctrl+D
printf 'ls -la\nexit\n' | runr sandbox terminal $SBX
runr sandbox terminal $SBX -o json                   # the one-time session instead of attaching
```

`runr sandbox terminal ID` (aliases `shell`, `attach`) opens `/bin/sh` in a running sandbox through RunR's
web shell, the terminal of the console ([web shell](web-shell.md)): `POST
/sandboxes/{id}/terminal` returns a one-time session (valid for 120 seconds), and runr connects
to its WebSocket `/ws/shell?token=…` on the console host (`wss://` for an `https` API URL). On a
terminal, the keyboard is in raw mode: every key goes to the shell as typed (Ctrl+C included),
the size follows the window, and the shell's output is written as it arrives. Piped stdin is
sent as typed input and ends with Ctrl+D, so the shell exits after the last line. The session
ends when the shell exits or after five idle minutes; runr then ends with exit code 0. A refused
session ends with exit code 1 and the server's message. With `-o json|yaml` runr prints the
session (`session_id`, `websocket_url`) for another client to connect to and does not attach.
The session token never appears in `--debug` traces. Any other command runs on a terminal with
`runr sandbox exec -it ID -- CMD`, which also passes on the command's exit code.

### Pause, resume and lifetime

```sh
runr sandbox pause $SBX            # freeze every process; files and memory stay
runr sandbox resume $SBX
runr sandbox extend $SBX --ttl 2h  # end two hours from now
runr sandbox keepalive $SBX        # reset the idle timer
```

A paused sandbox runs no commands (exec, files and the terminal answer exit code 5 until it is
resumed); its TTL keeps counting. `extend` (alias `timeout`) sets the sandbox to end `--ttl`
from now, within the region's maximum TTL counted from the sandbox's start; `keepalive` resets
the idle timer without running a command.

### Network

```sh
runr sandbox network $SBX                                   # mode and egress allowlist
runr sandbox network $SBX --allow pypi.org:443 --allow files.pythonhosted.org:443
runr sandbox network $SBX --none                            # no network
```

`runr sandbox network ID` shows the network of a sandbox; `--allow HOST[:PORT]` (repeatable)
replaces its egress allowlist with addresses, CIDRs or host names, each with an optional port
(host names are resolved again), and `--none` takes the network away. The change applies to the
running sandbox at once; `-o json` prints the network (`mode`, `egress_allow`).

### Region settings

```sh
runr sandbox regions                                # enabled, classes, default class, TTLs and limits per region
runr sandbox regions get eu-1
runr sandbox regions set eu-1 --allowed-class kata --allowed-class gvisor --default-class gvisor
runr sandbox regions set eu-1 --default-ttl 30m --max-ttl 4h --max-cpu 2 --max-memory 4Gi --max-disk 10Gi
runr sandbox regions set eu-1 --disable
runr sandbox regions set eu-1 --unset max-ttl --all-classes
```

Every region that offers sandboxes has its own settings. `regions` (or `ls`) lists them with
the values that apply to your organization: the classes it gets (the region's allowed classes of
those its nodes have installed, narrowed by the organization's quota), the default class, the
default and maximum TTL and the maximum CPU, memory and disk of one sandbox (the region's own
limit or the platform's, whichever is lower). `get REGION` shows one region, also the installed
and allowed classes. `set REGION` (a region name or id) changes only the settings it is given:
`--enable` / `--disable`, `--allowed-class` (repeatable; `kata` allows every Kata hypervisor) or
`--all-classes`, `--default-class`, `--allow-runc` (`--allow-runc=false` forbids runc again;
runc can be the default only while it is allowed), `--default-ttl`, `--max-ttl`, `--max-cpu`
(cores or millicores), `--max-memory` and `--max-disk` (MiB or `4Gi`); `--unset
classes|default-class|default-ttl|max-ttl|max-cpu|max-memory|max-disk` puts one back to the
platform's. `get` shows whether you can change a region ("you can change it"). A change you may
not make ends with exit code 3: a token without `sandboxes.manage` is told which permission it
lacks, and a region of the platform (on an installation with several organizations, only
platform administrators change those) is named as such.

### Sandbox runtimes of a region

```sh
runr regions sandbox eu-1                           # Kata Containers and gVisor: status, hypervisors; the mapping
runr regions sandbox shims eu-1                     # Kata hypervisors the nodes can run; /dev/kvm per node
runr regions sandbox install eu-1 gvisor --wait
runr regions sandbox install eu-1 kata --hypervisor clh --hypervisor fc --devmapper-pool-gb 50 --wait --yes
runr regions sandbox map eu-1 --faas gvisor --xaas gvisor --build kata-clh
runr regions sandbox uninstall eu-1 gvisor --yes --wait
```

The classes a region offers come from the sandbox runtimes installed on its nodes.
`runr regions sandbox [status] REGION` shows Kata Containers (with its installed hypervisors)
and gVisor (`not_installed`, `installing`, `ready`, `uninstalling` or `error`, with the last
line of the install log) and the runtime class each project type runs under. `shims REGION`
lists the Kata hypervisors (`clh`, `qemu`, `dragonball`, `fc`) with whether the nodes can run
them and why not, and the hardware virtualization (`/dev/kvm`) of every node Kata would run on —
`yes`, `no` or `not checked`; RunR checks new nodes in the background and `--recheck` checks
every node again.

`install REGION kata|gvisor` installs a runtime in the background; `--wait` waits until it ends
(exit code 6 when it fails, 7 at `--timeout`, default 30 minutes). Kata takes `--hypervisor`
(repeatable, default `clh`; installing again with others adds them) and, for `fc`,
`--devmapper-pool-gb` (the sparse thin-pool, default 20). Kata needs `/dev/kvm` on the nodes —
without it the install is refused with exit code 5 — and restarts the container runtime on every
target node, so runr asks first (`--yes` outside a terminal). `uninstall REGION RUNTIME` removes
a runtime (it asks too). `map REGION` sets the class the region's `--faas`, `--xaas` and `--caas`
projects and `--build` image builds run under: `runc`, `gvisor` or `kata-<hypervisor>`, each
installed and ready; types left out keep theirs, and running projects get the new class with
their next deployment.

### Pools and quotas

```sh
runr sandbox pools create --region eu-1 --runtime python --size 3 --memory 1Gi
runr sandbox pools                                  # size, warm, starting, hits, misses, errors
runr sandbox pools update 01J… --size 5
runr sandbox pools update 01J… --disable            # warm sandboxes are removed, the pool stays
runr sandbox pools rm 01J… --yes

runr sandbox quotas                                 # your organization's limits and usage
runr sandbox quotas ls                              # the defaults row and every organization
runr sandbox quotas set --max-concurrent 20 --max-cpu 16 --max-memory 32Gi --max-time 200h
runr sandbox quotas set 01J… --allowed-class kata --allowed-class gvisor --max-pool-size 4
runr sandbox quotas set 01J… --unset concurrent     # back to the default
runr sandbox quotas reset 01J… --yes

runr sandbox usage --from 2026-10-01                # sandboxes, time, CPU and memory hours, cost per day
runr sandbox metrics --window 7d                    # active, warm, pool hit rate, start times
runr sandbox metrics --prometheus                   # the Prometheus text format of the scrape endpoint
```

- **Warm pools** keep sandboxes of one runtime, version, size and class running in a region; a
  `create` that matches gets one of them (`from_pool` in the answer) and the pool refills in
  the background. `pools create` takes `--region` (name or id), `--runtime`,
  `--version`, `--size` (0–50), `--cpu`, `--memory`, `--disk` and `--class`; the warm sandboxes
  of all pools count against the organization's pool size quota.
- **Quotas** limit concurrent sandboxes, their CPU and memory together, sandbox time per month,
  warm pool sandboxes and the allowed sandbox classes; 0 is unlimited. `quotas` (or `get`) shows
  your organization's limits next to what it uses. `ls`, `set` and `reset` are for platform
  administrators (on a single-tenant installation: every user with `sandboxes.manage`); the
  organization argument is a tenant id, `default` (also when left out) is the defaults row that
  applies to organizations without limits of their own. `set` changes only the limits it is
  given: `--max-concurrent`, `--max-cpu` (cores or millicores), `--max-memory` (MiB or `32Gi`),
  `--max-time` (a duration per month), `--max-pool-size`, `--allowed-class` (repeatable) or
  `--all-classes`; `--unset concurrent|cpu|memory|time|pool-size|classes` puts one back to the
  default. `reset` removes an organization's own limits.
- **`usage`** lists the organization's daily sandbox usage (`--from`, `--to`, default this month)
  with its cost; **`metrics`** shows active and warm sandboxes, the warm pool hit rate and the
  average start time from a pool and cold (`--window 1h|24h|7d|30d`, `--region`,
  `--all-organizations` for platform administrators). `metrics --prometheus` prints the
  installation's sandbox metrics as the Prometheus scrape endpoint (`GET
  /api/sandbox-metrics/prometheus`) serves them, for platform administrators (on a
  single-tenant installation every user with `sandboxes.manage`); it takes no other flag.

### Permissions

| Command | Needs |
|---|---|
| `runr sandbox ls\|get`, `logs ID [--pod]`, `network ID` (show), `regions [ls]\|get`, `pools [ls]`, `quotas [get]`, `usage`, `metrics` | `sandboxes.view` |
| `runr sandbox create\|rm\|extend`, `network ID --allow\|--none` | `sandboxes.create` |
| `runr sandbox exec\|run\|cp\|files [mkdir\|rm]\|start\|ps\|kill\|pause\|resume\|keepalive\|terminal`, `logs ID PROCESS` | `sandboxes.exec` (`run` without `--sandbox` also `sandboxes.create`) |
| `runr sandbox regions set`, `pools create\|update\|rm`, `quotas ls\|set\|reset`, `metrics --prometheus` | `sandboxes.manage` (`regions set` also needs to own the region; with several organizations, the quotas and `--prometheus` need a platform administrator) |
| `runr regions sandbox [status]\|shims` | `regions.view` |
| `runr regions sandbox install\|uninstall\|map` | `sandbox.manage` and a region your organization may change |

A token for an agent that runs code and nothing else:

```sh
runr tokens create agent --ability sandboxes.view --ability sandboxes.create --ability sandboxes.exec
```

## Observability: traces, metrics, uptime and alerts

```sh
runr traces enable                       # tracing on; redeploys the live version
runr traces --status error --since 6h    # failed requests of the last six hours
runr traces get 4bf92f3577b34da6a3ce929d0e0e4736 --logs
runr uptime create health --path /health --interval 30s
runr alerts channels create oncall --type slack --webhook-url @slack-url.txt
runr alerts rules create api-errors --type error_rate -p orders --param threshold=5 --channel oncall
```

The commands work on the project of `-p`, `RUNR_PROJECT` or `runr.yaml`, like the other
project commands; alert channels, alert rules and the status page belong to the organization.
See [observability.md](observability.md) for how tracing, replay, uptime checks and alerting
work on the platform.

### Traces

`runr traces` (also `runr traces ls`) searches the project's traces in the tracing backend of
its region (OpenTelemetry Collector and Tempo), newest first:

| Flag | Default | Description |
|---|---|---|
| `--since`, `--until` | `1h`, now | a duration back from now (`15m`, `6h`, `7d`) or an RFC 3339 time; at most 7 days |
| `--status` | all | `error` (5xx and timeouts) or `ok` |
| `--min-duration` | | only traces whose server span took at least this long (`250ms`) |
| `--path` | | part of the request path |
| `--limit` | `50` | 1–200 |

`runr traces get TRACE_ID` prints the trace as a waterfall: each span indented under its
parent with its service, kind, start offset, duration and status; `--logs` adds the log lines
of the project's pods that carry the trace id. `-o json` returns the normalized spans with
their attributes, events and resource.

Tracing is switched per project and applies with the next deployment: `runr traces enable
[--sample-ratio 0.2]` and `runr traces disable` save the switch and deploy the live version
again (`--no-deploy` only saves; the next deployment picks it up). `runr traces status` shows
the switches, the sample ratio and the state of the region's tracing backend. A region whose
backend RunR cannot query (mode `external` or `off`, or not ready yet) answers with exit
code 5 and the reason.

### Request replay

With request replay on, the project's failed requests (HTTP 5xx or timeouts) are captured with
their headers (secrets redacted before they leave the pod) and bodies:

```sh
runr traces replay enable --retention-days 3 --redact-header X-Tenant
runr traces replay ls --status 502
runr traces replay get 01J…                     # request and the answer it got
runr traces replay run 01J… -H "Authorization: Bearer test-token"
runr traces replay rm --all --yes
runr traces replay disable
```

`replay run` sends the captured request again to the project's current revision and prints a
diff of the answers (status, added, removed and changed headers, a line diff of the bodies).
Redacted headers are not sent; give the ones the request needs with `-H` (used for that replay
only, never stored), `--query` replaces the captured query string. Captures can hold personal
data: keep the retention short and delete captures once they are no longer needed. Replay
works for code runtimes with an http or queue trigger.

### Recorded metrics

```sh
runr metrics                                        # the last 7 days: CPU, memory, requests, cold starts, sessions
runr metrics --period 30d
runr metrics history --since 6h                     # the 5-minute snapshots
runr metrics history --from 2026-10-01 --to 2026-10-08 --granularity 1d
runr metrics live                                   # what the live deployment uses right now
runr metrics live --quick --watch 5s                # read again every 5 seconds until Ctrl+C
```

RunR records the CPU, memory, requests per second, concurrency, pods and cold starts of a
project's workload. `runr metrics` summarizes the last `--period` (`7d`, `30d` or `90d`):
average and peak CPU (cores), memory (MiB) and requests per second, the cold starts and the
deployment sessions (how many, how long, how much CPU). `runr metrics history` lists the
snapshots of the last `--since` (default 24 hours) or between `--from` and `--to` (RFC 3339 or
`YYYY-MM-DD`) at a `--granularity` of `1m`, `5m`, `1h` or `1d` — by default `5m` up to a day,
`1h` up to two weeks and `1d` beyond. `-o json` prints the API's answers.

`runr metrics live` reads the live deployment's usage from its region's Prometheus (`GET
/projects/{project}/deployments/{deployment}/metrics`): CPU (cores), memory (MiB) and requests
per second of the last minute, and the averages of the last hour; `--quick` reads the current
values only and answers faster. `--deployment ID` reads another deployment of the project,
`--watch DURATION` (at least `1s`) reads again until Ctrl+C. A dash is a value the region has
no reading for — no pod running (scaled to zero), or a region without Prometheus. `-o json`
prints the API's answer (`cpu`, `memory`, `rps`, `cpuAvg1h`, `memAvg1h`), one document per
reading.

### Uptime checks

```sh
runr uptime create health --path /health --interval 30s --expect 200
runr uptime create global --url https://orders.example.com/health --region eu-1 --region us-1
runr uptime ls                                   # status, uptime of 24 h / 7 d / 30 d, average response
runr uptime results health --hours 72
runr uptime run health                           # probe now from the RunR API
runr uptime update health --enabled=false        # pause
runr uptime incidents --open
runr uptime incidents update 01J… --title "Checkout degraded" --message @update.md
runr uptime rm health --yes
```

A check probes a path of the project's endpoint (`--path`, default `/`) or an absolute `--url`,
with `--method` (GET, HEAD, POST), `--header "Name: value"` (stored write-only), `--interval`
(30 s – 1 h, default 1 min), `--timeout` (up to 60 s), `--expect` (`200`, `200,204`,
`200-399`, `2xx`) and `--body-regex`. It is probed by the RunR API (`--locations
control_plane`, the default) or by the agents of the regions given with `--region`. After
`--failure-threshold` failed probes in a row (default 3) the check is down, which opens an
incident and fires the `uptime_down` alert rules; `--recovery-threshold` successes (default 2)
resolve it. `--status-page`, `--status-page-name` and `--status-page-order` put the check on
the status page. `runr uptime update` changes only the flags given. `runr uptime run` ends
with exit code 6 when the probe fails.

### Alerts

```sh
runr alerts channels create ops --type email --email ops@example.com --email sre@example.com
runr alerts channels create oncall --type slack --webhook-url @slack-url.txt
runr alerts channels create hook --type webhook --url https://hooks.example.com/runr --secret -
runr alerts channels create tg --type telegram --bot-token @bot-token.txt --chat-id -1001234567890
runr alerts channels test oncall
runr alerts rules create api-errors --type error_rate -p orders --param threshold=5 --channel oncall
runr alerts rules create slow --type p95_latency -p orders --param threshold_ms=800 --severity critical
runr alerts rules create capacity --type resource_threshold --region eu-1 --param resource=memory
runr alerts events --firing
runr alerts deliveries --failed
runr alerts deliveries retry 01J…
```

Channels are `email` (`--email`, up to 20), `slack` and `teams` (`--webhook-url`), `telegram`
(`--bot-token`, `--chat-id`) and `webhook` (`--url`, optional `--secret` for the
`X-RunR-Signature` HMAC); `--config` takes the whole configuration as JSON. Secret values are
write-only: the API shows the host of a URL and whether a secret is set, and `@file` or `-`
(stdin) keeps them out of the shell history. `runr alerts channels update` keeps the settings
it is not given. `runr alerts channels test` sends a test message now and ends with exit code 6
when it cannot be delivered.

Rule types and their `--param key=value` parameters:

| Type | Fires when | Parameters |
|---|---|---|
| `resource_threshold` | a region's CPU or memory reservation crosses 60 % / 80 % | `resource` (`cpu`, `memory`, `any`), `min_level` (`warning`, `critical`); `--region` |
| `uptime_down` | an uptime incident opens (resolves with it) | `-p` limits it to a project |
| `error_rate` | share of failed invocations above `threshold` % | `threshold`, `window_minutes`, `min_requests` |
| `p95_latency` | 95th percentile duration above `threshold_ms` | `threshold_ms`, `window_minutes`, `min_requests` |
| `cold_start_rate` | share of cold starts above `threshold` % | `threshold`, `window_minutes`, `min_requests` |

The last three read the span metrics of traced projects. Every rule has a `--severity`
(`info`, `warning` — the default — or `critical`), a `--cooldown` (default 15 minutes: a
still-firing alert is sent again after it) and the `--channel`s it sends to. `-p` scopes a rule
to a project; `runr alerts rules update RULE --all-projects` removes the scope. The type of a
rule is fixed. `runr alerts events` lists fired and resolved alerts; `runr alerts deliveries`
the delivery log (status, attempts, response code, last error) with `retry` for a failed
delivery.

### Status page

```sh
runr status-page                                 # settings and the checks it can show
runr status-page set --slug acme --title "Acme status" --public --show health
runr status-page set --custom-domain status.example.com --history-days 30
```

The organization's public page lives at `<console>/status/<slug>` (and at
`https://<custom domain>/status` once the domain points at the console); it shows the uptime
checks marked for it with their daily uptime and the incident history. `set` changes only the
flags given; the first save needs `--slug` and `--title`.

### The tracing backend of a region

Region administrators choose where a region's spans go with `runr regions tracing`:

```sh
runr regions tracing eu-1                                    # mode, status, retention, sampling
runr regions tracing set eu-1 --mode managed --retention-hours 168 --install --wait
runr regions tracing set eu-1 --mode reuse --tempo-url http://tempo.monitoring.svc:3200 \
  --tempo-otlp-endpoint tempo.monitoring.svc:4317 --install --wait
printf 'Authorization: Bearer %s\n' "$OTLP_TOKEN" | runr regions tracing set eu-1 \
  --mode external --export-endpoint https://otlp.example.com/v1/traces --export-headers -
runr regions tracing install eu-1 --wait                     # apply the stored configuration again
runr regions tracing rm eu-1 --yes                           # remove the collector (and RunR's Tempo)
```

| Mode | Description |
|---|---|
| `managed` | RunR installs Tempo and the collector in the region; `--retention-hours` (1–720) bounds how long traces are kept |
| `reuse` | the collector sends to a Tempo already in the cluster: `--tempo-url`, `--tempo-otlp-endpoint`, `--tempo-org-id` (multi-tenant Tempo) |
| `external` | the collector exports OTLP to `--export-endpoint` (https); RunR cannot query those traces |
| `off` | no collector |

`set` keeps every setting a flag leaves out (the mode too) and `--sample-rate` (0–1) sets the
share of requests Knative traces. Export headers are write-only: `--export-headers` reads them
from a file or stdin (`"Name: value"` lines or a JSON object, at most 10), RunR shows their
names only, and `--clear-export-headers` removes them. `--install` applies the configuration
at once; `--wait` (with `--install`, and on `install`) follows the install: exit code 6 when it
fails, 7 after `--timeout` (default 15 minutes). A region without a cluster connection answers
409 (exit code 5).

### Permissions

| Command | Needs |
|---|---|
| `runr traces [ls]`, `traces get` | `traces.view` (`--logs` also `logs.view`) |
| `runr traces status` | `projects.view` |
| `runr traces enable\|disable` | `projects.update` (and `projects.deploy` for the redeploy, or `--no-deploy`) |
| `runr traces replay …` | `replay.manage` (`enable\|disable` redeploy like `traces enable`) |
| `runr metrics [history\|live]`, `runr uptime ls\|results\|incidents` | `metrics.view` |
| `runr uptime create\|update\|rm\|run`, `incidents update` | `uptime.manage` |
| `runr alerts …` | `alerts.manage` |
| `runr status-page …` | `status_page.manage` |
| `runr regions tracing [get]` | `regions.view` |
| `runr regions tracing set\|install\|rm` | `regions.observability.manage` |

## AI agents: runr mcp

RunR serves the [Model Context Protocol](mcp.md) at `https://<console>/mcp`: AI agents —
Claude Code, Claude Desktop, Cursor, VS Code and any other MCP client — operate RunR with
exactly the permissions of their token. Projects can also be served as MCP servers of their
own behind RunR's gateway (`runr mcp-server`).

```sh
runr mcp                                         # endpoint + configuration of the common clients
runr mcp --client cursor --auth token            # one client, with an API token
runr mcp token create --preset read --client claude-code
runr mcp clients                                 # OAuth clients connected with your account
runr mcp clients revoke Claude --yes
runr mcp tools --allowed                         # the tools this token may use
```

### Connecting a client

`runr mcp` (also `runr mcp config`) reads `GET /api/mcp` and prints the endpoint, the
transport (Streamable HTTP), the protocol revisions, whether OAuth 2.1 is offered, the token
lifetimes, and a ready-to-paste configuration for each client:

| Client | Where it goes |
|---|---|
| Claude Code | `claude mcp add --transport http runr <endpoint>`, run in a shell |
| Claude Desktop | `claude_desktop_config.json`, through the `mcp-remote` bridge (`npx -y mcp-remote <endpoint>`) |
| Cursor | `~/.cursor/mcp.json` (or `.cursor/mcp.json` in the project) |
| VS Code | `.vscode/mcp.json` |

- **OAuth** (the default where the server offers it): the client needs the URL only; it
  discovers RunR's authorization server, registers itself and opens the consent page in the
  browser, where you pick the permissions and the lifetime of its token.
- **API token** (`--auth token`, or the default when the server has no OAuth): the client
  sends `Authorization: Bearer <token>`. `--token` (or `--token-stdin`) embeds a token;
  without one the snippets take it from the `RUNR_TOKEN` environment variable (Claude Code,
  Cursor: `${env:RUNR_TOKEN}`), ask for it (VS Code: a password input) or show a
  `<RUNR_TOKEN>` placeholder to replace (Claude Desktop).

`--client claude-code|claude-desktop|cursor|vscode` prints one client. `-o json` prints the
API's answer (`mcp`), the chosen `auth` and the `snippets` (`client`, `where`, `format`,
`text`).

### Tokens for agents

```sh
runr mcp token create --preset read                       # read-only tools
runr mcp token create ci-agent --group deployments --group operations --client cursor
runr mcp token create --scope projects.view,logs.view --expires 7d
runr mcp token create --preset full --expires never
```

`runr mcp token create [NAME]` creates an API token (`POST /api/tokens`) for an MCP client and
prints it once, with the configuration of `--client` (default Claude Code) that sends it. What
the agent may do:

| Choice | Permissions |
|---|---|
| `--preset read` | the permissions of every read-only tool of the catalog |
| `--preset deploy` | the `deploy` token preset plus `logs.view` and `metrics.view` |
| `--preset full` | every permission you hold |
| `--group NAME` (repeatable) | what the tools of a catalog group need: `projects`, `code`, `git`, `deployments`, `operations`, `observability`, `configuration`, `sandboxes`, `workflows`, `bindings`, `models`, … |
| `--scope PERMISSION` (repeatable, comma-separated) | these permissions |

The choices add up. On a terminal without any of them, runr lists the tool groups with the
permissions they need and asks (numbers, `r` for read-only or `a` for full access); in a
script one of them is required (exit code 2). The token never exceeds your own permissions
(the API refuses more with exit code 5). It expires after the server's default token lifetime
(30 days unless the installation changed it); `--expires 7d` or `720h` sets another lifetime,
`--expires never` keeps it until it is revoked. The default name is `mcp-<client>` with
`--client`, else `mcp-agent`.

### Connected clients and tools

`runr mcp clients` lists the OAuth clients connected with your account — client id, name,
redirect hosts, tokens, when they connected and were last used. `runr mcp clients revoke
CLIENT --yes` (client id or name) revokes every token the client holds of yours; its access
ends at once.

`runr mcp tools` lists the tool catalog: each tool with its group, the permissions it needs
(`a|b`: either) and whether the current token may use it. `--group`, `--search TEXT` and
`--allowed` (only tools the token may use) filter the list; `-o json` adds descriptions,
annotations and input schemas.

### Hosted MCP servers: runr mcp-server

```sh
runr mcp-server templates                         # Python, TypeScript, a container image
runr mcp-server set -p weather                    # serve the project (access mode token), redeploy
runr mcp-server set --access-mode oauth --audit-calls
runr mcp-server                                   # URL, access, path, key, the Claude Code command
runr mcp-server inspect                           # initialize and list the server's tools
runr mcp-server inspect --tool forecast --args '{"city": "Ankara"}'
runr mcp-server calls --outcome error
runr mcp-server rotate-key --yes
runr mcp-server ls                                # every hosted server of the organization
runr mcp-server rm --yes
```

A project with the http trigger is served at `https://<console>/mcp/servers/<handle>` (the
handle is the project's slug); the gateway forwards to the function's URL plus the path
(default `/mcp`), signs every request and records every call.

- **`set`** serves the project or changes it: `--access-mode public|token|oauth` (a new server:
  `token`), `--path`, `--audit-calls` (every call also in the audit log). A project with a
  schedule or queue trigger is refused (exit code 5). The gateway key reaches the workload
  with a deploy, so runr deploys the live version again when the server is new or the
  running deployment has an older key (`--no-deploy` leaves that to the next `runr deploy`,
  `--timeout` bounds the wait).
- **`get`** (the default) shows the URL, access mode, path, call auditing, the function URL,
  the OAuth resource metadata and when the key was rotated, plus the configuration of
  `--client` (default Claude Code) for this server: the URL alone for `public` and `oauth`,
  with a bearer token for `token`. A project that is not served answers exit code 4.
- **`rotate-key --yes`** creates a new gateway key, which reaches the workload with a deploy.
  For seven days after the rotation the gateway signs with both keys, so a deployment that
  still has the old key keeps working. runr redeploys the live version (`--no-deploy`: deploy
  within the seven days). The key itself is never shown: the server reads it from
  `RUNR_MCP_GATEWAY_KEY`.
- **`calls`** lists the newest calls through the gateway: time, JSON-RPC method, tool or
  resource name, caller, access mode, status, outcome and duration (`--outcome ok|error|denied|
  unreachable`, `--method`, `--limit` up to 100).
- **`inspect`** talks to the server through the gateway as you (auth `console`): without flags
  it initializes a session and lists the tools; `--tool NAME --args JSON|@file|-` calls a tool
  (a tool error or an HTTP error answer of the server ends with exit code 6),
  `--method NAME --params JSON` sends another request (`resources/list`, `prompts/get`, …),
  `--message JSON|@file` sends one JSON-RPC message as it is; `--session` and
  `--protocol-version` continue a session, `--no-init` skips the initialization. `-o json`
  prints every request and answer. A server that does not answer ends with exit code 8.
  Inspector requests appear in the call log.
- **`rm --yes`** stops serving the project (the project and its deployment stay; the call log
  is removed). **`ls`** lists the organization's servers; **`templates`** the starters with
  their runtime (`mcp-python`, `mcp-node`) and whether it is registered.

### Permissions

| Command | Needs |
|---|---|
| `runr mcp [config]`, `mcp tools`, `mcp-server templates` | a login |
| `runr mcp token create`, `mcp clients [ls]\|revoke` | `tokens.manage` |
| `runr mcp-server [get]\|ls` | `projects.view` |
| `runr mcp-server set\|rotate-key\|rm` | `mcp.servers.manage` on the project (and `projects.deploy` for the redeploy) |
| `runr mcp-server calls` | `logs.view` |
| `runr mcp-server inspect` | `mcp.servers.call` |

## Supply chain: runr security

```sh
runr security                              # every version: status, vulnerabilities, signature
runr security orders-api --version v7      # one version: findings and the deploy policy verdict
runr security --live --fail-on critical    # CI gate on the live deployment
runr security --version latest --scan --wait
runr security --version v7 --sbom sbom.spdx.json
runr security --version v7 --sbom - --sbom-format cyclonedx-json > bom.cdx.json
```

RunR produces an SBOM, a vulnerability scan and a signature for every version's image and the
region's policy decides whether a version may deploy ([supply chain](supply-chain.md)).
`runr security [PROJECT]` reads that report; the project argument replaces `--project` and
`runr.yaml`.

| Flag | Description |
|---|---|
| (none) | overview: the region and its data location, the deploy policy, the pipeline steps, every version with its counts and signature, the live version |
| `--version REF` | one version (`v3`, `3`, `latest` or an id): image, digest, step status, vulnerability counts (and how many are fixable), the findings (`--all-findings` lists more than 20) and the policy verdict (allowed, allowed with warnings, blocked, overridden) |
| `--live`, `--deployment ID` | the version a deployment runs |
| `--scan` | queue a new scan (a full run when the version has no SBOM yet); `--wait` waits until it finished: exit code 6 when the run fails, 7 after `--timeout` (default 15 minutes) |
| `--sbom FILE` | download the version's SBOM (`-` for stdout), `--sbom-format spdx-json` (default) or `cyclonedx-json` |
| `--fail-on SEVERITY` | exit code 6 when the report has findings of `critical`, `high`, `medium` or `low` severity or higher; `policy` fails when the policy blocks the version; a version without a report fails as well |

`-o json` prints the API's report, findings included. A scan while one runs answers 409
(exit code 5).

A model service's image (the engine's or its own) has a report of its own when its region scans
external images: `runr models services security SERVICE` takes `--sbom`, `--sbom-format`,
`--fail-on` and `--all-findings` like `runr security` ([Model services](#model-services)).

### The supply chain of a region

```sh
runr regions supply-chain eu-1                                  # scans, scanner DB, gate, signing, admission, CaaS policy
runr regions supply-chain set eu-1 --set gate_mode=enforce --set gate_max_critical=0 --set gate_max_high=
runr regions supply-chain set eu-1 --set caas_policy=signed --set caas_allowed_registries=ghcr.io/acme --trusted-key acme.pub
runr regions supply-chain signing-key rotate eu-1 --wait --yes  # generate, or rotate (the old key is retired)
runr regions supply-chain signing-key forget-retired eu-1 --yes
runr regions supply-chain public-key eu-1 > cosign.pub          # for cosign verify --key
runr regions supply-chain scanner-db refresh eu-1 --wait
runr regions supply-chain admission preview eu-1 -o yaml        # the Helm values and policy documents an install applies
runr regions supply-chain admission install eu-1 --mode warn --wait
runr regions supply-chain admission uninstall eu-1 --yes
runr regions supply-chain runtime-images eu-1                   # runtime images built there and their reports
runr regions supply-chain runtime-images sbom eu-1 REPORT --format cyclonedx-json --file q.cdx.json
```

`runr regions supply-chain [get] [REGION]` shows a region's supply chain: SBOMs and scans (with
external images and the rescan interval), the scanner DB (mode, status, source), the policy gate
(mode, maximum critical and high findings, only fixable findings, a required scan), signing (the
key's status and fingerprint, retired keys, verification on deploy, attestations), the admission
policy (mode, status, required SBOM, exempt images), the CaaS image policy (registries, trusted
keys), the data location and whether you can change it. `set REGION` changes settings with
`--set KEY=VALUE` (repeatable): `sbom_enabled`, `scan_enabled`, `scan_external_images`,
`gate_only_fixable`, `gate_require_scan`, `signing_enabled`, `verify_on_deploy`, `attest_enabled`,
`admission_require_sbom` (true/false, yes/no, on/off), `rescan_interval_hours`,
`gate_max_critical` and `gate_max_high` (empty: no limit), `scanner_db_mode`
(`online|mirror|offline`), `scanner_db_source` and `data_location` (empty: none), `gate_mode` and
`admission_mode` (`off|warn|enforce`), `caas_policy` (`any|allowlist|signed`),
`admission_exempt_images` and `caas_allowed_registries` (comma-separated); `--trusted-key FILE`
(repeatable, `-` for stdin) sets the PEM keys of signed CaaS images and `--set caas_trusted_keys=`
clears them. An installed admission policy follows the admission and CaaS settings.

Key generation, the scanner DB copy (mirror and offline modes; the region needs a registry) and
admission installs run in the background; `--wait` waits (exit code 6 when they fail, 7 at
`--timeout`). `signing-key rotate` generates the key, or a new one: the current key is retired
(images it signed still verify while it is listed) and its private key is gone, so a rotation asks
first (`--yes`). `signing-key forget-retired` removes the retired keys — images only they signed
no longer verify — and asks too, as does `admission uninstall`. `admission install` takes `--mode
warn|enforce` (default: the region's `admission_mode`). `public-key` prints the PEM (`--file`
writes it), `runtime-images sbom` the SBOM of a runtime image report (`--format`, `--file`).

### Permissions

| Command | Needs |
|---|---|
| `runr security …` (reports, `--scan`, `--sbom`) | `security.view` |
| `runr regions supply-chain [get]\|public-key\|runtime-images [sbom]` | `regions.view` |
| `runr regions supply-chain set\|signing-key …\|scanner-db refresh\|admission …` | `regions.supply_chain.manage` on a region your organization may change |
| `runr models services security …` | `models.view` |

## Data residency: runr residency

```sh
runr residency
runr residency -o json
```

Prints whether your organization may create projects in every region or only in the allowed
ones, whether residency is enforced, the allowed data locations, and per region its data
location, whether it is allowed and whether it is the default region
([data residency](supply-chain.md#data-residency)).

On an installation with several organizations, an organization's security policy holds its
residency and its own policy gate:

```sh
runr residency policy                                          # yours; --tenant ID for another
runr residency set --allow-location TR --default-region ist-1 --enforce
runr residency set --tenant 01J… --allow-region eu-1 --allow-region eu-2
runr residency set --gate-mode enforce --gate-max-critical 0 --gate-require-scan
runr residency set --unset gate-max-high --all-locations --no-default-region
```

`residency policy` shows whether residency is enforced, the allowed regions and data locations
(empty lists restrict nothing), the default region and the organization's policy gate (mode,
maximum critical and high findings, a required scan), which is stricter than the regions' where
it is set. `residency set` changes only what it is given: `--enforce[=false]`,
`--allow-region` (repeatable, name or id) or `--all-regions`, `--allow-location` (repeatable) or
`--all-locations`, `--default-region` (it must be allowed) or `--no-default-region`;
`--gate-mode off|warn|enforce`, `--gate-max-critical`, `--gate-max-high`,
`--gate-require-scan[=false]`, and `--unset gate-mode|gate-max-critical|gate-max-high|gate-require-scan`
to leave one to the regions. Without `--tenant` both work on your organization.

### Permissions

| Command | Needs |
|---|---|
| `runr residency` | a login (every signed-in user) |
| `runr residency policy` | `tenants.residency.manage` or `regions.supply_chain.manage` (another organization's: a platform administrator) |
| `runr residency set` | residency settings: `tenants.residency.manage`; gate settings: `regions.supply_chain.manage` |

## Access control: members, roles, groups

Role-based access control ([permissions](permissions.md)) is administered from the CLI as well
as from the console. Users are given by id, e-mail address or username; roles by key or name;
groups by id or name.

```sh
runr members                                   # members, their own role and groups
runr members set-role grace@example.com viewer
runr members add grace@example.com --role developer
runr members rm grace@example.com --yes

runr roles                                     # built-in and custom roles, * marks the default
runr roles permissions                         # the permission catalog
runr roles create "Release manager" --permission projects.deploy,logs.view,metrics.view
runr roles update release-manager --add domains.manage --remove metrics.view
runr roles default viewer                      # the role new members get
runr roles rm release-manager --yes

runr groups create platform --role developer
runr groups add-member platform grace@example.com alan
runr groups update platform --role ""         # the group grants project access only

runr access -p orders-api                      # who has a role on this project
runr access grant grace@example.com --role developer -p orders-api
runr access grant --group platform --role viewer -p orders-api
runr access revoke grace@example.com -p orders-api --yes
```

- **Members.** `runr members ls` lists the organization's members with their own
  organization-wide role and their groups; `set-role` changes that role (nobody changes their
  own role, and the organization keeps at least one owner). `runr members add EMAIL` (alias
  `invite`) depends on the edition: a multi-tenant installation invites the address into the
  current organization (an existing account is added at once, anyone else gets an invitation
  e-mail); a single-tenant installation creates the account directly with `--name`,
  `--username` (default: the part of the address before `@`) and an initial password from
  `--password-stdin` or a prompt. `runr members rm` removes a member from the organization
  (multi-tenant) or deletes the account (single-tenant).
- **Roles.** Built-in roles (`owner`, `admin`, `developer`, `viewer`) are read-only. Custom
  roles are created from `--permission` (repeatable or comma-separated) or a file
  (`--from-file role.yaml` with `name`, `key`, `description`, `permissions`; YAML or JSON);
  the key defaults to the slug of the name. RunR adds the permissions a permission implies
  (`env.edit` brings `env.view`), so `runr roles get` lists more than you set. `update`
  replaces the permissions with `--permission` or changes single ones with `--add` /
  `--remove`. Deleting a role gives its members the default role. Nobody can create or change
  a role with permissions they do not hold themselves.
- **Groups.** Members of a group get the group's organization-wide role (`--role`, optional)
  and the project access granted to the group. Members synced from single sign-on show the
  source `sso`.
- **Project access.** `runr access grant` gives a user or a group (`--group`) a role on the
  project of `-p` / `runr.yaml` only; granting again changes the role of the same grant.
  `runr access revoke` takes the grant id (`runr access ls` lists it), a user or `--group`.
  The Owner role is given to members directly only.

```yaml
# release-manager.yaml — runr roles create -f release-manager.yaml
name: Release manager
key: release-manager
description: Deploys releases and watches them
permissions: [projects.deploy, logs.view, metrics.view]
```

**Permissions.** `runr members ls`, `runr groups ls|get` → `members.view`; `runr members set-role`,
`runr roles create|update|rm|default` → `roles.manage`; `runr members add` → `members.invite`;
`runr members rm` → `members.remove`; `runr groups create|update|rm|add-member|rm-member` →
`groups.manage` (plus `roles.manage` to set a group's role); `runr access ls` →
`projects.view`, `runr access grant|revoke` → `projects.manage_access` on the project;
`runr roles ls|get|permissions` → any signed-in user.

## Audit log: runr audit

```sh
runr audit ls                                         # the newest 25 events
runr audit ls --action 'project.secret.*' --since 7d
runr audit ls --this-project --limit 100 -o json
runr audit ls --actor ada --result denied
runr audit get 01JC…                                  # one event with its metadata
runr audit export --since 30d --file audit.csv
runr audit export --format json --action 'role.*,project.access.*' > access.json
```

The audit log is append-only ([audit log](security.md#audit-log)). Filters of `ls` and
`export`:

| Flag | Filter |
|---|---|
| `--actor` | user id, or part of the name or e-mail |
| `--action` | exact action, `project.*` for a prefix, or a comma-separated list |
| `--target-type`, `--target-id`, `--target` | the target's type, id, or part of its name |
| `--this-project` | the project of `--project` / `runr.yaml` (target type `project` and its id) |
| `--result` | `success`, `denied` or `failure` |
| `--since`, `--until` | RFC 3339, a date (`2026-10-01`) or a duration back from now (`24h`, `7d`) |
| `--request-id` | the `X-Request-ID` of an API request |
| `--query` | free text (action, actor, target, IP address) |
| `--tenant` | a tenant id, or `platform` (platform administrators outside a tenant) |

`runr audit ls` prints up to `--limit` events (1–100, default 25), newest first, and says
how many match; `runr audit export` streams every matching event (up to the API's export
limit, 100 000 by default) as CSV or JSON (`--format`) to `--file` or stdout. Listing needs
`audit.view`, exporting `audit.export`.

```sh
runr audit settings                                   # retention, forwarding, whether you can change it
runr audit settings set --retention-days 730          # 0 keeps events forever
```

The retention applies to the whole installation: a daily job deletes older events (up to the
longest retention the API allows, 3650 days). `runr audit settings` needs `audit.view`;
`settings set` needs `settings.manage`, and on an installation with several organizations a
platform administrator outside any organization (others get exit code 3).

## Regions

A region is a Kubernetes cluster (1.30 or newer) projects run in ([architecture](architecture.md#regions)).
`runr regions` connects clusters, installs what projects need on them — Knative, a Redis for
queue triggers, an image registry, WebAssembly support — and changes, moves and removes them. A
region is named by its name or id; with one active region the name may be left out of reading
commands.

```sh
runr regions check eu-2 --kubeconfig eu-2.yaml      # version, nodes, StorageClasses, the namespace
runr regions create eu-2 --kubeconfig eu-2.yaml --node worker-1 --node worker-2
runr regions knative install eu-2 --wait            # the Knative operator, then Knative Serving
runr regions get eu-2                               # cluster, Knative, Redis, registry, workers, capacity
runr regions status eu-2                            # checks the cluster now
```

### Creating a region

`runr regions create NAME --kubeconfig FILE` connects a cluster. NAME is also the namespace the
region's projects run in, which must not exist on the cluster yet; `runr regions check [NAME]
--kubeconfig FILE` tests the kubeconfig, the Kubernetes version and the namespace and lists the
worker nodes and StorageClasses (exit code 5 when one of them stands in the way). A kubeconfig is
read from a file or stdin (`-`), never from an argument; RunR stores it encrypted and runr's
trace (`--debug`) never shows it.

| Flag | Meaning |
|---|---|
| `--node NODE` | a worker node projects run on (repeatable); default: every schedulable node |
| `--inactive` | create the region inactive (no deploys until `runr regions set REGION --set status=active`) |
| `--redis MODE` | the Redis of queue triggers: `managed` (default, RunR installs it), `in_cluster` or `external` (`--redis-address host:port`, `--redis-password-file FILE`), `none` |
| `--prometheus-url`, `--loki-url`, `--loki-org-id` | reuse a Prometheus / Loki already running in the cluster (an in-cluster Service URL) instead of installing RunR's |
| `--registry internal\|external` | set the region up with an image registry, in the background (below) |
| `--wait`, `--timeout` | wait until the region and its Redis are set up (exit code 6 when the setup fails) |

With `--registry` the region and its registry are set up in the background: `internal` runs
RunR's registry on a volume (`--registry-storage pvc`, the default, with `--storage-class` —
default the cluster's default class — and `--registry-size`, default `10Gi`) or on S3
(`--registry-storage s3 --registry-s3 FILE` with `ENDPOINT`, `BUCKET`, `REGION`, `ACCESS_KEY` and
`SECRET_KEY` lines); `external` uses yours (`--registry-address`, `--registry-credentials FILE`
with `USERNAME` and `PASSWORD` lines). The command prints a setup token; `--wait` (or
`runr regions registry setup-status TOKEN --wait`) follows it until the region exists. A failed
setup starts again with `runr regions create NAME --retry TOKEN` and the same flags.
`runr regions registry test pvc|s3|external --kubeconfig FILE` checks a StorageClass, a bucket or
an external registry from a pod in the cluster first.

```sh
runr regions registry test pvc --kubeconfig eu-2.yaml --storage-class fast-ssd
runr regions create eu-2 --kubeconfig eu-2.yaml --registry internal --storage-class fast-ssd --wait
runr regions create eu-3 --kubeconfig - --registry external --registry-address registry.example.com/team \
    --registry-credentials registry.env < eu-3.yaml
```

### Status, Knative and worker nodes

`runr regions get` shows the whole region; `runr regions status` checks the cluster now: whether
RunR reaches it and Knative Serving and the region's Traefik are ready (`ready`, `degraded` with
the reasons, `not_installed`). While an install runs or after an error it shows the recorded
state; `--refresh` checks anyway.

`runr regions knative [REGION]` shows the Knative operator and Knative Serving. `install REGION`
installs both with Helm; `install REGION operator` and then `install REGION serving` install them
one by one (`--namespace`; `--version` picks a Knative minor version of
`runr regions knative versions`, default the newest the cluster supports). An operator or Serving
already running in the namespace is adopted instead, and `runr regions knative detect REGION`
adopts what runs on the cluster. `uninstall REGION [all|operator|serving]` removes them (the
operator after Serving; the region's Traefik stays while it serves other Ingresses, such as the
console on the same cluster, unless `--remove-traefik`). Installs and removals run in the
background: `--wait` waits, exit code 6 when one fails.

`runr regions nodes [REGION]` lists the worker nodes with their capacity and whether projects run
on them; `runr regions nodes set REGION NODE…` reserves nodes for projects (a taint only projects
tolerate) and releases the others, `--all` reserves none. Releasing nodes asks for confirmation.
The region's capacity follows the selection.

### Kubeconfig

```sh
runr regions kubeconfig eu-1                          # ./eu-1-kubeconfig.yaml, mode 0600
runr regions kubeconfig eu-1 -f ~/.kube/eu-1.yaml --force
runr regions kubeconfig eu-1 --stdout | kubectl --kubeconfig /dev/stdin get nodes
```

The kubeconfig RunR uses for a region is a credential. `runr regions kubeconfig` writes it to a
file only you can read (mode 0600; an existing file is kept unless `--force`, which also tightens
its mode) and prints it only with `--stdout`; `-o json` prints the file name and size, never the
content. `runr regions set REGION --kubeconfig FILE` replaces it, for example after a credential
rotation.

### Settings, capacity and function TLS

`runr regions set REGION --set KEY=VALUE` changes a region:

| Key | Value |
|---|---|
| `status` | `active`, `inactive` (no new deploys) or `maintenance` |
| `request_timeout` | the longest request of a function in seconds (1–3600, default 300) |
| `agent_ws_url` | RunR's address as the region's agents reach it (`wss://…`); empty: the console URL |
| `cert_manager_cluster_issuer` | the ClusterIssuer of custom-domain certificates; empty: none |
| `ingress_trusted_ips` | comma-separated addresses or CIDRs of a load balancer in front of the region's Traefik that sends PROXY protocol or `X-Forwarded-For`; empty: trust nobody |

`runr regions capacity [REGION]` shows the region's capacity budgets: the always-on budget (the
capacity, without overcommit) and the FaaS overcommit budget of scale-to-zero projects and model
services ([architecture](architecture.md#region-capacity-and-admission)).
`runr regions capacity set REGION --set faas_cpu_overcommit=4 --set faas_memory_overcommit=1.25`
changes the factors (1–10, two decimals; 1 = no overcommit).

`runr regions function-tls [REGION]` shows the certificate the region's Traefik serves function
domains with; `runr regions function-tls set REGION --mode console` uses the console's
certificate, `--mode custom --cert FILE --key FILE` (PEM, `-` for stdin) your own.
`--replace-existing` replaces a default TLS store of the region's Traefik that RunR did not create.

### Redis, registry and Skupper

`runr regions redis [REGION]` shows the Redis queue triggers use. `runr regions redis test REGION
--address host:port --password-file FILE` checks from a pod in the cluster that a Redis answers;
`runr regions redis set REGION --mode managed|in_cluster|external` installs or connects one
(`--address`, `--password-file`, `--wait`), `runr regions redis rm REGION` removes it.

`runr regions registry [REGION]` shows the region's image registry; `runr regions registry rm
REGION` removes it (RunR's registry with its images). Builds in a region — runtime plugins with a
build step, WebAssembly functions — push their images to it.

Private database connections of [bindings](bindings.md) run over Skupper v2, which the cluster's
operators install: `runr regions skupper [REGION]` shows whether the cluster runs it and the site
of the region's project namespace, `runr regions skupper site REGION` creates the site.

### Moving a region to another cluster

```sh
runr regions migration start eu-1 --kubeconfig new-cluster.yaml --wait
runr regions migration eu-1                             # dns_check: point DNS at the new address
runr regions migration complete eu-1                    # or: runr regions migration rollback eu-1
```

`start` installs the region's components on the new cluster and deploys its projects there again,
in the background (`--wait` waits until the move reaches the DNS step; exit code 6 when it
fails). Then point the region's DNS at the new address and `complete` the move, which releases the
old cluster, or `rollback` to the old cluster (also after a failed move). A region with a data
location moves only within it: `--data-location` names the new cluster's.

### WebAssembly and demo projects

`runr regions wasm [REGION]` shows WebAssembly support: its status, the shims, and — read from the
cluster — every node with its eligibility, the RuntimeClasses and the installer ([WebAssembly](wasm.md#install-webassembly-support);
`--no-cluster` shows the recorded state only). `runr regions wasm install REGION` installs the
Spin shim (`--shim spin --shim wasmtime` for both) on every eligible node or the `--node` ones;
installing again changes the shims or nodes. It restarts the container runtime once on every
selected node, so it asks for confirmation (`--yes`); `--wait` follows it.
`runr regions wasm uninstall REGION` removes it (refused while Wasm projects run).

`runr regions demo install REGION` deploys a set of demo projects to a region with Knative
Serving, `runr regions demo rm REGION` removes them; `runr regions demo [REGION]` shows their state.

Every command that removes something — `rm`, the `uninstall` commands, `redis rm`,
`registry rm`, `migration rollback`, `demo rm`, `nodes set` when it releases nodes — asks for
confirmation on a terminal and needs `--yes` elsewhere.

### Permissions

| Commands | Permission |
|---|---|
| `get`, `status`, `nodes`, `knative`, `knative versions\|namespace`, `redis`, `registry`, `registry setup-status`, `migration`, `function-tls`, `skupper`, `capacity`, `demo`, `wasm` | `regions.view` |
| `create`, `check`, `set`, `rm`, `kubeconfig`, `nodes set`, `knative install\|uninstall\|detect`, `redis test\|set\|rm`, `migration start\|complete\|rollback`, `function-tls set`, `skupper site`, `capacity set`, `demo install\|rm` | `regions.manage` |
| `create --registry` | `regions.manage` and `registry.manage` |
| `registry rm`, `registry test` | `registry.manage` |
| `wasm install\|uninstall` | `sandbox.manage` |

On a multi-tenant installation the changes need a region your organization may change: the
platform's regions are read-only for organizations, an organization's own regions are changed by
its owners and admins.

## Output, errors and exit codes

Every command prints a human table by default and the API objects with `-o json` or
`-o yaml`. Progress lines go to stderr (`-q` silences them), results to stdout.

`--debug` prints every HTTP request and answer to stderr. The `Authorization` header,
cookies and the values of `password`, `token`, `plain_token`, `two_factor_code`,
`privateKey`, `secret_env_vars`, `kubeconfig`, `client_secret`, `access_key`, `secret_key`,
`credentials`, `access_token`, `refresh_token`, `webhook_secret`, `webhook_url`, `bot_token`,
`headers`, `values` (an external binding's variables) and `env` (a sandbox's environment)
fields are replaced by `[redacted]`; the bodies of the secrets endpoints
(`/projects/{project}/secrets/{name}` and its `/value`), binding credentials, the Hugging Face
token, a workflow signing key and alert channels are left out entirely
(`[redacted: N bytes]`). A streamed command (`runr sandbox exec`) is traced by event name
and size, never its output; the one-time tokens of `runr sandbox terminal`, `runr shell` and
of the exec WebSocket of `runr sandbox exec` are left out like the secret bodies, and their
WebSocket URLs are traced without the token.

| Exit code | Meaning |
|---|---|
| 0 | success |
| 1 | other error |
| 2 | wrong usage (flags, arguments, `--yes` needed) |
| 3 | not logged in, token rejected (401) or permission denied (403) |
| 4 | not found (project, version, region, domain, context) |
| 5 | rejected by validation (422, invalid `runr.yaml`, a domain whose DNS records are not found) |
| 6 | build, deployment or another awaited operation failed: a runtime image build, a workflow run (`--wait`), a binding, a model service, an offline copy or upload, a sandbox start, a security scan or `runr security --fail-on` |
| 7 | timed out waiting for a build or deployment |
| 8 | API unreachable or server error (5xx); safe requests are retried three times |

`runr sandbox exec` and `runr sandbox run` exit with the exit code of the command they ran
(130 after Ctrl+C); runr's own codes apply when the call itself fails (for example 5 when the
sandbox is not running, 6 when a new sandbox cannot start).

## CI/CD

In CI set `RUNR_API_URL` and `RUNR_TOKEN` (a token with the `deploy` preset) and run
`runr deploy`. `--output-env FILE` appends `project=`, `project_id=`, `version=`,
`version_id=`, `deployment_id=`, `status=` and `url=` lines — the format of
`$GITHUB_OUTPUT` and of GitLab dotenv reports.

- GitHub Actions: [the RunR deploy action](github-action.md).
- GitLab CI:

```yaml
# .gitlab-ci.yml — set RUNR_API_URL and RUNR_TOKEN (masked) in Settings → CI/CD → Variables
deploy:
  stage: deploy
  image: alpine:3.21
  variables:
    RUNR_VERSION: v0.5.0
  before_script:
    - apk add --no-cache curl tar
    - curl -fsSL "https://github.com/brf-tech/runr/releases/download/${RUNR_VERSION}/runr_linux_amd64.tar.gz" | tar -xz -C /usr/local/bin runr
  script:
    - runr deploy --message "$CI_COMMIT_SHORT_SHA $CI_COMMIT_TITLE" --output-env deploy.env
  artifacts:
    reports:
      dotenv: deploy.env      # later jobs get $url, $version, $deployment_id
  environment:
    name: production
    url: $url
  rules:
    - if: $CI_COMMIT_BRANCH == $CI_DEFAULT_BRANCH
```

## Configuration reference

| Variable | Effect |
|---|---|
| `RUNR_API_URL` | API URL; overrides the context |
| `RUNR_TOKEN` | API token; overrides the context |
| `RUNR_CONTEXT` | Context to use (like `--context`) |
| `RUNR_CONFIG` | Path of the config file |
| `RUNR_PROJECT` | Default project (like `--project`) |
| `RUNR_RUNTIME_REGISTRY` | Registry of the runtime images for `runr dev` (default `ghcr.io/brf-tech/runr`) |
| `RUNR_WRAPPER_IMAGE` | Image of the universal wrapper `runr dev` installs for exec and persistent runtime plugins (default `<registry>/wrapper:1.1.0`; `RUNR_RUNTIME_REGISTRY` sets the registry) |
| `RUNR_DOCKER` | Docker CLI binary for `runr dev` |

The config file:

```yaml
current_context: runr.example.com
contexts:
  runr.example.com:
    api_url: https://runr.example.com
    token: 12|…
    token_id: "12"          # set by login --with-password; logout revokes it
    user: ada
update_check: false         # true: runr version looks up the latest release
```

`runr` contacts GitHub only for `runr version --check-update` or with `update_check: true`.

## Building the CLI

The CLI lives in `cli/` (Go module `github.com/brf-tech/runr/cli`):

```sh
cd cli
make build        # bin/runr
make test         # unit and command tests against the in-memory fake API
make contract     # check the client against pkg/runr/testdata/openapi.json
make openapi      # export the OpenAPI document from ../api (local PHP or the API image)
make cross        # all release platforms into bin/
make snapshot     # goreleaser archives + checksums into dist/ (no publishing)
```

`pkg/runr` is the API client the CLI and the Terraform provider share. Every endpoint it
calls is declared in `pkg/runr/contract.go` with the fields and the permission it uses; the
contract test checks them against the OpenAPI document of the API (and against
`api/config/route_permissions.php`), and the fake API in `pkg/runr/runrtest` refuses
requests the contract does not declare. Refresh the document with `make openapi` after API
changes.
