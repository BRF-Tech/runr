"""MCP e2e client (runtime/lib/test-mcp.sh, docs/mcp.md).

Runs inside a container that shares the API container's network (base URL http://localhost) with
the official MCP Python SDK installed. Subcommands:

  prepare  sign in as the administrator, create a full and a read-only API token, find the region
           → prints `export NAME='value'` lines for the shell
  server   the RunR MCP server: initialize, sessions, tools/list per token scope, tools/call,
           resources, resource templates, prompts, 401 + protected resource metadata
  oauth    OAuth 2.1: metadata, dynamic client registration, authorize + consent API, PKCE token
           exchange, code replay, the issued token on /mcp, connected clients, revocation
  hosted   creates a hosted MCP server with the mcp_server_create tool (Python template, token
           access, deploy with progress) → prints `export` lines (project, handle, URLs)
  gateway  the hosted server through the gateway: 401 without a token, tools with a token, the
           caller identity, the call log, public and OAuth access modes

Every check prints ✓ / ✗; the exit code is 1 when one failed.
"""

import argparse
import asyncio
import base64
import hashlib
import json
import secrets
import sys
import time
import urllib.parse
from datetime import timedelta

import httpx
from mcp import ClientSession
from mcp.client.streamable_http import streamablehttp_client
from pydantic import AnyUrl

GREEN, RED, NC = "\033[0;32m", "\033[0;31m", "\033[0m"
failures = 0


def check(name: str, ok: bool, detail: str = "") -> bool:
    """One result line on stderr (stdout carries the `export` lines of prepare / hosted)."""
    global failures
    if ok:
        print(f"  {GREEN}✓{NC} {name}", file=sys.stderr)
    else:
        failures += 1
        print(f"  {RED}✗{NC} {name}" + (f": {detail}" if detail else ""), file=sys.stderr)
    return ok


def api(base: str, token: str | None = None) -> httpx.Client:
    headers = {"Accept": "application/json"}
    if token:
        headers["Authorization"] = f"Bearer {token}"
    return httpx.Client(base_url=base, headers=headers, timeout=60, follow_redirects=False)


def text_of(result) -> str:
    return "\n".join(getattr(c, "text", "") for c in (result.content or []))


async def session_for(url: str, token: str | None):
    headers = {"Authorization": f"Bearer {token}"} if token else {}
    return streamablehttp_client(url, headers=headers, timeout=60, sse_read_timeout=900)


# ── prepare ──────────────────────────────────────────────────────────────────


def cmd_prepare(args) -> None:
    with api(args.base) as http:
        login = http.post("/api/auth/login", json={"login": args.admin_user, "password": args.admin_password})
        console = login.json().get("token", "")
        if not check("administrator signs in", bool(console), login.text[:200]):
            return

    with api(args.base, console) as http:
        full = http.post("/api/tokens", json={"name": "mcp-e2e-full", "abilities": ["*"]})
        readonly = http.post("/api/tokens", json={
            "name": "mcp-e2e-readonly",
            "abilities": ["projects.view", "logs.view", "metrics.view", "regions.view"],
        })
        regions = http.get("/api/knative-regions")
        region_list = regions.json().get("data", regions.json()) if regions.is_success else []
        check("API tokens created", full.status_code == 201 and readonly.status_code == 201, f"{full.status_code} {readonly.status_code}")
        check("e2e region found", bool(region_list), regions.text[:200])

    print(f"export MCP_E2E_CONSOLE_TOKEN='{console}'")
    print(f"export MCP_E2E_FULL_TOKEN='{full.json().get('plain_token', '')}'")
    print(f"export MCP_E2E_READONLY_TOKEN='{readonly.json().get('plain_token', '')}'")
    print(f"export MCP_E2E_REGION='{region_list[0]['id'] if region_list else ''}'")


# ── server ───────────────────────────────────────────────────────────────────


