"""Request bodies and response handling shared by the sync and the async client."""

from __future__ import annotations

import base64
from typing import Any, Dict, List, Mapping, Optional, Sequence, Union
from urllib.parse import quote

from .errors import RunrSandboxError
from .models import ExecEvent, RunResult
from .sse import SSEEvent

Command = Union[str, Sequence[str]]
NetworkSpec = Union[str, Sequence[str], Mapping[str, Any], None]
FilesSpec = Union[Mapping[str, Union[str, bytes]], Sequence[Mapping[str, Any]], None]

#: Status values after which a sandbox never runs again.
ENDED_STATUSES = frozenset({"terminating", "terminated", "failed"})
#: Status values of a sandbox that is up (paused sandboxes keep their state).
LIVE_STATUSES = frozenset({"running", "paused"})

STREAM_HEADERS = {"Accept": "text/event-stream"}

#: HTTP timeout margin on top of a command's own timeout.
EXEC_HTTP_MARGIN = 60.0
DEFAULT_EXEC_TIMEOUT = 60


def sandbox_path(sandbox_id: str, suffix: str = "") -> str:
    return f"/sandboxes/{quote(str(sandbox_id), safe='')}{suffix}"


def normalize_network(network: NetworkSpec) -> Any:
    """``"none"`` stays; a list of hosts / CIDRs becomes ``{"egress_allow": [...]}``; a dict is sent as given."""
    if network is None:
        return None
    if isinstance(network, str):
        return network
    if isinstance(network, Mapping):
        return dict(network)
    return {"egress_allow": [str(entry) for entry in network]}


def normalize_files(files: FilesSpec) -> Optional[List[Dict[str, Any]]]:
    """``{path: str | bytes}`` (or a list of ``{path, content | content_base64, mode}``) → API entries."""
    if files is None:
        return None
    entries: List[Dict[str, Any]] = []
    if isinstance(files, Mapping):
        for path, content in files.items():
            entries.append({"path": str(path), "content_base64": _b64(content)})
        return entries
    for item in files:
        entry: Dict[str, Any] = {"path": str(item["path"])}
        if "content_base64" in item:
            entry["content_base64"] = item["content_base64"]
        else:
            entry["content_base64"] = _b64(item.get("content", b""))
        if item.get("mode") is not None:
            entry["mode"] = _mode(item["mode"])
        entries.append(entry)
    return entries


def _b64(content: Union[str, bytes, bytearray]) -> str:
    raw = content.encode("utf-8") if isinstance(content, str) else bytes(content)
    return base64.b64encode(raw).decode("ascii")


def _mode(mode: Union[int, str]) -> str:
    """0o755 / 493 / "755" / "0755" → "0755"."""
    if isinstance(mode, int):
        return format(mode, "04o")
    text = str(mode).strip()
    return text if text.startswith("0") else "0" + text


def _compact(body: Dict[str, Any]) -> Dict[str, Any]:
    return {key: value for key, value in body.items() if value is not None}


def create_body(
    *,
    runtime: Optional[str] = None,
    version: Optional[str] = None,
    image: Optional[str] = None,
    cpu: Union[str, float, int, None] = None,
    memory: Union[str, int, None] = None,
    disk: Union[str, int, None] = None,
    ttl_seconds: Optional[int] = None,
    idle_timeout: Optional[int] = None,
    network: NetworkSpec = None,
    env: Optional[Mapping[str, str]] = None,
    files: FilesSpec = None,
    region: Optional[str] = None,
    sandbox_class: Optional[str] = None,
    read_only_root: Optional[bool] = None,
    pids_limit: Optional[int] = None,
    metadata: Optional[Mapping[str, Any]] = None,
    wait: bool = True,
) -> Dict[str, Any]:
    return _compact(
        {
            "runtime": runtime,
            "version": version,
            "image": image,
            "cpu": cpu,
            "memory": memory,
            "disk": disk,
            "ttl_seconds": ttl_seconds,
            "idle_timeout": idle_timeout,
            "network": normalize_network(network),
            "env": {str(k): str(v) for k, v in env.items()} if env is not None else None,
            "files": normalize_files(files),
            "region": region,
            "sandbox_class": sandbox_class,
            "read_only_root": read_only_root,
            "pids_limit": pids_limit,
            "metadata": dict(metadata) if metadata is not None else None,
            "wait": wait,
        }
    )


