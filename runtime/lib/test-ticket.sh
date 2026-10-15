# cmd_test_ticket — Ticket module e2e tests (API + WS mock, no K8s required)

cmd_test_ticket() {
  export TICKET_ENABLED=true
  # Single-tenant flows (one organization's queue), like `ci.sh test`; tickets on a multi-tenant
  # stack are covered by the Playwright stack suite (vue/e2e/tickets.spec.ts, E2E_TENANCY=true)
  export TENANCY_ENABLED=false
  local api_port="${API_PORT:-8060}"
  local base_url="http://localhost:${api_port}"

  echo -e "${BOLD}Ticket E2E Test (API + WS mock)${NC}"
  echo ""

  cd "${SCRIPT_DIR}/.."

  echo "Starting Docker Compose (api, horizon, scheduler, reverb, mailpit) [TICKET_ENABLED=true]..."
  # An empty APP_KEY= stops the API container (test.sh)
  ensure_compose_app_key
  docker compose up -d --build api horizon scheduler reverb mailpit

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

  # ci.sh runs with set -e: capture the exit code, or a failed run ends before the summary
  local exit_code=0
  docker compose exec -T api php artisan test:e2e-ticket \
    --base-url=http://localhost || exit_code=$?

  echo ""
  if [ "$exit_code" = "0" ]; then
    echo -e "${GREEN}Ticket e2e tests passed.${NC}"
  else
    echo -e "${RED}Ticket e2e tests failed.${NC}"
  fi

  return $exit_code
}