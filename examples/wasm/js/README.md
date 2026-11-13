# JavaScript / TypeScript on WebAssembly (`wasm-js`)

A Spin HTTP component in JavaScript (Spin JS SDK: esbuild bundle, componentized with
StarlingMonkey) that answers every RunR trigger: HTTP requests, queue messages and schedule
ticks (both arrive as CloudEvents). The handler uses the web `Request` / `Response` API through a
`fetch` event listener. Its build files are version **4.2**'s of the runtime (esbuild 0.28, the
default of new projects); the handler is the same on 4.1 (esbuild 0.25), so `index.js` builds on
both.

| File | Role |
|---|---|
| `index.js` | the handler — the only file an XaaS project needs (`index.ts` works the same way in a FaaS project) |
| `package.json` | dependencies (RunR's default of 4.2: `@spinframework/wasi-http-proxy`, build tools, esbuild 0.28) |
| `build.mjs` | bundles `index.ts` / `index.mjs` / `index.js` and componentizes it into `dist/app.wasm` |
| `spin.toml` | the Spin app: one component on `/...`, built with `node build.mjs` |

## In RunR

1. The region needs WebAssembly support: **Settings → Regions → (region) → Isolation → WebAssembly → Install**.
2. Create a project in that region and pick **JavaScript (Wasm)**, version **4.2** (the default;
   4.1 builds the same handler with esbuild 0.25).
3. XaaS: paste `index.js` and save. FaaS: upload the files; a `package.json` of your own is
   installed during the build (packages that only use web APIs bundle into the component;
   Node.js built-ins such as `fs` or `child_process` are not part of the component model).
4. Deploy and call it: `curl "https://<project>.<region>.<domain>/?name=RunR"` →
   `Hello, RunR! (from JavaScript, Wasm)`.

## Locally

```bash
npm install
spin build && spin up          # Node.js 22, Spin 4.1 or later
curl "http://127.0.0.1:3000/?name=RunR"
```
