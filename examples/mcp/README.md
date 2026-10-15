# MCP server examples

Starters for MCP servers hosted on RunR ([docs/mcp.md](../../docs/mcp.md#hosting-mcp-servers)).
Each directory holds a runtime manifest whose starter template is the server, the server
itself, its dependency file and a Dockerfile for the bring-your-own-image route.

| Starter | Directory | Runtime id | SDK | Entry file |
|---|---|---|---|---|
| Python | [`python/`](python/) | `mcp-python` | official MCP Python SDK (FastMCP) | `server.py` |
| TypeScript | [`node/`](node/) | `mcp-node` | official TypeScript SDK (`@modelcontextprotocol/sdk`) | `server.ts` |

Both serve Streamable HTTP at `/mcp` on `$PORT`, run statelessly (every request stands on its
own, so Knative can scale them out) and check the signature of the RunR MCP gateway: with
`RUNR_MCP_GATEWAY_KEY` set — RunR sets it on every hosted MCP server — a request that did not
come through the gateway gets `401`. The caller the gateway identified arrives in
`X-RunR-Identity`; the `whoami` tool of each starter returns it.

## One click in the console

**Projects → New MCP server** → pick Python or TypeScript, a region and the access mode. The
first server of a runtime registers the runtime (holders of `runtimes.manage`, or of
`runtimes.use_custom` while custom runtimes are allowed; for everyone else an administrator
does it once). RunR creates a FaaS
project with the starter files, serves it at `https://<console>/mcp/servers/<project slug>` and
opens its **MCP** tab: endpoint, client snippets, inspector and call log. Deploy the project and
connect a client:

```bash
claude mcp add --transport http weather https://runr.example.com/mcp/servers/weather
```

## From an agent

The RunR MCP server has a tool for it: `mcp_server_create` with `template: "python"` (or
`"node"`, or `"image"` with an image and port) creates, configures and deploys the server and
reports the progress.

## Bring your own image

An existing MCP server image runs as a CaaS project: **New MCP server → Container image**, or
create a CaaS project with the HTTP trigger and turn it into an MCP server in its **MCP** tab
(path of the MCP endpoint, default `/mcp`). The Dockerfiles here build the starters that way:

```bash
docker build -t registry.example.com/acme/mcp-starter:1.0 examples/mcp/python
docker push registry.example.com/acme/mcp-starter:1.0
```

Check `X-RunR-Signature` in your own server the way `server.py` / `server.ts` do
([signature reference](../../docs/mcp.md#gateway-headers-and-signature)), so that the gateway
is the only way in.

## Register a runtime yourself

```bash
TOKEN=...   # an API token with runtimes.manage or runtimes.use_custom
curl -sS https://runr.example.com/api/mcp-servers/templates -H "Authorization: Bearer $TOKEN" \
  | python3 -c 'import json,sys; t=[t for t in json.load(sys.stdin)["data"] if t["id"]=="python"][0]; print(json.dumps({"manifest": t["runtime_manifest"]}))' \
  | curl -sS -X POST https://runr.example.com/api/runtimes -H "Authorization: Bearer $TOKEN" \
      -H 'Content-Type: application/json' -d @-
```

`runtime.yaml` references the starter files as `"@server.py"`; the API bundles the manifests
with these files inlined (`php artisan mcp:templates-bundle`, `api/resources/mcp/templates.json`).
