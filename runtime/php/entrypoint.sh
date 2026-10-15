#!/bin/sh
set -e

if [ "$RUNR_MODE" = "schedule" ]; then
    exec php /app/runner.php
else
    exec php /app/server.php
fi
