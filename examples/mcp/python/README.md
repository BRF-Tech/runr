# MCP server starter — Python

`server.py` is a Model Context Protocol server on the official
[MCP Python SDK](https://github.com/modelcontextprotocol/python-sdk) (FastMCP): Streamable HTTP
at `/mcp` on `$PORT`, stateless, behind the RunR MCP gateway
([docs/mcp.md](../../../docs/mcp.md#hosting-mcp-servers)).

| File | |
|---|---|
| `runtime.yaml` | the `mcp-python` runtime: image `ghcr.io/runrsh/reqs/python:3.13-slim`, dependencies from `requirements.txt` into `/app/vendor`, `run.command` `python -u /app/code/server.py` (http mode), starter template `server.py` |
| `server.py` | tools `hello`, `add`, `whoami`, resource `starter://about`, prompt `greet`; `GatewaySignature` checks `X-RunR-Signature` |
| `requirements.txt` | `mcp`, `uvicorn` |
| `Dockerfile` | the same server as an image for a CaaS project |

## Deploy on RunR

**Projects → New MCP server → Python**. RunR creates a FaaS project on `mcp-python` with these
files; the first save builds the dependencies into the version's image. Deploy it, then connect:

```bash
claude mcp add --transport http my-server https://runr.example.com/mcp/servers/<project slug>
```

Keep `requirements.txt` in the project: without the dependency file the version is not built
and the image has no MCP SDK.

## Add a tool

```python
@mcp.tool()
def forecast(city: str, days: int = 3) -> dict:
    """Weather forecast for a city."""
    return {"city": city, "days": days, "summary": "sunny"}
```

Save (a new version), deploy, and the tool shows up in `tools/list`. Tools that need the
caller take `ctx: Context` and read `identity_of(ctx)`:

```python
@mcp.tool()
def my_projects(ctx: Context) -> str:
    identity = identity_of(ctx) or {}
    user = (identity.get("user") or {}).get("name", "anonymous")
    return f"Hello {user}, authenticated by {identity.get('auth')}"
```

## Run locally

```bash
python -m venv .venv && . .venv/bin/activate
pip install -r requirements.txt
PORT=8080 python server.py
npx @modelcontextprotocol/inspector   # connect to http://localhost:8080/mcp (Streamable HTTP)
```

Without `RUNR_MCP_GATEWAY_KEY` the server accepts unsigned requests. To try the check locally,
set a key and sign a request the way the gateway does:

```bash
export RUNR_MCP_GATEWAY_KEY=local-test-key
PORT=8080 python server.py &
python - <<'EOF'
import base64, hashlib, hmac, json, os, time, urllib.request
key = os.environ["RUNR_MCP_GATEWAY_KEY"]
body = json.dumps({"jsonrpc": "2.0", "id": 1, "method": "tools/list"}).encode()
identity = base64.urlsafe_b64encode(json.dumps({"auth": "token", "user": {"id": "1", "name": "Ada"}}).encode()).decode().rstrip("=")
t = int(time.time())
signed = f"{t}.POST\n/mcp\n{hashlib.sha256(body).hexdigest()}\n{identity}".encode()
sig = hmac.new(key.encode(), signed, hashlib.sha256).hexdigest()
req = urllib.request.Request("http://localhost:8080/mcp", data=body, method="POST", headers={
    "Content-Type": "application/json", "Accept": "application/json, text/event-stream",
    "X-RunR-Identity": identity, "X-RunR-Signature": f"t={t},v1={sig}"})
print(urllib.request.urlopen(req).read().decode())
EOF
```

## As your own image

```bash
docker build -t registry.example.com/acme/mcp-python:1.0 .
docker push registry.example.com/acme/mcp-python:1.0
```

Then **Projects → New MCP server → Container image** with that image and port `8080`.
