#!/bin/sh
set -e

if [ "$RUNR_MODE" = "schedule" ]; then
    set -- python -u /app/runner.py
else
    set -- python -u /app/server.py
fi

# Tracing on for the project (RunR sets RUNR_OTEL_AUTO=1 and the OTEL_* variables):
# OpenTelemetry auto-instrumentation of the libraries the handler uses (docs/observability.md)
if [ "$RUNR_OTEL_AUTO" = "1" ] && [ -x /app/otel/bin/opentelemetry-instrument ]; then
    export PYTHONPATH="${PYTHONPATH:+$PYTHONPATH:}/app/otel"
    exec /app/otel/bin/opentelemetry-instrument "$@"
fi

exec "$@"
