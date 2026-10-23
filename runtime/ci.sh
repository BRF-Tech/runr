#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Load the lib files ──
source "${SCRIPT_DIR}/lib/common.sh"
source "${SCRIPT_DIR}/lib/runtime-images.sh"
source "${SCRIPT_DIR}/lib/build.sh"
source "${SCRIPT_DIR}/lib/test.sh"
source "${SCRIPT_DIR}/lib/test-runtimes.sh"
source "${SCRIPT_DIR}/lib/test-observability.sh"
source "${SCRIPT_DIR}/lib/test-triggers.sh"
source "${SCRIPT_DIR}/lib/test-trigger-sources.sh"
source "${SCRIPT_DIR}/lib/test-reconcile.sh"
source "${SCRIPT_DIR}/lib/test-network.sh"
source "${SCRIPT_DIR}/lib/test-internal-network.sh"
source "${SCRIPT_DIR}/lib/test-cert-manager.sh"
source "${SCRIPT_DIR}/lib/test-wasm.sh"
source "${SCRIPT_DIR}/lib/build-wasm.sh"
source "${SCRIPT_DIR}/lib/test-sandbox-api.sh"
source "${SCRIPT_DIR}/lib/test-supply-chain.sh"
source "${SCRIPT_DIR}/lib/test-ai.sh"
source "${SCRIPT_DIR}/lib/test-bindings.sh"
source "${SCRIPT_DIR}/lib/test-git-deploy.sh"
source "${SCRIPT_DIR}/lib/test-images.sh"
source "${SCRIPT_DIR}/lib/test-org-transfer.sh"
source "${SCRIPT_DIR}/lib/test-mcp.sh"
source "${SCRIPT_DIR}/lib/test-admission.sh"
source "${SCRIPT_DIR}/lib/test-runtime-patches.sh"
source "${SCRIPT_DIR}/lib/test-warmer.sh"
source "${SCRIPT_DIR}/lib/test-deploy-strategies.sh"
source "${SCRIPT_DIR}/lib/test-secrets.sh"
source "${SCRIPT_DIR}/lib/test-environments.sh"
source "${SCRIPT_DIR}/lib/test-api-product.sh"
source "${SCRIPT_DIR}/lib/test-helm-upgrade.sh"
source "${SCRIPT_DIR}/lib/test-tenancy.sh"
source "${SCRIPT_DIR}/lib/test-authprovider.sh"
source "${SCRIPT_DIR}/lib/test-ticket.sh"
source "${SCRIPT_DIR}/lib/test-upgrade.sh"
source "${SCRIPT_DIR}/lib/setup.sh"
source "${SCRIPT_DIR}/lib/deploy-images.sh"
source "${SCRIPT_DIR}/lib/deploy.sh"

