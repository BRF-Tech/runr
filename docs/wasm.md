# WebAssembly functions

RunR runs functions as WebAssembly components on [runwasi](https://github.com/containerd/runwasi)
containerd shims. A region gets **WebAssembly support** from its Isolation tab: RunR installs the
Spin shim ([containerd-shim-spin](https://github.com/spinframework/containerd-shim-spin), in
[RunR's build](#the-spin-shim-runr-installs)) on the nodes you select, creates its RuntimeClass and
turns on the Knative features Wasm functions need.
Five runtime plugins then appear in that region's runtime picker, marked **Wasm**:

| Runtime | Language | Toolchain | Handler |
|---|---|---|---|
| `wasm-rust` | Rust | cargo, `wasm32-wasip1`, Spin Rust SDK 5.2 | `#[http_component] fn handle(req: Request) -> anyhow::Result<impl IntoResponse>` |
| `wasm-go` | Go | TinyGo 0.42, Spin Go SDK v2 | `spinhttp.Handle(func(w http.ResponseWriter, r *http.Request))` in `init()` |
| `wasm-js` | JavaScript / TypeScript | esbuild, Spin JS SDK (StarlingMonkey) | `addEventListener('fetch', (event) => event.respondWith(...))` |
| `wasm-python` | Python | componentize-py 0.25, Spin Python SDK 5.0 | `class HttpHandler(http.Handler)` with `async def handle_request(self, request)` |
| `wasm-exec` | any language with a WASI target — C / C++, Zig, Go (TinyGo), an uploaded module | wasi-sdk 34, Zig 0.16, TinyGo 0.42; no SDK | a command module on Spin's WAGI executor: the request on stdin and in CGI variables, the response on stdout ([wasm-exec](#wasm-exec)) |

A Wasm function starts as a WebAssembly instance inside the shim — no container image filesystem
to unpack, no language runtime to boot — and the shim keeps the compiled native code of each
component on the node, so a scale-from-zero start loads code that is ready to run. Knative
scales Wasm functions to zero and back exactly like every other RunR function.

- [How it works](#how-it-works)
- [Install WebAssembly support](#install-webassembly-support)
- [Write a Wasm function](#write-a-wasm-function)
- [wasm-exec](#wasm-exec)
- [Triggers](#triggers)
- [The WebAssembly sandbox model](#the-webassembly-sandbox-model)
- [Configuration and variables](#configuration-and-variables)
- [Resources and scheduling](#resources-and-scheduling)
- [API](#api)
- [Permissions](#permissions)
- [Versions](#versions)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)

## How it works

```text
Settings → Regions → (region) → Isolation → WebAssembly → Install (shims, nodes)
  └─ wasm-installer DaemonSet (runr-sandbox) on the selected nodes
       ├─ /usr/local/bin/containerd-shim-spin-v2 (from ghcr.io/brf-tech/runr/containerd-shim-spin, sha256-checked)
       ├─ runtime handler spin-runr → io.containerd.spin.v2 in containerd's configuration
       └─ containerd / k3s / RKE2 restarted once where the configuration changed
  └─ node labels runr.dev/wasm=true, runr.dev/wasm-spin=true
  └─ RuntimeClass wasmtime-spin-v2 (handler spin-runr, nodeSelector runr.dev/wasm-spin=true)
  └─ Knative features: kubernetes.podspec-runtimeclassname, kubernetes.podspec-nodeselector

Save (wasm-rust project)
  └─ build Job: toolchain image → spin build → spin registry push
       └─ <region registry>/runr-builds/<project>:v<N>  (Spin OCI artifact)
Deploy
  └─ Knative Service: runtimeClassName wasmtime-spin-v2, nodeSelector runr.dev/wasm-spin=true,
     command ["/"], image = the artifact
       pod ├─ user container  → WebAssembly (Spin HTTP trigger on :8080)
           └─ queue-proxy     → Linux container (runwasi's libcontainer executor)
```

### Shims and RuntimeClasses

| Shim | Release | Runtime handler | RuntimeClass | Runs |
|---|---|---|---|---|
| Spin (required) | containerd-shim-spin v0.26.0-runr.1 — RunR's build of v0.26.0 (Spin runtime 4.1) | `spin-runr` | `wasmtime-spin-v2` | Spin apps — the five `wasm-*` runtimes, `wasm-exec` included |
| Wasmtime (optional) | containerd-shim-wasmtime v0.6.1 | `wasmtime-runr` | `wasmtime-v1` | WASI command modules and components packaged as OCI images, for platform runtimes that set `runtimeClass: wasmtime-v1` |

The RuntimeClass name `wasmtime-spin-v2` is the one the Spin operator uses, so Spin apps written
for SpinKube find the same RuntimeClass. The handlers carry a `-runr` suffix: k3s registers shims
it finds on its `PATH` under their plain names (`spin`, `wasmtime`), and RunR's handlers stay
independent of that. RunR leaves a RuntimeClass of the same name that another tool created alone
and reports it instead of replacing it.

### The Spin shim RunR installs

RunR installs its own build of containerd-shim-spin: **v0.26.0-runr.1**, the image
`ghcr.io/brf-tech/runr/containerd-shim-spin:v0.26.0-runr.1`, built from `runtime/wasm-shim-spin`. It is the
upstream tag v0.26.0 with one change in the runwasi library it is built on (containerd-shim-wasm
1.0.1): the Linux containers of a Wasm pod run with the environment of their pod spec. The upstream
releases since v0.16.0 start them with the shim's own environment, and Knative's queue-proxy, which
reads its configuration from its environment (`CONTAINER_CONCURRENCY`, `SERVING_*`, …), exits at
start — no Wasm revision would become ready. The change is the one proposed to runwasi for exactly
this; Wasm containers are unchanged.

| | |
|---|---|
| Source | containerd-shim-spin tag v0.26.0 (commit `9227600`), containerd-shim-wasm 1.0.1 from crates.io with `patches/containerd-shim-wasm-1.0.1-linux-container-env.patch` |
| Toolchain | Rust 1.95.0 (the shim's `rust-toolchain.toml`), the cross-rs musl images upstream builds its releases with, protoc and a static libseccomp as in upstream's `cross/Dockerfile` — every input pinned by digest or sha256 |
| Binaries | static musl builds for amd64 and arm64, cross-compiled on amd64 |
| Image | `/shim/containerd-shim-spin-v2`, `SHA256SUMS`, `VERSION`, `LICENSE` and `NOTICE` (Apache-2.0) on RunR's Alpine base |
| Build | `runtime/ci.sh wasm-shim` (local) / `runtime/ci.sh wasm-shim --push` (both platforms) |

The version is the image tag and `api/app/Services/Wasm/WasmShim.php` pins it; the installer
reports it in its log.

### One pod-level RuntimeClass, two kinds of container

A RuntimeClass applies to the whole pod, so Knative's queue-proxy sidecar runs under the Spin
shim too. runwasi decides per container: an entrypoint that resolves to an ELF binary or a
script (`#!`) in the container's filesystem is a Linux container and runs through runwasi's
libcontainer (youki) executor; a container whose image carries WebAssembly layers is run by the
Wasm engine ([runwasi executor](https://github.com/containerd/runwasi/blob/main/crates/containerd-shim-wasm/src/sys/unix/container/executor.rs):
`is_linux_container` checks the ELF magic / shebang of `argv[0]`, OCI sources with Wasm layers go
to the engine). queue-proxy is an ELF binary, so it runs as a regular Linux container next to the
Wasm app, with the environment of its pod spec ([RunR's Spin shim](#the-spin-shim-runr-installs)) —
the Knative Service is the same as for every other runtime, plus three fields:

```yaml
spec:
  template:
    spec:
      runtimeClassName: wasmtime-spin-v2
      nodeSelector:
        runr.dev/wasm-spin: "true"        # also what the RuntimeClass selects
      containers:
        - name: handler
          image: <registry>/runr-builds/hello:v3
          command: ["/"]                  # anything that is not an executable of the image
          env:
            - {name: SPIN_HTTP_LISTEN_ADDR, value: "0.0.0.0:8080"}
          ports: [{containerPort: 8080}]
```

The Spin shim's HTTP trigger listens on `SPIN_HTTP_LISTEN_ADDR` (port 80 by default); the Wasm
runtimes set it to Knative's port 8080 (`run.env` of their manifests). Knative rejects
`runtimeClassName` and `nodeSelector` in a pod spec unless `config-features` enables them — the
installer merge-patches exactly those two keys into the KnativeServing CR (or the
`config-features` ConfigMap without the operator), `region:reconcile` keeps them enabled, and a
change of the dedicated workers keeps `nodeselector` enabled while the region has WebAssembly
support.

### Packaging: Spin OCI artifacts

A Wasm function version is built by a Job in the project's region (same name, labels and
lifecycle as the Kaniko build Job; registry retention trims old tags the same way):

1. an init container lays out the project's files — over the runtime's default `spin.toml`,
   `Cargo.toml` / `go.mod` / `package.json` (`build.files`), so a FaaS project can bring its own;
2. the `build` container runs the runtime's build image (the toolchain plus the Spin CLI of the
   Spin version the shim embeds): the dependency install of `requirements.txt` (Python), then the
   runtime's `build.command` (`spin build` for the SDK runtimes, `runr-wasm-exec build` for
   [wasm-exec](#wasm-exec)), then `spin registry push --insecure <registry>/runr-builds/<project>:v<N>`.

`spin registry push` stores the app as an OCI artifact: the locked Spin app as its config layer
and every component as an `application/vnd.wasm.content.layer.v1+wasm` layer. containerd pulls it
like an image; the Spin shim compiles its Wasm layers once per node and keeps the native code in
containerd's content store (keyed by the engine's compatibility hash), so every later start of the
same version on that node loads precompiled code. That is why RunR pushes artifacts rather than an
image `FROM scratch` with `spin.toml`: the shim reads such an image from its filesystem and
compiles it again on every start.

The build images are pulled by the nodes like any image (`ghcr.io/brf-tech/runr/exec-wasm-<lang>:4.1`,
built from `runtime/wasm-<lang>/Dockerfile` with `runtime/ci.sh wasm` on base images from the reqs
mirror; their build steps run Spin with `OTEL_SDK_DISABLED=true`, as a docker-container BuildKit
builder hands every build step an OTLP endpoint Spin rejects) and come with the
dependencies of the default project already fetched and compiled — Rust's `CARGO_TARGET_DIR`,
Go's module and TinyGo caches, JavaScript's `node_modules` — so a build of the starter code only
compiles the function itself. The build Job runs under the region's build sandbox like Kaniko.

**Supply chain.** A Spin app is signed with the region key like every build, so signature
verification on deploy and the admission policy cover Wasm functions too; it gets no SBOM and no
vulnerability scan (syft and Trivy read container filesystems, an artifact has none), and the
policy gate never requires a scan of it — see [Supply chain → WebAssembly](supply-chain.md#overview).

## Install WebAssembly support

**Settings → Regions → (region) → Isolation → WebAssembly**: choose the shims (Spin is always
included; Wasmtime optional) and the nodes (all eligible nodes, or a selection), then **Install**.
The card shows every node with its architecture, container runtime and the shims it has, the
install log while it runs, and the Knative features the install still enables. The region needs
Knative Serving first: Wasm functions run as Knative Services.

A node is eligible when it

- runs Linux on amd64 or arm64,
- runs containerd: plain containerd (1.7 with config version 2, 2.x with config version 3) or the
  containerd embedded in k3s / RKE2,
- has no `NoSchedule` / `NoExecute` taint other than `runr.dev/dedicated`, and
- is one of the dedicated workers (`runr.dev/dedicated=projects`) when the region has them.

The selected nodes have to run the same container runtime flavor. Hardware virtualization is not
needed: WebAssembly runs in the shim process, so cloud VMs without nested virtualization run Wasm
functions too.

From the command line, `runr regions wasm REGION` shows the same: the status, the shims and every
node with its eligibility. `runr regions wasm install REGION --wait` installs the Spin shim on every
eligible node (`--shim wasmtime` adds Wasmtime, `--node` selects nodes; it asks before the
container runtimes restart, `--yes` skips the question) and `runr regions wasm uninstall REGION`
removes it ([CLI](cli.md#webassembly-and-demo-projects)).

What the installer does on each selected node (`wasm-installer` DaemonSet, privileged, the node's
`/` at `/host`, one node at a time):

1. installs each shim binary atomically into `/usr/local/bin`: the Spin shim from its image (an
   init container of the installer pod copies the image's `/shim`; the binary is checked against
   the image's `SHA256SUMS`), the Wasmtime shim from its pinned release tarball (sha256-checked).
   A binary already in place with the same sha256 stays (`/var/lib/runr/wasm/<binary>.sha256`); a
   different build of the shim is replaced — Wasm pods that run keep the shim process they started
   with;
2. writes RunR's runtime handlers into containerd's configuration — one block between the
   markers `# runr-wasm: begin` / `# runr-wasm: end`, with `SystemdCgroup = true` when runc on the
   node uses the systemd cgroup driver:

   | Node | Where |
   |---|---|
   | k3s / RKE2 whose generated `config[-v3].toml` imports `config[-v3].toml.d` | drop-in `runr-wasm.toml` there |
   | k3s / RKE2 otherwise | the block in `config[-v3].toml.tmpl` (`{{ template "base" . }}` first) |
   | containerd with an `imports` glob in `config.toml` (e.g. `/etc/containerd/conf.d/*.toml`) | drop-in `runr-wasm.toml` in that directory |
   | containerd otherwise | the block appended to `/etc/containerd/config.toml` (rewritten in place; `config.toml.runr-pre-wasm` keeps the original) |

   Every change has to pass `containerd --config <file> config dump` with the node's own
   containerd binary before anything restarts; a rejected change is undone and reported;
3. restarts containerd (or the k3s / RKE2 service that runs it) when the configuration changed or
   a handler is not loaded, and waits until `crictl info` lists the handlers. Running pods keep
   running; on k3s / RKE2 the restart also restarts kubelet (and on a server node the Kubernetes
   API), staggered per node. A node is restarted at most once per change, so a failing node never
   loops.

When the installer is ready on a node, RunR labels it `runr.dev/wasm=true` and
`runr.dev/wasm-<shim>=true`, then creates the RuntimeClasses and enables the Knative features. The
install is idempotent: installing again changes and restarts nothing on a node that has
everything.

**Changing shims or nodes** is another install with the new selection. A deselected shim loses
its RuntimeClass and node labels before the installer removes its handler and binary; nodes
taken out of the selection are cleaned by the `wasm-cleanup` DaemonSet. Both are refused while
Wasm pods use what would go away. A failed change of a working install keeps the region ready
with the shims its nodes still have, and the log ends with the error; a failed first install
removes what it did on the nodes again.

**Uninstall** is refused while pods run under a Wasm RuntimeClass. Otherwise the RuntimeClasses
and node labels go first (no new Wasm pods), then the cleanup DaemonSet removes RunR's drop-in or
block from containerd's configuration (checked with `config dump`), restarts the runtime where a
RunR handler is still loaded, and removes the binaries and `/var/lib/runr/wasm`. A node that still
runs a shim process refuses its cleanup.

**Upgrading RunR.** `region:reconcile` (the Helm bootstrap job runs it after every upgrade)
compares the `wasm-installer` of every region whose WebAssembly support is ready with the shims of
the new release. When they differ, or the installer is missing (a failed change removes it), it
queues that region's WebAssembly install with the same shims and nodes: the installer replaces the binaries in place, one node at a time, and restarts the
container runtime only on nodes where RunR's configuration block changes — a new shim build alone
restarts nothing. The region shows "installing" until every node is done; a failure keeps the
install as it was and ends the log with the error. `php artisan region:reconcile --dry-run` shows
it beforehand. Wasm pods that run during the upgrade keep the shim they started with until they
are replaced.

Regions that share a cluster share its nodes: WebAssembly support installed through one of them
is on those nodes for all of them.

## Write a Wasm function

Create a project in a region with WebAssembly support and pick one of the **Wasm** runtimes. The
picker offers them only in regions whose WebAssembly support is ready with the Spin shim; the
API refuses to create or deploy a Wasm project anywhere else, naming the region.

- **XaaS** projects hold the handler file (`lib.rs`, `main.go`, `index.js`, `app.py`, `main.c`
  for wasm-exec); the build adds the runtime's default `spin.toml` and project files (wasm-exec
  generates its `spin.toml`).
- **FaaS** projects can add their own `spin.toml`, `Cargo.toml`, `go.mod`, `package.json` or
  `requirements.txt` next to the handler — they replace the defaults. Keep one component serving
  `/...` and its `source` pointing at what the build produces.

Every save is a build (the runtimes have a `build` step); the version deploys once its artifact
is in the region registry. Starter code and complete examples: [`examples/wasm`](../examples/wasm).

```rust
use spin_sdk::http::{IntoResponse, Request, Response};
use spin_sdk::http_component;

#[http_component]
fn handle(req: Request) -> anyhow::Result<impl IntoResponse> {
    let name = req.query().split('&').find_map(|p| p.strip_prefix("name=")).unwrap_or("World");
    Ok(Response::builder()
        .status(200)
        .header("content-type", "text/plain")
        .body(format!("Hello, {name}! (from Rust, Wasm)"))
        .build())
}
```

```bash
curl "https://hello.<region>.<domain>/?name=RunR"
# Hello, RunR! (from Rust, Wasm)
```

## wasm-exec

`wasm-exec` is the WebAssembly counterpart of RunR's `exec` mode: any program that compiles to a
WASI command — a preview 1 module (`wasm32-wasip1`, a `_start` export) or a WASI 0.2 component
(`wasi:cli/run`) — runs without a Spin SDK. The request reaches it CGI-style — the body on stdin, the request in environment variables — and what
it writes to stdout is the response. This is Spin's **WAGI** executor (`executor = { type = "wagi" }`
of an HTTP trigger).

**Why it works on the pinned shim.** RunR's containerd-shim-spin v0.26.0-runr.1 (`WasmShim.php`)
embeds Spin 4.1.0; its `spin-trigger-http` crate has the WAGI executor (`crates/trigger-http/src/wagi.rs` at
`v4.1.0`: CGI headers in the environment, the body on stdin, argv from `argv`, the module's
`wasi:cli/run` called once per request), and the shim turns a preview 1 command module into a
component on load with Spin's WASI command adapter (`spin-componentize`: a core module with
`_start` and no wit-bindgen metadata is adapted with `wasi_snapshot_preview1.command`); a WASI 0.2
component runs as it is. So a plain `.wasm` from wasi-sdk or Zig, and TinyGo's component, run
unchanged.

**Go is built as a component.** The WASI adapter keeps its state in pages it adds to the module's
memory. wasi-libc's allocator (C, C++) and Zig's take memory where `memory.grow` puts it; TinyGo's
heap assumes the memory past its end is its own, grows over the adapter's pages and corrupts them
or its own objects as soon as a program allocates past its initial memory (the Q interpreter's
script reader is enough). As a WASI 0.2 component (`tinygo build -target=wasip2`) a TinyGo program
needs no adapter. Spin 4.1 logs the upstream notice "WAGI will
be deprecated in a future version of Spin" when an app uses the executor; the shim release is
pinned, and a shim upgrade — a change of `WasmShim.php` — is checked against this contract by
`runtime/ci.sh test-images`. `spin-componentize` refuses modules from wasi-sdk releases before 19
(a wasi-libc allocation bug): use wasi-sdk 19 or later.

### The contract

| The module gets | Value |
|---|---|
| stdin | the request body |
| `REQUEST_METHOD`, `PATH_INFO`, `QUERY_STRING`, `SERVER_NAME`, `SERVER_PORT`, `REMOTE_ADDR`, `CONTENT_TYPE`, `CONTENT_LENGTH`, `GATEWAY_INTERFACE=CGI/1.1` | the request (CGI) |
| `HTTP_<NAME>` | every request header (`X-Api-Key` → `HTTP_X_API_KEY`); queue messages and schedule ticks carry `HTTP_CE_TYPE` (`dev.runr.queue.message`, `dev.runr.schedule.tick`), `HTTP_CE_ID`, `HTTP_CE_SOURCE` |
| `X_FULL_URL`, `X_MATCHED_ROUTE`, `X_RAW_PATH_INFO`, `SCRIPT_NAME` | Spin's request variables |
| `RUNR_RUNTIME`, `RUNR_RUNTIME_VERSION`, `RUNR_HANDLER`, `RUNR_FILENAME` | the RunR contract, as the component's static environment |
| argv | `${SCRIPT_NAME} ${ARGS}` (the path and the query words), or the runtime's `wagi.argv` |
| files | the project's files, read-only at `/<name>` (the runtime's `wagi.files`) |

The module writes CGI to stdout: header lines (`Content-Type: …` or `Location: …`, optionally
`Status: 201`), a blank line, the body. Without `Content-Type` or `Location` Spin answers 500. A
module that exits with a non-zero code answers 500 as well. stderr goes to the function's logs.

```c
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

int main(void) {
    const char *event = getenv("HTTP_CE_TYPE");
    printf("Content-Type: text/plain\n\n");
    if (event && strcmp(event, "dev.runr.schedule.tick") == 0) { printf("tick from C (Wasm)"); return 0; }
    printf("Hello from C (Wasm), %s %s", getenv("REQUEST_METHOD"), getenv("PATH_INFO"));
    return 0;
}
```

Preview 1 modules have no sockets: a wasm-exec function answers requests and reads its mapped
files, it opens no connections of its own. Configuration comes with the request or the files; the
project's environment variables reach Spin application variables (as for every Wasm function),
which WAGI modules do not read.

### Building

The build Job runs `runr-wasm-exec build` in `ghcr.io/brf-tech/runr/exec-wasm-exec:4.1` (wasi-sdk 34, Zig
0.16, TinyGo 0.42 with wasm-tools 1.240, the Spin CLI 4.1, `runtime/wasm-exec`); the first match
makes `main.wasm`:

| The project has | Build |
|---|---|
| `wasm.upload` (one line: an uploaded module, `q-interpreter:1.0`) | the module is fetched from the region registry (`runr-image-upload fetch`, digest-checked) — nothing compiled ([Images](images.md)) |
| `build.sh` | runs it; it writes `main.wasm` |
| `build.zig` | `zig build -Dtarget=wasm32-wasi -Doptimize=ReleaseSmall`, the first installed `.wasm` |
| `*.zig` | `zig build-exe -target wasm32-wasi -O ReleaseSmall main.zig` |
| `go.mod` or `*.go` | `tinygo build -target=wasip2 -no-debug` (a WASI 0.2 component) |
| `*.cc` / `*.cpp` / `*.cxx` | `$WASI_SDK_PATH/bin/clang++ --target=wasm32-wasip1 -O2 -fno-exceptions` over the C and C++ files |
| `*.c` | `$WASI_SDK_PATH/bin/clang --target=wasm32-wasip1 -O2` over the C files |

RunR generates the `spin.toml` unless the project brings one (`WagiSpinManifest`):

```toml
spin_manifest_version = 2

[application]
name = "hello-c"
version = "0.1.0"

[[trigger.http]]
route = "/..."
component = "function"
executor = { type = "wagi", argv = "${SCRIPT_NAME} ${ARGS}" }

[component.function]
source = "main.wasm"
files = ["main.c"]
environment = { RUNR_FILENAME = "main.c", RUNR_HANDLER = "main", RUNR_RUNTIME = "wasm-exec", RUNR_RUNTIME_VERSION = "4.1" }
```

and pushes the Spin OCI artifact like every Wasm build. XaaS projects hold `main.c`; FaaS projects
add more sources, another language, a `build.sh`, a `wasm.upload` or their own `spin.toml`
(another `argv`, other `files`).

### Interpreters and other languages

A language whose interpreter compiles to WASI runs its scripts the same way: the interpreter is
the module, the script a mapped file, `argv` starts it. [`examples/wasm/q-exec`](../examples/wasm/q-exec)
does this for Q: the Q interpreter (Go) compiled with TinyGo, run as `q -cgi /main.q`. Shipped as a
platform runtime, the interpreter is an [uploaded image](images.md) — the version source of the
runtime — and every function build only packages it with the project's script:

```yaml
id: q-wasm
versions:
  - version: "1.0"
    upload: q-interpreter:1.0          # runr images push q.wasm --name q-interpreter:1.0
build:
  image: ghcr.io/brf-tech/runr/exec-wasm-exec:4.1
  command: runr-wasm-exec build        # fetches the module (RUNR_WASM_MODULE) as main.wasm
runtimeClass: wasmtime-spin-v2
wagi:
  argv: "q -cgi /{file}"
  files: true
```

The `wagi` section works for any built-in or platform runtime on the Spin shim (`argv` with the
manifest placeholders, `files`, extra static `environment`). A language without a TinyGo / wasi-sdk
/ Zig toolchain in the image — Swift (`swiftc -target wasm32-unknown-wasi`), Rust without the Spin
SDK (`--target wasm32-wasip1`), anything else that writes a preview 1 module or a WASI 0.2
command component — is compiled where its toolchain is and uploaded: `runr images push app.wasm --name app:1.0` and `wasm.upload` with
`app:1.0`.

## Triggers

All three RunR triggers reach a Wasm function as HTTP requests on its Spin HTTP trigger, with the
[CloudEvent contract](runtimes.md#http-mode) of every http-mode runtime:

| Trigger | What arrives | How |
|---|---|---|
| `http` | the client's request | Knative Service (queue-proxy in front) |
| `queue` | `POST /` with `ce-type: dev.runr.queue.message`, the entry's fields as JSON body | the queue consumer delivers each Redis stream entry to the cluster-local Knative Service; a 2xx answer acknowledges it |
| `schedule` | `POST /` with `ce-type: dev.runr.schedule.tick`, body `{"trigger":"schedule"}` | see below |

The handler tells them apart by the `ce-type` header — the starter code of every Wasm runtime
does exactly that.

A schedule run is a CronJob pod under the Wasm RuntimeClass. The Spin app serves HTTP and never
exits on its own, so it runs as a native sidecar of the Job (an init container with
`restartPolicy: Always` and a startup probe on port 8080), and the Job's container is the
universal wrapper's `tick` — a Linux container that sends the schedule CloudEvent to
`127.0.0.1:8080`, prints the response and exits 0 for a status below 400. The Job then completes
and the kubelet stops the sidecar. Native sidecars are part of Kubernetes 1.29 and later.

## The WebAssembly sandbox model

A Wasm function runs in a capability-based sandbox: the component can do what the interfaces it
imports allow, and nothing else. There is no syscall surface to filter — a component has no way
to issue an arbitrary Linux syscall, open a raw socket, spawn a process, load a kernel module or
read the node's files.

| Capability | In a Wasm function |
|---|---|
| Incoming HTTP | the request of every trigger, through `wasi:http` |
| Outbound HTTP(S) | to the hosts `allowed_outbound_hosts` of the component lists — RunR's default `spin.toml` allows `https://*:*` and `http://*:*`; a FaaS project narrows it in its own `spin.toml` |
| Other outbound services | Spin's interfaces (Redis, PostgreSQL, MySQL, MQTT) with the same allow-list |
| Filesystem | none by default; files a `spin.toml` lists under `files` are mapped read-only into the component |
| Environment | the component's `environment` table and Spin application variables (below) |
| Processes and threads | one instance per request; no child processes |
| Memory | the component's linear memory, inside the pod's memory limit |
| CPU and time | the pod's CPU limit and the project's timeout, as for every function |

What follows from the model for the languages:

- **Rust**: crates that compile to `wasm32-wasip1` work; crates that need threads, `std::process`
  or native C libraries are for the regular runtimes.
- **Go**: TinyGo compiles a large part of the standard library; code that relies on `cgo` or on
  reflection-heavy packages is checked with `tinygo build` first.
- **JavaScript / TypeScript**: web APIs (`fetch`, `Request`, `Response`, `URL`, `TextEncoder`,
  `crypto.subtle`, ...) are there; Node.js built-in modules (`fs`, `net`, `child_process`) belong
  to the `nodejs` runtime.
- **Python**: CPython compiled to WebAssembly with the standard library; pure-Python packages from
  `requirements.txt` are bundled into the component, packages with native extensions belong to
  the `python` runtime.

The isolation of a Wasm function is the WebAssembly sandbox inside the shim; the region's sandbox
mapping (runc, gVisor, Kata) applies to every other runtime and to the build Jobs. The Wasm
RuntimeClass replaces that mapping for Wasm pods, the way a runtime's `runtimeClass` always does
([runtimes.md](runtimes.md#sandboxing-gpus-and-runtimeclasses)).

## Configuration and variables

Spin components read configuration as **application variables**. The project's environment
variables are the container's environment, which the Spin shim hands to Spin's environment
variable provider: a variable `SPIN_VARIABLE_GREETING=hi` becomes the value of the application
variable `greeting`. A FaaS project declares it in its `spin.toml`:

```toml
[variables]
greeting = { default = "hello" }

[component.function.variables]
greeting = "{{ greeting }}"
```

and reads it with the SDK (`spin_sdk::variables::get("greeting")` in Rust,
`spin_sdk.variables.get("greeting")` in Python, `@spinframework/spin-variables` in JavaScript,
the `variables` package of the Go SDK). The `RUNR_*` contract variables are part of
the container environment as well; inside the component, the request itself (path, query,
headers, CloudEvent headers) carries what the function needs.

## Resources and scheduling

The Wasm runtimes default new projects to `100m` CPU and `128` MiB (`resources` of their
manifests); a project's own size wins, and the region's capacity check uses it like for every
project. The RuntimeClass has no pod overhead: there is no VM, the instance runs inside the shim.
Pods land only on nodes labelled `runr.dev/wasm-spin=true` (RuntimeClass `scheduling` and the
pod's `nodeSelector`); with dedicated workers they also carry the `runr.dev/dedicated` toleration
and selector as every project pod does.

## API

All endpoints are under `/api`, authenticated with a session or an API token.

| Method & path | Permission | Purpose |
|---|---|---|
| `GET /knative-regions/{id}/wasm` | `regions.view` | `{status, message, shims, nodes, available_shims, spin_runtime, warning, can_manage, cluster, cluster_error}` — `cluster` (skipped with `?cluster=0`): `{nodes: [{name, arch, flavor, runtime_version, eligible, reason, target, shims}], runtime_classes, knative_features_to_enable, installer}` |
| `POST /knative-regions/{id}/wasm` | `sandbox.manage` | `{shims: ["spin"] \| ["spin", "wasmtime"], nodes: [names] \| null}` → 202 `{message, warning, region}`; install or change (queued) |
| `DELETE /knative-regions/{id}/wasm` | `sandbox.manage` | 202 `{message, region}`; uninstall (queued; refused while Wasm pods run) |

`status` is `not_installed`, `installing`, `ready`, `uninstalling` or `error`; `message` is the
step log. Progress is broadcast as `install.progress` with `component: "wasm"`, the end as
`wasm.installed`, `wasm.install_failed`, `wasm.uninstalled` or `wasm.uninstall_failed`. A node
that cannot take WebAssembly answers 422 with the reason (`not_linux`, `not_dedicated`, `tainted`,
`runtime`, `arch`); a region without Knative Serving answers 422 as well.

The region resource (`GET /knative-regions`, the settings payload) carries
`sandbox_wasm_status`, `sandbox_wasm_status_message`, `sandbox_wasm_shims`, `sandbox_wasm_nodes`
and `has_sandbox_wasm` (status `ready`); runtime
catalog entries carry `wasm` — the shim a runtime needs (`"spin"`), `null` for other runtimes —
and `GET /runtimes?region=<id>` lists Wasm runtimes only for regions that offer them.

The RunR MCP server ([AI agents](mcp.md)) wraps these routes as `wasm_status`, `wasm_install`
(follows the install) and `wasm_uninstall`; `runtimes_list` names the shim a runtime needs
(`wasm`). In the CLI, `runr regions wasm [status]|install|uninstall` wraps them,
`runr runtimes ls --region <region>` lists the runtimes a region offers (kind `wasm` for the
WebAssembly ones) and `runr images push module.wasm` uploads a module for [wasm-exec](#wasm-exec).

## Permissions

WebAssembly support is one of a region's node-level sandbox runtimes, next to gVisor and Kata, and
uses the same catalog permission ([permissions.md](permissions.md)):

| Permission | Grants |
|---|---|
| `regions.view` | the WebAssembly status of a region (`GET /knative-regions/{id}/wasm`) |
| `sandbox.manage` | install, change and uninstall a region's WebAssembly support |

Owners and admins hold `sandbox.manage` by default; platform administrators pass every check.
The routes also carry `region.mutable`, like every route that changes a region (with Tenancy:
platform regions read-only for tenant users, a tenant's own regions for members with
`regions.manage`, `sandbox.manage` or `registry.manage`). Creating and deploying Wasm projects
needs nothing beyond creating and deploying projects.

## Versions

| Component | Version |
|---|---|
| containerd-shim-spin | v0.26.0-runr.1: v0.26.0 built by RunR (Spin runtime 4.1.0, runwasi containerd-shim-wasm 1.0.1 with the pod environment for Linux containers) |
| containerd-shim-wasmtime | v0.6.1 |
| Spin CLI in the build images | 4.1.0 |
| Rust | 1.98, `wasm32-wasip1`, spin-sdk 5.2 |
| TinyGo | 0.42, spin-go-sdk v2.2.1 |
| Node.js | 22, @spinframework/build-tools 2.1, @spinframework/wasi-http-proxy 2.0 |
| Python | 3.13, componentize-py 0.25.1, spin-sdk 5.0.0 (world `spin:up/http-trigger@4.1.0`) |
| wasm-exec | wasi-sdk 34, Zig 0.16.0, TinyGo 0.42.0, runr-image-upload 1.0.0; the WAGI executor of Spin 4.1.0 |

`api/app/Services/Wasm/WasmShim.php` pins the Spin shim image (its tag is the build's version,
`runtime/wasm-shim-spin`) and the Wasmtime release tarballs by sha256. A new shim is a change of
that file; installing WebAssembly support again applies it, and on existing regions
`region:reconcile` does at the upgrade ([Upgrading RunR](#install-webassembly-support)).

## Troubleshooting

- **The install log** (the WebAssembly card, or `sandbox_wasm_status_message`) has every step;
  a node script error ends with the node's name. `kubectl -n runr-sandbox logs ds/wasm-installer`
  shows the node side while it runs.
- **`containerd rejects ...`**: the node's containerd did not accept the changed configuration;
  nothing was restarted and the change was undone.
- **A Wasm pod stays `Pending`**: no node carries `runr.dev/wasm-spin=true` with room for it — the
  install did not finish on any node, or the selected nodes are full.
- **A Wasm pod fails with `no runtime for "spin-runr"`**: the node's runtime has not loaded the
  handler; installing WebAssembly support again restarts it there.
- **queue-proxy of a Wasm pod exits with `Required key CONTAINER_CONCURRENCY missing value`**: the
  node's `containerd-shim-spin-v2` is not RunR's build (replaced by hand or by another tool), so
  Linux containers do not get their pod's environment. Installing WebAssembly support again puts
  RunR's build back.
- **The build fails**: the version's build error is the last lines of the build Job's log
  (`spin build` / the toolchain, or `spin registry push`).

## Testing

- **API**: `cd api && php artisan test tests/Unit/Wasm tests/Feature/Wasm` — shim catalog, node
  scripts against a fake node (k3s drop-in and template, containerd 2.x imports drop-in,
  config.toml block, idempotency, cleanup, the Spin shim from its image, a shim of an earlier
  build replaced without a restart), installer / cleanup DaemonSets and RuntimeClasses,
  install / change / uninstall against a fake cluster, region:reconcile's step (an outdated
  installer queues the install), the Knative feature patch, the endpoints and
  their permissions, the Wasm runtime manifests, the build Job of every language, the deploy manifests
  (runtimeClassName, nodeSelector, schedule sidecar) and the picker / catalog filtering.
  `php artisan runtimes:validate ../runtime/wasm-*/runtime.yaml` and `php artisan runtimes:bundle --check`
  check the manifests and the bundled catalog.
- **Wrapper**: `cd runtime/wrapper && go test -count=1 -run Tick ./...` (Linux) — the `tick`
  subcommand.
- **UI**: `cd vue && npx vitest run tests/components/wasm tests/utils/wasm.spec.js` (the WebAssembly
  card: status, shims, node selection, install / change / uninstall, progress events, read-only;
  the runtime picker's filtering and Wasm badge) and
  `npx playwright test e2e/wasm.spec.ts --project=mock` (mocked API).
- **wasm-exec**: `cd api && php artisan test tests/Feature/Wasm/WasmExecTest.php tests/Unit/Wasm/WagiSpinManifestTest.php`
  (the manifest, the build Job, the generated WAGI `spin.toml`, `wasm.upload` modules, a platform
  runtime on an uploaded interpreter); `cd examples/runtimes/q/interpreter && go test ./...` (the
  interpreter's CGI mode); `KIND_LOCK=/tmp/runr-kind.lock bash runtime/ci.sh test-images` deploys
  `examples/wasm/c-exec` and Q (Wasm) on Kind and calls them.
- **End to end**: `bash runtime/ci.sh test-wasm [--k8s=X.Y.Z] [--no-cleanup]` creates a Kind
  cluster (`KIND_LOCK=/tmp/runr-kind.lock` by default), loads the Spin shim image (the local
  image of that version, else pulled, else built) and the Rust and JavaScript build images into
  it, installs WebAssembly support through the API, puts the upstream Spin shim back on the nodes
  through an installer of an earlier build and checks that region:reconcile's step replaces it
  with RunR's build without a runtime restart, deploys the Rust and JavaScript examples, checks
  their answers and pod specs, prints the cold start of each after it scaled to zero
  (`WASM_COLD_STARTS` runs, default 3, with the median), then deletes them and uninstalls
  WebAssembly support.
- **The Spin shim build**: `docker build --target test runtime/wasm-shim-spin` runs the unit tests
  of the changed runwasi executor (a static busybox `env` run through the same validate /
  setup_envs / exec sequence libcontainer runs).
