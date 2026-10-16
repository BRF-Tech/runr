#!/usr/bin/env bash
# Tests `ci.sh ml-runtimes` without Docker: docker is a shell function that records every call and
# answers like a working (or, for one case, broken) image. Checks the image list and its tags (the
# framework pins' major.minor, CPU and -gpu), the build flags (--pull, linux/amd64, the Python
# runtime's handler server as build context, VERSION), the smoke test of a local build — on a host
# without a GPU and on one where `docker run --gpus all` gives the -gpu images a GPU — and the push.
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
gpu_host=0       # docker run --gpus all gives containers a GPU
framework_gpu=1  # the framework in a container with the GPU uses it (0: it runs on the CPU)
schedule_gpu=1   # …in the schedule run too (0: only over http)

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
    "run --rm")
      # the GPU probe: nvidia-smi -L in the image with --gpus all
      if [ "$gpu_host" = 1 ]; then echo "GPU 0: NVIDIA GeForce RTX 4080 (UUID: GPU-0)"; return 0; fi
      echo 'docker: Error response from daemon: could not select device driver "" with capabilities: [[gpu]]' >&2
      return 125 ;;
    "exec -i")
      cat > /dev/null
      local name="$3" runtime cuda=null version gpus=0 device=cpu
      case "$name" in *pytorch*) runtime=pytorch version="$torch" ;; *) runtime=tensorflow version="$tf" device="/job:localhost/replica:0/task:0/device:CPU:0" ;; esac
      case "$name" in *-gpu-*) cuda='"12.6"' ;; esac
      if grep -q "^docker create --name ${name} --gpus all " "$calls" && [ "$framework_gpu" = 1 ]; then
        gpus=1
        if [ "$runtime" = pytorch ]; then device="cuda:0"; else device="/job:localhost/replica:0/task:0/device:GPU:0"; fi
      fi
      echo "${smoke_status} {\"runtime\": \"${runtime}\", \"version\": \"${version}.1\", \"cuda\": ${cuda}, \"gpus\": ${gpus}, \"device\": \"${device}\", \"mean\": 2.5, \"matmul\": 54.0, \"trigger\": \"http\"}" ;;
    "start -a")
      echo "W0000 a framework log line"
      local sgpus=0 sdevice=cpu
      if grep -q "^docker create --name ${3} --gpus all " "$calls" && [ "$framework_gpu" = 1 ] && [ "$schedule_gpu" = 1 ]; then
        sgpus=1
        case "$3" in *pytorch*) sdevice="cuda:0" ;; *) sdevice="/job:localhost/replica:0/task:0/device:GPU:0" ;; esac
      fi
      echo "{\"statusCode\": 200, \"body\": {\"gpus\": ${sgpus}, \"device\": \"${sdevice}\", \"mean\": 2.5, \"matmul\": 54.0, \"trigger\": \"schedule\"}}" ;;
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
# no GPU on this host: the -gpu image is probed once, smoke-tested without one, and says so
[ "$(grep -c "^docker run --rm --gpus all --entrypoint nvidia-smi ghcr.io/brf-tech/runr/exec-pytorch:${torch}-gpu -L" "$calls")" = 1 ] \
  || fail "GPU probe: $(grep '^docker run' "$calls" || true)"
grep -q -- '--gpus all -e' "$calls" && fail "a smoke container got --gpus without a GPU"
[ "$(echo "$out" | grep -c 'GPU: *skipped (docker run --gpus all gives no GPU on this host)')" = 1 ] || fail "GPU skip not reported once: $out"

# --- smoke test on the host's GPU --------------------------------------------------------------
: > "$calls"
gpu_host=1
out=$(cmd_build_ml_runtimes tensorflow 2>&1) || fail "smoke test on a GPU host failed: $out"
grep -q "^docker create --name runr-ml-smoke-tensorflow-gpu-[0-9]* --gpus all -e TF_CPP_MIN_LOG_LEVEL=2 -e TF_FORCE_GPU_ALLOW_GROWTH=true ghcr.io/brf-tech/runr/exec-tensorflow:${tf}-gpu" "$calls" \
  || fail "-gpu smoke container without the GPU: $(grep '^docker create' "$calls")"
