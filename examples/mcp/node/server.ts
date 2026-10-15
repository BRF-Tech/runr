/**
 * RunR MCP server starter (TypeScript).
 *
 * A Model Context Protocol server on the official TypeScript SDK (@modelcontextprotocol/sdk),
 * served over Streamable HTTP at /mcp on $PORT. Node runs this file directly
 * (--experimental-strip-types), so it uses erasable TypeScript only: types and interfaces, no
 * enums or namespaces.
 *
 * RunR puts its gateway in front: clients connect to https://<console>/mcp/servers/<handle>,
 * the gateway checks their access (public, RunR token or OAuth) and forwards every request here
 * with two headers:
 *
 *   X-RunR-Identity   base64url JSON of the caller: {auth, user, tenant, token_id, client_id, scopes}
 *   X-RunR-Signature  t=<unix seconds>,v1=<hex>[,v1=<hex>]
 *                     hex = HMAC-SHA256(RUNR_MCP_GATEWAY_KEY,
 *                           "<t>." + METHOD + "\n" + PATH + "\n" + sha256(body) + "\n" + identity)
 *
 * RUNR_MCP_GATEWAY_KEY reaches the container as a secret environment variable. With the key set,
 * requests without a valid signature get 401, so the function URL itself cannot be used to get
 * around the gateway. Without the key (running on your machine) requests pass unchecked.
 *
 * Every request gets its own server and transport (stateless mode), so any replica can answer
 * it when Knative scales the server out.
 */
import { createHash, createHmac, timingSafeEqual } from "node:crypto";
import { createServer, type IncomingMessage, type ServerResponse } from "node:http";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { z } from "zod";

const GATEWAY_KEY = process.env.RUNR_MCP_GATEWAY_KEY ?? "";
const SIGNATURE_TOLERANCE = 300; // seconds between the gateway's timestamp and this clock
const MCP_PATH = "/mcp";
const MAX_BODY_BYTES = 4 * 1024 * 1024;

interface Identity {
  auth: "public" | "token" | "oauth" | "console";
  user: { id: string; name?: string; email?: string } | null;
  tenant: string | null;
  token_id: string | null;
  client_id: string | null;
  scopes: string[];
}

// ── Tools, resources and prompts ─────────────────────────────────────────────

function buildServer(identity: Identity | null): McpServer {
  const server = new McpServer({ name: "runr-mcp-starter", version: "1.0.0" });

  server.registerTool(
    "hello",
    {
      title: "Say hello",
      description: "Greets someone by name.",
      inputSchema: { name: z.string().default("World").describe("Who to greet") },
    },
    async ({ name }) => ({ content: [{ type: "text", text: `Hello, ${name}! (from RunR)` }] }),
  );

  server.registerTool(
    "add",
    {
      title: "Add two numbers",
      description: "Adds two numbers.",
      inputSchema: { a: z.number(), b: z.number() },
    },
    async ({ a, b }) => ({ content: [{ type: "text", text: String(a + b) }] }),
  );

  server.registerTool(
    "whoami",
    {
      title: "Who am I",
      description: "Who is calling: the identity the RunR gateway forwarded for this request.",
      inputSchema: {},
    },
    async () => {
      const result = identity ?? { auth: "none", note: "This request did not come through the RunR gateway." };
      return { content: [{ type: "text", text: JSON.stringify(result, null, 2) }] };
    },
  );

  server.registerResource(
    "about",
    "starter://about",
    { title: "About this server", description: "What this server is.", mimeType: "text/plain" },
    async (uri) => ({
      contents: [{ uri: uri.href, text: "A RunR MCP starter: tools hello, add and whoami; edit server.ts to add your own." }],
    }),
  );

  server.registerPrompt(
    "greet",
    {
      title: "Greet someone",
      description: "Asks the model to greet someone with the hello tool.",
      argsSchema: { name: z.string() },
    },
    ({ name }) => ({
      messages: [
        {
          role: "user",
          content: { type: "text", text: `Use the hello tool to greet ${name}, then tell them which RunR user called the server (whoami).` },
        },
      ],
    }),
  );

  return server;
}

// ── Gateway identity and signature ───────────────────────────────────────────

