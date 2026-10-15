# Rust on WebAssembly (`wasm-rust`)

A Spin HTTP component in Rust (Spin Rust SDK 5.2, `wasm32-wasip1`) that answers every RunR
trigger: HTTP requests, queue messages and schedule ticks (both arrive as CloudEvents).

| File | Role |
|---|---|
| `lib.rs` | the handler — the only file an XaaS project needs |
| `Cargo.toml` | the crate (RunR's default; `[lib] path = "lib.rs"`, crate type `cdylib`) |
| `spin.toml` | the Spin app: one component on `/...`, built with `cargo build --target wasm32-wasip1 --release` |

## In RunR

1. The region needs WebAssembly support: **Settings → Regions → (region) → Isolation → WebAssembly → Install**.
2. Create a project in that region and pick **Rust (Wasm)** (the runtime picker marks it **Wasm**).
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
rustup target add wasm32-wasip1
spin build && spin up          # Spin 4.1 or later
curl "http://127.0.0.1:3000/?name=RunR"
```
