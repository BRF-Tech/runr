import hashlib
import json

import pytest
from fastapi.testclient import TestClient

from runr_hf_serve.receiver import PathError, create_receiver_app, safe_path
from runr_hf_serve.snapshot import MARKER


@pytest.fixture
def receiver(tmp_path):
    root = tmp_path / "snapshot"
    with TestClient(create_receiver_app(str(root))) as client:
        yield client, root


def put(client, path, data, offset=0):
    return client.put(f"/files/{path}", params={"offset": offset}, content=data,
                      headers={"content-type": "application/octet-stream"})


def test_healthz(receiver):
    client, _ = receiver
    assert client.get("/healthz").json() == {"status": "ok"}


def test_chunks_land_at_their_offsets_in_any_order(receiver):
    client, root = receiver
    assert put(client, "weights/model.safetensors", b"WORLD", offset=5).status_code == 200
    r = put(client, "weights/model.safetensors", b"HELLO", offset=0)
    assert r.json() == {"path": "weights/model.safetensors", "size": 10}
    # a repeated chunk (retry) overwrites the same bytes
    put(client, "weights/model.safetensors", b"HELLO", offset=0)
    assert (root / "weights" / "model.safetensors").read_bytes() == b"HELLOWORLD"


@pytest.mark.parametrize("path", ["../escape.bin", "a/../../b", MARKER])
def test_path_traversal_and_the_marker_are_refused(receiver, path):
    client, root = receiver
    r = put(client, path, b"x")
    assert r.status_code in (400, 404)  # 404: the HTTP client may normalise the URL first
    assert not (root.parent / "escape.bin").exists()
    assert not (root / MARKER).exists()


@pytest.mark.parametrize("path", ["", "/etc/passwd", "C:/x", "a\\b", "a//b", "..", "a/..", "a/./b"])
def test_safe_path_refuses_paths_outside_the_root(tmp_path, path):
    with pytest.raises(PathError):
        safe_path(str(tmp_path), path)


def test_safe_path_accepts_nested_relative_paths(tmp_path):
    assert safe_path(str(tmp_path), "tokenizer/vocab.txt").endswith("vocab.txt")


def test_complete_verifies_sizes_and_hashes_then_writes_the_marker(receiver):
    client, root = receiver
    put(client, "config.json", b"{}")
    put(client, "model.bin", b"abcdef")

    r = client.post("/complete", json={"files": [
        {"path": "config.json", "size": 2},
        {"path": "model.bin", "size": 6, "sha256": hashlib.sha256(b"abcdef").hexdigest()},
    ]})
    assert r.status_code == 200
    assert r.json() == {"ok": True, "size_bytes": 8, "files": [{"path": "config.json", "size": 2}, {"path": "model.bin", "size": 6}]}

    marker = json.loads((root / MARKER).read_text())
    assert marker["source"] == "upload"
    assert marker["repo"] is None and marker["revision"] is None
    assert marker["size_bytes"] == 8


def test_complete_refuses_a_size_mismatch(receiver):
    client, root = receiver
    put(client, "model.bin", b"abc")
    r = client.post("/complete", json={"files": [{"path": "model.bin", "size": 4}]})
    assert r.status_code == 422
    assert r.json()["ok"] is False
    assert "3 bytes uploaded, 4 expected" in r.json()["error"]
    assert not (root / MARKER).exists()


def test_complete_refuses_a_sha256_mismatch(receiver):
    client, root = receiver
    put(client, "model.bin", b"abc")
    r = client.post("/complete", json={"files": [{"path": "model.bin", "size": 3, "sha256": "0" * 64}]})
    assert r.status_code == 422
    assert "sha256" in r.json()["error"]
    assert not (root / MARKER).exists()


def test_complete_refuses_absent_files_and_bad_bodies(receiver):
    client, _ = receiver
    assert client.post("/complete", json={"files": [{"path": "nope.bin", "size": 1}]}).status_code == 422
    assert client.post("/complete", json={"files": []}).status_code == 422
    assert client.post("/complete", json={"files": [{"path": "../x", "size": 1}]}).status_code == 422
    assert client.post("/complete", content=b"no json").status_code == 422


def test_negative_offsets_are_refused(receiver):
    client, _ = receiver
    assert put(client, "a.bin", b"x", offset=-1).status_code == 400
