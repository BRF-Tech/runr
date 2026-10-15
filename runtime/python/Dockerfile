ARG VERSION=3.13
FROM ghcr.io/runrsh/reqs/python:${VERSION}-slim

WORKDIR /app

# Bytecode of the standard library (incl. site-packages) compiled at build time. The official
# slim image ships the stdlib without .pyc, so every cold start compiled each module it imported
# under the function's CPU limit. Level 0 = what entrypoint.sh runs (python -u, no -O /
# PYTHONOPTIMIZE); unchecked-hash .pyc are used without looking at the source mtime, so nothing
# at start can invalidate them. server.py / runner.py run as __main__, which Python never loads
# from bytecode (0.6 ms to compile at 250m) — no .pyc for them.
RUN python -m compileall -q -j 0 --invalidation-mode unchecked-hash \
      "$(python -c 'import sysconfig; print(sysconfig.get_path("stdlib"))')"

# OpenTelemetry auto-instrumentation in /app/otel, used only when RunR switches tracing on for a
# project (RUNR_OTEL_AUTO=1 → entrypoint.sh runs the server under opentelemetry-instrument).
# Instrumentations activate only for libraries the handler actually has. OTEL=0 builds without it.
ARG OTEL=1
RUN if [ "$OTEL" = "1" ]; then \
      pip install --no-cache-dir --target=/app/otel \
        opentelemetry-distro opentelemetry-exporter-otlp-proto-http \
        opentelemetry-instrumentation-urllib opentelemetry-instrumentation-urllib3 \
        opentelemetry-instrumentation-requests opentelemetry-instrumentation-httpx \
        opentelemetry-instrumentation-logging opentelemetry-instrumentation-redis \
        opentelemetry-instrumentation-psycopg2 opentelemetry-instrumentation-sqlite3 \
      && python -m compileall -q -j 0 --invalidation-mode unchecked-hash /app/otel; \
    fi

COPY server.py runner.py entrypoint.sh ./
RUN chmod +x entrypoint.sh

# User code is mounted here (ConfigMap)
RUN mkdir -p /app/code

ENV RUNR_MODE=http
ENV RUNR_HANDLER=handler
ENV RUNR_FILENAME=main.py

EXPOSE 8080

ENTRYPOINT ["./entrypoint.sh"]
