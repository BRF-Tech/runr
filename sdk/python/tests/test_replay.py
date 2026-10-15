"""Deterministic replay semantics of the Python SDK, driven through the real protocol."""

import asyncio
from datetime import datetime, timedelta, timezone

import pytest

from fake_engine import KEY, FakeEngine
from runr_workflows import NonRetriableError, RetryAfterError, StepError, Workflows


def make():
    return Workflows(signing_key=KEY)


def test_each_step_runs_once_and_is_memoized():
    wf = make()
    calls = {"a": 0, "b": 0}

    @wf.workflow("sum")
    def flow(ctx, step):
        def a():
            calls["a"] += 1
            return ctx.event["data"]["x"] * 2

        def b(v):
            calls["b"] += 1
            return v + 1

        x = step.run("a", a)
        y = step.run("b", b, x)
        return {"x": x, "y": y}

    engine = FakeEngine(wf.handler())
    result = engine.run("sum", {"x": 5})

    assert result == {"status": "completed", "output": {"x": 10, "y": 11}, "steps": result["steps"]}
    assert calls == {"a": 1, "b": 1}
    assert engine.invocations == 3
    assert [op["op"] for op in engine.ops] == ["step_completed", "step_completed", "run_completed"]
    assert engine.ops[0]["id"] == "a" and engine.ops[0]["name"] == "a"
    assert isinstance(engine.ops[0]["duration_ms"], int)


def test_repeated_ids_get_counter_suffixes():
    wf = make()

    @wf.workflow("loop")
    def flow(ctx, step):
        return sum(step.run("add", lambda i=i: i) for i in [1, 2, 3])

    engine = FakeEngine(wf.handler())
    result = engine.run("loop")
    assert result["output"] == 6
    assert list(result["steps"]) == ["add", "add:1", "add:2"]


def test_failures_are_retried_with_attempt_numbers():
    wf = make()
    seen = []

    @wf.workflow("flaky")
    def flow(ctx, step):
        def call():
            seen.append(ctx.attempt)
            if ctx.attempt < 2:
                raise RuntimeError("timeout")
            return "ok"
        return step.run("call", call)

    engine = FakeEngine(wf.handler())
    result = engine.run("flaky")
    assert result["output"] == "ok"
    assert seen == [0, 1, 2]
    failed = [op for op in engine.ops if op["op"] == "step_failed"]
    assert len(failed) == 2
    assert failed[0]["error"]["name"] == "RuntimeError"
    assert failed[0]["error"]["message"] == "timeout"
    assert "retriable" not in failed[0]


def test_non_retriable_and_retry_after():
    wf = make()

    @wf.workflow("fatal")
    def fatal(ctx, step):
        step.run("validate", lambda: (_ for _ in ()).throw(NonRetriableError("bad card")))

    @wf.workflow("later")
    def later(ctx, step):
        def call():
            if ctx.attempt == 0:
                raise RetryAfterError("rate limited", "30s")
            return 1
        return step.run("call", call)

    engine = FakeEngine(wf.handler())
    result = engine.run("fatal")
    assert result["status"] == "failed"
    assert engine.ops[0]["retriable"] is False
    assert engine.ops[1]["op"] == "run_failed" and engine.ops[1]["retriable"] is False
    assert engine.ops[1]["error"]["name"] == "StepError"

    engine = FakeEngine(wf.handler())
    assert engine.run("later")["output"] == 1
    assert engine.ops[0]["retry_after"] == "30s"


def test_retry_after_accepts_timedelta_and_datetime():
    wf = make()
    when = datetime(2030, 1, 1, tzinfo=timezone.utc)

    @wf.workflow("td")
    def td(ctx, step):
        def call():
            if ctx.attempt == 0:
                raise RetryAfterError("x", timedelta(seconds=90))
            if ctx.attempt == 1:
                raise RetryAfterError("y", when)
            return "done"
        return step.run("c", call)

    engine = FakeEngine(wf.handler())
    engine.run("td")
    assert engine.ops[0]["retry_after"] == "90000ms"
    assert engine.ops[1]["retry_after"] == "2030-01-01T00:00:00Z"


def test_a_failed_step_can_be_handled():
    wf = make()

    @wf.workflow("compensate")
    def flow(ctx, step):
        try:
            step.run("reserve", lambda: (_ for _ in ()).throw(NonRetriableError("out of stock")))
        except StepError as exc:
            assert exc.step_id == "reserve"
            assert exc.error_message == "out of stock"
            return step.run("refund", lambda: "refunded")
        return "reserved"

    assert FakeEngine(wf.handler()).run("compensate")["output"] == "refunded"


def test_interrupts_are_not_swallowed_by_except_exception():
    wf = make()

    @wf.workflow("greedy")
    def flow(ctx, step):
        try:
            value = step.run("a", lambda: 1)
        except Exception:  # noqa: BLE001 — user code catching everything
            return "swallowed"
        return value

    assert FakeEngine(wf.handler()).run("greedy")["output"] == 1


