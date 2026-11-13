# WebAssembly examples

One example per language of RunR's WebAssembly runtimes — Spin HTTP components for the SDK
runtimes, WASI command modules on Spin's WAGI executor for `wasm-exec`. Each answers the three
RunR triggers with one handler — HTTP requests, queue messages and schedule ticks arrive as
HTTP requests, the latter two as CloudEvents (`ce-type: dev.runr.queue.message` /
`dev.runr.schedule.tick`):

| Directory | Runtime | Toolchain |
|---|---|---|
| [`rust`](rust) | `wasm-rust` 4.2 — Rust (Wasm) | cargo, `wasm32-wasip2`, Spin Rust SDK 8 (async, WASIp3) |
| [`go`](go) | `wasm-go` — Go (Wasm) | TinyGo 0.44, Spin Go SDK v2 |
| [`js`](js) | `wasm-js` 4.2 — JavaScript (Wasm) | esbuild 0.28 + Spin JS SDK (StarlingMonkey) |
| [`python`](python) | `wasm-python` — Python (Wasm) | componentize-py 0.25, Spin Python SDK 5.0 |
| [`c-exec`](c-exec) | `wasm-exec` — WASI (Wasm exec) | wasi-sdk 34, no SDK: Spin's WAGI executor (CGI on stdin / stdout) |
| [`q-exec`](q-exec) | `q-wasm` — Q (Wasm), a platform runtime on an uploaded interpreter | TinyGo 0.42 (the Q interpreter), WAGI |

`?name=RunR` answers `Hello, RunR! (from <language>, Wasm)` (the Q script answers
`Hello, RunR! (from Q)` on both of its runtimes). The region needs WebAssembly
support first (Settings → Regions → (region) → Isolation → WebAssembly); [docs/wasm.md](../../docs/wasm.md)
explains how it works. Every directory also builds and runs on its own with `spin build && spin up`.

The Rust and JavaScript examples are version 4.2 of their runtimes, the SDK generation next to 4.1
and the default of new projects ([docs/wasm.md](../../docs/wasm.md#versions)). 4.1 (Spin Rust SDK 5,
esbuild 0.25) stays for the projects written for it. Go has 4.1 only: its next SDK generation waits
for WASIp3 support in a Go release.

The Rust and JavaScript examples are what `runtime/ci.sh test-wasm` deploys end to end (on 4.2,
next to wasm-rust 4.1's starter).
`c-exec` and `q-exec` are what `runtime/ci.sh test-images` deploys: the C function built in the
region, Q from an uploaded `q.wasm` ([docs/images.md](../../docs/images.md)).
