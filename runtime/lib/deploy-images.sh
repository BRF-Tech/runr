# deploy-images: Build RunR images + distribute to K8s nodes via DaemonSet
# Problems and fixes:
#   - buildx --load multi-manifest tar → ctr import fails → use --output type=docker,dest=
#   - docker pull --platform manifest problem → buildx with FROM (caddy buildx inline)
#   - kubectl cp sets mode 600 → busybox httpd cannot read it → chmod 644 is required
#   - K8s DNS does not resolve in the nsenter host ns → download in an initContainer, import with nsenter
#   - a base image the build host's Docker already holds (e.g. an old ghcr.io/brf-tech/runr/reqs/caddy
#     pull) was built on instead of the registry's → every build runs with --pull

_deploy_build_dir=""
_DEPLOY_TAR_DIR="${SCRIPT_DIR}/../deploy"

# ── Image Build ──────────────────────────────────────────────────────────────

_deploy_build_images() {
  _deploy_build_dir="$_DEPLOY_TAR_DIR"
  mkdir -p "$_deploy_build_dir"
  local root_dir="${SCRIPT_DIR}/.."
  local cache_flag=""
  [ "$_deploy_no_cache" = "1" ] && cache_flag="--no-cache"

  # Version: the tag if there is one, otherwise main-<hash>
  _deploy_app_version=$(resolve_app_version "$root_dir")
  echo -e "  version : ${CYAN}${_deploy_app_version}${NC}"

  echo -e "${BOLD}[1/3] Building the API image (linux/amd64)...${NC}"
  # runr://docs/{page} of the MCP server (docs/mcp.md): the image carries docs/ as resources/docs
  mkdir -p "${root_dir}/api/resources/docs" && cp "${root_dir}"/docs/*.md "${root_dir}/api/resources/docs/"
  docker buildx build --pull --platform linux/amd64 \
    --file "${root_dir}/api/Dockerfile.prod" \
    --output "type=docker,dest=${_deploy_build_dir}/api.tar" \
    --build-arg "APP_VERSION=${_deploy_app_version}" \
    --provenance=false --sbom=false $cache_flag \
    -t "ghcr.io/brf-tech/runr/api:latest" \
    "${root_dir}/api" 2>&1
  echo -e "${GREEN}  API OK${NC}"

  echo -e "${BOLD}[2/3] Building the UI image (linux/amd64)...${NC}"
  docker buildx build --pull --platform linux/amd64 \
    --output "type=docker,dest=${_deploy_build_dir}/ui.tar" \
    --provenance=false --sbom=false $cache_flag \
    --build-arg VITE_WS_PROXY=true \
    --build-arg "VITE_REVERB_KEY=runr-key" \
    --build-arg "VITE_TENANCY_ENABLED=${_deploy_tenancy}" \
    --build-arg "VITE_AUTH_PROVIDER_ENABLED=${_deploy_auth_provider}" \
    --build-arg "VITE_TICKET_ENABLED=${_deploy_ticket}" \
    -t "ghcr.io/brf-tech/runr/ui:latest" \
    "${root_dir}/vue" 2>&1
  echo -e "${GREEN}  UI OK${NC}"

  echo -e "${BOLD}[3/3] Preparing the gateway (caddy) image...${NC}"
  docker buildx build --pull --platform linux/amd64 \
    --output "type=docker,dest=${_deploy_build_dir}/gateway.tar" \
    --provenance=false --sbom=false $cache_flag \
    -t "ghcr.io/brf-tech/runr/reqs/caddy:2-alpine" \
    - <<'CADDY_EOF' 2>&1
FROM ghcr.io/brf-tech/runr/reqs/caddy:2-alpine
CADDY_EOF
  echo -e "${GREEN}  Gateway OK${NC}"

  echo ""
  echo -e "${BOLD}Image sizes (under deploy/):${NC}"
  ls -lh "${_deploy_build_dir}"/*.tar | awk '{print "  " $NF " → " $5}'
}

# ── Load existing tars (for --skip-build) ────────────────────────────────────

_deploy_load_existing_tars() {
  _deploy_build_dir="$_DEPLOY_TAR_DIR"
  _deploy_app_version=$(resolve_app_version "${SCRIPT_DIR}/..")
  echo -e "  version : ${CYAN}${_deploy_app_version}${NC}"
  echo -e "${BOLD}Checking existing tar files...${NC}"
  local missing=0
  for f in api.tar ui.tar gateway.tar; do
    if [ -f "${_deploy_build_dir}/${f}" ]; then
      echo -e "  ${GREEN}✓${NC} ${f} ($(du -h "${_deploy_build_dir}/${f}" | cut -f1))"
    else
      echo -e "  ${RED}✗${NC} ${f} not found"
      missing=1
    fi
  done
  if [ "$missing" = "1" ]; then
    echo -e "${RED}Tar files are missing! Run 'ci.sh build-deploy' first.${NC}"
    exit 1
  fi
}

# ── Image Server (temporary pod + service) ───────────────────────────────────

_deploy_create_image_server() {
  local ns="$_deploy_namespace"

  echo -e "${BOLD}Creating the image server...${NC}"
  kubectl apply -f - <<EOF
apiVersion: v1
kind: Pod
metadata:
  name: runr-image-server
  namespace: ${ns}
  labels:
    app: runr-image-server
spec:
  containers:
  - name: server
    image: ghcr.io/brf-tech/runr/reqs/busybox:latest
    command: ["sh", "-c", "httpd -f -p 80 -h /data"]
    ports:
    - containerPort: 80
    volumeMounts:
    - name: images
      mountPath: /data
  volumes:
  - name: images
    emptyDir: {}
---
apiVersion: v1
kind: Service
metadata:
  name: runr-image-server
  namespace: ${ns}
spec:
  selector:
    app: runr-image-server
  ports:
  - port: 80
    targetPort: 80
EOF

  kubectl wait --for=condition=Ready pod/runr-image-server -n "$ns" --timeout=60s

  echo -e "${BOLD}Uploading tar files...${NC}"
  for f in api.tar ui.tar gateway.tar; do
    kubectl cp "${_deploy_build_dir}/${f}" "${ns}/runr-image-server:/data/${f}"
    echo "  ${f} uploaded"
  done

  # CRITICAL: kubectl cp keeps the local file mode (600)
  # busybox httpd needs world-readable files → chmod 644 is required
  kubectl exec -n "$ns" runr-image-server -- chmod 644 /data/api.tar \
    /data/ui.tar /data/gateway.tar

  echo -e "${GREEN}  Image server ready${NC}"
}

# ── DaemonSet: Download + ctr import ─────────────────────────────────────────

_deploy_create_importer() {
  local ns="$_deploy_namespace"

  echo -e "${BOLD}Creating the image importer DaemonSet...${NC}"
  kubectl apply -f - <<EOF
apiVersion: apps/v1
kind: DaemonSet
metadata:
  name: runr-image-importer
  namespace: ${ns}
spec:
  selector:
    matchLabels:
      app: runr-image-importer
  template:
    metadata:
      labels:
        app: runr-image-importer
    spec:
      hostPID: true
      tolerations:
      - operator: Exists
      initContainers:
      - name: download
        image: ghcr.io/brf-tech/runr/reqs/redis:7-alpine
        command:
        - sh
        - -c
        - |
          SRV=http://runr-image-server.${ns}.svc.cluster.local
          for f in api.tar ui.tar gateway.tar; do
            wget -O /host-tmp/runr-\${f} \${SRV}/\${f}
          done
          ls -lh /host-tmp/runr-*.tar
        volumeMounts:
        - name: host-tmp
          mountPath: /host-tmp
      containers:
      - name: importer
        image: ghcr.io/brf-tech/runr/reqs/redis:7-alpine
        securityContext:
          privileged: true
        command:
        - nsenter
        - "--target"
        - "1"
        - "--mount"
        - "--uts"
        - "--ipc"
        - "--pid"
        - "--"
        - sh
        - -c
        - |
          for f in /tmp/runr-api.tar /tmp/runr-ui.tar /tmp/runr-gateway.tar; do
            echo "Importing \${f}..."
            ctr -n k8s.io images import "\${f}" 2>&1
          done
          rm -f /tmp/runr-api.tar /tmp/runr-ui.tar /tmp/runr-gateway.tar
          echo "IMPORT_DONE"
          sleep 3600
      volumes:
      - name: host-tmp
        hostPath:
          path: /tmp
          type: Directory
EOF
}

# ── Wait for the import to finish ────────────────────────────────────────────

_deploy_wait_importer() {
  local ns="$_deploy_namespace"

  # Wait for the DaemonSet to be scheduled
  echo -e "${BOLD}Waiting for the DaemonSet to be scheduled...${NC}"
  local sched_wait=0
  local expected=0
  while [ "$sched_wait" -lt 30 ]; do
    expected=$(kubectl get ds runr-image-importer -n "$ns" -o jsonpath='{.status.desiredNumberScheduled}' 2>/dev/null || echo 0)
    [ "$expected" -gt 0 ] && break
    sleep 3
    sched_wait=$((sched_wait + 3))
  done

  echo -e "${BOLD}Waiting for the image import on ${expected} nodes...${NC}"

  local elapsed=0
  while [ "$elapsed" -lt 600 ]; do
    local done_count
    done_count=$(kubectl logs -n "$ns" -l app=runr-image-importer -c importer --tail=1 2>/dev/null \
      | grep -c "IMPORT_DONE" || true)
    if [ "$done_count" -ge "$expected" ]; then
      echo -e "${GREEN}  ${done_count}/${expected} nodes done${NC}"
      return 0
    fi
    echo "  ${done_count}/${expected} nodes done, waiting..."
    sleep 10
    elapsed=$((elapsed + 10))
  done

  echo -e "${RED}Timeout: ${elapsed}s passed, not all nodes finished${NC}"
  return 1
}

# ── Main push function ───────────────────────────────────────────────────────

_deploy_push_images() {
  _deploy_create_image_server
  _deploy_create_importer
  _deploy_wait_importer
  _images_cleanup
}

# ── Cleanup ──────────────────────────────────────────────────────────────────

_images_cleanup() {
  local ns="${_deploy_namespace:-runr}"
  kubectl delete ds runr-image-importer -n "$ns" --ignore-not-found >/dev/null 2>&1 || true
  kubectl delete pod runr-image-server -n "$ns" --ignore-not-found >/dev/null 2>&1 || true
  kubectl delete svc runr-image-server -n "$ns" --ignore-not-found >/dev/null 2>&1 || true
}

# ── Registry push ─────────────────────────────────────────────────────────────

_deploy_registry_push() {
  local target="$1"
  local registry="${2:-ghcr.io/brf-tech/runr}"

  if [ "$target" = "api" ] || [ "$target" = "all" ]; then
    local api_tag="${registry}/api:latest"
    docker load -i "${_DEPLOY_TAR_DIR}/api.tar"
    docker tag "ghcr.io/brf-tech/runr/api:latest" "$api_tag" 2>/dev/null || true
    echo -e "${BOLD}Pushing $api_tag ...${NC}"
    docker push "$api_tag"
    echo -e "${GREEN}  API push OK${NC}"
  fi

  if [ "$target" = "ui" ] || [ "$target" = "all" ]; then
    local ui_tag="${registry}/ui:latest"
    docker load -i "${_DEPLOY_TAR_DIR}/ui.tar"
    docker tag "ghcr.io/brf-tech/runr/ui:latest" "$ui_tag" 2>/dev/null || true
    echo -e "${BOLD}Pushing $ui_tag ...${NC}"
    docker push "$ui_tag"
    echo -e "${GREEN}  UI push OK${NC}"
  fi
}
