ARG VERSION=8.4
FROM ghcr.io/brf-tech/runr/reqs/php:${VERSION}-cli-alpine

WORKDIR /app

COPY server.php runner.php entrypoint.sh ./
RUN chmod +x entrypoint.sh

RUN mkdir -p /app/code

ENV RUNR_MODE=http
ENV RUNR_HANDLER=handler
ENV RUNR_FILENAME=main.php

EXPOSE 8080

ENTRYPOINT ["./entrypoint.sh"]
