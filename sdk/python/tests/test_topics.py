"""publish(): the request to the topic publisher and its errors — against a fake opener and a
local HTTP server."""

import json
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

from runr_workflows import PublishError, Topics, publish


class FakeResponse:
    def __init__(self, status, body):
        self.status = status
        self._body = body

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class FakeOpener:
    """Records requests; answers 202 {id, stream_id}, or the status it was given."""

    def __init__(self, status=202, body=None):
        self.requests = []
        self.status = status
        self.body = body

    def __call__(self, request, timeout=None):
        self.requests.append(request)
        body = self.body
        if body is None:
            body = json.dumps({"id": request.get_header("Ce-id") or "evt-generated",
                               "stream_id": f"1700000000000-{len(self.requests)}"}).encode()
        return FakeResponse(self.status, body)


def test_publish_json_with_cloudevents_headers():
    opener = FakeOpener()
    topics = Topics("http://runr-topics.ns.svc.cluster.local:8080/", "tok", opener=opener)
    res = topics.publish("orders", {"order_id": "o-1", "name": "é"}, type="order.created", subject="o-1", id="evt-1")

    assert res == {"id": "evt-1", "stream_id": "1700000000000-1"}
    req = opener.requests[0]
    assert req.full_url == "http://runr-topics.ns.svc.cluster.local:8080/v1/topics/orders/events"
    assert req.get_method() == "POST"
    assert req.data == '{"order_id":"o-1","name":"é"}'.encode("utf-8")
    # urllib stores header names capitalized
    assert req.get_header("Authorization") == "Bearer tok"
    assert req.get_header("Content-type") == "application/json"
    assert req.get_header("Ce-type") == "order.created"
    assert req.get_header("Ce-subject") == "o-1"
    assert req.get_header("Ce-id") == "evt-1"


def test_publish_without_options_and_encoded_topic():
    opener = FakeOpener()
    Topics("http://pub", "tok", opener=opener).publish("team.a/b", "hello")
    req = opener.requests[0]
    assert req.full_url == "http://pub/v1/topics/team.a%2Fb/events"
    assert req.data == b'"hello"'  # JSON without a content type
    assert not [h for h in req.header_items() if h[0].lower().startswith("ce-")]


def test_raw_data_with_content_type():
    opener = FakeOpener()
    topics = Topics("http://pub", "tok", opener=opener)
    topics.publish("logs", "plain text", content_type="text/plain")
    topics.publish("blobs", b"\x00\x01", content_type="application/octet-stream")
    topics.publish("cloud", {"a": 1}, content_type="application/cloudevents+json")
    assert opener.requests[0].data == b"plain text"
    assert opener.requests[0].get_header("Content-type") == "text/plain"
    assert opener.requests[1].data == b"\x00\x01"
    assert opener.requests[2].data == b'{"a":1}'
    assert opener.requests[2].get_header("Content-type") == "application/cloudevents+json"


def test_non_2xx_answer_raises_publish_error():
    opener = FakeOpener(status=500, body=b"publisher down")
    with pytest.raises(PublishError) as info:
        Topics("http://pub", "tok", opener=opener).publish("orders", {})
    assert info.value.status == 500
    assert info.value.body == "publisher down"
    assert "HTTP 500" in str(info.value)


def test_environment_and_missing_settings(monkeypatch):
    monkeypatch.delenv("RUNR_TOPICS_URL", raising=False)
    monkeypatch.delenv("RUNR_TOPICS_TOKEN", raising=False)
    with pytest.raises(ValueError, match="RUNR_TOPICS_URL"):
        Topics()
    with pytest.raises(ValueError, match="RUNR_TOPICS_TOKEN"):
        Topics(url="http://pub")
    with pytest.raises(ValueError, match="topic"):
        Topics("http://pub", "tok", opener=FakeOpener()).publish("", {})

    monkeypatch.setenv("RUNR_TOPICS_URL", "http://env-pub:8080")
    monkeypatch.setenv("RUNR_TOPICS_TOKEN", "env-token")
    topics = Topics(opener=FakeOpener())
    assert topics.url == "http://env-pub:8080" and topics.token == "env-token"


class _Publisher(BaseHTTPRequestHandler):
    """A local topic publisher: 202 for the token "good", 403 otherwise."""

    received = []

    def do_POST(self):  # noqa: N802
        body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        type(self).received.append((self.path, {k.lower(): v for k, v in self.headers.items()}, body))
        if self.headers.get("Authorization") != "Bearer good":
            self._answer(403, b'{"message":"topic not allowed"}')
            return
        self._answer(202, json.dumps({"id": self.headers.get("ce-id", "gen-1"), "stream_id": "1-0"}).encode())

    def _answer(self, status, body):
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


@pytest.fixture
def publisher():
    _Publisher.received = []
    server = HTTPServer(("127.0.0.1", 0), _Publisher)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{server.server_port}"
    finally:
        server.shutdown()
        server.server_close()


def test_publish_over_http(publisher, monkeypatch):
    monkeypatch.setenv("RUNR_TOPICS_URL", publisher)
    monkeypatch.setenv("RUNR_TOPICS_TOKEN", "good")
    assert publish("orders", [1, 2], id="evt-9") == {"id": "evt-9", "stream_id": "1-0"}
    path, headers, body = _Publisher.received[0]
    assert path == "/v1/topics/orders/events"
    assert body == b"[1,2]"
    assert headers["content-type"] == "application/json" and headers["authorization"] == "Bearer good"

    monkeypatch.setenv("RUNR_TOPICS_TOKEN", "bad")
    with pytest.raises(PublishError) as info:
        publish("orders", {"a": 1})
    assert info.value.status == 403
    assert info.value.topic == "orders"
    assert "topic not allowed" in info.value.body
