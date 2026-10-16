# cmd_build_ml_runtimes — images of the built-in ML runtimes (runtime/pytorch, runtime/tensorflow):
# ${REGISTRY}/exec-<runtime>:<X.Y> (CPU, Dockerfile) and :<X.Y>-gpu (CUDA, Dockerfile.gpu). X.Y
# is the major.minor of the framework pin (ML_RUNTIMES); these tags are the versions executor:sync
# discovers for the two runtimes (docs/runtimes.md#ml-runtimes). Built for linux/amd64 only
# (ML_PLATFORM), every build pulls its base image (--pull) and takes the handler server of the
# Python runtime (runtime/python) as the build context "runtime-python".
#
#   ci.sh ml-runtimes                  local builds of the four images, each one smoke-tested
#   ci.sh ml-runtimes pytorch gpu      only these (runtime: pytorch, tensorflow; variant: cpu, gpu)
#   ci.sh ml-runtimes --no-smoke       local builds without the smoke test
#   ci.sh ml-runtimes --push           build + push (the images a release publishes)
#   ci.sh ml-runtimes --list           print the image references, build nothing
#
# The smoke test runs a handler through the image's own server, like a function: a tensor op on
# the request's values, answered as JSON (http trigger), and the same handler once with
# RUNR_MODE=schedule (runner.py). No port is published; the request comes from inside the container.

ML_PLATFORM="${ML_PLATFORM:-linux/amd64}"

# runtime|framework pin file|package — the image tag is the pin's major.minor
ML_RUNTIMES=(
  "pytorch|requirements-torch.txt|torch"
  "tensorflow|requirements-cpu.txt|tensorflow-cpu"
)

# ml_runtime_version <runtime> — major.minor of the framework pin ("torch==2.14.1" → 2.14)
ml_runtime_version() {
  local def runtime file pkg
  for def in "${ML_RUNTIMES[@]}"; do
    IFS='|' read -r runtime file pkg <<< "$def"
    [ "$runtime" = "$1" ] || continue
    sed -n -E "s/^${pkg}(\[[^]]*\])?==([0-9]+)\.([0-9]+)(\.[0-9]+)*[[:space:]]*$/\2.\3/p" \
      "${SCRIPT_DIR}/${runtime}/${file}" | head -1
    return 0
  done
  return 1
}

# ml_runtime_images [runtime...] [variant...] — "runtime|variant|Dockerfile|tag", one per image
ml_runtime_images() {
  local runtimes=() variants=() arg def runtime file pkg variant version
  for arg in "$@"; do
    case "$arg" in
      cpu|gpu) variants+=("$arg") ;;
      *) runtimes+=("$arg") ;;
    esac
  done
  [ "${#variants[@]}" -eq 0 ] && variants=(cpu gpu)

  for def in "${ML_RUNTIMES[@]}"; do
    IFS='|' read -r runtime file pkg <<< "$def"
    if [ "${#runtimes[@]}" -gt 0 ] && [[ " ${runtimes[*]} " != *" ${runtime} "* ]]; then
      continue
    fi
    version="$(ml_runtime_version "$runtime")"
    if [ -z "$version" ]; then
      echo -e "${RED}${runtime}: no ${pkg}==X.Y.Z pin in runtime/${runtime}/${file}${NC}" >&2
      return 1
    fi
    for variant in "${variants[@]}"; do
      if [ "$variant" = cpu ]; then
        echo "${runtime}|cpu|Dockerfile|${version}"
      else
        echo "${runtime}|gpu|Dockerfile.gpu|${version}-gpu"
      fi
    done
  done
}

# ml_runtime_smoke <image> <runtime> <variant> <X.Y> — the image answers a handler like a function
ml_runtime_smoke() {
  local image="$1" runtime="$2" variant="$3" version="$4"
  local tmp name rc=0 http="" schedule="" cuda
  tmp="$(mktemp -d)"
  name="runr-ml-smoke-${runtime}-${variant}-$$"

  case "$runtime" in
    pytorch)
      cat > "${tmp}/main.py" <<'PY'
import torch


def handler(event, context):
    x = torch.tensor(event.get("values", [[1.0, 2.0], [3.0, 4.0]]))
    return {"statusCode": 200, "body": {
        "runtime": "pytorch", "version": torch.__version__, "cuda": torch.version.cuda,
        "mean": x.mean().item(), "trigger": event.get("trigger", "http"),
    }}
PY
      ;;
    tensorflow)
      cat > "${tmp}/main.py" <<'PY'
import tensorflow as tf


def handler(event, context):
    x = tf.constant(event.get("values", [[1.0, 2.0], [3.0, 4.0]]))
    return {"statusCode": 200, "body": {
        "runtime": "tensorflow", "version": tf.__version__,
        "cuda": tf.sysconfig.get_build_info().get("cuda_version"),
        "mean": float(tf.reduce_mean(x).numpy()), "trigger": event.get("trigger", "http"),
    }}
PY
      ;;
  esac

  # The request, sent from inside the container once the server listens (it imports the handler,
  # and with it the framework, before it does)
  cat > "${tmp}/request.py" <<'PY'
import json, time, urllib.error, urllib.request

