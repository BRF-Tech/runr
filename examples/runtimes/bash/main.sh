#!/usr/bin/env bash
# RunR Bash example: exec mode, raw input. RunR runs this script once per invocation:
# the request body arrives on stdin, metadata in RUNR_* variables, stdout is the response.
set -euo pipefail

urldecode() { local s="${1//+/ }"; printf '%b' "${s//%/\\x}"; }

case "${RUNR_TRIGGER:-http}" in
  schedule)
    echo "schedule trigger fired" >&2
    echo "tick from bash"
    ;;
  queue)
    body="$(cat)"
    echo "queue message received" >&2
    printf 'processed %s (from bash)' "$body"
    ;;
  *)
    name=""
    IFS='&' read -ra pairs <<< "${RUNR_QUERY:-}"
    for pair in "${pairs[@]}"; do
      if [[ "$pair" == name=* ]]; then name="$(urldecode "${pair#name=}")"; fi
    done
    printf 'Hello, %s! (from bash)' "${name:-World}"
    ;;
esac
