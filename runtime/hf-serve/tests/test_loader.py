import sys
import types

import pytest

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
