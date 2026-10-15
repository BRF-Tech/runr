"""Deterministic replay: the ``step`` object a workflow receives.

Every invocation re-runs the workflow from the top. A step whose result the engine sent back
(memoized) returns that result immediately; the first step without a result runs (``run``) or
is reported (``sleep``, ``wait_for_event``, ``invoke``, ``send_event``) and the invocation
ends with that op. Step ids must be stable between invocations; a repeated id gets a counter
suffix (``"add"``, ``"add:1"``, ...).
"""

from __future__ import annotations

import asyncio
import inspect
import json
import time
from datetime import datetime, timedelta, timezone
from typing import Any, Callable, Dict, List, Optional, Union

from .errors import NonRetriableError, RetryAfterError, StepError, error_payload

Duration = Union[str, int, float, timedelta]


class Interrupt(BaseException):
    """Ends an invocation with an op. A BaseException so ``except Exception`` in user code
    does not swallow it."""

    def __init__(self, op: Dict[str, Any]):
        super().__init__(op.get("op"))
        self.op = op


def duration_string(value: Duration) -> str:
    if isinstance(value, timedelta):
        return f"{int(value.total_seconds() * 1000)}ms"
    if isinstance(value, bool):
        raise TypeError("duration must be a string, a number of seconds or a timedelta")
    if isinstance(value, (int, float)):
        if value < 0:
            raise ValueError("duration must not be negative")
        return f"{int(round(value * 1000))}ms"
    if isinstance(value, str) and value.strip():
        return value.strip()
    raise TypeError("duration must be a string, a number of seconds or a timedelta")


def instant_string(value: Union[str, datetime]) -> str:
    if isinstance(value, datetime):
        if value.tzinfo is None:
            value = value.replace(tzinfo=timezone.utc)
        return value.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")
    if isinstance(value, str) and value.strip():
        return value.strip()
    raise TypeError("until must be a datetime or an ISO-8601 string")


def _json_value(value: Any, what: str) -> Any:
    try:
        json.dumps(value, allow_nan=False)
    except (TypeError, ValueError) as exc:
        raise NonRetriableError(f"{what} is not JSON serializable: {exc}") from None
    return value


def _resolve(value: Any) -> Any:
    if inspect.isawaitable(value):
        async def _wait():
            return await value
        return asyncio.run(_wait())
    return value


