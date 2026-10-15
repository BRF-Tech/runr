"""Wire format of the RunR workflow protocol v1 (docs/workflows-protocol.md).

Request (engine -> function), HTTP POST, JSON body::

    {"runr_workflow": "v1", "signature": "t=...,v1=...", "request": "<JSON string>"}

The signature is also sent as the ``X-RunR-Signature`` header::

    t=<unix seconds>,v1=<hex HMAC-SHA256(key, "<t>.<request>")>[,v1=...]
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
from typing import Any, Dict, Iterable, List, Optional, Tuple

VERSION = "v1"
HEADER_SIGNATURE = "X-RunR-Signature"
HEADER_PROTOCOL = "X-RunR-Workflow-Protocol"
DEFAULT_TOLERANCE = 300


def encode(value: Any) -> str:
    """Compact JSON, as the engine encodes it."""
    return json.dumps(value, separators=(",", ":"), ensure_ascii=False, allow_nan=False)


def digest(request: str, key: str, timestamp: int) -> str:
    return hmac.new(key.encode("utf-8"), f"{timestamp}.{request}".encode("utf-8"), hashlib.sha256).hexdigest()


def sign(request: str, keys: Iterable[str], timestamp: Optional[int] = None) -> str:
    t = int(timestamp if timestamp is not None else time.time())
    parts = [f"t={t}"] + [f"v1={digest(request, k, t)}" for k in keys]
    return ",".join(parts)


def parse_signature(header: str) -> Tuple[Optional[int], List[str]]:
    t: Optional[int] = None
    v1: List[str] = []
    for part in (header or "").split(","):
        key, _, value = part.strip().partition("=")
        if key == "t" and value.isdigit():
            t = int(value)
        elif key == "v1" and value:
            v1.append(value.lower())
    return t, v1


def verify(request: str, header: str, keys: Iterable[Optional[str]], tolerance: int = DEFAULT_TOLERANCE,
           now: Optional[int] = None) -> bool:
    t, candidates = parse_signature(header)
    if t is None or not candidates:
        return False
    current = int(now if now is not None else time.time())
    if abs(current - t) > tolerance:
        return False
    for key in keys:
        if not key:
            continue
        expected = digest(request, key, t)
        if any(hmac.compare_digest(expected, c) for c in candidates):
            return True
    return False


def envelope(request: Dict[str, Any], keys: Iterable[str], timestamp: Optional[int] = None) -> Dict[str, Any]:
    """Build a signed envelope (used by tests and local tooling to play the engine)."""
    raw = encode(request)
    return {"runr_workflow": VERSION, "signature": sign(raw, list(keys), timestamp), "request": raw}


def response(body: Dict[str, Any]) -> Dict[str, Any]:
    return {"runr_workflow": VERSION, **body}
