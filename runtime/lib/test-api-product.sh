# API product e2e (gateway API keys, plans, usage, JWT, gateway-only).
#
# cmd_test_api_product — `ci.sh test-api-product [cmd_test flags]`: builds the gateway-auth image
#                        from runtime/gateway-auth (the tag the API deploys:
#                        ghcr.io/brf-tech/runr/gateway-auth:1.0.0), loads it into the Kind
#                        cluster and runs Kind + Knative (cmd_test) with only the API product suite
#                        (test:e2e --suite=api-product, E2eTestApiProductTrait) on one Python
#                        function routed through the admin's gateway:
#                         - public route through the gateway host; auth_mode key: runr-gateway-auth
#                           becomes ready, 401 without / with an unknown key, 200 with the
#                           consumer's key, X-Runr-Consumer at the function and no key there
#                         - a plan of 3 requests a minute: 429 with RateLimit-Limit / Retry-After
#                         - a revoked key answers 401 within the sync interval
#                         - the region's usage reports reach GET /api/api-usage
#                         - auth_mode jwt with the JWKS of a second function: a valid RS256 token
#                           passes, a token of another key and an expired one answer 401
#                         - gateway_only: the function's own URL answers 404, the gateway 200
#
# KIND_LOCK              — one Kind cluster at a time on a shared host; this suite defaults it to
#                          /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).
# GATEWAY_AUTH_IMAGE     — the tag to build and load (default ghcr.io/brf-tech/runr/gateway-auth:1.0.0)

GATEWAY_AUTH_IMAGE="${GATEWAY_AUTH_IMAGE:-${REGISTRY}/gateway-auth:1.0.0}"

# TEST_AFTER_KIND_CREATE hook of cmd_test: the locally built gateway-auth into every node.
api_product_after_kind_create() {
  local cluster="$1"
  echo ""
  echo "API product e2e: ${GATEWAY_AUTH_IMAGE} into kind/${cluster}..."
  kind_load_image "$GATEWAY_AUTH_IMAGE" "$cluster"
}

cmd_test_api_product() {
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"

  echo -e "${BOLD}── gateway-auth (API product) image ──${NC}"
  docker build -t "$GATEWAY_AUTH_IMAGE" "${SCRIPT_DIR}/gateway-auth" || {
    echo -e "${RED}gateway-auth image build failed${NC}"
    exit 1
  }

  # the API deploys its default tag (config api_product.gateway_auth_image): the one just built
  # and loaded, so the nodes never pull it
  export TEST_AFTER_KIND_CREATE=api_product_after_kind_create
  cmd_test --suite=api-product "$@"
}
