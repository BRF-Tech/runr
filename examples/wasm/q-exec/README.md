# Q on WebAssembly (`wasm-exec`, WAGI)

[Q](../../runtimes/q) — the toy language whose interpreter RunR runs as a container-based runtime — on
WebAssembly: the same interpreter ([`examples/runtimes/q/interpreter`](../../runtimes/q/interpreter),
Go, standard library only) compiled with TinyGo to a WASI 0.2 command component and run on Spin's
**WAGI** executor in CGI mode (`q -cgi /main.q`: the request in the environment and on stdin, the
response as CGI on stdout). The script is the same five lines; it answers the three triggers.

| File | Role |
|---|---|
| `main.q` | the function |
| `runtime.yaml` | **Q (Wasm)**, a platform runtime whose version is the uploaded interpreter (`upload: q-interpreter:1.0`) |
| `spin.toml` | for running it locally (builds the interpreter with TinyGo) |

## In RunR: a runtime from an uploaded interpreter

1. The region needs WebAssembly support (**Settings → Regions → (region) → Isolation → WebAssembly**).
2. Compile the interpreter and upload it into the region (Settings → Images, or the CLI):

   ```bash
   cd examples/runtimes/q/interpreter
   tinygo build -target=wasip2 -no-debug -o q.wasm .     # TinyGo 0.42 with wasm-tools
   runr images push q.wasm --region eu-1 --name q-interpreter:1.0
   ```

   Do this as a platform administrator (outside an organization): platform runtimes use platform
   uploads. Upload it into every region the runtime is offered in.
3. Register the runtime — Settings → Runtimes → Add runtime with `runtime.yaml`,
   `runr runtimes register -f examples/wasm/q-exec/runtime.yaml` from the repository root, or
   the API from `examples/wasm/q-exec`:

   ```bash
   jq -n --rawfile manifest runtime.yaml '{manifest: $manifest}' > body.json
   curl -sS -X POST "$API/runtimes" -H "Authorization: Bearer $TOKEN" \
     -H 'Content-Type: application/json' -H 'Accept: application/json' -d @body.json
   ```

   Registration checks that `q-interpreter:1.0` is uploaded in each of the runtime's regions.
4. Create a project on **Q (Wasm)**, keep `main.q`, deploy, call it:

   ```bash
   curl "https://<project>.<region>.<domain>/?name=RunR"
   # Hello, RunR! (from Q)
   ```

Every build packages the uploaded interpreter with the project's `main.q` (mapped read-only into
the component) into a Spin app — no compiler runs per function.

## In RunR: a wasm-exec project

Without registering a runtime, a FaaS project on **WASI (Wasm exec)** can carry the interpreter
itself:

- with the uploaded module: `wasm.upload` (one line, `q-interpreter:1.0`), `main.q`, and a
  `spin.toml` whose executor runs the interpreter — `executor = { type = "wagi", argv = "q -cgi /main.q" }`,
  `source = "main.wasm"`, `files = ["main.q"]`;
- built from source: the interpreter's `.go` files and `go.mod` next to `main.q` (the build runs
  TinyGo), plus the same `spin.toml`.

## Locally

```bash
spin build && spin up      # Spin 4.1 or later, TinyGo 0.42 with wasm-tools
curl "http://127.0.0.1:3000/?name=RunR"
# Hello, RunR! (from Q)
```
