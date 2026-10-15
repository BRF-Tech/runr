"""``mirror``: download a pinned snapshot of a Hub repository into region storage.

    python -m runr_hf_serve mirror --repo ORG/NAME --revision REV --dest DIR \
        [--allow-pattern '*.safetensors' ...] [--ignore-pattern '*.bin' ...]

Uses ``huggingface_hub.snapshot_download(local_dir=DIR)``, so the directory holds the files of
the repository at that revision as plain files a serving engine loads with ``HF_HUB_OFFLINE=1``.
``HF_TOKEN`` from the environment opens gated and private repositories. The last line on stdout
is ``RUNR_SNAPSHOT <json>`` (the marker written to ``DIR/.runr-snapshot.json``) or, on failure,
``RUNR_ERROR <message>`` with exit status 1.
"""

from __future__ import annotations

import json
import os
import re
import sys
from typing import List, Optional

from .snapshot import list_files, write_marker

SHA_RE = re.compile(r"^[0-9a-f]{40}$")


def resolve_revision(repo: str, revision: Optional[str], token: Optional[str]) -> str:
    """The commit sha a branch or tag points to (a full sha is returned as it is)."""
    if revision and SHA_RE.match(revision):
        return revision
    from huggingface_hub import HfApi  # noqa: WPS433

    return HfApi().model_info(repo, revision=revision or "main", token=token).sha


def download(repo: str, revision: str, dest: str, allow: Optional[List[str]], ignore: Optional[List[str]], token: Optional[str]) -> str:
    from huggingface_hub import snapshot_download  # noqa: WPS433

    return snapshot_download(
        repo_id=repo,
        revision=revision,
        local_dir=dest,
        allow_patterns=allow or None,
        ignore_patterns=ignore or None,
        token=token,
    )


def explain(exc: Exception) -> str:
    """A message for the RunR UI; gated and unknown repositories get a hint."""
    name = type(exc).__name__
    text = str(exc).strip().splitlines()[0] if str(exc).strip() else name
    if name == "GatedRepoError":
        return f"{text} — the repository is gated: accept its terms on the Hub and save a Hugging Face token in RunR"
    if name in ("RepositoryNotFoundError", "RevisionNotFoundError"):
        return f"{text} — check the repository id and revision (private repositories need a token)"
    return f"{name}: {text}"


def run(args) -> int:
    token = os.environ.get("HF_TOKEN") or os.environ.get("HUGGING_FACE_HUB_TOKEN") or None
    try:
        os.makedirs(args.dest, exist_ok=True)
        sha = resolve_revision(args.repo, args.revision, token)
        print(f"mirroring {args.repo}@{sha} into {args.dest}", flush=True)
        download(args.repo, sha, args.dest, args.allow_pattern, args.ignore_pattern, token)
        files = list_files(args.dest)
        if not files:
            raise RuntimeError("the download produced no files (check the allow / ignore patterns)")
        marker = write_marker(args.dest, "hub", files, repo=args.repo, revision=sha)
    except Exception as exc:  # reported to RunR through the last log line
        print(f"RUNR_ERROR {explain(exc)}", flush=True)
        return 1

    print(f"{len(marker['files'])} files, {marker['size_bytes']} bytes", flush=True)
    print("RUNR_SNAPSHOT " + json.dumps(marker, separators=(",", ":")), flush=True)
    return 0


def add_arguments(parser) -> None:
    parser.add_argument("--repo", required=True, help="Hub repository id, org/name")
    parser.add_argument("--revision", default="main", help="commit sha, branch or tag (default main)")
    parser.add_argument("--dest", required=True, help="target directory (created when absent)")
    parser.add_argument("--allow-pattern", action="append", default=None, help="only files matching (repeatable)")
    parser.add_argument("--ignore-pattern", action="append", default=None, help="skip files matching (repeatable)")


if __name__ == "__main__":  # pragma: no cover
    import argparse

    p = argparse.ArgumentParser(prog="runr_hf_serve.mirror")
    add_arguments(p)
    sys.exit(run(p.parse_args()))