async def cmd_server(args) -> None:
    url = args.base + "/mcp"

    async with await session_for(url, args.token) as (read, write, session_id):
        async with ClientSession(read, write) as session:
            init = await session.initialize()
            check("initialize negotiates a protocol revision", init.protocolVersion in ("2025-11-25", "2025-06-18", "2025-03-26"), init.protocolVersion)
            check("the server answers with an Mcp-Session-Id", bool(session_id()))

            tools = [t.name for t in (await session.list_tools()).tools]
            check("tools/list (full token) has deploy, projects_list, whoami", {"deploy", "projects_list", "whoami"} <= set(tools), ", ".join(tools))

            whoami = await session.call_tool("whoami", {})
            check("tools/call whoami", not whoami.isError, text_of(whoami)[:200])

            projects = await session.call_tool("projects_list", {})
            check("tools/call projects_list", not projects.isError, text_of(projects)[:200])

            invalid = await session.call_tool("projects_list", {"limit": "many"})
            check("invalid arguments come back as a tool error", bool(invalid.isError), text_of(invalid)[:200])

            doc = await session.read_resource(AnyUrl("runr://docs/mcp"))
            body = "".join(getattr(c, "text", "") for c in doc.contents)
            check("resources/read runr://docs/mcp", "MCP" in body, body[:120])

            templates = [t.uriTemplate for t in (await session.list_resource_templates()).resourceTemplates]
            check("resource templates include runr://projects/{id}", "runr://projects/{id}" in templates, ", ".join(templates))

            prompts = [p.name for p in (await session.list_prompts()).prompts]
            check("prompts/list has deploy_function, debug_deployment, create_workflow",
                  {"deploy_function", "debug_deployment", "create_workflow"} <= set(prompts), ", ".join(prompts))
            prompt = await session.get_prompt("deploy_function", {"runtime": "python", "description": "a hello world HTTP function"})
            check("prompts/get deploy_function", len(prompt.messages) > 0)

    async with await session_for(url, args.readonly_token) as (read, write, _):
        async with ClientSession(read, write) as session:
            await session.initialize()
            tools = {t.name for t in (await session.list_tools()).tools}
            check("tools/list (read-only token) hides deploy and project_create",
                  "projects_list" in tools and "deploy" not in tools and "project_create" not in tools, ", ".join(sorted(tools)))
            try:
                hidden = await session.call_tool("deploy", {"project": "anything"})
                check("a hidden tool cannot be called", bool(hidden.isError), text_of(hidden)[:200])
            except Exception as e:  # JSON-RPC error: unknown tool
                check("a hidden tool cannot be called", True, str(e))

    with api(args.base) as http:
        anonymous = http.post("/mcp", json={"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
            "protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "1"}}},
            headers={"Accept": "application/json, text/event-stream"})
        challenge = anonymous.headers.get("www-authenticate", "")
        check("no token: 401 with resource_metadata", anonymous.status_code == 401 and "resource_metadata=" in challenge, f"{anonymous.status_code} {challenge}")
        prm = http.get("/.well-known/oauth-protected-resource/mcp")
        check("protected resource metadata names /mcp and the authorization server",
              prm.is_success and prm.json().get("resource") == url and prm.json().get("authorization_servers"), prm.text[:200])
        get = http.get("/mcp", headers={"Authorization": f"Bearer {args.token}", "Accept": "text/event-stream"})
        check("GET /mcp answers 405", get.status_code == 405, str(get.status_code))


# ── oauth ────────────────────────────────────────────────────────────────────


def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).decode().rstrip("=")


