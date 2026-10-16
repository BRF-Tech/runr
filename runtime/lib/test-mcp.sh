# MCP e2e (docs/mcp.md): the RunR MCP server, its OAuth authorization server and hosted MCP
# servers behind the gateway, driven by an MCP client on the official MCP Python SDK.
#
# cmd_test_mcp   — `ci.sh test-mcp [cmd_test flags]`: Kind + Knative (cmd_test --suite=runtimes
#                  sets up the region — registry, Knative, Redis — and runs the runtime plugin
#                  suite), then mcp_after_e2e (TEST_AFTER_E2E hook of cmd_test) against that stack:
#                    1. prepare — administrator sign-in, a full and a read-only API token
#                    2. server  — initialize / Mcp-Session-Id, tools/list per token scope (the
#                                 read-only token does not see deploy), tools/call whoami and
#                                 projects_list, a schema error as a tool error, resources/read
#                                 runr://docs/mcp, resource templates, prompts, 401 +
#                                 protected resource metadata, GET /mcp → 405
#                    3. oauth   — metadata, dynamic client registration, authorize → consent API
#                                 approve, PKCE token exchange (wrong verifier refused, code works
#                                 once), the issued token on /mcp, connected clients, revocation
#                    4. hosted  — mcp_server_create (Python starter, token access, deploy, with
#                                 progress notifications)
#                    5. gateway — 401 without a token, tools/list + tools/call + whoami with a
#                                 token through /mcp/servers/<handle>, the call log, public mode,
#                                 OAuth mode (401 with the server's resource metadata); the
#                                 function URL refuses requests without the gateway signature
#
# The client runs in a container (python 3.13 + the MCP SDK, built here) that shares the network
# of the compose `api` container, so it reaches the API as http://localhost exactly like
# `php artisan test:e2e` — also under docker-in-docker (CI).
#
# The gateway forwards to the function URL https://<slug>.<knative domain>: the api container
# gets an /etc/hosts entry for it (Traefik's LoadBalancer IP on the kind network, which the api
# service joins) and MCP_GATEWAY_VERIFY_TLS=false (the region's Traefik answers with its default
# certificate in Kind).
#
# KIND_LOCK — one Kind cluster at a time on a shared host; defaults to /tmp/runr-kind.lock here
#             (test-runtimes.sh: acquire_kind_lock).

MCP_E2E_CLIENT_IMAGE="${MCP_E2E_CLIENT_IMAGE:-runr-mcp-e2e-client:latest}"
MCP_E2E_PYTHON_IMAGE="${MCP_E2E_PYTHON_IMAGE:-ghcr.io/brf-tech/runr/reqs/python:3.13-slim}"
MCP_E2E_SDK="${MCP_E2E_SDK:-mcp>=1.12,<2}"

cmd_test_mcp() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  export MCP_GATEWAY_VERIFY_TLS="${MCP_GATEWAY_VERIFY_TLS:-false}"
  export TEST_AFTER_E2E=mcp_after_e2e
  cmd_test --suite=runtimes "$@"
}

# The MCP client: runtime/lib/mcp_client.py on stdin, in the api container's network namespace.
_mcp_client() {
  local api_cid
  api_cid=$(docker compose ps -q api)
  docker run --rm -i --network "container:${api_cid}" "$MCP_E2E_CLIENT_IMAGE" \
    python - "$@" < "${SCRIPT_DIR}/lib/mcp_client.py"
}

_mcp_phase() {
  local title="$1"
  shift
  echo ""
  echo -e "${BOLD}── MCP: ${title} ──${NC}"
  if "$@"; then
    _mcp_passed=$((_mcp_passed + 1))
  else
    _mcp_failed=$((_mcp_failed + 1))
  fi
}

# The hosted server's function host → Traefik's LoadBalancer IP, inside the api container.
_mcp_route_function_host() {
  local kubeconfig="$1" function_url="$2"
  local host ip="" ns="${MCP_E2E_TRAEFIK_NAMESPACE:-knative-serving}" i
  host=$(echo "$function_url" | sed -E 's#^[a-z]+://([^/:]+).*#\1#')
  [ -z "$host" ] && return 1

  for i in $(seq 1 30); do
    ip=$(kubectl --kubeconfig "$kubeconfig" -n "$ns" get svc traefik \
      -o jsonpath='{.status.loadBalancer.ingress[0].ip}' 2>/dev/null || true)
    [ -n "$ip" ] && break
    sleep 2
  done
  if [ -z "$ip" ]; then
    echo -e "  ${RED}✗ Traefik has no LoadBalancer IP (namespace ${ns})${NC}"
    return 1
  fi

  docker compose exec -T -u root api sh -c "grep -v ' ${host}\$' /etc/hosts > /tmp/hosts.mcp; echo '${ip} ${host}' >> /tmp/hosts.mcp; cat /tmp/hosts.mcp > /etc/hosts"
  echo -e "  ${GREEN}✓${NC} ${host} → ${ip} (api container)"
}

