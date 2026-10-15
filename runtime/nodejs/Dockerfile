ARG VERSION=22
FROM ghcr.io/runrsh/reqs/node:${VERSION}-slim

WORKDIR /app

# OpenTelemetry auto-instrumentation in /app/otel, loaded only when RunR switches tracing on for a
# project (RUNR_OTEL_AUTO=1 → entrypoint.sh adds it to NODE_OPTIONS; OTEL_NODE_ENABLED_INSTRUMENTATIONS
# limits it to the instrumentations RunR selects). OTEL=0 builds without it.
ARG OTEL=1
RUN if [ "$OTEL" = "1" ]; then \
      mkdir -p /app/otel && cd /app/otel && npm init -y >/dev/null \
      && npm install --omit=dev --no-audit --no-fund @opentelemetry/api @opentelemetry/auto-instrumentations-node \
      && npm cache clean --force; \
    fi

COPY server.js runner.js entrypoint.sh ./
RUN chmod +x entrypoint.sh

RUN mkdir -p /app/code

ENV RUNR_MODE=http
ENV RUNR_HANDLER=handler
ENV RUNR_FILENAME=main.js

EXPOSE 8080

ENTRYPOINT ["./entrypoint.sh"]
