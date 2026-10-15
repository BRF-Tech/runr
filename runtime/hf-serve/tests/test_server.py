import base64
import math
import threading

from fastapi.testclient import TestClient

from conftest import FakeImage, FakeTokenizer, png_base64
from runr_hf_serve.config import Settings
from runr_hf_serve.loader import LoadedModel
from runr_hf_serve.server import create_app


# ── Health and loading states ───────────────────────────────────────────────


def test_healthz_answers_503_while_loading_then_200():
    release = threading.Event()
    model = LoadedModel(task="text-classification", engine="transformers", pipe=lambda x: [])

    def slow_loader(_settings):
        release.wait(5)
        return model

    app = create_app(Settings(model_id="org/m", task="text-classification"), loader=slow_loader, background=True)
    with TestClient(app) as client:
        loading = client.get("/healthz")
        assert loading.status_code == 503
        assert loading.json() == {"status": "loading"}
        assert client.post("/predict", json={"inputs": "x"}).status_code == 503

        release.set()
        for _ in range(100):
            if app.state.runr.status == "ready":
                break
            threading.Event().wait(0.02)
        assert client.get("/healthz").json() == {"status": "ok"}


def test_a_failed_load_is_reported_and_never_ready():
    def broken(_settings):
        raise OSError("model.safetensors not found")

    app = create_app(Settings(model_id="org/m", task="fill-mask"), loader=broken, background=False)
    with TestClient(app) as client:
        health = client.get("/healthz")
        assert health.status_code == 500
        assert health.json()["status"] == "error"
        assert "model.safetensors not found" in health.json()["error"]

        predict = client.post("/predict", json={"inputs": "x"})
        assert predict.status_code == 500
        assert "failed to load" in predict.json()["error"]


def test_info_and_models(client_for):
    client, _, _ = client_for("text-classification", [])
    info = client.get("/info").json()
    assert info["model"] == "org/tiny"
    assert info["revision"] == "abc"
    assert info["task"] == "text-classification"
    assert info["served_name"] == "tiny"
    assert info["status"] == "ready"

    models = client.get("/v1/models").json()
    assert models["data"][0]["id"] == "tiny"


# ── /predict per task ───────────────────────────────────────────────────────


def test_predict_text_classification(client_for):
    client, pipe, _ = client_for("text-classification", [{"label": "POSITIVE", "score": 0.98}])
    r = client.post("/predict", json={"inputs": "I love it", "parameters": {"top_k": 1}})
    assert r.status_code == 200
    assert r.json() == {"model": "tiny", "task": "text-classification", "outputs": [{"label": "POSITIVE", "score": 0.98}]}
    assert pipe.calls == [(("I love it",), {"top_k": 1})]


class FloatLike:
    """numpy.float32 stand-in: not a float, has item()."""

    def __init__(self, v):
        self.v = v

    def item(self):
        return self.v


def test_predict_token_classification_makes_numpy_like_values_json_safe(client_for):
    reply = [{"entity_group": "PER", "score": FloatLike(0.9), "word": "Ada", "start": 0, "end": 3}]
    client, _, _ = client_for("token-classification", reply)
    out = client.post("/predict", json={"inputs": "Ada wrote code"}).json()["outputs"]
    assert out == [{"entity_group": "PER", "score": 0.9, "word": "Ada", "start": 0, "end": 3}]


def test_predict_question_answering_passes_question_and_context(client_for):
    client, pipe, _ = client_for("question-answering", {"answer": "Paris", "score": 0.7, "start": 0, "end": 5})
    r = client.post("/predict", json={"inputs": {"question": "Capital?", "context": "Paris is the capital."}})
    assert r.json()["outputs"]["answer"] == "Paris"
    assert pipe.calls[0][1] == {"question": "Capital?", "context": "Paris is the capital."}

    bad = client.post("/predict", json={"inputs": "no dict"})
    assert bad.status_code == 400


def test_predict_zero_shot_needs_candidate_labels(client_for):
    client, pipe, _ = client_for("zero-shot-classification", {"labels": ["a", "b"], "scores": [0.6, 0.4]})
    assert client.post("/predict", json={"inputs": "text"}).status_code == 400
    ok = client.post("/predict", json={"inputs": "text", "parameters": {"candidate_labels": ["a", "b"]}})
    assert ok.status_code == 200
    assert pipe.calls[0][1] == {"candidate_labels": ["a", "b"]}


def test_predict_image_classification_decodes_base64_and_data_urls(client_for):
    client, pipe, _ = client_for("image-classification", [{"label": "cat", "score": 0.5}])
    assert client.post("/predict", json={"inputs": png_base64()}).status_code == 200
    assert client.post("/predict", json={"inputs": "data:image/png;base64," + png_base64()}).status_code == 200
    image = pipe.calls[0][0][0]
    assert image.size == (2, 2)


def test_predict_never_fetches_urls(client_for):
    client, pipe, _ = client_for("image-classification", [])
    r = client.post("/predict", json={"inputs": "https://example.com/cat.png"})
    assert r.status_code == 400
    assert "URLs are not fetched" in r.json()["error"]
    assert pipe.calls == []

    bad = client.post("/predict", json={"inputs": "%%%not-base64"})
    assert bad.status_code == 400


