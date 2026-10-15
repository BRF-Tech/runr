#!/usr/bin/env bash
# Pushes the action's `secrets` input (KEY=VALUE lines) to the project as secret environment
# variables with `runr env set --secret --no-deploy`, before `runr deploy` deploys them. Each
# value is masked in the log and passed on stdin, never as an argument. Blank lines and #
# comments are skipped; spaces around the key and the value are removed.
#
# RUNR_SECRETS_MODE=before (default): a project that does not exist yet (the first deploy
# creates it) is not an error: the step writes pending=true to $GITHUB_OUTPUT and the action
# sets the secrets after the deploy, then deploys again. RUNR_SECRETS_MODE=after: every secret
# must be set.
set -euo pipefail

runr="runr"
if [ -n "${RUNR_CLI_DIR:-}" ] && { [ -x "$RUNR_CLI_DIR/runr" ] || [ -x "$RUNR_CLI_DIR/runr.exe" ]; }; then
  runr="$RUNR_CLI_DIR/runr"
fi
mode="${RUNR_SECRETS_MODE:-before}"

if [ -z "${INPUT_SECRETS:-}" ]; then
  exit 0
fi
if [ -z "${RUNR_API_URL:-}" ] || [ -z "${RUNR_TOKEN:-}" ]; then
  echo "::error::api-url and token are required"
  exit 2
fi
echo "::add-mask::${RUNR_TOKEN}"

# Parse and mask every value first, so nothing is printed before all values are masked.
keys=()
values=()
n=0
while IFS= read -r line || [ -n "$line" ]; do
  n=$((n + 1))
  line="${line%$'\r'}"
  trimmed="${line#"${line%%[![:space:]]*}"}"
  case "$trimmed" in "" | "#"*) continue ;; esac
  if [[ "$trimmed" != *=* ]]; then
    echo "::error::secrets line $n is not KEY=VALUE"
    exit 2
  fi
  key="${trimmed%%=*}"
  key="${key%"${key##*[![:space:]]}"}"
  value="${trimmed#*=}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"
  if ! [[ "$key" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]]; then
    echo "::error::secrets line $n: invalid variable name"
    exit 2
  fi
  if [ -z "$value" ]; then
    echo "::error::secrets line $n: $key has an empty value"
    exit 2
  fi
  echo "::add-mask::${value}"
  keys+=("$key")
  values+=("$value")
done <<< "${INPUT_SECRETS}"

if [ "${#keys[@]}" -eq 0 ]; then
  exit 0
fi

common=(env set --secret --no-deploy --quiet)
[ -n "${INPUT_PROJECT:-}" ] && common+=(--project "$INPUT_PROJECT")
common+=(--dir "${INPUT_PATH:-.}")

for i in "${!keys[@]}"; do
  key="${keys[$i]}"
  set +e
  printf '%s' "${values[$i]}" | "$runr" "${common[@]}" "$key"
  code=$?
  set -e
  if [ "$code" -eq 4 ] && [ "$mode" = "before" ] && [ "$i" -eq 0 ]; then
    # Exit code 4: the project does not exist yet; set the secrets after the first deploy.
    echo "The project does not exist yet; its secret variables are set after the first deploy."
    [ -n "${GITHUB_OUTPUT:-}" ] && echo "pending=true" >> "$GITHUB_OUTPUT"
    exit 0
  fi
  if [ "$code" -ne 0 ]; then
    echo "::error::setting the secret variable $key failed (runr exit code $code)"
    exit "$code"
  fi
  echo "Secret variable $key set."
done
[ -n "${GITHUB_OUTPUT:-}" ] && echo "pending=false" >> "$GITHUB_OUTPUT"
exit 0
