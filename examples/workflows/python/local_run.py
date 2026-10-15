"""Drive main.py locally through the real protocol, playing the engine (no cluster needed).

    pip install -e ../../../sdk/python
    RUNR_WORKFLOW_SIGNING_KEY=rwk_local python local_run.py
"""

import json
import os

from runr_workflows import protocol

os.environ.setdefault("RUNR_WORKFLOW_SIGNING_KEY", "rwk_local")

import main  # noqa: E402  (reads the key at request time)

KEY = os.environ["RUNR_WORKFLOW_SIGNING_KEY"]


def call(request):
    env = protocol.envelope(request, [KEY])
    res = main.handler(env, {"headers": {"X-RunR-Signature": env["signature"]}})
    return res["body"]


def run(workflow, data, events=None):
    steps, attempts = {}, {}
    event = {"id": "local", "name": "local/start", "data": data, "ts": 0}
    while True:
        retrying = [k for k in attempts if k not in steps]
        body = call({"type": "execute", "workflow": workflow, "event": event, "steps": steps,
                     "run": {"id": "local-run", "attempt": attempts.get(retrying[0], 0) if retrying else 0}})
        op = body["ops"][0]
        print(json.dumps(op)[:160])
        kind = op["op"]
        if kind in ("run_completed", "run_failed"):
            return op
        if kind == "step_failed" and op.get("retriable", True) and attempts.get(op["id"], 0) < 3:
            attempts[op["id"]] = attempts.get(op["id"], 0) + 1
            continue
        if kind == "step_failed":
            steps[op["id"]] = {"status": "failed", "error": op["error"]}
        elif kind == "wait_for_event":
            steps[op["id"]] = {"status": "completed", "output": (events or {}).get(op["event"])}
        elif kind == "invoke":
            child = run(op["workflow"], op.get("data") or {})
            steps[op["id"]] = {"status": "completed", "output": child.get("output")}
        elif kind == "send_event":
            steps[op["id"]] = {"status": "completed", "output": {"ids": ["local-event"]}}
        else:
            steps[op["id"]] = {"status": "completed", "output": op.get("output")}


if __name__ == "__main__":
    print(call({"type": "introspect"})["workflows"][0]["name"])
    print(run("process-order", {"order_id": "o-1", "customer_id": "c-1", "total": 120, "items": ["book"]},
              events={"order.shipped": {"id": "e", "name": "order.shipped", "data": {"order_id": "o-1", "tracking": "T-1"}, "ts": 0}}))
    print(run("nightly-report", {}))
