"""Errors a workflow raises or receives."""

from __future__ import annotations

from typing import Any, Dict, Optional, Union
from datetime import datetime, timedelta


class NonRetriableError(Exception):
    """Raise inside ``step.run`` (or the workflow) to fail without further retries."""


class RetryAfterError(Exception):
    """Raise inside ``step.run`` to retry after a given delay instead of the backoff.

    ``retry_after`` is a duration ("30s", "5m"), a number of seconds, a ``timedelta`` or
    an absolute ``datetime``.
    """

    def __init__(self, message: str, retry_after: Union[str, int, float, timedelta, datetime]):
        super().__init__(message)
        self.retry_after = retry_after


class StepError(Exception):
    """A step failed for good (retries exhausted or non-retriable).

    Raised into the workflow on the invocation after the failure; catch it to compensate.
    An uncaught ``StepError`` fails the run.
    """

    def __init__(self, step_id: str, name: Optional[str], message: Optional[str]):
        super().__init__(f"step {step_id} failed: {message or ''}".rstrip())
        self.step_id = step_id
        self.error_name = name or "Error"
        self.error_message = message or ""


class SignatureError(Exception):
    """The request was not signed by the RunR engine."""


class PublishError(Exception):
    """The topic publisher refused an event (401 bad token, 403 topic not allowed, 413 too large, …)."""

    def __init__(self, topic: str, status: int, body: str = ""):
        detail = f": {body[:500]}" if body else ""
        super().__init__(f"publishing to topic {topic} failed: HTTP {status}{detail}")
        self.topic = topic
        self.status = status
        self.body = body


def error_payload(exc: BaseException) -> Dict[str, Any]:
    import traceback

    return {
        "name": type(exc).__name__,
        "message": str(exc),
        "stack": "".join(traceback.format_exception(type(exc), exc, exc.__traceback__))[-16000:],
    }
