# cmd_deploy / cmd_upgrade — deploy/upgrade RunR on a K8s cluster
# deploy:  first install (namespace + secret + build + helm + install + admin)
# upgrade: update an existing install (build + helm + migrate)

# ── State ──
_deploy_kubeconfig=""
_deploy_domain=""
_deploy_namespace="runr"
_deploy_tenancy="false"
_deploy_auth_provider="false"
_deploy_ticket="false"
_deploy_billing="false"
_deploy_admin_email=""
_deploy_admin_pass=""
_deploy_skip_build=""
_deploy_skip_images=""
_deploy_build_only=""
_deploy_no_cache=""
_deploy_reinstall=""
_deploy_hpa="false"
_deploy_pdb="true"
_deploy_replicas_api=""
_deploy_replicas_gateway=""
_deploy_replicas_ui=""
_deploy_app_version=""

_deploy_cleanup() {
  _images_cleanup 2>/dev/null || true
  [ -n "$_deploy_build_dir" ] && rm -rf "$_deploy_build_dir" 2>/dev/null || true
}

_deploy_parse_args() {
  for arg in "$@"; do
    case "$arg" in
      --kubeconfig=*)       _deploy_kubeconfig="${arg#*=}" ;;
      --domain=*)           _deploy_domain="${arg#*=}" ;;
      --namespace=*)        _deploy_namespace="${arg#*=}" ;;
      --tenancy)            _deploy_tenancy="true" ;;
      --no-tenancy)         _deploy_tenancy="false" ;;
      --auth-provider)      _deploy_auth_provider="true" ;;
      --no-auth-provider)   _deploy_auth_provider="false" ;;
      --ticket)             _deploy_ticket="true" ;;
      --no-ticket)          _deploy_ticket="false" ;;
      --billing)            _deploy_billing="true" ;;
      --no-billing)         _deploy_billing="false" ;;
      --admin-email=*)      _deploy_admin_email="${arg#*=}" ;;
      --admin-pass=*)       _deploy_admin_pass="${arg#*=}" ;;
      --skip-build)         _deploy_skip_build="1" ;;
      --skip-images)        _deploy_skip_images="1" ;;
      --build-only)         _deploy_build_only="1" ;;
      --no-cache)           _deploy_no_cache="1" ;;
      --reinstall)          _deploy_reinstall="1" ;;
      --hpa)                _deploy_hpa="true" ;;
      --no-hpa)             _deploy_hpa="false" ;;
      --pdb)                _deploy_pdb="true" ;;
      --no-pdb)             _deploy_pdb="false" ;;
      --replicas-api=*)     _deploy_replicas_api="${arg#*=}" ;;
      --replicas-gateway=*) _deploy_replicas_gateway="${arg#*=}" ;;
      --replicas-ui=*)      _deploy_replicas_ui="${arg#*=}" ;;
      *) echo -e "${RED}Unknown flag: $arg${NC}"; exit 1 ;;
    esac
  done
}

_deploy_validate() {
  local ok=1
  if [ -z "$_deploy_kubeconfig" ]; then
    echo -e "${RED}--kubeconfig is required${NC}"; ok=0
  elif [ ! -f "$_deploy_kubeconfig" ]; then
    echo -e "${RED}Kubeconfig not found: $_deploy_kubeconfig${NC}"; ok=0
  fi
  [ -z "$_deploy_domain" ] && echo -e "${RED}--domain is required${NC}" && ok=0
  for tool in docker kubectl helm pwgen openssl; do
    command -v "$tool" >/dev/null 2>&1 || { echo -e "${RED}$tool not found${NC}"; ok=0; }
  done
  [ "$ok" = "0" ] && exit 1
  export KUBECONFIG="$_deploy_kubeconfig"
}

# upgrade only checks the kubeconfig + namespace
_upgrade_validate() {
  local ok=1
  if [ -z "$_deploy_kubeconfig" ]; then
    echo -e "${RED}--kubeconfig is required${NC}"; ok=0
  elif [ ! -f "$_deploy_kubeconfig" ]; then
    echo -e "${RED}Kubeconfig not found: $_deploy_kubeconfig${NC}"; ok=0
  fi
  for tool in docker kubectl helm; do
    command -v "$tool" >/dev/null 2>&1 || { echo -e "${RED}$tool not found${NC}"; ok=0; }
  done
  [ "$ok" = "0" ] && exit 1
  export KUBECONFIG="$_deploy_kubeconfig"
}

