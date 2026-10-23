# Global projects e2e (one project in two regions behind one global host name).
#
# cmd_test_global_projects — `ci.sh test-global-projects [cmd_test flags]`: Kind + Knative (cmd_test)
#                            and a second Kind cluster, running only the global-projects suite
#                            (test:e2e --suite=global-projects, E2eTestGlobalProjectsTrait):
#                             - cluster A is the e2e region's (cmd_test's cluster; KIND_WORKERS
#                               defaults to 1 here); cluster B (<A>-global) is one node with another
#                               service range (10.98.0.0/16) and becomes a second region through the
#                               API (Knative Serving installed there by RunR)
#                             - global DNS enabled on both regions: a PowerDNS server each, with a
#                               load balancer address from Cloud Provider KIND
#                             - a global CaaS project (nginx) in A (primary) and B: rollout to B,
#                               the global host answers through both Traefik addresses, the health
#                               routes, identical zones on both servers, both answer A's address
#                               (HA), A's copy removed → both answer B's address, proximity mode,
#                               the project deleted → its record and copies go
#                             - phase 2: a cron-only project (CronJob active in A, suspended in B), a
#                               gateway of B routing to a second project's copy in B, a custom domain
#                               routed by both regions, an image uploaded into A run by B from its own
#                               registry (copied through the control plane), KV and Postgres data
#                               services, a KV binding of A reached from B through the data link
#                               (redis-cli PING), a dedicated Postgres binding with a replica cluster
#                               in B (a row written in A read from B's replica, a row written in B
#                               through the link), a switchover to B, a failover back to A with B
#                               fenced and rejoining, B's GeoIP source (none, an unreachable URL,
#                               file)
#                            Two clusters, not two regions on one: each region's copy is told apart by
#                            its own Traefik address, agent and DNS server, which regions sharing a
#                            cluster share (one Traefik, one agent, one PowerDNS per cluster).
#                            Not on Kind: the zone's delegation and the ACME certificates (the wildcard,
#                            a custom domain's DNS-01 through the zone).
#
# The region agent with the global-health listener comes from this checkout (built here as
# ${REGISTRY}/agent:e2e-global and loaded into both clusters; GLOBAL_AGENT_IMAGE= keeps the
# published agent). PowerDNS (reqs/pdns-auth) is loaded into both clusters when the host has it.
# Phase 2 loads into both clusters: the data service images (test-bindings.sh; the CloudNativePG
# operator itself comes from ghcr.io as in the bindings suite), reqs/nginx (the projects and the data
# link's forwarder), the upload receiver and the transfer agent (runtime/image-upload 1.0.0 / 1.1.0:
# the upload into A, the copy into B) and gateway-auth; it saves the nginx image as an upload archive
# into api:/tmp/runr-global-images/nginx.tar.
#
# RUNR_E2E_GLOBAL_CASES — only these cases (comma-separated, e.g. gp-data-link,gp-postgres-replica):
#                        the suite adds the setup (zone, region B, DNS servers, the first project and
#                        its rollout) and the cases they build on (test:e2e --global-cases)
# KIND_LOCK            — one Kind run at a time on a shared host; defaults to /tmp/runr-kind.lock.
# GLOBAL_E2E_DIR       — where the upload archive is prepared on the host (default
#                        /tmp/runr-global-images-e2e).

_gp_target_cluster=""
_gp_agent_image="${GLOBAL_AGENT_IMAGE-${REGISTRY}/agent:e2e-global}"

