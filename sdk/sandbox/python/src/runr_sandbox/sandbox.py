"""Blocking client: :class:`Sandbox`."""

from __future__ import annotations

import base64
import os
import time
from types import TracebackType
from typing import Any, Dict, Iterator, List, Mapping, Optional, Type, Union
from urllib.parse import quote

import httpx

from . import _api
from ._api import Command, FilesSpec, NetworkSpec
from ._tar import extract_archive, tar_directory, tar_files
from ._transport import SyncTransport
from .config import resolve_config
from .errors import ConflictError, NotFoundError, RunrSandboxError, SandboxStartTimeout
from .models import ExecEvent, ExecResult, FileEntry, Process, ProcessLogs, RunResult
from .sse import SSEDecoder

__all__ = ["Sandbox", "Files", "Processes"]


class Sandbox:
    """An isolated sandbox on a RunR region.

    Create one with :meth:`create` (or attach to an existing one with :meth:`connect`); use it as a
    context manager so it is removed when the block ends::

        with Sandbox.create(runtime="python") as sandbox:
            print(sandbox.exec("python3 -c 'print(6 * 7)'").stdout)
    """

    def __init__(self, info: Dict[str, Any], transport: SyncTransport, *, owns_transport: bool = True) -> None:
        self._info: Dict[str, Any] = dict(info)
        self._transport = transport
        self._owns_transport = owns_transport
        self._closed = False
        self.files = Files(self)
        self.processes = Processes(self)

    # ── Construction ────────────────────────────────────────────────────────

    @classmethod
    def create(
        cls,
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
        start_timeout: float = 180.0,
        api_url: Optional[str] = None,
        api_key: Optional[str] = None,
        tenant_id: Optional[str] = None,
        timeout: Optional[float] = None,
        http_client: Optional[httpx.Client] = None,
    ) -> "Sandbox":
        """Create a sandbox and (with ``wait=True``) return once it runs.

        ``network`` is ``"none"`` (default: no traffic), a list of allowed egress destinations
        (``["pypi.org:443", "10.0.0.0/8"]``) or the API's ``{"egress_allow": [...]}``. ``files`` maps
        absolute paths to their content (``str`` or ``bytes``). A sandbox that does not run within
        ``start_timeout`` seconds is removed and :class:`SandboxStartTimeout` is raised.
        """
        config = resolve_config(api_url, api_key, tenant_id, timeout)
        transport = SyncTransport(config, http_client)
        body = _api.create_body(
            runtime=runtime, version=version, image=image, cpu=cpu, memory=memory, disk=disk,
            ttl_seconds=ttl_seconds, idle_timeout=idle_timeout, network=network, env=env, files=files,
            region=region, sandbox_class=sandbox_class, read_only_root=read_only_root,
            pids_limit=pids_limit, metadata=metadata, wait=wait,
        )
        try:
            payload = transport.json("POST", "/sandboxes", json=body, timeout=max(config.timeout, start_timeout + 30))
        except BaseException:
            transport.close()
            raise
        sandbox = cls(_api.unwrap(payload), transport, owns_transport=True)
        if wait:
            try:
                sandbox._wait_running(start_timeout)
            except BaseException:
                # A sandbox that did not come up is removed (best effort) instead of left running
                try:
                    sandbox.close()
                except RunrSandboxError:
                    pass
                raise
        return sandbox

    @classmethod
    def connect(
        cls,
        sandbox_id: str,
        *,
        api_url: Optional[str] = None,
        api_key: Optional[str] = None,
        tenant_id: Optional[str] = None,
        timeout: Optional[float] = None,
        http_client: Optional[httpx.Client] = None,
    ) -> "Sandbox":
        """Attach to an existing sandbox."""
        transport = SyncTransport(resolve_config(api_url, api_key, tenant_id, timeout), http_client)
        try:
            payload = transport.json("GET", _api.sandbox_path(sandbox_id))
        except BaseException:
            transport.close()
            raise
        return cls(_api.unwrap(payload), transport, owns_transport=True)

    @classmethod
    def list(
        cls,
        *,
        status: Optional[str] = None,
        region: Optional[str] = None,
        runtime: Optional[str] = None,
        metadata: Optional[Mapping[str, Any]] = None,
        page: Optional[int] = None,
        per_page: Optional[int] = None,
        all_tenants: Optional[bool] = None,
        api_url: Optional[str] = None,
        api_key: Optional[str] = None,
        tenant_id: Optional[str] = None,
        timeout: Optional[float] = None,
        http_client: Optional[httpx.Client] = None,
    ) -> List["Sandbox"]:
        """Sandboxes of the organization (default: the active ones). The returned objects share one
        HTTP connection pool."""
        transport = SyncTransport(resolve_config(api_url, api_key, tenant_id, timeout), http_client)
        params = _api.list_params(
            status=status, region=region, runtime=runtime, metadata=metadata,
            page=page, per_page=per_page, all_tenants=all_tenants,
        )
        try:
            payload = transport.json("GET", "/sandboxes", params=params)
        except BaseException:
            transport.close()
            raise
        return [cls(item, transport, owns_transport=False) for item in _api.unwrap(payload) or []]

    # ── State ───────────────────────────────────────────────────────────────

    @property
    def id(self) -> str:
        return str(self._info.get("id", ""))

    @property
    def info(self) -> Dict[str, Any]:
        """The sandbox object as the API last returned it."""
        return dict(self._info)

    @property
    def status(self) -> str:
        return str(self._info.get("status", ""))

    def refresh(self) -> "Sandbox":
        """Reload the sandbox from the API."""
        self._update(self._transport.json("GET", _api.sandbox_path(self.id)))
        return self

    def _update(self, payload: Any) -> None:
        data = _api.unwrap(payload)
        if isinstance(data, dict) and data.get("id"):
            self._info = data

    def _path(self, suffix: str = "") -> str:
        return _api.sandbox_path(self.id, suffix)

    def _wait_running(self, start_timeout: float) -> None:
        deadline = time.monotonic() + start_timeout
        delay = 0.25
        while True:
            status = self.status
            if status in _api.LIVE_STATUSES:
                return
            if status in _api.ENDED_STATUSES:
                raise _api.start_failure(self._info)
            if time.monotonic() >= deadline:
                raise SandboxStartTimeout(
                    None,
                    f"Sandbox {self.id} did not start within {start_timeout:g} s (status {status}); it is removed.",
                    "start_timeout",
                    self.info,
                )
            time.sleep(min(delay, max(0.0, deadline - time.monotonic())))
            delay = min(delay * 1.5, 2.0)
            self.refresh()

    # ── Commands ────────────────────────────────────────────────────────────

    def exec(
        self,
        cmd: Command,
        *,
        stdin: Union[str, bytes, None] = None,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> ExecResult:
        """Run a command and wait for it. ``cmd`` is a shell string (``/bin/sh -c``) or an argv list."""
        payload = self._transport.json(
            "POST",
            self._path("/exec"),
            json=_api.exec_body(cmd, stdin=stdin, timeout=timeout, env=env, cwd=cwd),
            timeout=_api.http_timeout(self._transport.config.timeout, timeout),
        )
        return ExecResult.from_api(_api.unwrap(payload))

    def exec_stream(
        self,
        cmd: Command,
        *,
        stdin: Union[str, bytes, None] = None,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> Iterator[ExecEvent]:
        """Run a command and yield its output as it arrives: ``stdout`` / ``stderr`` events, then one
        ``exit`` event."""
        body = _api.exec_body(cmd, stdin=stdin, timeout=timeout, env=env, cwd=cwd, stream=True)
        yield from self._stream("POST", self._path("/exec"), body)

    def run_code(
        self,
        code: str,
        language: Optional[str] = None,
        *,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> RunResult:
        """Run code with the sandbox's interpreter; returns output, exit status, the files the run
        changed and the images it saved under ``/output``."""
        payload = self._transport.json(
            "POST",
            self._path("/run"),
            json=_api.run_body(code, language=language, timeout=timeout, env=env, cwd=cwd),
            timeout=_api.http_timeout(self._transport.config.timeout, timeout),
        )
        return RunResult.from_api(_api.unwrap(payload))

    def run_code_stream(
        self,
        code: str,
        language: Optional[str] = None,
        *,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> Iterator[ExecEvent]:
        """Like :meth:`run_code`, streamed: output events, ``exit``, then a ``result`` event."""
        body = _api.run_body(code, language=language, timeout=timeout, env=env, cwd=cwd, stream=True)
        yield from self._stream("POST", self._path("/run"), body)

    def _stream(self, method: str, path: str, body: Optional[Dict[str, Any]], params: Optional[Dict[str, Any]] = None) -> Iterator[ExecEvent]:
        state = _api.ExecStreamState()
        decoder = SSEDecoder()
        with self._transport.stream(method, path, json=body, params=params, headers=_api.STREAM_HEADERS) as response:
            for chunk in response.iter_bytes():
                for event in decoder.feed(chunk):
                    converted = state.convert(event)
                    if converted is not None:
                        yield converted
            for event in decoder.flush():
                converted = state.convert(event)
                if converted is not None:
                    yield converted

    # ── Lifecycle ───────────────────────────────────────────────────────────

    def set_timeout(self, ttl_seconds: int) -> "Sandbox":
        """Let the sandbox live ``ttl_seconds`` from now (capped by the region's maximum)."""
        self._update(self._transport.json("POST", self._path("/timeout"), json={"ttl_seconds": int(ttl_seconds)}))
        return self

    def keepalive(self) -> "Sandbox":
        """Reset the idle timer."""
        self._update(self._transport.json("POST", self._path("/keepalive")))
        return self

    def pause(self) -> "Sandbox":
        """Freeze every process of the sandbox (commands are refused until :meth:`resume`)."""
        self._update(self._transport.json("POST", self._path("/pause")))
        return self

    def resume(self) -> "Sandbox":
        self._update(self._transport.json("POST", self._path("/resume")))
        return self

    def set_network(self, network: NetworkSpec) -> "Sandbox":
        """Replace the egress allowlist (``"none"`` or a list of hosts / CIDRs)."""
        self._update(self._transport.json("PUT", self._path("/network"), json={"network": _api.normalize_network(network)}))
        return self

    def logs(self, pod: bool = False) -> Dict[str, Any]:
        """The sandbox's event log (``pod=True`` adds the pod's status and events)."""
        return _api.unwrap(self._transport.json("GET", self._path("/logs"), params={"pod": 1} if pod else None))

    def terminal(self, cols: int = 80, rows: int = 24) -> Dict[str, Any]:
        """A one-time session for the interactive terminal WebSocket (``{session_id, url}``)."""
        return _api.unwrap(self._transport.json("POST", self._path("/terminal"), json={"cols": cols, "rows": rows}))

    def kill(self) -> None:
        """Remove the sandbox. A sandbox that is already gone is not an error."""
        try:
            self._update(self._transport.json("DELETE", self._path()))
        except (NotFoundError, ConflictError):
            pass

    def close(self) -> None:
        """Remove the sandbox and release the HTTP connections. Safe to call more than once."""
        if self._closed:
            return
        self._closed = True
        try:
            self.kill()
        finally:
            if self._owns_transport:
                self._transport.close()

    def __enter__(self) -> "Sandbox":
        return self

    def __exit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        tb: Optional[TracebackType],
    ) -> None:
        self.close()

    def __repr__(self) -> str:
        return f"Sandbox(id={self.id!r}, status={self.status!r}, runtime={self._info.get('runtime')!r})"


class Files:
    """File operations of a sandbox (``sandbox.files``)."""

    def __init__(self, sandbox: Sandbox) -> None:
        self._sandbox = sandbox

    @property
    def _transport(self) -> SyncTransport:
        return self._sandbox._transport

    def list(self, path: str = "/workspace") -> List[FileEntry]:
        """Entries of a directory (a file's own entry when ``path`` is a file)."""
        data = _api.unwrap(self._transport.json("GET", self._sandbox._path("/files"), params={"path": path}))
        return [FileEntry.from_api(entry) for entry in (data or {}).get("entries", [])]

    def read(self, path: str) -> bytes:
        response = self._transport.request(
            "GET", self._sandbox._path("/files/content"), params={"path": path},
            headers={"Accept": "application/octet-stream"},
        )
        return response.content

    def read_text(self, path: str, encoding: str = "utf-8") -> str:
        return self.read(path).decode(encoding)

    def write(self, path: str, data: Union[str, bytes], mode: Union[int, str, None] = None) -> Dict[str, Any]:
        """Write a file (parent directories are created). ``mode`` like ``0o755`` or ``"0755"``."""
        raw = data.encode("utf-8") if isinstance(data, str) else bytes(data)
        if mode is not None:
            payload = self._transport.json(
                "PUT", self._sandbox._path("/files/content"), params={"path": path},
                json={"content_base64": base64.b64encode(raw).decode("ascii"), "mode": _api._mode(mode)},
            )
        else:
            payload = self._transport.json(
                "PUT", self._sandbox._path("/files/content"), params={"path": path}, content=raw,
                headers={"Content-Type": "application/octet-stream"},
            )
        return _api.unwrap(payload)

    def remove(self, path: str, recursive: bool = False) -> None:
        params: Dict[str, Any] = {"path": path}
        if recursive:
            params["recursive"] = 1
        self._transport.request("DELETE", self._sandbox._path("/files"), params=params)

    def mkdir(self, path: str) -> None:
        self._transport.request("POST", self._sandbox._path("/files/mkdir"), json={"path": path})

    def exists(self, path: str) -> bool:
        try:
            self._transport.request("GET", self._sandbox._path("/files"), params={"path": path})
        except NotFoundError:
            return False
        return True

    def upload_archive(self, archive: bytes, remote_dir: str = "/workspace") -> Dict[str, Any]:
        """Extract a tar archive into ``remote_dir``."""
        return _api.unwrap(self._transport.json(
            "PUT", self._sandbox._path("/files/archive"), params={"path": remote_dir}, content=bytes(archive),
            headers={"Content-Type": "application/x-tar"},
        ))

    def upload_dir(self, local_dir: Union[str, "os.PathLike[str]"], remote_dir: str = "/workspace") -> Dict[str, Any]:
        """Upload a local directory's contents into ``remote_dir``."""
        return self.upload_archive(tar_directory(local_dir), remote_dir)

    def upload_files(self, files: Mapping[str, Union[str, bytes]], remote_dir: str = "/workspace") -> Dict[str, Any]:
        """Upload in-memory files ``{relative path: content}`` into ``remote_dir`` in one request."""
        return self.upload_archive(tar_files(files), remote_dir)

    def download_archive(self, remote_dir: str = "/workspace") -> bytes:
        """A tar of ``remote_dir``'s contents."""
        response = self._transport.request(
            "GET", self._sandbox._path("/files/archive"), params={"path": remote_dir},
            headers={"Accept": "application/x-tar"},
        )
        return response.content

    def download_dir(self, remote_dir: str, local_dir: Union[str, "os.PathLike[str]"]) -> List[str]:
        """Download ``remote_dir`` into ``local_dir``; returns the local paths of the files written."""
        return extract_archive(self.download_archive(remote_dir), local_dir)


class Processes:
    """Background processes of a sandbox (``sandbox.processes``)."""

    def __init__(self, sandbox: Sandbox) -> None:
        self._sandbox = sandbox

    @property
    def _transport(self) -> SyncTransport:
        return self._sandbox._transport

    def start(self, cmd: Command, *, env: Optional[Mapping[str, str]] = None, cwd: Optional[str] = None) -> Process:
        payload = self._transport.json("POST", self._sandbox._path("/processes"), json=_api.process_body(cmd, env=env, cwd=cwd))
        return Process.from_api(_api.unwrap(payload))

    def list(self) -> List[Process]:
        payload = self._transport.json("GET", self._sandbox._path("/processes"))
        return [Process.from_api(item) for item in _api.unwrap(payload) or []]

    def get(self, process_id: str) -> Process:
        payload = self._transport.json("GET", self._sandbox._path(f"/processes/{_quote(process_id)}"))
        return Process.from_api(_api.unwrap(payload))

    def logs(self, process_id: str, tail_bytes: Optional[int] = None) -> ProcessLogs:
        params = {"tail_bytes": int(tail_bytes)} if tail_bytes is not None else None
        payload = self._transport.json("GET", self._sandbox._path(f"/processes/{_quote(process_id)}/logs"), params=params)
        return ProcessLogs.from_api(_api.unwrap(payload))

    def follow(self, process_id: str) -> Iterator[ExecEvent]:
        """Stream the process's output until it ends (``stdout`` / ``stderr`` events, then ``exit``)."""
        yield from self._sandbox._stream(
            "GET", self._sandbox._path(f"/processes/{_quote(process_id)}/logs"), None, params={"follow": 1},
        )

    def kill(self, process_id: str, signal: str = "TERM") -> Process:
        payload = self._transport.json(
            "DELETE", self._sandbox._path(f"/processes/{_quote(process_id)}"), params={"signal": signal.upper()},
        )
        return Process.from_api(_api.unwrap(payload))


def _quote(value: str) -> str:
    return quote(str(value), safe="")
