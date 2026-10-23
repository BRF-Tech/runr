# Internal network e2e (docs/internal-network.md).
#
# cmd_test_internal_network — `ci.sh test-internal-network [cmd_test flags]`: Kind + Knative
#                    (cmd_test) running only the internal network suite (test:e2e
#                    --suite=internal-network, E2eTestInternalNetworkTrait), on an HTTP project
#                    that echoes what it gets and a queue-triggered one (Python), and a small model
#                    service bound to the HTTP project (the AI suite's tiny CPU model on hf-serve):
#                     - a new region runs the authz (the region agent in authz mode, built from
#                       this checkout: build_e2e_runr_images in test.sh), the private entry point
#                       asks it, CoreDNS sends runr.internal to traefik-internal, the region enforces
#                     - without a token 401, another key's token 401, a caller off the allow list
#                       403, the project itself and RunR 200; an added caller is admitted, a
#                       removed one refused; the callee sees X-Runr-Caller, never the token
#                     - a grant to another organization, refused again while the platform
#                       switches such grants off
#                     - access mode internal (no public host) and allowlist (403 outside the
#                       ranges); a queue message delivered through the authz (the consumer of
#                       this checkout sends the project's token); RunR's own calls through the API
#                       server carry runr:system
#                     - the bound model service: the bound project's calls pass with and without a
#                       token, another project's are refused
#                     - audit mode admits and logs; a region as an earlier release left it is
#                       moved by region:reconcile's internal-network step while probe pods call
#                       without a token — the queue project its own service, the HTTP project its
#                       bound model service: none of their calls fails; afterwards the bound model
#                       calls pass with and without a token and the unbound ones are refused
#
# The model service runs hf-serve's CPU image (the version runtime-images.sh pins, pulled once and
# loaded into the Kind nodes like test-ai.sh does) and downloads the tiny model from the Hub.
# KIND_PRELOAD=0 (a host short of disk, test.sh): the published image is not loaded, the node of the
# model service pulls it and keeps only its unpacked layers (a loaded image keeps its compressed
# ones next to them: 0.5 GiB more for hf-serve 1.1.1); a local build (RUNTIME_IMAGES_LOCAL=1) or
# another tag is loaded as before. RUNR_E2E_IN_MODEL=0 leaves the model service out (no image
# pulled; in-model is skipped).
#
# KIND_LOCK          — one Kind cluster at a time on a shared host; this suite defaults it to
#                      /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

# internal_network_pulls_hf_serve — true when the Kind nodes pull the model service's image instead
# of getting it loaded: KIND_PRELOAD=0 and the published image of the pinned version (not a local
# build, which is on no registry)
internal_network_pulls_hf_serve() {
  [ "${KIND_PRELOAD:-1}" = "0" ] && [ "${RUNTIME_IMAGES_LOCAL:-0}" != "1" ] && [ "$AI_HF_SERVE_TAG" = "$AI_HF_SERVE_DEFAULT_TAG" ]
}

cmd_test_internal_network() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"

  if [ "${RUNR_E2E_IN_MODEL:-1}" != "0" ]; then
    echo "  hf-serve image (CPU) of the bound model service:"
    runtime_image_ready "$AI_HF_SERVE_TAG" || return 1
    if [ "$AI_HF_SERVE_TAG" != "$AI_HF_SERVE_DEFAULT_TAG" ]; then
      # mirror Jobs and receivers use the configured default image
      docker tag "$AI_HF_SERVE_TAG" "$AI_HF_SERVE_DEFAULT_TAG"
    fi
    if internal_network_pulls_hf_serve; then
      echo "  ${AI_HF_SERVE_TAG}: not loaded into the Kind nodes (KIND_PRELOAD=0): the model service's node pulls it"
    else
      # ai_after_kind_create (test-ai.sh) loads it into the nodes; no GPU here
      _ai_hf_serve=1
      _ai_gpu=""
      export TEST_AFTER_KIND_CREATE=ai_after_kind_create
    fi
    export TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} --hf-serve-image=${AI_HF_SERVE_TAG}"
  fi

  cmd_test --suite=internal-network "$@"
}
