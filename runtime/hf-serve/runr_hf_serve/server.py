"""The model server: a uniform ``/predict`` route plus OpenAI-compatible routes per task.

The model is loaded once at startup (in a background thread, so the process answers health
checks meanwhile): ``/healthz`` answers 503 while loading, 500 when loading failed — the pod
never becomes ready and the reason is in the logs and the body — and 200 once the model serves.
"""

from __future__ import annotations

import logging
import threading
import time
import uuid
from contextlib import asynccontextmanager
from typing import Any, Callable, Optional

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from starlette.concurrency import run_in_threadpool

from . import tasks
from .config import Settings
from .loader import LoadedModel, load_model

log = logging.getLogger("runr_hf_serve")

Loader = Callable[[Settings], LoadedModel]


class ModelState:
    """Loading status and the loaded model; inference is serialised (pipelines are not thread-safe)."""

    def __init__(self, settings: Settings, loader: Loader):
        self.settings = settings
        self.loader = loader
        self.status = "loading"
        self.error: Optional[str] = None
        self.model: Optional[LoadedModel] = None
        self.lock = threading.Lock()
        self.loaded_at: Optional[float] = None

    def load(self) -> None:
        started = time.monotonic()
        try:
            self.model = self.loader(self.settings)
            self.status = "ready"
            self.loaded_at = time.time()
            log.info("model ready after %.1f s", time.monotonic() - started)
        except Exception as exc:  # reported on /healthz and in the log
            self.status = "error"
            self.error = f"{type(exc).__name__}: {exc}"
            log.exception("loading the model failed")


def _error(status: int, message: str) -> JSONResponse:
    return JSONResponse(status_code=status, content={"error": message})


async def _json_body(request: Request) -> dict:
    try:
        body = await request.json()
    except Exception as exc:
        raise tasks.InputError(f"the body must be JSON: {exc}") from exc
    if not isinstance(body, dict):
        raise tasks.InputError("the body must be a JSON object")
    return body


