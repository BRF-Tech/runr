"""Loads the configured model: a ``transformers`` pipeline or a ``diffusers`` pipeline.

The heavy libraries are imported here and only here, when the model is loaded, so the server,
the mirror and the receiver start (and are tested) without them.
"""

from __future__ import annotations

import logging
from dataclasses import dataclass, field
from typing import Any, Callable, Optional

from . import hub_cache, pipelines, threads
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
    """Load the model the settings describe (raises on any problem; the server reports it).

    Online with a pinned revision whose earlier load completed, the model cache's snapshot is
    loaded without a Hub request; when that fails, the model is loaded from the Hub (hub_cache).
    """
    settings.validate()
    device = resolve_device(settings.device)
    cached = hub_cache.complete_snapshot(settings)
    if settings.offline:
        origin = "offline"
    elif cached:
        origin = f"revision {settings.revision}, from the model cache"
    else:
        origin = f"revision {settings.revision or 'main'}"
    log.info("loading %s (%s, %s) for %s on %s", settings.source, origin, settings.engine, settings.task, device)

    if cached:
        try:
            return _load(settings, device, cached)
        except Exception as exc:  # an incomplete or damaged cache: the Hub has the files
            reason = (str(exc).strip().splitlines() or [""])[0]
            log.warning("the model cache's snapshot %s did not load (%s: %s); loading from the Hub",
                        cached, type(exc).__name__, reason)

    model = _load(settings, device, None)
    hub_cache.mark_complete(settings)
    return model


def _load(settings: Settings, device: str, local_dir: Optional[str]) -> LoadedModel:
    """``local_dir``: a directory loaded in place of the Hub repository (the cached snapshot)."""
    if settings.engine == "diffusers":
        return _load_diffusers(settings, device, local_dir)
    return _load_transformers(settings, device, local_dir)


def _dtype_argument(kwargs: dict) -> dict:
    """``torch_dtype`` of the pipeline arguments as ``dtype``, the name transformers 5 and diffusers take."""
    if "torch_dtype" in kwargs and "dtype" not in kwargs:
        kwargs["dtype"] = kwargs.pop("torch_dtype")
    return kwargs


def _load_transformers(settings: Settings, device: str, local_dir: Optional[str] = None) -> LoadedModel:
    from transformers import pipeline  # noqa: WPS433

    threads.apply_torch(threads.thread_count())

    kwargs = _dtype_argument(dict(settings.pipeline_kwargs))
    if not settings.offline and local_dir is None:
        if settings.revision:
            kwargs.setdefault("revision", settings.revision)
        if settings.token:
            kwargs.setdefault("token", settings.token)
    source = local_dir or settings.source

    task = PIPELINE_ALIASES.get(settings.task, settings.task)
    if task in pipelines.TASKS:
        # transformers 5 dropped these pipelines: RunR's own, same call and output shapes
        pipe = pipelines.load(task, source, device, kwargs)
    else:
        pipe = pipeline(task=task, model=source, device=0 if device == "cuda" else -1, **kwargs)

    return LoadedModel(
        task=settings.task,
        engine="transformers",
        pipe=pipe,
        tokenizer=getattr(pipe, "tokenizer", None),
        device=device,
    )


def _load_diffusers(settings: Settings, device: str, local_dir: Optional[str] = None) -> LoadedModel:
    import torch  # noqa: WPS433
    from diffusers import DiffusionPipeline  # noqa: WPS433

    threads.apply_torch(threads.thread_count())

    # dtype (diffusers deprecates torch_dtype and refuses both); a name from RUNR_PIPELINE_KWARGS
    # ("float16") as the torch dtype, which is what diffusers takes
    kwargs = _dtype_argument(dict(settings.pipeline_kwargs))
    if isinstance(kwargs.get("dtype"), str) and isinstance(getattr(torch, kwargs["dtype"], None), torch.dtype):
        kwargs["dtype"] = getattr(torch, kwargs["dtype"])
    kwargs.setdefault("dtype", torch.float16 if device == "cuda" else torch.float32)
    if settings.offline or local_dir is not None:
        kwargs.setdefault("local_files_only", True)
    else:
        if settings.revision:
            kwargs.setdefault("revision", settings.revision)
        if settings.token:
            kwargs.setdefault("token", settings.token)

    pipe = DiffusionPipeline.from_pretrained(local_dir or settings.source, **kwargs).to(device)

    return LoadedModel(task=settings.task, engine="diffusers", pipe=pipe, device=device)
