# Organization export / import e2e (docs/org-export.md).
#
# cmd_test_org_transfer       — `ci.sh test-org-transfer [cmd_test flags]`: Kind + Knative (cmd_test)
#                               running only the org-transfer suite (php artisan test:e2e
#                               --suite=org-transfer, api/app/Console/Commands/Concerns/
#                               E2eTestOrgTransferTrait.php): an organization with projects, versions,
#                               domains, a gateway route, roles, alert rules and bindings with data is
#                               exported through the API (the region side of every data dump and image
#                               runs as a transfer agent Job — runtime/image-upload `transfer`), the
#                               package is downloaded, uploaded again in chunks, planned (region map,
#                               renames; the run history per project, the KV dump's Redis version) and
#                               applied into another organization of the same install, data restored,
#                               the history imported (never live) and the projects deployed; a second
#                               export goes into the object binding and an import reads it straight
#                               from there (source binding). The Kind stack is single-tenant
#                               (test.sh): "another organization" is the suite's import with renamed
#                               projects and bindings; the tenant-to-tenant case runs in test:e2e-tenancy.
# prepare_org_transfer_e2e    — builds the transfer agent (runtime/image-upload, tagged like the API's
#                               default ORG_TRANSFER_AGENT_IMAGE) and the binding data service images,
#                               and loads them into the Kind cluster (cmd_test calls it for
#                               --suite=org-transfer, step 4c of test.sh).
#
# ORG_TRANSFER_AGENT_VERSION  — tag of the locally built agent image (default 1.1.0, the version the
#                               API's org_transfer config names); the API pulls it IfNotPresent, so
#                               the loaded build is what runs.
# KIND_LOCK                   — one Kind cluster at a time on a shared host; defaults to
#                               /tmp/runr-kind.lock here (test-runtimes.sh: acquire_kind_lock).

prepare_org_transfer_e2e() {
  local cluster="$1"
  local version="${ORG_TRANSFER_AGENT_VERSION:-1.1.0}"
  local agent_tag="${REGISTRY}/image-upload:${version}"

  echo ""
  echo "Org transfer e2e preparation..."
  echo "  transfer agent image: ${agent_tag} (runtime/image-upload)"
  docker build -q --pull --build-arg "VERSION=${version}" -t "${agent_tag}" "${SCRIPT_DIR}/image-upload" >/dev/null
  if [ -n "$cluster" ]; then
    kind load docker-image "${agent_tag}" --name "$cluster" >/dev/null
    echo "  loaded into kind/${cluster}"
  fi

  # pg_dump / pg_restore (CloudNativePG image), redis-cli, rclone and the data services themselves
  # (test-bindings.sh)
  preload_bindings_images "$cluster"
}

cmd_test_org_transfer() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  # cmd_test runs prepare_org_transfer_e2e for --suite=org-transfer (step 4c of test.sh)
  cmd_test --suite=org-transfer "$@"
}
