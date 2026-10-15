# WebAssembly examples

One example per language of RunR's WebAssembly runtimes — Spin HTTP components for the SDK
runtimes, WASI command modules on Spin's WAGI executor for `wasm-exec`. Each answers the three
RunR triggers with one handler — HTTP requests, queue messages and schedule ticks arrive as
HTTP requests, the latter two as CloudEvents (`ce-type: dev.runr.queue.message` /
`dev.runr.schedule.tick`):

| Directory | Runtime | Toolchain |
|---|---|---|
| [`rust`](rust) | `wasm-rust` — Rust (Wasm) | cargo, `wasm32-wasip1`, Spin Rust SDK 5.2 |
| [`go`](go) | `wasm-go` — Go (Wasm) | TinyGo 0.42, Spin Go SDK v2 |
| [`js`](js) | `wasm-js` — JavaScript (Wasm) | esbuild + Spin JS SDK (StarlingMonkey) |
| [`python`](python) | `wasm-python` — Python (Wasm) | componentize-py 0.25, Spin Python SDK 5.0 |
| [`c-exec`](c-exec) | `wasm-exec` — WASI (Wasm exec) | wasi-sdk 34, no SDK: Spin's WAGI executor (CGI on stdin / stdout) |
| [`q-exec`](q-exec) | `q-wasm` — Q (Wasm), a platform runtime on an uploaded interpreter | TinyGo 0.42 (the Q interpreter), WAGI |

`?name=RunR` answers `Hello, RunR! (from <language>, Wasm)` (the Q script answers
`Hello, RunR! (from Q)` on both of its runtimes). The region needs WebAssembly
support first (Settings → Regions → (region) → Isolation → WebAssembly); [docs/wasm.md](../../docs/wasm.md)
explains how it works. Every directory also builds and runs on its own with `spin build && spin up`.

The Rust and JavaScript examples are what `runtime/ci.sh test-wasm` deploys end to end.
`c-exec` and `q-exec` are what `runtime/ci.sh test-images` deploys: the C function built in the
region, Q from an uploaded `q.wasm` ([docs/images.md](../../docs/images.md)).
