# cmd_test_upgrade — upgrade of a Docker Compose install (compose/, compose/README.md → Updating):
# the release being upgraded from (--from, default main) runs on PostgreSQL and gets realistic data
# (an administrator, a member, projects with environment variables and a code version, API tokens
# — one of them with 0.4.x scopes — and settings); then the images of this checkout start on the
# same database the way the README updates an install. Asserts: the migrations run cleanly, every
# user signs in and keeps the access they had (the RBAC of 0.5 fails closed), environment variables
# are encrypted at rest and read back unchanged, existing tokens work (scoped ones stay scoped),
# settings survive. API-only, no Kubernetes.
#
#   ci.sh test-upgrade [--from=REF] [--tenancy] [--keep] [--skip-build]
#
#   --from=REF     git ref of the old release (default main)
#   --from-dir=DIR a checkout or unpacked release of the old version instead of --from
#   --tenancy      multi-tenant install: a tenant with an owner, a "member" and a "viewer", the
#                  projects in the tenant; the upgrade also runs the tenant migrations
#   --keep         leave the stack and the images for a look afterwards
#   --skip-build   reuse runr-upgrade-api:from / :to from an earlier run
#
# Env: UPGRADE_API_PORT (127.0.0.1 port of the API, default 18060),
#      UPGRADE_PROJECT (Compose project name, default runr-upgrade)

_UP_FAILS=0
_UP_PASSES=0

_up_check() {
  local label="$1"; shift
  if "$@"; then
    _UP_PASSES=$((_UP_PASSES + 1))
    echo -e "  ${GREEN}✓${NC} ${label}"
  else
    _UP_FAILS=$((_UP_FAILS + 1))
    echo -e "  ${RED}✗${NC} ${label}"
  fi
}

# _up_api METHOD PATH TOKEN [JSON] [TENANT] → body on stdout; the status code is kept in a file
# (callers often run it in a $(...) subshell): _up_status / _up_status_is / _up_2xx read it
_up_api() {
  local method="$1" path="$2" token="$3" data="${4:-}" tenant="${5:-}"
  local args=(-s -o "${_UP_WORK}/body" -w '%{http_code}' -X "$method" -H 'Accept: application/json')
  [ -n "$token" ] && args+=(-H "Authorization: Bearer ${token}")
  [ -n "$tenant" ] && args+=(-H "X-Tenant-ID: ${tenant}")
  [ -n "$data" ] && args+=(-H 'Content-Type: application/json' --data "$data")
  curl "${args[@]}" "${_UP_BASE}${path}" >"${_UP_WORK}/status" 2>/dev/null || echo 000 >"${_UP_WORK}/status"
  cat "${_UP_WORK}/body"
}

_up_status() { cat "${_UP_WORK}/status"; }
_up_status_is() { [ "$(_up_status)" = "$1" ]; }
_up_2xx() { [[ "$(_up_status)" == 2* ]]; }
_up_absent() { ! grep -q -F -- "$1" "$2"; }
# _up_jq JSON [jq args] FILTER → true when the filter holds (jq -e), quietly
_up_jq() { local json="$1"; shift; jq -e "$@" >/dev/null 2>&1 <<<"$json"; }

_up_compose() {
  local dir="$1"; shift
  docker compose -p "$_UP_PROJECT" --project-directory "$dir" \
    -f "$dir/docker-compose.yml" -f "$dir/upgrade.override.yml" "$@"
}

_up_psql() {
  _up_compose "$_UP_DIR" exec -T postgres psql -U runr -d runr -tAc "$1"
}

_up_wait_api() {
  for _ in $(seq 1 120); do
    curl -sf "${_UP_BASE}/up" >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo -e "${RED}API unreachable at ${_UP_BASE}${NC}"
  _up_compose "$_UP_DIR" logs --tail 80 api
  return 1
}

_up_login() {
  _up_api POST /api/auth/login "" "{\"login\":\"$1\",\"password\":\"$2\"}" | jq -r '.token // empty'
}

