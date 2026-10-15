"""A minimal engine for SDK tests: drives a Workflows handler through the real protocol
(signed envelopes, memoized steps, one op per invocation) until the run ends."""

from __future__ import annotations

import json
from typing import Any, Callable, Dict, List, Optional

from runr_workflows import protocol

KEY = "rwk_test_key"


class FakeEngine:
    def __init__(self, handler: Callable[[Any, Any], Dict[str, Any]], key: str = KEY, max_attempts: int = 4):
        self.handler = handler
        self.key = key
        self.max_attempts = max_attempts
        self.invocations = 0
        self.ops: List[Dict[str, Any]] = []
        self.sent_events: List[Dict[str, Any]] = []
        # event to deliver to a waitForEvent, by event name (None = time out)
        self.events: Dict[str, Optional[Dict[str, Any]]] = {}
        self.children: Dict[str, Any] = {}

    def call(self, request: Dict[str, Any]) -> Dict[str, Any]:
        envelope = protocol.envelope(request, [self.key])
        result = self.handler(json.loads(json.dumps(envelope)), {"headers": {"X-RunR-Signature": envelope["signature"]}})
        assert result["statusCode"] == 200, result
        body = json.loads(json.dumps(result["body"]))
        assert body["runr_workflow"] == "v1"
        return body

    def run(self, workflow: str, data: Optional[Dict[str, Any]] = None, max_invocations: int = 50) -> Dict[str, Any]:
        steps: Dict[str, Any] = {}
        attempts: Dict[str, int] = {}
        run_attempt = 0
        event = {"id": "ev1", "name": "test/start", "data": data or {}, "ts": 1700000000000}

        for _ in range(max_invocations):
            self.invocations += 1
            retrying = [k for k, n in attempts.items() if k not in steps]
            attempt = attempts[retrying[0]] if retrying else run_attempt
            body = self.call({
                "type": "execute", "protocol": "v1", "ts": 0, "workflow": workflow,
                "run": {"id": "run-1", "attempt": attempt, "started_at": None, "source": "api", "replay": False, "parent_run_id": None},
                "event": event, "steps": steps, "step_order": list(steps),
            })
            assert len(body["ops"]) == 1
            op = body["ops"][0]
            self.ops.append(op)
            kind = op["op"]

            if kind == "run_completed":
                return {"status": "completed", "output": op.get("output"), "steps": steps}
            if kind == "run_failed":
                run_attempt += 1
                if op.get("retriable", True) and run_attempt < self.max_attempts:
                    continue
                return {"status": "failed", "error": op["error"], "steps": steps}
            if kind == "step_completed":
                steps[op["id"]] = {"status": "completed", "output": op.get("output")}
                run_attempt = 0
            elif kind == "step_failed":
                attempts[op["id"]] = attempts.get(op["id"], 0) + 1
                if op.get("retriable", True) and attempts[op["id"]] < self.max_attempts:
                    continue
                steps[op["id"]] = {"status": "failed", "error": op["error"]}
            elif kind == "sleep":
                steps[op["id"]] = {"status": "completed", "output": None}
            elif kind == "wait_for_event":
                delivered = self.events.get(op["event"])
                steps[op["id"]] = {"status": "completed", "output": delivered}
            elif kind == "invoke":
                child = self.children.get(op["workflow"])
                if isinstance(child, Exception):
                    steps[op["id"]] = {"status": "failed", "error": {"name": "InvokeFailed", "message": str(child)}}
                else:
                    steps[op["id"]] = {"status": "completed", "output": child}
            elif kind == "send_event":
                self.sent_events.extend(op["events"])
                steps[op["id"]] = {"status": "completed", "output": {"ids": [f"e{i}" for i, _ in enumerate(op["events"])]}}
            else:
                raise AssertionError(f"unknown op {kind}")

        raise AssertionError("run did not finish")