# The function URL itself refuses a request without the gateway signature.
_mcp_direct_refused() {
  local url="$1"
  local code
  code=$(docker compose exec -T api curl -sk -o /dev/null -w '%{http_code}' -X POST \
    -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
    -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' "$url" 2>/dev/null || echo "000")
  if [ "$code" = "401" ]; then
    echo -e "  ${GREEN}✓${NC} the function URL refuses unsigned requests (401)"
  else
    echo -e "  ${RED}✗${NC} the function URL answered ${code} to an unsigned request"
    return 1
  fi
}

mcp_after_e2e() {
  local base_url="$1" kubeconfig="$2"
  local base="http://localhost"
  _mcp_passed=0
  _mcp_failed=0

  echo ""
  echo -e "${BOLD}MCP e2e — RunR MCP server, OAuth, hosted MCP servers (${base_url})${NC}"
  echo "  client image: ${MCP_E2E_CLIENT_IMAGE} (${MCP_E2E_PYTHON_IMAGE} + ${MCP_E2E_SDK})"
  printf 'FROM %s\nRUN pip install --no-cache-dir "%s"\n' "$MCP_E2E_PYTHON_IMAGE" "$MCP_E2E_SDK" \
    | docker build -q --pull -t "$MCP_E2E_CLIENT_IMAGE" - >/dev/null

  local admin_user admin_password env_file="${SCRIPT_DIR}/../api/.env.docker"
  admin_user=$(grep -E '^ADMIN_USERNAME=' "$env_file" 2>/dev/null | cut -d= -f2- || true)
  admin_password=$(grep -E '^ADMIN_PASSWORD=' "$env_file" 2>/dev/null | cut -d= -f2- || true)

  echo ""
  echo -e "${BOLD}── MCP: prepare ──${NC}"
  local exports
  if ! exports=$(_mcp_client prepare --base "$base" --admin-user "${admin_user:-admin}" --admin-password "${admin_password:-admin123}"); then
    echo -e "${RED}MCP e2e: preparation failed${NC}"
    return 1
  fi
  eval "$exports"

  _mcp_phase "RunR MCP server" _mcp_client server --base "$base" \
    --token "$MCP_E2E_FULL_TOKEN" --readonly-token "$MCP_E2E_READONLY_TOKEN"
  _mcp_phase "OAuth" _mcp_client oauth --base "$base" --console-token "$MCP_E2E_CONSOLE_TOKEN"

  echo ""
  echo -e "${BOLD}── MCP: hosted server ──${NC}"
  if exports=$(_mcp_client hosted --base "$base" --token "$MCP_E2E_FULL_TOKEN" \
      --console-token "$MCP_E2E_CONSOLE_TOKEN" --region "$MCP_E2E_REGION"); then
    _mcp_passed=$((_mcp_passed + 1))
    eval "$exports"

    if _mcp_route_function_host "$kubeconfig" "$MCP_E2E_FUNCTION_URL"; then
      _mcp_phase "gateway" _mcp_client gateway --base "$base" --token "$MCP_E2E_FULL_TOKEN" \
        --console-token "$MCP_E2E_CONSOLE_TOKEN" --project "$MCP_E2E_PROJECT" --url "$MCP_E2E_HOSTED_URL"
      _mcp_phase "function URL" _mcp_direct_refused "${MCP_E2E_FUNCTION_URL%/}${MCP_E2E_PATH}"
    else
      _mcp_failed=$((_mcp_failed + 1))
    fi
  else
    _mcp_failed=$((_mcp_failed + 1))
  fi

  echo ""
  if [ "$_mcp_failed" -gt 0 ]; then
    echo -e "${RED}MCP e2e: ${_mcp_failed} phase(s) failed, ${_mcp_passed} passed${NC}"
    return 1
  fi
  echo -e "${GREEN}MCP e2e: all ${_mcp_passed} phases passed${NC}"
}