function decodeIdentity(header: string): Identity | null {
  if (!header) return null;
  try {
    return JSON.parse(Buffer.from(header, "base64url").toString("utf8")) as Identity;
  } catch {
    return null;
  }
}

function verifySignature(method: string, path: string, body: Buffer, identity: string, header: string): boolean {
  let timestamp: number | null = null;
  const signatures: string[] = [];
  for (const part of header.split(",")) {
    const [key, value = ""] = part.trim().split("=", 2);
    if (key === "t" && /^\d+$/.test(value)) timestamp = Number(value);
    else if (key === "v1" && value) signatures.push(value.toLowerCase());
  }
  if (timestamp === null || signatures.length === 0 || Math.abs(Date.now() / 1000 - timestamp) > SIGNATURE_TOLERANCE) {
    return false;
  }

  const bodyHash = createHash("sha256").update(body).digest("hex");
  const expected = Buffer.from(
    createHmac("sha256", GATEWAY_KEY).update(`${timestamp}.${method}\n${path}\n${bodyHash}\n${identity}`).digest("hex"),
  );
  return signatures.some((candidate) => {
    const given = Buffer.from(candidate);
    return given.length === expected.length && timingSafeEqual(given, expected);
  });
}

async function readBody(req: IncomingMessage): Promise<Buffer | null> {
  const chunks: Buffer[] = [];
  let size = 0;
  for await (const chunk of req) {
    size += (chunk as Buffer).length;
    if (size > MAX_BODY_BYTES) return null;
    chunks.push(chunk as Buffer);
  }
  return Buffer.concat(chunks);
}

function send(res: ServerResponse, status: number, body: string, type = "text/plain"): void {
  res.writeHead(status, { "Content-Type": type }).end(body);
}

// ── HTTP ─────────────────────────────────────────────────────────────────────

async function handle(req: IncomingMessage, res: ServerResponse): Promise<void> {
  const path = new URL(req.url ?? "/", "http://localhost").pathname;

  // Knative probes and the health check need no signature
  if (req.headers["k-network-probe"] || req.headers["k-kubelet-probe"] || (req.method === "GET" && (path === "/" || path === "/healthz"))) {
    send(res, 200, "ok");
    return;
  }

  if (path !== MCP_PATH) {
    send(res, 404, "not found");
    return;
  }

  // Stateless mode: no session to stream to or to end
  if (req.method !== "POST") {
    res.writeHead(405, { Allow: "POST", "Content-Type": "application/json" }).end(
      JSON.stringify({ jsonrpc: "2.0", error: { code: -32000, message: "Method not allowed." }, id: null }),
    );
    return;
  }

  const body = await readBody(req);
  if (body === null) {
    send(res, 413, "request too large");
    return;
  }

  const identityHeader = String(req.headers["x-runr-identity"] ?? "");
  if (GATEWAY_KEY && !verifySignature(req.method, path, body, identityHeader, String(req.headers["x-runr-signature"] ?? ""))) {
    send(res, 401, JSON.stringify({ error: "requests must come through the RunR gateway" }), "application/json");
    return;
  }

  let message: unknown;
  try {
    message = JSON.parse(body.toString("utf8"));
  } catch {
    res.writeHead(400, { "Content-Type": "application/json" }).end(
      JSON.stringify({ jsonrpc: "2.0", error: { code: -32700, message: "Parse error" }, id: null }),
    );
    return;
  }

  const server = buildServer(decodeIdentity(identityHeader));
  const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
  res.on("close", () => {
    void transport.close();
    void server.close();
  });
  await server.connect(transport);
  await transport.handleRequest(req, res, message);
}

function main(): void {
  const port = Number(process.env.PORT ?? "8080");
  if (!GATEWAY_KEY) {
    console.log("RUNR_MCP_GATEWAY_KEY is not set: requests are not checked for the gateway signature");
  }
  createServer((req, res) => {
    handle(req, res).catch((error) => {
      console.error("request failed:", error);
      if (!res.headersSent) send(res, 500, "internal error");
      else res.end();
    });
  }).listen(port, "0.0.0.0", () => console.log(`RunR MCP starter: Streamable HTTP on :${port}${MCP_PATH}`));
}

main();
