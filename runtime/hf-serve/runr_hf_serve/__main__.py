"""Entry point of the image: ``serve`` (default), ``mirror`` or ``receive``."""

from __future__ import annotations

import argparse
import logging
import os
import sys
from typing import List, Optional

from . import __version__, mirror, threads


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="python -m runr_hf_serve", description="RunR hf-serve " + __version__)
    sub = parser.add_subparsers(dest="command")

    sub.add_parser("serve", help="serve the model the RUNR_* environment describes (default)")

    mirror.add_arguments(sub.add_parser("mirror", help="download a pinned Hub snapshot into a directory"))

    receive = sub.add_parser("receive", help="receive chunked uploads into a directory")
    receive.add_argument("--root", required=True, help="the snapshot directory files are written to")

    sub.add_parser("version", help="print the version")
    return parser


def main(argv: Optional[List[str]] = None) -> int:
    logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO").upper(), format="%(asctime)s %(levelname)s %(name)s: %(message)s")
    args = build_parser().parse_args(argv)
    command = args.command or "serve"

    if command == "version":
        print(__version__)
        return 0

    if command == "mirror":
        return mirror.run(args)

    import uvicorn  # noqa: WPS433

    port = int(os.environ.get("PORT") or 8080)
    if command == "receive":
        from .receiver import create_receiver_app  # noqa: WPS433

        uvicorn.run(create_receiver_app(args.root), host="0.0.0.0", port=port)
        return 0

    # before anything imports torch: OpenMP / MKL read their thread counts once
    threads.configure()

    from .server import create_app  # noqa: WPS433

    # a model that does not load ends the process (exit 1, reason in the termination log): the
    # pod restarts with back-off (transient Hub errors heal) and Kubernetes shows why
    uvicorn.run(create_app(exit_on_error=True), host="0.0.0.0", port=port, timeout_keep_alive=75)
    return 0


if __name__ == "__main__":
    sys.exit(main())
