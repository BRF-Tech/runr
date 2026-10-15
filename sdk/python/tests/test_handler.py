"""Entry points: RunR runtime handler, WSGI app, introspection, signature enforcement, client."""

import io
import json
import time

import pytest

from fake_engine import KEY
from runr_workflows import EventsClient, SignatureError, Workflows, protocol, verify_request


def make():
    wf = Workflows(signing_key=KEY)

    @wf.workflow("orders", triggers=[{"event": "order.created"}, {"cron": "0 9 * * *"}], retries=2, timeout="1d",
                 concurrency={"limit": 3, "key": "event.data.customer"}, idempotency="event.data.order_id",
                 cancel_on=[{"event": "order.cancelled", "match": "data.order_id"}], description="Order flow")
    def orders(ctx, step):
        return step.run("a", lambda: ctx.run_id)

    @wf.workflow("plain")
    def plain(ctx, step):
        return {"workflow": ctx.workflow, "source": ctx.source, "replay": ctx.replay}

    return wf


def execute_request(workflow="plain", steps=None):
    return {"type": "execute", "protocol": "v1", "ts": 0, "workflow": workflow,
            "run": {"id": "r-1", "attempt": 0, "source": "event", "replay": True},
            "event": {"id": "e", "name": "x", "data": {}, "ts": 1}, "steps": steps or {}, "step_order": []}


def test_introspection_lists_definitions():
    handler = make().handler()
    env = protocol.envelope({"type": "introspect", "protocol": "v1", "ts": 0}, [KEY])
    res = handler(env, {"headers": {"x-runr-signature": env["signature"]}})

    assert res["statusCode"] == 200
    assert res["headers"]["X-RunR-Workflow-Protocol"] == "v1"
    body = res["body"]
    assert body["runr_workflow"] == "v1"
    assert body["sdk"]["name"] == "runr-workflows" and body["sdk"]["language"] == "python"
    names = [w["name"] for w in body["workflows"]]
    assert names == ["orders", "plain"]
    orders = body["workflows"][0]
    assert orders["triggers"] == [{"event": "order.created"}, {"cron": "0 9 * * *"}]
    assert orders["retries"] == 2 and orders["timeout"] == "1d"
    assert orders["concurrency"] == {"limit": 3, "key": "event.data.customer"}
    assert orders["idempotency"] == "event.data.order_id"
    assert orders["cancel_on"] == [{"event": "order.cancelled", "match": "data.order_id"}]
    assert orders["description"] == "Order flow"
    assert "retries" not in body["workflows"][1], "unset options are left to the engine defaults"


def test_execute_through_the_runtime_handler_and_context():
    handler = make().handler()
    env = protocol.envelope(execute_request(), [KEY])
    res = handler(env, {"headers": {"X-RunR-Signature": env["signature"]}})

    assert res["body"]["ops"] == [{"op": "run_completed", "output": {"workflow": "plain", "source": "event", "replay": True}}]


def test_signature_from_the_envelope_when_headers_are_missing():
    env = protocol.envelope(execute_request(), [KEY])
    assert make().handler()(env, None)["statusCode"] == 200


def test_unsigned_wrongly_signed_or_stale_requests_are_rejected():
    handler = make().handler()
    raw = protocol.encode(execute_request())

    unsigned = {"runr_workflow": "v1", "request": raw}
    assert handler(unsigned, {})["statusCode"] == 401

    wrong = protocol.envelope(execute_request(), ["rwk_other"])
    assert handler(wrong, {})["statusCode"] == 401

    stale = {"runr_workflow": "v1", "request": raw, "signature": protocol.sign(raw, [KEY], int(time.time()) - 600)}
    assert handler(stale, {})["statusCode"] == 401

    # A tampered request with the original signature
    env = protocol.envelope(execute_request(), [KEY])
    env["request"] = env["request"].replace('"plain"', '"orders"')
    assert handler(env, {})["statusCode"] == 401