async def cmd_oauth(args) -> None:
    resource = args.base + "/mcp"
    redirect_uri = "http://127.0.0.1:53682/callback"

    with api(args.base) as http:
        prm = http.get("/.well-known/oauth-protected-resource/mcp").json()
        issuer = prm["authorization_servers"][0]
        meta = http.get("/.well-known/oauth-authorization-server").json()
        check("authorization server metadata (S256, code, registration)",
              meta.get("issuer") == issuer and "S256" in meta.get("code_challenge_methods_supported", [])
              and "code" in meta.get("response_types_supported", []) and meta.get("registration_endpoint"), json.dumps(meta)[:200])

        def path(endpoint: str) -> str:
            return urllib.parse.urlparse(endpoint).path

        reg = http.post(path(meta["registration_endpoint"]), json={
            "client_name": "RunR e2e client",
            "redirect_uris": [redirect_uri],
            "grant_types": ["authorization_code"],
            "response_types": ["code"],
            "token_endpoint_auth_method": "none",
        })
        client_id = reg.json().get("client_id", "") if reg.status_code == 201 else ""
        if not check("dynamic client registration", bool(client_id), f"{reg.status_code} {reg.text[:200]}"):
            return

        bad = http.post(path(meta["registration_endpoint"]), json={"client_name": "bad", "redirect_uris": ["http://evil.example.com/cb"]})
        check("registration refuses a non-loopback http redirect URI", bad.status_code == 400, str(bad.status_code))

        verifier = b64url(secrets.token_bytes(48))
        challenge = b64url(hashlib.sha256(verifier.encode()).digest())
        state = secrets.token_hex(8)
        authorize = http.get(path(meta["authorization_endpoint"]), params={
            "response_type": "code", "client_id": client_id, "redirect_uri": redirect_uri,
            "code_challenge": challenge, "code_challenge_method": "S256", "state": state,
            "scope": "projects.view logs.view", "resource": resource,
        })
        location = authorize.headers.get("location", "")
        request_id = urllib.parse.parse_qs(urllib.parse.urlparse(location).query).get("request", [""])[0]
        if not check("authorize redirects to the consent page", authorize.status_code in (302, 303) and "/oauth-consent" in location and request_id, f"{authorize.status_code} {location}"):
            return

        plain = http.get(path(meta["authorization_endpoint"]), params={
            "response_type": "code", "client_id": client_id, "redirect_uri": redirect_uri,
            "code_challenge": verifier, "code_challenge_method": "plain", "state": state,
        })
        plain_location = plain.headers.get("location", "")
        check("PKCE plain is refused (error back to the client)", "error=" in plain_location, plain_location)

    with api(args.base, args.console_token) as console:
        shown = console.get(f"/api/oauth2/requests/{request_id}")
        data = shown.json().get("data", {}) if shown.is_success else {}
        check("consent request shows the client and the requested scopes",
              data.get("client", {}).get("name") == "RunR e2e client" and "projects.view" in data.get("requested_scopes", []), shown.text[:200])
        approved = console.post(f"/api/oauth2/requests/{request_id}/approve", json={"abilities": ["projects.view"], "expires_in_days": 7})
        redirect = approved.json().get("redirect_url", "") if approved.is_success else ""
        query = urllib.parse.parse_qs(urllib.parse.urlparse(redirect).query)
        code = query.get("code", [""])[0]
        check("consent approve returns the redirect with code and state", bool(code) and query.get("state", [""])[0] == state, f"{approved.status_code} {redirect[:200]}")

    with api(args.base) as http:
        token_path = urllib.parse.urlparse(meta["token_endpoint"]).path
        exchange = {"grant_type": "authorization_code", "code": code, "redirect_uri": redirect_uri,
                    "client_id": client_id, "code_verifier": verifier, "resource": resource}
        wrong = http.post(token_path, data={**exchange, "code_verifier": b64url(secrets.token_bytes(48))})
        check("a wrong code_verifier is refused", wrong.status_code == 400 and wrong.json().get("error") == "invalid_grant", wrong.text[:200])

        issued = http.post(token_path, data=exchange)
        body = issued.json() if issued.headers.get("content-type", "").startswith("application/json") else {}
        access = body.get("access_token", "")
        if not check("token endpoint issues a bearer token with the approved scope",
                     issued.is_success and body.get("token_type", "").lower() == "bearer" and "projects.view" in body.get("scope", ""), issued.text[:200]):
            return

    async with await session_for(resource, access) as (read, write, _):
        async with ClientSession(read, write) as session:
            await session.initialize()
            tools = {t.name for t in (await session.list_tools()).tools}
            check("the OAuth token sees the approved tools only", "projects_list" in tools and "deploy" not in tools, ", ".join(sorted(tools)))

    with api(args.base, args.console_token) as console:
        clients = console.get("/api/mcp/clients")
        names = [c.get("name") for c in clients.json().get("data", [])] if clients.is_success else []
        check("Settings → AI agents lists the connected client", "RunR e2e client" in names, clients.text[:200])

    with api(args.base) as http:
        revoke = http.post(urllib.parse.urlparse(meta["revocation_endpoint"]).path, data={"token": access, "client_id": client_id})
        check("revocation answers 200", revoke.status_code == 200, str(revoke.status_code))
        after = http.post("/mcp", json={"jsonrpc": "2.0", "id": 1, "method": "ping"},
                          headers={"Authorization": f"Bearer {access}", "Accept": "application/json, text/event-stream"})
        check("a revoked token gets 401", after.status_code == 401, str(after.status_code))

        # Last: a code presented twice is refused and revokes the token it produced (RFC 6749
        # 4.1.2), so the token checks above must come first
        replay = http.post(token_path, data=exchange)
        check("a code works once", replay.status_code == 400, str(replay.status_code))


