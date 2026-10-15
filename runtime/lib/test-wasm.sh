# WebAssembly e2e (runwasi / Spin on containerd).
#
# cmd_test_wasm      — `ci.sh test-wasm [cmd_test flags]`: Kind + Knative (cmd_test) running only the
#                      WebAssembly suite (test:e2e --suite=wasm, E2eTestWasmTrait): installs the spin
#                      shim into the Kind nodes through the API (WasmInstallService: wasm-installer
#                      DaemonSet, containerd config, RuntimeClass wasmtime-spin-v2, Knative features),
#                      deploys the Rust and JavaScript examples of examples/wasm as wasm-rust / wasm-js
#                      functions (build Job → Spin OCI artifact in the region registry), calls them,
#                      prints the cold start after scale-to-zero (WASM_COLD_STARTS runs, default 3,
#                      with the median), then deletes them and uninstalls WebAssembly support.
# prepare_wasm_e2e   — D-Bus in the Kind nodes and the image of RunR's Spin shim loaded into them
#                      (kind_load_wasm_shim), builds the wasm-rust and wasm-js build images
#                      (runtime/wasm-*), loads them into the Kind cluster (the build Job's image) and
#                      copies examples/wasm into the API container (/tmp/runr-examples/wasm).
#
# KIND_LOCK          — one Kind cluster at a time on a shared host: defaults to /tmp/runr-kind.lock here
#                      (the lock test-runtimes uses; see acquire_kind_lock in test-runtimes.sh).
# WASM_E2E_LANGS     — build images to prepare (default "rust js": the languages the suite deploys).

# kind_nodes_dbus CLUSTER — D-Bus in the Kind nodes. With the kubelet's systemd cgroup driver the
# Spin shim creates its containers' cgroups through systemd, over D-Bus; Kind's node image runs
# systemd without D-Bus, so every Wasm pod sandbox failed ("cgroup error: systemd error: dbus
# error"). A regular node runs D-Bus; the Kind nodes get it here.
kind_nodes_dbus() {
  local cluster="$1" node
  for node in $(kind get nodes --name "$cluster"); do
    docker exec "$node" test -S /run/dbus/system_bus_socket 2>/dev/null && continue
    if docker exec "$node" bash -ceu '
      export DEBIAN_FRONTEND=noninteractive
      apt-get update -qq >/dev/null
      apt-get install -y -qq --no-install-recommends dbus >/dev/null
      systemctl start dbus.socket dbus.service' >/dev/null 2>&1; then
      echo "  ${node}: D-Bus running"
    else
      echo -e "  ${YELLOW}${node}: D-Bus could not be installed (Wasm pod sandboxes there fail)${NC}"
    fi
  done
}

# kind_load_wasm_shim CLUSTER — the image of the Spin shim RunR installs (runtime/wasm-shim-spin,
# ghcr.io/runrsh/containerd-shim-spin:<version>) into the Kind nodes, where the wasm-installer's init
# container takes it from. A local image of that version is used as it is (its tag changes with
# every change of the build); otherwise it is pulled, or built (ci.sh wasm-shim: a full Rust build).
kind_load_wasm_shim() {
  local cluster="$1" tag
  tag="$(wasm_shim_image)"
  if docker image inspect "$tag" >/dev/null 2>&1; then
    echo "  Spin shim image: ${tag} (local)"
  elif docker pull -q "$tag" >/dev/null 2>&1; then
    echo "  Spin shim image: ${tag} (pulled)"
  else
    echo "  Spin shim image: ${tag} — building it (Rust build of containerd-shim-spin)..."
    docker build -q -t "$tag" "${SCRIPT_DIR}/wasm-shim-spin" >/dev/null
  fi
  kind load docker-image "$tag" --name "$cluster" >/dev/null
  echo "  ${tag} loaded into kind/${cluster}"
}

prepare_wasm_e2e() {
  local cluster="$1"
  local langs="${WASM_E2E_LANGS:-rust js}"

  echo ""
  echo "Preparing the WebAssembly e2e..."
  if [ -n "$cluster" ]; then
    kind_nodes_dbus "$cluster"
    kind_load_wasm_shim "$cluster"
  fi
  for lang in $langs; do
    local version
    version="$(wasm_runtime_version "$lang")"
    local tag="${REGISTRY}/exec-wasm-${lang}:${version}"
    echo "  build image: ${tag}"
    docker build -q -t "${tag}" "${SCRIPT_DIR}/wasm-${lang}" >/dev/null
    if [ -n "$cluster" ]; then
      kind load docker-image "${tag}" --name "$cluster" >/dev/null
      echo "  ${tag} loaded into kind/${cluster}"
    fi
  done

  # prepare_runtime_e2e copied examples/runtimes to /tmp/runr-examples; the Wasm examples go next to them
  docker compose exec -T api rm -rf /tmp/runr-examples/wasm >/dev/null 2>&1 || true
  docker compose cp "${SCRIPT_DIR}/../examples/wasm" api:/tmp/runr-examples/wasm
  echo "  examples/wasm → api:/tmp/runr-examples/wasm"
}

cmd_test_wasm() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  # cmd_test runs prepare_wasm_e2e for --suite=wasm (step 4c of test.sh)
  cmd_test --suite=wasm "$@"
}
