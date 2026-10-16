import os
import sys
import types

import pytest

from runr_hf_serve import hub_cache as cache
from runr_hf_serve.config import Settings
from runr_hf_serve.loader import load_model, resolve_device


@pytest.fixture
def fake_transformers(monkeypatch):
    calls = []

    def pipeline(**kwargs):
        calls.append(kwargs)
        return types.SimpleNamespace(tokenizer="tok")

    module = types.ModuleType("transformers")
    module.pipeline = pipeline
    monkeypatch.setitem(sys.modules, "transformers", module)
    return calls


def test_offline_loads_the_local_directory_without_hub_arguments(fake_transformers, tmp_path):
    settings = Settings(model_id="org/name", revision="abc", model_path=str(tmp_path), task="text-classification",
                        device="cpu", token="hf_x")
    model = load_model(settings)

    call = fake_transformers[0]
    assert call["model"] == str(tmp_path)
    assert call["task"] == "text-classification"
    assert call["device"] == -1
    assert "revision" not in call and "token" not in call
    assert model.tokenizer == "tok"
    assert model.device == "cpu"


def test_online_passes_revision_token_and_pipeline_kwargs(fake_transformers):
    settings = Settings(model_id="org/name", revision="abc123", task="sentence-similarity", device="cuda",
                        token="hf_x", pipeline_kwargs={"trust_remote_code": False})
    model = load_model(settings)

    call = fake_transformers[0]
    assert call["model"] == "org/name"
    assert call["task"] == "feature-extraction"  # sentence-similarity runs the feature-extraction pipeline
    assert call["revision"] == "abc123"
    assert call["token"] == "hf_x"
    assert call["device"] == 0
    assert call["trust_remote_code"] is False
    assert model.task == "sentence-similarity"


def test_resolve_device():
    assert resolve_device("cpu", lambda: True) == "cpu"
    assert resolve_device("cuda", lambda: False) == "cuda"
    assert resolve_device("auto", lambda: True) == "cuda"
    assert resolve_device("auto", lambda: False) == "cpu"

    def broken():
        raise RuntimeError("driver")

    assert resolve_device("auto", broken) == "cpu"


def test_invalid_settings_raise_before_any_import(monkeypatch):
    monkeypatch.setitem(sys.modules, "transformers", None)  # importing would fail
    with pytest.raises(ValueError, match="RUNR_TASK"):
        load_model(Settings(model_id="org/name"))


def test_tasks_transformers_5_dropped_use_runr_pipelines(fake_transformers, monkeypatch):
    loaded = []

    def load(task, source, device, kwargs):
        loaded.append((task, source, device, kwargs))
        return types.SimpleNamespace(tokenizer="t5-tok")

    monkeypatch.setattr("runr_hf_serve.pipelines.load", load)

    model = load_model(Settings(model_id="org/t5", revision="abc", task="summarization", device="cpu", token="hf_x"))

    assert loaded == [("summarization", "org/t5", "cpu", {"revision": "abc", "token": "hf_x"})]
    assert fake_transformers == []  # transformers.pipeline() is not called
    assert model.tokenizer == "t5-tok"
    assert model.task == "summarization"


def test_torch_threads_follow_the_configured_count(fake_transformers, monkeypatch):
    applied = []
    monkeypatch.setenv("OMP_NUM_THREADS", "2")
    monkeypatch.setattr("runr_hf_serve.threads.apply_torch", applied.append)

    load_model(Settings(model_id="org/name", task="fill-mask", device="cpu"))

    assert applied == [2]


# ── Online with a pinned revision: the model cache's snapshot without a Hub request ─────────────

SHA = "714eb0fa89d2f80546fda750413ed43d93601a13"


