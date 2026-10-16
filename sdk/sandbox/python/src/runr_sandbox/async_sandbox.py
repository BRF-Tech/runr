"""Asynchronous client: :class:`AsyncSandbox` (asyncio, httpx.AsyncClient)."""

from __future__ import annotations

import asyncio
import base64
import os
import ssl
import time
from types import TracebackType
from typing import Any, AsyncIterator, Awaitable, Callable, Dict, Generator, List, Mapping, Optional, Type, Union
from urllib.parse import quote

import httpx

from . import _api
from ._api import Command, FilesSpec, NetworkSpec
from ._tar import extract_archive, tar_directory, tar_files
from ._transport import AsyncTransport
from .config import resolve_config
from .errors import ConflictError, NotFoundError, RunrSandboxError, SandboxStartTimeout
from .exec_ws import AsyncExecSession, ExecSessionOpening
from .models import ExecEvent, ExecResult, FileEntry, Process, ProcessLogs, RunResult
from .sse import SSEDecoder

__all__ = ["AsyncSandbox", "AsyncFiles", "AsyncProcesses"]


class _SandboxCreation:
    """What :meth:`AsyncSandbox.create` returns: await it for the sandbox, or use it directly as
    ``async with AsyncSandbox.create(...) as sandbox:`` to remove the sandbox when the block ends."""

    def __init__(self, factory: Callable[[], Awaitable["AsyncSandbox"]]) -> None:
        self._factory = factory
        self._sandbox: Optional[AsyncSandbox] = None

    def __await__(self) -> Generator[Any, None, "AsyncSandbox"]:
        return self._factory().__await__()

    async def __aenter__(self) -> "AsyncSandbox":
        self._sandbox = await self._factory()
        return self._sandbox

    async def __aexit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        tb: Optional[TracebackType],
    ) -> None:
        if self._sandbox is not None:
            await self._sandbox.close()