# ── hosted ───────────────────────────────────────────────────────────────────


async def cmd_hosted(args) -> None:
    name = "mcp-e2e-" + secrets.token_hex(3)
    progress: list[str] = []

    async def on_progress(value: float, total: float | None, message: str | None) -> None:
        progress.append(message or str(value))

    async with await session_for(args.base + "/mcp", args.token) as (read, write, _):
        async with ClientSession(read, write) as session:
            await session.initialize()
            started = time.time()
            result = await session.call_tool(
                "mcp_server_create",
                {"name": name, "region": args.region, "template": "python", "access_mode": "token", "deploy": True},
                read_timeout_seconds=timedelta(minutes=15),
                progress_callback=on_progress,
            )
            data = result.structuredContent or {}
            deployment = (data.get("deployment") or {}).get("status")
            check(f"mcp_server_create deploys the Python starter ({int(time.time() - started)} s)",
                  not result.isError and deployment == "active", text_of(result)[:300])
            check("long-running tool reported progress", len(progress) > 0, f"{len(progress)} notifications")

    project = data.get("project") or {}
    server = data.get("mcp_server") or {}
    with api(args.base, args.console_token) as console:
        detail = console.get(f"/api/projects/{project.get('id', 'none')}/mcp-server")
        hosted = detail.json().get("data", {}) if detail.is_success else {}
        check("the project is an MCP server", hosted.get("access_mode") == "token" and hosted.get("handle"), detail.text[:200])

    print(f"export MCP_E2E_PROJECT='{project.get('id', '')}'")
    print(f"export MCP_E2E_HANDLE='{hosted.get('handle') or server.get('handle', '')}'")
    print(f"export MCP_E2E_HOSTED_URL='{hosted.get('url') or server.get('url', '')}'")
    print(f"export MCP_E2E_FUNCTION_URL='{hosted.get('function_url', '')}'")
    print(f"export MCP_E2E_PATH='{hosted.get('path', '/mcp')}'")


# ── gateway ──────────────────────────────────────────────────────────────────


def local(url: str, base: str) -> str:
    """The hosted URL as this container reaches it (same path on the base URL)."""
    return base + urllib.parse.urlparse(url).path


