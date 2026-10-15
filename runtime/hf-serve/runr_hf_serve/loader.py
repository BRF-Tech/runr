"""Loads the configured model: a ``transformers`` pipeline or a ``diffusers`` pipeline.

The heavy libraries are imported here and only here, when the model is loaded, so the server,
the mirror and the receiver start (and are tested) without them.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any, Callable, Optional

from . import pipelines, threads
from .config import Settings

log = logging.getLogger("runr_hf_serve")

# Hub pipeline tags that map to a different transformers pipeline
PIPELINE_ALIASES = {
    "sentence-similarity": "feature-extraction",
    "conversational": "text-generation",
}


@dataclass
class LoadedModel:
    """What the routes need of a loaded model."""

    task: str
    engine: str
    pipe: Callable[..., Any]
    tokenizer: Optional[Any] = None
    device: str = "cpu"
    info: dict = field(default_factory=dict)


def resolve_device(preference: str, cuda_available: Optional[Callable[[], bool]] = None) -> str:
    """``auto`` → ``cuda`` when torch sees a GPU, else ``cpu``."""
    if preference in ("cpu", "cuda"):
        return preference
    if cuda_available is None:
        try:
            import torch  # noqa: WPS433 (lazy import)

            cuda_available = torch.cuda.is_available
        except Exception:  # torch absent or broken: CPU
            return "cpu"
    try:
        return "cuda" if cuda_available() else "cpu"
    except Exception:
        return "cpu"


def load_model(settings: Settings) -> LoadedModel:
    """Load the model the settings describe (raises on any problem; the server reports it)."""
    settings.validate()
    device = resolve_device(settings.device)
    log.info(
        "loading %s (%s, %s) for %s on %s",
        settings.source,
        "offline" if settings.offline else f"revision {settings.revision or 'main'}",
        settings.engine,
        settings.task,
        device,
    )
    if settings.engine == "diffusers":
        return _load_diffusers(settings, device)
    return _load_transformers(settings, device)


def _load_transformers(settings: Settings, device: str) -> LoadedModel:
    from transformers import pipeline  # noqa: WPS433

    threads.apply_torch(threads.thread_count())

    kwargs = dict(settings.pipeline_kwargs)
    if not settings.offline:
        if settings.revision:
            kwargs.setdefault("revision", settings.revision)
        if settings.token:
            kwargs.setdefault("token", settings.token)

    task = PIPELINE_ALIASES.get(settings.task, settings.task)
    if task in pipelines.TASKS:
        # transformers 5 dropped these pipelines: RunR's own, same call and output shapes
        pipe = pipelines.load(task, settings.source, device, kwargs)
    else:
        pipe = pipeline(task=task, model=settings.source, device=0 if device == "cuda" else -1, **kwargs)

    return LoadedModel(
        task=settings.task,
        engine="transformers",
        pipe=pipe,
        tokenizer=getattr(pipe, "tokenizer", None),
        device=device,
    )


def _load_diffusers(settings: Settings, device: str) -> LoadedModel:
    import torch  # noqa: WPS433
    from diffusers import DiffusionPipeline  # noqa: WPS433

    threads.apply_torch(threads.thread_count())

    kwargs = dict(settings.pipeline_kwargs)
    kwargs.setdefault("torch_dtype", torch.float16 if device == "cuda" else torch.float32)
    if settings.offline:
        kwargs.setdefault("local_files_only", True)
    else:
        if settings.revision:
            kwargs.setdefault("revision", settings.revision)
        if settings.token:
            kwargs.setdefault("token", settings.token)

    pipe = DiffusionPipeline.from_pretrained(settings.source, **kwargs).to(device)

    return LoadedModel(task=settings.task, engine="diffusers", pipe=pipe, device=device)
