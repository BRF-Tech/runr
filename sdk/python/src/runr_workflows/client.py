"""Send events to RunR from anywhere (``POST /api/events`` with an API token)."""

from __future__ import annotations

import json
import os
import urllib.request
from typing import Any, Dict, List, Optional, Union


class EventsClient:
    """>>> EventsClient("https://runr.example.com", token).send("order.created", {"order_id": 1})"""

    def __init__(self, base_url: Optional[str] = None, token: Optional[str] = None, tenant_id: Optional[str] = None,
                 timeout: float = 10.0, opener: Any = None):
        self.base_url = (base_url or os.environ.get("RUNR_API_URL", "")).rstrip("/")
        self.token = token or os.environ.get("RUNR_API_TOKEN")
        self.tenant_id = tenant_id or os.environ.get("RUNR_TENANT_ID")
        self.timeout = timeout
        self._open = opener or urllib.request.urlopen
        if not self.base_url or not self.token:
            raise ValueError("EventsClient needs base_url (RUNR_API_URL) and token (RUNR_API_TOKEN)")

    def send(self, name: str, data: Optional[Dict[str, Any]] = None, *, id: Optional[str] = None,  # noqa: A002
             ts: Optional[int] = None) -> Dict[str, Any]:
        event: Dict[str, Any] = {"name": name, "data": data or {}}
        if id:
            event["id"] = id
        if ts:
            event["ts"] = ts
        return self.send_many([event])

    def send_many(self, events: List[Dict[str, Any]]) -> Dict[str, Any]:
        body = json.dumps({"events": events}).encode("utf-8")
        headers = {"Content-Type": "application/json", "Accept": "application/json", "Authorization": f"Bearer {self.token}"}
        if self.tenant_id:
            headers["X-Tenant-ID"] = self.tenant_id
        request = urllib.request.Request(f"{self.base_url}/api/events", data=body, headers=headers, method="POST")
        with self._open(request, timeout=self.timeout) as response:
            return json.loads(response.read().decode("utf-8"))


Events = Union[Dict[str, Any], List[Dict[str, Any]]]