def test_errors_outside_steps_are_retriable_run_failures():
    wf = make()
    calls = []

    @wf.workflow("crash")
    def flow(ctx, step):
        calls.append(1)
        raise KeyError("missing")

    engine = FakeEngine(wf.handler(), max_attempts=3)
    result = engine.run("crash")
    assert result["status"] == "failed"
    assert len(calls) == 3
    assert all(op["retriable"] is True for op in engine.ops)


def test_sleep_wait_invoke_and_send_ops():
    wf = make()

    @wf.workflow("everything")
    def flow(ctx, step):
        step.sleep("nap", "1h")
        step.sleep("short", timedelta(seconds=2))
        step.sleep("seconds", 5)
        step.sleep_until("until", datetime(2030, 1, 1, 12, 0))
        paid = step.wait_for_event("paid", "order.paid", match="data.order_id", timeout="7d", if_="async.data.ok")
        child = step.invoke("child", "double", {"n": 2}, timeout="1h")
        sent = step.send_event("notify", {"name": "order.done", "data": {"id": 1}})
        missing = step.wait_for_event("never", "never.happens", timeout=60)
        return {"paid": paid["data"]["amount"], "child": child, "sent": sent, "missing": missing}

    engine = FakeEngine(wf.handler())
    engine.events["order.paid"] = {"id": "e9", "name": "order.paid", "data": {"amount": 12}, "ts": 1}
    engine.events["never.happens"] = None
    engine.children["double"] = 4
    result = engine.run("everything")

    assert result["output"] == {"paid": 12, "child": 4, "sent": {"ids": ["e0"]}, "missing": None}
    ops = {op["id"]: op for op in engine.ops if "id" in op}
    assert ops["nap"] == {"op": "sleep", "id": "nap", "name": "nap", "duration": "1h"}
    assert ops["short"]["duration"] == "2000ms"
    assert ops["seconds"]["duration"] == "5000ms"
    assert ops["until"]["until"] == "2030-01-01T12:00:00Z"
    assert ops["paid"] == {"op": "wait_for_event", "id": "paid", "name": "paid", "event": "order.paid",
                           "match": "data.order_id", "if": "async.data.ok", "timeout": "7d"}
    assert ops["child"] == {"op": "invoke", "id": "child", "name": "child", "workflow": "double", "data": {"n": 2}, "timeout": "1h"}
    assert ops["notify"]["events"] == [{"name": "order.done", "data": {"id": 1}}]
    assert ops["never"]["timeout"] == "60000ms"
    assert engine.sent_events == [{"name": "order.done", "data": {"id": 1}}]


def test_a_failed_invoke_raises_step_error():
    wf = make()

    @wf.workflow("parent")
    def flow(ctx, step):
        try:
            step.invoke("c", "child")
        except StepError as exc:
            return exc.error_name
        return "ok"

    engine = FakeEngine(wf.handler())
    engine.children["child"] = RuntimeError("child failed")
    assert engine.run("parent")["output"] == "InvokeFailed"


def test_async_workflows_and_async_step_functions():
    wf = make()

    async def fetch(n):
        await asyncio.sleep(0)
        return n * 3

    @wf.workflow("async-flow")
    async def flow(ctx, step):
        a = await step.run("fetch", fetch, 2)
        await step.sleep("nap", "1s")
        b = await step.run("sync-inside", lambda: a + 1)
        return [a, b]

    @wf.workflow("sync-calls-async")
    def sync_flow(ctx, step):
        return step.run("fetch", fetch, 5)

    assert FakeEngine(wf.handler()).run("async-flow")["output"] == [6, 7]
    assert FakeEngine(wf.handler()).run("sync-calls-async")["output"] == 15


def test_non_json_outputs_fail_without_retry():
    wf = make()

    @wf.workflow("bad-output")
    def flow(ctx, step):
        step.run("obj", lambda: object())

    engine = FakeEngine(wf.handler())
    assert engine.run("bad-output")["status"] == "failed"
    assert engine.ops[0]["op"] == "step_failed" and engine.ops[0]["retriable"] is False


def test_nested_steps_are_rejected():
    wf = make()

    @wf.workflow("nested")
    def flow(ctx, step):
        return step.run("outer", lambda: step.run("inner", lambda: 1))

    engine = FakeEngine(wf.handler(), max_attempts=1)
    assert engine.run("nested")["status"] == "failed"
    assert "nested" in engine.ops[0]["error"]["message"]


def test_registration_validation():
    wf = make()
    with pytest.raises(ValueError):
        wf.workflow("bad name")
    wf.add("ok", lambda ctx, step: 1)
    with pytest.raises(ValueError):
        wf.add("ok", lambda ctx, step: 2)
    with pytest.raises(ValueError):
        wf.workflow("")
    assert wf.names == ["ok"]
    assert wf.execute({"workflow": "ok", "steps": {}, "run": {}}) == {"op": "run_completed", "output": 1}
    assert wf.execute({"workflow": "nope"})["error"]["name"] == "WorkflowNotFound"
