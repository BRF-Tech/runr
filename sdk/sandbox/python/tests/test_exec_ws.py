"""The exec WebSocket (Sandbox.exec_ws, AsyncSandbox.exec_ws) against the fake API, whose WebSocket
side is a hand-written RFC 6455 server (tests/fake_server.py)."""

import asyncio
import base64
import logging
import ssl
import threading

import pytest

from runr_sandbox import AsyncExecSession, AsyncSandbox, AuthError, ExecResult, ExecSession, RunrSandboxError, Sandbox
from runr_sandbox import _api
from runr_sandbox.exec_ws import _connect_options, tls_context
from runr_sandbox.config import resolve_config


def run(coro):
    return asyncio.run(coro)


def ws_body(server):
    return server.state.calls("POST", r"/exec/ws$")[-1]["json"]


def ws_log(server):
    return server.state.ws_logs[-1]


def sent(server):
    return [m for m in ws_log(server)["messages"]]


# ── Sync ────────────────────────────────────────────────────────────────────


def test_stdin_while_the_command_runs(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws(["cat"], stdin="hello\n", env={"A": "1"}, cwd="/workspace", timeout=30) as session:
            assert isinstance(session, ExecSession)
            assert session.exec_id and session.exec_id.startswith("exec-")
            assert session.tty is False and not session.done
            session.send_stdin("more\n")
            session.write(b"\x00\xff")
            session.send_stdin(b"")  # nothing to send
            session.close_stdin()
            result = session.wait()
        assert isinstance(result, ExecResult)
        assert result.exit_code == 0 and result.ok and not result.killed
        assert result.stdout_bytes == b"hello\nmore\n\x00\xff"
        assert session.done and session.exit_code == 0

    body = ws_body(server)
    assert body == {"cmd": ["cat"], "stdin": "hello\n", "env": {"A": "1"}, "cwd": "/workspace", "timeout": 30}
    log = ws_log(server)
    assert sent(server) == [
        {"type": "stdin", "data": base64.b64encode(b"more\n").decode()},
        {"type": "stdin", "data": base64.b64encode(b"\x00\xff").decode()},
        {"type": "stdin_close"},
    ]
    assert log["unmasked"] == 0, "client frames are masked"
    assert log["client_closed"], "the client answered the close frame"
    assert log["headers"]["user-agent"].startswith("runr-sandbox-python/")
    assert "authorization" not in log["headers"], "the token in the URL is the credential"


def test_bytes_stdin_and_string_command(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws("cat", stdin=b"\x01\x02") as session:
            session.close_stdin()
            assert session.wait().stdout_bytes == b"\x01\x02"
    assert ws_body(server) == {"cmd": "cat", "stdin_base64": base64.b64encode(b"\x01\x02").decode()}


def test_events_fragmented_messages_and_pings(server, opts):
    with Sandbox.create(**opts) as sb:
        session = sb.exec_ws("multi")
        events = list(session)
        assert [e.type for e in events] == ["stdout", "stderr", "stdout", "exit"]
        assert events[0].text == "line 1\n" and events[0].exec_id == session.exec_id
        assert events[1].data == b"warning\n"
        assert events[2].data == b"\xff\xfe"
        assert (events[3].exit_code, events[3].duration_ms, events[3].timed_out, events[3].killed) == (3, 21, False, False)
        assert list(session) == [], "the session ends with its exit event"
        result = session.wait()
        assert result.exit_code == 3 and result.stdout == "" and result.stderr == ""
        session.close()
        session.close()
    assert ws_log(server)["pongs"] == [b"are you there"]


def test_wait_collects_the_output(server, opts):
    with Sandbox.create(**opts) as sb:
        result = sb.exec_ws("multi").wait()
    assert result.stdout_bytes == b"line 1\n\xff\xfe" and result.stderr == "warning\n" and result.exit_code == 3


def test_a_command_that_exits_on_its_own(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws("echo hi") as session:
            result = session.wait()
            # The command ended: input is dropped, nothing is sent any more
            session.send_stdin("late\n")
            session.close_stdin()
            session.kill()
            session.resize(10, 10)
    assert result.stdout == "hi\n" and result.exit_code == 0
    assert sent(server) == []


def test_tty_size_and_resize(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws("size", tty=True, cols=120, rows=40) as session:
            assert session.tty is True
            events = iter(session)
            assert next(events).data == b"120x40\r\n"
            session.resize(100, 30)
            assert next(events).data == b"100x30\r\n"
            session.close_stdin()
            assert session.wait().exit_code == 0
    assert ws_body(server) == {"cmd": "size", "tty": True, "cols": 120, "rows": 40}
    assert {"type": "resize", "cols": 100, "rows": 30} in sent(server)


def test_tty_with_the_default_size(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws(["cat"], tty=True) as session:
            session.send_stdin("ls\n")
            session.close_stdin()
            assert session.wait().stdout_bytes == b"ls\r\n"
    assert ws_body(server) == {"cmd": ["cat"], "tty": True}


def test_an_api_without_terminal_support(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws("legacy", tty=True) as session:
            assert session.tty is False
            session.kill()


def test_argument_checks(server, opts):
    with Sandbox.create(**opts) as sb:
        with pytest.raises(ValueError):
            sb.exec_ws()
        with pytest.raises(ValueError):
            sb.exec_ws("ls", code="print(1)")
        with pytest.raises(ValueError, match="tty=True"):
            sb.exec_ws("cat", cols=80)
        with pytest.raises(ValueError):
            sb.exec_ws("cat", tty=True, rows=0)
        with pytest.raises(ValueError):
            sb.exec_ws("cat", tty=True, cols=1001)
        with pytest.raises(ValueError):
            sb.exec_ws("cat", tty=True, cols=True)
        with sb.exec_ws("cat", tty=True) as session:
            with pytest.raises(ValueError):
                session.resize(0, 24)
            with pytest.raises(ValueError):
                session.resize(80, 1001)
            session.kill()
    assert len(server.state.calls("POST", r"/exec/ws$")) == 1


def test_code_with_a_language(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws(code="print(1)", language="python", stdin="x") as session:
            session.close_stdin()
            assert session.wait().stdout == "ran: code\n"
    assert ws_body(server) == {"code": "print(1)", "language": "python", "stdin": "x"}


def test_kill(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws(["sleep", "600"]) as session:
            session.close_stdin()  # sleep reads no input
            session.kill()
            result = session.wait()
            session.kill()  # after the exit: nothing is sent
    assert result.exit_code == 137 and result.killed and not result.ok
    assert sent(server) == [{"type": "stdin_close"}, {"type": "kill"}]


def test_input_from_another_thread(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws("cat") as session:
            def feed():
                for line in ("one\n", "two\n", "three\n"):
                    session.send_stdin(line)
                session.close_stdin()

            thread = threading.Thread(target=feed)
            thread.start()
            out = b"".join(e.data for e in session if e.type == "stdout")
            thread.join(5)
    assert out == b"one\ntwo\nthree\n"
    assert session.exit_code == 0


def test_an_error_message_raises(server, opts):
    with Sandbox.create(**opts) as sb:
        session = sb.exec_ws("error")
        with pytest.raises(RunrSandboxError) as info:
            session.wait()
    assert info.value.message == "The sandbox ended during the command."
    assert info.value.reason == "exec_error" and info.value.status is None


def test_an_expired_session_fails_when_opened(server, opts):
    with Sandbox.create(**opts) as sb:
        with pytest.raises(RunrSandboxError) as info:
            sb.exec_ws("expired")
    assert "expired" in info.value.message
    assert ws_log(server)["client_closed"]


@pytest.mark.parametrize("cmd", ["drop", "abort"])
def test_a_connection_that_ends_without_an_exit(server, opts, cmd):
    with Sandbox.create(**opts) as sb:
        session = sb.exec_ws(cmd)
        events = iter(session)
        assert next(events).data == b"partial\n"
        with pytest.raises(RunrSandboxError) as info:
            next(events)
    assert info.value.reason == "ws_closed"
    token = ws_log(server)["token"]
    assert token not in str(info.value) and "token=[redacted]" in str(info.value)


def test_a_refused_upgrade_maps_the_status(server, opts):
    with Sandbox.create(**opts) as sb:
        with pytest.raises(AuthError) as info:
            sb.exec_ws("badpath")
    assert info.value.status == 401


def test_an_unreachable_websocket(server, opts):
    with Sandbox.create(**opts) as sb:
        with pytest.raises(RunrSandboxError) as info:
            sb.exec_ws("refused")
    assert info.value.reason in ("ws_error", "ws_timeout")
    assert "token=[redacted]" in str(info.value) and "tok-" not in str(info.value)


def test_an_answer_with_the_token_only(server, opts):
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws("token-only") as session:
            session.close_stdin()
            assert session.wait().stdout == "ran: token-only\n"
    assert ws_log(server)["token"].startswith("tok-")


def test_the_token_is_not_logged(server, opts, caplog):
    caplog.set_level(logging.DEBUG, logger="runr_sandbox.ws")
    with Sandbox.create(**opts) as sb:
        with sb.exec_ws("echo hi") as session:
            session.wait()
    token = ws_log(server)["token"]
    messages = [record.getMessage() for record in caplog.records if record.name.startswith("runr_sandbox.ws")]
    assert messages, "websockets logs the handshake at debug level"
    assert any("token=[redacted]" in m for m in messages)
    assert not any(token in m for m in messages)


def test_the_sandbox_must_run(server, opts):
    with Sandbox.create(**opts) as sb:
        sb.pause()
        with pytest.raises(RunrSandboxError) as info:
            sb.exec_ws("cat")
        assert info.value.status == 409


# ── asyncio ─────────────────────────────────────────────────────────────────


def test_async_stdin_and_wait(server, opts):
    async def scenario():
        async with AsyncSandbox.create(**opts) as sb:
            async with sb.exec_ws(["cat"], stdin="a\n") as session:
                assert isinstance(session, AsyncExecSession) and session.tty is False
                await session.send_stdin("b\n")
                await session.write(b"c\n")
                await session.close_stdin()
                return session, await session.wait()

    session, result = run(scenario())
    assert result.stdout == "a\nb\nc\n" and result.exit_code == 0
    assert session.done and session.exit_code == 0
    assert sent(server)[-1] == {"type": "stdin_close"}
    assert ws_log(server)["unmasked"] == 0 and ws_log(server)["client_closed"]


def test_async_iteration_and_pings(server, opts):
    async def scenario():
        async with AsyncSandbox.create(**opts) as sb:
            session = await sb.exec_ws("multi")
            events = [event async for event in session]
            again = [event async for event in session]
            await session.close()
            return events, again

    events, again = run(scenario())
    assert [e.type for e in events] == ["stdout", "stderr", "stdout", "exit"]
    assert events[0].text == "line 1\n" and events[-1].exit_code == 3
    assert again == []
    assert ws_log(server)["pongs"] == [b"are you there"]


def test_async_tty_resize_and_kill(server, opts):
    async def scenario():
        async with AsyncSandbox.create(**opts) as sb:
            async with sb.exec_ws("size", tty=True, cols=90, rows=20) as session:
                assert session.tty
                events = session.__aiter__()
                first = await events.__anext__()
                await session.resize(132, 43)
                second = await events.__anext__()
                await session.kill()
                result = await session.wait()
                await session.kill()
                return first.data, second.data, result

    first, second, result = run(scenario())
    assert (first, second) == (b"90x20\r\n", b"132x43\r\n")
    assert result.exit_code == 137 and result.killed
    assert ws_body(server) == {"cmd": "size", "tty": True, "cols": 90, "rows": 20}
    assert sent(server) == [{"type": "resize", "cols": 132, "rows": 43}, {"type": "kill"}]


def test_async_errors(server, opts):
    async def scenario():
        async with AsyncSandbox.create(**opts) as sb:
            with pytest.raises(ValueError):
                sb.exec_ws("cat", rows=10)
            session = await sb.exec_ws("error")
            with pytest.raises(RunrSandboxError) as failed:
                await session.wait()
            with pytest.raises(RunrSandboxError) as expired:
                await sb.exec_ws("expired")
            with pytest.raises(RunrSandboxError) as dropped:
                async with sb.exec_ws("drop") as dropping:
                    await dropping.wait()
            with pytest.raises(AuthError):
                await sb.exec_ws("badpath")
            with pytest.raises(RunrSandboxError) as refused:
                await sb.exec_ws("refused")
            return failed.value, expired.value, dropped.value, refused.value

    failed, expired, dropped, refused = run(scenario())
    assert failed.reason == "exec_error"
    assert "expired" in expired.message
    assert dropped.reason == "ws_closed" and "token=[redacted]" in str(dropped)
    assert refused.reason in ("ws_error", "ws_timeout") and "tok-" not in str(refused)


# ── URLs, TLS and messages ──────────────────────────────────────────────────


@pytest.mark.parametrize(
    "api_url, url, expected",
    [
        ("https://runr.example.com/api", "/ws/sandbox-exec?token=t", "wss://runr.example.com/ws/sandbox-exec?token=t"),
        ("http://127.0.0.1:8080/api", "/ws/sandbox-exec?token=t", "ws://127.0.0.1:8080/ws/sandbox-exec?token=t"),
        ("https://example.com/console/api", "ws/sandbox-exec?token=t", "wss://example.com/ws/sandbox-exec?token=t"),
        ("https://runr.example.com/api", "wss://ws.example.com/ws/sandbox-exec?token=t", "wss://ws.example.com/ws/sandbox-exec?token=t"),
        ("https://runr.example.com/api", "https://ws.example.com/x?token=t", "wss://ws.example.com/x?token=t"),
        ("https://runr.example.com/api", "http://ws.example.com/x?token=t", "ws://ws.example.com/x?token=t"),
    ],
)
def test_websocket_url(api_url, url, expected):
    assert _api.websocket_url(api_url, url) == expected


def test_session_url_and_redaction():
    assert _api.exec_ws_session_url({"url": "/ws/x?token=a"}) == "/ws/x?token=a"
    assert _api.exec_ws_session_url({"token": "a b"}) == "/ws/sandbox-exec?token=a%20b"
    with pytest.raises(RunrSandboxError):
        _api.exec_ws_session_url({})
    assert _api.redact_token("GET /ws/sandbox-exec?token=abc-123&x=1 HTTP/1.1") == "GET /ws/sandbox-exec?token=[redacted]&x=1 HTTP/1.1"


def test_message_conversion():
    state = _api.ExecSocketState("from-answer")
    assert state.convert('{"type":"start","exec_id":"e1","tty":true}') is None
    assert state.started and state.exec_id == "e1" and state.tty
    assert state.convert("not json") is None and state.convert("[1]") is None and state.convert('{"type":"later"}') is None
    with pytest.raises(RunrSandboxError) as info:
        state.convert('{"type":"stdout","data":"***"}')
    assert info.value.reason == "bad_response"
    event = state.convert(b'{"type":"exit","exit_code":0,"duration_ms":5}')
    assert event is not None and event.type == "exit" and event.killed is False and state.exit is event
    assert _api.stdin_message("é") == '{"type": "stdin", "data": "w6k="}'
    assert _api.resize_message(80, 24) == '{"type": "resize", "cols": 80, "rows": 24}'


def test_tls_settings(monkeypatch):
    config = resolve_config("https://runr.example.com", "k")
    custom = ssl.create_default_context()
    assert tls_context(custom) is custom
    import certifi

    monkeypatch.setenv("SSL_CERT_FILE", certifi.where())
    assert isinstance(tls_context(), ssl.SSLContext)
    monkeypatch.delenv("SSL_CERT_FILE")
    monkeypatch.delenv("SSL_CERT_DIR", raising=False)
    assert isinstance(tls_context(), ssl.SSLContext)

    secure = _connect_options(config, "wss://runr.example.com/ws/x", custom, threaded=True)
    assert secure["ssl"] is custom and secure["user_agent_header"] == config.user_agent
    assert secure["compression"] is None and secure["open_timeout"] <= 30
    plain = _connect_options(config, "ws://runr.example.com/ws/x", None, threaded=False)
    assert "ssl" not in plain and plain["ping_interval"] is None
