"""Exceptions raised by the RunR Sandbox SDK."""

from __future__ import annotations

from typing import Any, Optional

__all__ = [
    "RunrSandboxError",
    "AuthError",
    "NotFoundError",
    "ConflictError",
    "ValidationError",
    "QuotaError",
    "PayloadTooLargeError",
    "RateLimitError",
    "SandboxStartTimeout",
    "error_from_payload",
]


class RunrSandboxError(Exception):
    """An error answered by the RunR API, or raised by the SDK itself.

    Attributes:
        status: HTTP status code (``None`` for errors raised without a response, such as an
            ``error`` event of a stream).
        message: Human-readable message (the API's ``message`` field).
        reason: Machine-readable reason (the API's ``reason`` field, e.g. ``quota_concurrent``).
        body: The decoded response body (dict) or its text.
    """

    def __init__(
        self,
        status: Optional[int],
        message: str,
        reason: Optional[str] = None,
        body: Any = None,
    ) -> None:
        super().__init__(message)
        self.status = status
        self.message = message
        self.reason = reason
        self.body = body

    def __str__(self) -> str:
        parts = []
        if self.status is not None:
            parts.append(f"HTTP {self.status}")
        if self.reason:
            parts.append(self.reason)
        prefix = f"[{', '.join(parts)}] " if parts else ""
        return f"{prefix}{self.message}"

    def __repr__(self) -> str:
        return (
            f"{type(self).__name__}(status={self.status!r}, message={self.message!r}, "
            f"reason={self.reason!r})"
        )


class AuthError(RunrSandboxError):
    """401 (no or invalid API token) or 403 (the token lacks the ability)."""


class NotFoundError(RunrSandboxError):
    """404: the sandbox (or process, file) does not exist or belongs to another organization."""


class ConflictError(RunrSandboxError):
    """409: the sandbox is not in a state that allows the call (e.g. it already ended)."""


class ValidationError(RunrSandboxError):
    """422: the request was rejected (invalid fields, unavailable region / runtime / class)."""


class QuotaError(ValidationError):
    """422 with a ``quota_*`` reason: the organization's sandbox quota does not allow the call."""


class PayloadTooLargeError(RunrSandboxError):
    """413: an upload or download is larger than the sandbox file limit."""


class RateLimitError(RunrSandboxError):
    """429: too many requests; retry after ``retry_after`` seconds when the API sent it."""

    def __init__(
        self,
        status: Optional[int],
        message: str,
        reason: Optional[str] = None,
        body: Any = None,
        retry_after: Optional[float] = None,
    ) -> None:
        super().__init__(status, message, reason, body)
        self.retry_after = retry_after


class SandboxStartTimeout(RunrSandboxError, TimeoutError):
    """The sandbox did not reach ``running`` within ``start_timeout`` seconds."""


def error_from_payload(
    status: int,
    body: Any,
    text: str = "",
    retry_after: Optional[str] = None,
) -> RunrSandboxError:
    """Map an error response to the matching exception class."""
    message = ""
    reason: Optional[str] = None
    if isinstance(body, dict):
        message = str(body.get("message") or "")
        raw_reason = body.get("reason")
        reason = str(raw_reason) if raw_reason else None
    if not message:
        message = text.strip()[:500] if text and text.strip() else f"HTTP {status}"
    payload = body if body is not None else text

    if status in (401, 403):
        return AuthError(status, message, reason, payload)
    if status == 404:
        return NotFoundError(status, message, reason, payload)
    if status == 409:
        return ConflictError(status, message, reason, payload)
    if status == 413:
        return PayloadTooLargeError(status, message, reason or "file_too_large", payload)
    if status == 422:
        if reason and reason.startswith("quota_"):
            return QuotaError(status, message, reason, payload)
        return ValidationError(status, message, reason, payload)
    if status == 429:
        seconds: Optional[float] = None
        if retry_after:
            try:
                seconds = float(retry_after)
            except ValueError:
                seconds = None
        return RateLimitError(status, message, reason, payload, retry_after=seconds)
    return RunrSandboxError(status, message, reason, payload)