def test_non_protocol_bodies_get_400():
    handler = make().handler()
    assert handler({"hello": "world"}, {})["statusCode"] == 400
    assert handler("text", {})["statusCode"] == 400
    env = protocol.envelope({"type": "teleport"}, [KEY])
    assert handler(env, {})["statusCode"] == 400


def test_missing_signing_key_is_a_500(monkeypatch):
    monkeypatch.delenv("RUNR_WORKFLOW_SIGNING_KEY", raising=False)
    monkeypatch.delenv("RUNR_WORKFLOW_SIGNING_KEY_FALLBACK", raising=False)
    wf = Workflows()
    env = protocol.envelope(execute_request(), [KEY])
    res = wf.handler()(env, {})
    assert res["statusCode"] == 500
    assert "RUNR_WORKFLOW_SIGNING_KEY" in res["body"]["error"]


def test_keys_from_the_environment_with_fallback(monkeypatch):
    monkeypatch.setenv("RUNR_WORKFLOW_SIGNING_KEY", "rwk_new")
    monkeypatch.setenv("RUNR_WORKFLOW_SIGNING_KEY_FALLBACK", KEY)
    wf = Workflows()
    wf.add("plain", lambda ctx, step: "ok")
    env = protocol.envelope(execute_request(), [KEY])
    assert wf.handler()(env, {})["statusCode"] == 200


def test_wsgi_app():
    app = make().wsgi()
    env = protocol.envelope(execute_request(), [KEY])
    body = json.dumps(env).encode()
    captured = {}

    def start_response(status, headers):
        captured["status"] = status
        captured["headers"] = dict(headers)

    out = b"".join(app({
        "REQUEST_METHOD": "POST", "CONTENT_LENGTH": str(len(body)), "wsgi.input": io.BytesIO(body),
        "HTTP_X_RUNR_SIGNATURE": env["signature"],
    }, start_response))

    assert captured["status"] == "200 OK"
    assert captured["headers"]["X-RunR-Workflow-Protocol"] == "v1"
    assert json.loads(out)["ops"][0]["op"] == "run_completed"

    out = b"".join(app({"REQUEST_METHOD": "GET", "wsgi.input": io.BytesIO(b"")}, start_response))
    assert captured["status"].startswith("405")


def test_handle_raw_with_list_headers():
    wf = make()
    env = protocol.envelope(execute_request(), [KEY])
    status, body = wf.handle_raw(json.dumps(env), {"X-Runr-Signature": [env["signature"]]})
    assert status == 200
    status, _ = wf.handle_raw(b"not json")
    assert status == 400


def test_verify_request_helper():
    env = protocol.envelope({"type": "introspect"}, [KEY])
    assert verify_request(env, [KEY]) == {"type": "introspect"}
    with pytest.raises(SignatureError):
        verify_request(env, ["rwk_wrong"])


def test_events_client_posts_events():
    sent = {}

    class Response:
        def __enter__(self):
            return self

        def __exit__(self, *a):
            return False

        def read(self):
            return b'{"ids":["01J"]}'

    def opener(request, timeout):
        sent["url"] = request.full_url
        sent["headers"] = dict(request.header_items())
        sent["body"] = json.loads(request.data)
        sent["timeout"] = timeout
        return Response()

    client = EventsClient("https://runr.example.com/", "tok", tenant_id="t-1", opener=opener)
    assert client.send("order.created", {"order_id": 1}, id="o-1") == {"ids": ["01J"]}
    assert sent["url"] == "https://runr.example.com/api/events"
    assert sent["headers"]["Authorization"] == "Bearer tok"
    assert sent["headers"]["X-tenant-id"] == "t-1"
    assert sent["body"] == {"events": [{"name": "order.created", "data": {"order_id": 1}, "id": "o-1"}]}

    with pytest.raises(ValueError):
        EventsClient("", "")
