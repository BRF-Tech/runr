"""The blocking client against the fake API."""

import base64
import io
import os
import tarfile

import pytest

from runr_sandbox import (
    AuthError,
    ConflictError,
    NotFoundError,
    PayloadTooLargeError,
    QuotaError,
    RunrSandboxError,
    Sandbox,
    SandboxStartTimeout,
    ValidationError,
)
from fake_server import PNG


# ── create / connect / list ─────────────────────────────────────────────────


def test_create_sends_the_options_and_returns_a_running_sandbox(server, opts):
    sandbox = Sandbox.create(
        runtime="python", version="3.14", cpu="500m", memory=512, disk="1Gi", ttl_seconds=600,
        idle_timeout=120, network=["pypi.org:443", "10.0.0.0/8"], env={"KEY": "v"},
        files={"/workspace/main.py": "print(1)\n", "/workspace/data.bin": b"\x00\x01"},
        region="eu-1", sandbox_class="gvisor", read_only_root=True, pids_limit=128,
        metadata={"agent": "planner"}, **opts,
    )
    try:
        assert sandbox.status == "running"
        assert sandbox.id.startswith("sbx")
        create = server.state.calls("POST", r"^/api/sandboxes$")[0]
        body = create["json"]
        assert body["network"] == {"egress_allow": ["pypi.org:443", "10.0.0.0/8"]}
        assert body["files"] == [
            {"path": "/workspace/main.py", "content_base64": base64.b64encode(b"print(1)\n").decode()},
            {"path": "/workspace/data.bin", "content_base64": base64.b64encode(b"\x00\x01").decode()},
        ]
        assert body["cpu"] == "500m" and body["memory"] == 512 and body["disk"] == "1Gi"
        assert body["wait"] is True and body["sandbox_class"] == "gvisor"
        assert body["env"] == {"KEY": "v"} and body["metadata"] == {"agent": "planner"}
        assert "image" not in body  # unset options are not sent
        assert create["headers"]["authorization"] == "Bearer test-token"
        assert create["headers"]["accept"] == "application/json"
        assert create["headers"]["user-agent"].startswith("runr-sandbox-python/")
        assert server.state.fs[sandbox.id]["/workspace/main.py"] == b"print(1)\n"
    finally:
        sandbox.close()


def test_network_none_is_sent_as_a_string(server, opts):
    with Sandbox.create(network="none", **opts):
        pass
    assert server.state.calls("POST", r"^/api/sandboxes$")[0]["json"]["network"] == "none"


def test_a_202_answer_is_polled_until_running(server, opts):
    with Sandbox.create(metadata={"slow": "2"}, **opts) as sandbox:
        assert sandbox.status == "running"
    polls = server.state.calls("GET", r"^/api/sandboxes/sbx\d+$")
    assert len(polls) == 2


def test_wait_false_returns_the_pending_sandbox(server, opts):
    sandbox = Sandbox.create(metadata={"never": "1"}, wait=False, **opts)
    assert sandbox.status == "pending"
    sandbox.close()


def test_a_sandbox_that_does_not_start_in_time_is_removed(server, opts):
    with pytest.raises(SandboxStartTimeout) as caught:
        Sandbox.create(metadata={"never": "1"}, start_timeout=0.6, **opts)
    assert isinstance(caught.value, TimeoutError)
    assert caught.value.reason == "start_timeout"
    assert server.state.calls("DELETE", r"^/api/sandboxes/sbx\d+$"), "the sandbox must be deleted"


def test_a_sandbox_that_fails_to_start_raises(server, opts):
    with pytest.raises(RunrSandboxError) as caught:
        Sandbox.create(metadata={"fail": "1"}, **opts)
    assert caught.value.reason == "start_failed"
    assert "ImagePullBackOff" in caught.value.message


def test_quota_errors_are_quota_errors(server, opts):
    with pytest.raises(QuotaError) as caught:
        Sandbox.create(runtime="quota", **opts)
    assert caught.value.status == 422
    assert caught.value.reason == "quota_concurrent"
    assert isinstance(caught.value, ValidationError)


def test_other_422_answers_are_validation_errors(server, opts):
    with pytest.raises(ValidationError) as caught:
        Sandbox.create(runtime="unknown", **opts)
    assert not isinstance(caught.value, QuotaError)
    assert caught.value.body["errors"] == {"runtime": ["Unknown runtime."]}


def test_a_wrong_token_is_an_auth_error(server):
    with pytest.raises(AuthError) as caught:
        Sandbox.create(api_url=server.url, api_key="wrong")
    assert caught.value.status == 401