_deploy_test_connectivity() {
  echo -e "\n${BOLD}── Cluster Connection ──${NC}"
  if ! kubectl get nodes -o wide 2>&1; then
    echo -e "${RED}Could not connect to the cluster!${NC}"; exit 1
  fi
  local node_count
  node_count=$(kubectl get nodes --no-headers 2>/dev/null | wc -l | tr -d ' ')
  echo -e "${GREEN}  ${node_count} nodes found${NC}"
}

_deploy_create_namespace() {
  echo -e "\n${BOLD}── Namespace ──${NC}"
  for ns in "$_deploy_namespace" "${_deploy_namespace}-projects"; do
    if kubectl get ns "$ns" >/dev/null 2>&1; then
      echo "  $ns exists"
      kubectl label namespace "$ns" app.kubernetes.io/managed-by=Helm --overwrite >/dev/null 2>&1
      kubectl annotate namespace "$ns" \
        meta.helm.sh/release-name=runr \
        meta.helm.sh/release-namespace="$_deploy_namespace" --overwrite >/dev/null 2>&1
    else
      kubectl create namespace "$ns"
      kubectl label namespace "$ns" app.kubernetes.io/managed-by=Helm
      kubectl annotate namespace "$ns" \
        meta.helm.sh/release-name=runr \
        meta.helm.sh/release-namespace="$_deploy_namespace"
      echo -e "  ${GREEN}$ns created${NC}"
    fi
  done
}

_deploy_generate_secrets() {
  echo -e "\n${BOLD}── Secret ──${NC}"
  local ns="$_deploy_namespace"
  if kubectl get secret runr-secret -n "$ns" >/dev/null 2>&1; then
    echo -e "  ${YELLOW}runr-secret already exists, skipping${NC}"
    return 0
  fi
  local app_key="base64:$(openssl rand -base64 32)"
  local reverb_secret db_password admin_password
  reverb_secret=$(pwgen -s 32 1)
  db_password=$(pwgen -s 48 1)
  # First administrator (admin) password: runr:install → AdminSeeder uses it (ADMIN_PASSWORD).
  # --admin-pass if given, otherwise random; shown in the summary.
  admin_password="${_deploy_admin_pass:-$(pwgen -s 20 1)}"
  kubectl create secret generic runr-secret \
    --namespace="$ns" \
    --from-literal=APP_KEY="$app_key" \
    --from-literal=REVERB_APP_SECRET="$reverb_secret" \
    --from-literal=DB_PASSWORD="$db_password" \
    --from-literal=ADMIN_PASSWORD="$admin_password"
  echo -e "  ${GREEN}runr-secret created${NC}"
}

# ADMIN_PASSWORD from runr-secret (empty if missing) — for the summary
_deploy_admin_password_from_secret() {
  kubectl get secret runr-secret -n "$_deploy_namespace" \
    -o jsonpath='{.data.ADMIN_PASSWORD}' 2>/dev/null | base64 -d 2>/dev/null || true
}

_deploy_build_and_push() {
  # --skip-images: the images are already on the nodes, skip build+push entirely
  if [ -n "$_deploy_skip_images" ]; then
    echo -e "\n${YELLOW}  --skip-images: images already present, skipping build+push${NC}"
    return 0
  fi

  # Build stage
  if [ -n "$_deploy_skip_build" ]; then
    echo -e "\n${YELLOW}  --skip-build: skipping the build, existing tars will be used${NC}"
    _deploy_load_existing_tars
  else
    echo -e "\n${BOLD}── Image Build ──${NC}"
    _deploy_build_images
  fi

  # --build-only: skip the push
  if [ -n "$_deploy_build_only" ]; then
    echo -e "\n${GREEN}  --build-only: tar files ready (under deploy/). Run again to push.${NC}"
    return 0
  fi

  # Push stage
  echo -e "\n${BOLD}── Image Push (to K8s nodes) ──${NC}"
  _deploy_push_images
}

