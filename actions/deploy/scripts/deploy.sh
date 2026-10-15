#!/usr/bin/env bash
# Runs `runr deploy` with the action's inputs; the CLI appends url=, version=, deployment_id=,
# … to $GITHUB_OUTPUT (--output-env).
set -euo pipefail

runr="runr"
if [ -n "${RUNR_CLI_DIR:-}" ] && { [ -x "$RUNR_CLI_DIR/runr" ] || [ -x "$RUNR_CLI_DIR/runr.exe" ]; }; then
  runr="$RUNR_CLI_DIR/runr"
fi

if [ -z "${RUNR_API_URL:-}" ] || [ -z "${RUNR_TOKEN:-}" ]; then
  echo "::error::api-url and token are required"
  exit 2
fi
echo "::add-mask::${RUNR_TOKEN}"

args=(deploy "${INPUT_PATH:-.}" --output-env "$GITHUB_OUTPUT" --timeout "${INPUT_TIMEOUT:-10m}")
[ -n "${INPUT_PROJECT:-}" ] && args+=(--project "$INPUT_PROJECT")
[ -n "${INPUT_IMAGE:-}" ] && args+=(--image "$INPUT_IMAGE")
[ -n "${INPUT_REGION:-}" ] && args+=(--region "$INPUT_REGION")
case "${INPUT_WAIT:-true}" in
  false | False | FALSE | no | 0) args+=(--no-wait) ;;
esac

message="${INPUT_MESSAGE:-}"
if [ -z "$message" ]; then
  message="${GITHUB_WORKFLOW:-github-actions} ${GITHUB_SHA:0:7}"
fi
args+=(--message "$message")

if [ -n "${INPUT_ARGS:-}" ]; then
  # shellcheck disable=SC2206 # extra arguments are split on whitespace on purpose
  extra=(${INPUT_ARGS})
  args+=("${extra[@]}")
fi

echo "runr ${args[*]}"
"$runr" "${args[@]}"

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  url="$(sed -n 's/^url=//p' "$GITHUB_OUTPUT" | tail -n1)"
  version="$(sed -n 's/^version=//p' "$GITHUB_OUTPUT" | tail -n1)"
  project="$(sed -n 's/^project=//p' "$GITHUB_OUTPUT" | tail -n1)"
  {
    echo "### RunR deploy"
    echo ""
    echo "| Project | Version | URL |"
    echo "|---|---|---|"
    echo "| ${project} | ${version} | ${url:--} |"
  } >> "$GITHUB_STEP_SUMMARY"
fi