def test_predict_feature_extraction_returns_pooled_normalized_vectors(client_for):
    client, _, _ = client_for("feature-extraction", [[[3.0, 0.0], [3.0, 8.0]]])  # [1][tokens][hidden]
    one = client.post("/predict", json={"inputs": "hello"}).json()["outputs"]
    assert one == [0.6, 0.8]  # mean [3, 4] → normalized

    many = client.post("/predict", json={"inputs": ["a", "b"]}).json()["outputs"]
    assert many == [[0.6, 0.8], [0.6, 0.8]]

    raw = client.post("/predict", json={"inputs": ["a"], "parameters": {"pooling": "none"}}).json()["outputs"]
    assert raw == [[[[3.0, 0.0], [3.0, 8.0]]]]


def test_predict_sentence_similarity_returns_cosines(client_for):
    vectors = {"a": [[[1.0, 0.0]]], "b": [[[1.0, 0.0]]], "c": [[[0.0, 1.0]]]}
    client, _, _ = client_for("sentence-similarity", lambda text: vectors[text])
    r = client.post("/predict", json={"inputs": {"source_sentence": "a", "sentences": ["b", "c"]}})
    assert r.json()["outputs"] == [1.0, 0.0]
    assert client.post("/predict", json={"inputs": "a"}).status_code == 400


def test_predict_text_generation_does_not_echo_the_prompt(client_for):
    client, pipe, _ = client_for("text-generation", [{"generated_text": " world"}])
    out = client.post("/predict", json={"inputs": "hello", "parameters": {"max_new_tokens": 5}}).json()["outputs"]
    assert out == [{"generated_text": " world"}]
    assert pipe.calls[0][1] == {"max_new_tokens": 5, "return_full_text": False}


def test_predict_text_to_image_returns_base64_png(client_for):
    client, pipe, _ = client_for("text-to-image", type("R", (), {"images": [FakeImage()]})(), engine="diffusers")
    out = client.post("/predict", json={"inputs": "a red square", "parameters": {"num_inference_steps": 2}}).json()["outputs"]
    assert base64.b64decode(out[0]["image"]).startswith(b"\x89PNG")
    assert pipe.calls[0] == (("a red square",), {"num_inference_steps": 2})


def test_predict_requires_inputs_and_json(client_for):
    client, _, _ = client_for("text-classification", [])
    assert client.post("/predict", json={}).status_code == 400
    assert client.post("/predict", content=b"not json", headers={"content-type": "application/json"}).status_code == 400
    assert client.post("/predict", json={"inputs": "x", "parameters": [1]}).status_code == 400


def test_inference_errors_answer_500(client_for):
    def boom(*_a, **_k):
        raise RuntimeError("CUDA out of memory")

    client, _, _ = client_for("text-classification", boom)
    r = client.post("/predict", json={"inputs": "x"})
    assert r.status_code == 500
    assert "CUDA out of memory" in r.json()["error"]
    assert client.get("/healthz").status_code == 200  # the model stays loaded


# ── OpenAI-compatible routes ────────────────────────────────────────────────


def test_embeddings_are_openai_shaped_and_normalized(client_for):
    client, _, _ = client_for("feature-extraction", [[[1.0, 2.0], [3.0, 2.0]]], tokenizer=FakeTokenizer())
    r = client.post("/v1/embeddings", json={"input": ["hello world", "hi"], "model": "tiny"})
    assert r.status_code == 200
    body = r.json()
    assert body["object"] == "list"
    assert body["model"] == "tiny"
    assert [d["index"] for d in body["data"]] == [0, 1]
    vector = body["data"][0]["embedding"]
    assert math.isclose(sum(x * x for x in vector), 1.0, rel_tol=1e-9)
    assert body["usage"] == {"prompt_tokens": 3, "total_tokens": 3}

    single = client.post("/v1/embeddings", json={"input": "one"}).json()
    assert len(single["data"]) == 1
    assert client.post("/v1/embeddings", json={"input": [1, 2]}).status_code == 400


def test_embeddings_are_refused_for_other_tasks(client_for):
    client, _, _ = client_for("text-classification", [])
    r = client.post("/v1/embeddings", json={"input": "x"})
    assert r.status_code == 400
    assert "text-classification" in r.json()["error"]


def test_chat_completions(client_for):
    tokenizer = FakeTokenizer()
    client, pipe, _ = client_for("text-generation", [{"generated_text": "Hello there STOP more"}], tokenizer=tokenizer)
    r = client.post("/v1/chat/completions", json={
        "model": "tiny",
        "messages": [{"role": "system", "content": "be brief"}, {"role": "user", "content": "hi"}],
        "max_tokens": 16,
        "temperature": 0.5,
        "stop": ["STOP"],
    })
    assert r.status_code == 200
    body = r.json()
    assert body["object"] == "chat.completion"
    assert body["id"].startswith("chatcmpl-")
    assert body["model"] == "tiny"
    assert body["choices"][0]["message"] == {"role": "assistant", "content": "Hello there "}
    assert body["choices"][0]["finish_reason"] == "stop"
    assert set(body["usage"]) == {"prompt_tokens", "completion_tokens", "total_tokens"}

    prompt, kwargs = pipe.calls[0][0][0], pipe.calls[0][1]
    assert prompt == "<chat>be brief|hi<assistant>"
    assert kwargs == {"max_new_tokens": 16, "do_sample": True, "temperature": 0.5, "return_full_text": False}
    assert tokenizer.templated[0][2] is True  # add_generation_prompt