def test_connect_and_unknown_ids(server, opts):
    created = Sandbox.create(**opts)
    try:
        again = Sandbox.connect(created.id, **opts)
        assert again.id == created.id and again.status == "running"
        again.close()
        with pytest.raises(NotFoundError):
            Sandbox.connect("nope", **opts)
    finally:
        created.close()


def test_list_with_filters(server, opts):
    a = Sandbox.create(metadata={"agent": "a"}, **opts)
    b = Sandbox.create(metadata={"agent": "b"}, **opts)
    try:
        found = Sandbox.list(metadata={"agent": "b"}, **opts)
        assert [s.id for s in found] == [b.id]
        request = server.state.calls("GET", r"^/api/sandboxes$")[-1]
        assert request["query"] == {"metadata[agent]": "b"}
        assert {s.id for s in Sandbox.list(**opts)} == {a.id, b.id}
    finally:
        a.close()
        b.close()


def test_the_tenant_header_is_sent(server, opts):
    with Sandbox.create(tenant_id="t-1", **opts):
        pass
    assert server.state.calls("POST", r"^/api/sandboxes$")[0]["headers"]["x-tenant-id"] == "t-1"


# ── context manager / close ─────────────────────────────────────────────────


def test_the_context_manager_deletes_the_sandbox(server, opts):
    with Sandbox.create(**opts) as sandbox:
        sid = sandbox.id
    assert server.state.sandboxes[sid]["status"] == "terminated"


def test_the_context_manager_deletes_the_sandbox_on_errors(server, opts):
    with pytest.raises(ZeroDivisionError):
        with Sandbox.create(**opts) as sandbox:
            sid = sandbox.id
            1 / 0
    assert server.state.sandboxes[sid]["status"] == "terminated"


def test_close_and_kill_are_idempotent(server, opts):
    sandbox = Sandbox.create(**opts)
    sandbox.kill()
    sandbox.kill()  # 409: already ended — not an error
    sandbox.close()
    sandbox.close()
    deletes = server.state.calls("DELETE", r"^/api/sandboxes/sbx\d+$")
    assert len(deletes) == 3


def test_close_ignores_a_sandbox_that_is_gone(server, opts):
    sandbox = Sandbox.create(**opts)
    del server.state.sandboxes[sandbox.id]
    sandbox.close()


# ── exec ────────────────────────────────────────────────────────────────────


def test_exec_returns_the_result(server, opts):
    with Sandbox.create(**opts) as sandbox:
        result = sandbox.exec("echo hello", timeout=30, env={"A": "1"}, cwd="/tmp")
        assert result.ok and result.exit_code == 0
        assert result.stdout == "hello\n" and result.stdout_bytes == b"hello\n"
        body = server.state.calls("POST", r"/exec$")[-1]["json"]
        assert body == {"cmd": "echo hello", "timeout": 30, "env": {"A": "1"}, "cwd": "/tmp"}

        failed = sandbox.exec(["exit", "3"])
        assert not failed.ok and failed.exit_code == 3 and failed.stderr == "exit 3\n"
        assert server.state.calls("POST", r"/exec$")[-1]["json"]["cmd"] == ["exit", "3"]

        timed_out = sandbox.exec("sleep")
        assert timed_out.timed_out and not timed_out.ok and timed_out.exit_code == 137


def test_exec_binary_output_and_stdin(server, opts):
    with Sandbox.create(**opts) as sandbox:
        binary = sandbox.exec("binary")
        assert binary.stdout_bytes == b"\xff\xfe\x00ok"
        assert binary.stdout == b"\xff\xfe\x00ok".decode("utf-8", "replace")

        assert sandbox.exec("cat", stdin="text in").stdout == "text in"
        assert server.state.calls("POST", r"/exec$")[-1]["json"]["stdin"] == "text in"
        assert sandbox.exec("cat", stdin=b"\x00\x01").stdout_bytes == b"\x00\x01"
        assert server.state.calls("POST", r"/exec$")[-1]["json"]["stdin_base64"] == "AAE="


