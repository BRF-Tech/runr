# Git deploy e2e (docs/git-deploy.md).
#
# cmd_test_git_deploy     — `ci.sh test-git-deploy [cmd_test flags]`: Kind + Knative (cmd_test) running
#                           only the git deploy suite of `php artisan test:e2e --suite=git-deploy`:
#                             git-remote-push   `git push runr main` → version → deploy → HTTP body,
#                                               and a non-fast-forward push is refused
#                             git-forgejo-push  a push to a connected Forgejo repository → deploy
#                             git-preview       a pull request → preview <slug>-pr-<n> → HTTP body,
#                                               RunR comment and commit status on Forgejo
#                             git-preview-close closing the request deletes the preview
# prepare_git_deploy_e2e  — starts a throwaway Forgejo container (its own name, never an existing
#                           Forgejo) on the compose network of the API, creates a user and a token
#                           and writes them to /tmp/runr-forgejo.json in the API container.
# cleanup_git_deploy_e2e  — removes that container and its volume (called by cmd_test's cleanup).
#
# KIND_LOCK               — one Kind cluster at a time on a shared host (default /tmp/runr-kind.lock).
# FORGEJO_IMAGE           — Forgejo image (default codeberg.org/forgejo/forgejo:9).

_git_e2e_forgejo=""

cmd_test_git_deploy() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=git-deploy "$@"
}

prepare_git_deploy_e2e() {
  local image="${FORGEJO_IMAGE:-codeberg.org/forgejo/forgejo:9}"
  local name="runr-e2e-forgejo-$$"
  local alias="forgejo.e2e.internal"
  local user="runr"
  local password
  password="$(head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"

  echo ""
  echo "Git deploy e2e: throwaway Forgejo (${name})..."

  local api_container network
  api_container="$(docker compose ps -q api)"
  network="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$api_container" | tr ' ' '\n' | grep -E '_default$' | head -1)"
  if [ -z "$network" ]; then
    echo -e "${RED}  compose network of the API not found${NC}"
    return 1
  fi

  docker run -d --name "$name" --network "$network" --network-alias "$alias" \
    -e USER_UID=1000 -e USER_GID=1000 \
    -e FORGEJO__security__INSTALL_LOCK=true \
    -e FORGEJO__database__DB_TYPE=sqlite3 \
    -e FORGEJO__server__ROOT_URL="http://${alias}:3000/" \
    -e FORGEJO__server__HTTP_PORT=3000 \
    -e FORGEJO__server__DISABLE_SSH=true \
    -e FORGEJO__service__DISABLE_REGISTRATION=true \
    -e FORGEJO__webhook__ALLOWED_HOST_LIST='*' \
    -e FORGEJO__webhook__SKIP_TLS_VERIFY=true \
    -e FORGEJO__repository__DEFAULT_BRANCH=main \
    "$image" >/dev/null
  _git_e2e_forgejo="$name"

  # Ready when the API container reaches it over the network
  local ready=0
  for _ in $(seq 1 60); do
    if docker compose exec -T api curl -sf "http://${alias}:3000/api/healthz" >/dev/null 2>&1; then
      ready=1
      break
    fi
    sleep 2
  done
  if [ "$ready" = "0" ]; then
    echo -e "${RED}  Forgejo did not start${NC}"
    docker logs --tail 50 "$name" || true
    return 1
  fi

  docker exec -u git "$name" forgejo admin user create --admin --username "$user" \
    --password "$password" --email "${user}@example.com" --must-change-password=false >/dev/null
  local token
  token="$(docker exec -u git "$name" forgejo admin user generate-access-token --username "$user" \
    --token-name runr-e2e --scopes all --raw | tail -1 | tr -d '\r\n')"
  if [ -z "$token" ]; then
    echo -e "${RED}  Forgejo access token could not be created${NC}"
    return 1
  fi

  # The API reaches Forgejo at the alias, Forgejo reaches the API at its compose service name
  local details
  details="$(mktemp /tmp/runr-forgejo.XXXXXX)"
  printf '{"url":"http://%s:3000","user":"%s","token":"%s","webhook_base":"http://api"}\n' "$alias" "$user" "$token" > "$details"
  docker compose cp "$details" api:/tmp/runr-forgejo.json
  rm -f "$details"

  echo "  Forgejo http://${alias}:3000 (user ${user}) on ${network}"
}

cleanup_git_deploy_e2e() {
  [ -n "$_git_e2e_forgejo" ] || return 0
  echo "Removing the Forgejo e2e container ${_git_e2e_forgejo}"
  docker rm -fv "$_git_e2e_forgejo" >/dev/null 2>&1 || true
  _git_e2e_forgejo=""
}