_deploy_helm() {
  echo -e "\n${BOLD}── Helm Upgrade ──${NC}"
  local -a helm_args=(
    --namespace "$_deploy_namespace"
    --set "domain=$_deploy_domain"
    --set "namespace=$_deploy_namespace"
    --set "createNamespace=true"
    --set "secret.create=false"
    --set "ingress.enabled=false"
    --set "projectNamespace=${_deploy_namespace}-projects"
    --set "tenancy.enabled=$_deploy_tenancy"
    --set "authProvider.enabled=$_deploy_auth_provider"
    --set "ticket.enabled=$_deploy_ticket"
    --set "billing.enabled=$_deploy_billing"
    --set "database.postgres.fixPgdata=true"
    --set "api.storage.fixPermissions=true"
    --set "podColocation=true"
    --set "bootstrap.enabled=false"
    --set "api.image.pullPolicy=IfNotPresent"
    --set "ui.image.pullPolicy=IfNotPresent"
    --set "gateway.image.pullPolicy=IfNotPresent"
    --set "gateway.hpa.enabled=$_deploy_hpa"
    --set "api.hpa.enabled=$_deploy_hpa"
    --set "ui.hpa.enabled=$_deploy_hpa"
    --set "gateway.pdb.enabled=$_deploy_pdb"
    --set "api.pdb.enabled=$_deploy_pdb"
    --set "ui.pdb.enabled=$_deploy_pdb"
    --set "redis.pdb.enabled=$_deploy_pdb"
    --set "database.postgres.pdb.enabled=$_deploy_pdb"
    --set "deployTimestamp=$(date +%s)"
    --timeout 10m
  )
  # APP_VERSION: use the one resolved during the build, otherwise resolve it
  if [ -z "$_deploy_app_version" ]; then
    _deploy_app_version=$(resolve_app_version "${SCRIPT_DIR}/..")
  fi
  helm_args+=(--set "appVersion=${_deploy_app_version}")
  [ -n "$_deploy_replicas_api" ] && helm_args+=(--set "api.replicas=$_deploy_replicas_api")
  [ -n "$_deploy_replicas_gateway" ] && helm_args+=(--set "gateway.replicas=$_deploy_replicas_gateway")
  [ -n "$_deploy_replicas_ui" ] && helm_args+=(--set "ui.replicas=$_deploy_replicas_ui")

  helm upgrade --install runr "${SCRIPT_DIR}/../helm" "${helm_args[@]}" 2>&1
  echo -e "${GREEN}  Helm upgrade done${NC}"
}

_deploy_wait_pods() {
  echo -e "\n${BOLD}── Waiting for Pods ──${NC}"
  local ns="$_deploy_namespace"
  for dep in api gateway ui horizon scheduler reverb; do
    echo -n "  runr-${dep}..."
    if kubectl rollout status "deployment/runr-${dep}" -n "$ns" --timeout=180s >/dev/null 2>&1; then
      echo -e " ${GREEN}OK${NC}"
    else
      echo -e " ${YELLOW}PENDING${NC}"
    fi
  done
  echo ""
  kubectl get pods -n "$ns" --field-selector=status.phase!=Succeeded
}

