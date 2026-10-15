"""The snapshot marker every offline copy of a model carries: ``<dir>/.runr-snapshot.json``.

RunR mounts a snapshot directory only once the marker exists; mirror, upload and import write it
last, after every file is in place.
"""

from __future__ import annotations

import json
import os
import tempfile
from datetime import datetime, timezone
from typing import Iterable, List, Optional

MARKER = ".runr-snapshot.json"

# Bookkeeping of huggingface_hub inside a local_dir download; not part of the model
SKIPPED_DIRS = {".cache", ".git", ".huggingface"}


def list_files(root: str) -> List[dict]:
    """Every file below ``root`` (relative POSIX paths, sizes), the marker and Hub metadata left out."""
    files = []
    for directory, subdirs, names in os.walk(root):
        rel_dir = os.path.relpath(directory, root)
        subdirs[:] = sorted(d for d in subdirs if not (rel_dir == "." and d in SKIPPED_DIRS))
        for name in sorted(names):
            path = os.path.join(directory, name)
            rel = os.path.relpath(path, root).replace(os.sep, "/")
            if rel == MARKER or not os.path.isfile(path):
                continue
            files.append({"path": rel, "size": os.path.getsize(path)})
    return files


def write_marker(root: str, source: str, files: Iterable[dict], repo: Optional[str] = None, revision: Optional[str] = None) -> dict:
    files = [{"path": f["path"], "size": int(f["size"])} for f in files]
    data = {
        "repo": repo,
        "revision": revision,
        "source": source,
        "files": files,
        "size_bytes": sum(f["size"] for f in files),
        "completed_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    }
    fd, tmp = tempfile.mkstemp(prefix=".runr-snapshot.", dir=root)
    with os.fdopen(fd, "w", encoding="utf-8") as handle:
        json.dump(data, handle, indent=2)
    os.replace(tmp, os.path.join(root, MARKER))
    return data