class Step:
    """Step tools of one invocation."""

    def __init__(self, steps: Optional[Dict[str, Any]] = None, attempt: int = 0):
        self._steps: Dict[str, Any] = steps or {}
        self._seen: Dict[str, int] = {}
        self._attempt = attempt
        self._running = False

    # ── helpers ──────────────────────────────────────────────────────────

    def _key(self, step_id: str) -> str:
        if not isinstance(step_id, str) or not step_id:
            raise ValueError("step id must be a non-empty string")
        if len(step_id.encode("utf-8")) > 200:
            raise ValueError("step id must be at most 200 bytes")
        if self._running:
            raise RuntimeError("steps cannot be nested inside step.run")
        n = self._seen.get(step_id, 0)
        self._seen[step_id] = n + 1
        return step_id if n == 0 else f"{step_id}:{n}"

    def _memo(self, key: str) -> Optional[Dict[str, Any]]:
        memo = self._steps.get(key)
        return memo if isinstance(memo, dict) else None

    @staticmethod
    def _unwrap(key: str, memo: Dict[str, Any]) -> Any:
        if memo.get("status") == "failed":
            err = memo.get("error") or {}
            raise StepError(key, err.get("name"), err.get("message"))
        return memo.get("output")

    # ── step tools ───────────────────────────────────────────────────────

    def run(self, step_id: str, fn: Callable[..., Any], *args: Any, **kwargs: Any) -> Any:
        """Run ``fn`` once; later invocations get its memoized result. Retried on failure."""
        key = self._key(step_id)
        memo = self._memo(key)
        if memo is not None:
            return self._unwrap(key, memo)

        started = time.monotonic()
        self._running = True
        try:
            output = _resolve(fn(*args, **kwargs))
            _json_value(output, f"step {key} output")
        except NonRetriableError as exc:
            raise Interrupt({"op": "step_failed", "id": key, "name": step_id, "error": error_payload(exc), "retriable": False})
        except RetryAfterError as exc:
            after = exc.retry_after
            raise Interrupt({
                "op": "step_failed", "id": key, "name": step_id, "error": error_payload(exc),
                "retry_after": instant_string(after) if isinstance(after, datetime) else duration_string(after),
            })
        except Exception as exc:  # noqa: BLE001 — every failure of user code is reported
            raise Interrupt({"op": "step_failed", "id": key, "name": step_id, "error": error_payload(exc)})
        finally:
            self._running = False

        raise Interrupt({
            "op": "step_completed", "id": key, "name": step_id, "output": output,
            "duration_ms": int((time.monotonic() - started) * 1000),
        })

    def sleep(self, step_id: str, duration: Duration) -> None:
        """Pause the run for ``duration`` ("30s", "2h", "1d", seconds or a timedelta)."""
        key = self._key(step_id)
        if self._memo(key) is not None:
            return None
        raise Interrupt({"op": "sleep", "id": key, "name": step_id, "duration": duration_string(duration)})

    def sleep_until(self, step_id: str, until: Union[str, datetime]) -> None:
        """Pause the run until an absolute time."""
        key = self._key(step_id)
        if self._memo(key) is not None:
            return None
        raise Interrupt({"op": "sleep", "id": key, "name": step_id, "until": instant_string(until)})

    def wait_for_event(self, step_id: str, event: str, *, match: Optional[str] = None,
                       if_: Optional[str] = None, timeout: Optional[Duration] = None) -> Optional[Dict[str, Any]]:
        """Wait for ``event``; returns the event ({id, name, data, ts}) or None on timeout.

        ``match="data.order_id"`` requires the field to equal the trigger event's;
        ``if_`` is an expression over ``event`` (trigger) and ``async`` (incoming).
        """
        key = self._key(step_id)
        memo = self._memo(key)
        if memo is not None:
            return self._unwrap(key, memo)
        op: Dict[str, Any] = {"op": "wait_for_event", "id": key, "name": step_id, "event": event}
        if match:
            op["match"] = match
        if if_:
            op["if"] = if_
        if timeout is not None:
            op["timeout"] = duration_string(timeout)
        raise Interrupt(op)

    def invoke(self, step_id: str, workflow: str, data: Optional[Dict[str, Any]] = None,
               timeout: Optional[Duration] = None) -> Any:
        """Run another workflow of the project and return its output (StepError if it fails)."""
        key = self._key(step_id)
        memo = self._memo(key)
        if memo is not None:
            return self._unwrap(key, memo)
        op: Dict[str, Any] = {"op": "invoke", "id": key, "name": step_id, "workflow": workflow,
                              "data": _json_value(data if data is not None else {}, "invoke data")}
        if timeout is not None:
            op["timeout"] = duration_string(timeout)
        raise Interrupt(op)

    def send_event(self, step_id: str, events: Union[Dict[str, Any], List[Dict[str, Any]]]) -> Dict[str, Any]:
        """Send one or more events ({name, data, id?}); returns {"ids": [...]}."""
        key = self._key(step_id)
        memo = self._memo(key)
        if memo is not None:
            return self._unwrap(key, memo)
        batch = [events] if isinstance(events, dict) else list(events)
        raise Interrupt({"op": "send_event", "id": key, "name": step_id, "events": _json_value(batch, "events")})


class AsyncStep(Step):
    """Step tools for ``async def`` workflows: the same methods as coroutines."""

    async def run(self, step_id: str, fn: Callable[..., Any], *args: Any, **kwargs: Any) -> Any:  # type: ignore[override]
        key = self._key(step_id)
        memo = self._memo(key)
        if memo is not None:
            return self._unwrap(key, memo)

        started = time.monotonic()
        self._running = True
        try:
            output = fn(*args, **kwargs)
            if inspect.isawaitable(output):
                output = await output
            _json_value(output, f"step {key} output")
        except NonRetriableError as exc:
            raise Interrupt({"op": "step_failed", "id": key, "name": step_id, "error": error_payload(exc), "retriable": False})
        except RetryAfterError as exc:
            after = exc.retry_after
            raise Interrupt({
                "op": "step_failed", "id": key, "name": step_id, "error": error_payload(exc),
                "retry_after": instant_string(after) if isinstance(after, datetime) else duration_string(after),
            })
        except Exception as exc:  # noqa: BLE001
            raise Interrupt({"op": "step_failed", "id": key, "name": step_id, "error": error_payload(exc)})
        finally:
            self._running = False

        raise Interrupt({
            "op": "step_completed", "id": key, "name": step_id, "output": output,
            "duration_ms": int((time.monotonic() - started) * 1000),
        })

    async def sleep(self, step_id: str, duration: Duration) -> None:  # type: ignore[override]
        return Step.sleep(self, step_id, duration)

    async def sleep_until(self, step_id: str, until: Union[str, datetime]) -> None:  # type: ignore[override]
        return Step.sleep_until(self, step_id, until)

    async def wait_for_event(self, step_id: str, event: str, **kwargs: Any) -> Optional[Dict[str, Any]]:  # type: ignore[override]
        return Step.wait_for_event(self, step_id, event, **kwargs)

    async def invoke(self, step_id: str, workflow: str, data: Optional[Dict[str, Any]] = None,  # type: ignore[override]
                     timeout: Optional[Duration] = None) -> Any:
        return Step.invoke(self, step_id, workflow, data, timeout)

    async def send_event(self, step_id: str, events: Union[Dict[str, Any], List[Dict[str, Any]]]) -> Dict[str, Any]:  # type: ignore[override]
        return Step.send_event(self, step_id, events)
