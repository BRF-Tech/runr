"""The asyncio client against the fake API (driven with asyncio.run, no plugin needed)."""

import asyncio
import os

import pytest

from runr_sandbox import AsyncSandbox, NotFoundError, QuotaError, RunrSandboxError, SandboxStartTimeout
from fake_server import PNG


def run(coro):
    return asyncio.run(coro)


def test_create_with_await_and_close(server, opts):
    async def scenario():
        sandbox = await AsyncSandbox.create(runtime="python", network=["pypi.org"], **opts)
        assert sandbox.status == "running"
        result = await sandbox.exec("echo async")
        assert result.stdout == "async\n" and result.ok
        await sandbox.close()
        await sandbox.close()
        return sandbox.id

    sid = run(scenario())
    assert server.state.sandboxes[sid]["status"] == "terminated"
    assert server.state.calls("POST", r"^/api/sandboxes$")[0]["json"]["network"] == {"egress_allow": ["pypi.org"]}


def test_async_with_on_create_removes_the_sandbox(server, opts):
    async def scenario():
        async with AsyncSandbox.create(**opts) as sandbox:
            assert sandbox.status == "running"
            return sandbox.id

    sid = run(scenario())
    assert server.state.sandboxes[sid]["status"] == "terminated"


def test_async_with_on_the_sandbox_removes_it_on_errors(server, opts):
    holder = {}

    async def scenario():
        sandbox = await AsyncSandbox.create(**opts)
        holder["id"] = sandbox.id
        async with sandbox:
            raise KeyError("boom")

    with pytest.raises(KeyError):
        run(scenario())
    assert server.state.sandboxes[holder["id"]]["status"] == "terminated"


def test_polling_and_start_timeout(server, opts):
    async def scenario():
        async with AsyncSandbox.create(metadata={"slow": "3"}, **opts) as sandbox:
            assert sandbox.status == "running"
        with pytest.raises(SandboxStartTimeout):
            await AsyncSandbox.create(metadata={"never": "1"}, start_timeout=0.5, **opts)

    run(scenario())
    assert len(server.state.calls("GET", r"^/api/sandboxes/sbx0001$")) == 3
    assert server.state.calls("DELETE", r"^/api/sandboxes/sbx0002$")


def test_errors(server, opts):
    async def scenario():
        with pytest.raises(QuotaError):
            await AsyncSandbox.create(runtime="quota", **opts)
        with pytest.raises(NotFoundError):
            await AsyncSandbox.connect("nope", **opts)

    run(scenario())


def test_exec_stream(server, opts):
    async def scenario():
        async with AsyncSandbox.create(**opts) as sandbox:
            events = [event async for event in sandbox.exec_stream(["multi"])]
            assert [e.type for e in events] == ["stdout", "stderr", "stdout", "stdout", "exit"]
            assert events[2].data == b"\xff\xfe"
            assert events[-1].exit_code == 3
            with pytest.raises(RunrSandboxError):
                async for _ in sandbox.exec_stream("error"):
                    pass

    run(scenario())


def test_run_code_and_files(server, opts, tmp_path):
    source = tmp_path / "in"
    (source / "sub").mkdir(parents=True)
    (source / "sub" / "f.txt").write_text("content")

    async def scenario():
        async with AsyncSandbox.create(**opts) as sandbox:
            result = await sandbox.run_code("print(6 * 7)")
            assert result.outputs[0].data == PNG
            events = [e async for e in sandbox.run_code_stream("print(6 * 7)")]
            assert events[-1].type == "result"

            await sandbox.files.write("/workspace/x.txt", "x")
            assert await sandbox.files.read_text("/workspace/x.txt") == "x"
            assert [e.name for e in await sandbox.files.list("/workspace")] == ["x.txt"]
            await sandbox.files.upload_dir(str(source), "/workspace/in")
            written = await sandbox.files.download_dir("/workspace/in", str(tmp_path / "out"))
            assert [os.path.basename(p) for p in written] == ["f.txt"]
            await sandbox.files.remove("/workspace/x.txt")
            assert not await sandbox.files.exists("/workspace/x.txt")

    run(scenario())
    assert (tmp_path / "out" / "sub" / "f.txt").read_text() == "content"


def test_processes_and_lifecycle(server, opts):
    async def scenario():
        async with AsyncSandbox.create(**opts) as sandbox:
            process = await sandbox.processes.start(["sleep", "100"])
            assert (await sandbox.processes.get(process.id)).running
            assert len(await sandbox.processes.list()) == 1
            assert (await sandbox.processes.logs(process.id)).stdout
            assert [e.type async for e in sandbox.processes.follow(process.id)] == ["stdout", "exit"]
            assert (await sandbox.processes.kill(process.id)).status == "killed"
            await sandbox.set_timeout(60)
            await sandbox.keepalive()
            await sandbox.pause()
            assert sandbox.status == "paused"
            await sandbox.resume()
            await sandbox.set_network("none")
            assert (await sandbox.logs())["events"]
            assert (await sandbox.refresh()).status == "running"

    run(scenario())


def test_list(server, opts):
    async def scenario():
        async with AsyncSandbox.create(metadata={"k": "v"}, **opts) as sandbox:
            found = await AsyncSandbox.list(metadata={"k": "v"}, **opts)
            assert [s.id for s in found] == [sandbox.id]

    run(scenario())
