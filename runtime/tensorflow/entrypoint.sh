#!/bin/sh
set -e

if [ "$RUNR_MODE" = "schedule" ]; then
    exec python -u /app/runner.py
else
    exec python -u /app/server.py
fi
