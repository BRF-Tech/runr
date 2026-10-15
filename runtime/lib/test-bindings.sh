# Bindings e2e (docs/bindings.md).
#
# cmd_test_bindings        — `ci.sh test-bindings [cmd_test flags]`: Kind + Knative (cmd_test) running
#                            only the bindings suite (php artisan test:e2e --suite=bindings,
#                            api/app/Console/Commands/Concerns/E2eTestBindingsTrait.php):
#                              1. installs the region data services through the API — managed KV
#                                 Redis, Garage, CloudNativePG + the shared Postgres cluster;
#                              2. creates kv, object, postgres (shared and dedicated) and external
#                                 bindings;
#                              3. deploys a Python function (redis, psycopg, boto3), attaches the
#                                 bindings to it (rolling restart) and checks that it writes and
#                                 reads each one, that the KV user stays inside its prefix and the
#                                 Postgres role inside its database;
#                              4. rotates credentials, detaches, measures usage, deletes / restores
#                                 / purges a binding.
# preload_bindings_images  — pulls the data service images and loads them into the Kind cluster
#                            (cmd_test calls it for --suite=bindings).
#
# KIND_LOCK                — one Kind cluster at a time on a shared host; defaults to
#                            /tmp/runr-kind.lock here (test-runtimes.sh: acquire_kind_lock).

BINDINGS_E2E_IMAGES=(
  "ghcr.io/brf-tech/reqs/redis:7-alpine"
  "ghcr.io/brf-tech/reqs/garage:v2.1.0"
  "ghcr.io/brf-tech/reqs/rclone:1.68.2"
  "ghcr.io/brf-tech/reqs/python:3.12-slim"
  "ghcr.io/cloudnative-pg/postgresql:17.5"
)

preload_bindings_images() {
  local cluster="$1"
  [ -z "$cluster" ] && return 0

  echo ""
  echo "Bindings e2e: data service images → kind/${cluster}"
  local img
  for img in "${BINDINGS_E2E_IMAGES[@]}"; do
    pull_image "$img" || echo "  pull failed: ${img} (the cluster pulls it itself)"
    kind_load_image "$img" "$cluster" || echo "  not loaded: ${img} (the cluster pulls it itself)"
  done
  echo -e "  ${GREEN}✓ data service images loaded${NC}"
}

cmd_test_bindings() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=bindings "$@"
}
