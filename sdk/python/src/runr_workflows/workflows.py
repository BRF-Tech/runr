"""Workflow registry and the HTTP entry points the RunR engine calls."""

from __future__ import annotations

import asyncio
import inspect
import json
import os
import re
from dataclasses import dataclass, field
from typing import Any, Callable, Dict, Iterable, List, Optional, Tuple, Union

from . import protocol
from .errors import NonRetriableError, SignatureError, StepError, error_payload
from .step import AsyncStep, Interrupt, Step

__version__ = "0.1.0"

NAME_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$")

SIGNING_KEY_ENV = "RUNR_WORKFLOW_SIGNING_KEY"
FALLBACK_KEY_ENV = "RUNR_WORKFLOW_SIGNING_KEY_FALLBACK"


@dataclass
class Context:
    """What a workflow knows about its run."""

    event: Dict[str, Any]
    run_id: str
    workflow: str
    attempt: int = 0
    replay: bool = False
    source: str = "api"
    parent_run_id: Optional[str] = None


@dataclass
class WorkflowDefinition:
    name: str
    fn: Callable[..., Any]
    options: Dict[str, Any] = field(default_factory=dict)

    def describe(self) -> Dict[str, Any]:
        return {"name": self.name, **{k: v for k, v in self.options.items() if v is not None}}


