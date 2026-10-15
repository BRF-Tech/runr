# MCP server starter — TypeScript

`server.ts` is a Model Context Protocol server on the official
[TypeScript SDK](https://github.com/modelcontextprotocol/typescript-sdk)
(`@modelcontextprotocol/sdk`): Streamable HTTP at `/mcp` on `$PORT`, stateless (a new server
and transport per request), behind the RunR MCP gateway
([docs/mcp.md](../../../docs/mcp.md#hosting-mcp-servers)).

| File | |
|---|---|
| `runtime.yaml` | the `mcp-node` runtime: image `ghcr.io/runrsh/reqs/node:22-slim`, dependencies from `package.json` into `/app/code/node_modules`, `run.command` `node --experimental-strip-types --disable-warning=ExperimentalWarning /app/code/server.ts` (http mode), starter template `server.ts` |
| `server.ts` | tools `hello`, `add`, `whoami`, resource `starter://about`, prompt `greet`; `verifySignature` checks `X-RunR-Signature` |
| `package.json` | `@modelcontextprotocol/sdk`, `zod`; `"type": "module"` |
| `Dockerfile` | the same server as an image for a CaaS project |

Node runs the TypeScript file directly (type stripping), so there is no build step for the
code — only `npm install` for the dependencies. Type stripping removes types; it does not
transform syntax, so keep to erasable TypeScript: types, interfaces, `as`, no `enum`, no
`namespace`, no constructor parameter properties, and import local files with their `.ts`
extension.

## Deploy on RunR

**Projects → New MCP server → TypeScript**. RunR creates a FaaS project on `mcp-node` with these
files; the first save builds the dependencies into the version's image. Deploy it, then connect:

```bash
claude mcp add --transport http my-server https://runr.example.com/mcp/servers/<project slug>
```

## Add a tool

```ts
server.registerTool(
  "forecast",
  {
    title: "Weather forecast",
    description: "Weather forecast for a city.",
    inputSchema: { city: z.string(), days: z.number().int().min(1).max(7).default(3) },
  },
  async ({ city, days }) => ({ content: [{ type: "text", text: `${city}: sunny for ${days} days` }] }),
);
```

`buildServer(identity)` receives the caller the gateway identified (`identity.user`,
`identity.auth`, `identity.scopes`), so tools can act on behalf of that user.

## Run locally

```bash
npm install
PORT=8080 npm start
npx @modelcontextprotocol/inspector   # connect to http://localhost:8080/mcp (Streamable HTTP)
```

Without `RUNR_MCP_GATEWAY_KEY` the server accepts unsigned requests; with it set, only requests
signed like the gateway does (see the Python README for a signing snippet) get through.

## As your own image

```bash
docker build -t registry.example.com/acme/mcp-node:1.0 .
docker push registry.example.com/acme/mcp-node:1.0
```

Then **Projects → New MCP server → Container image** with that image and port `8080`.
