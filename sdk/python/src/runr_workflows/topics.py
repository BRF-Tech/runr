"""Publish events to the organization's topics from a function (docs/triggers.md#topics).

Every project with a topic trigger on the topic receives the event as a CloudEvent. RunR sets
``RUNR_TOPICS_URL`` and ``RUNR_TOPICS_TOKEN`` for functions in a region where their
organization has a topic::

    from runr_workflows import publish

    publish("orders", {"order_id": "o-1"}, type="order.created", subject="o-1")
"""

from __future__ import annotations

import json
import os
import urllib.error
import urllib.parse
import urllib.request
from typing import Any, Dict, Optional

from .errors import PublishError

TOPICS_URL_ENV = "RUNR_TOPICS_URL"
TOPICS_TOKEN_ENV = "RUNR_TOPICS_TOKEN"

_JSON = "application/json"


class Topics:
    """>>> Topics().publish("orders", {"order_id": "o-1"}, type="order.created")"""

    def __init__(self, url: Optional[str] = None, token: Optional[str] = None, timeout: float = 10.0,
                 opener: Any = None):
        self.url = (url or os.environ.get(TOPICS_URL_ENV, "")).rstrip("/")
        self.token = token or os.environ.get(TOPICS_TOKEN_ENV)
        self.timeout = timeout
        self._open = opener or urllib.request.urlopen
        if not self.url or not self.token:
            raise ValueError(f"Topics needs url ({TOPICS_URL_ENV}) and token ({TOPICS_TOKEN_ENV}); RunR sets them "
                             "for functions in a region where the organization has a topic")

    def publish(self, topic: str, data: Any = None, *, type: Optional[str] = None,  # noqa: A002
                subject: Optional[str] = None, id: Optional[str] = None,  # noqa: A002
                content_type: Optional[str] = None) -> Dict[str, Any]:
        """Publish one event; returns ``{"id", "stream_id"}``.

        Data is sent as JSON. With ``content_type``, ``str`` and bytes are sent as is (a ``str``
        UTF-8 encoded) and anything else as JSON. ``type``, ``subject`` and ``id`` become the
        CloudEvents headers ``ce-type``, ``ce-subject`` and ``ce-id``. Raises ``PublishError``
        when the publisher answers with a status that is not 2xx.
        """
        if not topic:
            raise ValueError("publish needs a topic name")
        body, ctype = _encode(data, content_type)
        headers = {"Content-Type": ctype, "Accept": _JSON, "Authorization": f"Bearer {self.token}"}
        for header, value in (("ce-type", type), ("ce-subject", subject), ("ce-id", id)):
            if value:
                headers[header] = value
        url = f"{self.url}/v1/topics/{urllib.parse.quote(topic, safe='')}/events"
        request = urllib.request.Request(url, data=body, headers=headers, method="POST")
        try:
            with self._open(request, timeout=self.timeout) as response:
                status = getattr(response, "status", None) or response.getcode()
                raw = response.read()
        except urllib.error.HTTPError as e:
            raise PublishError(topic, e.code, _text(e.read())) from None
        if not 200 <= int(status) < 300:
            raise PublishError(topic, int(status), _text(raw))
        return json.loads(raw.decode("utf-8"))


def publish(topic: str, data: Any = None, *, type: Optional[str] = None,  # noqa: A002
            subject: Optional[str] = None, id: Optional[str] = None,  # noqa: A002
            content_type: Optional[str] = None) -> Dict[str, Any]:
    """Publish one event with the environment's topic publisher (``RUNR_TOPICS_URL``, ``RUNR_TOPICS_TOKEN``)."""
    return Topics().publish(topic, data, type=type, subject=subject, id=id, content_type=content_type)


def _encode(data: Any, content_type: Optional[str]) -> "tuple[bytes, str]":
    if content_type:
        if isinstance(data, (bytes, bytearray, memoryview)):
            return bytes(data), content_type
        if isinstance(data, str):
            return data.encode("utf-8"), content_type
        return _json(data), content_type
    return _json(data), _JSON


def _json(data: Any) -> bytes:
    return json.dumps(data, separators=(",", ":"), ensure_ascii=False).encode("utf-8")


def _text(raw: Optional[bytes]) -> str:
    return (raw or b"").decode("utf-8", errors="replace")