def create_app(settings: Optional[Settings] = None, loader: Optional[Loader] = None, background: bool = True) -> FastAPI:
    settings = settings or Settings.from_env()
    state = ModelState(settings, loader or load_model)

    @asynccontextmanager
    async def lifespan(_app: FastAPI):
        if background:
            threading.Thread(target=state.load, name="model-loader", daemon=True).start()
        else:
            state.load()
        yield

    app = FastAPI(title="RunR hf-serve", lifespan=lifespan, docs_url=None, redoc_url=None)
    app.state.runr = state

    def ready_model(*task_groups: set) -> LoadedModel:
        if state.status == "loading":
            raise _NotReady(503, "the model is still loading")
        if state.status == "error" or state.model is None:
            raise _NotReady(500, f"the model failed to load: {state.error}")
        if task_groups and not any(state.model.task in group for group in task_groups):
            raise tasks.InputError(f"this route is not available for the task {state.model.task}")
        return state.model

    @app.exception_handler(tasks.InputError)
    async def input_error(_request: Request, exc: tasks.InputError):
        return _error(400, str(exc))

    @app.exception_handler(_NotReady)
    async def not_ready(_request: Request, exc: "_NotReady"):
        return _error(exc.status, exc.message)

    def locked(fn: Callable[[], Any]) -> Any:
        try:
            with state.lock:
                return fn()
        except (tasks.InputError, _NotReady):
            raise
        except Exception as exc:
            log.exception("inference failed")
            raise _NotReady(500, f"inference failed: {type(exc).__name__}: {exc}") from exc

    async def run(fn: Callable[[], Any]) -> Any:
        """Inference off the event loop (health checks keep answering), one call at a time."""
        return await run_in_threadpool(locked, fn)

    # ── Health and metadata ────────────────────────────────────────────────

    @app.get("/healthz")
    def healthz():
        if state.status == "ready":
            return {"status": "ok"}
        if state.status == "loading":
            return JSONResponse(status_code=503, content={"status": "loading"})
        return JSONResponse(status_code=500, content={"status": "error", "error": state.error})

    @app.get("/info")
    def info():
        model = state.model
        return {
            "model": settings.model_id,
            "revision": settings.revision,
            "path": settings.model_path,
            "task": settings.task,
            "engine": settings.engine,
            "device": model.device if model else settings.device,
            "served_name": settings.served_name,
            "status": state.status,
        }

    @app.get("/v1/models")
    def models():
        return {
            "object": "list",
            "data": [{"id": settings.served_name, "object": "model", "owned_by": "runr"}],
        }

    # ── Uniform route ──────────────────────────────────────────────────────

    @app.post("/predict")
    async def predict(request: Request):
        body = await _json_body(request)
        model = ready_model()
        parameters = body.get("parameters") or {}
        if not isinstance(parameters, dict):
            raise tasks.InputError("parameters must be a JSON object")
        outputs = await run(lambda: tasks.predict(model, body.get("inputs"), parameters))
        return {"model": settings.served_name, "task": model.task, "outputs": outputs}

    # ── OpenAI-compatible routes ───────────────────────────────────────────

    @app.post("/v1/embeddings")
    async def embeddings(request: Request):
        body = await _json_body(request)
        model = ready_model(tasks.EMBEDDING_TASKS)
        texts = body.get("input")
        if isinstance(texts, str):
            texts = [texts]
        if not isinstance(texts, list) or not texts or not all(isinstance(t, str) for t in texts):
            raise tasks.InputError("input must be a string or a non-empty list of strings")
        vectors = await run(lambda: tasks.embed(model, texts))
        tokens = sum(tasks.count_tokens(model.tokenizer, t) for t in texts)
        return {
            "object": "list",
            "data": [{"object": "embedding", "index": i, "embedding": v} for i, v in enumerate(vectors)],
            "model": settings.served_name,
            "usage": {"prompt_tokens": tokens, "total_tokens": tokens},
        }

    @app.post("/v1/chat/completions")
    async def chat_completions(request: Request):
        body = await _json_body(request)
        model = ready_model(tasks.GENERATION_TASKS)
        if body.get("stream"):
            raise tasks.InputError("streaming is not available on this engine; send stream: false")
        prompt = tasks.chat_prompt(model.tokenizer, body.get("messages"))
        kwargs = tasks.generation_kwargs(body)
        text = tasks.apply_stop(await run(lambda: tasks.generate(model, prompt, kwargs)), body.get("stop"))
        prompt_tokens = tasks.count_tokens(model.tokenizer, prompt)
        completion_tokens = tasks.count_tokens(model.tokenizer, text)
        return {
            "id": f"chatcmpl-{uuid.uuid4().hex}",
            "object": "chat.completion",
            "created": int(time.time()),
            "model": settings.served_name,
            "choices": [{"index": 0, "message": {"role": "assistant", "content": text}, "finish_reason": "stop"}],
            "usage": {
                "prompt_tokens": prompt_tokens,
                "completion_tokens": completion_tokens,
                "total_tokens": prompt_tokens + completion_tokens,
            },
        }

    @app.post("/v1/completions")
    async def completions(request: Request):
        body = await _json_body(request)
        model = ready_model(tasks.GENERATION_TASKS)
        if body.get("stream"):
            raise tasks.InputError("streaming is not available on this engine; send stream: false")
        prompts = body.get("prompt")
        if isinstance(prompts, str):
            prompts = [prompts]
        if not isinstance(prompts, list) or not prompts or not all(isinstance(p, str) for p in prompts):
            raise tasks.InputError("prompt must be a string or a non-empty list of strings")
        kwargs = tasks.generation_kwargs(body)
        texts = []
        for p in prompts:
            generated = await run(lambda p=p: tasks.generate(model, p, kwargs))
            texts.append(tasks.apply_stop(generated, body.get("stop")))
        prompt_tokens = sum(tasks.count_tokens(model.tokenizer, p) for p in prompts)
        completion_tokens = sum(tasks.count_tokens(model.tokenizer, t) for t in texts)
        return {
            "id": f"cmpl-{uuid.uuid4().hex}",
            "object": "text_completion",
            "created": int(time.time()),
            "model": settings.served_name,
            "choices": [{"index": i, "text": t, "finish_reason": "stop"} for i, t in enumerate(texts)],
            "usage": {
                "prompt_tokens": prompt_tokens,
                "completion_tokens": completion_tokens,
                "total_tokens": prompt_tokens + completion_tokens,
            },
        }

    @app.post("/v1/images/generations")
    async def image_generations(request: Request):
        body = await _json_body(request)
        model = ready_model(tasks.IMAGE_GENERATION_TASKS)
        parameters = tasks.parse_size(body.get("size"))
        if body.get("n"):
            parameters["num_images_per_prompt"] = int(body["n"])
        for key in ("num_inference_steps", "guidance_scale", "negative_prompt"):
            if key in body:
                parameters[key] = body[key]
        if body.get("seed") is not None:
            parameters["generator"] = _generator(model, int(body["seed"]))
        images = await run(lambda: tasks.text_to_image(model, body.get("prompt"), parameters))
        return {"created": int(time.time()), "data": [{"b64_json": image} for image in images]}

    return app


def _generator(model: LoadedModel, seed: int):
    import torch  # noqa: WPS433

    return torch.Generator(device=model.device).manual_seed(seed)


class _NotReady(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status
        self.message = message