def test_chat_without_a_template_and_greedy_by_default(client_for):
    client, pipe, _ = client_for("text-generation", [{"generated_text": "ok"}])
    r = client.post("/v1/chat/completions", json={"messages": [{"role": "user", "content": "hi"}]})
    assert r.status_code == 200
    assert pipe.calls[0][0][0] == "user: hi\nassistant:"
    assert pipe.calls[0][1]["do_sample"] is False
    assert pipe.calls[0][1]["max_new_tokens"] == 256


def test_chat_rejects_streaming_bad_messages_and_other_tasks(client_for):
    client, _, _ = client_for("text-generation", [{"generated_text": "ok"}])
    assert client.post("/v1/chat/completions", json={"messages": [{"role": "user", "content": "hi"}], "stream": True}).status_code == 400
    assert client.post("/v1/chat/completions", json={"messages": []}).status_code == 400
    assert client.post("/v1/chat/completions", json={"messages": [{"role": "user"}]}).status_code == 400

    other, _, _ = client_for("fill-mask", [])
    assert other.post("/v1/chat/completions", json={"messages": [{"role": "user", "content": "hi"}]}).status_code == 400


def test_completions_for_a_list_of_prompts(client_for):
    client, pipe, _ = client_for("text-generation", lambda prompt, **_: [{"generated_text": prompt.upper()}])
    r = client.post("/v1/completions", json={"prompt": ["ab", "cd"], "max_tokens": 4})
    body = r.json()
    assert body["object"] == "text_completion"
    assert [c["text"] for c in body["choices"]] == ["AB", "CD"]
    assert [c["index"] for c in body["choices"]] == [0, 1]
    assert len(pipe.calls) == 2


def test_image_generations(client_for):
    result = type("R", (), {"images": [FakeImage(), FakeImage()]})()
    client, pipe, _ = client_for("text-to-image", result, engine="diffusers")
    r = client.post("/v1/images/generations", json={"prompt": "a cat", "n": 2, "size": "256x128", "num_inference_steps": 4})
    assert r.status_code == 200
    body = r.json()
    assert len(body["data"]) == 2
    assert base64.b64decode(body["data"][0]["b64_json"]).startswith(b"\x89PNG")
    assert pipe.calls[0][1] == {"width": 256, "height": 128, "num_images_per_prompt": 2, "num_inference_steps": 4}

    assert client.post("/v1/images/generations", json={"prompt": ""}).status_code == 400
    assert client.post("/v1/images/generations", json={"prompt": "x", "size": "big"}).status_code == 400


# ── Load failures of `python -m runr_hf_serve serve` ────────────────────────


def test_a_failed_load_exits_with_the_reason_in_the_termination_log(monkeypatch, tmp_path):
    from runr_hf_serve import server

    log_file = tmp_path / "termination-log"
    monkeypatch.setenv("RUNR_TERMINATION_LOG", str(log_file))
    exits = []
    monkeypatch.setattr(server, "_exit", exits.append)

    def broken(_settings):
        raise OSError("relative URL\nwithout a base")

    app = create_app(Settings(model_id="org/m", task="fill-mask"), loader=broken, background=False, exit_on_error=True)
    with TestClient(app):
        pass

    assert exits == [1]
    assert log_file.read_text(encoding="utf-8") == "the model failed to load: OSError: relative URL without a base"


def test_the_termination_reason_is_one_line_of_at_most_2_kb():
    from runr_hf_serve import server

    reason = server.termination_reason("ValueError: " + "é" * 3000)
    assert "\n" not in reason
    assert len(reason.encode("utf-8")) <= 2048
    assert reason.startswith("the model failed to load: ValueError: é")


def test_an_unwritable_termination_log_is_ignored(monkeypatch, tmp_path):
    from runr_hf_serve import server

    exits = []
    monkeypatch.setattr(server, "_exit", exits.append)
    assert server.write_termination_log("x", str(tmp_path / "missing" / "log")) is False

    monkeypatch.setenv("RUNR_TERMINATION_LOG", str(tmp_path / "missing" / "log"))
    server.exit_on_load_error("OSError: boom")
    assert exits == [1]


def test_without_exit_on_error_the_process_keeps_answering(monkeypatch):
    from runr_hf_serve import server

    exits = []
    monkeypatch.setattr(server, "_exit", exits.append)

    def broken(_settings):
        raise OSError("nope")

    with TestClient(create_app(Settings(model_id="org/m", task="fill-mask"), loader=broken, background=False)) as client:
        assert client.get("/healthz").status_code == 500
    assert exits == []
