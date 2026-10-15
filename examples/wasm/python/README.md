# Python on WebAssembly (`wasm-python`)

A Spin HTTP component in Python (componentize-py 0.25 + Spin Python SDK 5.0: CPython compiled to
WebAssembly, bundled with the app's modules) that answers every RunR trigger: HTTP requests,
queue messages and schedule ticks (both arrive as CloudEvents).

| File | Role |
|---|---|
| `app.py` | the handler: `class HttpHandler(http.Handler)` with `async def handle_request(self, request)` |
| `spin.toml` | the Spin app: one component on `/...`, built with componentize-py |
| `requirements.txt` (FaaS, optional) | pure-Python packages, installed during the build and bundled into the component |

Packages are bundled as Python source: pure-Python packages work; packages with native
extensions (C modules such as `numpy` wheels for Linux) are built for a Linux CPU, not for
WebAssembly, and are left to the regular `python` runtime.

## In RunR

1. The region needs WebAssembly support: **Settings → Regions → (region) → Isolation → WebAssembly → Install**.
2. Create a project in that region and pick **Python (Wasm)**.
3. XaaS: paste `app.py` and save. FaaS: upload `app.py` (and a `requirements.txt`).
4. Deploy and call it: `curl "https://<project>.<region>.<domain>/?name=RunR"` →
   `Hello, RunR! (from Python, Wasm)`.

## Locally

```bash
python -m venv .venv && . .venv/bin/activate
pip install componentize-py==0.25.1 spin-sdk==5.0.0
spin build && spin up          # Spin 4.1 or later
curl "http://127.0.0.1:3000/?name=RunR"
```
