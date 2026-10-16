# runr-sandbox

Python client for the **RunR Sandbox API**: start isolated sandboxes on your own Kubernetes cluster,
run commands and code in them, move files in and out, and remove them again. Every sandbox is a pod
under the strongest isolation the region offers (Kata Containers or gVisor), without network access
unless you allow it.

```bash
pip install runr-sandbox
```

Python 3.9 or newer. Dependencies: [httpx](https://www.python-httpx.org/) and, for the exec
WebSocket, [websockets](https://websockets.readthedocs.io/).

## Configuration

| Argument | Environment variable | Meaning |
|---|---|---|
| `api_url` | `RUNR_API_URL` | your RunR address, e.g. `https://runr.example.com` (`/api` is appended) |
| `api_key` | `RUNR_API_KEY` | an API token (Settings → API Tokens) |
| `tenant_id` | `RUNR_TENANT_ID` | the organization, on multi-tenant installations (sent as `X-Tenant-ID`) |
| `timeout` | | HTTP timeout in seconds (default 300) |

```bash
export RUNR_API_URL=https://runr.example.com
export RUNR_API_KEY=...
```

## Quick start

```python
from runr_sandbox import Sandbox

with Sandbox.create(runtime="python", ttl_seconds=600) as sandbox:
    result = sandbox.exec("python3 --version")
    print(result.exit_code, result.stdout)
# leaving the block removes the sandbox
```

`Sandbox.create()` accepts:

| Option | Example | Meaning |
|---|---|---|
| `runtime`, `version` | `"python"`, `"3.14"` | a runtime of the RunR catalog (default `python`, its default version) |
| `image` | `"docker.io/library/alpine:3.21"` | any image instead of a runtime (it needs `/bin/sh`; `tar` for directory transfer) |
| `cpu`, `memory`, `disk` | `"500m"`, `512`, `"1Gi"` | resources; memory and disk in MiB or as a quantity |
| `ttl_seconds`, `idle_timeout` | `900`, `300` | lifetime and idle timeout in seconds |
| `network` | `"none"`, `["pypi.org:443", "10.0.0.0/8"]` | no traffic (default) or an egress allowlist of hosts / CIDRs |
| `env` | `{"API_KEY": "..."}` | environment of every command |
| `files` | `{"/workspace/main.py": "print(1)"}` | files written before the sandbox is returned (`str` or `bytes`) |
| `region`, `sandbox_class` | `"eu-1"`, `"gvisor"` | where and under which isolation (`auto`, `kata`, `kata-fc`, `gvisor`, …) |
| `read_only_root`, `pids_limit` | `True`, `256` | hardening options |
| `metadata` | `{"agent": "planner"}` | your labels, filterable in `Sandbox.list(metadata=...)` |
| `wait`, `start_timeout` | `True`, `180` | wait until the sandbox runs (a sandbox that does not start in time is removed) |

Attach to an existing sandbox with `Sandbox.connect(sandbox_id)` and list them with
`Sandbox.list(status="active", metadata={"agent": "planner"})`.

## Commands

```python
result = sandbox.exec("ls -la /workspace")            # shell string
result = sandbox.exec(["python3", "-c", "print(42)"])  # argv list
result = sandbox.exec("cat > in.txt", stdin="data", timeout=30, env={"A": "1"}, cwd="/workspace")

result.ok          # exit_code == 0 and not timed_out
result.exit_code   # 137 when the timeout killed it
result.stdout      # text; result.stdout_bytes for the raw bytes
```

### Streaming

```python
import sys

for event in sandbox.exec_stream("pip install --user requests"):
    if event.type == "stdout":
        print(event.text, end="")
    elif event.type == "stderr":
        print(event.text, end="", file=sys.stderr)
    elif event.type == "exit":
        print("exit code", event.exit_code)
```

Output arrives as Server-Sent Events while the command runs; `event.data` holds the bytes.

### Interactive commands: the exec WebSocket

`exec_ws()` runs a command over the exec WebSocket. The session sends input while the command
runs, so an interactive program reads each line as it is sent:

```python
with sandbox.exec_ws(["python3", "-i", "-q"]) as session:
    session.send_stdin("x = 6 * 7\n")
    session.send_stdin("print(x)\n")
    session.close_stdin()                 # end of input: the interpreter exits
    result = session.wait()               # exit code and the collected output
    print(result.exit_code, result.stdout)
```

Iterate over the session to read the output as it arrives (`stdout` / `stderr` events, then one
`exit` event); `send_stdin()` may be called from another thread meanwhile. `kill()` stops the
command (its exit event has `killed` set, exit code 137); leaving the block closes the connection,
which stops a command that still runs.

With `tty=True` the command runs on a terminal of `cols` × `rows` (default 80 × 24): programs see a
TTY (prompts, colours, line editing), stdout and stderr arrive together as `stdout` events with
terminal line endings, `resize()` changes the size, and `close_stdin()` sends Ctrl+D:

```python
import shutil

cols, rows = shutil.get_terminal_size()
with sandbox.exec_ws("bash", tty=True, cols=cols, rows=rows) as session:
    session.send_stdin("ls --color=auto /workspace\n")
    session.resize(120, 40)
    session.send_stdin("exit\n")
    for event in session:
        if event.type == "stdout":
            print(event.text, end="")
```

`exec_ws()` takes the options of `exec()` (`stdin`, `timeout`, `env`, `cwd`), or `code` with
`language` instead of a command. The WebSocket opens on the host of `RUNR_API_URL` (`wss://` for
`https`) with a one-time token, which the SDK never logs; `ssl_context` sets its TLS settings
(default: the CA bundle of `SSL_CERT_FILE` / `SSL_CERT_DIR`, else certifi's). `AsyncSandbox` has
the same as `async with sandbox.exec_ws(...) as session:`, with `await session.send_stdin(...)`
and `async for event in session`.

## Running code

```python
result = sandbox.run_code("""
import matplotlib.pyplot as plt
plt.plot([1, 2, 3], [1, 4, 9])
plt.savefig("/output/plot.png")
print("done")
""", language="python")

print(result.stdout)              # "done\n"
print(result.files_changed)       # [FileChange(path='/output/plot.png', size=...)]
for output in result.outputs:     # images saved under /output
    output.save("plot.png")
```

`language` is `python`, `javascript`, `typescript`, `bash`, `sh`, `php`, `ruby`, `go` or the id of a
runtime that runs code through an `exec` command; by default the sandbox's own runtime.
`run_code_stream()` streams the output and ends with a `result` event.

## Files

```python
sandbox.files.write("/workspace/data.csv", "a,b\n1,2\n")
sandbox.files.write("/workspace/run.sh", "#!/bin/sh\necho hi\n", mode=0o755)
data = sandbox.files.read("/workspace/data.csv")           # bytes
text = sandbox.files.read_text("/workspace/data.csv")
for entry in sandbox.files.list("/workspace"):
    print(entry.type, entry.size, entry.path)
sandbox.files.mkdir("/workspace/src")
sandbox.files.remove("/workspace/src", recursive=True)

sandbox.files.upload_dir("./project", "/workspace/project")      # as one tar archive
sandbox.files.download_dir("/workspace/project", "./result")
sandbox.files.upload_files({"a.txt": "A", "sub/b.bin": b"\x00"}, "/workspace")
```

Writable places are `/workspace` (the working directory), `/output`, `/tmp` and `/home/sandbox`.

## Background processes

```python
server = sandbox.processes.start("python3 -m http.server 8000", cwd="/workspace")
print(sandbox.processes.list())
print(sandbox.processes.logs(server.id).stdout)
for event in sandbox.processes.follow(server.id):   # until the process ends
    print(event.text, end="")
sandbox.processes.kill(server.id, signal="TERM")
```

## Lifetime

```python
sandbox.set_timeout(1800)      # live 30 more minutes (capped by the region's maximum)
sandbox.keepalive()            # reset the idle timer
sandbox.pause(); sandbox.resume()
sandbox.set_network(["api.example.com:443"])
sandbox.kill()                 # remove now; close() also releases the HTTP connections
```

## asyncio

`AsyncSandbox` has the same methods as coroutines and async iterators:

```python
import asyncio
from runr_sandbox import AsyncSandbox

async def main():
    async with AsyncSandbox.create(runtime="nodejs") as sandbox:
        async for event in sandbox.exec_stream(["node", "-e", "console.log(1 + 1)"]):
            print(event.type, event.text)

asyncio.run(main())
```

## Example: an AI agent code interpreter

A tool an LLM agent can call: it runs the model's code in a sandbox that lives for the whole
conversation and returns what the code printed plus any charts it drew.

```python
import base64
from runr_sandbox import Sandbox

class CodeInterpreter:
    """One sandbox per conversation; the agent calls run() as a tool."""

    tool_schema = {
        "name": "run_python",
        "description": "Run Python code in an isolated sandbox. Save charts to /output/*.png.",
        "input_schema": {
            "type": "object",
            "properties": {"code": {"type": "string", "description": "Python source code"}},
            "required": ["code"],
        },
    }

    def __init__(self):
        self.sandbox = Sandbox.create(
            runtime="python",
            ttl_seconds=3600,
            idle_timeout=900,
            network=["pypi.org:443", "files.pythonhosted.org:443"],  # pip only
            metadata={"purpose": "code-interpreter"},
        )

    def run(self, code: str) -> dict:
        result = self.sandbox.run_code(code, language="python", timeout=120)
        return {
            "exit_code": result.exit_code,
            "timed_out": result.timed_out,
            "stdout": result.stdout[-20000:],
            "stderr": result.stderr[-20000:],
            "files_changed": [f.path for f in result.files_changed],
            "images": [
                {"path": o.path, "media_type": o.mime, "data": base64.b64encode(o.data).decode()}
                for o in result.outputs
            ],
        }

    def close(self):
        self.sandbox.close()


interpreter = CodeInterpreter()
try:
    # In the agent loop: when the model calls the tool, pass its input on and return the result
    observation = interpreter.run("import math\nprint(math.factorial(20))")
    print(observation["stdout"])
finally:
    interpreter.close()
```

The code runs under the region's sandbox isolation, as a non-root user with no service-account
token, inside the CPU, memory, disk and process limits you set; with the allowlist above it can
reach the package index and nothing else.

## Errors

All exceptions derive from `RunrSandboxError` (`status`, `message`, `reason`, `body`):

| Exception | When |
|---|---|
| `AuthError` | 401 / 403 — no token, an invalid one, or a token without the ability |
| `NotFoundError` | 404 — unknown sandbox, process or file |
| `ConflictError` | 409 — the sandbox is not running (ended or paused) |
| `QuotaError` | 422 with a `quota_*` reason — e.g. `quota_concurrent`, `quota_cpu`, `quota_seconds` |
| `ValidationError` | other 422 answers — invalid options, unavailable region, runtime or sandbox class |
| `PayloadTooLargeError` | 413 — a file above the upload / download limit |
| `RateLimitError` | 429 — `retry_after` holds the seconds to wait |
| `SandboxStartTimeout` | the sandbox did not run within `start_timeout` (also a `TimeoutError`) |

On the exec WebSocket, `RunrSandboxError` carries the reason `exec_error` when the API ends the
command with an error message, `ws_closed` when the connection ends before the exit event, and
`ws_error`, `ws_handshake` or `ws_timeout` when it cannot be opened; a refused upgrade maps its
HTTP status to the classes above.

## Development

```bash
pip install -e '.[test]'
pytest
```

The tests run against an in-process fake of the API (`tests/fake_server.py`), which serves the
exec WebSocket with its own RFC 6455 framing.

## License

MIT