# runr:install → migrate + AdminSeeder + SettingsSeeder + executor:sync
_deploy_run_install() {
  echo -e "\n${BOLD}── RunR Install ──${NC}"
  local ns="$_deploy_namespace"
  local api_pod
  api_pod=$(kubectl get pod -n "$ns" -l app=runr-api -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)
  if [ -z "$api_pod" ]; then
    echo -e "${RED}API pod not found!${NC}"; return 1
  fi
  kubectl wait --for=condition=ready "pod/$api_pod" -n "$ns" --timeout=120s >/dev/null 2>&1

  local install_args="--demo"
  [ "$_deploy_reinstall" = "1" ] && install_args="$install_args --reinstall"
  kubectl exec -n "$ns" "$api_pod" -- php artisan runr:install $install_args 2>&1

  # Tenancy: create the demo/test tenant + test user
  if [ "$_deploy_tenancy" = "true" ]; then
    echo -e "  ${BOLD}Tenancy seed...${NC}"
    kubectl exec -n "$ns" "$api_pod" -- php artisan db:seed \
      --class='Modules\\Tenancy\\Database\\Seeders\\TenancySeeder' --force 2>&1 || true
  fi

  # Admin: create the admin account only when tenancy is enabled
  if [ "$_deploy_tenancy" = "true" ]; then
    if [ -z "$_deploy_admin_email" ] || [ -z "$_deploy_admin_pass" ]; then
      echo -e "${RED}  Tenancy is enabled — a central admin account is required.${NC}"
      echo -e "${RED}  Please add --admin-email=X --admin-pass=Y.${NC}"
      return 1
    fi
    _deploy_set_admin "$ns" "$api_pod"
  else
    if [ -n "$_deploy_admin_email" ]; then
      echo -e "${YELLOW}  Tenancy is disabled — --admin-email is not used; the administrator account is 'admin' (password: runr-secret ADMIN_PASSWORD, shown in the summary).${NC}"
      echo -e "${YELLOW}  Users can create accounts on the register page.${NC}"
    else
      echo -e "${GREEN}  Administrator account 'admin' (password in the summary). Accounts can be created on the register page.${NC}"
    fi
  fi
}

# for upgrade: only migrate + tenancy migrate + executor sync
_deploy_run_migrate() {
  echo -e "\n${BOLD}── Migrate ──${NC}"
  local ns="$_deploy_namespace"
  local api_pod
  api_pod=$(kubectl get pod -n "$ns" -l app=runr-api -o jsonpath='{.items[0].metadata.name}' 2>/dev/null)
  if [ -z "$api_pod" ]; then
    echo -e "${RED}API pod not found!${NC}"; return 1
  fi
  kubectl wait --for=condition=ready "pod/$api_pod" -n "$ns" --timeout=120s >/dev/null 2>&1

  kubectl exec -n "$ns" "$api_pod" -- php artisan migrate --force 2>&1
  if [ "$_deploy_tenancy" = "true" ]; then
    kubectl exec -n "$ns" "$api_pod" -- php artisan tenancy:migrate 2>&1 || true
  fi
  kubectl exec -n "$ns" "$api_pod" -- php artisan executor:sync 2>&1 || true
  echo -e "${GREEN}  Migration done${NC}"
}

_deploy_set_admin() {
  local ns="$1" api_pod="$2"
  kubectl exec -n "$ns" "$api_pod" -- php artisan tinker --execute="
    \$u = App\Models\User::where('username','admin')->first();
    if (\$u) {
      \$u->email = '${_deploy_admin_email}';
      \$u->password = bcrypt('${_deploy_admin_pass}');
      \$u->is_admin = true;
      \$u->save();
    }
  " >/dev/null 2>&1
  echo -e "${GREEN}  Admin: ${_deploy_admin_email}${NC}"
}

_deploy_summary() {
  local mode="${1:-deploy}"
  echo ""
  echo -e "${GREEN}════════════════════════════════════════${NC}"
  if [ "$mode" = "upgrade" ]; then
    echo -e "${GREEN}  RunR upgraded successfully!${NC}"
  else
    echo -e "${GREEN}  RunR deployed successfully!${NC}"
  fi
  echo -e "${GREEN}════════════════════════════════════════${NC}"
  echo "  Namespace: ${_deploy_namespace}"
  echo "  Domain:    ${_deploy_domain}"
  if [ "$mode" = "deploy" ] && [ "$_deploy_tenancy" = "true" ]; then
    echo "  Admin:     ${_deploy_admin_email} / ${_deploy_admin_pass}"
  elif [ "$mode" = "deploy" ]; then
    local admin_pw
    admin_pw=$(_deploy_admin_password_from_secret)
    if [ -n "$admin_pw" ]; then
      echo "  Admin:     admin / ${admin_pw}"
    else
      echo "  Admin:     admin / (no ADMIN_PASSWORD in runr-secret — the password generated in the runr:install output)"
    fi
  fi
  [ "$_deploy_tenancy" = "true" ] && echo "  Tenancy:   enabled"
  [ "$_deploy_auth_provider" = "true" ] && echo "  AuthProv:  enabled"
  [ "$_deploy_ticket" = "true" ] && echo "  Ticket:    enabled"
  [ "$_deploy_billing" = "true" ] && echo "  Billing:   enabled"
  [ "$_deploy_hpa" = "true" ] && echo "  HPA:       enabled"
  [ "$_deploy_pdb" = "true" ] && echo "  PDB:       enabled"
  echo ""
}

