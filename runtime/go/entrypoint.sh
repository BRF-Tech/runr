#!/bin/sh
set -e

cd /app/code

# create go.mod if it is missing
if [ ! -f go.mod ]; then
    go mod init runr-function
fi

if [ "$RUNR_MODE" = "schedule" ]; then
    cp /app/wrapper_schedule.go ./runr_wrapper_.go
else
    cp /app/wrapper_http.go ./runr_wrapper_.go
fi

# Build and run
go build -o /tmp/runr-handler .
exec /tmp/runr-handler