# ── Help ──
cmd_help() {
  cat <<EOF
Usage: ci.sh <command> [flags]

The runtime images (exec-*, wrapper, hf-serve) and the reqs mirror are built and published by
BRF-Tech/runr-runtimes (https://github.com/BRF-Tech/runr-runtimes): its ci.sh has the build,
wrapper, wasm, ml-runtimes, mirror-reqs, mirror-bases and eol commands. The e2e suites here pull
the runtime images at the tags pinned in lib/runtime-images.sh (RUNTIME_IMAGES_LOCAL=1: a local
build of them).

Commands:
  queue-consumer [--push]             Build + push the queue-consumer image (<appVersion> and latest)
  wasm-shim [--push]                  The Spin shim RunR installs on region nodes (containerd-shim-spin, runtime/wasm-shim-spin)
  supply-chain [--push]               Supply-chain tools image (runr-supply-chain: syft, trivy, cosign, oras)
  image-upload [--push]               Image upload receiver + org transfer agent (runr-image-upload: chunks →
                                      region registry; transfer: org export / import data and images)
  gateway-auth [--push]               API product service of the regions (keys, JWT, limits, usage; runtime/gateway-auth)
  build-agent [--push]                Build + push the RunR monitoring agent image (<appVersion> and latest)
  release-images [--push [--force]]   The images of the regions a release references by version (agent and
                                      queue-consumer at helm/Chart.yaml appVersion, gateway-auth,
                                      image-upload, supply-chain, the Spin shim at their own); --push
                                      builds and pushes each one the registry does not have yet
  test-image [--push]                 Build + push the CaaS test image (nginx-test)
  test [--k8s=X.Y.Z] [--no-cleanup]  Kind cluster + Knative + e2e test (runtime plugins included)
  test-runtimes [test flags]          Kind + Knative, runtime plugin e2e only (python, C, Q, schedule, queue)
  test-observability [test flags]     Kind + Knative, observability e2e only (collector + Tempo, traces, replay)
  test-triggers [test flags]          Kind + Knative, triggers e2e only (webhook inbox, replay, platform events, schedule, topics)
  test-trigger-sources [test flags]   Kind + Knative, trigger sources e2e (S3, Kafka, NATS, RabbitMQ, MQTT, Postgres CDC + slot cleanup, IMAP, schedule time zone)
  test-reconcile [test flags]         Kind + Knative, region:reconcile e2e: tracing backend update (Tempo
                                      fix-permissions, requests, stuck pod), registry pre-warm Job (failed
                                      Job started again, waits for Zot, every image fetched), Zot restart,
                                      step order
  test-network [test flags]           Kind + Knative, region network e2e: cluster-local routes only on
                                      Traefik's private entry point (404 through the LoadBalancer),
                                      NetworkPolicies (no way past the route to the pods), scale to /
                                      from zero, queue trigger, custom domain / alias / gateway, an
                                      earlier release's region moved by region:reconcile under load
                                      (the disruption measured)
  test-internal-network [test flags]  Kind + Knative, internal network e2e: the authz (tokens, allow
                                      lists, cross-organization grants and the platform's switch),
                                      runr.internal names, access modes internal / allowlist, queue
                                      trigger and RunR's own calls through it, audit mode, an earlier
                                      release's region moved by region:reconcile under load
  test-cert-manager [test flags]      Kind + Knative, cert-manager e2e: installed with Serving, the region CA
                                      issuer, HTTPS of a custom domain with its certificate, removal, reinstall
  test-wasm [test flags]            Kind + Knative, WebAssembly e2e (spin shim, Rust + JS functions, cold starts)
  test-sandbox-api [test flags]       Kind, Sandbox API e2e only (gVisor / runc; Kata where /dev/kvm exists)
  test-supply-chain [test flags]      Kind + Knative, supply-chain e2e only (SBOM, scan, signing, admission)
  test-ai [test flags]                Kind + Knative, AI e2e: model services online/offline, snapshots, bindings
                                      (RUNR_E2E_TEI=1 adds TEI; GPU case when the host can give Kind a GPU,
                                      see lib/test-ai.sh; KIND_LOCK defaults to /tmp/runr-kind.lock,
                                      KIND_ETCD_IN_MEMORY to 1; RUNR_E2E_AI_CASES=ai-gpu-caas runs single
                                      cases; a host GPU that needs a reset ends the GPU case at once)
  test-bindings [test flags]          Kind + Knative, bindings e2e only (KV Redis, Garage, CloudNativePG, external)
  test-images [test flags]            Kind + Knative, uploaded images e2e (tar + wasm upload, CaaS from an
                                      upload, wasm-exec in C and Q; KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-org-transfer [test flags]      Kind + Knative, org export / import e2e: export with binding data and
                                      images, download, chunked upload, plan, apply into a renamed copy,
                                      deploy (lib/test-org-transfer.sh; KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-git-deploy [test flags]       Kind + Knative + throwaway Forgejo: git push, push-to-deploy, PR preview
                                      (KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-mcp [test flags]               Kind + Knative, MCP e2e: RunR MCP server, OAuth, hosted MCP servers through the
                                      gateway, driven by the official MCP Python SDK client (lib/test-mcp.sh)
  test-api-product [test flags]       Kind + Knative, API product e2e: gateway-auth built and loaded, API keys,
                                      plans (429 + RateLimit headers), revocation, usage reports, JWT routes,
                                      gateway-only projects (lib/test-api-product.sh)
  test-admission [test flags]         Kind + Knative with billing on, admission e2e: project stop / resume, region
                                      status blocks (503 / 404), fixed-region allocations (lib/test-admission.sh)
  test-runtime-patches [test flags]   Kind + Knative, runtime patch e2e: signed feed, functions by digest, deadline
                                      migration rolled back, apply, pin (lib/test-runtime-patches.sh; needs ghcr.io)
  test-warmer [test flags]            Kind + Knative, timed warmer e2e: schedule preview, min-scale in the
                                      revision template, PodAutoscaler patches without a restart, warmer off
                                      and redeployed (lib/test-warmer.sh; KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-deploy-strategies [test flags] Kind + Knative, deploy strategies e2e: blue-green (test URL, promote, warm
                                      previous revision, rollback) and canary (traffic split, automatic
                                      promotion, abort) rollouts (lib/test-deploy-strategies.sh)
  test-secrets [test flags]           Kind + Knative, project secrets e2e: pass-through values, secrets:move-to-region,
                                      External Secrets Operator + an OpenBao reference (lib/test-secrets.sh;
                                      KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-environments [test flags]      Kind + Knative, environments e2e: staging next to production, promotion,
                                      rollback restoring the config (lib/test-environments.sh)
  test-global-projects [test flags]   Kind + Knative on two Kind clusters (two regions), global projects e2e:
                                      PowerDNS per region, rollout, health route, HA failover, proximity
                                      (lib/test-global-projects.sh; KIND_LOCK defaults to /tmp/runr-kind.lock;
                                      RUNR_E2E_GLOBAL_CASES=gp-postgres-replica runs single cases)
  test-region-move [test flags]       Kind + Knative on two Kind clusters, region move e2e: the region moved to
                                      the second cluster (rebuilds, report, secret values in its Secret and
                                      OpenBao, references) and rolled back (restored state, values copied back,
                                      the second cluster emptied of the projects) (lib/test-region-move.sh;
                                      KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-helm-upgrade [flags]          Kind: install the released chart + images (--from, default main), create
                                      data, helm upgrade to this checkout, check users, roles, env vars at rest,
                                      secret env, tokens, the function during the upgrade, the agent and
                                      white-label; --edition=multi-tenant: tenant roles and 0.5.x billing data;
                                      --billing: billing.enabled=true on the upgrade, Billing API on the imported
                                      data and a region offer (lib/test-helm-upgrade.sh; KIND_LOCK defaults to
                                      /tmp/runr-kind.lock)
  test-tenancy                        Tenancy e2e test (API-only, no K8s needed)
  test-authprovider                   AuthProvider e2e test (API-only, no K8s needed)
  test-ticket                         Ticket e2e test (API + WS mock, no K8s needed)
  test-upgrade [--from=REF] [--tenancy] [--keep] [--skip-build]
                                      Compose upgrade (compose/): REF (default main) with data on
                                      PostgreSQL, then this checkout on the same database (no K8s)
  setup                               Detect the CPU architecture, create docker-compose.override.yml
  build-deploy [--no-cache]            Build + save tars only (no cluster needed)
  deploy [flags]                      First install: namespace + secret + build + helm + admin
  upgrade [flags]                     Update an existing install: build + helm + migrate
  registry-push api|ui|all [REGISTRY] Push existing tars to a registry
  deploy-status [--kubeconfig=...]    Show deployment status
  help                                Show this message

Environment:
  PLATFORMS       Build platforms (default: linux/amd64,linux/arm64)
  RUNTIME_IMAGES_REGISTRY  Where the e2e suites pull the runtime images from (default: ghcr.io/brf-tech/runr)
  RUNTIME_IMAGES_LOCAL     1: a runtime image this host has (a local build of BRF-Tech/runr-runtimes)
                  is used as it is, not pulled

Examples:
  ci.sh test-image --push        nginx-test build + push
  ci.sh test --k8s=1.32.0       e2e test with Kind
  ci.sh test-helm-upgrade        main's release → this checkout through helm upgrade
  ci.sh test-tenancy             Tenancy e2e test (Docker Compose)
  ci.sh test-authprovider        AuthProvider e2e test (Docker Compose)
  ci.sh test-ticket              Ticket e2e test (Docker Compose, API + WS mock)
  ci.sh test-upgrade --tenancy   Upgrade of a multi-tenant Compose install from main

  ci.sh deploy --kubeconfig=PATH --domain=DOMAIN \\
               [--namespace=NS] [--tenancy] [--auth-provider] [--ticket] [--billing] \\
               [--admin-email=X --admin-pass=Y] \\
               [--hpa] [--pdb] [--no-pdb] \\
               [--replicas-api=N] [--replicas-gateway=N] [--replicas-ui=N] \\
               [--skip-build] [--no-cache] [--reinstall]
  ci.sh build-deploy --no-cache --tenancy --auth-provider --ticket
  ci.sh upgrade --kubeconfig=PATH --skip-build [--domain=DOMAIN] \\
               [--namespace=NS] [--tenancy] [--auth-provider] [--ticket] [--billing] \\
               [--hpa] [--pdb] [--no-pdb] \\
               [--replicas-api=N] [--replicas-gateway=N] [--replicas-ui=N] \\
               [--no-cache] [--build-only]
  ci.sh registry-push all ghcr.io/myorg
  ci.sh deploy-status --kubeconfig=PATH [--namespace=NS]
EOF
}

# ── Entrypoint ──
command="${1:-help}"
shift || true

case "$command" in
  build|wrapper|wasm|ml-runtimes|mirror-reqs|mirror-bases|eol) cmd_moved_to_runtimes "$command" ;;
  queue-consumer)  cmd_build_queue_consumer "$@" ;;
  wasm-shim)       cmd_build_wasm_shim "$@" ;;
  supply-chain)    cmd_build_supply_chain "$@" ;;
  image-upload)    cmd_build_image_upload "$@" ;;
  gateway-auth)    cmd_build_gateway_auth "$@" ;;
  build-agent)     cmd_build_agent "$@" ;;
  release-images)  cmd_release_images "$@" ;;
  test-image)      cmd_test_image "$@" ;;
  test)            cmd_test "$@" ;;
  test-runtimes)   cmd_test_runtimes "$@" ;;
  test-observability) cmd_test_observability "$@" ;;
  test-triggers)      cmd_test_triggers "$@" ;;
  test-trigger-sources) cmd_test_trigger_sources "$@" ;;
  test-reconcile)  cmd_test_reconcile "$@" ;;
  test-network)    cmd_test_network "$@" ;;
  test-internal-network) cmd_test_internal_network "$@" ;;
  test-cert-manager) cmd_test_cert_manager "$@" ;;
  test-wasm)       cmd_test_wasm "$@" ;;
  test-sandbox-api) cmd_test_sandbox_api "$@" ;;
  test-supply-chain) cmd_test_supply_chain "$@" ;;
  test-ai)         cmd_test_ai "$@" ;;
  test-bindings)   cmd_test_bindings "$@" ;;
  test-git-deploy) cmd_test_git_deploy "$@" ;;
  test-images)     cmd_test_images "$@" ;;
  test-org-transfer) cmd_test_org_transfer "$@" ;;
  test-mcp)        cmd_test_mcp "$@" ;;
  test-admission)  cmd_test_admission "$@" ;;
  test-runtime-patches) cmd_test_runtime_patches "$@" ;;
  test-warmer)     cmd_test_warmer "$@" ;;
  test-deploy-strategies) cmd_test_deploy_strategies "$@" ;;
  test-secrets)    cmd_test_secrets "$@" ;;
  test-environments) cmd_test_environments "$@" ;;
  test-api-product) cmd_test_api_product "$@" ;;
  test-region-move) source "${SCRIPT_DIR}/lib/test-region-move.sh"; cmd_test_region_move "$@" ;;
  test-global-projects) source "${SCRIPT_DIR}/lib/test-global-projects.sh"; cmd_test_global_projects "$@" ;;
  test-helm-upgrade) cmd_test_helm_upgrade "$@" ;;
  test-tenancy)        cmd_test_tenancy "$@" ;;
  test-authprovider)   cmd_test_authprovider "$@" ;;
  test-ticket)         cmd_test_ticket "$@" ;;
  test-upgrade)        cmd_test_upgrade "$@" ;;
  setup)               cmd_setup ;;
  build-deploy)        cmd_build_deploy "$@" ;;
  deploy)              cmd_deploy "$@" ;;
  upgrade)             cmd_upgrade "$@" ;;
  registry-push)       cmd_registry_push "$@" ;;
  deploy-status)       cmd_deploy_status "$@" ;;
  help)            cmd_help ;;
  *)               echo "Unknown command: $command"; cmd_help; exit 1 ;;
esac
