"""An in-memory fake of the RunR Sandbox API for the SDK tests.

It runs a ThreadingHTTPServer on 127.0.0.1 (random port) in a background thread and implements the
subset of the API the SDK uses, with deterministic behaviour:

- ``POST /api/sandboxes``: 201 running; ``metadata.slow = "<n>"`` answers 202 pending and turns
  running after n GETs; ``metadata.never`` stays pending; ``metadata.fail`` turns failed;
  ``runtime = "quota"`` answers 422 ``quota_concurrent``.
- ``exec``: ``echo X`` prints X, ``exit N`` exits N, ``binary`` prints non-UTF-8 bytes,
  ``cat`` echoes stdin, ``printenv K`` prints the env var, ``sleep`` times out, ``error`` (stream)
  sends an error event; anything else prints ``ran: <cmd>``. Streams are written in small pieces
  with a comment line in between.
- files: an in-memory file system per sandbox, real tar handling for the archive endpoints.
"""

from __future__ import annotations

import base64
import io
import json
import posixpath
import re
import tarfile
import threading
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import parse_qs, urlparse

TOKEN = "test-token"
PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 8


def _now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


class FakeState:
    def __init__(self) -> None:
        self.lock = threading.Lock()
        self.sandboxes: Dict[str, Dict[str, Any]] = {}
        self.fs: Dict[str, Dict[str, bytes]] = {}
        self.processes: Dict[str, Dict[str, Dict[str, Any]]] = {}
        self.pending_polls: Dict[str, int] = {}
        self.requests: List[Dict[str, Any]] = []
        self.counter = 0

    def next_id(self, prefix: str = "sbx") -> str:
        self.counter += 1
        return f"{prefix}{self.counter:04d}"

    def calls(self, method: Optional[str] = None, path_regex: Optional[str] = None) -> List[Dict[str, Any]]:
        out = []
        for request in self.requests:
            if method and request["method"] != method:
                continue
            if path_regex and not re.search(path_regex, request["path"]):
                continue
            out.append(request)
        return out


def _sandbox(sid: str, body: Dict[str, Any], status: str) -> Dict[str, Any]:
    network = body.get("network") or "none"
    if isinstance(network, dict):
        net = {"mode": "allowlist", "egress_allow": list(network.get("egress_allow") or [])}
    else:
        net = {"mode": "none", "egress_allow": []}
    return {
        "id": sid,
        "status": status,
        "region": {"id": "r1", "name": "eu-1"},
        "runtime": body.get("runtime") or "python",
        "runtime_version": body.get("version") or "3.14",
        "image": body.get("image") or "ghcr.io/brf-tech/exec-python:3.14",
        "sandbox_class": "gvisor",
        "isolation": "gvisor",
        "resources": {"cpu": "500m", "cpu_m": 500, "memory_mb": 512, "disk_mb": 1024, "pids_limit": 256},
        "network": net,
        "read_only_root": True,
        "env_keys": sorted((body.get("env") or {}).keys()),
        "metadata": body.get("metadata") or {},
        "ttl_seconds": body.get("ttl_seconds") or 900,
        "idle_timeout_seconds": body.get("idle_timeout") or 600,
        "expires_at": _now(),
        "idle_expires_at": _now(),
        "last_activity_at": _now(),
        "created_at": _now(),
        "started_at": _now() if status == "running" else None,
        "ended_at": None,
        "end_reason": None,
        "from_pool": False,
        "start_ms": 100,
        "error": None,
        "created_by": "1",
        "tenant_id": None,
    }