grep -q "^docker create --name runr-ml-smoke-tensorflow-gpu-[0-9]*-schedule --gpus all -e RUNR_MODE=schedule -e TF_CPP_MIN_LOG_LEVEL=2 -e TF_FORCE_GPU_ALLOW_GROWTH=true ghcr.io/brf-tech/runr/exec-tensorflow:${tf}-gpu" "$calls" \
  || fail "-gpu schedule run without the GPU: $(grep '^docker create' "$calls")"
grep -q '^docker create --name runr-ml-smoke-tensorflow-cpu-[0-9]* --gpus' "$calls" && fail "the CPU image got the GPU"
grep -q '^docker create --name runr-ml-smoke-tensorflow-cpu-[0-9]*-schedule --gpus' "$calls" && fail "the CPU image's schedule run got the GPU"
grep -q '^docker run --rm --gpus all --entrypoint nvidia-smi .*exec-tensorflow:[0-9.]*  *-L' "$calls" && fail "the CPU image was probed"
[ "$(echo "$out" | grep -c "the framework sees the host's GPU and ran the ops on it (http and schedule)")" = 1 ] || fail "GPU result: $out"
echo "$out" | grep -q 'skipped' && fail "GPU check reported as skipped on a GPU host: $out"

# the framework does not use the GPU it was given (TensorFlow without a CUDA library): failure
: > "$calls"
framework_gpu=0
out=$( (cmd_build_ml_runtimes tensorflow gpu) 2>&1) && fail "a -gpu image running on the CPU passed: $out"
echo "$out" | grep -q 'docker run --gpus all gives a GPU, but the framework does not run on it (http, schedule)' || fail "GPU failure: $out"
echo "$out" | grep -q "smoke test of ghcr.io/brf-tech/runr/exec-tensorflow:${tf}-gpu failed" || fail "GPU failure result: $out"
framework_gpu=1

# …or uses it over http only: the schedule run fails the smoke test
: > "$calls"
schedule_gpu=0
out=$( (cmd_build_ml_runtimes pytorch gpu) 2>&1) && fail "a schedule run on the CPU passed: $out"
echo "$out" | grep -q 'docker run --gpus all gives a GPU, but the framework does not run on it (schedule)' || fail "schedule GPU failure: $out"
schedule_gpu=1

# ML_SMOKE_GPU=0: never on the GPU; ML_SMOKE_GPU=1: a GPU is required
: > "$calls"
out=$(ML_SMOKE_GPU=0 cmd_build_ml_runtimes pytorch gpu 2>&1) || fail "ML_SMOKE_GPU=0: $out"
grep -q '^docker run --rm --gpus' "$calls" && fail "ML_SMOKE_GPU=0 probed the GPU"
grep -q -- '--gpus all -e' "$calls" && fail "ML_SMOKE_GPU=0 gave the container the GPU"
echo "$out" | grep -q 'GPU: *skipped (ML_SMOKE_GPU=0)' || fail "ML_SMOKE_GPU=0 not reported: $out"
gpu_host=0
: > "$calls"
out=$( (ML_SMOKE_GPU=1 cmd_build_ml_runtimes pytorch gpu) 2>&1) && fail "ML_SMOKE_GPU=1 passed without a GPU: $out"
echo "$out" | grep -q 'ML_SMOKE_GPU=1, but docker run --gpus all gives .*exec-pytorch:.*-gpu no GPU' || fail "ML_SMOKE_GPU=1: $out"
grep -q '^docker create' "$calls" && fail "ML_SMOKE_GPU=1 without a GPU still started containers"
out=$(ML_SMOKE_GPU=1 cmd_build_ml_runtimes pytorch cpu 2>&1) || fail "ML_SMOKE_GPU=1 applies to -gpu images only: $out"

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
