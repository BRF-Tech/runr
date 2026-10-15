import pytest

from runr_hf_serve.config import ConfigError, Settings


def test_online_settings_from_the_environment():
    s = Settings.from_env({
        "RUNR_MODEL_ID": "sentence-transformers/all-MiniLM-L6-v2",
        "RUNR_MODEL_REVISION": "c9745ed1d9f207416be6d2e6f8de32d1f16199bf",
        "RUNR_TASK": "feature-extraction",
        "HF_TOKEN": "hf_secret",
        "PORT": "9000",
    })
    assert s.source == "sentence-transformers/all-MiniLM-L6-v2"
    assert not s.offline
    assert s.served_name == "all-MiniLM-L6-v2"
    assert s.engine == "transformers"
    assert s.device == "auto"
    assert s.token == "hf_secret"
    assert s.port == 9000


def test_the_local_path_wins_over_the_id(tmp_path):
    s = Settings.from_env({
        "RUNR_MODEL_ID": "org/name",
        "RUNR_MODEL_PATH": str(tmp_path),
        "RUNR_TASK": "text-classification",
    })
    assert s.offline
    assert s.source == str(tmp_path)
    assert s.served_name == tmp_path.name
    assert s.validate() is s


def test_served_name_and_pipeline_kwargs():
    s = Settings.from_env({
        "RUNR_MODEL_ID": "org/name",
        "RUNR_TASK": "text-generation",
        "RUNR_SERVED_NAME": "chat",
        "RUNR_PIPELINE_KWARGS": '{"trust_remote_code": false, "torch_dtype": "auto"}',
        "RUNR_DEVICE": "CUDA",
    })
    assert s.served_name == "chat"
    assert s.pipeline_kwargs == {"trust_remote_code": False, "torch_dtype": "auto"}
    assert s.device == "cuda"


def test_diffusers_defaults_to_text_to_image():
    s = Settings.from_env({"RUNR_MODEL_ID": "org/sd", "RUNR_ENGINE": "diffusers"})
    assert s.task == "text-to-image"


@pytest.mark.parametrize("env, message", [
    ({"RUNR_TASK": "fill-mask"}, "RUNR_MODEL_PATH"),
    ({"RUNR_MODEL_ID": "org/name"}, "RUNR_TASK"),
    ({"RUNR_MODEL_ID": "org/name", "RUNR_TASK": "x", "RUNR_ENGINE": "onnx"}, "RUNR_ENGINE"),
    ({"RUNR_MODEL_ID": "org/name", "RUNR_TASK": "x", "RUNR_DEVICE": "tpu"}, "RUNR_DEVICE"),
    ({"RUNR_MODEL_PATH": "/nonexistent/runr-model", "RUNR_TASK": "x"}, "not a directory"),
])
def test_invalid_settings_are_explained(env, message):
    with pytest.raises(ConfigError, match=message):
        Settings.from_env(env).validate()


@pytest.mark.parametrize("raw", ["{not json", "[1, 2]"])
def test_pipeline_kwargs_must_be_a_json_object(raw):
    with pytest.raises(ConfigError, match="RUNR_PIPELINE_KWARGS"):
        Settings.from_env({"RUNR_MODEL_ID": "org/name", "RUNR_TASK": "x", "RUNR_PIPELINE_KWARGS": raw})
