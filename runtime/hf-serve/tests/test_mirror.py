import json
import os

import pytest

from runr_hf_serve import __main__ as cli
from runr_hf_serve import mirror
from runr_hf_serve.snapshot import MARKER

SHA = "0123456789abcdef0123456789abcdef01234567"


@pytest.fixture
def fake_hub(monkeypatch):
    calls = {"resolve": [], "download": []}

    def resolve(repo, revision, token):
        calls["resolve"].append((repo, revision, token))
        return SHA

    def download(repo, revision, dest, allow, ignore, token):
        calls["download"].append((repo, revision, dest, allow, ignore, token))
        os.makedirs(os.path.join(dest, "onnx"), exist_ok=True)
        os.makedirs(os.path.join(dest, ".cache", "huggingface"), exist_ok=True)
        with open(os.path.join(dest, "config.json"), "w") as f:
            f.write('{"model_type": "bert"}')
        with open(os.path.join(dest, "model.safetensors"), "wb") as f:
            f.write(b"\0" * 1000)
        with open(os.path.join(dest, "onnx", "model.onnx"), "wb") as f:
            f.write(b"\1" * 10)
        with open(os.path.join(dest, ".cache", "huggingface", "download.lock"), "w") as f:
            f.write("x")
        return dest

    monkeypatch.setattr(mirror, "resolve_revision", resolve)
    monkeypatch.setattr(mirror, "download", download)
    return calls


def last_line(capsys) -> str:
    return capsys.readouterr().out.strip().splitlines()[-1]


def test_mirror_downloads_writes_the_marker_and_reports_it(fake_hub, tmp_path, capsys, monkeypatch):
    monkeypatch.setenv("HF_TOKEN", "hf_secret")
    dest = tmp_path / "snap"

    code = cli.main(["mirror", "--repo", "org/tiny", "--revision", "main", "--dest", str(dest),
                     "--allow-pattern", "*.json", "--allow-pattern", "*.safetensors", "--ignore-pattern", "*.bin"])

    assert code == 0
    line = last_line(capsys)
    assert line.startswith("RUNR_SNAPSHOT ")
    reported = json.loads(line[len("RUNR_SNAPSHOT "):])

    marker = json.loads((dest / MARKER).read_text())
    assert marker == reported
    assert marker["repo"] == "org/tiny"
    assert marker["revision"] == SHA
    assert marker["source"] == "hub"
    assert [f["path"] for f in marker["files"]] == ["config.json", "model.safetensors", "onnx/model.onnx"]
    assert marker["size_bytes"] == 22 + 1000 + 10
    assert marker["completed_at"].endswith("Z")

    # the resolved sha is what gets downloaded; patterns and the token pass through
    assert fake_hub["resolve"] == [("org/tiny", "main", "hf_secret")]
    repo, revision, target, allow, ignore, token = fake_hub["download"][0]
    assert (repo, revision, target, token) == ("org/tiny", SHA, str(dest), "hf_secret")
    assert allow == ["*.json", "*.safetensors"]
    assert ignore == ["*.bin"]


def test_mirror_reports_errors_and_exits_1(monkeypatch, tmp_path, capsys):
    class GatedRepoError(Exception):
        pass

    def resolve(repo, revision, token):
        raise GatedRepoError("Access to model org/llama is restricted")

    monkeypatch.setattr(mirror, "resolve_revision", resolve)
    monkeypatch.delenv("HF_TOKEN", raising=False)

    code = cli.main(["mirror", "--repo", "org/llama", "--dest", str(tmp_path / "x")])

    assert code == 1
    line = last_line(capsys)
    assert line.startswith("RUNR_ERROR ")
    assert "restricted" in line and "gated" in line
    assert not (tmp_path / "x" / MARKER).exists()


def test_a_download_without_files_fails(monkeypatch, tmp_path, capsys):
    monkeypatch.setattr(mirror, "resolve_revision", lambda repo, revision, token: SHA)
    monkeypatch.setattr(mirror, "download", lambda *a: a[2])

    assert cli.main(["mirror", "--repo", "org/x", "--dest", str(tmp_path / "empty")]) == 1
    assert "no files" in last_line(capsys)


def test_a_full_sha_is_not_resolved_again():
    assert mirror.resolve_revision("org/x", SHA, None) == SHA


def test_explain_hints():
    class RepositoryNotFoundError(Exception):
        pass

    assert "check the repository id" in mirror.explain(RepositoryNotFoundError("404 Client Error"))
    assert mirror.explain(ValueError("boom")) == "ValueError: boom"
