"""Shared fixtures: fake pipelines instead of transformers / diffusers models."""

import base64
import io
import os
import sys

import pytest

sys.path.insert(0, os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from fastapi.testclient import TestClient  # noqa: E402

from runr_hf_serve.config import Settings  # noqa: E402
from runr_hf_serve.loader import LoadedModel  # noqa: E402
from runr_hf_serve.server import create_app  # noqa: E402


class FakePipe:
    """Records every call; answers with ``reply`` (a value or a callable of the call)."""

    def __init__(self, reply):
        self.reply = reply
        self.calls = []

    def __call__(self, *args, **kwargs):
        self.calls.append((args, kwargs))
        return self.reply(*args, **kwargs) if callable(self.reply) else self.reply


class FakeTokenizer:
    chat_template = "{% for m in messages %}{{ m.content }}{% endfor %}"

    def __init__(self):
        self.templated = []

    def apply_chat_template(self, messages, tokenize=False, add_generation_prompt=True):
        self.templated.append((messages, tokenize, add_generation_prompt))
        return "<chat>" + "|".join(m["content"] for m in messages) + "<assistant>"

    def __call__(self, text):
        return {"input_ids": list(range(len(text.split())))}


class FakeImage:
    """Enough of a PIL image for the server: save() writes a real 1x1 PNG."""

    mode = "RGB"
    size = (1, 1)

    def save(self, buffer, format="PNG"):  # noqa: A002 (PIL signature)
        buffer.write(png_bytes())


def png_bytes() -> bytes:
    from PIL import Image

    buffer = io.BytesIO()
    Image.new("RGB", (2, 2), (255, 0, 0)).save(buffer, format="PNG")
    return buffer.getvalue()


def png_base64() -> str:
    return base64.b64encode(png_bytes()).decode("ascii")


def make_client(task, reply, engine="transformers", tokenizer=None, settings=None):
    pipe = FakePipe(reply)
    model = LoadedModel(task=task, engine=engine, pipe=pipe, tokenizer=tokenizer, device="cpu")
    settings = settings or Settings(model_id="org/tiny", revision="abc", task=task, engine=engine, served_name="tiny")
    app = create_app(settings, loader=lambda _s: model, background=False)
    return TestClient(app), pipe, app


@pytest.fixture
def client_for():
    clients = []

    def factory(*args, **kwargs):
        client, pipe, app = make_client(*args, **kwargs)
        client.__enter__()
        clients.append(client)
        return client, pipe, app

    yield factory
    for client in clients:
        client.__exit__(None, None, None)
