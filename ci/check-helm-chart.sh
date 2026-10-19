#!/bin/bash
set -euo pipefail

# ── Helm chart static check ──────────────────────────────────────────────────
# helm lint --strict and helm template of helm/ with the defaults and with every values file in
# ci/helm-values/ (multi-tenant edition, white-label branding, supply chain, AI / GPU,
# observability, high availability, external services, network policies, a shared API volume, an
# operator-managed Secret); every rendering is validated with kubeconform against each Kubernetes
# version RunR supports (1.30 and later: KNATIVE_MIN_K8S_MINOR). Further checks render single
# values and look at what the templates make of them (storage, secrets, gateway, ports, …).
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

# Placement of RunR's pods (template helper runr.placement): by default every pod template — the
# Deployments and the hook Jobs — tolerates the dedicated workers' taint and prefers the other
# nodes; with ci/helm-values/placement.yaml both come on top of the operator's tolerations,
# affinity (node, pod, anti) and nodeSelector, and the API storage colocation stays; switched off
# (placement.tolerateDedicatedWorkers=false, podColocation=false) nothing is added. Values without
# placement (reused from a release before it) get the default
count() { grep -c -E -- "$1" "$2" || true; }
if helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example >"$OUT/placement-default.render" 2>"$OUT/placement.err" \
  && helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example --set placement=null >"$OUT/placement-reused.render" 2>>"$OUT/placement.err" \
  && helm template runr "$ROOT/helm" --namespace runr -f "$ROOT/ci/helm-values/placement.yaml" >"$OUT/placement-set.render" 2>>"$OUT/placement.err" \
  && helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example \
    --set placement.tolerateDedicatedWorkers=false --set podColocation=false >"$OUT/placement-off.render" 2>>"$OUT/placement.err"; then
  pods=$(count '^kind: (Deployment|Job)$' "$OUT/placement-default.render")
  colocated=$(count 'requiredDuringSchedulingIgnoredDuringExecution:' "$OUT/placement-default.render")
  problems=()
  [ "$pods" -ge 12 ] || problems+=("only $pods pod templates")
  for f in placement-default placement-reused placement-set; do
    [ "$(count '^ +value: projects$' "$OUT/$f.render")" = "$pods" ] || problems+=("$f: not every pod tolerates runr.dev/dedicated=projects")
    [ "$(count '^ +operator: NotIn$' "$OUT/$f.render")" = "$pods" ] || problems+=("$f: not every pod prefers the nodes that are not dedicated workers")
  done
  [ "$colocated" -ge 7 ] || problems+=("only $colocated pods colocated with the API storage")
  for want in '^ +value: infra$' '^ +- key: node-role\.kubernetes\.io/infra$' '^ +weight: 10$' 'topologyKey: topology\.kubernetes\.io/zone$' '^ +node-role\.kubernetes\.io/infra: "true"$'; do
    [ "$(count "$want" "$OUT/placement-set.render")" = "$pods" ] || problems+=("placement-set: ${want} not on every pod")
  done
  [ "$(count 'requiredDuringSchedulingIgnoredDuringExecution:' "$OUT/placement-set.render")" = "$((pods + colocated))" ] \
    || problems+=("placement-set: the required node affinity and the colocation do not both stay")
  [ "$(count 'runr\.dev/dedicated|^ +(affinity|tolerations):$' "$OUT/placement-off.render")" = 0 ] || problems+=("placement-off: placement rendered although switched off")
  if [ ${#problems[@]} -eq 0 ]; then
    echo "✓ placement: $pods pod templates tolerate the dedicated workers, operator placement merged, $colocated colocated, nothing when switched off"
  else
    printf '✗ placement: %s\n' "${problems[@]}"
    fail=1
  fi
else
  echo "✗ placement: helm template"
  cat "$OUT/placement.err"
  fail=1
fi

# SSO callbacks: the provider redirects to the API's web route /oauth/<provider>/callback, which the
# gateway must send to runr-api; the console's own /oauth/callback page (and every other path the
# API does not serve) stays with runr-ui
if helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example \
    --show-only templates/gateway-configmap.yaml >"$OUT/gateway.render" 2>"$OUT/gateway.err"; then
  oauth=$(sed -n '/^ *handle \/oauth\/\*\/callback {$/,/^ *}$/p' "$OUT/gateway.render")
  problems=()
  grep -q 'reverse_proxy runr-api:80 {' <<<"$oauth" || problems+=("no handle /oauth/*/callback → runr-api:80")
  grep -q -E '^ *handle /oauth/(\*|callback) \{' "$OUT/gateway.render" && problems+=("a broader /oauth route would take the console's /oauth/callback from runr-ui")
  grep -q -E '^ *handle \{$' "$OUT/gateway.render" && grep -A1 -E '^ *handle \{$' "$OUT/gateway.render" | grep -q 'reverse_proxy runr-ui:80' \
    || problems+=("the catch-all no longer goes to runr-ui")
  if [ ${#problems[@]} -eq 0 ]; then
    echo "✓ gateway: /oauth/<provider>/callback → runr-api, /oauth/callback and the rest → runr-ui"
  else
    printf '✗ gateway: %s\n' "${problems[@]}"
    fail=1
  fi
else
  echo "✗ gateway: helm template"
  cat "$OUT/gateway.err"
  fail=1
fi

# UI image per edition (template helper runr.uiImage): single-tenant keeps the tag; multi-tenant gets
# the published multi-tenant variant <tag>-mt, <appVersion>-mt for the moving tag latest (which has
# no -mt variant); an explicit suffix — "" included, the default before the -mt default and what
# `--reuse-values` keeps from those releases — is used as it is
app_version=$(sed -n -E 's/^appVersion: *"?([^"]+)"?$/\1/p' "$ROOT/helm/Chart.yaml")
ui_image() {
  helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example "$@" \
    --show-only templates/ui-deployment.yaml 2>&1 | sed -n -E 's/^ *image: "?([^"]+)"?$/\1/p' | head -1
}
problems=()
check_ui() { # want args...
  local want=$1 got
  shift
  got=$(ui_image "$@")
  [ "$got" = "$want" ] || problems+=("${*:-defaults}: $got (want $want)")
}
check_ui "ghcr.io/brf-tech/runr/ui:latest"
check_ui "ghcr.io/brf-tech/runr/ui:0.5.6" --set ui.image.tag=0.5.6
check_ui "ghcr.io/brf-tech/runr/ui:${app_version}-mt" --set edition=multi-tenant
check_ui "ghcr.io/brf-tech/runr/ui:0.5.6-mt" --set edition=multi-tenant --set ui.image.tag=0.5.6
check_ui "ghcr.io/brf-tech/runr/ui:latest" --set edition=multi-tenant --set ui.image.multiTenantSuffix=
check_ui "ghcr.io/brf-tech/runr/ui:0.5.6" --set edition=multi-tenant --set ui.image.tag=0.5.6 --set ui.image.multiTenantSuffix=
check_ui "registry.example.com/runr-ui:2.0-modules" --set edition=multi-tenant --set ui.image.repository=registry.example.com/runr-ui \
  --set-string ui.image.tag=2.0 --set ui.image.multiTenantSuffix=-modules
check_ui "ghcr.io/brf-tech/runr/ui:${app_version}-mt" -f "$ROOT/ci/helm-values/tenancy.yaml"
if [ ${#problems[@]} -eq 0 ]; then
  echo "✓ ui-image: single-tenant <tag>, multi-tenant <tag>-mt (${app_version}-mt for latest), explicit suffix kept"
else
  printf '✗ ui-image: %s\n' "${problems[@]}"
  fail=1
fi

# Ticket module (values ticket.enabled → TICKET_ENABLED of every API container and hook): on by
# default and for values without the key (reused from a release before it, when the API enabled the
# module by default), off when set so
ticket_env() {
  helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example "$@" \
    --show-only templates/configmap.yaml 2>&1 | sed -n -E 's/^ *TICKET_ENABLED: "?([a-z]+)"?$/\1/p'
}
if [ "$(ticket_env)" = true ] && [ "$(ticket_env --set ticket=null)" = true ] \
  && [ "$(ticket_env --set ticket.enabled=false)" = false ] && [ "$(ticket_env --set-string ticket.enabled=false)" = false ]; then
  echo "✓ ticket: TICKET_ENABLED true by default and for values without ticket, false when ticket.enabled=false"
else
  echo "✗ ticket: TICKET_ENABLED rendered wrong (defaults: $(ticket_env), without ticket: $(ticket_env --set ticket=null), off: $(ticket_env --set ticket.enabled=false))"
  fail=1
fi

# Billing module (values billing.* → BILLING_ENABLED / BILLING_CURRENCY of every API container and
# hook, docs/billing.md): off by default and for values without the key (reused from a release before
# the module), on when set so; the currency defaults to USD; the bootstrap hook toggles the module like
# the entrypoint; the 0.5.7 plan and billing keys are gone from the ConfigMap
billing_env() {
  local key=$1
  shift
  helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example "$@" \
    --show-only templates/configmap.yaml 2>&1 | sed -n -E "s/^ *${key}: \"?([A-Za-z]+)\"?$/\1/p"
}
billing_problems=()
[ "$(billing_env BILLING_ENABLED)" = false ] || billing_problems+=("BILLING_ENABLED is not false by default ($(billing_env BILLING_ENABLED))")
[ "$(billing_env BILLING_ENABLED --set billing=null)" = false ] || billing_problems+=("BILLING_ENABLED is not false without billing values")
[ "$(billing_env BILLING_ENABLED --set billing.enabled=true)" = true ] || billing_problems+=("BILLING_ENABLED is not true with billing.enabled=true")
[ "$(billing_env BILLING_CURRENCY)" = USD ] || billing_problems+=("BILLING_CURRENCY is not USD by default ($(billing_env BILLING_CURRENCY))")
[ "$(billing_env BILLING_CURRENCY --set billing.currency=EUR)" = EUR ] || billing_problems+=("BILLING_CURRENCY does not follow billing.currency")
if helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example \
  --show-only templates/configmap.yaml 2>&1 | grep -q -E '^ *(PLANS_ENABLED|BILLING_USAGE_THRESHOLDS|BILLING_STORE_URL):'; then
  billing_problems+=("the ConfigMap still renders a 0.5.7 plan / billing key")
fi
if ! helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example \
  --show-only templates/bootstrap-job.yaml 2>&1 | grep -q -F 'toggle Billing "${BILLING_ENABLED:-false}"'; then
  billing_problems+=("the bootstrap hook does not toggle the Billing module")
fi
if [ ${#billing_problems[@]} -eq 0 ]; then
  echo "✓ billing: BILLING_ENABLED false by default and for values without billing, true when billing.enabled=true; BILLING_CURRENCY USD by default; bootstrap toggles Billing"
else
  printf '✗ billing: %s\n' "${billing_problems[@]}"
  fail=1
fi

# ── Production settings ──────────────────────────────────────────────────────
# render <name> [args…]: helm template with the domain set → $OUT/<name>.render (errors: .err)
render() {
  local name=$1
  shift
  helm template runr "$ROOT/helm" --namespace runr --set domain=console.acme-cloud.example "$@" >"$OUT/$name.render" 2>"$OUT/$name.err"
}
# refused <name> <message> [args…]: the values stop the install with that message
refused() {
  local name=$1 want=$2
  shift 2
  if render "$name" "$@"; then
    return 1
  fi
  grep -q -F -- "$want" "$OUT/$name.err"
}
# report <check> <what passed>: prints the problems collected in $problems, or the success line
report() {
  if [ ${#problems[@]} -eq 0 ]; then
    echo "✓ $1: $2"
  else
    printf "✗ $1: %s\n" "${problems[@]}"
    fail=1
  fi
}
# pods <file>: number of pod templates (Deployments and Jobs)
pods() { count '^kind: (Deployment|Job)$' "$1"; }

# API volume (api.storage.accessModes): ReadWriteOnce — the default, and values reused from a
# release before the key — colocates the pods that mount it and creates no API disruption budget
# even with api.pdb.enabled (it would hold a node drain forever); ReadWriteMany spreads them and
# creates the budget
problems=()
if render storage-rwo --set api.pdb.enabled=true && render storage-reused --set api.storage.accessModes=null --set api.pdb.enabled=true \
  && render storage-rwx --set 'api.storage.accessModes={ReadWriteMany}' --set api.storage.storageClassName=nfs-rwx --set api.pdb.enabled=true; then
  claim() { sed -n '/^  name: runr-api-storage$/,/^---$/p' "$OUT/$1.render"; }
  for f in storage-rwo storage-reused; do
    [ "$(claim "$f" | count '^ +- ReadWriteOnce$' -)" = 1 ] || problems+=("$f: the API volume is not ReadWriteOnce")
    [ "$(count '^ +runr/storage-group: api$' "$OUT/$f.render")" -ge 7 ] || problems+=("$f: the pods that mount the API volume are not colocated")
    [ "$(count '^kind: PodDisruptionBudget$' "$OUT/$f.render")" = 0 ] || problems+=("$f: a disruption budget for the API with a ReadWriteOnce volume")
  done
  [ "$(claim storage-rwx | count '^ +- ReadWriteMany$' -)" = 1 ] || problems+=("storage-rwx: the API volume is not ReadWriteMany")
  [ "$(claim storage-rwx | count '^  storageClassName: nfs-rwx$' -)" = 1 ] || problems+=("storage-rwx: api.storage.storageClassName not on the claim")
  [ "$(count 'runr/storage-group|requiredDuringSchedulingIgnoredDuringExecution' "$OUT/storage-rwx.render")" = 0 ] || problems+=("storage-rwx: pods colocated although the volume is ReadWriteMany")
  [ "$(count '^kind: PodDisruptionBudget$' "$OUT/storage-rwx.render")" = 1 ] || problems+=("storage-rwx: no disruption budget for the API")
else
  problems+=("helm template: $(cat "$OUT"/storage-*.err)")
fi
if render pg-class --set database.postgres.storageClassName=fast-rwo --show-only templates/postgres-deployment.yaml \
  && render pg-default --show-only templates/postgres-deployment.yaml; then
  grep -q '^  storageClassName: fast-rwo$' "$OUT/pg-class.render" || problems+=("database.postgres.storageClassName not on runr-postgres-data")
  grep -q 'storageClassName' "$OUT/pg-default.render" && problems+=("runr-postgres-data has a StorageClass without database.postgres.storageClassName")
else
  problems+=("helm template: $(cat "$OUT"/pg-*.err)")
fi
report storage "ReadWriteOnce colocated without an API budget, ReadWriteMany spread with it, StorageClasses of both volumes"

# Passwords of the database, the SMTP server and Redis come from Secrets, never from the ConfigMap:
# database.password / mail.password / redis.password are written to the chart's Secret,
# *.existingSecret is referenced as it is; secret.create=false refuses the plain passwords; the
# chart's Redis requires a configured password; SQLite is refused with a reason
problems=()
ext="$OUT/external-services.yaml"
[ "$(count 'smtp-example-password|redis-example-password' "$ext")" = 0 ] || problems+=("external-services: a password in plain text")
[ "$(grep -A4 '^ *- name: DB_PASSWORD$' "$ext" | count 'name: "acme-postgres"' -)" -ge 6 ] || problems+=("external-services: DB_PASSWORD not from database.existingSecret")
[ "$(grep -A4 '^ *- name: DB_PASSWORD$' "$ext" | count 'key: "runr-password"' -)" -ge 6 ] || problems+=("external-services: DB_PASSWORD not from database.existingSecretPasswordKey")
for key in MAIL_PASSWORD REDIS_PASSWORD; do
  [ "$(grep -A4 "^ *- name: ${key}\$" "$ext" | count "key: \"${key}\"" -)" -ge 6 ] || problems+=("external-services: ${key} not from the chart's Secret")
  grep -q "^  ${key}: " "$ext" || problems+=("external-services: ${key} not in the chart's Secret")
done
grep -q '^  DB_PASSWORD: ' "$ext" && problems+=("external-services: a generated DB_PASSWORD although database.existingSecret is set")
for want in 'MAIL_USERNAME: "runr@acme-cloud.example"' 'MAIL_SCHEME: "smtps"' 'REDIS_USERNAME: "runr"' 'REDIS_PORT: "6380"'; do
  grep -q -F "  $want" "$ext" || problems+=("external-services: no $want")
done
ops="$OUT/operator-secret.yaml"
for want in 'acme-postgres:password' 'acme-smtp:password' 'runr-secret:REDIS_PASSWORD'; do
  [ "$(grep -A3 'secretKeyRef:' "$ops" | tr -d '"' | grep -A1 "name: ${want%%:*}\$" | count "key: ${want#*:}\$" -)" -ge 6 ] || problems+=("operator-secret: no reference to ${want}")
done
grep -q -F 'args: ["redis-server", "--requirepass", "$(REDIS_PASSWORD)"]' "$ops" || problems+=("operator-secret: the chart's Redis does not require the password")
if render secrets-default && render secrets-db --set database.password=db-example-password --show-only templates/secret.yaml; then
  [ "$(count 'MAIL_PASSWORD|REDIS_PASSWORD|requirepass|MAIL_USERNAME|MAIL_SCHEME' "$OUT/secrets-default.render")" = 0 ] || problems+=("defaults: SMTP / Redis credentials rendered")
  grep -q "^  DB_PASSWORD: $(printf '%s' db-example-password | base64)\$" "$OUT/secrets-db.render" || problems+=("database.password not written to the chart's Secret")
else
  problems+=("helm template: $(cat "$OUT"/secrets-*.err)")
fi
refused secrets-create-false 'database.password is written to the chart' --set secret.create=false --set database.password=x || problems+=("secret.create=false with database.password not refused")
refused secrets-mail-create-false 'mail.password is written to the chart' --set secret.create=false --set mail.host=smtp.example.com --set mail.password=x || problems+=("secret.create=false with mail.password not refused")
refused sqlite 'database.driver "sqlite" is not supported by the Helm chart' --set database.driver=sqlite || problems+=("database.driver=sqlite not refused with its reason")
refused mail-encryption 'mail.encryption' --set mail.host=smtp.example.com --set mail.encryption=ssl3 || problems+=("an unknown mail.encryption accepted")
report secrets "database / SMTP / Redis passwords from Secrets (chart's or existing), none in the ConfigMap; SQLite and plain passwords with secret.create=false refused"

# values.schema.json validates the keys the templates read — images.* and supplyChain.* at the top
# level — and accepts api.images / api.supplyChain, which earlier schemas listed, without effect
problems=()
refused schema-images 'images.quotaGb' --set-json 'images.quotaGb=[1]' || problems+=("images.quotaGb not validated")
refused schema-supply-chain 'supplyChain.enabled' --set-string supplyChain.enabled=maybe || problems+=("supplyChain.enabled not validated")
render schema-old-keys --set api.images.quotaGb=100 --set api.supplyChain.toolsImage=registry.example.com/x:1 || problems+=("api.images / api.supplyChain refused: $(cat "$OUT/schema-old-keys.err")")
report schema "images.* and supplyChain.* validated at the top level, the old api.* keys accepted"

# Gateway: the ingress controller's X-Forwarded-Proto (https) reaches the API — no route overwrites
# it with the gateway's own scheme, and the ingress controller is a trusted proxy; /horizon and
# /horizon/* go to the API
problems=()
if render forwarded --show-only templates/gateway-configmap.yaml && render forwarded-direct --set ingress.enabled=false --show-only templates/gateway-configmap.yaml; then
  grep -q 'X-Forwarded-Proto' <(grep -v '^ *#' "$OUT/forwarded.render") && problems+=("a route sets X-Forwarded-Proto itself")
  grep -q 'trusted_proxies static private_ranges' "$OUT/forwarded.render" || problems+=("the ingress controller is not a trusted proxy")
  grep -q 'trusted_proxies' "$OUT/forwarded-direct.render" && problems+=("trusted proxies without an ingress controller in front")
  grep -q -F '@horizon path /horizon /horizon/*' "$OUT/forwarded.render" && grep -A1 -F 'handle @horizon {' "$OUT/forwarded.render" | grep -q 'reverse_proxy runr-api:80' \
    || problems+=("/horizon is not routed to runr-api")
else
  problems+=("helm template: $(cat "$OUT"/forwarded*.err)")
fi
report forwarded "X-Forwarded-Proto of the ingress controller kept, /horizon → runr-api"

# Ports: api.port, ui.port and reverb.port are the ports the processes listen on (RUNR_API_PORT,
# RUNR_UI_PORT, REVERB_SERVER_PORT), the containers' and the Services' target ports; Reverb scales
# with Redis publish/subscribe from 2 replicas on
problems=()
if render ports --set api.port=8080 --set ui.port=8081 --set reverb.port=9090 --set reverb.replicas=2; then
  for want in 'name: RUNR_API_PORT' 'name: RUNR_UI_PORT'; do
    grep -A1 -F "$want" "$OUT/ports.render" | grep -q -E 'value: "808[01]"' || problems+=("ports: ${want#name: } not set")
  done
  for want in 'REVERB_SERVER_PORT: "9090"' 'containerPort: 8080' 'containerPort: 8081' 'containerPort: 9090' 'targetPort: 8080' 'targetPort: 8081' 'reverse_proxy runr-reverb:9090'; do
    grep -q -F "$want" "$OUT/ports.render" || problems+=("ports: no $want")
  done
  grep -A1 'name: REVERB_SCALING_ENABLED' "$OUT/ports.render" | grep -q 'value: "true"' || problems+=("2 Reverb replicas without REVERB_SCALING_ENABLED")
  grep -A1 'name: REVERB_SCALING_ENABLED' "$OUT/secrets-default.render" | grep -q 'value: "false"' || problems+=("1 Reverb replica with REVERB_SCALING_ENABLED")
else
  problems+=("helm template: $(cat "$OUT/ports.err")")
fi
refused reverb-zero 'reverb.replicas must be at least 1' --set reverb.replicas=0 || problems+=("reverb.replicas=0 accepted")
report ports "api / ui / reverb ports reach the processes, Reverb replicas share broadcasts over Redis"

# HorizontalPodAutoscaler: the Deployment it scales has no replicas field in a rendering (helm
# upgrade keeps the live count, read with lookup), the others keep theirs
problems=()
for component in api gateway ui; do
  if render "hpa-$component" --set "$component.hpa.enabled=true" --show-only "templates/$component-deployment.yaml" \
    && render "nohpa-$component" --show-only "templates/$component-deployment.yaml"; then
    grep -q '^  replicas:' "$OUT/hpa-$component.render" && problems+=("$component: replicas rendered although its HPA is on")
    grep -q '^  replicas: 2$' "$OUT/nohpa-$component.render" || problems+=("$component: replicas missing without an HPA")
  else
    problems+=("helm template: $(cat "$OUT/hpa-$component.err" "$OUT/nohpa-$component.err")")
  fi
done
report hpa "replicas left to the HPA of the gateway, API and UI"

# First administrator with secret.create=false: the bootstrap Job stores a generated password only
# in the Secret runr-admin (get / patch on that one Secret), never in its log; with
# secret.create=true none of that exists (the password is ADMIN_PASSWORD of runr-secret)
problems=()
job=$(sed -n '/^kind: Job$/,/^---$/p' "$ops" | sed -n '/name: runr-bootstrap$/,$p')
grep -q -F 'patch secret runr-admin --type merge --patch-file' <<<"$job" || problems+=("operator-secret: the bootstrap Job does not store the password in runr-admin")
grep -q -E '(echo|printf).*\$ADMIN_PASSWORD"?( |$)' <<<"$(grep -v 'base64' <<<"$job")" && problems+=("operator-secret: the bootstrap Job prints the password")
grep -q -E '^  name: runr-admin$' "$ops" || problems+=("operator-secret: no Secret runr-admin")
grep -A3 '^rules:$' "$ops" | grep -q -F 'resourceNames: ["runr-admin"]' && grep -q -F 'verbs: ["get", "patch"]' "$ops" \
  || problems+=("operator-secret: the bootstrap Job may not read and patch runr-admin, or more")
[ "$(count 'runr-admin' "$OUT/secrets-default.render")" = 0 ] || problems+=("defaults: runr-admin rendered with secret.create=true")
report admin "a generated password goes to the Secret runr-admin only (secret.create=false)"

# NetworkPolicies: none by default; switched on, one per component with the bundled services, the
# external services' ports with external ones
problems=()
[ "$(count '^kind: NetworkPolicy$' "$OUT/secrets-default.render")" = 0 ] || problems+=("defaults: NetworkPolicies rendered")
[ "$(count '^kind: NetworkPolicy$' "$OUT/network-policy.yaml")" = 9 ] || problems+=("network-policy: $(count '^kind: NetworkPolicy$' "$OUT/network-policy.yaml") NetworkPolicies, want 9")
grep -q -F 'kubernetes.io/metadata.name: ingress-nginx' "$OUT/network-policy.yaml" || problems+=("network-policy: networkPolicy.gatewayFrom not used")
grep -q -F 'port: 30500' "$OUT/network-policy.yaml" || problems+=("network-policy: networkPolicy.extraEgress not used")
if render np-external -f "$ROOT/ci/helm-values/external-services.yaml" --set networkPolicy.enabled=true; then
  [ "$(count '^kind: NetworkPolicy$' "$OUT/np-external.render")" = 6 ] || problems+=("external services: $(count '^kind: NetworkPolicy$' "$OUT/np-external.render") NetworkPolicies, want 6")
  for port in 5432 6380 465; do
    grep -q "port: $port\$" "$OUT/np-external.render" || problems+=("external services: no egress to port $port")
  done
else
  problems+=("helm template: $(cat "$OUT/np-external.err")")
fi
report network-policy "off by default; on: the flows of docs/install.md per component"

# Chart.yaml kubeVersion: the newest API the templates use is autoscaling/v2 (1.23)
problems=()
refused kube-1.22 'requires kubeVersion' --kube-version 1.22.0 || problems+=("Kubernetes 1.22 accepted")
render kube-1.23 --kube-version 1.23.0 || problems+=("Kubernetes 1.23 refused: $(cat "$OUT/kube-1.23.err")")
report kube-version "1.23 and later"

# CORS follows the domain (config/cors.php reads APP_URL / FRONTEND_URL) plus cors.allowedOrigins;
# TLS with an existing Secret (ingress.tls.mode=secret); gateway.affinity replaces placement.affinity
# for the gateway only
problems=()
grep -q -F 'CORS_ALLOWED_ORIGINS: "https://portal.acme-cloud.example,https://status.acme-cloud.example"' "$ext" || problems+=("cors.allowedOrigins not in CORS_ALLOWED_ORIGINS")
grep -q -F 'CORS_ALLOWED_ORIGINS: ""' "$OUT/secrets-default.render" || problems+=("defaults: CORS_ALLOWED_ORIGINS not empty")
shared="$OUT/shared-storage.yaml"
grep -q '^      secretName: acme-console-tls$' "$shared" || problems+=("shared-storage: the Ingress does not use the TLS Secret")
grep -q '^type: kubernetes.io/tls$' "$shared" && problems+=("shared-storage: a TLS Secret created for mode secret")
refused tls-secret-unnamed 'ingress.tls.mode=secret: set ingress.tls.secretName' --set ingress.tls.mode=secret || problems+=("mode secret without secretName accepted")
[ "$(count 'topologyKey: topology\.kubernetes\.io/zone$' "$shared")" = 1 ] || problems+=("shared-storage: gateway.affinity not on the gateway alone")
report console "CORS origins, TLS from an existing Secret, per-component affinity"

# Bootstrap Job (bootstrap.tenancySetup): the multi-tenant edition converts an installation that
# ran single-tenant (tenancy:setup) before the administrator check, with the longer deadline; the
# single-tenant edition and tenancySetup=false do not; values without the key (reused from a
# release before it) convert like true
problems=()
boot() {
  local name=$1
  shift
  render "$name" --show-only templates/bootstrap-job.yaml "$@" || problems+=("$name: $(cat "$OUT/$name.err")")
}
boot boot-single
boot boot-tenancy --set edition=multi-tenant
boot boot-tenancy-flag --set tenancy.enabled=true
boot boot-tenancy-off --set edition=multi-tenant --set bootstrap.tenancySetup=false
boot boot-tenancy-reused --set edition=multi-tenant --set bootstrap.tenancySetup=null
setup='timeout 480 php artisan tenancy:setup --force || echo "runr-bootstrap: run php artisan tenancy:setup"'
migrate='timeout 120 php artisan tenancy:migrate || echo "runr-bootstrap: tenant migrations incomplete (run php artisan tenancy:migrate)"'
for n in boot-tenancy boot-tenancy-flag boot-tenancy-reused; do
  grep -q -F -- "$setup" "$OUT/$n.render" || { problems+=("$n: no tenancy:setup step"); continue; }
  grep -q '^  activeDeadlineSeconds: 1200$' "$OUT/$n.render" || problems+=("$n: activeDeadlineSeconds not 1200")
  at_setup=$(grep -n -F -- "$setup" "$OUT/$n.render" | head -1 | cut -d: -f1)
  at_admin=$(grep -n -F 'set +e' "$OUT/$n.render" | head -1 | cut -d: -f1)
  [ "$at_setup" -lt "$at_admin" ] || problems+=("$n: tenancy:setup after the administrator check")
done
for n in boot-tenancy boot-tenancy-flag boot-tenancy-reused boot-tenancy-off; do
  grep -q -F -- "$migrate" "$OUT/$n.render" || { problems+=("$n: no tenancy:migrate step"); continue; }
  at_migrate=$(grep -n -F -- "$migrate" "$OUT/$n.render" | head -1 | cut -d: -f1)
  at_toggle=$(grep -n -F 'toggle Billing' "$OUT/$n.render" | head -1 | cut -d: -f1)
  [ "$at_toggle" -lt "$at_migrate" ] || problems+=("$n: tenancy:migrate before the module toggles")
done
for n in boot-single boot-tenancy-off; do
  grep -q -F 'tenancy:setup' "$OUT/$n.render" && problems+=("$n: tenancy:setup rendered")
done
grep -q -F 'tenancy:migrate' "$OUT/boot-single.render" && problems+=("boot-single: tenancy:migrate rendered")
grep -q '^  activeDeadlineSeconds: 600$' "$OUT/boot-single.render" || problems+=("boot-single: activeDeadlineSeconds not 600")
grep -q '^  activeDeadlineSeconds: 720$' "$OUT/boot-tenancy-off.render" || problems+=("boot-tenancy-off: activeDeadlineSeconds not 720")
report bootstrap "multi-tenant: tenant migrations after the module toggles, tenancy:setup before the administrator check (also for values without bootstrap.tenancySetup), single-tenant and tenancySetup=false: no conversion"

# Hook Jobs (migrations, bootstrap) carry the module switches of the values as explicit env: a
# pre-upgrade hook runs before the new ConfigMap is applied, so through envFrom runr-config an
# upgrade that switches billing on would migrate with billing off (no billing tables in the
# tenant schemas). hook_env prints how often each value follows "- name: <key>" (3 = every Job).
problems=()
hook_env() {
  { grep -A1 -E "^ *- name: $2$" "$1" || true; } | sed -n -E 's/^ *value: "?([^"]*)"?$/\1/p' | sort | uniq -c | sed -E 's/^ +//'
}
if render hooks-on --show-only templates/migration-job.yaml --show-only templates/bootstrap-job.yaml \
    --set edition=multi-tenant --set billing.enabled=true --set billing.currency=EUR --set ticket.enabled=false \
  && render hooks-default --show-only templates/migration-job.yaml --show-only templates/bootstrap-job.yaml; then
  for want in "TENANCY_ENABLED=3 true" "AUTH_PROVIDER_ENABLED=3 true" "TICKET_ENABLED=3 false" "BILLING_ENABLED=3 true" "BILLING_CURRENCY=3 EUR"; do
    got=$(hook_env "$OUT/hooks-on.render" "${want%%=*}")
    [ "$got" = "${want#*=}" ] || problems+=("switched on: ${want%%=*} is '${got}', want '${want#*=}' (the 2 migration Jobs and the bootstrap Job)")
  done
  for want in "TENANCY_ENABLED=3 false" "TICKET_ENABLED=3 true" "BILLING_ENABLED=3 false" "BILLING_CURRENCY=3 USD"; do
    got=$(hook_env "$OUT/hooks-default.render" "${want%%=*}")
    [ "$got" = "${want#*=}" ] || problems+=("defaults: ${want%%=*} is '${got}', want '${want#*=}'")
  done
else
  problems+=("helm template: $(cat "$OUT/hooks-on.err" "$OUT/hooks-default.err" 2>/dev/null || true)")
fi
report hook-env "the migration and bootstrap Jobs take the module switches from the values, not from the ConfigMap"

# Values of a release before these keys (`helm upgrade --reuse-values` keeps the old chart's values,
# without them): the chart renders what that release ran — same resources, a ReadWriteOnce API
# volume with its colocated pods, passwords from runr-secret
problems=()
reused=(--set api.storage.accessModes=null --set api.affinity=null --set gateway.affinity=null --set ui.affinity=null
  --set cors=null --set networkPolicy=null --set database.postgres.storageClassName=null
  --set database.password=null --set database.existingSecret=null --set database.existingSecretPasswordKey=null
  --set redis.username=null --set redis.password=null --set redis.existingSecret=null --set redis.existingSecretPasswordKey=null
  --set mail.username=null --set mail.password=null --set mail.existingSecret=null --set mail.existingSecretPasswordKey=null --set mail.encryption=null)
if render reused-keys "${reused[@]}"; then
  [ "$(count '^kind:' "$OUT/reused-keys.render")" = "$(count '^kind:' "$OUT/secrets-default.render")" ] || problems+=("another set of resources than the defaults")
  [ "$(count '^ +runr/storage-group: api$' "$OUT/reused-keys.render")" -ge 7 ] || problems+=("the API volume's pods are not colocated")
  [ "$(grep -A3 '^ *- name: DB_PASSWORD$' "$OUT/reused-keys.render" | count 'name: "runr-secret"' -)" -ge 6 ] || problems+=("DB_PASSWORD not from runr-secret")
else
  problems+=("helm template: $(cat "$OUT/reused-keys.err")")
fi
report reused-values "values without the new keys render the earlier setup"

# NOTES.txt (what `helm install` prints): rendered from a copy of the chart, where it becomes a
# ConfigMap value
mkdir -p "$OUT/notes" && cp -r "$ROOT/helm" "$OUT/notes/runr"
{ echo '{{- define "runr.notes-check" -}}'; cat "$ROOT/helm/templates/NOTES.txt"; echo '{{- end -}}'; } >"$OUT/notes/runr/templates/_notes-check.tpl"
rm "$OUT/notes/runr/templates/NOTES.txt"
printf '%s\n' 'apiVersion: v1' 'kind: ConfigMap' 'metadata:' '  name: notes-check' 'data:' '  notes: |' '    {{- include "runr.notes-check" . | nindent 4 }}' \
  >"$OUT/notes/runr/templates/zz-notes-check.yaml"
notes() { helm template runr "$OUT/notes/runr" --namespace runr --set domain=console.acme-cloud.example "$@" --show-only templates/zz-notes-check.yaml 2>&1 || true; }
problems=()
text=$(notes)
grep -q -F 'php artisan runr:horizon-link' <<<"$text" || problems+=("defaults: no Horizon sign-in command")
grep -q -F 'ReadWriteOnce: the API, Horizon, scheduler and Reverb pods run on the volume' <<<"$text" || problems+=("defaults: no storage note")
text=$(notes -f "$ROOT/ci/helm-values/ha.yaml")
grep -q -F 'api.pdb: no PodDisruptionBudget for runr-api' <<<"$text" || problems+=("ha: no note on the skipped API budget")
text=$(notes -f "$ROOT/ci/helm-values/operator-secret.yaml")
grep -q -F "kubectl get secret runr-admin -n runr -o jsonpath='{.data.password}'" <<<"$text" || problems+=("operator-secret: no runr-admin command")
grep -q -F 'DB_PASSWORD=' <<<"$text" && problems+=("operator-secret: DB_PASSWORD asked for although database.existingSecret is set")
text=$(notes -f "$ROOT/ci/helm-values/shared-storage.yaml")
grep -q -F 'TLS: your Secret acme-console-tls' <<<"$text" || problems+=("shared-storage: no TLS note")
report notes "Horizon link, storage, skipped API budget, runr-admin, TLS Secret"

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
