# Runtimes

A RunR runtime is a **plugin**: a declarative manifest (`runtime.yaml`) plus, per version, a
**runtime image** — an existing image, one RunR builds from a Dockerfile or a Git repository, or
an [uploaded image](images.md). The manifest tells RunR everything it needs to run code of that
language — the image per version, the starter template, how dependencies are installed, how
compiled code is built, and how the process receives invocations (the run mode `http`, `exec` or
`persistent`; the last two through RunR's universal wrapper `runr-wrapper`). Python, Node.js, Go,
PHP, PyTorch, TensorFlow and the WebAssembly runtimes are plugins of exactly the same kind; a
runtime for C, Rust, Zig, Haskell or a language you wrote yourself goes through the same code
paths, the same build pipeline and the same sandbox.

The pod isolation layer is a separate thing: the **sandbox runtime** (runc, gVisor or Kata
Containers), i.e. the Kubernetes RuntimeClass a region maps each project type (XaaS, FaaS, CaaS)
to — see [Sandboxing, GPUs and RuntimeClasses](#sandboxing-gpus-and-runtimeclasses).

- [Sources of runtimes](#sources-of-runtimes)
- [How a function runs](#how-a-function-runs)
- [The execution contract](#the-execution-contract)
- [The universal wrapper](#the-universal-wrapper)
- [Manifest reference](#manifest-reference)
- [Validate a manifest locally](#validate-a-manifest-locally)
- [Version sources and runtime image builds](#version-sources-and-runtime-image-builds)
- [Compiled languages](#compiled-languages)
- [Dependencies](#dependencies)
- [Sandboxing, GPUs and RuntimeClasses](#sandboxing-gpus-and-runtimeclasses)
- [Permissions](#permissions)
- [API](#api)
- [CLI](#cli)
- [Console](#console)
- [Write your own runtime](#write-your-own-runtime)
- [Examples](#examples)
- [Built-in catalog](#built-in-catalog)
- [Configuration](#configuration)
- [Testing](#testing)

## Sources of runtimes

| Source (`source` in the API) | Where it comes from | Who sees it |
|---|---|---|
| **Built-in** (`builtin`) | the catalog shipped in the repository, `runtime/<id>/runtime.yaml` (next to the image's Dockerfile and language wrapper) — see [Built-in catalog](#built-in-catalog) | everyone; enabled / disabled and region availability are editable, the manifest follows the release |
| **Platform** (`platform`) | registered by a platform administrator in Settings → Runtimes, with `runr runtimes register` or with `POST /api/runtimes` | everyone, in the regions it is available in |
| **Custom** (`custom`) | registered by a member of an organization when the platform allows custom runtimes | the organization (tenant) that registered it; outside tenant context every user |

Each version of a runtime names where its runtime image comes from — `image`, `dockerfile`,
`git` or `upload` ([versions](#versions)). Bringing your own image works with every source: an
image that serves the [HTTP contract](#http-mode) itself (`run.mode: http`), or one that uses the
[universal wrapper](#the-universal-wrapper) as its entrypoint.

Runtime ids are one namespace across all sources: `projects.runtime` stores the id (`python`,
`c`, `q`), so an id identifies one runtime everywhere and a registration with an id that exists
already is rejected. Every manifest is validated strictly against the JSON Schema in
`api/resources/runtimes/runtime.schema.json` (`apiVersion: runr.sh/v1`) plus the rules a schema
cannot express; a rejected manifest comes back with every problem and its JSON path, e.g.
`/run/command: is required in exec mode`.

Disabling a runtime takes it out of the catalog: new projects cannot pick it and deploys of its
projects are refused with a message naming the runtime; running deployments stay untouched. The
same holds for a region the runtime is not offered in. A runtime with projects can be disabled,
not deleted.

## How a function runs

1. **Save.** Each save in the editor is a commit in the project's internal git repository and a
   new version. When the runtime has a [`build`](#compiled-languages) step, or a FaaS project
   contains the runtime's [dependency file](#dependencies), the version is built: RunR generates
   a Dockerfile from the manifest and runs it as a Kaniko Job in the project's region; the image
   lands in the region registry (`runr-builds/<project>:v<N>`).
2. **Deploy.** RunR renders a Knative Service (http and queue triggers) or a CronJob (schedule
   trigger). Without a build, the code is mounted from a ConfigMap at `/app/code` into the
   runtime version's image; with a build, the image already contains it.
3. **Invoke.** In `http` mode the runtime's own process serves requests on `$PORT`. In `exec`
   and `persistent` mode RunR's **universal wrapper** (`runr-wrapper`, a small static Go binary
   in the spirit of the OpenFaaS classic watchdog) is the container's entrypoint: it receives
   HTTP requests, CloudEvents from the queue consumer and schedule ticks, and hands each
   invocation to your program over stdin/stdout. Code-mounted functions get the wrapper through
   an init container (`runr-wrapper install`) that copies it into an `emptyDir` mounted at
   `/runr`; built images carry it at `/runr/runr-wrapper`.

The pods run under the RuntimeClass the region maps the project's type to — runc, gVisor or
Kata — exactly like built-in runtimes ([details](#sandboxing-gpus-and-runtimeclasses)).

## The execution contract

### Container environment

Every function container of a code runtime gets:

| Variable | Value |
|---|---|
| `RUNR_MODE` | `http` (http and queue triggers) or `schedule` |
| `RUNR_TRIGGER` | `http`, `schedule` or `queue` |
| `RUNR_HANDLER` | the handler function name (`handler`) |
| `RUNR_FILENAME` | the handler file (`main.py`, `main.c`, `main.q`) |
| `RUNR_CODE_DIR` | `/app/code` |
| `RUNR_RUNTIME`, `RUNR_RUNTIME_VERSION` | runtime id and the version the project runs on (the default version when the project has none) |
| `RUNR_TIMEOUT` | the project's timeout in seconds |
| `PORT` | the port to listen on (`8080`, set by Knative) |

plus `run.env` of the manifest and the project's environment variables (which win over the
variables above when they use the same name). A built image also carries `dependencies.env` when
the dependency file was installed. Runtimes that run through the wrapper additionally get
`RUNR_WRAPPER_MODE`, `RUNR_COMMAND` and `RUNR_INPUT` / `RUNR_WORKERS`
([wrapper reference](#the-universal-wrapper)).

### Run modes

#### http mode

The process listens on `$PORT` and answers every path. A code-mounted function starts
`run.command` (or the image's entrypoint when there is none); a built function's image has
`run.command` as its `ENTRYPOINT`. RunR sends it:

- **http trigger**: the client's request as it arrived (Knative's queue-proxy sits in front).
- **queue trigger**: the queue consumer reads the Redis stream and POSTs every entry as a
  [CloudEvent](https://cloudevents.io) in binary mode: `ce-specversion: 1.0`,
  `ce-type: dev.runr.queue.message`, `ce-source: /redisstream/<stream>/<group>`, `ce-id: <entry id>`,
  `ce-time`, `X-RunR-Delivery-Attempt`, `Content-Type: application/json` and the entry's fields
  as a JSON object in the body. A 2xx answer acknowledges the entry; anything else is retried
  with exponential backoff and, after the retry limit, moved to the dead-letter stream
  `<stream>-dlq`.
- **schedule trigger**, two ways (`run.schedule`):
  - `invoke` (default): the CronJob runs the wrapper, which starts `run.command` with `PORT`,
    waits until `127.0.0.1:$PORT` accepts connections (up to 60 s), POSTs one schedule
    CloudEvent in binary mode (`ce-type: dev.runr.schedule.tick`, `ce-source: /runr/schedule`,
    `Content-Type: application/json`, body `{"trigger":"schedule"}`), prints the response body,
    stops the server (SIGTERM, SIGKILL after 5 s) and exits 0 for a status below 400, 1
    otherwise. The server's own stdout goes to the logs. `run.command` is required for this.
  - `native`: the image itself runs the handler once and exits when `RUNR_MODE=schedule` — what
    the built-in images do.

#### exec mode

The wrapper starts `run.command` once per invocation:

- **stdin**: the [request envelope](#request-envelope) as JSON (`run.input: json`, default) or
  the raw request body (`run.input: raw`, handy for shell scripts and C).
- **environment**: the container environment plus, per invocation:

  | Variable | Value |
  |---|---|
  | `RUNR_TRIGGER` | `http`, `queue` or `schedule` |
  | `RUNR_REQUEST_ID` | the envelope `id` |
  | `RUNR_METHOD`, `RUNR_PATH` | request method and path |
  | `RUNR_QUERY` | the raw query string (`name=RunR&x=1`) |
  | `RUNR_CONTENT_TYPE` | the request's Content-Type |
  | `RUNR_HEADER_<NAME>` | every request header; the name upper-cased, every character other than A–Z and 0–9 replaced by `_` (`X-Api-Key` → `RUNR_HEADER_X_API_KEY`) |
  | `RUNR_EVENT_ID`, `RUNR_EVENT_TYPE`, `RUNR_EVENT_SOURCE` | the CloudEvent attributes (queue and schedule) |

- **stdout**: the [response](#responses).
- **stderr**: streamed to the function's logs.
- **exit code**: non-zero without a response envelope on stdout answers
  500 `{"error":"handler exited with status N"}`; a command that cannot be started answers
  500 `{"error":"handler failed to start"}`.

The process runs in its own process group, in `/app/code` when that directory exists.

#### persistent mode

Starting a process per request is cheap for a static binary and expensive for an interpreter.
In persistent mode the wrapper starts `run.workers` processes (1–16, default 1) once and keeps
them running; each gets `RUNR_WORKER` (its slot, from `0`) in its environment. For every
invocation the wrapper writes the request envelope as **one JSON line** to an idle process's
stdin and reads its stdout until a line parses as JSON — that line is the response. Lines that
are not JSON are written to the logs and skipped, so a stray debug print does not break the
protocol. Requests queue for a free worker; each worker handles one request at a time. The
per-invocation variables of exec mode are not set here: everything about the request is in the
envelope.

A worker that exits is restarted — after 100 ms when it served a request or ran for at least
10 s, otherwise with a delay doubling up to 5 s — and the request it was handling answers
502 `{"error":"handler process exited"}`. A request that exceeds the timeout, waiting for a
worker included, answers 504; a worker that ran into the timeout is killed and restarted.

### Request envelope

```json
{
  "runr": 1,
  "trigger": "queue",
  "id": "1728561234567-0",
  "method": "POST",
  "path": "/",
  "query": {},
  "rawQuery": "",
  "headers": {"content-type": "application/json", "ce-type": "dev.runr.queue.message", "host": "orders.fn.svc.cluster.local"},
  "body": "{\"item\":42}",
  "isBase64Encoded": false,
  "event": {
    "specversion": "1.0",
    "id": "1728561234567-0",
    "type": "dev.runr.queue.message",
    "source": "/redisstream/orders/orders-api",
    "time": "2026-10-10T12:00:00Z",
    "datacontenttype": "application/json",
    "data": {"item": 42}
  }
}
```

| Field | Meaning |
|---|---|
| `runr` | contract version, `1` |
| `trigger` | `http`, `queue` or `schedule` |
| `id` | the `X-Request-Id` header, else the `ce-id` header, else the CloudEvent id, else a generated UUID |
| `method`, `path` | the request line |
| `query` | query parameters, first value per name; `rawQuery` is the raw string |
| `headers` | request headers, lower-case names, repeated values joined with `, `, plus `host` |
| `body` | the body as a UTF-8 string; base64 with `isBase64Encoded: true` when it is not valid UTF-8 |
| `event` | present for CloudEvents and schedule ticks: binary mode (any request with a `ce-specversion` header, i.e. queue deliveries) or structured mode (`Content-Type: application/cloudevents+json`). `data` is the parsed JSON payload, else the payload string, `null` for an empty body; `subject` appears when the event has one |

A schedule invocation carries `method: POST`, `path: /`, the schedule CloudEvent headers,
`body: {"trigger":"schedule"}` and an `event` of type `dev.runr.schedule.tick` with source
`/runr/schedule` — the same request an http-mode server receives for a tick.

### Responses

What the program writes to stdout (exec) or as its response line (persistent) becomes the
HTTP response:

- **A JSON object with a numeric `statusCode`** is a response envelope:

  ```json
  {"statusCode": 201, "headers": {"Content-Type": "text/html"}, "body": "<b>created</b>", "isBase64Encoded": false}
  ```

  A string `body` is written as it is, with `Content-Type: text/plain; charset=utf-8` unless
  `headers` sets one; with `isBase64Encoded: true` it is base64-decoded and defaults to
  `application/octet-stream`. Any other `body` value is JSON-encoded with
  `Content-Type: application/json` unless set. Header values that are arrays become repeated
  headers, numbers and booleans are written as text. A `statusCode` outside 100–999 answers 500.
  This is the same `statusCode` / `headers` / `body` shape the built-in runtimes' handlers return.
- **Anything else** is the raw body with status 200: `Content-Type: application/json` when the
  output is valid JSON, `text/plain; charset=utf-8` otherwise. In persistent mode any JSON value
  other than an envelope is the body (a JSON string is sent as text).

Every response carries `Access-Control-Allow-Origin: *` unless the handler sets it, and the
wrapper answers CORS preflights (`OPTIONS`) with 204 and the same CORS headers the built-in
runtimes send (`Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS`,
`Allow-Headers: Content-Type, Authorization`, `Max-Age: 86400`). Knative probe requests
(`K-Network-Probe`, `K-Kubelet-Probe`) are answered `200 ok` by the wrapper without invoking your
program.

### Timeouts, limits and logs

- Each invocation may run for `RUNR_TIMEOUT` seconds (the project's timeout); the wrapper then
  kills the process group and answers 504 `{"error":"handler timed out after Ns"}`.
- Request bodies above 10 MiB answer 413; handler output above 10 MiB answers 502.
- stderr of your program — and in persistent mode every non-JSON stdout line — shows up in the
  project's logs next to the wrapper's own lines, which start with `[runr-wrapper]`.
- On SIGTERM (scale-down, new revision) the wrapper stops accepting requests, drains the ones
  in flight for up to 25 s and stops the workers (SIGTERM, SIGKILL after 5 s).

## The universal wrapper

`runr-wrapper` lives in `runtime/wrapper` (Go, standard library only) and ships as the image
`ghcr.io/brf-tech/runr/wrapper:<version>` (`FROM scratch`, the binary at `/runr-wrapper`). RunR
configures it through the environment; an image that wants the wrapper as its own entrypoint
sets the same variables.

| Variable | Default | Meaning |
|---|---|---|
| `RUNR_WRAPPER_MODE` | | `exec`, `persistent` or `http` (required) |
| `RUNR_COMMAND` | | the runtime command as a JSON array (`["q", "/app/code/main.q"]`); any other string runs through `/bin/sh -c` (required) |
| `RUNR_TRIGGER` | `http` | `http` and `queue` listen on `$PORT`; `schedule` invokes once and exits |
| `RUNR_INPUT` | `json` | exec mode stdin: `json` or `raw` |
| `RUNR_WORKERS` | `1` | persistent mode processes, clamped to 1–16 |
| `RUNR_TIMEOUT` | `30` | per invocation, seconds or a Go duration (`500ms`) |
| `RUNR_MAX_BODY` | `10485760` | request body and handler output limit in bytes |
| `PORT` | `8080` | listen port; in http mode the port of the runtime's server |
| `RUNR_CODE_DIR` | `/app/code` | working directory of the runtime command when it exists |
| `RUNR_TRACING` | | `1`: a server span per invocation, exported over OTLP/HTTP ([tracing](observability.md#auto-instrumentation)) |
| `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`, `OTEL_EXPORTER_OTLP_ENDPOINT` | | where the spans go (`/v1/traces` is appended to the second); tracing stays off without one |
| `OTEL_TRACES_SAMPLER_ARG` | `1` | sampling ratio: a sampled incoming `traceparent` is always followed, otherwise the trace id ratio decides (`OTEL_TRACES_SAMPLER=always_on` / `always_off`: 1 / 0) |
| `OTEL_SDK_DISABLED`, `OTEL_TRACES_EXPORTER=none` | | switch the wrapper's spans off |
| `RUNR_REPLAY_URL`, `RUNR_REPLAY_TOKEN` | | failed requests (5xx, timeouts) are posted there ([request replay](observability.md#request-replay)) |
| `RUNR_REPLAY_MAX_BODY` | `65536` | request / response body bytes kept per capture |
| `RUNR_REPLAY_PER_MINUTE` | `60` | captures per minute and pod |
| `RUNR_REPLAY_REDACT_HEADERS`, `RUNR_REPLAY_REDACT_QUERY` | | comma-separated names on top of the built-in lists (never captured / value replaced by `REDACTED`) |
| `RUNR_UPSTREAM_PORT` | `$PORT + 1` | observed http mode: the port the runtime's own server listens on |

In `http` mode with the http or queue trigger the wrapper replaces itself with `RUNR_COMMAND`
(exec), so it adds nothing on that path; with the schedule trigger it runs the
[invoke](#http-mode) sequence.

When tracing or replay capture is on, `http` mode runs as an observed proxy instead: the wrapper
starts `RUNR_COMMAND` with `PORT=$RUNR_UPSTREAM_PORT`, listens on `$PORT` itself and forwards
every request, so each one gets the wrapper's server span (the runtime's own instrumentation
continues the trace from the `traceparent` the proxy sends) and failed ones are captured. RunR
fronts built-in http runtimes this way only for projects that switched tracing or replay on.
With tracing on, every invocation logs an access line with its trace id:

```text
[runr-wrapper] trace_id=4bf92f3577b34da6a3ce929d0e0e4736 span_id=00f067aa0ba902b7 sampled=true GET /orders 500 41.3ms cold_start=false
```

Subcommands: `runr-wrapper` or `runr-wrapper serve` runs as configured,
`runr-wrapper install <dest>` copies the binary to `<dest>` (what the init container and the
build Job do; it works in the scratch image), `runr-wrapper tick` waits until a server listens on
`127.0.0.1:$PORT` (or `RUNR_TICK_URL`), sends it one schedule CloudEvent, prints the response and
exits 0 for a status below 400 (the schedule trigger of [WebAssembly functions](wasm.md#triggers)),
`runr-wrapper version` prints the version.

## Manifest reference

```yaml
apiVersion: runr.sh/v1
kind: Runtime
id: c
name: C
extensions: [c, h]
versions:
  - version: "17"
    image: ghcr.io/brf-tech/runr/reqs/alpine:3.21
    default: true
build:
  command: "apk add --no-cache gcc musl-dev >/dev/null && gcc -O2 -static -o /build/out/handler {file}"
  runImage: ghcr.io/brf-tech/runr/reqs/alpine:3.21
  source: false
run:
  mode: exec
  input: raw
  command: ["/app/handler"]
```

Unknown fields are rejected everywhere in the manifest.

### Top-level fields

| Field | Required | Default | Meaning |
|---|---|---|---|
| `apiVersion` | yes | | `runr.sh/v1` |
| `kind` | yes | | `Runtime` |
| `id` | yes | | `^[a-z][a-z0-9-]{0,31}$` (single letters such as `c` or `q` are valid); stored on projects. `docker`, `caas`, `custom`, `runtime`, `runtimes` and `validate` are reserved |
| `name` | yes | | display name, 1–64 characters |
| `description` | | | up to 500 characters, shown in the runtime picker |
| `icon` | | the built-in icon for built-in ids, else a generic code icon | `data:image/svg+xml;base64,…` (also `png`, `jpeg`, `webp`) or an `https://` URL, up to 64 KiB |
| `language` | | `plaintext` | Monaco editor language id (`python`, `c`, `rust`, `shell`, …) for files with the runtime's extensions |
| `extensions` | yes | | 1–10 distinct file extensions without the dot (`^[a-z0-9]{1,10}$`); the first is the handler file's |
| `category` | | `general` | `general` or `ml` |
| `versions` | yes¹ | | see [versions](#versions) |
| `versionDiscovery` | | | built-in catalog only, see [versions](#versions) |
| `handler` | | | see [handler and template](#handler-and-template) |
| `template` | | `{"{file}": ""}` | starter code, see [handler and template](#handler-and-template) |
| `dependencies` | | | see [Dependencies](#dependencies) |
| `build` | | | see [Compiled languages](#compiled-languages) |
| `run` | yes | | see [run](#run) |
| `triggers` | | `[http, schedule, queue]` | the triggers projects of this runtime may use; creating a project, changing its trigger and deploying check it |
| `sandbox` | | `[runc, gvisor, kata]` | the sandboxes the runtime works under, see [Sandboxing](#sandboxing-gpus-and-runtimeclasses) |
| `resources` | | | defaults for new projects, see [resources](#resources) |
| `runtimeClass` | | | built-in and platform runtimes only, see [RuntimeClasses](#sandboxing-gpus-and-runtimeclasses) |
| `wagi` | | | runtimes on the Spin shim (requires `runtimeClass: wasmtime-spin-v2`): `argv` (default `${SCRIPT_NAME} ${ARGS}`), `files` (map the project's files into the module, default `false`), `environment` (extra static variables) — RunR generates a `spin.toml` with Spin's WAGI executor for the module the build produces ([wasm-exec](wasm.md#wasm-exec)) |

¹ unless `versionDiscovery` is set.

### versions

```yaml
versions:
  - version: "1.0"                       # ^[0-9A-Za-z][0-9A-Za-z._-]{0,31}$, quoted
    image: ghcr.io/acme/q:1.0            # exactly one of image | dockerfile | git | upload
    default: true
    eol: "2027-06-30"
    gpu: false
  - version: "2.0"
    dockerfile: Dockerfile               # a file uploaded with the runtime
    buildArgs: {Q_VERSION: "2.0"}
  - version: "3.0"
    git: {url: "https://github.com/acme/q.git", ref: main, path: runtime/, dockerfile: Dockerfile}
  - version: "4.0"
    upload: q:4.0                        # an uploaded image (docs/images.md)
```

| Field | Meaning |
|---|---|
| `version` | version label shown in the picker and stored on projects (`runtime_version`); a string, so quote numbers (`"1.0"`); unique within the runtime |
| `image` | an existing image that runs the code |
| `dockerfile` | RunR builds the image in each region from this uploaded file, with all uploaded files as the build context; the file must be among the uploaded files |
| `git` | RunR builds the image in each region from a Git repository: `url` (`https://`), `ref` (a branch name, or a full ref such as `refs/tags/v1.0`; default the repository's default branch), `path` (context directory inside the repository), `dockerfile` (path relative to the context, default `Dockerfile`) |
| `upload` | an [uploaded image](images.md) of the runtime's owner: `repository[:tag]`, `repository@sha256:<digest>` or the upload id. Resolved in each region to that region's upload of the name, pinned by digest — so an air-gapped region runs the runtime from an image that came through RunR. Platform runtimes name platform uploads, custom runtimes their organization's. Registering or changing the runtime requires the image in every region the runtime is offered in (active regions with a registry; a platform runtime offered in every region is checked in the platform's regions, not in an organization's own regions); a deploy or build in a region without it is refused naming the region. A WebAssembly runtime with `upload` versions sets `build.image` (its toolchain): the uploaded module reaches the build as `RUNR_WASM_MODULE` ([wasm.md](wasm.md#wasm-exec)) |
| `buildArgs` | `--build-arg` values, `dockerfile` and `git` versions only |
| `default` | the version new projects get; at most one per runtime, the last version when none is marked |
| `eol` | end-of-life date (`YYYY-MM-DD`): the version past it leaves the picker, its projects are flagged in the UI, and saving or deploying them asks for an upgrade |
| `gpu` | the version needs a GPU node (with [`resources.gpu`](#resources)) |

Up to 50 versions. Removing a version from the manifest retires it: projects pinned to it run on
the default version, as with any retired version.

`versionDiscovery` (built-in catalog) replaces a static list with the images RunR publishes:
`executor:sync` lists the clean `X.Y[.Z]` tags of the image `repository`
(`ghcr.io/brf-tech/runr/exec-python`) in its own registry, maps each to `image`
(`ghcr.io/brf-tech/runr/exec-python:{version}`), marks the newest as default, deactivates tags that
left the repository, reads end-of-life dates for the `eol` product from
[endoflife.date](https://endoflife.date) and, with `filter: lts-even-major`, keeps the newest
minor of each even major (Node.js LTS lines). A `X.Y-gpu` tag next to a listed version is that
version's GPU build: a version of its own (`2.14-gpu`, `gpu_required`), listed after it and never
the default ([ML runtimes](#ml-runtimes)). The tags come from the registry's OCI distribution API
(`GET /v2/<name>/tags/list`, every page of its `Link` header), read anonymously with a pull token
from the registry's token service; a `repository` without a registry host is on Docker Hub
(`registry-1.docker.io`). A runtime whose registry does not answer keeps its versions, and a
partial tag list deactivates none.

### handler and template

```yaml
handler:
  file: main                     # entry file base name → main.<extensions[0]>
  function: handler              # function name for languages with function handlers
  pattern: 'def\s+{handler}\s*\('    # checked on every save of the entry file
template:
  files:
    "{file}": |
      def {handler}(event, context):
          return {"statusCode": 200, "body": "Hello, World!"}
```

| Field | Default | Meaning |
|---|---|---|
| `handler.file` | `main` | base name of the entry file (`^[A-Za-z0-9_-]{1,64}$`); XaaS projects store `<file>.<extension>` as their file name, FaaS handlers are written `<file>.<function>` (`main.handler`) — a FaaS handler without a dot names only the function and keeps the default file |
| `handler.function` | `handler` | the default handler name (`RUNR_HANDLER`), `^[A-Za-z_][A-Za-z0-9_]{0,63}$` |
| `handler.pattern` | | a regular expression (up to 500 characters) the entry file must match on save; `{handler}` stands for the escaped handler name. A mismatch rejects the save with a clear message; without a pattern any content is accepted |
| `template.files` | `{"{file}": ""}` | the code a new project starts with, 1–20 files; the key `{file}` is the entry file. XaaS projects get the entry file, FaaS projects every template file plus the dependency template |

### run

| Field | Default | Meaning |
|---|---|---|
| `run.mode` | | `http`, `exec` or `persistent` ([run modes](#run-modes)) |
| `run.command` | image entrypoint (http) | the command as an argument list (1–32 items); required for `exec` and `persistent`, and for `http` with `schedule: invoke` when `schedule` is among the triggers |
| `run.schedule` | `invoke` | `http` mode only: `invoke` (the wrapper starts the server and sends one event) or `native` (the image handles `RUNR_MODE=schedule`) |
| `run.input` | `json` | `exec` mode only: stdin is the request envelope (`json`) or the request body (`raw`) |
| `run.workers` | `1` | `persistent` mode only: number of long-lived processes, 1–16 |
| `run.env` | | extra environment variables of the function container (up to 50) |

### resources

```yaml
resources:
  cpu: "500m"          # default CPU of new projects (request == limit), "250m" or "0.5"
  memory_mb: 512       # default memory of new projects, 64–65536
  gpu:
    count: 1           # 1–8
    resource: nvidia.com/gpu
```

`cpu` and `memory_mb` replace the project type's default size for new projects of this runtime;
an explicit size on the project wins, and the region capacity check uses the resulting size.
`gpu` sets a limit of `count` units of the extended resource `resource` (default
`nvidia.com/gpu`) on the function container, so the scheduler places the pods on nodes that
offer it.

### Placeholders

Manifest strings may use these placeholders:

| Placeholder | Value |
|---|---|
| `{version}` | the runtime version the project runs on (`3.14`) |
| `{semver}` | the version when it is numeric `X.Y` or `X.Y.Z`, else empty |
| `{image}` | the version's image (build stage strings) |
| `{file}` | the entry file with extension (`main.c`) |
| `{handler}` / `{Handler}` | the handler function name / with an upper-case first letter (Go) |
| `{mode}` | `http` or `schedule` |
| `{trigger}` | `http`, `schedule` or `queue` |

Where they apply:

- `template.files` (names and contents): `{file}`, `{handler}`, `{Handler}` — rendered once
  when the starter code is created.
- `dependencies.image`, `dependencies.install`, `dependencies.env`, `build.image`,
  `build.command`, `build.runImage` and `run.env` of a build: every placeholder. `FROM` lines then
  go through the region's registry (pull-through cache).
- `run.command` and `run.env` at deploy: every placeholder except `{image}`.
- `versionDiscovery.image`: `{version}`.

`{if:NAME}…{end}` keeps its content only when the placeholder `NAME` is non-empty. The PHP
runtime pins Composer's platform to numeric versions only:

```yaml
install: "cd /build/code && {if:semver}composer config platform.php {semver} && {end}composer install --no-dev --no-interaction"
```

Only the names above are replaced; anything else in braces — `${HOME}`, `{"statusCode": 200}`,
`format!("{}", x)` — stays as written. `dependencies.template` is used as written.

## Validate a manifest locally

The same checks `POST /api/runtimes` runs (JSON Schema and rules) are available in the API
container:

```bash
php artisan runtimes:validate path/to/runtime.yaml [more/runtime.yaml …] [--files=dir]
```

Files in `--files` count as the files uploaded with the runtime, so a `dockerfile` version is
checked against them:

```bash
php artisan runtimes:validate ../examples/runtimes/q/runtime.yaml --files=../examples/runtimes/q/interpreter
# ../examples/runtimes/q/runtime.yaml: valid (q, persistent mode, 1 version(s))
```

Every problem is printed with its JSON path; the exit code is 1 when a manifest is invalid. The
schema itself (`api/resources/runtimes/runtime.schema.json`) also works in editors: with the
YAML language server, a `# yaml-language-server: $schema=<path to runtime.schema.json>` line at
the top of `runtime.yaml` gives completion and inline errors.

## Version sources and runtime image builds

A version with `image` runs that image (through the region registry's mirror when the region has
one). A version with `dockerfile` or `git` is built by RunR in **each region** it is used in: a
Kaniko Job (`rt-<id>-<version>-<digest>`, up to 15 minutes) in the region's project namespace
pushes `runr-runtimes/<id>:<version>-<digest>` to the region's registry. The digest covers the
version source, the build args and — for `dockerfile` versions — the uploaded files, so a changed
Dockerfile builds a new image and an unchanged one is reused: the image is cached in the region
registry for every function of that runtime. Git sources are cloned by Kaniko
(`git://<host>/<path>#<ref>`, `path` as the context sub-directory). Deleting the runtime deletes
its images (`runr-runtimes/<id>`, every version and earlier source) from the registries of the
regions it was built in.

Builds start when a runtime is registered, changed or re-enabled (in every active region with a
registry it is available in), from **Settings → Runtimes → Builds** or
`POST /api/runtimes/{id}/builds` (which rebuilds), and when a deploy or function build needs the
image in a region that has none for the current source:

- a **function build** (compiled runtime, dependency build) waits for the runtime image — it
  checks every 10 s for up to 15 minutes — and then runs;
- a **deploy** of a code-mounted function answers that the runtime image is being built in the
  region and is deployed again once the build is ready.

Build status (`pending`, `building`, `ready`, `failed`) and the Kaniko error of a failed build
are listed per region and version (`GET /api/runtimes/{id}/builds`).

A version with `upload` runs the uploaded image (`<registry>/runr-uploads/<owner>/<repository>@sha256:…`)
like an `image` version: as the image of code-mounted functions and as the `FROM` of function
builds — nothing is built for it. Settings → Runtimes → **Version from an uploaded image** adds such
a version to the manifest.

Uploaded files are part of the runtime: up to 50 files with ConfigMap-key names (letters,
digits, `.`, `-`, `_`; no folders), at most 900 KB together, since they reach the build as a
Kubernetes ConfigMap. Use `git` for larger build contexts. A region needs a container registry
for runtime image builds and function builds alike.

## Compiled languages

A `build` section makes every saved version a build — the same pipeline Go uses:

```yaml
build:
  image: "{image}"                      # build image (default: the version image)
  command: "gcc -O2 -static -o /build/out/handler {file}"
  runImage: ghcr.io/brf-tech/runr/reqs/alpine:3.21   # final image (default: the version image)
  source: false                         # copy the code into /app/code of the final image (default true)
  files:                                # extra files placed next to the code before the build
    runr_wrapper_.go: {http: "…", schedule: "…"}
```

RunR generates a two-stage Dockerfile and runs it with Kaniko in the project's region
(`activeDeadlineSeconds`: `BUILD_DEADLINE_SECONDS`, 900 by default):

1. `FROM <build.image> AS build`: the project's files plus `build.files` in `/build/code` — a
   `{http, schedule}` pair picks the variant for the project's trigger (queue uses `http`) — and
   one `RUN` with `mkdir -p /build/out`, the dependency install (when the project contains the
   dependency file) and `build.command`, with the placeholders resolved. The command runs in
   `/build/code`; everything it writes to `/build/out` is the build output.
2. `FROM <build.runImage>`: `/build/out` copied to `/app`, the code to `/app/code` unless
   `source: false`, the `dependencies.copy` paths, the wrapper at `/runr/runr-wrapper` for
   runtimes that use it (`exec`, `persistent`, or `http` with `schedule: invoke`), the
   environment (`RUNR_MODE`, `RUNR_HANDLER`, `RUNR_FILENAME`, `dependencies.env`, `run.env` and
   the wrapper variables), `EXPOSE 8080` and the entrypoint: `run.command` in http mode, the
   wrapper in exec and persistent mode.

The base images come through the region registry's pull-through cache (Zot), which fetches an
image it does not hold yet from its upstream before it answers — for a large image that took
4.5 to 5 minutes on a test region. `region:reconcile` (run after every install and upgrade) has
the registry of each region fetch the base images of the release's function builds ahead of the
first build: the default version of every built-in and platform runtime that builds, through a
Job `runr-registry-prewarm-<hash>` in the registry namespace that logs how long each image took.
A build that still runs past its deadline while Kaniko waits for its base image (Kaniko's last log
line `Retrieving image …`) is built once more; meanwhile its `build_error` says so. If the second
build runs out of time too, the version fails.

The image is pushed to the region registry as `runr-builds/<project>:v<N>` and old build images
are trimmed by the registry retention. A build that fails leaves nothing in the registry (Kaniko
runs with `--skip-push-permission-check`, so it contacts the registry only to push the finished
image). Once a day, build repositories without a tag (`runr-builds/…`, `runr-runtimes/…`) are
handed to Zot's garbage collection, which removes them after its delay (one hour by default). Build errors appear on the version. Kaniko builds run
under the region's build sandbox (`sandbox_build_runtime`). A manifest change applies to the
versions built after it.

## Dependencies

```yaml
dependencies:
  file: requirements.txt
  template: "redis>=5.0\n"          # starter content of new FaaS projects
  image: "{image}"                  # install image (default: the version image)
  install: "pip install --no-cache-dir --target=/build/vendor -r /build/code/requirements.txt"
  copy:
    - {from: /build/vendor, to: /app/vendor}
  env:
    PYTHONPATH: /app/vendor
```

A FaaS project that contains `dependencies.file` is built: `install` runs in `image` with the
project's files in `/build/code`, each `copy` entry (up to 10, absolute paths) is copied from the
build stage into the final image (the version image), and `env` is set on it. Projects without
the file deploy without a build. Runtimes with a `build` section run the install in the build
image, before `build.command`. `file` must differ from the handler file; `file` and `install` are
required.

## Sandboxing, GPUs and RuntimeClasses

Function pods of every runtime — built-in, platform or custom — run with the sandbox runtime
(RuntimeClass) the region maps the project's type to (Settings → Regions → (region) → Isolation: runc, gVisor
or a Kata hypervisor), and Kaniko builds with the region's build sandbox. A runtime cannot lower
that isolation: the manifest's `sandbox` list only declares which sandboxes the runtime works
under (default all three; every Kata hypervisor counts as `kata`). A deploy into a region whose
sandbox for the project type is not in the list is refused with a message naming the runtime and
the sandbox, instead of running the code under a different isolation. The wrapper's init
container runs in the same pod, under the same RuntimeClass.

`resources.gpu` requests GPUs as a container limit, see [resources](#resources).

`runtimeClass` names a Kubernetes RuntimeClass the function pods run with **instead of** the
region's sandbox mapping for the project type — for example a WebAssembly shim such as
`wasmtime` or `spin` installed on the nodes. Only built-in and platform-registered runtimes may
set it; a custom (organization) runtime manifest with `runtimeClass` is rejected, so members
never choose their own isolation. RunR's own WebAssembly runtimes (`wasm-rust`, `wasm-go`,
`wasm-js`, `wasm-python`, `wasm-exec`) work this way on the Spin shim RunR installs per region
(RuntimeClass `wasmtime-spin-v2`) — see [wasm.md](wasm.md). A runtime whose `runtimeClass` is
one of the WebAssembly RuntimeClasses (`wasmtime-spin-v2`, `wasmtime-v1`) is offered only in
regions whose WebAssembly support is ready with that shim.

## Permissions

| Ability | Grants |
|---|---|
| `runtimes.manage` | register platform runtimes; edit, enable / disable, delete any registered runtime; enable / disable and set regions of built-in runtimes; list disabled runtimes; trigger runtime image builds and test deploys of any runtime; switch custom runtimes on or off for the platform |
| `runtimes.use_custom` | register custom runtimes for the own organization and edit, enable / disable, delete, build and test-deploy them; create projects on custom runtimes |

Both are permissions of the role model ([Roles and permissions](permissions.md)).
`runtimes.manage` exists in the single-tenant edition and belongs to its Owner and Admin roles,
so runtimes registered there are platform runtimes; in a multi-tenant installation platform
runtimes are shared by every tenant and only platform administrators manage them.
`runtimes.use_custom` belongs to the Owner, Admin and Developer roles (and custom roles that
include it) and takes effect while the platform setting **Custom Runtimes** (Settings → Platform,
`custom_runtimes_enabled`, off by default) is on; holders of `runtimes.manage` always have it.
Creating a project on a built-in or platform runtime needs no permission beyond
`projects.create`.

## API

All endpoints are under `/api`, authenticated with a session or an API token.

| Method & path | Ability | Purpose |
|---|---|---|
| `GET /runtimes` | | catalog: `{data: Runtime[], abilities: {manage, use_custom}, custom_enabled}` — the enabled runtimes the current organization can use; `?region=<id>` filters by availability, `?all=1` (manage or use_custom) adds disabled runtimes (all of them for manage, the custom ones for use_custom) |
| `GET /runtimes/{id}` | | one runtime with `manifest` (normalized, every default filled in), `manifest_source` (as submitted; built-ins rendered as YAML) and `files` (`{name: content}`); disabled runtimes for manage / use_custom only |
| `GET /runtimes/{id}/template` | | starter files: `?service_type=xaas\|faas&handler=main.handler` → `{files: {name: content}}` |
| `POST /runtimes/validate` | manage or use_custom | `{manifest, files?}` → `{valid: true, manifest}` or 422 |
| `POST /runtimes` | manage or use_custom | `{manifest, files?, regions?}` → 201 `{data}`; holders of manage register platform runtimes, use_custom-only members custom runtimes of their organization |
| `PUT /runtimes/{id}` | manage, or use_custom for a custom runtime of the own organization | `{manifest?, files?, regions?, is_enabled?}` → `{data}`; the id cannot change; built-in runtimes accept `regions` and `is_enabled` only |
| `DELETE /runtimes/{id}` | as PUT | 204; 409 while projects use the runtime; 403 for built-in runtimes |
| `GET /runtimes/{id}/builds` | | runtime image builds per region and version: `{data: [{version, knative_region_id, region_name, status, image, error, updated_at}]}` |
| `POST /runtimes/{id}/builds` | as PUT | `{knative_region_id, version?}` → 202 with the build list; (re)builds the runtime image of every `dockerfile` / `git` version, or of `version`, in the region; 422 when the runtime has none |
| `POST /runtimes/{id}/test-deploy` | as PUT | `{knative_region_id, version?}` → 201 `{data: Project}`: an XaaS project `rt-<id>-<random>` with the HTTP trigger and the starter template, built when needed and deployed as soon as its version is ready |
| `GET /settings`, `PATCH /settings` | manage for `custom_runtimes_enabled` | `custom_runtimes_enabled` (bool) |

Request limits: `manifest` (YAML or JSON text) up to 256 KiB; `files` as described in
[Version sources](#version-sources-and-runtime-image-builds); `regions` a list of region ids or
`null` for every region.

An invalid manifest answers 422:

```json
{
  "message": "The runtime manifest is not valid.",
  "errors": {"manifest": ["/run/command: is required in exec mode"]},
  "problems": [{"path": "/run/command", "message": "is required in exec mode"}]
}
```

A runtime in the catalog:

```json
{
  "id": "q", "name": "Q", "description": "…", "icon": "data:image/svg+xml;base64,…",
  "language": "plaintext", "extensions": ["q"], "category": "general",
  "source": "custom", "run_mode": "persistent", "compiled": false,
  "triggers": ["http", "schedule", "queue"], "sandbox": ["runc", "gvisor", "kata"],
  "runtime_class": null, "wasm": null, "gpu": null,
  "handler": {"file": "main", "function": "handler", "filename": "main.q", "pattern": null},
  "dependency_file": null,
  "versions": [{"version": "1.0", "is_default": true, "eol_at": null, "gpu_required": false, "image": null, "upload": null, "built": true}],
  "regions": null, "is_enabled": true, "tenant_id": null, "created_by": "1",
  "projects_count": 3, "updated_at": "2026-10-10T12:00:00Z"
}
```

`wasm` names the WebAssembly shim a runtime needs in a region (`"spin"`), `null` for every other
runtime; a version's `upload` is its uploaded-image reference and `built` is true for `dockerfile`
and `git` versions, which RunR builds per region.

Projects reference runtimes as before: `POST /api/projects` with `"runtime": "q"` and an
optional `"runtime_version": "1.0"`. The request is refused when the runtime is unknown,
disabled, not offered in the project's region, does not support the trigger, or is a custom
runtime and the user lacks `runtimes.use_custom`.

## CLI

`runr runtimes` works with the catalog ([CLI reference](cli.md#runtimes-and-runtime-plugins)):

```bash
runr runtimes ls [--all] [--region eu-1]       # versions, kind (built-in, plugin, wasm), run mode, image
runr runtimes get q [--manifest]
runr runtimes validate -f runtime.yaml [--files interpreter/]
runr runtimes register -f runtime.yaml [--files interpreter/] [--region eu-1] [--wait]
runr runtimes update q -f runtime.yaml --files interpreter/   # or --enable / --disable / --region / --all-regions
runr runtimes template q --type faas --out ./qfn
runr runtimes builds q
runr runtimes build q --region eu-1 [--version 1.0] [--wait]
runr runtimes test-deploy q --region eu-1
runr runtimes rm q --yes
```

`runr dev` runs a project of a runtime plugin locally in Docker the way its manifest tells the
cluster to: the version's image (`dockerfile` and `git` versions built locally with
`docker build`), the universal wrapper as entrypoint for `exec` and `persistent` runtimes, built
functions from the same generated Dockerfile, and `runr dev invoke` for queue and schedule events
([runtime plugins in runr dev](cli.md#runtime-plugins-in-runr-dev)). WebAssembly runtimes run on
a region's shim only: `runr dev` names the shim and points to `runr deploy`.

## Console

- **New project**: the runtime picker comes from the catalog — icon, name, versions — filtered
  by the chosen region; runtimes that do not support the chosen trigger are shown greyed out. A
  collapsible preview shows the starter template. The editor opens with the manifest's starter
  template, checks the entry file against `handler.pattern`, and highlights files with the
  runtime's extensions in the manifest's Monaco `language`.
- **Settings → Runtimes** (holders of `runtimes.manage` or `runtimes.use_custom`): every runtime
  with its icon, source, run mode, versions, regions, project count and an enabled toggle;
  **Add runtime** with a manifest editor that validates as you type and lists each problem with
  its path, file uploads for Dockerfile builds, and region availability; **Edit**, **View
  manifest**, **Builds** per region (with rebuild), **Delete**, and **Test deploy**, which
  creates a project from the starter template in a region, deploys it and opens it.
- **Settings → Platform → Custom Runtimes** (holders of `runtimes.manage`) switches
  `runtimes.use_custom` on for members.

## Write your own runtime

The goal: run Bash scripts as functions. No image to build — the official `bash` image has
everything — and no wrapper code to write.

**1. Write the handler** (`main.sh`). In exec mode with raw input, RunR runs it once per
invocation: the body on stdin, metadata in `RUNR_*` variables, stdout becomes the response.

```bash
#!/usr/bin/env bash
set -euo pipefail
case "${RUNR_TRIGGER:-http}" in
  schedule) echo "tick from bash" ;;
  queue)    printf 'processed %s (from bash)' "$(cat)" ;;
  *)        printf 'Hello from bash! You called %s %s' "$RUNR_METHOD" "$RUNR_PATH" ;;
esac
```

**2. Write the manifest** (`runtime.yaml`):

```yaml
apiVersion: runr.sh/v1
kind: Runtime
id: bash
name: Bash
language: shell
extensions: [sh, bash]
versions:
  - version: "5.2"
    image: docker.io/library/bash:5.2
    default: true
template:
  files:
    "{file}": |
      #!/usr/bin/env bash
      printf 'Hello from bash!'
run:
  mode: exec
  input: raw
  command: ["bash", "/app/code/{file}"]
```

**3. Validate and register it** (Settings → Runtimes → Add runtime,
`runr runtimes validate -f runtime.yaml` and `runr runtimes register -f runtime.yaml`, or):

```bash
jq -n --rawfile manifest runtime.yaml '{manifest: $manifest}' > body.json
curl -sS -X POST "$API/runtimes/validate" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' -d @body.json
curl -sS -X POST "$API/runtimes" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' -d @body.json
```

**4. Use it.** Create a project, pick **Bash**, paste `main.sh` into the editor, save and
deploy. `curl https://<project>.<region>.<domain>/hello` answers
`Hello from bash! You called GET /hello`. Switch the trigger to *schedule* and the CronJob logs
`tick from bash` on every run; switch it to *queue*, `XADD` an entry to the stream, and the
entry comes back as `processed {"msg":"hi"} (from bash)` — the same script serves all three
triggers because the wrapper speaks the same contract for each.

From here, `examples/runtimes/` shows the other shapes: a compiled language (C), a binary that
is its own HTTP server (Rust), and a language with its own interpreter image (Q).

## Examples

| Example | Shape |
|---|---|
| [`examples/runtimes/c`](../examples/runtimes/c) | compiled with gcc in a `build` step (`/build/out/handler` → `/app/handler`), `exec` mode with raw input |
| [`examples/runtimes/rust`](../examples/runtimes/rust) | compiled with rustc, `http` mode — the binary serves `$PORT` itself; schedule ticks reach it through the wrapper (`schedule: invoke`) |
| [`examples/runtimes/bash`](../examples/runtimes/bash) | interpreted, `exec` mode, the official bash image |
| [`examples/runtimes/q`](../examples/runtimes/q) | Q, a toy language with an interpreter written in Go; the runtime image is built per region from the uploaded Dockerfile, `persistent` mode |
| [`examples/wasm/q-exec`](../examples/wasm/q-exec) | Q on WebAssembly: the same interpreter compiled with TinyGo, an `upload:` version on the Spin shim with a `wagi` section |

The Q handler that answers all three triggers is five lines:

```q
# main.q: hello world in Q, for every trigger
set name = query.name or "World"
if trigger == "schedule" then return "tick from Q"
if trigger == "queue" then return "processed {data.msg} (from Q)"
print "Hello, {name}! (from Q)"
```

The MCP server runtimes `mcp-python` and `mcp-node` ([`examples/mcp`](../examples/mcp)) are
`http`-mode runtimes whose starter template is a Model Context Protocol server; the console
registers them for hosted MCP servers ([AI agents](mcp.md#templates)).

[`examples/runtimes/README.md`](../examples/runtimes/README.md) shows how to register each one;
[`examples/runtimes/q/README.md`](../examples/runtimes/q/README.md) is the Q language reference.

## Built-in catalog

The built-in runtimes are manifests in `runtime/<id>/runtime.yaml`, next to each image's
Dockerfile and language wrapper; `runtime/ci.sh build` builds the python, nodejs, go and php
images, skipping versions past their end of life, `runtime/ci.sh ml-runtimes` the PyTorch and
TensorFlow images ([ML runtimes](#ml-runtimes)) and `runtime/ci.sh wasm` the build images of
the WebAssembly runtimes. Built-in manifests may use `versionDiscovery`
and `@path` references in `build.files` (a file next to the manifest, inlined when the catalog is
bundled).

The API image does not contain `runtime/`, so the catalog also ships as
`api/resources/runtimes/builtin.json`. The API reads `runtime/` directly when the checkout has it
(`../runtime` from `api/`, which docker compose mounts, or `RUNR_RUNTIME_CATALOG`), else the
bundle. After changing a built-in manifest, regenerate the bundle:

```bash
php artisan runtimes:bundle          # writes api/resources/runtimes/builtin.json
php artisan runtimes:bundle --check  # CI: exit 1 when the bundle differs from runtime/*/runtime.yaml
```

| Runtime | Mode | Notes |
|---|---|---|
| `python`, `nodejs`, `php` | `http`, `schedule: native` | the image's own server (`server.py`, `server.js`, `server.php`) loads `RUNR_FILENAME` from `/app/code` and calls `RUNR_HANDLER(event, context)`; `requirements.txt`, `package.json`, `composer.json` trigger a dependency build in FaaS projects (Composer in the composer image, pinned to the runtime's PHP version) |
| `pytorch`, `tensorflow` | `http`, `schedule: native` | Python 3.12 images with the ML stack preinstalled (`category: ml`), the same handler server as `python`; a CPU and a `-gpu` version each ([ML runtimes](#ml-runtimes)) |
| `go` | `http`, `schedule: native`, `build` | the project's `.go` files are compiled together with RunR's Go wrapper for the trigger (`runtime/go/wrapper_http.go` / `wrapper_schedule.go` as `build.files`) into a static binary, `/app/handler` on `ghcr.io/brf-tech/runr/reqs/alpine:3.20` |
| `wasm-rust`, `wasm-go`, `wasm-js`, `wasm-python` | `http`, `schedule: native`, `build`, `runtimeClass: wasmtime-spin-v2` | WebAssembly: built into a Spin OCI artifact and run on the Spin shim; offered in regions with WebAssembly support — [wasm.md](wasm.md) |
| `wasm-exec` | `http`, `schedule: native`, `build`, `runtimeClass: wasmtime-spin-v2`, `wagi` | any WASI preview 1 command module — C / C++ (wasi-sdk), Zig, Go (TinyGo), a `build.sh`, an uploaded `.wasm` — on Spin's WAGI executor (CGI on stdin / stdout) — [wasm.md](wasm.md#wasm-exec) |

Built-in versions come from `executor:sync` (at API start and on its schedule): it creates the
built-in rows, discovers versions through `versionDiscovery` and syncs the versions of platform
and custom runtimes from their manifests. `php artisan runtimes:sync [--builds]` does the latter
two on demand, with `--builds` also starting region builds of `dockerfile` / `git` versions.

### ML runtimes

The PyTorch and TensorFlow runtimes are Python 3.12 with the framework and the ML essentials
preinstalled — NumPy, SciPy, pandas, Matplotlib, scikit-learn, Pillow, and Keras with TensorFlow —
and run handlers exactly like `python` (`def handler(event, context)`, its `server.py` and
`runner.py`). A FaaS project's `requirements.txt` is installed on top in a dependency build that
runs in the version's own image.

| Version | Image | Framework | Unpacked |
|---|---|---|---|
| `pytorch` `2.14` (default) | `ghcr.io/brf-tech/runr/exec-pytorch:2.14` | torch 2.14.1 and torchvision 0.29.1, CPU wheels | 1.5 GB |
| `pytorch` `2.14-gpu` | `ghcr.io/brf-tech/runr/exec-pytorch:2.14-gpu` | the same torch and torchvision built for CUDA 12.6 (PyTorch's `cu126` wheels), the torch hf-serve runs | 7.0 GB |
| `tensorflow` `2.21` (default) | `ghcr.io/brf-tech/runr/exec-tensorflow:2.21` | TensorFlow 2.21.0, Google's CPU build (`tensorflow-cpu`), Keras 3.15 | 2.0 GB |
| `tensorflow` `2.21-gpu` | `ghcr.io/brf-tech/runr/exec-tensorflow:2.21-gpu` | TensorFlow 2.21.0 with the CUDA 12 libraries of its `and-cuda` extra, Keras 3.15 | 6.9 GB |

The `-gpu` versions carry the CUDA libraries as NVIDIA's pip packages and need a GPU node whose
driver supports CUDA 12. A project on a `-gpu` version is a GPU project
([GPU runtime versions](ai.md#gpu-runtime-versions-xaas--faas)).
The images are built for `linux/amd64`. Being this large (the unpacked size of each image's
filesystem above), they are left out of the node pre-pull (below) and pulled by the nodes their
functions run on.

`runtime/ci.sh ml-runtimes` builds them from `runtime/pytorch` and `runtime/tensorflow`
(`Dockerfile`, `Dockerfile.gpu`; the handler server comes from `runtime/python`). A version is
the major.minor of the framework pin (`torch==2.14.1` in `requirements-torch.txt`,
`tensorflow-cpu==2.21.0` in `requirements-cpu.txt`); `executor:sync` picks up the published tags
through `versionDiscovery`. Each build pulls its base image, installs the pinned packages, and
fails unless `pip check` passes, the frameworks import and the version (and, for `-gpu`, the CUDA
build) matches. A local build also runs a smoke test: a handler doing a tensor op answers an HTTP
request with JSON through the image's server, and once more as a scheduled run.

```bash
runtime/ci.sh ml-runtimes --list            # the image references
runtime/ci.sh ml-runtimes pytorch cpu       # one image, built and smoke-tested locally
runtime/ci.sh ml-runtimes --push            # build and push all four (linux/amd64)
```

**Upgrading.** A migration adds the `runtimes` and `runtime_builds` tables with a built-in row
each for `python`, `nodejs`, `go`, `php`, `tensorflow` and `pytorch`; `executor:sync` adds the
rows of the other built-ins (the WebAssembly runtimes) and their versions. Existing projects keep
their `runtime` / `runtime_version` values and run on the same images: the built-in manifests
describe exactly what these runtimes did before they became plugins. Custom runtimes stay off
until a holder of `runtimes.manage` switches **Custom Runtimes** on.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `EXECUTOR_RUNTIMES` | `python,nodejs,go,php,tensorflow,pytorch,wasm-rust,wasm-go,wasm-js,wasm-python,wasm-exec` | built-in runtimes offered on the platform; projects of a built-in left out keep deploying (the Wasm runtimes appear only in regions with WebAssembly support) |
| `RUNR_RUNTIME_CATALOG` | `../runtime` when present | directory of the built-in catalog sources |
| `RUNR_WRAPPER_IMAGE` | `ghcr.io/brf-tech/runr/wrapper:1.1.0` | the universal wrapper image for init containers and build Jobs |
| `BUILD_DEADLINE_SECONDS` | `900` | deadline of a function build Job (Kaniko and WebAssembly toolchain builds; at least 60); the build checks give up a minute later, and a version still building two minutes after the deadline is marked failed |

These are environment variables of the API (`api/config/executor.php`); the Helm chart has no
dedicated values for them, so a Helm installation sets them via `extraEnv`.

RunR's image pre-pull on region nodes covers the wrapper image and the active versions of the
built-in and platform runtimes that have a plain image, except the WebAssembly runtimes (their
image is the build toolchain) and the ML runtimes (`category: ml`); those, custom runtime images
and region builds are pulled on first use.

## Testing

- **API**: `cd api && php artisan test tests/Unit/Runtimes tests/Feature/Runtimes` — manifest
  validation, the built-in catalog and the migration of existing projects, the catalog / registration
  API and its abilities, build and deploy manifests per run mode, region runtime image builds and
  `executor:sync`; `tests/Feature/Runtimes/RuntimeUploadSourceTest.php` covers the `upload` source
  (validation, the image in every region, owner scoping, resolution by digest).
- **UI**: `cd vue && npx vitest run src/components/project/__tests__ src/components/settings/__tests__ src/utils/__tests__ src/components/__tests__`
  (runtime picker, Settings → Runtimes, helpers, editor language) and `npx playwright test e2e/runtimes.spec.ts`.
- **Wrapper**: `cd runtime/wrapper && go vet ./... && go test -count=1 ./...` (Linux; the race
  detector needs `CGO_ENABLED=1`).
- **Q interpreter**: `cd examples/runtimes/q/interpreter && go test ./...`.
- **ML runtime images**: `bash runtime/test/ml_runtimes_test.sh` checks `ci.sh ml-runtimes`
  without Docker (image list and tags, build flags, smoke test, push);
  `runtime/ci.sh ml-runtimes` builds the images and smoke-tests each one.
- **End to end**: `bash runtime/ci.sh test-runtimes` creates a Kind cluster, installs Knative,
  Redis and the region registry through the API (docker compose), builds the wrapper image from
  `runtime/wrapper` and loads it into the cluster, copies `examples/runtimes` into the API
  container and runs only the runtime plugin suite
  (`php artisan test:e2e --suite=runtimes --examples=/tmp/runr-examples`): the built-in Python
  echo function unchanged, the C example (compiled, exec mode), the Q example (persistent mode,
  runtime image built in the region), a schedule-triggered Bash function (the CronJob's log
  carries `tick from bash`) and a queue-triggered Q function (`XADD msg=hi`, the handler logs
  `processed hi (from Q)`). HTTP functions are called with `?name=RunR` and their bodies checked;
  once they scaled to zero they are called again and the time of that call is printed as the
  cold start. `bash runtime/ci.sh test` runs the same suite after its other phases. The flags of
  `ci.sh test` apply (`--k8s=X.Y.Z`, `--no-cleanup`, `--name=…`, `--kubeconfig=…` for an existing
  cluster, where `RUNR_WRAPPER_IMAGE` must be pullable).
- **Shared hosts**: `KIND_LOCK=/tmp/runr-kind.lock bash runtime/ci.sh test-runtimes` takes the
  lock directory (`mkdir`, owner in `<lock>/owner`: `KIND_LOCK_OWNER`, the PID and the time)
  before creating the cluster, waits for it up to `KIND_LOCK_WAIT` seconds (default 3600) while
  another run holds it, and releases it during cleanup once the cluster is gone. With
  `--no-cleanup` (or a cluster that could not be deleted) the lock stays with the cluster; the run
  prints the `kind delete cluster` and `rm -rf <lock>` that free both.