# The API image of a tree (compose/build.sh: Dockerfile.prod, docs/ as runr://docs resources)
_up_build() {
  local tree="$1" tag="$2"
  mkdir -p "$tree/api/resources/docs"
  cp "$tree"/docs/*.md "$tree/api/resources/docs/" 2>/dev/null || true
  docker build --pull --file "$tree/api/Dockerfile.prod" --target base -t "$tag" "$tree/api"
}

# A compose directory for one release: its compose/ files, the install's .env / api.env and an
# override that names the image and publishes the API (no UI image needed: the suite is API-only)
_up_compose_dir() {
  local tree="$1" dir="$2" image="$3"
  mkdir -p "$dir"
  cp "$tree/compose/docker-compose.yml" "$dir/"
  cp "$tree"/compose/Caddyfile* "$dir/" 2>/dev/null || true
  cp "${_UP_WORK}/install.env" "$dir/.env"
  cp "${_UP_WORK}/api.env" "$dir/api.env"
  cat > "$dir/upgrade.override.yml" <<EOF
services:
  gateway:
    profiles: ["ui"]
  api:
    image: ${image}
    ports: ["127.0.0.1:${_UP_PORT}:80"]
  horizon:
    image: ${image}
  scheduler:
    image: ${image}
  reverb:
    image: ${image}
  migrator:
    image: ${image}
EOF
}

cmd_test_upgrade() {
  # The checks handle their failures themselves (ci.sh runs with set -e)
  set +e
  local from_ref="main" from_dir="" tenancy=false keep=false skip_build=false
  for arg in "$@"; do
    case "$arg" in
      --from=*)     from_ref="${arg#--from=}" ;;
      --from-dir=*) from_dir="${arg#--from-dir=}" ;;
      --tenancy)    tenancy=true ;;
      --keep)       keep=true ;;
      --skip-build) skip_build=true ;;
      *) echo "Unknown flag: $arg"; return 1 ;;
    esac
  done

  local root; root="$(cd "${SCRIPT_DIR}/.." && pwd)"
  _UP_PROJECT="${UPGRADE_PROJECT:-runr-upgrade}"
  _UP_PORT="${UPGRADE_API_PORT:-18060}"
  _UP_BASE="http://127.0.0.1:${_UP_PORT}"
  _UP_WORK="$(mktemp -d "${TMPDIR:-/tmp}/runr-upgrade.XXXXXX")"
  _UP_FAILS=0
  _UP_PASSES=0
  local img_from="runr-upgrade-api:from" img_to="runr-upgrade-api:to"

  # ── The old release: a tree of --from (git archive) or the checkout given with --from-dir ──
  local from_tree="${_UP_WORK}/from"
  if [ -n "$from_dir" ]; then
    from_tree="$(cd "$from_dir" && pwd)"
    from_ref="$from_tree"
  else
    mkdir -p "$from_tree"
    git -C "$root" archive "$from_ref" | tar -x -C "$from_tree" \
      || { echo -e "${RED}git archive ${from_ref} failed${NC}"; return 1; }
  fi
  echo -e "${BOLD}Compose upgrade test: ${from_ref} → this checkout (tenancy: ${tenancy})${NC}"

  if [ "$skip_build" = false ]; then
    echo "Building ${img_from} (${from_ref}) and ${img_to} (this checkout)..."
    _up_build "$from_tree" "$img_from" || return 1
    _up_build "$root" "$img_to" || return 1
  fi

  # The install's settings: kept over the upgrade, as an operator keeps .env and api.env
  local db_pass admin_pass app_key
  db_pass="$(head -c 18 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 20)"
  admin_pass="Admin-$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 12)"
  app_key="base64:$(head -c 32 /dev/urandom | base64)"
  cat > "${_UP_WORK}/install.env" <<EOF
DB_NAME=runr
DB_USER=runr
DB_PASS=${db_pass}
TENANCY_ENABLED=${tenancy}
AUTH_PROVIDER_ENABLED=false
TICKET_ENABLED=true
EOF
  tr -d '\r' < "${from_tree}/compose/api.env.example" | sed \
      -e "s|^APP_KEY=.*|APP_KEY=${app_key}|" \
      -e "s|^APP_URL=.*|APP_URL=${_UP_BASE}|" \
      -e "s|^FRONTEND_URL=.*|FRONTEND_URL=${_UP_BASE}|" \
      -e "s|^DB_PASSWORD=.*|DB_PASSWORD=${db_pass}|" \
      -e "s|^REVERB_APP_KEY=.*|REVERB_APP_KEY=upgrade-reverb-key|" \
      -e "s|^REVERB_APP_SECRET=.*|REVERB_APP_SECRET=upgrade-reverb-secret|" \
      > "${_UP_WORK}/api.env"
  printf '\nADMIN_PASSWORD=%s\n' "$admin_pass" >> "${_UP_WORK}/api.env"

  _UP_DIR="${_UP_WORK}/compose-from"
  _up_compose_dir "$from_tree" "$_UP_DIR" "$img_from"

  # Leftovers of an earlier run with --keep
  _up_compose "$_UP_DIR" down -v --remove-orphans >/dev/null 2>&1 || true

  echo "Starting ${from_ref} (PostgreSQL)..."
  _up_compose "$_UP_DIR" up -d postgres redis
  _up_compose "$_UP_DIR" run --rm migrator >"${_UP_WORK}/migrate-from.log" 2>&1 \
    || { cat "${_UP_WORK}/migrate-from.log"; echo -e "${RED}Migrations of ${from_ref} failed${NC}"; return 1; }
  _up_compose "$_UP_DIR" up -d api horizon scheduler reverb
  _up_wait_api || return 1
  # The first administrator (runr:install seeds the same without the cluster calls)
  _up_compose "$_UP_DIR" exec -T api php artisan db:seed --class=AdminSeeder --force >/dev/null
  _up_compose "$_UP_DIR" exec -T api php artisan db:seed --class=SettingsSeeder --force >/dev/null
  _up_compose "$_UP_DIR" cp "${SCRIPT_DIR}/lib/upgrade-seed.php" api:/tmp/upgrade-seed.php >/dev/null
  local region
  region="$(_up_compose "$_UP_DIR" exec -T api php /tmp/upgrade-seed.php | jq -r '.region_id')"
  [ -n "$region" ] && [ "$region" != null ] || { echo -e "${RED}No region seeded${NC}"; return 1; }

  # ── Data on the old release ──
  echo "Creating data on ${from_ref}..."
  local admin_token dana_token vic_token tenant="" ci_token reader_token
  admin_token="$(_up_login admin "$admin_pass")"
  [ -n "$admin_token" ] || { echo -e "${RED}Administrator login on ${from_ref} failed${NC}"; return 1; }

  _up_api PATCH /api/settings "$admin_token" '{"base_domain":"apps.acme-cloud.example","registration_enabled":false}' >/dev/null
  _up_2xx || { echo -e "${RED}Settings on ${from_ref}: $(_up_status)${NC}"; return 1; }

  local u
  for u in dana vic; do
    _up_api POST /api/users "$admin_token" "{\"name\":\"${u^}\",\"username\":\"${u}\",\"email\":\"${u}@acme-cloud.example\",\"password\":\"Pass-${u}-12345\"}" >/dev/null
    _up_2xx || { echo -e "${RED}Creating ${u} on ${from_ref}: $(_up_status) $(cat "${_UP_WORK}/body")${NC}"; return 1; }
  done

  if [ "$tenancy" = true ]; then
    tenant="$(_up_api POST /api/tenants "$admin_token" '{"name":"Acme Cloud","slug":"acme"}' | jq -r '.id // .tenant.id // empty')"
    [ -n "$tenant" ] || { echo -e "${RED}Tenant on ${from_ref}: $(_up_status) $(cat "${_UP_WORK}/body")${NC}"; return 1; }
    _up_api POST "/api/tenants/${tenant}/regions" "$admin_token" "{\"region_id\":\"${region}\",\"cpu_quota_m\":4000,\"memory_quota_mb\":8192}" >/dev/null
    _up_2xx || { echo -e "${RED}Tenant region on ${from_ref}: $(_up_status)${NC}"; return 1; }
    _up_api POST "/api/tenants/${tenant}/users" "$admin_token" '{"email":"dana@acme-cloud.example","role":"member"}' >/dev/null
    _up_api POST "/api/tenants/${tenant}/users" "$admin_token" '{"email":"vic@acme-cloud.example","role":"viewer"}' >/dev/null
  fi

  dana_token="$(_up_login dana Pass-dana-12345)"
  vic_token="$(_up_login vic Pass-vic-12345)"
  [ -n "$dana_token" ] && [ -n "$vic_token" ] || { echo -e "${RED}Member logins on ${from_ref} failed${NC}"; return 1; }

  local secret="pg-$(head -c 12 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 16)"
  local snippet caas
  snippet="$(_up_api POST /api/projects "$dana_token" "{\"name\":\"orders-api\",\"service_type\":\"xaas\",\"runtime\":\"python\",\"runtime_version\":\"3.12\",\"knative_region_id\":\"${region}\",\"config\":{\"cpu\":\"250m\",\"memory_mb\":128,\"timeout_seconds\":30,\"env_vars\":{\"LOG_LEVEL\":\"debug\",\"DATABASE_PASSWORD\":\"${secret}\"}}}" "$tenant" | jq -r '.data.id // .id // empty')"
  [ -n "$snippet" ] || { echo -e "${RED}Project on ${from_ref}: $(_up_status) $(cat "${_UP_WORK}/body")${NC}"; return 1; }
  caas="$(_up_api POST /api/projects "$dana_token" "{\"name\":\"storefront\",\"service_type\":\"caas\",\"knative_region_id\":\"${region}\",\"config\":{\"image\":\"nginx:1.27\",\"ports\":[80],\"cpu\":\"250m\",\"memory_mb\":128,\"env_vars\":{\"PAYMENT_TOKEN\":\"${secret}-pay\",\"MODE\":\"live\"}}}" "$tenant" | jq -r '.data.id // .id // empty')"
  [ -n "$caas" ] || { echo -e "${RED}Container project on ${from_ref}: $(_up_status) $(cat "${_UP_WORK}/body")${NC}"; return 1; }
  _up_api POST "/api/projects/${snippet}/code" "$dana_token" '{"code":"def handler(event, context):\n    return {\"orders\": []}\n","message":"orders v1"}' "$tenant" >/dev/null
  _up_2xx || { echo -e "${RED}Code version on ${from_ref}: $(_up_status) $(cat "${_UP_WORK}/body")${NC}"; return 1; }

  ci_token="$(_up_api POST /api/tokens "$admin_token" '{"name":"ci"}' | jq -r '.plain_token // empty')"
  reader_token="$(_up_api POST /api/tokens "$dana_token" '{"name":"dashboard","abilities":["projects:read"]}' "$tenant" | jq -r '.plain_token // empty')"
  [ -n "$ci_token" ] && [ -n "$reader_token" ] || { echo -e "${RED}API tokens on ${from_ref} failed${NC}"; return 1; }

  local projects_before plain_before
  projects_before="$(_up_api GET /api/projects "$dana_token" "" "$tenant" | jq -r '.data | length' 2>/dev/null)"
  # 0.4.x stores env vars as plain JSON: the encryption check below must start from there
  _up_compose "$_UP_DIR" exec -T postgres pg_dump -U runr -d runr --data-only >"${_UP_WORK}/dump-from.sql" 2>/dev/null
  plain_before="$(grep -c -F -- "$secret" "${_UP_WORK}/dump-from.sql" || true)"
  echo "  (before the upgrade the env var value is stored in plaintext in ${plain_before} row(s))"

  # ── Upgrade: the new images on the same database (compose/README.md → Updating: stop the
  # application containers, migrate with the new image, start the new release) ──
  echo ""
  echo "Upgrading to this checkout..."
  _UP_DIR="${_UP_WORK}/compose-to"
  _up_compose_dir "$root" "$_UP_DIR" "$img_to"
  _up_compose "$_UP_DIR" stop api horizon scheduler reverb >/dev/null 2>&1
  local migrator_rc=0
  _up_compose "$_UP_DIR" run --rm migrator >"${_UP_WORK}/migrate-to.log" 2>&1 || migrator_rc=$?
  _up_compose "$_UP_DIR" up -d api horizon scheduler reverb >"${_UP_WORK}/up-to.log" 2>&1 \
    || cat "${_UP_WORK}/up-to.log"
  _up_wait_api || return 1

  echo ""
  echo -e "${BOLD}Upgrade checks${NC}"
  _up_check "migrator of the new release exits 0" [ "$migrator_rc" = 0 ]
  [ "$migrator_rc" = 0 ] || tail -40 "${_UP_WORK}/migrate-to.log"
  local pending
  pending="$(_up_compose "$_UP_DIR" exec -T api php artisan migrate:status 2>&1 | grep -c -E 'Pending' || true)"
  _up_check "no pending central migration" [ "$pending" = 0 ]
  if [ "$tenancy" = true ]; then
    _up_check "tenant schema migrated (tenancy:migrate in the migrator)" \
      [ "$(_up_psql "SELECT count(*) FROM information_schema.tables WHERE table_schema <> 'public' AND table_name = 'project_secrets'" 2>/dev/null || echo 0)" -ge 1 ]
  fi
  _up_check "no project lost (${projects_before})" \
    [ "$(_up_api GET /api/projects "$dana_token" "" "$tenant" | jq -r '.data | length' 2>/dev/null)" = "$projects_before" ]

  # Every user signs in
  local admin2 dana2 vic2
  admin2="$(_up_login admin "$admin_pass")"
  _up_check "administrator signs in" [ -n "$admin2" ]
  dana2="$(_up_login dana Pass-dana-12345)"
  _up_check "member (dana) signs in" [ -n "$dana2" ]
  vic2="$(_up_login vic Pass-vic-12345)"
  _up_check "second user (vic) signs in" [ -n "$vic2" ]

  # Existing tokens (sessions and API tokens of the old release)
  _up_api GET /api/auth/me "$admin_token" >/dev/null
  _up_check "an old session token still works" _up_status_is 200
  _up_api GET /api/projects "$ci_token" "" "$tenant" >/dev/null
  _up_check "an old full-access API token still works" _up_status_is 200
  local listed
  listed="$(_up_api GET /api/projects "$reader_token" "" "$tenant" | jq -r '[.data[]?.name] | join(",")')"
  _up_check "an old projects:read token still lists the projects (${listed})" grep -q orders-api <<<"$listed"
  _up_api PUT "/api/projects/${snippet}" "$reader_token" '{"description":"changed by a read token"}' "$tenant" >/dev/null
  _up_check "an old projects:read token stays read-only ($(_up_status))" _up_status_is 403
  _up_check "0.4.x token scopes translated" [ "$(_up_psql "SELECT count(*) FROM personal_access_tokens WHERE abilities LIKE '%projects:read%'")" = 0 ]

  # Access: what everyone could do before (fail closed must not lock anyone out)
  local perms
  perms="$(_up_api GET /api/auth/permissions "$admin2")"
  _up_check "administrator keeps full access" _up_jq "$perms" '.platform_admin == true or .role == "owner"'
  perms="$(_up_api GET /api/auth/permissions "$dana2" "" "$tenant")"
  if [ "$tenancy" = true ]; then
    _up_check "tenant member keeps the member role ($(jq -r .role <<<"$perms" 2>/dev/null))" _up_jq "$perms" '.role == "member"'
  else
    _up_check "existing user becomes Admin ($(jq -r .role <<<"$perms" 2>/dev/null))" _up_jq "$perms" '.role == "admin"'
  fi
  _up_check "member keeps env.edit and projects.create" _up_jq "$perms" '(.permissions | index("env.edit")) and (.permissions | index("projects.create"))'

  local project
  project="$(_up_api GET "/api/projects/${snippet}" "$dana2" "" "$tenant")"
  _up_check "member reads the project" _up_status_is 200
  _up_check "plain env var reads back" _up_jq "$project" '[.. | objects | select(has("LOG_LEVEL")) | .LOG_LEVEL] | index("debug")'
  _up_check "secret-like env var reads back unchanged" _up_jq "$project" --arg s "$secret" '[.. | objects | select(has("DATABASE_PASSWORD")) | .DATABASE_PASSWORD] | index($s)'
  project="$(_up_api GET "/api/projects/${caas}" "$dana2" "" "$tenant")"
  _up_check "container env var reads back unchanged" _up_jq "$project" --arg s "${secret}-pay" '[.. | objects | select(has("PAYMENT_TOKEN")) | .PAYMENT_TOKEN] | index($s)'
  _up_api PUT "/api/projects/${snippet}" "$dana2" "{\"config\":{\"env_vars\":{\"LOG_LEVEL\":\"info\",\"DATABASE_PASSWORD\":\"${secret}\"}}}" "$tenant" >/dev/null
  _up_check "member edits env vars ($(_up_status))" _up_2xx
  _up_api PUT "/api/projects/${snippet}/secrets/API_SECRET" "$dana2" "{\"value\":\"${secret}-api\"}" "$tenant" >/dev/null
  _up_check "member adds a secret env var ($(_up_status))" _up_2xx
  local secrets_list
  secrets_list="$(_up_api GET "/api/projects/${snippet}/secrets" "$dana2" "" "$tenant")"
  _up_check "secret env vars are listed without their value" _up_absent "${secret}-api" <(printf '%s' "$secrets_list")
  _up_check "the secret env var is listed" grep -q API_SECRET <<<"$secrets_list"
  _up_api GET /api/projects "$vic2" "" "$tenant" >/dev/null
  _up_check "second user still lists projects ($(_up_status))" _up_status_is 200
  if [ "$tenancy" = true ]; then
    _up_api PUT "/api/projects/${snippet}" "$vic2" '{"description":"viewer edit"}' "$tenant" >/dev/null
    _up_check "tenant viewer stays read-only ($(_up_status))" _up_status_is 403
  fi

  # Encryption at rest: no plaintext secret left in the database (central and tenant schemas)
  _up_compose "$_UP_DIR" exec -T postgres pg_dump -U runr -d runr --data-only >"${_UP_WORK}/dump.sql" 2>/dev/null
  _up_check "the database dump still holds the projects" grep -q orders-api "${_UP_WORK}/dump.sql"
  _up_check "the database dump holds no plaintext env var or secret value" _up_absent "$secret" "${_UP_WORK}/dump.sql"

  # Settings survive
  local settings
  settings="$(_up_api GET /api/settings "$admin2")"
  _up_check "base domain kept ($(jq -r .base_domain <<<"$settings" 2>/dev/null))" _up_jq "$settings" '.base_domain == "apps.acme-cloud.example"'
  _up_check "registration stays off" _up_jq "$settings" '.registration_enabled == false'
  _up_check "existing region keeps the supply chain off" \
    [ "$(_up_psql "SELECT count(*) FROM region_supply_chain_settings WHERE knative_region_id = '${region}' AND NOT sbom_enabled AND NOT scan_enabled" 2>/dev/null || echo err)" = 1 ]

  # compose/README.md: every service of the new release reports healthy
  local svc unhealthy=""
  for _ in $(seq 1 30); do
    unhealthy=""
    for svc in api horizon scheduler reverb; do
      [ "$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$(_up_compose "$_UP_DIR" ps -q "$svc")" 2>/dev/null)" = healthy ] \
        || unhealthy="${unhealthy} ${svc}"
    done
    [ -z "$unhealthy" ] && break
    sleep 5
  done
  _up_check "api, horizon, scheduler and reverb report healthy${unhealthy:+ (not:${unhealthy})}" [ -z "$unhealthy" ]

  echo ""
  echo -e "  Passed: ${_UP_PASSES}  Failed: ${_UP_FAILS}"
  if [ "$keep" = true ]; then
    echo "Kept: docker compose -p ${_UP_PROJECT} (API ${_UP_BASE}, admin / ${admin_pass}), work dir ${_UP_WORK}"
  else
    _up_compose "$_UP_DIR" down -v --remove-orphans >/dev/null 2>&1 || true
    docker image rm "$img_from" "$img_to" >/dev/null 2>&1 || true
    [[ "${_UP_WORK}" == */runr-upgrade.* ]] && rm -rf -- "${_UP_WORK}"
  fi

  if [ "$_UP_FAILS" -gt 0 ]; then
    echo -e "${RED}Upgrade test failed.${NC}"
    return 1
  fi
  echo -e "${GREEN}Upgrade test passed.${NC}"
}
