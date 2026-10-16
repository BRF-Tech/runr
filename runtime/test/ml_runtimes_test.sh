#!/usr/bin/env bash
# Tests `ci.sh ml-runtimes` without Docker: docker is a shell function that records every call and
# answers like a working (or, for one case, broken) image. Checks the image list and its tags (the
# framework pins' major.minor, CPU and -gpu), the build flags (--pull, linux/amd64, the Python
# runtime's handler server as build context, VERSION), the smoke test of a local build and the push.
#
#   bash runtime/test/ml_runtimes_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -f -- "$tmp"/calls "$tmp"/out "$tmp"/pins/*/*; rmdir -- "$tmp"/pins/* "$tmp"/pins "$tmp" 2>/dev/null || true' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

SCRIPT_DIR="$here"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/build.sh
source "$here/lib/build.sh"
# shellcheck source=../lib/build-ml.sh
source "$here/lib/build-ml.sh"

REGISTRY="ghcr.io/brf-tech/runr"
calls="$tmp/calls"
: > "$calls"
smoke_status=200

# the pins of the checkout: "torch==2.14.1" → 2.14
pin() { sed -n -E "s/^$2==([0-9]+\.[0-9]+)\..*/\1/p" "$here/$1"; }
torch="$(pin pytorch/requirements-torch.txt torch)"
tf="$(pin tensorflow/requirements-cpu.txt tensorflow-cpu)"
[ -n "$torch" ] && [ -n "$tf" ] || fail "no framework pins in the checkout"
grep -q "^tensorflow\[and-cuda\]==${tf}\." "$here/tensorflow/requirements-cuda.txt" \
  || fail "requirements-cuda.txt pins another TensorFlow than requirements-cpu.txt"

docker() {
  echo "docker $*" >> "$calls"
  case "$1 ${2:-}" in
    "image inspect") echo 2345000000 ;;
    "buildx inspect") return 0 ;;
    "exec -i")
      cat > /dev/null
      local name="$3" runtime cuda=null version
      case "$name" in *pytorch*) runtime=pytorch version="$torch" ;; *) runtime=tensorflow version="$tf" ;; esac
      case "$name" in *-gpu-*) cuda='"12.6"' ;; esac
      echo "${smoke_status} {\"runtime\": \"${runtime}\", \"version\": \"${version}.1\", \"cuda\": ${cuda}, \"mean\": 2.5, \"trigger\": \"http\"}" ;;
    "start -a")
      echo "W0000 a framework log line"
      echo '{"statusCode": 200, "body": {"mean": 2.5, "trigger": "schedule"}}' ;;
    "cp -") cat > /dev/null ;;
  esac
  return 0
}