# ── First install: namespace + secret + build + helm + install + admin ──
cmd_deploy() {
  trap _deploy_cleanup EXIT
  _deploy_parse_args "$@"
  _deploy_validate
  _deploy_test_connectivity
  _deploy_create_namespace
  _deploy_generate_secrets
  _deploy_build_and_push
  _deploy_helm
  _deploy_wait_pods
  _deploy_run_install
  _deploy_summary "deploy"
}

# ── Upgrade: build + helm upgrade + migrate (no namespace/secret/admin) ──
cmd_upgrade() {
  trap _deploy_cleanup EXIT
  _deploy_parse_args "$@"
  _upgrade_validate

  # no domain given: read it from the existing helm release
  if [ -z "$_deploy_domain" ]; then
    _deploy_domain=$(helm get values runr -n "$_deploy_namespace" -o json 2>/dev/null | grep -o '"domain":"[^"]*"' | cut -d'"' -f4)
    if [ -z "$_deploy_domain" ]; then
      echo -e "${RED}--domain is required (could not read it from the existing release)${NC}"; exit 1
    fi
    echo -e "${YELLOW}  Domain taken from the existing release: $_deploy_domain${NC}"
  fi

  # read the tenancy/auth state from the existing release (unless overridden)
  local current_values
  current_values=$(helm get values runr -n "$_deploy_namespace" -o json 2>/dev/null || echo "{}")
  if ! printf '%s\n' "$@" | grep -q -- '--tenancy\|--no-tenancy'; then
    _deploy_tenancy=$(echo "$current_values" | grep -o '"enabled":[a-z]*' | head -1 | grep -o 'true\|false' || echo "false")
  fi
  if ! printf '%s\n' "$@" | grep -q -- '--ticket\|--no-ticket'; then
    _deploy_ticket=$(echo "$current_values" | grep -o '"ticket":{[^}]*}' | grep -o '"enabled":[a-z]*' | grep -o 'true\|false' || echo "false")
  fi
  if ! printf '%s\n' "$@" | grep -q -- '--billing\|--no-billing'; then
    _deploy_billing=$(echo "$current_values" | grep -o '"billing":{[^}]*}' | grep -o '"enabled":[a-z]*' | grep -o 'true\|false' || echo "false")
  fi

  _deploy_test_connectivity
  _deploy_build_and_push

  # --build-only: stop here
  if [ -n "$_deploy_build_only" ]; then
    return 0
  fi

  _deploy_helm
  _deploy_wait_pods
  _deploy_run_migrate
  _deploy_summary "upgrade"
}

# ── Build only (no kubeconfig/cluster needed) ──
cmd_build_deploy() {
  _deploy_parse_args "$@"
  echo -e "\n${BOLD}── Build Only ──${NC}"
  _deploy_build_images
  echo -e "\n${GREEN}Tar files ready. Next:${NC}"
  echo -e "  ${BOLD}MVP push:${NC}  ci.sh upgrade --skip-build --kubeconfig=... [other flags]"
  echo -e "  ${BOLD}Registry:${NC} ci.sh registry-push api|ui|all [REGISTRY]"
}

# ── Registry push ──
cmd_registry_push() {
  local target="${1:-all}"
  local registry="${2:-ghcr.io/brf-tech/runr}"
  _deploy_registry_push "$target" "$registry"
  echo -e "\n${GREEN}Registry push done: $registry${NC}"
}

cmd_deploy_status() {
  local ns="runr"
  for arg in "$@"; do
    case "$arg" in
      --kubeconfig=*) export KUBECONFIG="${arg#*=}" ;;
      --namespace=*)  ns="${arg#*=}" ;;
    esac
  done
  echo -e "${BOLD}── RunR Deploy Status ──${NC}"
  kubectl get pods,svc,hpa,pdb -n "$ns" 2>&1
}