def test_exec_stream_yields_output_and_exit(server, opts):
    with Sandbox.create(**opts) as sandbox:
        events = list(sandbox.exec_stream("multi"))
        assert [e.type for e in events] == ["stdout", "stderr", "stdout", "stdout", "exit"]
        assert events[0].text == "line 1\n"
        assert events[1].data == b"warning\n"
        assert events[2].data == b"\xff\xfe"  # data_base64 chunk
        assert events[3].text == "héllo ✓\n"
        assert events[4].exit_code == 3 and events[4].timed_out is False and events[4].duration_ms == 21
        assert events[4].exec_id == "exec-1"
        request = server.state.calls("POST", r"/exec$")[-1]
        assert request["json"]["stream"] is True
        assert request["headers"]["accept"] == "text/event-stream"


def test_an_error_event_raises(server, opts):
    with Sandbox.create(**opts) as sandbox:
        with pytest.raises(RunrSandboxError) as caught:
            list(sandbox.exec_stream("error"))
        assert caught.value.reason == "not_running"


def test_exec_on_a_paused_sandbox_is_a_conflict(server, opts):
    with Sandbox.create(**opts) as sandbox:
        sandbox.pause()
        assert sandbox.status == "paused"
        with pytest.raises(ConflictError):
            sandbox.exec("echo x")
        sandbox.resume()
        assert sandbox.exec("echo x").stdout == "x\n"


# ── run_code ────────────────────────────────────────────────────────────────


def test_run_code_returns_the_structured_result(server, opts, tmp_path):
    with Sandbox.create(**opts) as sandbox:
        result = sandbox.run_code("print(6 * 7)", language="python", timeout=20)
        assert result.ok and result.stdout == "42\n" and result.language == "python"
        assert [f.path for f in result.files_changed] == ["/output/plot.png", "/workspace/data.csv"]
        assert len(result.outputs) == 1
        image = result.outputs[0]
        assert image.mime == "image/png" and image.data == PNG and image.name == "plot.png"
        saved = image.save(str(tmp_path))
        assert saved == os.path.join(str(tmp_path), "plot.png")
        with open(saved, "rb") as handle:
            assert handle.read() == PNG
        body = server.state.calls("POST", r"/run$")[-1]["json"]
        assert body == {"code": "print(6 * 7)", "language": "python", "timeout": 20}


def test_run_code_failure(server, opts):
    with Sandbox.create(**opts) as sandbox:
        result = sandbox.run_code("raise ValueError()")
        assert not result.ok and "ValueError" in result.stderr


def test_run_code_stream_ends_with_a_result(server, opts):
    with Sandbox.create(**opts) as sandbox:
        events = list(sandbox.run_code_stream("print(6 * 7)"))
        assert [e.type for e in events] == ["stdout", "exit", "result"]
        assert events[-1].result.outputs[0].data == PNG


# ── files ───────────────────────────────────────────────────────────────────


def test_write_read_list_remove(server, opts):
    with Sandbox.create(**opts) as sandbox:
        assert sandbox.files.write("/workspace/a.txt", "hello") == {"path": "/workspace/a.txt", "size": 5}
        request = server.state.calls("PUT", r"/files/content$")[-1]
        assert request["headers"]["content-type"] == "application/octet-stream" and request["raw"] == b"hello"

        sandbox.files.write("/workspace/run.sh", "#!/bin/sh\n", mode=0o755)
        assert server.state.calls("PUT", r"/files/content$")[-1]["json"]["mode"] == "0755"

        assert sandbox.files.read("/workspace/a.txt") == b"hello"
        assert sandbox.files.read_text("/workspace/a.txt") == "hello"
        names = [e.name for e in sandbox.files.list("/workspace")]
        assert names == ["a.txt", "run.sh"]
        assert sandbox.files.exists("/workspace/a.txt")

        sandbox.files.remove("/workspace/a.txt")
        assert not sandbox.files.exists("/workspace/a.txt")
        with pytest.raises(NotFoundError):
            sandbox.files.read("/workspace/a.txt")


def test_directories(server, opts):
    with Sandbox.create(**opts) as sandbox:
        sandbox.files.mkdir("/workspace/src")
        sandbox.files.write("/workspace/src/x.py", "x")
        entries = sandbox.files.list("/workspace")
        assert [(e.name, e.type, e.is_dir) for e in entries] == [("src", "dir", True)]
        with pytest.raises(ValidationError):
            sandbox.files.remove("/workspace/src")
        sandbox.files.remove("/workspace/src", recursive=True)
        assert server.state.calls("DELETE", r"/files$")[-1]["query"] == {"path": "/workspace/src", "recursive": "1"}


def test_a_large_upload_is_payload_too_large(server, opts):
    with Sandbox.create(**opts) as sandbox:
        with pytest.raises(PayloadTooLargeError) as caught:
            sandbox.files.write("/workspace/big", b"x" * (1024 * 1024 + 1))
        assert caught.value.reason == "file_too_large"


