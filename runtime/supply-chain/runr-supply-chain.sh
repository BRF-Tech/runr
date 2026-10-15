#!/bin/sh
# runr-supply-chain — the supply-chain steps RunR runs for one image (docs/supply-chain.md).
#
# Modes (first argument):
#   pipeline   steps of $RUNR_STEPS (comma separated, in this order) for $IMAGE:
#                sbom    syft → SPDX JSON + CycloneDX JSON
#                attach  oras attach both SBOMs to the image as OCI referrers
#                scan    trivy image (vulnerabilities) + cosign-vuln predicate
#                sign    cosign sign the image digest with the region key, then verify it
#                attest  cosign attest the SPDX SBOM (spdxjson) and the scan (vuln)
#   keygen     cosign generate-key-pair into $RUNR_KEY_REF (k8s://<namespace>/<secret>)
#   mirror-db  copy the Trivy DBs ($RUNR_DB_SOURCE, $RUNR_JAVA_DB_SOURCE) into the region
#              registry ($RUNR_DB_TARGET, $RUNR_JAVA_DB_TARGET) and report the DB build time
#
# Output protocol (one line each, read by RunR from the pod log; tool output goes to files):
#   RUNR_STEP <step> <ok|failed|skipped> [base64(message)]
#   RUNR_DIGEST <sha256:...>
#   RUNR_FILE <name> <base64(gzip(content))>
#   RUNR_DONE
# A failed step never stops the later ones that do not depend on it; the exit code is 0 once the
# protocol is complete, so RunR reads every step's result.
set -u

MODE="${1:-pipeline}"
WORK="${RUNR_WORK_DIR:-/work}"
MAX_INLINE="${RUNR_MAX_INLINE_BYTES:-6291456}"
PLAIN_HTTP="${RUNR_PLAIN_HTTP:-false}"
STEPS="${RUNR_STEPS:-sbom,scan}"

[ -d "$WORK" ] && [ -w "$WORK" ] || WORK=/tmp/runr-work
mkdir -p "$WORK"
cd "$WORK" || exit 1

ORAS_FLAGS=""
ORAS_TO_FLAGS=""
COSIGN_FLAGS=""
if [ "$PLAIN_HTTP" = "true" ]; then
  ORAS_FLAGS="--plain-http"
  ORAS_TO_FLAGS="--to-plain-http"
  COSIGN_FLAGS="--allow-insecure-registry=true --allow-http-registry=true"
fi

# step <name> <ok|failed|skipped> [message]
step() {
  if [ -n "${3:-}" ]; then
    printf 'RUNR_STEP %s %s %s\n' "$1" "$2" "$(printf '%s' "$3" | tail -c 1000 | base64 -w 0)"
  else
    printf 'RUNR_STEP %s %s\n' "$1" "$2"
  fi
}

# run <log-name> <command...> — the command's output goes to $WORK/<log-name>.log
run() {
  name="$1"
  shift
  "$@" > "$WORK/$name.log" 2>&1
}

last_log() {
  [ -f "$WORK/$1.log" ] && tail -c 1000 "$WORK/$1.log"
}

has_step() {
  case ",$STEPS," in
    *",$1,"*) return 0 ;;
  esac
  return 1
}

# emit_file <name> <path> — gzip + base64 on one line, unless larger than RUNR_MAX_INLINE_BYTES
emit_file() {
  [ -s "$2" ] || return 0
  gz="$WORK/.$1.gz"
  gzip -c "$2" > "$gz"
  size=$(wc -c < "$gz" | tr -d ' ')
  if [ "$size" -gt "$MAX_INLINE" ]; then
    step "inline-$1" skipped "gzip size ${size} exceeds ${MAX_INLINE} bytes; the document stays in the registry"
    return 0
  fi
  printf 'RUNR_FILE %s ' "$1"
  base64 -w 0 "$gz"
  printf '\n'
}

# Pin $IMAGE to its digest (REF), unless it already is one
resolve_digest() {
  DIGEST=""
  REF="$IMAGE"
  case "$IMAGE" in
    *@sha256:*)
      DIGEST="${IMAGE##*@}"
      return 0
      ;;
  esac
  # shellcheck disable=SC2086
  DIGEST=$(oras resolve $ORAS_FLAGS "$IMAGE" 2> "$WORK/resolve.log" | tr -d '[:space:]') || DIGEST=""
  case "$DIGEST" in
    sha256:*) ;;
    *) DIGEST=""; return 0 ;;
  esac
  repo="$IMAGE"
  last="${IMAGE##*/}"
  case "$last" in
    *:*) repo="${IMAGE%:*}" ;;
  esac
  REF="${repo}@${DIGEST}"
}

