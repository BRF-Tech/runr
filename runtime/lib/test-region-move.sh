# Region move e2e (a region moved to another cluster and rolled back).
#
# cmd_test_region_move — `ci.sh test-region-move [cmd_test flags]`: Kind + Knative (cmd_test) and a
#                        second Kind cluster, running only the region-move suite (test:e2e
#                        --suite=region-move, E2eTestRegionMoveTrait):
#                         - cluster A is the e2e region's (cmd_test's cluster; KIND_WORKERS defaults
#                           to 1 here: the control-plane and the region's dedicated worker); cluster
#                           B (<A>-to) is one node with another service range (10.97.0.0/16), so its
#                           Zot gets another ClusterIP and the move another registry address
#                         - on A: a FaaS Python function with a pinned dependency (built by Kaniko
#                           into A's Zot) and two CaaS nginx projects, each called through A's
#                           Traefik address with its host
#                         - the move to B through the API: the built version is built again into B's
#                           Zot and deployed when built, migration-status reports every project
#                           (deployed / moved), the functions answer through B's Traefik address;
#                           one project is stopped on B, a new version builds on B (its Kaniko pod
#                           held Pending: B's node cordoned)
#                         - the rollback: A's kubeconfig, address, registry address and the rest of
#                           the saved cluster state are back, the build on B ends cancelled and its
#                           Job leaves B, the projects run on A again, the stopped one's Knative
#                           Service is removed from A (nothing serves unmetered); the projects'
#                           Knative Services leave B, a data service's Secret and B's installs stay
#                           there, and the operator's notification says what left; the report is empty
#                         - secret values (E2eTestRegionMoveSecretsTrait), on a Python function that
#                           answers their SHA-256: before the move, on A, one in the region's Secret,
#                           External Secrets Operator and a reference to a throwaway OpenBao dev
#                           store (a copy on each cluster at the same address), the region's OpenBao
#                           and one in it; the move copies the values to B (a new OpenBao there, its
#                           own unseal key and CA) and the function on B sees all three; values set
#                           while the region runs on B are there only; the rollback copies them back
#                           to A (the function on A sees the new ones, the region has A's OpenBao CA
#                           again) and removes the values Secret, the ExternalSecrets and the
#                           project's values, policy and role in B's OpenBao from B
#                        The DNS cut-over cannot happen on Kind: the functions are called through
#                        the address the function domain's record would name. Kind only (no
#                        --kubeconfig): the suite creates B next to A. Both clusters pull the
#                        External Secrets Operator and OpenBao images from ghcr.io (public reqs
#                        mirror) and the operator's chart from charts.external-secrets.io, as
#                        test-secrets does.
#
# KIND_LOCK            — one Kind run at a time on a shared host; this suite defaults it to
#                        /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock). Both clusters
#                        are created and deleted while the run holds it.

_rm_target_cluster=""

# TEST_AFTER_KIND_CREATE of cmd_test: cluster B, next to the region's cluster A (the Kind lock is
# held). cmd_test's locals (k8s_ver, kind_api_addr, kube_server_addr) are visible here.
region_move_create_target() {
  local cluster="${1}-to" config status=0
  local version="${k8s_ver:-${K8S_VERSION:-1.32.0}}"
  local etcd_patch=""
  if [ "${KIND_ETCD_IN_MEMORY:-0}" = "1" ]; then
    etcd_patch='        etcd:
          local:
            dataDir: /tmp/etcd'
  fi

  echo ""
  echo "Creating Kind cluster ${cluster} (1 node, services 10.97.0.0/16): the cluster the region moves to..."
  config=$(mktemp /tmp/runr-kind-config.XXXXXX)
  cat > "$config" <<KINDEOF
kind: Cluster
apiVersion: kind.x-k8s.io/v1alpha4
networking:
  apiServerAddress: "${kind_api_addr:-127.0.0.1}"
  serviceSubnet: "10.97.0.0/16"
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
${etcd_patch}
KINDEOF
  # (no taints: [] patch as on A with one worker: Kind untaints the node of a one-node cluster
  # itself, and fails when the taint is not there)
  # this run's cluster from here on (the cleanup deletes it, also half-created)
  _rm_target_cluster="$cluster"
  kind create cluster --name "$cluster" \
    --image "${REGISTRY}/reqs/kindest-node:v${version}" \
    --config "$config" || status=$?
  rm -f "$config"
  if [ "$status" != "0" ]; then
    echo -e "${RED}Kind cluster ${cluster} could not be created${NC}"
    return "$status"
  fi

  # Cloud Provider KIND leaves nodes labelled as control-plane out of its load balancers: B's only
  # node runs its Traefik
  docker exec "${cluster}-control-plane" kubectl --kubeconfig /etc/kubernetes/admin.conf \
    label node "${cluster}-control-plane" node.kubernetes.io/exclude-from-external-load-balancers- >/dev/null \
    || echo -e "${YELLOW}  ${cluster}-control-plane keeps its load balancer exclusion: B's Traefik may get no address${NC}"
}

# TEST_BEFORE_E2E of cmd_test (the API stack runs, the current directory is the checkout): B gets
# the images A got, and its kubeconfig as the API container reaches it goes to
# api:/tmp/kubeconfig-move (--move-kubeconfig)
region_move_prepare_target() {
  local cluster="$_rm_target_cluster" kubeconfig wrapper="${REGISTRY}/wrapper:${WRAPPER_VERSION:-1.1.0}"
  [ -n "$cluster" ] || return 0

  preload_kind_images "$cluster"
  # the universal wrapper the region's image pre-pull runs on every node (prepare_runtime_e2e
  # built it)
  if kind load docker-image "$wrapper" --name "$cluster" >/dev/null 2>&1; then
    echo "  ${wrapper} loaded into kind/${cluster}"
  fi

  kubeconfig=$(mktemp /tmp/runr-e2e-move-kubeconfig.XXXXXX)
  kind get kubeconfig --internal --name "$cluster" > "$kubeconfig"
  docker compose cp "$kubeconfig" api:/tmp/kubeconfig-move
  rm -f "$kubeconfig"
  echo "  kubeconfig of kind/${cluster} → api:/tmp/kubeconfig-move"
}

# Cluster B at the end of the run: deleted, or kept with A (--no-cleanup)
region_move_delete_target() {
  local cluster="$_rm_target_cluster"
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
  _rm_target_cluster=""
}

# cmd_test's EXIT trap (_test_cleanup, test.sh) stops Cloud Provider KIND only when no other Kind
# cluster is left: B is deleted first. The trap of this command runs the one below, which then runs
# test.sh's own (kept as _rm_test_cleanup).
if declare -F _test_cleanup >/dev/null && ! declare -F _rm_test_cleanup >/dev/null; then
  eval "_rm_test_cleanup() $(declare -f _test_cleanup | tail -n +2)"
fi
_test_cleanup() {
  kind_cleanup_begin "${_test_cluster_name:-runr-e2e-$$}"
  region_move_delete_target
  _rm_test_cleanup
}

cmd_test_region_move() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  # A: the control-plane and the region's dedicated worker; B: one node (the disk of two clusters)
  export KIND_WORKERS="${KIND_WORKERS:-1}"
  export TEST_AFTER_KIND_CREATE=region_move_create_target
  export TEST_BEFORE_E2E=region_move_prepare_target
  export TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} --move-kubeconfig=/tmp/kubeconfig-move"
  cmd_test --suite=region-move "$@"
}