class Workflows:
    """A set of workflows served by one function.

    >>> wf = Workflows()
    >>> @wf.workflow("hello", triggers=[{"event": "user.signup"}])
    ... def hello(ctx, step):
    ...     return step.run("greet", lambda: "hi " + ctx.event["data"]["name"])
    >>> handler = wf.handler()   # RunR python runtime entry point
    """

    def __init__(self, signing_key: Optional[str] = None, signing_key_fallback: Optional[str] = None,
                 tolerance: int = protocol.DEFAULT_TOLERANCE):
        self._signing_key = signing_key
        self._fallback = signing_key_fallback
        self.tolerance = tolerance
        self._workflows: Dict[str, WorkflowDefinition] = {}

    # ── registration ─────────────────────────────────────────────────────

    def workflow(self, name: str, *, triggers: Optional[List[Dict[str, Any]]] = None, retries: Optional[int] = None,
                 timeout: Optional[str] = None, concurrency: Union[int, Dict[str, Any], List[Any], None] = None,
                 idempotency: Optional[str] = None, cancel_on: Optional[List[Dict[str, Any]]] = None,
                 description: Optional[str] = None) -> Callable[[Callable[..., Any]], Callable[..., Any]]:
        """Decorator registering ``fn(ctx, step)`` as workflow ``name``."""
        if not NAME_PATTERN.match(name or ""):
            raise ValueError(f"invalid workflow name {name!r}")
        if name in self._workflows:
            raise ValueError(f"workflow {name!r} is registered twice")

        def register(fn: Callable[..., Any]) -> Callable[..., Any]:
            self._workflows[name] = WorkflowDefinition(name, fn, {
                "description": description,
                "triggers": triggers or [],
                "retries": retries,
                "timeout": timeout,
                "concurrency": concurrency,
                "idempotency": idempotency,
                "cancel_on": cancel_on,
            })
            return fn

        return register

    def add(self, name: str, fn: Callable[..., Any], **options: Any) -> None:
        """Register without the decorator."""
        self.workflow(name, **options)(fn)

    @property
    def names(self) -> List[str]:
        return sorted(self._workflows)

    def definitions(self) -> List[Dict[str, Any]]:
        return [self._workflows[n].describe() for n in self.names]

    # ── keys ─────────────────────────────────────────────────────────────

    def _keys(self) -> List[str]:
        keys = [self._signing_key or os.environ.get(SIGNING_KEY_ENV), self._fallback or os.environ.get(FALLBACK_KEY_ENV)]
        return [k for k in keys if k]

    # ── protocol ─────────────────────────────────────────────────────────

    def handle(self, envelope: Any, header_signature: Optional[str] = None) -> Tuple[int, Dict[str, Any]]:
        """Process one engine request (decoded envelope) → (HTTP status, JSON body)."""
        keys = self._keys()
        if not keys:
            return 500, {"error": f"runr-workflows: {SIGNING_KEY_ENV} is not set"}

        if not isinstance(envelope, dict) or envelope.get("runr_workflow") != protocol.VERSION or not isinstance(envelope.get("request"), str):
            return 400, {"error": "runr-workflows: not a workflow protocol v1 request"}

        raw = envelope["request"]
        signature = header_signature or envelope.get("signature") or ""
        if not protocol.verify(raw, signature, keys, self.tolerance):
            return 401, {"error": "runr-workflows: invalid signature"}

        try:
            request = json.loads(raw)
        except ValueError:
            return 400, {"error": "runr-workflows: request is not JSON"}

        kind = request.get("type")
        if kind == "introspect":
            return 200, protocol.response({
                "sdk": {"name": "runr-workflows", "language": "python", "version": __version__},
                "workflows": self.definitions(),
            })
        if kind == "execute":
            return 200, protocol.response({"ops": [self.execute(request)]})

        return 400, {"error": f"runr-workflows: unknown request type {kind!r}"}

    def execute(self, request: Dict[str, Any]) -> Dict[str, Any]:
        """Run the workflow of an execute request once; returns the single op."""
        name = request.get("workflow")
        definition = self._workflows.get(name or "")
        if definition is None:
            return {"op": "run_failed", "retriable": False,
                    "error": {"name": "WorkflowNotFound", "message": f"workflow {name!r} is not served by this function"}}

        run = request.get("run") or {}
        ctx = Context(
            event=request.get("event") or {},
            run_id=str(run.get("id", "")),
            workflow=definition.name,
            attempt=int(run.get("attempt") or 0),
            replay=bool(run.get("replay")),
            source=str(run.get("source") or "api"),
            parent_run_id=run.get("parent_run_id"),
        )
        is_async = inspect.iscoroutinefunction(definition.fn)
        step = (AsyncStep if is_async else Step)(request.get("steps") or {}, ctx.attempt)

        try:
            result = definition.fn(ctx, step)
            if inspect.isawaitable(result):
                async def _wait(awaitable=result):
                    return await awaitable
                result = asyncio.run(_wait())
            json.dumps(result, allow_nan=False)
            return {"op": "run_completed", "output": result}
        except Interrupt as interrupt:
            return interrupt.op
        except StepError as exc:
            return {"op": "run_failed", "retriable": False, "error": error_payload(exc)}
        except NonRetriableError as exc:
            return {"op": "run_failed", "retriable": False, "error": error_payload(exc)}
        except (TypeError, ValueError) as exc:
            if "JSON serializable" in str(exc) or "Out of range float" in str(exc):
                return {"op": "run_failed", "retriable": False, "error": error_payload(exc)}
            return {"op": "run_failed", "retriable": True, "error": error_payload(exc)}
        except Exception as exc:  # noqa: BLE001
            return {"op": "run_failed", "retriable": True, "error": error_payload(exc)}

    def handle_raw(self, body: Union[bytes, str], headers: Optional[Dict[str, Any]] = None) -> Tuple[int, Dict[str, Any]]:
        """Process a raw HTTP body + headers."""
        try:
            envelope = json.loads(body.decode("utf-8") if isinstance(body, bytes) else body)
        except ValueError:
            return 400, {"error": "runr-workflows: body is not JSON"}
        return self.handle(envelope, _header(headers or {}, protocol.HEADER_SIGNATURE))

    # ── entry points ─────────────────────────────────────────────────────

    def handler(self) -> Callable[[Any, Any], Dict[str, Any]]:
        """``handler(event, context)`` for the RunR python runtime (event = parsed JSON body)."""

        def handle(event: Any, context: Any = None) -> Dict[str, Any]:
            headers = (context or {}).get("headers", {}) if isinstance(context, dict) else {}
            status, body = self.handle(event, _header(headers, protocol.HEADER_SIGNATURE))
            return {
                "statusCode": status,
                "headers": {"Content-Type": "application/json", protocol.HEADER_PROTOCOL: protocol.VERSION},
                "body": body,
            }

        return handle

    def wsgi(self) -> Callable[..., Iterable[bytes]]:
        """A WSGI application (any path, POST)."""

        def app(environ: Dict[str, Any], start_response: Callable[..., Any]) -> Iterable[bytes]:
            if environ.get("REQUEST_METHOD") != "POST":
                status, body = 405, {"error": "runr-workflows: POST only"}
            else:
                length = int(environ.get("CONTENT_LENGTH") or 0)
                raw = environ["wsgi.input"].read(length) if length else b""
                status, body = self.handle_raw(raw, {protocol.HEADER_SIGNATURE: environ.get("HTTP_X_RUNR_SIGNATURE", "")})
            payload = json.dumps(body).encode("utf-8")
            start_response(f"{status} {_REASONS.get(status, 'OK')}", [
                ("Content-Type", "application/json"),
                (protocol.HEADER_PROTOCOL, protocol.VERSION),
                ("Content-Length", str(len(payload))),
            ])
            return [payload]

        return app

    def serve(self, host: str = "0.0.0.0", port: Optional[int] = None) -> None:
        """Standalone HTTP server (e.g. a container project)."""
        from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

        workflows = self

        class RequestHandler(BaseHTTPRequestHandler):
            def do_POST(self) -> None:  # noqa: N802
                length = int(self.headers.get("Content-Length") or 0)
                status, body = workflows.handle_raw(self.rfile.read(length), dict(self.headers))
                payload = json.dumps(body).encode("utf-8")
                self.send_response(status)
                self.send_header("Content-Type", "application/json")
                self.send_header(protocol.HEADER_PROTOCOL, protocol.VERSION)
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def do_GET(self) -> None:  # noqa: N802 — health check
                payload = b'{"ok":true}'
                self.send_response(200)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)

            def log_message(self, fmt: str, *args: Any) -> None:
                print(f"[runr-workflows] {self.address_string()} {fmt % args}")

        server = ThreadingHTTPServer((host, int(port or os.environ.get("PORT", "8080"))), RequestHandler)
        print(f"runr-workflows serving {', '.join(self.names) or 'no workflows'} on :{server.server_address[1]}")
        server.serve_forever()


_REASONS = {200: "OK", 400: "Bad Request", 401: "Unauthorized", 405: "Method Not Allowed", 500: "Internal Server Error"}


def _header(headers: Any, name: str) -> Optional[str]:
    if not headers:
        return None
    lower = name.lower()
    try:
        items = headers.items()
    except AttributeError:
        return None
    for key, value in items:
        if str(key).lower() == lower:
            if isinstance(value, (list, tuple)):
                return str(value[0]) if value else None
            return str(value)
    return None


def verify_request(envelope: Dict[str, Any], keys: Iterable[str], header_signature: Optional[str] = None,
                   tolerance: int = protocol.DEFAULT_TOLERANCE) -> Dict[str, Any]:
    """Verify an envelope and return the decoded request (raises SignatureError)."""
    raw = envelope.get("request") if isinstance(envelope, dict) else None
    if not isinstance(raw, str) or not protocol.verify(raw, header_signature or envelope.get("signature", ""), list(keys), tolerance):
        raise SignatureError("invalid signature")
    return json.loads(raw)
