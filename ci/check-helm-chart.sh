#!/bin/bash
set -euo pipefail

# ── Helm chart static check ──────────────────────────────────────────────────
# helm lint --strict and helm template of helm/ with the defaults and with every values file in
# ci/helm-values/ (multi-tenant edition, white-label branding, supply chain, AI / GPU,
# observability, high availability); every rendering is validated with kubeconform against each
# Kubernetes version RunR supports (1.30 and later: KNATIVE_MIN_K8S_MINOR).
#
#   bash ci/check-helm-chart.sh
#   K8S_VERSIONS="1.30.0 1.35.0" bash ci/check-helm-chart.sh
#
# Needs helm, and kubeconform on the PATH or docker (kubeconform then runs in a container); it
# downloads the Kubernetes schemas from GitHub.
# ─────────────────────────────────────────────────────────────────────────────

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
K8S_VERSIONS="${K8S_VERSIONS:-1.30.0 1.31.0 1.32.0 1.33.0 1.34.0 1.35.0}"
KUBECONFORM_IMAGE="${KUBECONFORM_IMAGE:-ghcr.io/yannh/kubeconform:v0.7.0}"
OUT="$(mktemp -d "${TMPDIR:-/tmp}/runr-helm-check.XXXXXX")"
trap 'rm -rf "$OUT"' EXIT

fail=0
scenarios=(defaults)
for f in "$ROOT"/ci/helm-values/*.yaml; do
  scenarios+=("$(basename "$f" .yaml)")
done

for name in "${scenarios[@]}"; do
  if [ "$name" = defaults ]; then
    args=(--set domain=console.acme-cloud.example)
  else
    args=(-f "$ROOT/ci/helm-values/$name.yaml")
  fi

  if ! helm lint "$ROOT/helm" --strict "${args[@]}" >"$OUT/$name.lint" 2>&1; then
    echo "✗ $name: helm lint"
    cat "$OUT/$name.lint"
    fail=1
    continue
  fi
  if ! helm template runr "$ROOT/helm" --namespace runr "${args[@]}" >"$OUT/$name.yaml" 2>"$OUT/$name.err"; then
    echo "✗ $name: helm template"
    cat "$OUT/$name.err"
    fail=1
    continue
  fi
  echo "✓ $name: lint + template ($(grep -c '^kind:' "$OUT/$name.yaml") resources)"
done

# An upgrade keeps the previous chart's image values (`helm upgrade --reuse-values` reuses them,
# installs may have set them): every image of RunR's earlier namespaces — ghcr.io/runrsh up to
# 0.5.0, RunR's packages directly under ghcr.io/brf-tech in 0.5.1 — renders as the same image under
# ghcr.io/brf-tech/runr (template helper runr.image).
old_refs='ghcr\.io/runrsh/|ghcr\.io/brf-tech/(reqs/|exec-|runr-|hf-serve|image-upload|containerd-shim-spin)'
for release in 0.5.0 0.5.1; do
  if [ "$release" = 0.5.0 ]; then
    old_ns="ghcr.io/runrsh"
    sed -E -e 's#ghcr\.io/brf-tech/runr/(api|ui)#ghcr.io/runrsh/runr/\1#g' -e 's#ghcr\.io/brf-tech/runr/#ghcr.io/runrsh/#g' \
      "$ROOT/helm/values.yaml" >"$OUT/values-${release}.values"
  else
    old_ns="ghcr.io/brf-tech"
    sed -E 's#ghcr\.io/brf-tech/runr/(reqs/|hf-serve)#ghcr.io/brf-tech/\1#g' "$ROOT/helm/values.yaml" >"$OUT/values-${release}.values"
  fi
  if helm template runr "$ROOT/helm" --namespace runr -f "$OUT/values-${release}.values" --set domain=console.acme-cloud.example \
      --set "supplyChain.toolsImage=${old_ns}/runr-supply-chain:1.0.0" --set "images.uploadImage=${old_ns}/image-upload:1.0.0" \
      --set "knative.traefikImage=${old_ns}/reqs/traefik:v3.7.4" >"$OUT/upgrade-images-${release}.render" 2>"$OUT/upgrade-images.err"; then
    left=$(grep -n -E "$old_refs" "$OUT/upgrade-images-${release}.render" || true)
    moved=$(grep -c 'ghcr\.io/brf-tech/runr/' "$OUT/upgrade-images-${release}.render" || true)
    if [ -z "$left" ] && [ "$moved" -ge 12 ]; then
      echo "✓ upgrade-images: values of RunR ${release} render ghcr.io/brf-tech/runr ($moved references)"
    else
      echo "✗ upgrade-images: values of RunR ${release} still render ${old_ns}/ (or too few ghcr.io/brf-tech/runr references: ${moved})"
      echo "$left"
      fail=1
    fi
  else
    echo "✗ upgrade-images (${release}): helm template"
    cat "$OUT/upgrade-images.err"
    fail=1
  fi
done

# The tracing chart versions the values.yaml of 0.5.0 / 0.5.1 set, which `helm upgrade --reuse-values` keeps: no
# OBS_*_CHART_VERSION, so the API installs the versions it pins; another version is passed on
if helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example \
    --set observability.tracing.collectorChartVersion=0.175.1 --set observability.tracing.tempoChartVersion=3.1.0 \
    >"$OUT/upgrade-tracing.render" 2>"$OUT/upgrade-tracing.err" \
  && helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example \
    --set observability.tracing.collectorChartVersion=0.177.0 --set observability.tracing.collectorImage=registry.example.com/otelcol:0.163.0 \
    >"$OUT/tracing-set.render" 2>>"$OUT/upgrade-tracing.err"; then
  if ! grep -q 'OBS_\(OTEL_COLLECTOR\|TEMPO\)_CHART_VERSION' "$OUT/upgrade-tracing.render" \
    && grep -q 'OBS_OTEL_COLLECTOR_CHART_VERSION: "0.177.0"' "$OUT/tracing-set.render" \
    && grep -q 'OBS_OTEL_COLLECTOR_IMAGE: "registry.example.com/otelcol:0.163.0"' "$OUT/tracing-set.render"; then
    echo "✓ upgrade-tracing: the tracing chart versions of 0.5.0 / 0.5.1 give way to the pinned ones, set values pass"
  else
    echo "✗ upgrade-tracing: OBS_*_CHART_VERSION / OBS_OTEL_COLLECTOR_IMAGE rendered wrong"
    grep 'OBS_\(OTEL\|TEMPO\)' "$OUT/upgrade-tracing.render" "$OUT/tracing-set.render" || true
    fail=1
  fi
else
  echo "✗ upgrade-tracing: helm template"
  cat "$OUT/upgrade-tracing.err"
  fail=1
fi

# kubeconform from the PATH, else in a container. The renderings go in on stdin: no bind mount,
# so a remote Docker daemon (docker-in-docker) works too.
run_kubeconform() {
  if type -P kubeconform >/dev/null; then
    kubeconform "$@"
  else
    docker run --rm -i "$KUBECONFORM_IMAGE" "$@"
  fi
}

for version in $K8S_VERSIONS; do
  if cat "$OUT"/*.yaml | run_kubeconform -strict -summary -kubernetes-version "$version" - >"$OUT/kubeconform-$version.txt" 2>&1; then
    echo "✓ Kubernetes $version: $(tail -1 "$OUT/kubeconform-$version.txt")"
  else
    echo "✗ Kubernetes $version"
    cat "$OUT/kubeconform-$version.txt"
    fail=1
  fi
done

exit "$fail"
