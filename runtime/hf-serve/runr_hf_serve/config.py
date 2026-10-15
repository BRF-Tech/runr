"""Settings of the model server, read from the environment RunR sets on the model service.

| Variable | Meaning |
|---|---|
| ``RUNR_MODEL_ID`` | Hub repository id (``org/name``), online mode |
| ``RUNR_MODEL_REVISION`` | commit sha or branch of the repository |
| ``RUNR_MODEL_PATH`` | local model directory; wins over the id (offline mode) |
| ``RUNR_TASK`` | pipeline tag (``text-classification``, ``feature-extraction``, ...) |
| ``RUNR_ENGINE`` | ``transformers`` (default) or ``diffusers`` |
| ``RUNR_SERVED_NAME`` | the ``model`` name of the OpenAI-compatible routes |
| ``RUNR_DEVICE`` | ``auto`` (default), ``cpu`` or ``cuda`` |
| ``RUNR_PIPELINE_KWARGS`` | JSON object passed to ``pipeline()`` / ``from_pretrained()`` |
| ``PORT`` | listen port, ``8080`` |
| ``HF_TOKEN`` | token for gated and private repositories |
"""

from __future__ import annotations

import json
import os
from dataclasses import dataclass, field
from typing import Mapping, Optional

ENGINES = ("transformers", "diffusers")
DEVICES = ("auto", "cpu", "cuda")


class ConfigError(ValueError):
    """The environment does not describe a model the server can load."""


def _clean(value: Optional[str]) -> Optional[str]:
    value = (value or "").strip()
    return value or None


@dataclass
class Settings:
    model_id: Optional[str] = None
    revision: Optional[str] = None
    model_path: Optional[str] = None
    task: Optional[str] = None
    engine: str = "transformers"
    served_name: str = "model"
    device: str = "auto"
    pipeline_kwargs: dict = field(default_factory=dict)
    port: int = 8080
    token: Optional[str] = None

    @property
    def offline(self) -> bool:
        """A local directory is served: nothing is fetched from the Hub."""
        return self.model_path is not None

    @property
    def source(self) -> Optional[str]:
        """What ``from_pretrained`` gets: the local path when set, else the repository id."""
        return self.model_path or self.model_id

    def validate(self) -> "Settings":
        if not self.source:
            raise ConfigError("set RUNR_MODEL_PATH (offline) or RUNR_MODEL_ID (online)")
        if not self.task:
            raise ConfigError("RUNR_TASK is required (the model's pipeline tag)")
        if self.engine not in ENGINES:
            raise ConfigError(f"RUNR_ENGINE must be one of {', '.join(ENGINES)}, not {self.engine!r}")
        if self.device not in DEVICES:
            raise ConfigError(f"RUNR_DEVICE must be one of {', '.join(DEVICES)}, not {self.device!r}")
        if self.offline and not os.path.isdir(self.model_path):
            raise ConfigError(f"RUNR_MODEL_PATH {self.model_path} is not a directory")
        return self

    @classmethod
    def from_env(cls, env: Optional[Mapping[str, str]] = None) -> "Settings":
        env = os.environ if env is None else env

        model_id = _clean(env.get("RUNR_MODEL_ID"))
        model_path = _clean(env.get("RUNR_MODEL_PATH"))
        engine = (_clean(env.get("RUNR_ENGINE")) or "transformers").lower()
        task = _clean(env.get("RUNR_TASK"))
        if task is None and engine == "diffusers":
            task = "text-to-image"

        raw_kwargs = _clean(env.get("RUNR_PIPELINE_KWARGS"))
        kwargs: dict = {}
        if raw_kwargs:
            try:
                kwargs = json.loads(raw_kwargs)
            except json.JSONDecodeError as exc:
                raise ConfigError(f"RUNR_PIPELINE_KWARGS is not valid JSON: {exc}") from exc
            if not isinstance(kwargs, dict):
                raise ConfigError("RUNR_PIPELINE_KWARGS must be a JSON object")

        served = _clean(env.get("RUNR_SERVED_NAME"))
        if served is None:
            if model_path:
                served = os.path.basename(model_path.rstrip("/\\")) or "model"
            elif model_id:
                served = model_id.rsplit("/", 1)[-1]
            else:
                served = "model"

        try:
            port = int(env.get("PORT") or 8080)
        except ValueError as exc:
            raise ConfigError(f"PORT must be a number, not {env.get('PORT')!r}") from exc

        return cls(
            model_id=model_id,
            revision=_clean(env.get("RUNR_MODEL_REVISION")),
            model_path=model_path,
            task=task,
            engine=engine,
            served_name=served,
            device=(_clean(env.get("RUNR_DEVICE")) or "auto").lower(),
            pipeline_kwargs=kwargs,
            port=port,
            token=_clean(env.get("HF_TOKEN")) or _clean(env.get("HUGGING_FACE_HUB_TOKEN")),
        )