body = json.dumps({"values": [[1.0, 2.0], [3.0, 4.0]]}).encode()
deadline = time.time() + 180
while True:
    request = urllib.request.Request("http://127.0.0.1:8080/smoke", data=body,
                                     headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response:
            print(response.status, response.read().decode())
        break
    except urllib.error.HTTPError as e:
        print(e.code, e.read().decode())
        break
    except OSError as e:
        if time.time() > deadline:
            raise SystemExit(f"no answer on :8080: {e}")
        time.sleep(1)
PY

  # http trigger: the image's server (entrypoint.sh → server.py) with the handler in /app/code
  if docker create --name "$name" -e TF_CPP_MIN_LOG_LEVEL=2 "$image" >/dev/null \
    && tar -C "$tmp" -cf - main.py | docker cp - "${name}:/app/code" \
    && docker start "$name" >/dev/null; then
    http=$(docker exec -i "$name" python - < "${tmp}/request.py") || rc=1
  else
    rc=1
  fi
  [ "$rc" = 0 ] || docker logs --tail 40 "$name" 2>&1 | sed 's/^/  | /' || true

  # schedule trigger (schedule: native): runner.py calls the handler once and prints the result
  if [ "$rc" = 0 ]; then
    if docker create --name "${name}-schedule" -e RUNR_MODE=schedule -e TF_CPP_MIN_LOG_LEVEL=2 "$image" >/dev/null \
      && tar -C "$tmp" -cf - main.py | docker cp - "${name}-schedule:/app/code"; then
      schedule=$(docker start -a "${name}-schedule" 2>&1) || rc=1
    else
      rc=1
    fi
    [ "$rc" = 0 ] || printf '%s\n' "${schedule:-}" | tail -n 40 | sed 's/^/  | /'
  fi

  docker rm -f "$name" "${name}-schedule" >/dev/null 2>&1 || true
  rm -f -- "${tmp}/main.py" "${tmp}/request.py"
  rmdir -- "$tmp"

  schedule="$(printf '%s\n' "${schedule:-}" | tail -n 1)"
  echo "  http:     ${http:-}"
  echo "  schedule: ${schedule}"
  if [ "$variant" = gpu ]; then cuda='"cuda": "1'; else cuda='"cuda": null'; fi
  local want
  for want in "200 {" "\"runtime\": \"${runtime}\"" "\"version\": \"${version}." "$cuda" '"mean": 2.5' '"trigger": "http"'; do
    [[ "${http:-}" == *"$want"* ]] || rc=1
  done
  for want in '"statusCode": 200' '"mean": 2.5' '"trigger": "schedule"'; do
    [[ "$schedule" == *"$want"* ]] || rc=1
  done
  if [ "$rc" != 0 ]; then
    echo -e "  ${RED}✗ smoke test of ${image} failed${NC}"
    return 1
  fi
  echo -e "  ${GREEN}✓ smoke test${NC}"
}

cmd_build_ml_runtimes() {
  local push=0 list=0 smoke=1 filters=()
  for arg in "$@"; do
    case "$arg" in
      --push) push=1 ;;
      --list) list=1 ;;
      --no-smoke) smoke=0 ;;
      pytorch|tensorflow|cpu|gpu) filters+=("$arg") ;;
      *) echo -e "${RED}unknown argument: ${arg} (pytorch, tensorflow, cpu, gpu, --push, --list, --no-smoke)${NC}"; exit 1 ;;
    esac
  done

  local images
  images="$(ml_runtime_images ${filters[@]+"${filters[@]}"})" || return 1

  if [ "$list" = "1" ]; then
    local runtime variant dockerfile tag
    while IFS='|' read -r runtime variant dockerfile tag; do
      echo "${REGISTRY}/exec-${runtime}:${tag}"
    done <<< "$images"
    return 0
  fi

  echo -e "${BOLD}── ML runtime images (${ML_PLATFORM}) ──${NC}"
  local runtime variant dockerfile tag image version args built=0
  # the list on fd 3: docker (and what wraps it) may read stdin
  while IFS='|' read -r runtime variant dockerfile tag <&3; do
    image="${REGISTRY}/exec-${runtime}:${tag}"
    version="${tag%-gpu}"
    args=(--pull -f "${SCRIPT_DIR}/${runtime}/${dockerfile}"
      --build-context "runtime-python=${SCRIPT_DIR}/python"
      --build-arg "VERSION=${version}")

    echo "==> Building ${image}"
    if [ "$push" = "1" ]; then
      PLATFORMS="$ML_PLATFORM" do_build "$image" "${SCRIPT_DIR}/${runtime}" 1 "${args[@]}" || return 1
    else
      do_build "$image" "${SCRIPT_DIR}/${runtime}" 0 --platform "$ML_PLATFORM" --load "${args[@]}" || return 1
      echo "  size (docker image inspect): $(( $(docker image inspect -f '{{.Size}}' "$image") / 1000000 )) MB"
      if [ "$smoke" = "1" ]; then
        ml_runtime_smoke "$image" "$runtime" "$variant" "$version" || return 1
      fi
    fi
    built=$((built + 1))
    echo ""
  done 3<<< "$images"

  [ "$push" = "1" ] && cleanup_buildx

  echo -e "${GREEN}Done. ${built} images.${NC}"
  [ "$push" = "0" ] && echo "To push: ci.sh ml-runtimes --push"
  return 0
}