def exec_body(
    cmd: Command,
    *,
    stdin: Union[str, bytes, None] = None,
    timeout: Optional[int] = None,
    env: Optional[Mapping[str, str]] = None,
    cwd: Optional[str] = None,
    stream: bool = False,
) -> Dict[str, Any]:
    body: Dict[str, Any] = {"cmd": cmd if isinstance(cmd, str) else [str(part) for part in cmd]}
    if isinstance(stdin, (bytes, bytearray)):
        body["stdin_base64"] = base64.b64encode(bytes(stdin)).decode("ascii")
    elif stdin is not None:
        body["stdin"] = stdin
    body.update(_compact({
        "timeout": timeout,
        "env": {str(k): str(v) for k, v in env.items()} if env is not None else None,
        "cwd": cwd,
    }))
    if stream:
        body["stream"] = True
    return body


def run_body(
    code: str,
    *,
    language: Optional[str] = None,
    timeout: Optional[int] = None,
    env: Optional[Mapping[str, str]] = None,
    cwd: Optional[str] = None,
    stream: bool = False,
) -> Dict[str, Any]:
    body = _compact({
        "code": code,
        "language": language,
        "timeout": timeout,
        "env": {str(k): str(v) for k, v in env.items()} if env is not None else None,
        "cwd": cwd,
    })
    if stream:
        body["stream"] = True
    return body


def process_body(cmd: Command, *, env: Optional[Mapping[str, str]] = None, cwd: Optional[str] = None) -> Dict[str, Any]:
    return _compact({
        "cmd": cmd if isinstance(cmd, str) else [str(part) for part in cmd],
        "env": {str(k): str(v) for k, v in env.items()} if env is not None else None,
        "cwd": cwd,
    })


def list_params(
    *,
    status: Optional[str] = None,
    region: Optional[str] = None,
    runtime: Optional[str] = None,
    metadata: Optional[Mapping[str, Any]] = None,
    page: Optional[int] = None,
    per_page: Optional[int] = None,
    all_tenants: Optional[bool] = None,
) -> Dict[str, Any]:
    params: Dict[str, Any] = _compact({
        "status": status,
        "region": region,
        "runtime": runtime,
        "page": page,
        "per_page": per_page,
        "all_tenants": 1 if all_tenants else None,
    })
    for key, value in (metadata or {}).items():
        params[f"metadata[{key}]"] = str(value)
    return params


def http_timeout(base: float, exec_timeout: Optional[int]) -> float:
    """An HTTP timeout that outlasts a command's own timeout."""
    return max(base, float(exec_timeout or DEFAULT_EXEC_TIMEOUT) + EXEC_HTTP_MARGIN)


def unwrap(payload: Any) -> Any:
    """The ``data`` member of an API response."""
    if isinstance(payload, dict) and "data" in payload:
        return payload["data"]
    return payload


class ExecStreamState:
    """Turns the SSE events of an exec / run stream into :class:`ExecEvent` objects."""

    def __init__(self) -> None:
        self.exec_id: Optional[str] = None

    def convert(self, event: SSEEvent) -> Optional[ExecEvent]:
        name = event.event
        if name == "start":
            payload = _json(event)
            exec_id = payload.get("exec_id")
            self.exec_id = str(exec_id) if exec_id else None
            return None
        if name in ("stdout", "stderr"):
            payload = _json(event)
            if payload.get("data_base64"):
                data = base64.b64decode(payload["data_base64"])
            else:
                data = str(payload.get("data") or "").encode("utf-8")
            return ExecEvent(type=name, data=data, exec_id=self.exec_id)
        if name == "exit":
            payload = _json(event)
            code = payload.get("exit_code")
            return ExecEvent(
                type="exit",
                exit_code=int(code) if code is not None else -1,
                timed_out=bool(payload.get("timed_out", False)),
                duration_ms=int(payload.get("duration_ms") or 0),
                truncated=bool(payload.get("truncated", False)),
                exec_id=self.exec_id,
            )
        if name == "result":
            return ExecEvent(type="result", result=RunResult.from_api(_json(event)), exec_id=self.exec_id)
        if name == "error":
            payload = _json(event)
            raise RunrSandboxError(
                None,
                str(payload.get("message") or "The sandbox stream failed."),
                payload.get("reason"),
                payload,
            )
        # Unknown events (and "message") are left out for forward compatibility
        return None


def _json(event: SSEEvent) -> Dict[str, Any]:
    try:
        payload = event.json()
    except ValueError:
        return {"data": event.data}
    return payload if isinstance(payload, dict) else {"data": payload}


def start_failure(info: Dict[str, Any]) -> RunrSandboxError:
    return RunrSandboxError(
        None,
        str(info.get("error") or f"Sandbox {info.get('id')} did not start (status {info.get('status')})."),
        "start_failed",
        info,
    )
