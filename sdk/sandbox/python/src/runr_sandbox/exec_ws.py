"""The exec WebSocket of the Sandbox API: a command that reads stdin while it runs, optionally on a
terminal (TTY) whose size follows the client's window.

``POST /sandboxes/{id}/exec/ws`` prepares the command and answers a one-time token; the session
then exchanges JSON messages on ``/ws/sandbox-exec?token=…`` of the console host::

    server → client  start {exec_id, tty} · stdout / stderr {data: base64}
                     · exit {exit_code, timed_out, duration_ms, killed} · error {message}
    client → server  stdin {data: base64} · stdin_close · kill · resize {cols, rows}

The server closes the connection after the exit (or error) message; a client that goes away stops
the command. :meth:`runr_sandbox.Sandbox.exec_ws` and :meth:`runr_sandbox.AsyncSandbox.exec_ws`
open the sessions defined here.
"""

from __future__ import annotations

import asyncio
import inspect
import json
import logging
import os
import ssl
from collections import deque
from types import TracebackType
from typing import Any, AsyncIterator, Awaitable, Callable, Deque, Dict, Generator, Iterator, Optional, Type, Union

import websockets.asyncio.client as _async_client
import websockets.sync.client as _sync_client
from websockets.exceptions import ConnectionClosed, ConnectionClosedOK, InvalidHandshake, InvalidStatus, InvalidURI

from . import _api
from ._transport import AsyncTransport, SyncTransport
from .config import ClientConfig
from .errors import RunrSandboxError, error_from_payload
from .models import ExecEvent, ExecResult

__all__ = ["ExecSession", "AsyncExecSession"]

#: Seconds to open the WebSocket and receive the start message (at most the client's timeout).
OPEN_TIMEOUT = 30.0
#: Seconds the closing handshake may take.
CLOSE_TIMEOUT = 5.0
#: Largest message accepted from the server (output arrives in chunks of up to 64 KiB).
MAX_MESSAGE = 16 * 1024 * 1024

# Keepalive pings stay off: the server's messages and the command's timeout bound a session, and
# a ping that a busy server answers late must not end a running command. Newer releases of the
# threading client take the setting too.
_SYNC_CONNECT = inspect.signature(_sync_client.connect).parameters
_SYNC_PING_SETTING = "ping_interval" in _SYNC_CONNECT
# Releases that turn the threading connect() into a context manager keep returning the
# connection itself with legacy=True.
_SYNC_LEGACY_SETTING = "legacy" in _SYNC_CONNECT


class _RedactingLogger(logging.LoggerAdapter):  # type: ignore[type-arg]
    """websockets logs the request line, which carries the session token: it is replaced."""

    def log(self, level: int, msg: Any, *args: Any, **kwargs: Any) -> None:
        if not self.isEnabledFor(level):
            return
        try:
            text = str(msg) % args if args else str(msg)
        except (TypeError, ValueError):
            text = str(msg)
        self.logger.log(level, _api.redact_token(text), **kwargs)


_LOGGER = _RedactingLogger(logging.getLogger("runr_sandbox.ws"), {})


def tls_context(custom: Optional[ssl.SSLContext] = None) -> ssl.SSLContext:
    """TLS settings of a ``wss://`` connection: ``custom`` when given; else the CA bundle that
    ``SSL_CERT_FILE`` / ``SSL_CERT_DIR`` name; else certifi's bundle, as httpx uses."""
    if custom is not None:
        return custom
    cafile = os.environ.get("SSL_CERT_FILE") or None
    capath = os.environ.get("SSL_CERT_DIR") or None
    if cafile or capath:
        return ssl.create_default_context(cafile=cafile, capath=capath)
    try:
        import certifi
    except ImportError:  # pragma: no cover - httpx installs certifi
        return ssl.create_default_context()
    return ssl.create_default_context(cafile=certifi.where())


def _connect_options(config: ClientConfig, url: str, ssl_context: Optional[ssl.SSLContext], *, threaded: bool) -> Dict[str, Any]:
    options: Dict[str, Any] = {
        "user_agent_header": config.user_agent,
        "open_timeout": min(config.timeout, OPEN_TIMEOUT),
        "close_timeout": CLOSE_TIMEOUT,
        "max_size": MAX_MESSAGE,
        "compression": None,
        "logger": _LOGGER,
    }
    if url.startswith("wss://"):
        options["ssl"] = tls_context(ssl_context)
    if not threaded or _SYNC_PING_SETTING:
        options["ping_interval"] = None
    if threaded and _SYNC_LEGACY_SETTING:
        options["legacy"] = True
    return options


