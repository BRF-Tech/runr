# Go on WebAssembly (`wasm-go`)

A Spin HTTP component in Go, compiled with TinyGo (`-target=wasip1 -buildmode=c-shared`) against
the Spin Go SDK v2, that answers every RunR trigger: HTTP requests, queue messages and schedule
ticks (both arrive as CloudEvents).

| File | Role |
|---|---|
| `main.go` | the handler: `spinhttp.Handle(func(w http.ResponseWriter, r *http.Request))` in `init()` |
| `go.mod` | the module (RunR's default; requires `github.com/spinframework/spin-go-sdk/v2`) |
| `spin.toml` | the Spin app: one component on `/...`, built with TinyGo |

The handler uses the standard `net/http` types; TinyGo compiles a large part of the standard
library, not all of it — packages that need goroutine-heavy runtime features, `cgo` or
reflection-heavy encoders are the ones to check with `tinygo build` first.

## In RunR

1. The region needs WebAssembly support: **Settings → Regions → (region) → Isolation → WebAssembly → Install**.
2. Create a project in that region and pick **Go (Wasm)**.
3. XaaS: paste `main.go` and save. FaaS: upload the files; your own `go.mod` can add modules
   (they are resolved during the build).
4. Deploy and call it: `curl "https://<project>.<region>.<domain>/?name=RunR"` →
   `Hello, RunR! (from Go, Wasm)`.

## Locally

```bash
go mod tidy
spin build && spin up          # TinyGo 0.42, Spin 4.1 or later
curl "http://127.0.0.1:3000/?name=RunR"
```
