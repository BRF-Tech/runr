#!/usr/bin/env bash
# Tests the action's scripts without network or a RunR API: a fake release (archive with a
# stub runr + checksums.txt) is served from file://, and the stub records its arguments and
# writes the outputs the real CLI writes with --output-env.
#
#   bash actions/deploy/test/run.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

os=linux
case "$(uname -s)" in Darwin) os=darwin ;; MINGW* | MSYS* | CYGWIN*) os=windows ;; esac
arch=amd64
case "$(uname -m)" in arm64 | aarch64) arch=arm64 ;; esac

# --- fake release -------------------------------------------------------------------------
rel="$tmp/release/v9.9.9"
mkdir -p "$rel" "$tmp/stub"
cat > "$tmp/stub/runr" <<'STUB'
#!/usr/bin/env bash
if [ "$1" = "version" ]; then echo "runr v9.9.9"; exit 0; fi
if [ "$1" = "env" ]; then
  # runr env set: record the arguments and the value read from stdin.
  echo "args=$*" >> "$RUNR_STUB_ENV"
  echo "stdin=$(cat)" >> "$RUNR_STUB_ENV"
  exit "${RUNR_STUB_ENV_EXIT:-0}"
fi
printf '%s\n' "$@" > "$RUNR_STUB_ARGS"
out=""
while [ $# -gt 0 ]; do
  [ "$1" = "--output-env" ] && out="$2"
  shift
done
printf 'project=hello\nproject_id=p1\nversion=v3\nversion_id=v-3\ndeployment_id=d1\nstatus=active\nurl=https://hello.eu-1.example.com\n' >> "$out"
STUB
chmod +x "$tmp/stub/runr"
archive="runr_${os}_${arch}.tar.gz"
[ "$os" = windows ] && archive="runr_${os}_${arch}.zip"
if [ "$os" = windows ]; then
  (cd "$tmp/stub" && zip -q "$rel/$archive" runr)
else
  tar -czf "$rel/$archive" -C "$tmp/stub" runr
fi
if command -v sha256sum >/dev/null 2>&1; then
  (cd "$rel" && sha256sum "$archive" > checksums.txt)
else
  (cd "$rel" && shasum -a 256 "$archive" > checksums.txt)
fi

# --- resolve.sh ---------------------------------------------------------------------------
export GITHUB_OUTPUT="$tmp/resolve.out" RUNNER_TOOL_CACHE="$tmp/toolcache"
RUNR_CLI_VERSION=9.9.9 bash "$here/scripts/resolve.sh" >/dev/null
grep -qx "tag=v9.9.9" "$GITHUB_OUTPUT" || fail "resolve: tag"
grep -qx "os=$os" "$GITHUB_OUTPUT" || fail "resolve: os"
dir="$(sed -n 's/^dir=//p' "$GITHUB_OUTPUT")"
[ "$dir" = "$tmp/toolcache/runr-cli/v9.9.9/$os-$arch" ] || fail "resolve: dir $dir"

# --- install.sh ---------------------------------------------------------------------------
RUNR_CLI_TAG=v9.9.9 RUNR_CLI_OS=$os RUNR_CLI_ARCH=$arch RUNR_CLI_DIR="$dir" \
  RUNR_CLI_BASE_URL="file://$tmp/release" bash "$here/scripts/install.sh" >/dev/null
[ -x "$dir/runr" ] || fail "install: binary missing"

# A tampered archive is refused.
echo "0000000000000000000000000000000000000000000000000000000000000000  $archive" > "$rel/checksums.txt"
if RUNR_CLI_TAG=v9.9.9 RUNR_CLI_OS=$os RUNR_CLI_ARCH=$arch RUNR_CLI_DIR="$tmp/other" \
  RUNR_CLI_BASE_URL="file://$tmp/release" bash "$here/scripts/install.sh" >/dev/null 2>&1; then
  fail "install: checksum mismatch accepted"
fi

# --- deploy.sh ----------------------------------------------------------------------------
export GITHUB_OUTPUT="$tmp/deploy.out" GITHUB_STEP_SUMMARY="$tmp/summary.md" RUNR_STUB_ARGS="$tmp/args"
: > "$GITHUB_OUTPUT"
RUNR_CLI_DIR="$dir" RUNR_API_URL=https://runr.example.com RUNR_TOKEN=tok INPUT_PATH=./svc INPUT_PROJECT=hello \
  INPUT_IMAGE=ghcr.io/acme/web:1 INPUT_WAIT=false INPUT_TIMEOUT=5m INPUT_ARGS="--region eu-1" \
  GITHUB_WORKFLOW=ci GITHUB_SHA=0123456789abcdef bash "$here/scripts/deploy.sh" >/dev/null

args="$(tr '\n' ' ' < "$RUNR_STUB_ARGS")"
for want in "deploy ./svc" "--output-env $GITHUB_OUTPUT" "--timeout 5m" "--project hello" \
  "--image ghcr.io/acme/web:1" "--no-wait" "--message ci 0123456" "--region eu-1"; do
  case "$args" in *"$want"*) ;; *) fail "deploy: args lack '$want': $args" ;; esac