# TEST_AFTER_KIND_CREATE of cmd_test: cluster B next to the region's cluster A (the Kind lock is
# held). cmd_test's locals (k8s_ver, kind_api_addr, kube_server_addr) are visible here.
global_projects_create_target() {
  local cluster="${1}-global" config status=0
  local version="${k8s_ver:-${K8S_VERSION:-1.32.0}}"

  echo ""
  echo "Creating Kind cluster ${cluster} (1 node, services 10.98.0.0/16): the second region..."
  config=$(mktemp /tmp/runr-kind-config.XXXXXX)
  cat > "$config" <<KINDEOF
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
networking:
  apiServerAddress: "${kind_api_addr:-127.0.0.1}"
  serviceSubnet: "10.98.0.0/16"
containerdConfigPatches:
  - |-
    [plugins."io.containerd.grpc.v1.cri".registry]
      config_path = "/etc/containerd/certs.d"
nodes:
  - role: control-plane
    kubeadmConfigPatches:
      - |
        kind: ClusterConfiguration
        apiServer:
          certSANs:
            - "localhost"
            - "127.0.0.1"
            - "host.docker.internal"
            - "${kube_server_addr:-127.0.0.1}"
KINDEOF
  _gp_target_cluster="$cluster"
  kind create cluster --name "$cluster" \
    --image "${REGISTRY}/reqs/kindest-node:v${version}" \
    --config "$config" || status=$?
  rm -f "$config"
  if [ "$status" != "0" ]; then
    echo -e "${RED}Kind cluster ${cluster} could not be created${NC}"
    return "$status"
  fi

  # Cloud Provider KIND leaves nodes labelled as control-plane out of its load balancers: B's only
  # node runs its Traefik and its PowerDNS
  docker exec "${cluster}-control-plane" kubectl --kubeconfig /etc/kubernetes/admin.conf \
    label node "${cluster}-control-plane" node.kubernetes.io/exclude-from-external-load-balancers- >/dev/null \
    || echo -e "${YELLOW}  ${cluster}-control-plane keeps its load balancer exclusion: B's services may get no address${NC}"
}

# TEST_BEFORE_E2E of cmd_test (the API stack runs, the current directory is the checkout): B gets
# the images A got, both get this checkout's agent and PowerDNS, and B's kubeconfig as the API
# container reaches it goes to api:/tmp/kubeconfig-global (--global-kubeconfig)
global_projects_prepare_target() {
  local cluster="$_gp_target_cluster" kubeconfig pdns="${REGISTRY}/reqs/pdns-auth:5.0.8" c
  [ -n "$cluster" ] || return 0

  preload_kind_images "$cluster"

  if [ -n "$_gp_agent_image" ]; then
    echo "  Building ${_gp_agent_image} (runtime/agent: the global-health listener)"
    if docker build -q -t "$_gp_agent_image" runtime/agent >/dev/null; then
      for c in "${_test_cluster_name}" "$cluster"; do
        kind load docker-image "$_gp_agent_image" --name "$c" >/dev/null 2>&1 \
          && echo "  ${_gp_agent_image} loaded into kind/${c}"
      done
    else
      echo -e "${YELLOW}  ${_gp_agent_image} not built: the regions run the published agent${NC}"
      _gp_agent_image=""
    fi
  fi

  if docker pull -q "$pdns" >/dev/null 2>&1; then
    for c in "${_test_cluster_name}" "$cluster"; do
      kind load docker-image "$pdns" --name "$c" >/dev/null 2>&1 && echo "  ${pdns} loaded into kind/${c}"
    done
  else
    echo -e "${YELLOW}  ${pdns} not pulled (is it mirrored? REQS_IMAGES of BRF-Tech/runr-runtimes): the nodes pull it${NC}"
  fi

  global_projects_prepare_phase2 "$cluster"

  kubeconfig=$(mktemp /tmp/runr-e2e-global-kubeconfig.XXXXXX)
  kind get kubeconfig --internal --name "$cluster" > "$kubeconfig"
  docker compose cp "$kubeconfig" api:/tmp/kubeconfig-global
  rm -f "$kubeconfig"
  echo "  kubeconfig of kind/${cluster} → api:/tmp/kubeconfig-global"

  export TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} --global-agent-image=${_gp_agent_image}"
}