class Handler(BaseHTTPRequestHandler):
    server_version = "FakeRunR/1.0"
    protocol_version = "HTTP/1.0"

    # ── plumbing ────────────────────────────────────────────────────────────

    @property
    def state(self) -> FakeState:
        return self.server.state  # type: ignore[attr-defined]

    def log_message(self, format: str, *args: Any) -> None:  # noqa: A002 - silence the default access log
        return

    def do_GET(self) -> None:
        self._dispatch("GET")

    def do_POST(self) -> None:
        self._dispatch("POST")

    def do_PUT(self) -> None:
        self._dispatch("PUT")

    def do_DELETE(self) -> None:
        self._dispatch("DELETE")

    def _body(self) -> bytes:
        length = int(self.headers.get("Content-Length") or 0)
        return self.rfile.read(length) if length else b""

    def _send_json(self, payload: Any, status: int = 200) -> None:
        raw = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def _send_bytes(self, raw: bytes, content_type: str, status: int = 200) -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def _error(self, http_status: int, message: str, reason: Optional[str] = None, **extra: Any) -> None:
        # extra carries body fields such as the sandbox `status` of a not_running error, so the
        # HTTP code has its own parameter name.
        payload: Dict[str, Any] = {"message": message}
        if reason:
            payload["reason"] = reason
        payload.update(extra)
        self._send_json(payload, http_status)

    def _sse_start(self) -> None:
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.send_header("Cache-Control", "no-cache")
        self.end_headers()

    def _sse(self, event: str, data: Dict[str, Any], split: bool = True) -> None:
        frame = f"event: {event}\ndata: {json.dumps(data)}\n\n".encode("utf-8")
        if split:
            # Several writes per event: the client must reassemble lines across chunks
            for i in range(0, len(frame), 7):
                self.wfile.write(frame[i:i + 7])
                self.wfile.flush()
        else:
            self.wfile.write(frame)
            self.wfile.flush()

    def _dispatch(self, method: str) -> None:
        parsed = urlparse(self.path)
        path = parsed.path
        query = {k: v[-1] for k, v in parse_qs(parsed.query, keep_blank_values=True).items()}
        raw = self._body()
        content_type = (self.headers.get("Content-Type") or "").split(";")[0].strip()
        body: Any = {}
        if raw and content_type == "application/json":
            body = json.loads(raw.decode("utf-8"))
        with self.state.lock:
            self.state.requests.append({
                "method": method,
                "path": path,
                "query": query,
                "headers": {k.lower(): v for k, v in self.headers.items()},
                "json": body if content_type == "application/json" else None,
                "raw": raw,
            })
        if self.headers.get("Authorization") != f"Bearer {TOKEN}":
            return self._error(401, "Unauthenticated.")
        try:
            self._route(method, path, query, body, raw, content_type)
        except BrokenPipeError:  # pragma: no cover - client went away mid-stream
            pass

    # ── routes ──────────────────────────────────────────────────────────────

    def _route(self, method: str, path: str, query: Dict[str, str], body: Any, raw: bytes, content_type: str) -> None:
        if path == "/api/sandboxes":
            if method == "POST":
                return self._create(body)
            if method == "GET":
                return self._list(query)
        match = re.fullmatch(r"/api/sandboxes/([^/]+)(/.*)?", path)
        if not match:
            return self._error(404, "Not found.")
        sid, rest = match.group(1), match.group(2) or ""
        with self.state.lock:
            sandbox = self.state.sandboxes.get(sid)
        if sandbox is None:
            return self._error(404, "Sandbox not found.")

        if rest == "" and method == "GET":
            with self.state.lock:
                remaining = self.state.pending_polls.get(sid)
                if remaining is not None:
                    if remaining <= 1:
                        del self.state.pending_polls[sid]
                        sandbox["status"] = "running"
                        sandbox["started_at"] = _now()
                    else:
                        self.state.pending_polls[sid] = remaining - 1
            if sandbox["metadata"].get("fail") and sandbox["status"] == "pending":
                sandbox["status"] = "failed"
                sandbox["error"] = "ImagePullBackOff: image not found"
            return self._send_json({"data": sandbox})
        if rest == "" and method == "DELETE":
            if sandbox["status"] in ("terminated", "failed"):
                return self._error(409, "The sandbox has ended.", "not_running", status=sandbox["status"])
            sandbox["status"] = "terminated"
            sandbox["end_reason"] = "deleted"
            sandbox["ended_at"] = _now()
            return self._send_json({"data": sandbox})
        if rest == "/timeout" and method == "POST":
            sandbox["ttl_seconds"] = int(body.get("ttl_seconds") or 0)
            return self._send_json({"data": sandbox})
        if rest == "/keepalive" and method == "POST":
            sandbox["last_activity_at"] = _now()
            return self._send_json({"data": sandbox})
        if rest == "/pause" and method == "POST":
            sandbox["status"] = "paused"
            return self._send_json({"data": sandbox})
        if rest == "/resume" and method == "POST":
            sandbox["status"] = "running"
            return self._send_json({"data": sandbox})
        if rest == "/network" and method == "PUT":
            network = body.get("network")
            if isinstance(network, dict):
                sandbox["network"] = {"mode": "allowlist", "egress_allow": list(network.get("egress_allow") or [])}
            else:
                sandbox["network"] = {"mode": "none", "egress_allow": []}
            return self._send_json({"data": sandbox})
        if rest == "/logs" and method == "GET":
            data: Dict[str, Any] = {"events": [{"id": "e1", "type": "created", "message": "Sandbox created", "data": {}, "user_id": "1", "created_at": _now()}]}
            if query.get("pod"):
                data["pod"] = {"phase": "Running", "conditions": [], "events": [], "log": ""}
            return self._send_json({"data": data})
        if rest == "/terminal" and method == "POST":
            return self._send_json({"data": {"session_id": "sess-1", "url": "/ws/shell?token=sess-1"}})

        if sandbox["status"] not in ("running",) and rest not in ("",):
            return self._error(409, "The sandbox is not running.", "not_running", status=sandbox["status"])

        if rest == "/exec" and method == "POST":
            return self._exec(sid, sandbox, body)
        if rest == "/run" and method == "POST":
            return self._run(body)
        if rest.startswith("/files"):
            return self._files(sid, method, rest, query, body, raw, content_type)
        if rest.startswith("/processes"):
            return self._processes(sid, method, rest, query, body)
        return self._error(404, "Not found.")

    def _create(self, body: Dict[str, Any]) -> None:
        if body.get("runtime") == "quota":
            return self._error(422, "The organization runs its maximum of 2 sandboxes.", "quota_concurrent")
        if body.get("runtime") == "unknown":
            return self._error(422, "Unknown runtime.", "runtime_unavailable", errors={"runtime": ["Unknown runtime."]})
        metadata = body.get("metadata") or {}
        with self.state.lock:
            sid = self.state.next_id()
            pending = bool(metadata.get("slow") or metadata.get("never") or metadata.get("fail"))
            sandbox = _sandbox(sid, body, "pending" if pending else "running")
            self.state.sandboxes[sid] = sandbox
            files: Dict[str, bytes] = {}
            for entry in body.get("files") or []:
                files[entry["path"]] = base64.b64decode(entry["content_base64"])
            self.state.fs[sid] = files
            self.state.processes[sid] = {}
            if metadata.get("slow"):
                self.state.pending_polls[sid] = int(metadata["slow"])
        return self._send_json({"data": sandbox}, 202 if pending else 201)

    def _list(self, query: Dict[str, str]) -> None:
        status = query.get("status", "active")
        items = list(self.state.sandboxes.values())
        if status == "active":
            items = [s for s in items if s["status"] in ("pending", "running", "paused")]
        elif status != "all":
            items = [s for s in items if s["status"] == status]
        for key, value in query.items():
            m = re.fullmatch(r"metadata\[(.+)\]", key)
            if m:
                items = [s for s in items if str(s["metadata"].get(m.group(1))) == value]
        if query.get("runtime"):
            items = [s for s in items if s["runtime"] == query["runtime"]]
        return self._send_json({"data": items, "meta": {"total": len(items), "page": 1, "per_page": 50}})

    # ── exec / run ──────────────────────────────────────────────────────────

    @staticmethod
    def _command(body: Dict[str, Any]) -> str:
        cmd = body.get("cmd")
        if isinstance(cmd, list):
            return " ".join(cmd)
        return str(cmd or "")

    def _outcome(self, body: Dict[str, Any]) -> Tuple[bytes, bytes, int, bool]:
        cmd = self._command(body)
        env = body.get("env") or {}
        if cmd.startswith("echo "):
            return cmd[5:].encode() + b"\n", b"", 0, False
        if cmd.startswith("exit "):
            code = int(cmd[5:])
            return b"", f"exit {code}\n".encode(), code, False
        if cmd == "binary":
            return b"\xff\xfe\x00ok", b"", 0, False
        if cmd == "cat":
            if body.get("stdin_base64"):
                return base64.b64decode(body["stdin_base64"]), b"", 0, False
            return str(body.get("stdin") or "").encode(), b"", 0, False
        if cmd.startswith("printenv "):
            return str(env.get(cmd[9:], "")).encode() + b"\n", b"", 0, False
        if cmd == "sleep":
            return b"", b"", 137, True
        return f"ran: {cmd}\n".encode(), b"", 0, False

    def _exec(self, sid: str, sandbox: Dict[str, Any], body: Dict[str, Any]) -> None:
        stream = bool(body.get("stream")) or "text/event-stream" in (self.headers.get("Accept") or "")
        cmd = self._command(body)
        if not stream:
            stdout, stderr, code, timed_out = self._outcome(body)
            data: Dict[str, Any] = {"exit_code": code, "timed_out": timed_out, "duration_ms": 5, "truncated": False}
            for key, value in (("stdout", stdout), ("stderr", stderr)):
                try:
                    data[key] = value.decode("utf-8")
                except UnicodeDecodeError:
                    data[key] = ""
                    data[f"{key}_base64"] = base64.b64encode(value).decode()
            return self._send_json({"data": data})

        self._sse_start()
        self._sse("start", {"exec_id": "exec-1", "sandbox_id": sid})
        if cmd == "error":
            self._sse("error", {"message": "The sandbox ended during the command.", "reason": "not_running"})
            return
        if cmd == "multi":
            self._sse("stdout", {"data": "line 1\n"})
            self.wfile.write(b": ping\n\n")
            self.wfile.flush()
            self._sse("stderr", {"data": "warning\n"})
            self._sse("stdout", {"data_base64": base64.b64encode(b"\xff\xfe").decode()})
            self._sse("stdout", {"data": "héllo ✓\n"})
            self._sse("exit", {"exit_code": 3, "timed_out": False, "duration_ms": 21, "truncated": False})
            return
        stdout, stderr, code, timed_out = self._outcome(body)
        if stdout:
            self._sse("stdout", {"data": stdout.decode("utf-8", "replace")})
        if stderr:
            self._sse("stderr", {"data": stderr.decode("utf-8", "replace")})
        self._sse("exit", {"exit_code": code, "timed_out": timed_out, "duration_ms": 5, "truncated": False})

    def _run(self, body: Dict[str, Any]) -> None:
        stream = bool(body.get("stream")) or "text/event-stream" in (self.headers.get("Accept") or "")
        code = str(body.get("code") or "")
        failed = "raise" in code
        result: Dict[str, Any] = {
            "language": body.get("language") or "python",
            "exit_code": 1 if failed else 0,
            "stdout": "" if failed else "42\n",
            "stderr": "Traceback (most recent call last):\nValueError\n" if failed else "",
            "timed_out": False,
            "duration_ms": 30,
            "truncated": False,
            "files_changed": [{"path": "/output/plot.png", "size": len(PNG)}, {"path": "/workspace/data.csv", "size": 12}],
            "outputs": [{"path": "/output/plot.png", "mime": "image/png", "size": len(PNG), "data_base64": base64.b64encode(PNG).decode()}],
        }
        if not stream:
            return self._send_json({"data": result})
        self._sse_start()
        self._sse("start", {"exec_id": "run-1", "sandbox_id": "x"})
        if result["stdout"]:
            self._sse("stdout", {"data": result["stdout"]})
        if result["stderr"]:
            self._sse("stderr", {"data": result["stderr"]})
        self._sse("exit", {"exit_code": result["exit_code"], "timed_out": False, "duration_ms": 30, "truncated": False})
        final = {k: v for k, v in result.items() if k not in ("stdout", "stderr")}
        self._sse("result", final, split=False)

    # ── files ───────────────────────────────────────────────────────────────

    def _files(self, sid: str, method: str, rest: str, query: Dict[str, str], body: Any, raw: bytes, content_type: str) -> None:
        fs = self.state.fs[sid]
        raw_path = query.get("path") or (body.get("path") if isinstance(body, dict) else None) or "/workspace"
        path = posixpath.normpath(raw_path)
        if rest == "/files" and method == "GET":
            prefix = path.rstrip("/") + "/"
            if path in fs:
                return self._send_json({"data": {"path": path, "is_dir": False, "entries": [self._entry(path, fs[path])], "truncated": False}})
            children: Dict[str, Dict[str, Any]] = {}
            for name, content in fs.items():
                if name.startswith(prefix):
                    first = name[len(prefix):].split("/", 1)
                    child = prefix + first[0]
                    if len(first) == 1:
                        children[child] = self._entry(child, content)
                    else:
                        children.setdefault(child, {"name": first[0], "path": child, "type": "dir", "size": 0, "mode": "0755", "modified_at": _now()})
            if not children and path not in ("/workspace", "/output", "/tmp"):
                return self._error(404, "No such file or directory.")
            return self._send_json({"data": {"path": path, "is_dir": True, "entries": sorted(children.values(), key=lambda e: e["name"]), "truncated": False}})
        if rest == "/files" and method == "DELETE":
            recursive = query.get("recursive") in ("1", "true")
            if path in fs:
                del fs[path]
            else:
                inside = [n for n in fs if n.startswith(path.rstrip("/") + "/")]
                if not inside:
                    return self._error(404, "No such file or directory.")
                if not recursive:
                    return self._error(422, "The directory is not empty; pass recursive=1.")
                for name in inside:
                    del fs[name]
            return self._send_json({"data": {"path": path, "deleted": True}})
        if rest == "/files/mkdir" and method == "POST":
            fs.setdefault(path.rstrip("/") + "/.keep", b"")
            return self._send_json({"data": {"path": path}})
        if rest == "/files/content" and method == "GET":
            if path not in fs:
                return self._error(404, "No such file or directory.")
            if query.get("format") == "json":
                return self._send_json({"data": {"path": path, "size": len(fs[path]), "content_base64": base64.b64encode(fs[path]).decode()}})
            return self._send_bytes(fs[path], "application/octet-stream")
        if rest == "/files/content" and method == "PUT":
            if content_type == "application/json":
                content = base64.b64decode(body.get("content_base64") or "")
            else:
                content = raw
            if len(content) > 1024 * 1024:
                return self._error(413, "The file is larger than the upload limit.", "file_too_large")
            fs[path] = content
            return self._send_json({"data": {"path": path, "size": len(content)}})
        if rest == "/files/archive" and method == "GET":
            prefix = path.rstrip("/") + "/"
            buffer = io.BytesIO()
            with tarfile.open(fileobj=buffer, mode="w") as archive:
                for name, content in sorted(fs.items()):
                    if name.startswith(prefix) and not name.endswith("/.keep"):
                        info = tarfile.TarInfo("./" + name[len(prefix):])
                        info.size = len(content)
                        archive.addfile(info, io.BytesIO(content))
            return self._send_bytes(buffer.getvalue(), "application/x-tar")
        if rest == "/files/archive" and method == "PUT":
            with tarfile.open(fileobj=io.BytesIO(raw), mode="r:*") as archive:
                for member in archive.getmembers():
                    if member.isfile():
                        handle = archive.extractfile(member)
                        name = posixpath.normpath(posixpath.join(path, member.name))
                        fs[name] = handle.read() if handle else b""
            return self._send_json({"data": {"path": path, "bytes": len(raw)}})
        return self._error(404, "Not found.")

    @staticmethod
    def _entry(path: str, content: bytes) -> Dict[str, Any]:
        return {"name": posixpath.basename(path), "path": path, "type": "file", "size": len(content), "mode": "0644", "modified_at": _now()}

    # ── processes ───────────────────────────────────────────────────────────

    def _processes(self, sid: str, method: str, rest: str, query: Dict[str, str], body: Dict[str, Any]) -> None:
        procs = self.state.processes[sid]
        if rest == "/processes" and method == "POST":
            with self.state.lock:
                pid = self.state.next_id("proc")
            process = {
                "id": pid, "sandbox_id": sid, "pid": 100 + len(procs), "command": self._command(body),
                "cwd": body.get("cwd") or "/workspace", "status": "running", "exit_code": None,
                "started_at": _now(), "ended_at": None,
            }
            procs[pid] = process
            return self._send_json({"data": process}, 201)
        if rest == "/processes" and method == "GET":
            return self._send_json({"data": list(procs.values())})
        match = re.fullmatch(r"/processes/([^/]+)(/logs)?", rest)
        if not match or match.group(1) not in procs:
            return self._error(404, "Process not found.")
        process = procs[match.group(1)]
        if match.group(2):
            if query.get("follow"):
                self._sse_start()
                self._sse("start", {"exec_id": process["id"], "sandbox_id": sid})
                self._sse("stdout", {"data": "serving on :8000\n"})
                self._sse("exit", {"exit_code": 0, "timed_out": False, "duration_ms": 1000, "truncated": False})
                return
            tail = int(query.get("tail_bytes") or 65536)
            stdout = "serving on :8000\n"[-tail:]
            return self._send_json({"data": {"stdout": stdout, "stderr": "", "truncated": tail < 17}})
        if method == "GET":
            return self._send_json({"data": process})
        if method == "DELETE":
            process["status"] = "killed"
            process["ended_at"] = _now()
            process["signal"] = query.get("signal")
            return self._send_json({"data": process})
        return self._error(404, "Not found.")


class FakeServer:
    """Start / stop the fake API; ``url`` is the origin (the SDK appends ``/api``)."""

    def __init__(self) -> None:
        self.state = FakeState()
        self.token = TOKEN
        self._httpd = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        self._httpd.state = self.state  # type: ignore[attr-defined]
        self._httpd.daemon_threads = True
        self._thread = threading.Thread(target=self._httpd.serve_forever, daemon=True)

    @property
    def url(self) -> str:
        host, port = self._httpd.server_address[:2]
        return f"http://{host}:{port}"

    def start(self) -> "FakeServer":
        self._thread.start()
        return self

    def stop(self) -> None:
        self._httpd.shutdown()
        self._httpd.server_close()
        self._thread.join(timeout=5)

    def __enter__(self) -> "FakeServer":
        return self.start()

    def __exit__(self, *exc: Any) -> None:
        self.stop()
