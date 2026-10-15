# cmd_test_authprovider — AuthProvider e2e tests (API-only, no K8s required)

cmd_test_authprovider() {
  export AUTH_PROVIDER_ENABLED=true
  # Single-tenant flows (registers its own user; the tenancy seed has a "test" user already),
  # like `ci.sh test`; the multi-tenant side is test-tenancy's
  export TENANCY_ENABLED=false
  local api_port="${API_PORT:-8060}"
  local base_url="http://localhost:${api_port}"

  echo -e "${BOLD}AuthProvider E2E Test (API-driven)${NC}"
  echo ""

  cd "${SCRIPT_DIR}/.."

  # Start services with auth provider enabled (an empty APP_KEY= stops the API container: test.sh)
  echo "Starting Docker Compose (api, horizon, scheduler, reverb, mailpit) [AUTH_PROVIDER_ENABLED=true]..."
  ensure_compose_app_key
  docker compose up -d --build api horizon scheduler reverb mailpit

  # Wait for API
  echo "Waiting for the API to be ready..."
  local api_ready=0
  for i in $(seq 1 30); do
    if curl -sf "${base_url}/up" >/dev/null 2>&1; then
      api_ready=1
      break
    fi
    sleep 1
  done

  if [ "$api_ready" = "0" ]; then
    echo -e "${RED}API unreachable (port ${api_port}). Exiting.${NC}"
    docker compose logs api
    exit 1
  fi
  echo -e "${GREEN}API ready.${NC}"
  echo ""

  # Run authprovider e2e
  # ci.sh runs with set -e: capture the exit code, or a failed run ends before the summary
  local exit_code=0
  docker compose exec -T api php artisan test:e2e-authprovider \
    --base-url=http://localhost || exit_code=$?

  echo ""
  if [ "$exit_code" = "0" ]; then
    echo -e "${GREEN}AuthProvider e2e tests passed.${NC}"
  else
    echo -e "${RED}AuthProvider e2e tests failed.${NC}"
  fi

  return $exit_code
}