def _handshake_error(exc: BaseException, url: str) -> RunrSandboxError:
    where = _api.redact_token(url)
    if isinstance(exc, InvalidStatus):
        status = int(exc.response.status_code)
        text = bytes(exc.response.body or b"").decode("utf-8", errors="replace")
        body: Any = None
        if text.strip():
            try:
                body = json.loads(text)
            except ValueError:
                body = None
        return error_from_payload(status, body, text or f"The exec WebSocket {where} answered HTTP {status}.")
    if isinstance(exc, InvalidURI):
        return RunrSandboxError(None, f"The exec WebSocket URL {where} is not valid.", "ws_error")
    if isinstance(exc, InvalidHandshake):
        return RunrSandboxError(None, f"The exec WebSocket handshake with {where} failed: {_api.redact_token(str(exc))}", "ws_handshake")
    if isinstance(exc, (TimeoutError, asyncio.TimeoutError)):
        return RunrSandboxError(None, f"The exec WebSocket {where} did not open in time.", "ws_timeout")
    return RunrSandboxError(None, f"Cannot reach the exec WebSocket {where}: {_api.redact_token(str(exc))}", "ws_error")


def _closed_early(url: str) -> RunrSandboxError:
    return RunrSandboxError(None, f"The exec WebSocket {_api.redact_token(url)} closed before the command exited.", "ws_closed")


def _session_data(payload: Any) -> Dict[str, Any]:
    data = _api.unwrap(payload)
    if not isinstance(data, dict):
        raise RunrSandboxError(None, "The RunR API answered no exec WebSocket session.", "bad_response", payload)
    return data


def _result(exit_event: ExecEvent, stdout: bytes, stderr: bytes) -> ExecResult:
    return ExecResult(
        exit_code=exit_event.exit_code if exit_event.exit_code is not None else -1,
        stdout=stdout.decode("utf-8", errors="replace"),
        stderr=stderr.decode("utf-8", errors="replace"),
        timed_out=bool(exit_event.timed_out),
        duration_ms=int(exit_event.duration_ms or 0),
        truncated=bool(exit_event.truncated),
        stdout_bytes=stdout,
        stderr_bytes=stderr,
        killed=bool(exit_event.killed),
    )


