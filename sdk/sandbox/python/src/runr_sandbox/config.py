"""Client configuration: API URL, token and organization, from arguments or the environment."""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from typing import Dict, Optional

from ._version import __version__
from .errors import RunrSandboxError

__all__ = ["ClientConfig", "resolve_config", "api_base_url", "DEFAULT_TIMEOUT"]

#: Default HTTP timeout in seconds. Creating a sandbox may wait for it to start on the cluster.
DEFAULT_TIMEOUT = 300.0


def api_base_url(url: str) -> str:
    """The API base for a RunR URL: ``https://runr.example.com`` → ``https://runr.example.com/api``.

    A URL that already ends with ``/api`` is kept as it is.
    """
    base = url.strip().rstrip("/")
    if not base:
        raise RunrSandboxError(None, "The RunR API URL is empty.", "config")
    if not base.endswith("/api"):
        base += "/api"
    return base


@dataclass(frozen=True)
class ClientConfig:
    """Resolved connection settings of a client."""

    api_url: str
    api_key: Optional[str] = None
    tenant_id: Optional[str] = None
    timeout: float = DEFAULT_TIMEOUT
    user_agent: str = field(default=f"runr-sandbox-python/{__version__}")

    def headers(self) -> Dict[str, str]:
        headers = {
            "Accept": "application/json",
            "User-Agent": self.user_agent,
        }
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"
        if self.tenant_id:
            headers["X-Tenant-ID"] = self.tenant_id
        return headers

    def url(self, path: str) -> str:
        return self.api_url + (path if path.startswith("/") else "/" + path)


def resolve_config(
    api_url: Optional[str] = None,
    api_key: Optional[str] = None,
    tenant_id: Optional[str] = None,
    timeout: Optional[float] = None,
) -> ClientConfig:
    """Settings from the arguments, falling back to ``RUNR_API_URL``, ``RUNR_API_KEY`` and
    ``RUNR_TENANT_ID``."""
    url = api_url or os.environ.get("RUNR_API_URL")
    if not url:
        raise RunrSandboxError(
            None,
            "No RunR API URL: pass api_url=... or set RUNR_API_URL (e.g. https://runr.example.com).",
            "config",
        )
    key = api_key if api_key is not None else os.environ.get("RUNR_API_KEY")
    tenant = tenant_id if tenant_id is not None else os.environ.get("RUNR_TENANT_ID")
    return ClientConfig(
        api_url=api_base_url(url),
        api_key=key or None,
        tenant_id=tenant or None,
        timeout=float(timeout) if timeout is not None else DEFAULT_TIMEOUT,
    )