done
grep -qx "url=https://hello.eu-1.example.com" "$GITHUB_OUTPUT" || fail "deploy: url output"
grep -q "| hello | v3 | https://hello.eu-1.example.com |" "$GITHUB_STEP_SUMMARY" || fail "deploy: summary"

if RUNR_CLI_DIR="$dir" RUNR_API_URL= RUNR_TOKEN= bash "$here/scripts/deploy.sh" >/dev/null 2>&1; then
  fail "deploy: ran without api-url and token"
fi

# --- secrets.sh ---------------------------------------------------------------------------
export RUNR_STUB_ENV="$tmp/env.log" GITHUB_OUTPUT="$tmp/secrets.out"
secrets_input=$'# database\nDATABASE_URL=postgres://u:p@db/x?a=b\n\n  API_KEY = s3cr3t\r\n'
run_secrets() {
  RUNR_CLI_DIR="$dir" RUNR_API_URL=https://runr.example.com RUNR_TOKEN=tok INPUT_PROJECT=hello \
    INPUT_PATH=./svc INPUT_SECRETS="$secrets_input" bash "$here/scripts/secrets.sh"
}

: > "$RUNR_STUB_ENV"; : > "$GITHUB_OUTPUT"
log="$(run_secrets)" || fail "secrets: exit $?"
grep -qx "args=env set --secret --no-deploy --quiet --project hello --dir ./svc DATABASE_URL" "$RUNR_STUB_ENV" ||
  fail "secrets: args $(cat "$RUNR_STUB_ENV")"
grep -qx "stdin=postgres://u:p@db/x?a=b" "$RUNR_STUB_ENV" || fail "secrets: value with = and ?"
grep -qx "args=env set --secret --no-deploy --quiet --project hello --dir ./svc API_KEY" "$RUNR_STUB_ENV" ||
  fail "secrets: key with spaces around ="
grep -qx "stdin=s3cr3t" "$RUNR_STUB_ENV" || fail "secrets: value of API_KEY (spaces and CR removed)"
grep -qx "::add-mask::postgres://u:p@db/x?a=b" <<< "$log" || fail "secrets: value not masked"
[ "$(grep -c "s3cr3t" <<< "$log")" = 1 ] || fail "secrets: a value was printed outside its mask line"
grep -qx "pending=false" "$GITHUB_OUTPUT" || fail "secrets: pending output"

# The first deploy creates the project: exit 4 of runr env set means "set them afterwards".
: > "$RUNR_STUB_ENV"; : > "$GITHUB_OUTPUT"
RUNR_STUB_ENV_EXIT=4 run_secrets >/dev/null || fail "secrets: a new project failed the step"
grep -qx "pending=true" "$GITHUB_OUTPUT" || fail "secrets: pending=true for a new project"
[ "$(grep -c '^args=' "$RUNR_STUB_ENV")" = 1 ] || fail "secrets: kept going after the project was not found"
if RUNR_STUB_ENV_EXIT=4 RUNR_SECRETS_MODE=after run_secrets >/dev/null 2>&1; then
  fail "secrets: after the deploy a project that is not found must fail"
fi
if RUNR_STUB_ENV_EXIT=3 run_secrets >/dev/null 2>&1; then
  fail "secrets: a refused token passed"
fi

# Nothing to do, and invalid input.
: > "$RUNR_STUB_ENV"
RUNR_CLI_DIR="$dir" INPUT_SECRETS= bash "$here/scripts/secrets.sh" >/dev/null || fail "secrets: empty input"
[ ! -s "$RUNR_STUB_ENV" ] || fail "secrets: called runr without secrets"
for bad in "NOEQUALS" "1BAD=x" "EMPTY="; do
  if RUNR_CLI_DIR="$dir" RUNR_API_URL=https://runr.example.com RUNR_TOKEN=tok INPUT_SECRETS="$bad" \
    bash "$here/scripts/secrets.sh" >/dev/null 2>&1; then
    fail "secrets: accepted '$bad'"
  fi
done

echo "ok - actions/deploy scripts"
