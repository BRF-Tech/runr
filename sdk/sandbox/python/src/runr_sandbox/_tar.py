"""Tar archives for directory transfer (``files/archive``)."""

from __future__ import annotations

import io
import os
import posixpath
import tarfile
from typing import List, Mapping, Union

from .errors import RunrSandboxError


def tar_directory(local_dir: Union[str, "os.PathLike[str]"]) -> bytes:
    """A tar of a local directory's contents, entry names relative to it."""
    root = os.fspath(local_dir)
    if not os.path.isdir(root):
        raise RunrSandboxError(None, f"{root} is not a directory.", "local_path")
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w", format=tarfile.PAX_FORMAT) as archive:
        for name in sorted(os.listdir(root)):
            archive.add(os.path.join(root, name), arcname=name, recursive=True, filter=_reset_owner)
    return buffer.getvalue()


def tar_files(files: Mapping[str, Union[str, bytes]]) -> bytes:
    """A tar of in-memory files ``{relative path: content}``."""
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w", format=tarfile.PAX_FORMAT) as archive:
        for name, content in files.items():
            relative = _safe_name(name)
            raw = content.encode("utf-8") if isinstance(content, str) else bytes(content)
            info = tarfile.TarInfo(relative)
            info.size = len(raw)
            info.mode = 0o644
            archive.addfile(info, io.BytesIO(raw))
    return buffer.getvalue()


def _reset_owner(info: tarfile.TarInfo) -> tarfile.TarInfo:
    info.uid = info.gid = 0
    info.uname = info.gname = ""
    return info


def _safe_name(name: str) -> str:
    normalized = posixpath.normpath(name.replace("\\", "/")).lstrip("/")
    if normalized in ("", ".") or normalized == ".." or normalized.startswith("../"):
        raise RunrSandboxError(None, f"Invalid archive entry name: {name!r}.", "archive")
    return normalized


def extract_archive(data: bytes, local_dir: Union[str, "os.PathLike[str]"]) -> List[str]:
    """Extract a tar into ``local_dir`` (created when needed); entries that would land outside
    of it (absolute names, ``..``, links pointing out) are refused. Returns the paths written."""
    root = os.path.realpath(os.fspath(local_dir))
    os.makedirs(root, exist_ok=True)
    written: List[str] = []
    with tarfile.open(fileobj=io.BytesIO(data), mode="r:*") as archive:
        members = archive.getmembers()
        for member in members:
            target = os.path.realpath(os.path.join(root, member.name))
            if target != root and not target.startswith(root + os.sep):
                raise RunrSandboxError(None, f"Archive entry {member.name!r} points outside {root}.", "archive")
            if member.issym() or member.islnk():
                link = os.path.realpath(os.path.join(os.path.dirname(target), member.linkname))
                if link != root and not link.startswith(root + os.sep):
                    raise RunrSandboxError(None, f"Archive link {member.name!r} points outside {root}.", "archive")
            if member.isdev():
                raise RunrSandboxError(None, f"Archive entry {member.name!r} is a device file.", "archive")
        if hasattr(tarfile, "data_filter"):
            archive.extractall(root, members=members, filter="data")
        else:  # pragma: no cover - Python without extraction filters
            archive.extractall(root, members=members)
        for member in members:
            if member.isfile():
                written.append(os.path.join(root, member.name))
    return written
