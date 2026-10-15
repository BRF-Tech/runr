#!/bin/sh
# schedule-watch.sh — restarts schedule:work when a PHP file changes
# Same logic as horizon:listen: poll → detect → restart

WATCH_DIRS="app config routes"
INTERVAL=5
STAMP=$(mktemp)
touch "$STAMP"

cleanup() { kill "$PID" 2>/dev/null; rm -f "$STAMP"; exit 0; }
trap cleanup INT TERM

while true; do
    php artisan schedule:work &
    PID=$!

    while true; do
        sleep "$INTERVAL"
        if [ -n "$(find $WATCH_DIRS -name '*.php' -newer "$STAMP" 2>/dev/null | head -1)" ]; then
            echo "[schedule-watch] PHP change detected, restarting..."
            kill "$PID" 2>/dev/null
            wait "$PID" 2>/dev/null
            touch "$STAMP"
            break
        fi
        kill -0 "$PID" 2>/dev/null || break
    done
done
