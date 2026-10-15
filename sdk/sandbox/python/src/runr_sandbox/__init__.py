"""RunR Sandbox SDK — isolated code execution for AI agents and apps on your own Kubernetes cluster.

Quick start::

    from runr_sandbox import Sandbox

    with Sandbox.create(runtime="python") as sandbox:
        result = sandbox.run_code("print(6 * 7)")
        print(result.stdout)

Configuration comes from the arguments or the environment: ``RUNR_API_URL`` (e.g.
``https://runr.example.com``), ``RUNR_API_KEY`` (an API token) and ``RUNR_TENANT_ID`` (optional).
"""

from ._version import __version__
from .async_sandbox import AsyncFiles, AsyncProcesses, AsyncSandbox
from .config import ClientConfig, resolve_config
from .errors import (
    AuthError,
    ConflictError,
    NotFoundError,
    PayloadTooLargeError,
    QuotaError,
    RateLimitError,
    RunrSandboxError,
    SandboxStartTimeout,
    ValidationError,
)
from .models import ExecEvent, ExecResult, FileChange, FileEntry, Process, ProcessLogs, RichOutput, RunResult
from .sandbox import Files, Processes, Sandbox
from .sse import SSEDecoder, SSEEvent

__all__ = [
    "__version__",
    "Sandbox",
    "AsyncSandbox",
    "Files",
    "Processes",
    "AsyncFiles",
    "AsyncProcesses",
    "ClientConfig",
    "resolve_config",
    "ExecResult",
    "RunResult",
    "RichOutput",
    "FileChange",
    "FileEntry",
    "Process",
    "ProcessLogs",
    "ExecEvent",
    "SSEDecoder",
    "SSEEvent",
    "RunrSandboxError",
    "AuthError",
    "NotFoundError",
    "ConflictError",
    "ValidationError",
    "QuotaError",
    "PayloadTooLargeError",
    "RateLimitError",
    "SandboxStartTimeout",
]
