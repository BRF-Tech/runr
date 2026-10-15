ARG VERSION=1.26
FROM ghcr.io/runrsh/reqs/golang:${VERSION}-alpine

WORKDIR /app

COPY wrapper_http.go wrapper_schedule.go entrypoint.sh ./
RUN chmod +x entrypoint.sh

RUN mkdir -p /app/code

ENV RUNR_MODE=http

EXPOSE 8080

ENTRYPOINT ["./entrypoint.sh"]