@pytest.fixture
def hub_cache(tmp_path, monkeypatch):
    """HF_HUB_CACHE in tmp_path; the fixture's function creates the cached snapshot of org/name."""
    root = tmp_path / "hub"
    monkeypatch.setenv("HF_HUB_CACHE", str(root))

    def snapshot(revision=SHA):
        path = root / "models--org--name" / "snapshots" / revision
        path.mkdir(parents=True, exist_ok=True)
        (path / "config.json").write_text("{}")
        return str(path)

    return snapshot


def online(**overrides):
    values = dict(model_id="org/name", revision=SHA, task="text-classification", device="cpu", token="hf_x")
    values.update(overrides)
    return Settings(**values)


def test_a_completed_load_of_a_pinned_revision_comes_from_the_cache_next_time(fake_transformers, hub_cache):
    snapshot = hub_cache()

    load_model(online())  # from the Hub, which filled the cache
    load_model(online())  # the next start

    first, second = fake_transformers
    assert first["model"] == "org/name"
    assert first["revision"] == SHA and first["token"] == "hf_x"
    assert second["model"] == snapshot
    assert "revision" not in second and "token" not in second


def test_cached_files_without_a_completed_load_go_to_the_hub(fake_transformers, hub_cache):
    hub_cache()  # files in the cache, but no load of them completed (a start that was cut short)

    assert cache.complete_snapshot(online()) is None
    load_model(online())
    assert fake_transformers[0]["model"] == "org/name"


@pytest.mark.parametrize("revision", ["main", "v1.0", None, SHA[:12]])
def test_a_branch_tag_or_short_sha_is_resolved_by_the_hub_on_every_start(fake_transformers, hub_cache, revision):
    hub_cache(revision or "main")

    load_model(online(revision=revision))
    load_model(online(revision=revision))

    assert [call["model"] for call in fake_transformers] == ["org/name", "org/name"]


def test_another_task_or_other_pipeline_arguments_load_from_the_hub_first(fake_transformers, hub_cache):
    hub_cache()
    load_model(online())

    load_model(online(task="feature-extraction"))
    load_model(online(pipeline_kwargs={"trust_remote_code": True}))
    load_model(online())

    assert [call["model"] for call in fake_transformers][1:3] == ["org/name", "org/name"]
    assert fake_transformers[3]["model"].endswith(SHA)


def test_a_cached_snapshot_that_does_not_load_falls_back_to_the_hub(hub_cache, monkeypatch, caplog):
    snapshot = hub_cache()
    calls = []

    def pipeline(**kwargs):
        calls.append(kwargs["model"])
        if kwargs["model"] == snapshot:
            raise OSError("model.safetensors not found")  # e.g. a blob removed from the cache
        return types.SimpleNamespace(tokenizer=None)

    module = types.ModuleType("transformers")
    module.pipeline = pipeline
    monkeypatch.setitem(sys.modules, "transformers", module)

    load_model(online())
    model = load_model(online())

    assert calls == ["org/name", snapshot, "org/name"]
    assert model.task == "text-classification"
    assert "did not load (OSError: model.safetensors not found); loading from the Hub" in caplog.text


def test_offline_mode_records_nothing_in_the_cache(fake_transformers, hub_cache, tmp_path):
    hub_cache()
    model_dir = tmp_path / "snapshot"
    model_dir.mkdir()

    load_model(Settings(model_id="org/name", revision=SHA, model_path=str(model_dir), task="fill-mask", device="cpu"))

    assert not (tmp_path / "hub" / "models--org--name" / cache.MARKER_DIR).exists()


def test_a_read_only_cache_keeps_loading_from_the_hub(fake_transformers, hub_cache, monkeypatch, caplog):
    hub_cache()

    def refuse(*_args, **_kwargs):
        raise PermissionError("read-only file system")

    monkeypatch.setattr("runr_hf_serve.hub_cache.tempfile.mkstemp", refuse)
    load_model(online())
    load_model(online())

    assert [call["model"] for call in fake_transformers] == ["org/name", "org/name"]
    assert "could not record the cached snapshot" in caplog.text


