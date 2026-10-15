"""RunR MCP server starter (Python).

A Model Context Protocol server on the official MCP Python SDK (FastMCP), served over
Streamable HTTP at /mcp on $PORT. RunR runs it as a project and puts its gateway in front:
clients connect to https://<console>/mcp/servers/<handle>, the gateway checks their access
(public, RunR token or OAuth) and forwards every request here with two headers:

  X-RunR-Identity   base64url JSON of the caller: {"auth", "user", "tenant", "token_id",
                    "client_id", "scopes"}
  X-RunR-Signature  t=<unix seconds>,v1=<hex>[,v1=<hex>]
                    hex = HMAC-SHA256(RUNR_MCP_GATEWAY_KEY,
                          "<t>." + METHOD + "\n" + PATH + "\n" + sha256(body) + "\n" + identity)

RUNR_MCP_GATEWAY_KEY reaches the container as a secret environment variable. With the key set,
GatewaySignature below answers 401 to every request that does not carry a valid signature, so
the function URL itself cannot be used to get around the gateway. Without the key (running on
your machine) requests pass unchecked.

Edit the tools, resources and prompts below; save to create a new version and deploy it.
"""

import base64
import hashlib
import hmac
import json
import os
import time
from contextvars import ContextVar

import uvicorn
from mcp.server.fastmcp import Context, FastMCP

GATEWAY_KEY = os.environ.get("RUNR_MCP_GATEWAY_KEY", "")
SIGNATURE_TOLERANCE = 300  # seconds between the gateway's timestamp and this clock

# The caller of the current request, as the gateway identified it (None outside the gateway)
current_identity: ContextVar[dict | None] = ContextVar("runr_identity", default=None)

# host="0.0.0.0": listen for the gateway, not only on localhost. stateless_http: every request
# stands on its own, so any replica can answer it when Knative scales the server out.
mcp = FastMCP("RunR MCP starter", host="0.0.0.0", stateless_http=True)


# ── Tools ────────────────────────────────────────────────────────────────────


@mcp.tool()
def hello(name: str = "World") -> str:
    """Greets someone by name."""
    return f"Hello, {name}! (from RunR)"


@mcp.tool()
def add(a: float, b: float) -> float:
    """Adds two numbers."""
    return a + b


@mcp.tool()
def whoami(ctx: Context) -> dict:
    """Who is calling: the identity the RunR gateway forwarded for this request."""
    identity = identity_of(ctx)
    if identity is None:
        return {"auth": "none", "note": "This request did not come through the RunR gateway."}
    return identity


# ── Resources and prompts ────────────────────────────────────────────────────


@mcp.resource("starter://about")
def about() -> str:
    """What this server is."""
    return "A RunR MCP starter: tools hello, add and whoami; edit server.py to add your own."


@mcp.prompt()
def greet(name: str) -> str:
    """A prompt that asks the model to greet someone with the hello tool."""
    return f"Use the hello tool to greet {name}, then tell them which RunR user called the server (whoami)."


# ── Gateway identity and signature ───────────────────────────────────────────


def decode_identity(header: str) -> dict | None:
    if not header:
        return None
    try:
        padded = header + "=" * (-len(header) % 4)
        return json.loads(base64.urlsafe_b64decode(padded.encode()).decode())
    except (ValueError, json.JSONDecodeError):
        return None


def identity_of(ctx: Context) -> dict | None:
    """The identity of the request a tool runs for (request headers, else the context variable)."""
    request = getattr(getattr(ctx, "request_context", None), "request", None)
    headers = getattr(request, "headers", None)
    if headers is not None:
        return decode_identity(headers.get("x-runr-identity", ""))
    return current_identity.get()


def verify_signature(method: str, path: str, body: bytes, identity: str, header: str) -> bool:
    """X-RunR-Signature check: any v1 matches and the timestamp is fresh."""
    timestamp = None
    signatures = []
    for part in header.split(","):
        key, _, value = part.strip().partition("=")
        if key == "t" and value.isdigit():
            timestamp = int(value)
        elif key == "v1" and value:
            signatures.append(value.lower())
    if timestamp is None or not signatures or abs(time.time() - timestamp) > SIGNATURE_TOLERANCE:
        return False

    signed = f"{timestamp}.{method}\n{path}\n{hashlib.sha256(body).hexdigest()}\n{identity}".encode()
    expected = hmac.new(GATEWAY_KEY.encode(), signed, hashlib.sha256).hexdigest()
    return any(hmac.compare_digest(expected, candidate) for candidate in signatures)


class GatewaySignature:
    """ASGI middleware: only requests signed by the RunR gateway reach the MCP app.

    The body is read once to check its hash and then handed to the app unchanged. Knative probes
    and GET / (health) are answered here without a signature.
    """

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http":
            await self.app(scope, receive, send)  # lifespan: starts FastMCP's session manager
            return

        headers = {k.decode("latin-1").lower(): v.decode("latin-1") for k, v in scope.get("headers", [])}
        if "k-network-probe" in headers or "k-kubelet-probe" in headers or (scope["method"] == "GET" and scope["path"] in ("/", "/healthz")):
            await respond(send, 200, b"ok")
            return

        body = b""
        more = True
        while more:
            message = await receive()
            if message["type"] == "http.disconnect":
                return
            body += message.get("body", b"")
            more = message.get("more_body", False)

        identity = headers.get("x-runr-identity", "")
        if GATEWAY_KEY and not verify_signature(scope["method"], scope["path"], body, identity, headers.get("x-runr-signature", "")):
            await respond(send, 401, b'{"error":"requests must come through the RunR gateway"}', b"application/json")
            return

        replayed = False

        async def replay():
            nonlocal replayed
            if not replayed:
                replayed = True
                return {"type": "http.request", "body": body, "more_body": False}
            return await receive()

        token = current_identity.set(decode_identity(identity))
        try:
            await self.app(scope, replay, send)
        finally:
            current_identity.reset(token)


async def respond(send, status: int, body: bytes, content_type: bytes = b"text/plain") -> None:
    await send({"type": "http.response.start", "status": status, "headers": [(b"content-type", content_type)]})
    await send({"type": "http.response.body", "body": body})


def main() -> None:
    port = int(os.environ.get("PORT", "8080"))
    if not GATEWAY_KEY:
        print("RUNR_MCP_GATEWAY_KEY is not set: requests are not checked for the gateway signature")
    print(f"RunR MCP starter: Streamable HTTP on :{port}{mcp.settings.streamable_http_path}")
    uvicorn.run(GatewaySignature(mcp.streamable_http_app()), host="0.0.0.0", port=port, proxy_headers=True, forwarded_allow_ips="*")


if __name__ == "__main__":
    main()