class ExecSession:
    """A command running over the exec WebSocket (:meth:`runr_sandbox.Sandbox.exec_ws`).

    Iterate over it for the output as it arrives — ``stdout`` / ``stderr`` :class:`ExecEvent`
    objects, then one ``exit`` event — or call :meth:`wait` for the result. Send input with
    :meth:`send_stdin` while the command runs (from another thread too), end it with
    :meth:`close_stdin`; :meth:`resize` sets the terminal size of a ``tty`` session and
    :meth:`kill` stops the command. Leaving the ``with`` block (or :meth:`close`) ends the
    connection, which stops a command that still runs.
    """

    def __init__(self, ws: Any, state: _api.ExecSocketState, url: str) -> None:
        self._ws = ws
        self._state = state
        self._url = url
        self._pending: Deque[ExecEvent] = deque()
        self._closed = False

    # ── Opening ─────────────────────────────────────────────────────────────

    @classmethod
    def open(
        cls,
        transport: SyncTransport,
        path: str,
        body: Dict[str, Any],
        *,
        ssl_context: Optional[ssl.SSLContext] = None,
    ) -> "ExecSession":
        """Prepare the command (``POST path``), connect, and wait for the start message."""
        data = _session_data(transport.json("POST", path, json=body))
        url = _api.websocket_url(transport.config.api_url, _api.exec_ws_session_url(data))
        state = _api.ExecSocketState(str(data["exec_id"]) if data.get("exec_id") else None, bool(data.get("tty", False)))
        options = _connect_options(transport.config, url, ssl_context, threaded=True)
        try:
            ws = _sync_client.connect(url, **options)
        except InvalidURI as exc:
            raise _handshake_error(exc, url) from None  # its text holds the token
        except (InvalidHandshake, OSError, TimeoutError) as exc:
            raise _handshake_error(exc, url) from exc
        session = cls(ws, state, url)
        session._await_start(float(options["open_timeout"]))
        return session

    def _await_start(self, timeout: float) -> None:
        try:
            while not self._state.started:
                try:
                    message = self._ws.recv(timeout)
                except TimeoutError:
                    raise RunrSandboxError(None, f"The exec WebSocket {_api.redact_token(self._url)} sent no start message in time.", "ws_timeout") from None
                except ConnectionClosed:
                    raise _closed_early(self._url) from None
                event = self._state.convert(message)
                if event is not None:
                    self._pending.append(event)
        except BaseException:
            self.close()
            raise

    # ── State ───────────────────────────────────────────────────────────────

    @property
    def exec_id(self) -> Optional[str]:
        """The command's id (as in the sandbox's activity log)."""
        return self._state.exec_id

    @property
    def tty(self) -> bool:
        """Whether the command runs on a terminal (an API without terminal support answers False)."""
        return self._state.tty

    @property
    def done(self) -> bool:
        """True once the exit message arrived."""
        return self._state.exit is not None

    @property
    def exit_code(self) -> Optional[int]:
        """The exit code, once the exit message arrived."""
        return self._state.exit.exit_code if self._state.exit is not None else None

    # ── Input ───────────────────────────────────────────────────────────────

    def _send(self, message: str) -> None:
        if self._closed or self._state.exit is not None:
            return
        try:
            self._ws.send(message)
        except ConnectionClosedOK:
            return  # the command ended; reading delivers its exit
        except ConnectionClosed:
            raise _closed_early(self._url) from None

    def send_stdin(self, data: Union[str, bytes, bytearray]) -> None:
        """Send input to the command (text is encoded as UTF-8). Ignored once the command ended."""
        if data:
            self._send(_api.stdin_message(data))

    write = send_stdin

    def close_stdin(self) -> None:
        """End the command's input: it reads end of file (on a terminal: Ctrl+D)."""
        self._send(_api.STDIN_CLOSE_MESSAGE)

    def resize(self, cols: int, rows: int) -> None:
        """Set the terminal size of a ``tty`` session (1–1000 columns and rows)."""
        self._send(_api.resize_message(cols, rows))

    def kill(self) -> None:
        """Stop the command (SIGKILL); its exit event follows with ``killed`` set."""
        self._send(_api.KILL_MESSAGE)

    # ── Output ──────────────────────────────────────────────────────────────

    def _next(self) -> Optional[ExecEvent]:
        """The next event; None once the exit event was delivered."""
        if self._pending:
            return self._pending.popleft()
        if self._state.exit is not None:
            return None
        while True:
            try:
                message = self._ws.recv()
            except ConnectionClosed:
                self.close()
                raise _closed_early(self._url) from None
            try:
                event = self._state.convert(message)
            except RunrSandboxError:
                self.close()
                raise
            if event is not None:
                if event.type == "exit":
                    self.close()
                return event

    def __iter__(self) -> Iterator[ExecEvent]:
        while True:
            event = self._next()
            if event is None:
                return
            yield event
            if event.type == "exit":
                return

    def wait(self) -> ExecResult:
        """Wait for the command to exit. The result holds the exit status and the output of the
        events that iteration did not read."""
        stdout, stderr = bytearray(), bytearray()
        while True:
            event = self._next()
            if event is None or event.type == "exit":
                break
            if event.type == "stdout":
                stdout += event.data
            elif event.type == "stderr":
                stderr += event.data
        assert self._state.exit is not None
        return _result(self._state.exit, bytes(stdout), bytes(stderr))

    # ── Closing ─────────────────────────────────────────────────────────────

    def close(self) -> None:
        """End the connection (a command that still runs is stopped). Safe to call more than once."""
        if self._closed:
            return
        self._closed = True
        try:
            self._ws.close()
        except Exception:  # pragma: no cover - the socket is gone already
            pass

    def __enter__(self) -> "ExecSession":
        return self

    def __exit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        tb: Optional[TracebackType],
    ) -> None:
        self.close()

    def __repr__(self) -> str:
        return f"ExecSession(exec_id={self.exec_id!r}, tty={self.tty!r}, exit_code={self.exit_code!r})"


