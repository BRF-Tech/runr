"""Online mode without a Hub request: the model cache's copy of a pinned revision.

In online mode the model is loaded from the Hub by id and revision, which fills the Hugging Face
cache (``HF_HUB_CACHE``; RunR puts it on the region's model volume). After a load of a pinned
revision (a full commit sha) has succeeded, a marker next to the cached repository records it:

    <HF_HUB_CACHE>/models--<org>--<name>/.runr-complete/<sha>-<key>.json

``key`` stands for the engine, the task and the pipeline arguments, which decide the files a load
reads. A later start with the same model, revision and key loads the cached snapshot directory
like an offline copy, without a request to the Hub, and loads from the Hub when that fails. A
branch or tag is resolved by the Hub on every start.
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import re
import tempfile
from datetime import datetime, timezone
from typing import Mapping, Optional

from .config import Settings

log = logging.getLogger("runr_hf_serve")

SHA_RE = re.compile(r"^[0-9a-f]{40}$")
MARKER_DIR = ".runr-complete"


def hub_cache_dir(env: Optional[Mapping[str, str]] = None) -> str:
    """The Hugging Face cache directory, with huggingface_hub's precedence of the variables."""
    env = os.environ if env is None else env

    def path(value: str) -> str:
        return os.path.expanduser(os.path.expandvars(value))

    for name in ("HF_HUB_CACHE", "HUGGINGFACE_HUB_CACHE"):
        if env.get(name):
            return path(env[name])
    if env.get("HF_HOME"):
        return os.path.join(path(env["HF_HOME"]), "hub")
    cache = env.get("XDG_CACHE_HOME") or os.path.join(os.path.expanduser("~"), ".cache")
    return os.path.join(path(cache), "huggingface", "hub")


def pinned(settings: Settings) -> bool:
    """Online mode with a full commit sha: the cached files of that revision never change."""
    return not settings.offline and bool(settings.model_id) and bool(SHA_RE.match(settings.revision or ""))


def _repo_dir(settings: Settings, env: Optional[Mapping[str, str]]) -> str:
    return os.path.join(hub_cache_dir(env), "models--" + str(settings.model_id).replace("/", "--"))


def _snapshot_dir(settings: Settings, env: Optional[Mapping[str, str]]) -> str:
    return os.path.join(_repo_dir(settings, env), "snapshots", str(settings.revision))


def _marker(settings: Settings, env: Optional[Mapping[str, str]]) -> str:
    load = json.dumps(
        {"engine": settings.engine, "task": settings.task, "kwargs": settings.pipeline_kwargs},
        sort_keys=True,
        default=str,
    )
    key = hashlib.sha256(load.encode("utf-8")).hexdigest()[:16]
    return os.path.join(_repo_dir(settings, env), MARKER_DIR, f"{settings.revision}-{key}.json")


def complete_snapshot(settings: Settings, env: Optional[Mapping[str, str]] = None) -> Optional[str]:
    """The cached snapshot directory of the pinned revision, once a load with these settings completed."""
    if not pinned(settings):
        return None
    snapshot = _snapshot_dir(settings, env)
    if os.path.isfile(_marker(settings, env)) and os.path.isdir(snapshot):
        return snapshot
    return None


def mark_complete(settings: Settings, env: Optional[Mapping[str, str]] = None) -> bool:
    """After a load from the Hub: the cache holds every file a load with these settings reads."""
    if not pinned(settings) or not os.path.isdir(_snapshot_dir(settings, env)):
        return False
    marker = _marker(settings, env)
    data = {
        "repo": settings.model_id,
        "revision": settings.revision,
        "engine": settings.engine,
        "task": settings.task,
        "completed_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    }
    tmp = None
    try:
        os.makedirs(os.path.dirname(marker), exist_ok=True)
        fd, tmp = tempfile.mkstemp(prefix=".tmp-", dir=os.path.dirname(marker))
        with os.fdopen(fd, "w", encoding="utf-8") as handle:
            json.dump(data, handle)
        os.chmod(tmp, 0o644)
        os.replace(tmp, marker)
    except OSError as exc:  # a read-only cache: every start asks the Hub
        log.warning("could not record the cached snapshot of %s@%s: %s", settings.model_id, settings.revision, exc)
        if tmp and os.path.exists(tmp):
            os.unlink(tmp)
        return False
    return True
