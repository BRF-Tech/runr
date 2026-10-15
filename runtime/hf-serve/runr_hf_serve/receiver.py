"""``receive``: the target of chunked model uploads (air-gapped regions).

RunR starts it as a short-lived pod with the snapshot directory mounted and forwards every chunk
a user uploads through the Kubernetes API server, so a region needs no inbound path of its own:

- ``PUT /files/{path}?offset=N`` — the raw body is written at byte ``N`` of ``path`` (chunks may
  arrive in any order and be repeated); answers ``{"path", "size"}``
- ``POST /complete`` ``{"files": [{"path", "size", "sha256"?}]}`` — checks every file's size (and
  sha256 when given), then writes the snapshot marker; answers ``{"ok": true, "size_bytes", "files"}``
  or 422 ``{"ok": false, "error"}``
- ``GET /healthz``

Paths are relative POSIX paths inside the root; absolute paths, ``..`` and the marker are refused.
"""

from __future__ import annotations

import hashlib
import os
import posixpath
from typing import Optional

from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse

from .snapshot import MARKER, write_marker

CHUNK = 1024 * 1024


class PathError(ValueError):
    pass


def safe_path(root: str, path: str) -> str:
    """Absolute target of a relative upload path, refusing anything that leaves ``root``."""
    if not isinstance(path, str) or path.strip() == "":
        raise PathError("the path is empty")
    if "\\" in path or "\x00" in path:
        raise PathError("the path may not contain backslashes or NUL")
    if path.startswith("/") or posixpath.isabs(path) or (len(path) > 1 and path[1] == ":"):
        raise PathError("the path must be relative")
    parts = path.split("/")
    if any(part in ("", ".", "..") for part in parts):
        raise PathError("the path may not contain empty, '.' or '..' segments")
    if path == MARKER:
        raise PathError(f"{MARKER} is written by RunR")
    root_abs = os.path.realpath(root)
    target = os.path.realpath(os.path.join(root_abs, *parts))
    if os.path.commonpath([root_abs, target]) != root_abs:
        raise PathError("the path leaves the upload directory")
    return target


def sha256_of(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for block in iter(lambda: handle.read(CHUNK), b""):
            digest.update(block)
    return digest.hexdigest()


def create_receiver_app(root: str) -> FastAPI:
    os.makedirs(root, exist_ok=True)
    app = FastAPI(title="RunR model upload receiver", docs_url=None, redoc_url=None)

    @app.get("/healthz")
    def healthz():
        return {"status": "ok"}

    @app.put("/files/{path:path}")
    async def put_file(path: str, request: Request, offset: int = 0):
        try:
            target = safe_path(root, path)
        except PathError as exc:
            return JSONResponse(status_code=400, content={"error": str(exc)})
        if offset < 0:
            return JSONResponse(status_code=400, content={"error": "offset must be >= 0"})

        os.makedirs(os.path.dirname(target), exist_ok=True)
        mode = "r+b" if os.path.exists(target) else "w+b"
        with open(target, mode) as handle:
            handle.seek(offset)
            async for chunk in request.stream():
                if chunk:
                    handle.write(chunk)
        return {"path": path, "size": os.path.getsize(target)}

    @app.post("/complete")
    async def complete(request: Request):
        try:
            body = await request.json()
        except Exception:
            body = None
        files = body.get("files") if isinstance(body, dict) else None
        if not isinstance(files, list) or not files:
            return JSONResponse(status_code=422, content={"ok": False, "error": "files must be a non-empty list"})

        checked = []
        for entry in files:
            if not isinstance(entry, dict):
                return _fail("every file needs path and size")
            path, size, digest = entry.get("path"), entry.get("size"), entry.get("sha256")
            try:
                target = safe_path(root, path)
            except PathError as exc:
                return _fail(f"{path}: {exc}")
            if not isinstance(size, int) or size < 0:
                return _fail(f"{path}: size must be a non-negative integer")
            if not os.path.isfile(target):
                return _fail(f"{path}: not uploaded")
            actual = os.path.getsize(target)
            if actual != size:
                return _fail(f"{path}: {actual} bytes uploaded, {size} expected")
            if digest:
                actual_digest = sha256_of(target)
                if actual_digest.lower() != str(digest).lower():
                    return _fail(f"{path}: sha256 {actual_digest} does not match {digest}")
            checked.append({"path": path, "size": size})

        marker = write_marker(root, "upload", checked)
        return {"ok": True, "size_bytes": marker["size_bytes"], "files": marker["files"]}

    return app


def _fail(message: str, status: int = 422) -> JSONResponse:
    return JSONResponse(status_code=status, content={"ok": False, "error": message})


def serve(root: str, port: Optional[int] = None) -> None:  # pragma: no cover - process entry
    import uvicorn  # noqa: WPS433

    uvicorn.run(create_receiver_app(root), host="0.0.0.0", port=port or int(os.environ.get("PORT") or 8080))
