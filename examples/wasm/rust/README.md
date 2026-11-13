# Rust on WebAssembly (`wasm-rust`)

A Spin HTTP component in Rust (Spin Rust SDK 8, `wasm32-wasip2`: an async `#[http_service]` on
WASIp3) that answers every RunR trigger: HTTP requests, queue messages and schedule ticks (both
arrive as CloudEvents). It is the starter of version **4.2** of the runtime, the default of new
projects.

| File | Role |
|---|---|
| `lib.rs` | the handler — the only file an XaaS project needs |
| `Cargo.toml` | the crate (RunR's default of 4.2; `[lib] path = "lib.rs"`, crate type `cdylib`, edition 2024) |
| `spin.toml` | the Spin app: one component on `/...`, built with `cargo build --target wasm32-wasip2 --release` |

The runtime's versions are SDK generations side by side: **4.2** is Spin Rust SDK 8 (this
example), **4.1** Spin Rust SDK 5 (a synchronous `#[http_component] fn handle(req: Request)`,
`wasm32-wasip1`). Code of one does not build on the other: SDK 6 made the SDK async on WASIp3, so
moving a 4.1 project to 4.2 means `#[http_service] async fn`, the `http` crate's request
(`req.headers()`, `req.uri().query()`, `req.into_body().bytes().await` for the body) and
`Response::builder()…body(…)?`, as `lib.rs` here does. See [docs/wasm.md](../../../docs/wasm.md#versions).

## In RunR

1. The region needs WebAssembly support: **Settings → Regions → (region) → Isolation → WebAssembly → Install**.
2. Create a project in that region, pick **Rust (Wasm)** (the runtime picker marks it **Wasm**)
   with version **4.2** (the default); its starter code is this `lib.rs`.
3. XaaS: paste `lib.rs` into the editor and save. FaaS: upload all three files — your own
   `Cargo.toml` can add crates (keep the package name, or change `source` in `spin.toml` with it).
4. Deploy and call it:

```bash
curl "https://<project>.<region>.<domain>/?name=RunR"
# Hello, RunR! (from Rust, Wasm)
```

Switch the trigger to **schedule** and each run logs `tick from Rust (Wasm)`; to **queue**, and
an entry `msg=hi` is answered with `processed {"msg":"hi"} (from Rust, Wasm)`.

## Locally

```bash
rustup target add wasm32-wasip2   # Rust 1.94 or later
spin build && spin up             # Spin 4.1 or later
curl "http://127.0.0.1:3000/?name=RunR"
```