async def cmd_gateway(args) -> None:
    url = local(args.url, args.base)
    initialize = {"jsonrpc": "2.0", "id": 1, "method": "initialize", "params": {
        "protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "1"}}}
    accept = {"Accept": "application/json, text/event-stream"}

    with api(args.base) as http:
        anonymous = http.post(urllib.parse.urlparse(url).path, json=initialize, headers=accept)
        check("token mode: 401 without a token", anonymous.status_code == 401, str(anonymous.status_code))

    async with await session_for(url, args.token) as (read, write, _):
        async with ClientSession(read, write) as session:
            await session.initialize()
            tools = {t.name for t in (await session.list_tools()).tools}
            check("tools/list through the gateway", {"hello", "add", "whoami"} <= tools, ", ".join(sorted(tools)))
            added = await session.call_tool("add", {"a": 2, "b": 3})
            check("tools/call add through the gateway", not added.isError and text_of(added).strip() in ("5", "5.0"), text_of(added))
            who = await session.call_tool("whoami", {})
            check("the server sees the caller the gateway identified", '"auth": "token"' in text_of(who) or '"auth":"token"' in text_of(who), text_of(who)[:200])

    with api(args.base, args.console_token) as console:
        calls = console.get(f"/api/projects/{args.project}/mcp-server/calls")
        rows = calls.json().get("data", []) if calls.is_success else []
        check("the call log has the tools/call of add",
              any(r.get("method") == "tools/call" and r.get("name") == "add" for r in rows), calls.text[:200])

        public = console.put(f"/api/projects/{args.project}/mcp-server", json={"access_mode": "public"})
        check("switch to public", public.is_success, public.text[:200])

    async with await session_for(url, None) as (read, write, _):
        async with ClientSession(read, write) as session:
            await session.initialize()
            tools = {t.name for t in (await session.list_tools()).tools}
            check("public mode: tools without a token", "hello" in tools, ", ".join(sorted(tools)))

    with api(args.base, args.console_token) as console:
        oauth = console.put(f"/api/projects/{args.project}/mcp-server", json={"access_mode": "oauth"})
        check("switch to OAuth", oauth.is_success, oauth.text[:200])

    with api(args.base) as http:
        challenge = http.post(urllib.parse.urlparse(url).path, json=initialize, headers=accept)
        header = challenge.headers.get("www-authenticate", "")
        check("OAuth mode: 401 with the server's resource metadata",
              challenge.status_code == 401 and "/.well-known/oauth-protected-resource/mcp/servers/" in header, f"{challenge.status_code} {header}")
        prm = http.get("/.well-known/oauth-protected-resource" + urllib.parse.urlparse(url).path)
        check("the server's metadata names it and mcp.servers.call",
              prm.is_success and prm.json().get("resource") == url and "mcp.servers.call" in prm.json().get("scopes_supported", []), prm.text[:200])


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)

    p = sub.add_parser("prepare")
    p.add_argument("--base", required=True)
    p.add_argument("--admin-user", default="admin")
    p.add_argument("--admin-password", required=True)

    p = sub.add_parser("server")
    p.add_argument("--base", required=True)
    p.add_argument("--token", required=True)
    p.add_argument("--readonly-token", required=True)

    p = sub.add_parser("oauth")
    p.add_argument("--base", required=True)
    p.add_argument("--console-token", required=True)

    p = sub.add_parser("hosted")
    p.add_argument("--base", required=True)
    p.add_argument("--token", required=True)
    p.add_argument("--console-token", required=True)
    p.add_argument("--region", required=True)

    p = sub.add_parser("gateway")
    p.add_argument("--base", required=True)
    p.add_argument("--token", required=True)
    p.add_argument("--console-token", required=True)
    p.add_argument("--project", required=True)
    p.add_argument("--url", required=True)

    args = parser.parse_args()
    try:
        if args.command == "prepare":
            cmd_prepare(args)
        else:
            asyncio.run({"server": cmd_server, "oauth": cmd_oauth, "hosted": cmd_hosted, "gateway": cmd_gateway}[args.command](args))
    except Exception as e:  # a crash is a failed check, with the reason
        # the SDK's task groups wrap the real error: name the innermost one
        while isinstance(e, BaseExceptionGroup) and e.exceptions:
            e = e.exceptions[0]
        check(f"{args.command} completed", False, f"{type(e).__name__}: {e}")

    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