class AsyncSandbox:
    """The asyncio variant of :class:`runr_sandbox.Sandbox`::

        async with AsyncSandbox.create(runtime="python") as sandbox:
            result = await sandbox.run_code("print(6 * 7)")
    """

    def __init__(self, info: Dict[str, Any], transport: AsyncTransport, *, owns_transport: bool = True) -> None:
        self._info: Dict[str, Any] = dict(info)
        self._transport = transport
        self._owns_transport = owns_transport
        self._closed = False
        self.files = AsyncFiles(self)
        self.processes = AsyncProcesses(self)

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
        http_client: Optional[httpx.AsyncClient] = None,
    ) -> _SandboxCreation:
        """Create a sandbox (see :meth:`runr_sandbox.Sandbox.create` for the options)."""
        body = _api.create_body(
            runtime=runtime, version=version, image=image, cpu=cpu, memory=memory, disk=disk,
            ttl_seconds=ttl_seconds, idle_timeout=idle_timeout, network=network, env=env, files=files,
            region=region, sandbox_class=sandbox_class, read_only_root=read_only_root,
            pids_limit=pids_limit, metadata=metadata, wait=wait,
        )
        config = resolve_config(api_url, api_key, tenant_id, timeout)

        async def factory() -> "AsyncSandbox":
            transport = AsyncTransport(config, http_client)
            try:
                payload = await transport.json("POST", "/sandboxes", json=body, timeout=max(config.timeout, start_timeout + 30))
            except BaseException:
                await transport.aclose()
                raise
            sandbox = cls(_api.unwrap(payload), transport, owns_transport=True)
            if wait:
                try:
                    await sandbox._wait_running(start_timeout)
                except BaseException:
                    try:
                        await sandbox.close()
                    except RunrSandboxError:
                        pass
                    raise
            return sandbox

        return _SandboxCreation(factory)

    @classmethod
    async def connect(
        cls,
        sandbox_id: str,
        *,
        api_url: Optional[str] = None,
        api_key: Optional[str] = None,
        tenant_id: Optional[str] = None,
        timeout: Optional[float] = None,
        http_client: Optional[httpx.AsyncClient] = None,
    ) -> "AsyncSandbox":
        transport = AsyncTransport(resolve_config(api_url, api_key, tenant_id, timeout), http_client)
        try:
            payload = await transport.json("GET", _api.sandbox_path(sandbox_id))
        except BaseException:
            await transport.aclose()
            raise
        return cls(_api.unwrap(payload), transport, owns_transport=True)

    @classmethod
    async def list(
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
        http_client: Optional[httpx.AsyncClient] = None,
    ) -> List["AsyncSandbox"]:
        transport = AsyncTransport(resolve_config(api_url, api_key, tenant_id, timeout), http_client)
        params = _api.list_params(
            status=status, region=region, runtime=runtime, metadata=metadata,
            page=page, per_page=per_page, all_tenants=all_tenants,
        )
        try:
            payload = await transport.json("GET", "/sandboxes", params=params)
        except BaseException:
            await transport.aclose()
            raise
        return [cls(item, transport, owns_transport=False) for item in _api.unwrap(payload) or []]

    # ── State ───────────────────────────────────────────────────────────────

    @property
    def id(self) -> str:
        return str(self._info.get("id", ""))

    @property
    def info(self) -> Dict[str, Any]:
        return dict(self._info)

    @property
    def status(self) -> str:
        return str(self._info.get("status", ""))

    async def refresh(self) -> "AsyncSandbox":
        self._update(await self._transport.json("GET", _api.sandbox_path(self.id)))
        return self

    def _update(self, payload: Any) -> None:
        data = _api.unwrap(payload)
        if isinstance(data, dict) and data.get("id"):
            self._info = data

    def _path(self, suffix: str = "") -> str:
        return _api.sandbox_path(self.id, suffix)

    async def _wait_running(self, start_timeout: float) -> None:
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
            await asyncio.sleep(min(delay, max(0.0, deadline - time.monotonic())))
            delay = min(delay * 1.5, 2.0)
            await self.refresh()

    # ── Commands ────────────────────────────────────────────────────────────

    async def exec(
        self,
        cmd: Command,
        *,
        stdin: Union[str, bytes, None] = None,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> ExecResult:
        payload = await self._transport.json(
            "POST",
            self._path("/exec"),
            json=_api.exec_body(cmd, stdin=stdin, timeout=timeout, env=env, cwd=cwd),
            timeout=_api.http_timeout(self._transport.config.timeout, timeout),
        )
        return ExecResult.from_api(_api.unwrap(payload))

    async def exec_stream(
        self,
        cmd: Command,
        *,
        stdin: Union[str, bytes, None] = None,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> AsyncIterator[ExecEvent]:
        body = _api.exec_body(cmd, stdin=stdin, timeout=timeout, env=env, cwd=cwd, stream=True)
        async for event in self._stream("POST", self._path("/exec"), body):
            yield event

    def exec_ws(
        self,
        cmd: Optional[Command] = None,
        *,
        code: Optional[str] = None,
        language: Optional[str] = None,
        stdin: Union[str, bytes, None] = None,
        tty: bool = False,
        cols: Optional[int] = None,
        rows: Optional[int] = None,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
        ssl_context: Optional[ssl.SSLContext] = None,
    ) -> ExecSessionOpening:
        """Run a command over the exec WebSocket (see :meth:`runr_sandbox.Sandbox.exec_ws`). Await
        the result for the :class:`AsyncExecSession`, or use it directly as
        ``async with sandbox.exec_ws(...) as session:``."""
        body = _api.exec_ws_body(
            cmd, code=code, language=language, stdin=stdin, tty=tty, cols=cols, rows=rows,
            timeout=timeout, env=env, cwd=cwd,
        )
        transport, path = self._transport, self._path("/exec/ws")
        return ExecSessionOpening(lambda: AsyncExecSession.open(transport, path, body, ssl_context=ssl_context))

    async def run_code(
        self,
        code: str,
        language: Optional[str] = None,
        *,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> RunResult:
        payload = await self._transport.json(
            "POST",
            self._path("/run"),
            json=_api.run_body(code, language=language, timeout=timeout, env=env, cwd=cwd),
            timeout=_api.http_timeout(self._transport.config.timeout, timeout),
        )
        return RunResult.from_api(_api.unwrap(payload))

    async def run_code_stream(
        self,
        code: str,
        language: Optional[str] = None,
        *,
        timeout: Optional[int] = None,
        env: Optional[Mapping[str, str]] = None,
        cwd: Optional[str] = None,
    ) -> AsyncIterator[ExecEvent]:
        body = _api.run_body(code, language=language, timeout=timeout, env=env, cwd=cwd, stream=True)
        async for event in self._stream("POST", self._path("/run"), body):
            yield event

    async def _stream(
        self,
        method: str,
        path: str,
        body: Optional[Dict[str, Any]],
        params: Optional[Dict[str, Any]] = None,
    ) -> AsyncIterator[ExecEvent]:
        state = _api.ExecStreamState()
        decoder = SSEDecoder()
        async with self._transport.stream(method, path, json=body, params=params, headers=_api.STREAM_HEADERS) as response:
            async for chunk in response.aiter_bytes():
                for event in decoder.feed(chunk):
                    converted = state.convert(event)
                    if converted is not None:
                        yield converted
            for event in decoder.flush():
                converted = state.convert(event)
                if converted is not None:
                    yield converted

    # ── Lifecycle ───────────────────────────────────────────────────────────

    async def set_timeout(self, ttl_seconds: int) -> "AsyncSandbox":
        self._update(await self._transport.json("POST", self._path("/timeout"), json={"ttl_seconds": int(ttl_seconds)}))
        return self

    async def keepalive(self) -> "AsyncSandbox":
        self._update(await self._transport.json("POST", self._path("/keepalive")))
        return self

    async def pause(self) -> "AsyncSandbox":
        self._update(await self._transport.json("POST", self._path("/pause")))
        return self

    async def resume(self) -> "AsyncSandbox":
        self._update(await self._transport.json("POST", self._path("/resume")))
        return self

    async def set_network(self, network: NetworkSpec) -> "AsyncSandbox":
        self._update(await self._transport.json("PUT", self._path("/network"), json={"network": _api.normalize_network(network)}))
        return self

    async def logs(self, pod: bool = False) -> Dict[str, Any]:
        return _api.unwrap(await self._transport.json("GET", self._path("/logs"), params={"pod": 1} if pod else None))

    async def terminal(self, cols: int = 80, rows: int = 24) -> Dict[str, Any]:
        return _api.unwrap(await self._transport.json("POST", self._path("/terminal"), json={"cols": cols, "rows": rows}))

    async def kill(self) -> None:
        try:
            self._update(await self._transport.json("DELETE", self._path()))
        except (NotFoundError, ConflictError):
            pass

    async def close(self) -> None:
        if self._closed:
            return
        self._closed = True
        try:
            await self.kill()
        finally:
            if self._owns_transport:
                await self._transport.aclose()

    async def __aenter__(self) -> "AsyncSandbox":
        return self

    async def __aexit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        tb: Optional[TracebackType],
    ) -> None:
        await self.close()

    def __repr__(self) -> str:
        return f"AsyncSandbox(id={self.id!r}, status={self.status!r}, runtime={self._info.get('runtime')!r})"


class AsyncFiles:
    """File operations of an :class:`AsyncSandbox` (``sandbox.files``)."""

    def __init__(self, sandbox: AsyncSandbox) -> None:
        self._sandbox = sandbox

    @property
    def _transport(self) -> AsyncTransport:
        return self._sandbox._transport

    async def list(self, path: str = "/workspace") -> List[FileEntry]:
        data = _api.unwrap(await self._transport.json("GET", self._sandbox._path("/files"), params={"path": path}))
        return [FileEntry.from_api(entry) for entry in (data or {}).get("entries", [])]

    async def read(self, path: str) -> bytes:
        response = await self._transport.request(
            "GET", self._sandbox._path("/files/content"), params={"path": path},
            headers={"Accept": "application/octet-stream"},
        )
        return response.content

    async def read_text(self, path: str, encoding: str = "utf-8") -> str:
        return (await self.read(path)).decode(encoding)

    async def write(self, path: str, data: Union[str, bytes], mode: Union[int, str, None] = None) -> Dict[str, Any]:
        raw = data.encode("utf-8") if isinstance(data, str) else bytes(data)
        if mode is not None:
            payload = await self._transport.json(
                "PUT", self._sandbox._path("/files/content"), params={"path": path},
                json={"content_base64": base64.b64encode(raw).decode("ascii"), "mode": _api._mode(mode)},
            )
        else:
            payload = await self._transport.json(
                "PUT", self._sandbox._path("/files/content"), params={"path": path}, content=raw,
                headers={"Content-Type": "application/octet-stream"},
            )
        return _api.unwrap(payload)

    async def remove(self, path: str, recursive: bool = False) -> None:
        params: Dict[str, Any] = {"path": path}
        if recursive:
            params["recursive"] = 1
        await self._transport.request("DELETE", self._sandbox._path("/files"), params=params)

    async def mkdir(self, path: str) -> None:
        await self._transport.request("POST", self._sandbox._path("/files/mkdir"), json={"path": path})

    async def exists(self, path: str) -> bool:
        try:
            await self._transport.request("GET", self._sandbox._path("/files"), params={"path": path})
        except NotFoundError:
            return False
        return True

    async def upload_archive(self, archive: bytes, remote_dir: str = "/workspace") -> Dict[str, Any]:
        return _api.unwrap(await self._transport.json(
            "PUT", self._sandbox._path("/files/archive"), params={"path": remote_dir}, content=bytes(archive),
            headers={"Content-Type": "application/x-tar"},
        ))

    async def upload_dir(self, local_dir: Union[str, "os.PathLike[str]"], remote_dir: str = "/workspace") -> Dict[str, Any]:
        return await self.upload_archive(tar_directory(local_dir), remote_dir)

    async def upload_files(self, files: Mapping[str, Union[str, bytes]], remote_dir: str = "/workspace") -> Dict[str, Any]:
        return await self.upload_archive(tar_files(files), remote_dir)

    async def download_archive(self, remote_dir: str = "/workspace") -> bytes:
        response = await self._transport.request(
            "GET", self._sandbox._path("/files/archive"), params={"path": remote_dir},
            headers={"Accept": "application/x-tar"},
        )
        return response.content

    async def download_dir(self, remote_dir: str, local_dir: Union[str, "os.PathLike[str]"]) -> List[str]:
        return extract_archive(await self.download_archive(remote_dir), local_dir)


class AsyncProcesses:
    """Background processes of an :class:`AsyncSandbox` (``sandbox.processes``)."""

    def __init__(self, sandbox: AsyncSandbox) -> None:
        self._sandbox = sandbox

    @property
    def _transport(self) -> AsyncTransport:
        return self._sandbox._transport

    async def start(self, cmd: Command, *, env: Optional[Mapping[str, str]] = None, cwd: Optional[str] = None) -> Process:
        payload = await self._transport.json("POST", self._sandbox._path("/processes"), json=_api.process_body(cmd, env=env, cwd=cwd))
        return Process.from_api(_api.unwrap(payload))

    async def list(self) -> List[Process]:
        payload = await self._transport.json("GET", self._sandbox._path("/processes"))
        return [Process.from_api(item) for item in _api.unwrap(payload) or []]

    async def get(self, process_id: str) -> Process:
        payload = await self._transport.json("GET", self._sandbox._path(f"/processes/{_quote(process_id)}"))
        return Process.from_api(_api.unwrap(payload))

    async def logs(self, process_id: str, tail_bytes: Optional[int] = None) -> ProcessLogs:
        params = {"tail_bytes": int(tail_bytes)} if tail_bytes is not None else None
        payload = await self._transport.json("GET", self._sandbox._path(f"/processes/{_quote(process_id)}/logs"), params=params)
        return ProcessLogs.from_api(_api.unwrap(payload))

    async def follow(self, process_id: str) -> AsyncIterator[ExecEvent]:
        async for event in self._sandbox._stream(
            "GET", self._sandbox._path(f"/processes/{_quote(process_id)}/logs"), None, params={"follow": 1},
        ):
            yield event

    async def kill(self, process_id: str, signal: str = "TERM") -> Process:
        payload = await self._transport.json(
            "DELETE", self._sandbox._path(f"/processes/{_quote(process_id)}"), params={"signal": signal.upper()},
        )
        return Process.from_api(_api.unwrap(payload))


def _quote(value: str) -> str:
    return quote(str(value), safe="")
