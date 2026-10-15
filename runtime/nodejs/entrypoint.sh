#!/bin/sh
set -e

# Tracing on for the project (RunR sets RUNR_OTEL_AUTO=1 and the OTEL_* variables):
# OpenTelemetry auto-instrumentation through NODE_OPTIONS (docs/observability.md)
OTEL_REGISTER=/app/otel/node_modules/@opentelemetry/auto-instrumentations-node/build/src/register.js
if [ "$RUNR_OTEL_AUTO" = "1" ] && [ -f "$OTEL_REGISTER" ]; then
    export NODE_OPTIONS="${NODE_OPTIONS:+$NODE_OPTIONS }--require $OTEL_REGISTER"
fi

if [ "$RUNR_MODE" = "schedule" ]; then
    exec node /app/runner.js
else
    exec node /app/server.js
fi