# --- the image list --------------------------------------------------------------------------
want="ghcr.io/brf-tech/runr/exec-pytorch:${torch}
ghcr.io/brf-tech/runr/exec-pytorch:${torch}-gpu
ghcr.io/brf-tech/runr/exec-tensorflow:${tf}
ghcr.io/brf-tech/runr/exec-tensorflow:${tf}-gpu"
[ "$(cmd_build_ml_runtimes --list)" = "$want" ] || fail "list: $(cmd_build_ml_runtimes --list)"
[ "$(cmd_build_ml_runtimes --list tensorflow gpu)" = "ghcr.io/brf-tech/runr/exec-tensorflow:${tf}-gpu" ] || fail "filtered list"
[ "$(cmd_build_ml_runtimes --list cpu)" = "ghcr.io/brf-tech/runr/exec-pytorch:${torch}
ghcr.io/brf-tech/runr/exec-tensorflow:${tf}" ] || fail "cpu list"
[ -s "$calls" ] && fail "--list ran docker: $(cat "$calls")"
out=$( (cmd_build_ml_runtimes --list pytorch cuda) 2>&1) && fail "unknown argument accepted"
echo "$out" | grep -q 'unknown argument: cuda' || fail "unknown argument: $out"

# a pin with extras and a missing pin
mkdir -p "$tmp/pins/pytorch" "$tmp/pins/tensorflow"
echo "torch==3.1.0  " > "$tmp/pins/pytorch/requirements-torch.txt"
printf '# none\nnumpy==2.0.0\n' > "$tmp/pins/tensorflow/requirements-cpu.txt"
[ "$(SCRIPT_DIR="$tmp/pins" ml_runtime_version pytorch)" = "3.1" ] || fail "pin with trailing blanks"
[ -z "$(SCRIPT_DIR="$tmp/pins" ml_runtime_version tensorflow)" ] || fail "a pin of another package was read"
out=$( (SCRIPT_DIR="$tmp/pins" cmd_build_ml_runtimes --list) 2>&1) && fail "a missing pin passed"
echo "$out" | grep -q 'no tensorflow-cpu==X.Y.Z pin' || fail "missing pin: $out"

# --- local build + smoke test ------------------------------------------------------------------
out=$(cmd_build_ml_runtimes pytorch 2>&1) || fail "local build failed: $out"
build=$(grep '^docker build ' "$calls" | head -1)
for flag in "--pull" "--platform linux/amd64" "--load" "-f ${here}/pytorch/Dockerfile" \
  "--build-context runtime-python=${here}/python" "--build-arg VERSION=${torch}" \
  "-t ghcr.io/brf-tech/runr/exec-pytorch:${torch} ${here}/pytorch"; do
  [[ "$build" == *"$flag"* ]] || fail "local build without '${flag}': $build"
done
grep '^docker build ' "$calls" | sed -n 2p | grep -q -- "-f ${here}/pytorch/Dockerfile.gpu .*-t ghcr.io/brf-tech/runr/exec-pytorch:${torch}-gpu " \
  || fail "gpu build: $(grep '^docker build ' "$calls")"
grep -q "^docker build .*VERSION=${torch} .*exec-pytorch:${torch}-gpu" "$calls" || fail "gpu build without VERSION=${torch}"
grep -q 'tensorflow' "$calls" && fail "a filtered-out runtime was built"
[ "$(grep -c '^docker exec -i runr-ml-smoke-pytorch-' "$calls")" = 2 ] || fail "not every image was smoke-tested"
grep -q '^docker create --name runr-ml-smoke-pytorch-cpu-[0-9]*-schedule -e RUNR_MODE=schedule' "$calls" || fail "no schedule run"
grep -q '^docker cp - runr-ml-smoke-pytorch-cpu-[0-9]*:/app/code' "$calls" || fail "handler not copied to /app/code"
grep -q '^docker rm -f runr-ml-smoke-pytorch-gpu-' "$calls" || fail "smoke containers not removed"
grep -q -- '--push\|push=true' "$calls" && fail "a local build pushed"
[ "$(echo "$out" | grep -c '✓ smoke test')" = 2 ] || fail "smoke results: $out"
echo "$out" | grep -q 'size (docker image inspect): 2345 MB' || fail "image size not printed: $out"

# a broken image fails the command
: > "$calls"
smoke_status=500
out=$( (cmd_build_ml_runtimes tensorflow cpu) 2>&1) && fail "a failing smoke test passed: $out"
echo "$out" | grep -q "smoke test of ghcr.io/brf-tech/runr/exec-tensorflow:${tf} failed" || fail "smoke failure: $out"
grep -q '^docker rm -f runr-ml-smoke-tensorflow-cpu-' "$calls" || fail "containers left behind after a failure"
smoke_status=200

# --no-smoke
: > "$calls"
cmd_build_ml_runtimes tensorflow --no-smoke > /dev/null 2>&1 || fail "--no-smoke build failed"
grep -q '^docker \(create\|exec\)' "$calls" && fail "--no-smoke ran the smoke test"

# --- push ------------------------------------------------------------------------------------
: > "$calls"
out=$(cmd_build_ml_runtimes --push 2>&1) || fail "push failed: $out"
[ "$(grep -c '^docker buildx build ' "$calls")" = 4 ] || fail "push builds: $(cat "$calls")"
while IFS= read -r line; do
  for flag in "--platform linux/amd64 " "--pull" "--build-context runtime-python=${here}/python" "push=true"; do
    [[ "$line" == *"$flag"* ]] || fail "push build without '${flag}': $line"
  done
  [[ "$line" == *"--load"* ]] && fail "push build loads: $line"
  [[ "$line" == *"arm64"* ]] && fail "ML images are amd64 only: $line"
done < <(grep '^docker buildx build ' "$calls")
grep -q "^docker buildx build .*-f ${here}/tensorflow/Dockerfile.gpu .*-t ghcr.io/brf-tech/runr/exec-tensorflow:${tf}-gpu " "$calls" || fail "tensorflow gpu push"
grep -q '^docker \(create\|exec\)' "$calls" && fail "push ran a smoke test"
grep -q '^docker buildx rm runr-multiarch' "$calls" || fail "builder not removed after the push"

echo "ok: ml-runtimes"