# The phase-2 cases' images in both clusters and the upload archive in the API container (a failure
# here leaves the case that needs it to fail or skip with its reason; the run goes on)
global_projects_prepare_phase2() {
  local cluster="$1" c img work="${GLOBAL_E2E_DIR:-/tmp/runr-global-images-e2e}"
  local nginx="${REGISTRY}/reqs/nginx:1.27-alpine"
  local upload_tag="${REGISTRY}/image-upload:${IMAGE_UPLOAD_VERSION:-1.0.0}"
  local transfer_tag="${REGISTRY}/image-upload:${ORG_TRANSFER_AGENT_VERSION:-1.1.0}"
  local gateway_auth="${GATEWAY_AUTH_IMAGE:-${REGISTRY}/gateway-auth:1.0.0}"
  local archive_tag="runr-e2e/gp-nginx:upload"

  echo ""
  echo "Global projects phase 2: images of both clusters, the upload archive..."

  # data services (KV Redis, CloudNativePG's Postgres, the ops tools) — test-bindings.sh
  for c in "${_test_cluster_name}" "$cluster"; do
    preload_bindings_images "$c"
  done

  # the receiver of the upload into A, the transfer agent of the copy into B
  docker build -q --pull -t "${upload_tag}" "${SCRIPT_DIR}/image-upload" >/dev/null \
    || echo -e "${YELLOW}  ${upload_tag} not built: gp-uploaded-image fails${NC}"
  docker build -q --pull --build-arg "VERSION=${ORG_TRANSFER_AGENT_VERSION:-1.1.0}" -t "${transfer_tag}" "${SCRIPT_DIR}/image-upload" >/dev/null \
    || echo -e "${YELLOW}  ${transfer_tag} not built: gp-uploaded-image fails${NC}"
  # the gateway's auth service (a gateway of region B, gp-gateway)
  docker build -q -t "${gateway_auth}" "${SCRIPT_DIR}/gateway-auth" >/dev/null \
    || echo -e "${YELLOW}  ${gateway_auth} not built: the clusters pull it${NC}"

  pull_image "$nginx" || echo -e "${YELLOW}  ${nginx} not pulled (is it mirrored?): the nodes pull it${NC}"
  for c in "${_test_cluster_name}" "$cluster"; do
    for img in "$nginx" "$upload_tag" "$transfer_tag" "$gateway_auth"; do
      kind_load_image "$img" "$c" >/dev/null 2>&1 \
        && echo "  ${img} → kind/${c}" \
        || echo "  ${img} not loaded into kind/${c} (the node pulls it)"
    done
  done

  # the upload: a docker save archive of a one-platform image of nginx (port 80, its welcome page)
  mkdir -p "$work"
  if printf 'FROM %s\n' "$nginx" | docker build -q -t "$archive_tag" - >/dev/null \
    && docker save "$archive_tag" -o "${work}/nginx.tar"; then
    docker compose exec -T api rm -rf /tmp/runr-global-images >/dev/null 2>&1 || true
    docker compose cp "$work" api:/tmp/runr-global-images
    echo "  ${archive_tag} → api:/tmp/runr-global-images/nginx.tar ($(du -h "${work}/nginx.tar" | cut -f1))"
  else
    echo -e "${YELLOW}  the upload archive was not saved: gp-uploaded-image is skipped${NC}"
  fi
}

# Cluster B at the end of the run: deleted, or kept with A (--no-cleanup)
global_projects_delete_target() {
  local cluster="$_gp_target_cluster"
  [ -n "$cluster" ] || return 0
  if [ "${_test_cleanup_kind:-1}" != "1" ]; then
    echo -e "${YELLOW}Kind cluster ${cluster} kept (--no-cleanup): kind delete cluster --name ${cluster}${NC}"
    return 0
  fi
  echo "Deleting Kind cluster: ${cluster}"
  for _ in 1 2 3; do
    kind delete cluster --name "$cluster" 2>&1 | tail -2 || true
    kind get clusters 2>/dev/null | grep -qx "$cluster" || break
    sleep 10
  done
  if kind get clusters 2>/dev/null | grep -qx "$cluster"; then
    echo -e "${RED}Kind cluster ${cluster} could not be deleted: delete it with 'kind delete cluster --name ${cluster}'${NC}"
  fi
  _gp_target_cluster=""
}

# cmd_test's EXIT trap (_test_cleanup, test.sh) stops Cloud Provider KIND only when no other Kind
# cluster is left: B is deleted first, then test.sh's own trap runs (kept as _gp_test_cleanup).
if declare -F _test_cleanup >/dev/null && ! declare -F _gp_test_cleanup >/dev/null; then
  eval "_gp_test_cleanup() $(declare -f _test_cleanup | tail -n +2)"
fi
_test_cleanup() {
  kind_cleanup_begin "${_test_cluster_name:-runr-e2e-$$}"
  global_projects_delete_target
  _gp_test_cleanup
}

cmd_test_global_projects() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  # A: the control-plane and the region's dedicated worker; B: one node (the disk of two clusters)
  export KIND_WORKERS="${KIND_WORKERS:-1}"
  export TEST_AFTER_KIND_CREATE=global_projects_create_target
  export TEST_BEFORE_E2E=global_projects_prepare_target
  export TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} --global-kubeconfig=/tmp/kubeconfig-global"
  if [ -n "${RUNR_E2E_GLOBAL_CASES:-}" ]; then
    echo "  cases: ${RUNR_E2E_GLOBAL_CASES} (RUNR_E2E_GLOBAL_CASES; the suite adds the setup and what they need)"
    TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS} --global-cases=${RUNR_E2E_GLOBAL_CASES// /,}"
  fi
  cmd_test --suite=global-projects "$@"
}
