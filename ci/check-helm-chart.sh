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

# An upgrade from RunR ≤ 0.5.0 keeps the previous chart's image values (`helm upgrade
# --reuse-values` reuses them, installs may have set them): every image under RunR's namespace up
# to 0.5.0 renders as the same image under ghcr.io/brf-tech (template helper runr.image).
old_ns="ghcr.io/runrsh"
sed "s#ghcr\.io/brf-tech/#${old_ns}/#g" "$ROOT/helm/values.yaml" >"$OUT/values-0.5.0.values"
if helm template runr "$ROOT/helm" --namespace runr -f "$OUT/values-0.5.0.values" --set domain=console.acme-cloud.example \
    --set "supplyChain.toolsImage=${old_ns}/runr-supply-chain:1.0.0" --set "images.uploadImage=${old_ns}/image-upload:1.0.0" \
    --set "knative.traefikImage=${old_ns}/reqs/traefik:v3.7.4" >"$OUT/upgrade-images.render" 2>"$OUT/upgrade-images.err"; then
  left=$(grep -n "${old_ns}/" "$OUT/upgrade-images.render" || true)
  moved=$(grep -c 'ghcr\.io/brf-tech/' "$OUT/upgrade-images.render" || true)
  if [ -z "$left" ] && [ "$moved" -ge 12 ]; then
    echo "✓ upgrade-images: values of RunR ≤ 0.5.0 render ghcr.io/brf-tech ($moved references)"
  else
    echo "✗ upgrade-images: values of RunR ≤ 0.5.0 still render ${old_ns}/ (or too few ghcr.io/brf-tech references: ${moved})"
    echo "$left"
    fail=1
  fi
else
  echo "✗ upgrade-images: helm template"
  cat "$OUT/upgrade-images.err"
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
