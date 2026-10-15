# cmd_test_tenancy — Tenancy e2e tests (API-only, no K8s required)

cmd_test_tenancy() {
  export TENANCY_ENABLED=true
  local api_port="${API_PORT:-8060}"
  local base_url="http://localhost:${api_port}"

  echo -e "${BOLD}Tenancy E2E Test (API-driven)${NC}"
  echo ""

  cd "${SCRIPT_DIR}/.."

  # Start services with tenancy enabled (an empty APP_KEY= stops the API container: test.sh)
  echo "Starting Docker Compose (api, horizon, scheduler, reverb, mailpit) [TENANCY_ENABLED=true]..."
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

  # Run tenancy e2e (26 test cases). ci.sh runs with set -e: capture the exit code, or a failed
  # run ends before the summary
  local exit_code=0
  docker compose exec -T api php artisan test:e2e-tenancy \
    --base-url=http://localhost || exit_code=$?

  echo ""
  if [ "$exit_code" = "0" ]; then
    echo -e "${GREEN}Tenancy e2e tests passed.${NC}"
  else
    echo -e "${RED}Tenancy e2e tests failed.${NC}"
  fi

  return $exit_code
}