def test_directory_round_trip_through_tar(server, opts, tmp_path):
    source = tmp_path / "src"
    (source / "pkg").mkdir(parents=True)
    (source / "main.py").write_text("print('hi')\n")
    (source / "pkg" / "util.py").write_bytes(b"\x00binary\xff")
    with Sandbox.create(**opts) as sandbox:
        sandbox.files.upload_dir(str(source), "/workspace/project")
        request = server.state.calls("PUT", r"/files/archive$")[-1]
        assert request["headers"]["content-type"] == "application/x-tar"
        assert request["query"] == {"path": "/workspace/project"}
        fs = server.state.fs[sandbox.id]
        assert fs["/workspace/project/main.py"] == b"print('hi')\n"
        assert fs["/workspace/project/pkg/util.py"] == b"\x00binary\xff"

        target = tmp_path / "copy"
        written = sandbox.files.download_dir("/workspace/project", str(target))
        assert sorted(os.path.relpath(p, str(target)).replace(os.sep, "/") for p in written) == ["main.py", "pkg/util.py"]
        assert (target / "pkg" / "util.py").read_bytes() == b"\x00binary\xff"


def test_upload_files_from_memory(server, opts):
    with Sandbox.create(**opts) as sandbox:
        sandbox.files.upload_files({"a.txt": "A", "deep/b.bin": b"\x01"}, "/workspace")
        fs = server.state.fs[sandbox.id]
        assert fs["/workspace/a.txt"] == b"A" and fs["/workspace/deep/b.bin"] == b"\x01"


def test_download_dir_refuses_entries_outside_the_target(server, opts, tmp_path, monkeypatch):
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w") as archive:
        info = tarfile.TarInfo("../escape.txt")
        info.size = 1
        archive.addfile(info, io.BytesIO(b"x"))
    with Sandbox.create(**opts) as sandbox:
        monkeypatch.setattr(sandbox.files, "download_archive", lambda remote_dir="/workspace": buffer.getvalue())
        with pytest.raises(RunrSandboxError):
            sandbox.files.download_dir("/workspace", str(tmp_path / "out"))
    assert not (tmp_path / "escape.txt").exists()


# ── processes ───────────────────────────────────────────────────────────────


def test_background_processes(server, opts):
    with Sandbox.create(**opts) as sandbox:
        process = sandbox.processes.start("python3 -m http.server", env={"PORT": "8000"}, cwd="/workspace")
        assert process.running and process.pid == 100 and process.command == "python3 -m http.server"
        assert server.state.calls("POST", r"/processes$")[-1]["json"] == {
            "cmd": "python3 -m http.server", "env": {"PORT": "8000"}, "cwd": "/workspace",
        }
        assert [p.id for p in sandbox.processes.list()] == [process.id]
        assert sandbox.processes.get(process.id).status == "running"
        logs = sandbox.processes.logs(process.id, tail_bytes=1024)
        assert logs.stdout == "serving on :8000\n" and not logs.truncated
        followed = list(sandbox.processes.follow(process.id))
        assert [e.type for e in followed] == ["stdout", "exit"]
        killed = sandbox.processes.kill(process.id, signal="kill")
        assert killed.status == "killed" and not killed.running
        assert server.state.calls("DELETE", r"/processes/")[-1]["query"] == {"signal": "KILL"}
        with pytest.raises(NotFoundError):
            sandbox.processes.get("nope")


# ── lifecycle calls ─────────────────────────────────────────────────────────


def test_lifecycle_calls(server, opts):
    with Sandbox.create(**opts) as sandbox:
        assert sandbox.set_timeout(1200).info["ttl_seconds"] == 1200
        sandbox.keepalive()
        sandbox.set_network(["example.com:443"])
        assert sandbox.info["network"] == {"mode": "allowlist", "egress_allow": ["example.com:443"]}
        assert server.state.calls("PUT", r"/network$")[-1]["json"] == {"network": {"egress_allow": ["example.com:443"]}}
        sandbox.set_network("none")
        assert sandbox.info["network"]["mode"] == "none"
        logs = sandbox.logs(pod=True)
        assert logs["events"][0]["type"] == "created" and logs["pod"]["phase"] == "Running"
        assert sandbox.terminal(cols=120, rows=40)["session_id"] == "sess-1"
        assert sandbox.refresh().status == "running"
        assert "Sandbox(id=" in repr(sandbox)
