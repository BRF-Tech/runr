"""Result and event types of the Sandbox API."""

from __future__ import annotations

import base64
import os
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional, Tuple

__all__ = [
    "ExecResult",
    "RunResult",
    "RichOutput",
    "FileChange",
    "FileEntry",
    "Process",
    "ProcessLogs",
    "ExecEvent",
]


def _decode_output(data: Dict[str, Any], key: str) -> Tuple[str, bytes]:
    """(text, bytes) of an output field that is either UTF-8 text (``key``) or base64 (``key_base64``)."""
    encoded = data.get(f"{key}_base64")
    if encoded:
        raw = base64.b64decode(encoded)
        return raw.decode("utf-8", errors="replace"), raw
    text = data.get(key) or ""
    return text, text.encode("utf-8")


@dataclass
class ExecResult:
    """The outcome of a finished command."""

    exit_code: int
    stdout: str = ""
    stderr: str = ""
    timed_out: bool = False
    duration_ms: int = 0
    truncated: bool = False
    stdout_bytes: bytes = b""
    stderr_bytes: bytes = b""
    #: True when a kill request ended the command (:meth:`ExecSession.kill`).
    killed: bool = False

    @property
    def ok(self) -> bool:
        """True when the command exited with status 0 (and did not time out)."""
        return self.exit_code == 0 and not self.timed_out

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "ExecResult":
        stdout, stdout_bytes = _decode_output(data, "stdout")
        stderr, stderr_bytes = _decode_output(data, "stderr")
        return cls(
            exit_code=int(data.get("exit_code") if data.get("exit_code") is not None else -1),
            stdout=stdout,
            stderr=stderr,
            timed_out=bool(data.get("timed_out", False)),
            duration_ms=int(data.get("duration_ms") or 0),
            truncated=bool(data.get("truncated", False)),
            stdout_bytes=stdout_bytes,
            stderr_bytes=stderr_bytes,
        )


@dataclass
class FileChange:
    """A file a code run created or modified."""

    path: str
    size: int = 0

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "FileChange":
        return cls(path=str(data.get("path", "")), size=int(data.get("size") or 0))


@dataclass
class RichOutput:
    """A rich output of a code run: an image the code saved under ``/output``."""

    path: str
    mime: str
    size: int
    data: bytes = field(repr=False, default=b"")

    @property
    def name(self) -> str:
        return os.path.basename(self.path)

    def save(self, path: Optional[str] = None) -> str:
        """Write the output to a local file (default: its file name in the current directory);
        a directory as ``path`` keeps the file name. Returns the path written."""
        target = path or self.name
        if os.path.isdir(target):
            target = os.path.join(target, self.name)
        with open(target, "wb") as handle:
            handle.write(self.data)
        return target

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "RichOutput":
        raw = base64.b64decode(data.get("data_base64") or "")
        return cls(
            path=str(data.get("path", "")),
            mime=str(data.get("mime") or "application/octet-stream"),
            size=int(data.get("size") or len(raw)),
            data=raw,
        )


@dataclass
class RunResult(ExecResult):
    """The outcome of :meth:`Sandbox.run_code`."""

    language: str = ""
    files_changed: List[FileChange] = field(default_factory=list)
    outputs: List[RichOutput] = field(default_factory=list)

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "RunResult":
        base = ExecResult.from_api(data)
        return cls(
            exit_code=base.exit_code,
            stdout=base.stdout,
            stderr=base.stderr,
            timed_out=base.timed_out,
            duration_ms=base.duration_ms,
            truncated=base.truncated,
            stdout_bytes=base.stdout_bytes,
            stderr_bytes=base.stderr_bytes,
            language=str(data.get("language") or ""),
            files_changed=[FileChange.from_api(f) for f in data.get("files_changed") or []],
            outputs=[RichOutput.from_api(o) for o in data.get("outputs") or []],
        )


@dataclass
class FileEntry:
    """An entry of a directory listing."""

    name: str
    path: str
    type: str
    size: int = 0
    mode: Optional[str] = None
    modified_at: Optional[str] = None

    @property
    def is_dir(self) -> bool:
        return self.type == "dir"

    @property
    def is_file(self) -> bool:
        return self.type == "file"

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "FileEntry":
        mode = data.get("mode")
        return cls(
            name=str(data.get("name", "")),
            path=str(data.get("path", "")),
            type=str(data.get("type") or "other"),
            size=int(data.get("size") or 0),
            mode=str(mode) if mode is not None else None,
            modified_at=data.get("modified_at"),
        )


@dataclass
class Process:
    """A background process of a sandbox. ``id`` is RunR's id, ``pid`` the OS process id."""

    id: str
    sandbox_id: str
    pid: Optional[int]
    command: str
    status: str
    cwd: Optional[str] = None
    exit_code: Optional[int] = None
    started_at: Optional[str] = None
    ended_at: Optional[str] = None

    @property
    def running(self) -> bool:
        return self.status == "running"

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "Process":
        pid = data.get("pid")
        exit_code = data.get("exit_code")
        return cls(
            id=str(data.get("id", "")),
            sandbox_id=str(data.get("sandbox_id", "")),
            pid=int(pid) if pid is not None else None,
            command=str(data.get("command") or ""),
            status=str(data.get("status") or ""),
            cwd=data.get("cwd"),
            exit_code=int(exit_code) if exit_code is not None else None,
            started_at=data.get("started_at"),
            ended_at=data.get("ended_at"),
        )


@dataclass
class ProcessLogs:
    """Output a background process wrote so far (the tail when it is long)."""

    stdout: str = ""
    stderr: str = ""
    truncated: bool = False

    @classmethod
    def from_api(cls, data: Dict[str, Any]) -> "ProcessLogs":
        stdout, _ = _decode_output(data, "stdout")
        stderr, _ = _decode_output(data, "stderr")
        return cls(stdout=stdout, stderr=stderr, truncated=bool(data.get("truncated", False)))


@dataclass
class ExecEvent:
    """One event of a streamed command.

    ``type`` is ``stdout`` or ``stderr`` (``data`` holds the bytes), ``exit`` (``exit_code``,
    ``timed_out``, ``duration_ms``, ``truncated``; ``killed`` on the exec WebSocket) or — for
    :meth:`Sandbox.run_code_stream` only — ``result`` (``result`` holds the :class:`RunResult`
    without stdout / stderr, which were streamed).
    """

    type: str
    data: bytes = b""
    exit_code: Optional[int] = None
    timed_out: Optional[bool] = None
    duration_ms: Optional[int] = None
    truncated: Optional[bool] = None
    exec_id: Optional[str] = None
    result: Optional[RunResult] = None
    killed: Optional[bool] = None

    @property
    def text(self) -> str:
        """``data`` decoded as UTF-8 (invalid bytes replaced)."""
        return self.data.decode("utf-8", errors="replace")
