# C on WebAssembly without an SDK (`wasm-exec`)

A C program compiled with [wasi-sdk](https://github.com/WebAssembly/wasi-sdk) to a WASI preview 1
command module and run on Spin's **WAGI** executor — the CGI contract: the request body on stdin,
the request in environment variables, the response on stdout. No Spin SDK, no bindings: anything
that writes `Content-Type: …`, a blank line and a body works.

| File | Role |
|---|---|
| `main.c` | the function — the only file an XaaS project needs (it is the runtime's starter code) |
| `spin.toml` | for running it locally; in RunR the runtime generates it |

What the program gets:

| Variable | Value |
|---|---|
| `REQUEST_METHOD`, `PATH_INFO`, `QUERY_STRING` | the request line |
| `CONTENT_TYPE`, `CONTENT_LENGTH` | the body (on stdin) |
| `HTTP_<NAME>` | every request header (`X-Api-Key` → `HTTP_X_API_KEY`); `HTTP_CE_TYPE` names the CloudEvent of queue messages and schedule ticks |
| `RUNR_RUNTIME`, `RUNR_RUNTIME_VERSION`, `RUNR_HANDLER`, `RUNR_FILENAME` | the RunR contract (static, from the generated spin.toml) |

## In RunR

1. The region needs WebAssembly support: **Settings → Regions → (region) → Isolation → WebAssembly → Install**.
2. Create a project in that region and pick **WASI (Wasm exec)**.
3. XaaS: keep (or paste) `main.c` and save — the build compiles it with wasi-sdk into
   `main.wasm`. FaaS projects can add more `.c` / `.h` files, use C++ (`.cc` / `.cpp`), Zig or
   Go (TinyGo), bring a `build.sh` that writes `main.wasm`, or skip the build with a `wasm.upload`
   file naming an uploaded module ([docs/images.md](../../../docs/images.md)).
4. Deploy and call it:

```bash
curl "https://<project>.<region>.<domain>/?name=RunR"
# Hello, RunR! (from C, Wasm)
```

Switch the trigger to **schedule** and each run answers `tick from C (Wasm)`; to **queue**, and an
entry `msg=hi` is answered with `processed {"msg":"hi"} (from C, Wasm)`.

## A precompiled module

Any language with a WASI preview 1 target works the same way — build the module where its
toolchain is, upload it, and name it in the project:

```bash
$WASI_SDK_PATH/bin/clang --target=wasm32-wasip1 -O2 -o hello.wasm main.c
runr images push hello.wasm --region eu-1 --name hello-c:1.0
printf 'hello-c:1.0\n' > wasm.upload   # a FaaS file of a wasm-exec project
```

## Locally

```bash
export WASI_SDK_PATH=/opt/wasi-sdk   # wasi-sdk 19 or later
spin build && spin up                # Spin 4.1 or later
curl "http://127.0.0.1:3000/?name=RunR"
```