pipeline() {
  if [ -z "${IMAGE:-}" ]; then
    step pipeline failed "IMAGE is not set"
    echo "RUNR_DONE"
    return 0
  fi

  resolve_digest
  [ -n "$DIGEST" ] && printf 'RUNR_DIGEST %s\n' "$DIGEST"

  if has_step sbom; then
    if run sbom syft scan "registry:${REF}" -q \
        -o "spdx-json=${WORK}/sbom.spdx.json" \
        -o "cyclonedx-json=${WORK}/sbom.cdx.json"; then
      step sbom ok
    else
      step sbom failed "$(last_log sbom)"
    fi
  fi

  if has_step attach; then
    if [ ! -s "$WORK/sbom.spdx.json" ] || [ ! -s "$WORK/sbom.cdx.json" ]; then
      step attach skipped "no SBOM to attach"
    else
      # shellcheck disable=SC2086
      if run attach-spdx oras attach $ORAS_FLAGS --artifact-type application/spdx+json "$REF" "sbom.spdx.json:application/spdx+json" \
          && run attach-cdx oras attach $ORAS_FLAGS --artifact-type application/vnd.cyclonedx+json "$REF" "sbom.cdx.json:application/vnd.cyclonedx+json"; then
        step attach ok
      else
        step attach failed "$(last_log attach-spdx; last_log attach-cdx)"
      fi
    fi
  fi

  if has_step scan; then
    if run scan trivy image --image-src remote --scanners vuln --format json --timeout 20m \
        --output "$WORK/trivy.json" "$REF"; then
      step scan ok
      if has_step attest; then
        run convert trivy convert --format cosign-vuln --output "$WORK/vuln.json" "$WORK/trivy.json" || rm -f "$WORK/vuln.json"
      fi
    else
      step scan failed "$(last_log scan)"
    fi
    trivy version --format json --cache-dir "${TRIVY_CACHE_DIR:-/cache}" > "$WORK/trivy-version.json" 2> /dev/null || rm -f "$WORK/trivy-version.json"
  fi

  if has_step sign; then
    if [ -z "$DIGEST" ]; then
      step sign failed "the digest of ${IMAGE} could not be resolved: $(tail -c 500 "$WORK/resolve.log" 2>/dev/null)"
    else
      # shellcheck disable=SC2086
      if run sign cosign sign --key "$RUNR_COSIGN_KEY" --tlog-upload=false --yes $COSIGN_FLAGS "$REF" \
          && run verify cosign verify --key "$RUNR_COSIGN_PUB" --insecure-ignore-tlog=true $COSIGN_FLAGS "$REF"; then
        step sign ok
      else
        step sign failed "$(last_log sign; last_log verify)"
      fi
    fi
  fi

  if has_step attest; then
    if [ -z "$DIGEST" ]; then
      step attest skipped "no digest"
    else
      failed=""
      attested=""
      if [ -s "$WORK/sbom.spdx.json" ]; then
        # shellcheck disable=SC2086
        if run attest-sbom cosign attest --key "$RUNR_COSIGN_KEY" --type spdxjson --predicate "$WORK/sbom.spdx.json" \
            --tlog-upload=false --yes $COSIGN_FLAGS "$REF"; then
          attested="sbom"
        else
          failed="sbom"
        fi
      fi
      if [ -s "$WORK/vuln.json" ]; then
        # shellcheck disable=SC2086
        if run attest-vuln cosign attest --key "$RUNR_COSIGN_KEY" --type vuln --predicate "$WORK/vuln.json" \
            --tlog-upload=false --yes $COSIGN_FLAGS "$REF"; then
          attested="${attested} vuln"
        else
          failed="${failed} vuln"
        fi
      fi
      if [ -n "$failed" ]; then
        step attest failed "attestation failed:${failed} $(last_log attest-sbom; last_log attest-vuln)"
      elif [ -n "$attested" ]; then
        step attest ok "$attested"
      else
        step attest skipped "nothing to attest"
      fi
    fi
  fi

  emit_file sbom-spdx "$WORK/sbom.spdx.json"
  emit_file sbom-cdx "$WORK/sbom.cdx.json"
  emit_file trivy "$WORK/trivy.json"
  emit_file trivy-version "$WORK/trivy-version.json"
  echo "RUNR_DONE"
}

keygen() {
  if [ -z "${RUNR_KEY_REF:-}" ] || [ -z "${COSIGN_PASSWORD:-}" ]; then
    step keygen failed "RUNR_KEY_REF and COSIGN_PASSWORD are required"
    echo "RUNR_DONE"
    return 1
  fi
  # cosign also writes cosign.pub into the working directory
  if run keygen cosign generate-key-pair "$RUNR_KEY_REF"; then
    step keygen ok
  else
    step keygen failed "$(last_log keygen)"
    echo "RUNR_DONE"
    return 1
  fi
  echo "RUNR_DONE"
}

mirror_db() {
  # shellcheck disable=SC2086
  if run mirror oras cp $ORAS_TO_FLAGS "$RUNR_DB_SOURCE" "$RUNR_DB_TARGET"; then
    step mirror ok
  else
    step mirror failed "$(last_log mirror)"
    echo "RUNR_DONE"
    return 1
  fi

  if [ -n "${RUNR_JAVA_DB_SOURCE:-}" ] && [ -n "${RUNR_JAVA_DB_TARGET:-}" ]; then
    # shellcheck disable=SC2086
    if run mirror-java oras cp $ORAS_TO_FLAGS "$RUNR_JAVA_DB_SOURCE" "$RUNR_JAVA_DB_TARGET"; then
      step mirror-java ok
    else
      step mirror-java failed "$(last_log mirror-java)"
    fi
  fi

  # Read the copy back the way scans will, then report its build time
  if run verify trivy image --download-db-only --db-repository "$RUNR_DB_TARGET"; then
    step verify ok
  else
    step verify failed "$(last_log verify)"
  fi
  trivy version --format json > "$WORK/trivy-version.json" 2> /dev/null || rm -f "$WORK/trivy-version.json"
  emit_file trivy-version "$WORK/trivy-version.json"
  echo "RUNR_DONE"
}

case "$MODE" in
  pipeline)  pipeline ;;
  keygen)    keygen ;;
  mirror-db) mirror_db ;;
  *)
    step "$MODE" failed "unknown mode ${MODE} (pipeline | keygen | mirror-db)"
    echo "RUNR_DONE"
    exit 2
    ;;
esac
