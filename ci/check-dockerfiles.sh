#!/bin/bash
set -euo pipefail

# ── Dockerfile check ─────────────────────────────────────────────────────────
# The Dockerfiles of RunR's images (every tracked Dockerfile outside examples/):
#
#  1. No base image from Docker Hub: its anonymous pull limit stops a build host or a CI runner
#     after a few pulls. A third-party base comes from the reqs mirror (ghcr.io/brf-tech/runr/reqs/…,
#     published by BRF-Tech/runr-runtimes, which ci/check-reqs-mirror.sh checks) or from another
#     registry. FROM references are resolved with the defaults of their ARGs.
#  2. A stage whose RUN steps run the Spin CLI sets OTEL_SDK_DISABLED=true (ARG or ENV) before
#     them. A docker-container BuildKit builder (buildx --driver docker-container: `ci.sh … --push`,
#     the CI build jobs) gives every RUN step
#     OTEL_EXPORTER_OTLP_TRACES_ENDPOINT=unix:///dev/otel-grpc.sock, and Spin exits with "Failed to
#     initialize telemetry: invalid URI" before it runs the command.
#
#   bash ci/check-dockerfiles.sh                 the tracked Dockerfiles
#   bash ci/check-dockerfiles.sh FILE...         these files
# Needs bash, git and awk.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# shellcheck source=/dev/null
source "${ROOT}/runtime/lib/common.sh"

# Is an image reference on Docker Hub? (no registry host: library/… or <org>/<name>)
on_docker_hub() {
  local ref="$1" first
  case "$ref" in
    */*) first="${ref%%/*}" ;;
    *) return 0 ;;
  esac
  case "$first" in
    docker.io|index.docker.io|registry-1.docker.io) return 0 ;;
    localhost|*.*|*:*) return 1 ;;
    *) return 0 ;;
  esac
}

# file:line of every RUN step that runs `spin` without OTEL_SDK_DISABLED=true in its stage
spin_without_otel_off() {
  awk '
    function check(s) {
      if (!disabled && s ~ /(^|[[:space:];&|(])spin[[:space:]]/ && !(FILENAME ":" run in seen)) {
        seen[FILENAME ":" run] = 1
        print FILENAME ":" run
      }
    }
    FNR == 1 { disabled = 0; inrun = 0 }
    inrun {
      if ($0 ~ /^[[:space:]]*#/) next
      check($0)
      if ($0 !~ /\\[[:space:]]*$/) inrun = 0
      next
    }
    toupper($1) == "FROM" { disabled = 0; next }
    (toupper($1) == "ARG" || toupper($1) == "ENV") && $0 ~ /OTEL_SDK_DISABLED=["\047]?true/ { disabled = 1; next }
    toupper($1) == "RUN" {
      run = FNR
      check($0)
      if ($0 ~ /\\[[:space:]]*$/) inrun = 1
    }
  ' "$@"
}

if [ "$#" -gt 0 ]; then
  dockerfiles=("$@")
else
  cd "$ROOT"
  mapfile -t dockerfiles < <(git -c safe.directory="$ROOT" ls-files -- '*Dockerfile' '*Dockerfile.*' ':!:examples/*')
fi

failed=0
bases=0
for file in "${dockerfiles[@]}"; do
  while IFS= read -r base; do
    [ -n "$base" ] || continue
    bases=$((bases + 1))
    if on_docker_hub "$base"; then
      echo "✗ ${file}: base image ${base} comes from Docker Hub — mirror it (REQS_IMAGES of lib/mirror.sh in BRF-Tech/runr-runtimes) and use ghcr.io/brf-tech/runr/reqs/…"
      failed=$((failed + 1))
    fi
  done < <(dockerfile_bases "$file")
done

while IFS= read -r where; do
  [ -n "$where" ] || continue
  echo "✗ ${where}: RUN runs spin without OTEL_SDK_DISABLED=true in its stage (ARG OTEL_SDK_DISABLED=true after FROM)"
  failed=$((failed + 1))
done < <(spin_without_otel_off "${dockerfiles[@]}")

if [ "$failed" -gt 0 ]; then
  exit 1
fi

echo "✓ ${#dockerfiles[@]} Dockerfiles: ${bases} base images, none from Docker Hub; every Spin RUN step has OTEL_SDK_DISABLED=true"