def test_hub_cache_dir_follows_huggingface_hub(tmp_path):
    assert cache.hub_cache_dir({"HF_HUB_CACHE": "/models/hf/hub", "HF_HOME": "/x"}) == "/models/hf/hub"
    assert cache.hub_cache_dir({"HUGGINGFACE_HUB_CACHE": "/legacy", "HF_HOME": "/x"}) == "/legacy"
    assert cache.hub_cache_dir({"HF_HOME": "/models/hf"}) == os.path.join("/models/hf", "hub")
    assert cache.hub_cache_dir({"XDG_CACHE_HOME": str(tmp_path)}) == os.path.join(str(tmp_path), "huggingface", "hub")


# ── diffusers: dtype ─────────────────────────────────────────────────────────────────────────────


@pytest.fixture
def fake_diffusers(monkeypatch):
    calls = []

    class dtype:  # noqa: N801 (torch.dtype)
        def __init__(self, name):
            self.name = name

    torch = types.ModuleType("torch")
    torch.dtype = dtype
    torch.float16 = dtype("float16")
    torch.bfloat16 = dtype("bfloat16")
    torch.float32 = dtype("float32")
    torch.set_num_threads = lambda _count: None

    class Pipe:
        def to(self, device):
            calls[-1]["to"] = device
            return self

    class DiffusionPipeline:
        @staticmethod
        def from_pretrained(source, **kwargs):
            calls.append({"source": source, **kwargs})
            return Pipe()

    diffusers = types.ModuleType("diffusers")
    diffusers.DiffusionPipeline = DiffusionPipeline
    monkeypatch.setitem(sys.modules, "torch", torch)
    monkeypatch.setitem(sys.modules, "diffusers", diffusers)
    return calls, torch


def diffusion(**overrides):
    values = dict(model_id="org/name", revision=SHA, task="text-to-image", engine="diffusers", device="cuda")
    values.update(overrides)
    return Settings(**values)


def test_diffusers_gets_dtype_not_the_deprecated_torch_dtype(fake_diffusers):
    calls, torch = fake_diffusers

    load_model(diffusion())
    load_model(diffusion(device="cpu"))

    assert calls[0]["dtype"] is torch.float16 and calls[0]["to"] == "cuda"
    assert calls[1]["dtype"] is torch.float32 and calls[1]["to"] == "cpu"
    assert all("torch_dtype" not in call for call in calls)


@pytest.mark.parametrize("kwargs, expected", [
    ({"torch_dtype": "bfloat16"}, "bfloat16"),  # the earlier argument, a dtype name as JSON carries it
    ({"dtype": "float32"}, "float32"),
])
def test_diffusers_dtype_from_the_pipeline_arguments(fake_diffusers, kwargs, expected):
    calls, torch = fake_diffusers

    load_model(diffusion(pipeline_kwargs=kwargs))

    assert calls[0]["dtype"] is getattr(torch, expected)
    assert "torch_dtype" not in calls[0]


def test_diffusers_cached_snapshot_is_loaded_with_local_files_only(fake_diffusers, tmp_path, monkeypatch):
    calls, _torch = fake_diffusers
    monkeypatch.setenv("HF_HUB_CACHE", str(tmp_path))
    snapshot = tmp_path / "models--org--name" / "snapshots" / SHA
    snapshot.mkdir(parents=True)

    load_model(diffusion(token="hf_x"))
    load_model(diffusion(token="hf_x"))

    assert calls[0]["source"] == "org/name" and calls[0]["revision"] == SHA and calls[0]["token"] == "hf_x"
    assert calls[1]["source"] == str(snapshot) and calls[1]["local_files_only"] is True
    assert "revision" not in calls[1] and "token" not in calls[1]


def test_transformers_pipeline_arguments_use_dtype(fake_transformers):
    load_model(online(revision="main", pipeline_kwargs={"torch_dtype": "float16"}))

    assert fake_transformers[0]["dtype"] == "float16"
    assert "torch_dtype" not in fake_transformers[0]