class AsyncExecSession:
    """The asyncio variant of :class:`ExecSession` (:meth:`runr_sandbox.AsyncSandbox.exec_ws`)::

        async with sandbox.exec_ws(["python3", "-i"]) as session:
            await session.send_stdin("print(6 * 7)\\n")
            await session.close_stdin()
            async for event in session:
                ...
    """

    def __init__(self, ws: Any, state: _api.ExecSocketState, url: str) -> None:
        self._ws = ws
        self._state = state
        self._url = url
        self._pending: Deque[ExecEvent] = deque()
        self._closed = False

    @classmethod
    async def open(
        cls,
        transport: AsyncTransport,
        path: str,
        body: Dict[str, Any],
        *,
        ssl_context: Optional[ssl.SSLContext] = None,
    ) -> "AsyncExecSession":
        """Prepare the command (``POST path``), connect, and wait for the start message."""
        data = _session_data(await transport.json("POST", path, json=body))
        url = _api.websocket_url(transport.config.api_url, _api.exec_ws_session_url(data))
        state = _api.ExecSocketState(str(data["exec_id"]) if data.get("exec_id") else None, bool(data.get("tty", False)))
        options = _connect_options(transport.config, url, ssl_context, threaded=False)
        try:
            ws = await _async_client.connect(url, **options)
        except InvalidURI as exc:
            raise _handshake_error(exc, url) from None  # its text holds the token
        except (InvalidHandshake, OSError, asyncio.TimeoutError) as exc:
            raise _handshake_error(exc, url) from exc
        session = cls(ws, state, url)
        await session._await_start(float(options["open_timeout"]))
        return session

    async def _await_start(self, timeout: float) -> None:
        try:
            while not self._state.started:
                try:
                    message = await asyncio.wait_for(self._ws.recv(), timeout)
                except asyncio.TimeoutError:
                    raise RunrSandboxError(None, f"The exec WebSocket {_api.redact_token(self._url)} sent no start message in time.", "ws_timeout") from None
                except ConnectionClosed:
                    raise _closed_early(self._url) from None
                event = self._state.convert(message)
                if event is not None:
                    self._pending.append(event)
        except BaseException:
            await self.close()
            raise

    @property
    def exec_id(self) -> Optional[str]:
        return self._state.exec_id

    @property
    def tty(self) -> bool:
        return self._state.tty

    @property
    def done(self) -> bool:
        return self._state.exit is not None

    @property
    def exit_code(self) -> Optional[int]:
        return self._state.exit.exit_code if self._state.exit is not None else None

    async def _send(self, message: str) -> None:
        if self._closed or self._state.exit is not None:
            return
        try:
            await self._ws.send(message)
        except ConnectionClosedOK:
            return
        except ConnectionClosed:
            raise _closed_early(self._url) from None

    async def send_stdin(self, data: Union[str, bytes, bytearray]) -> None:
        """Send input to the command (text is encoded as UTF-8). Ignored once the command ended."""
        if data:
            await self._send(_api.stdin_message(data))

    write = send_stdin

    async def close_stdin(self) -> None:
        """End the command's input: it reads end of file (on a terminal: Ctrl+D)."""
        await self._send(_api.STDIN_CLOSE_MESSAGE)

    async def resize(self, cols: int, rows: int) -> None:
        """Set the terminal size of a ``tty`` session (1–1000 columns and rows)."""
        await self._send(_api.resize_message(cols, rows))

    async def kill(self) -> None:
        """Stop the command (SIGKILL); its exit event follows with ``killed`` set."""
        await self._send(_api.KILL_MESSAGE)

    async def _next(self) -> Optional[ExecEvent]:
        if self._pending:
            return self._pending.popleft()
        if self._state.exit is not None:
            return None
        while True:
            try:
                message = await self._ws.recv()
            except ConnectionClosed:
                await self.close()
                raise _closed_early(self._url) from None
            try:
                event = self._state.convert(message)
            except RunrSandboxError:
                await self.close()
                raise
            if event is not None:
                if event.type == "exit":
                    await self.close()
                return event

    async def _events(self) -> AsyncIterator[ExecEvent]:
        while True:
            event = await self._next()
            if event is None:
                return
            yield event
            if event.type == "exit":
                return

    def __aiter__(self) -> AsyncIterator[ExecEvent]:
        return self._events()

    async def wait(self) -> ExecResult:
        """Wait for the command to exit (see :meth:`ExecSession.wait`)."""
        stdout, stderr = bytearray(), bytearray()
        while True:
            event = await self._next()
            if event is None or event.type == "exit":
                break
            if event.type == "stdout":
                stdout += event.data
            elif event.type == "stderr":
                stderr += event.data
        assert self._state.exit is not None
        return _result(self._state.exit, bytes(stdout), bytes(stderr))

    async def close(self) -> None:
        """End the connection (a command that still runs is stopped). Safe to call more than once."""
        if self._closed:
            return
        self._closed = True
        try:
            await self._ws.close()
        except Exception:  # pragma: no cover - the socket is gone already
            pass

    async def __aenter__(self) -> "AsyncExecSession":
        return self

    async def __aexit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        tb: Optional[TracebackType],
    ) -> None:
        await self.close()

    def __repr__(self) -> str:
        return f"AsyncExecSession(exec_id={self.exec_id!r}, tty={self.tty!r}, exit_code={self.exit_code!r})"


class ExecSessionOpening:
    """What :meth:`runr_sandbox.AsyncSandbox.exec_ws` returns: await it for the session, or use it
    as ``async with sandbox.exec_ws(...) as session:`` to close the connection when the block ends."""

    def __init__(self, factory: Callable[[], Awaitable[AsyncExecSession]]) -> None:
        self._factory = factory
        self._session: Optional[AsyncExecSession] = None

    def __await__(self) -> Generator[Any, None, AsyncExecSession]:
        return self._factory().__await__()

    async def __aenter__(self) -> AsyncExecSession:
        self._session = await self._factory()
        return self._session

    async def __aexit__(
        self,
        exc_type: Optional[Type[BaseException]],
        exc: Optional[BaseException],
        tb: Optional[TracebackType],
    ) -> None:
        if self._session is not None:
            await self._session.close()
