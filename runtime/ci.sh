#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# ── Load the lib files ──
source "${SCRIPT_DIR}/lib/common.sh"
source "${SCRIPT_DIR}/lib/build.sh"
source "${SCRIPT_DIR}/lib/mirror.sh"
source "${SCRIPT_DIR}/lib/eol.sh"
source "${SCRIPT_DIR}/lib/test.sh"
source "${SCRIPT_DIR}/lib/test-runtimes.sh"
source "${SCRIPT_DIR}/lib/test-observability.sh"
source "${SCRIPT_DIR}/lib/test-wasm.sh"
source "${SCRIPT_DIR}/lib/build-wasm.sh"
source "${SCRIPT_DIR}/lib/test-sandbox-api.sh"
source "${SCRIPT_DIR}/lib/test-supply-chain.sh"
source "${SCRIPT_DIR}/lib/test-ai.sh"
source "${SCRIPT_DIR}/lib/test-bindings.sh"
source "${SCRIPT_DIR}/lib/test-git-deploy.sh"
source "${SCRIPT_DIR}/lib/test-images.sh"
source "${SCRIPT_DIR}/lib/test-mcp.sh"
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

Commands:
  build [--push]                      Discover + build images (skip EOL ones)
  queue-consumer [--push]             Build + push the queue-consumer image
  wrapper [--push]                    Build + push the universal runtime wrapper image (runr-wrapper)
  wasm [--push] [lang...]             WebAssembly runtime build images (exec-wasm-rust|go|js|python|exec) build + push
  wasm-shim [--push]                  The Spin shim RunR installs on region nodes (containerd-shim-spin, runtime/wasm-shim-spin)
  supply-chain [--push]               Supply-chain tools image (runr-supply-chain: syft, trivy, cosign, oras)
  image-upload [--push]               Image upload receiver (runr-image-upload: chunks → region registry)
  build-agent [--push]                Build + push the RunR monitoring agent image
  mirror-reqs [--dry-run]             Multi-arch mirror of the upstream reqs images
  mirror-bases [--dry-run]            Multi-arch mirror of the runtime base images
  eol [--prune] [--yes]               EOL check of the GHCR exec-* images + optional cleanup
  test-image [--push]                 Build + push the CaaS test image (nginx-test)
  test [--k8s=X.Y.Z] [--no-cleanup]  Kind cluster + Knative + e2e test (runtime plugins included)
  test-runtimes [test flags]          Kind + Knative, runtime plugin e2e only (python, C, Q, schedule, queue)
  test-observability [test flags]     Kind + Knative, observability e2e only (collector + Tempo, traces, replay)
  test-wasm [test flags]              Kind + Knative, WebAssembly e2e (spin shim, Rust + JS functions, cold starts)
  test-sandbox-api [test flags]       Kind, Sandbox API e2e only (gVisor / runc; Kata where /dev/kvm exists)
  test-supply-chain [test flags]      Kind + Knative, supply-chain e2e only (SBOM, scan, signing, admission)
  test-ai [test flags]                Kind + Knative, AI e2e: model services online/offline, snapshots, bindings
                                      (RUNR_E2E_TEI=1 adds TEI; GPU case when the host can give Kind a GPU,
                                      see lib/test-ai.sh; KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-bindings [test flags]          Kind + Knative, bindings e2e only (KV Redis, Garage, CloudNativePG, external)
  test-images [test flags]            Kind + Knative, uploaded images e2e (tar + wasm upload, CaaS from an
                                      upload, wasm-exec in C and Q; KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-git-deploy [test flags]        Kind + Knative + throwaway Forgejo: git push, push-to-deploy, PR preview
                                      (KIND_LOCK defaults to /tmp/runr-kind.lock)
  test-mcp [test flags]               Kind + Knative, MCP e2e: RunR MCP server, OAuth, hosted MCP servers through the
                                      gateway, driven by the official MCP Python SDK client (lib/test-mcp.sh)
  test-helm-upgrade [flags]           Kind: install the released chart + images (--from, default main), create
                                      data, helm upgrade to this checkout, check users, roles, env vars at rest,
                                      secret env, tokens, the function during the upgrade, the agent and
                                      white-label; --edition=multi-tenant: tenant roles (lib/test-helm-upgrade.sh;
                                      KIND_LOCK defaults to /tmp/runr-kind.lock)
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
  PLATFORMS  Build platforms (default: linux/amd64,linux/arm64)

Examples:
  ci.sh build                    Build only (local, single platform)
  ci.sh build --push             Build + push (multi-arch: amd64+arm64)
  ci.sh mirror-reqs              Upstream reqs → ghcr multi-arch mirror
  ci.sh mirror-reqs --dry-run    Show the mirror list (no push)
  ci.sh mirror-bases             Mirror the runtime base images
  ci.sh mirror-bases --dry-run   Show the base image list (no push)
  ci.sh eol                      Show the EOL report
  ci.sh eol --prune --yes        Delete EOL tags from GHCR (GHCR_TOKEN)
  ci.sh test-image --push        nginx-test build + push
  ci.sh test --k8s=1.32.0       e2e test with Kind
  ci.sh test-helm-upgrade        main's release → this checkout through helm upgrade
  ci.sh test-tenancy             Tenancy e2e test (Docker Compose)
  ci.sh test-authprovider        AuthProvider e2e test (Docker Compose)
  ci.sh test-ticket              Ticket e2e test (Docker Compose, API + WS mock)
  ci.sh test-upgrade --tenancy   Upgrade of a multi-tenant Compose install from main

  ci.sh deploy --kubeconfig=PATH --domain=DOMAIN \\
               [--namespace=NS] [--tenancy] [--auth-provider] [--ticket] \\
               [--admin-email=X --admin-pass=Y] \\
               [--hpa] [--pdb] [--no-pdb] \\
               [--replicas-api=N] [--replicas-gateway=N] [--replicas-ui=N] \\
               [--skip-build] [--no-cache] [--reinstall]
  ci.sh build-deploy --no-cache --tenancy --auth-provider --ticket
  ci.sh upgrade --kubeconfig=PATH --skip-build [--domain=DOMAIN] \\
               [--namespace=NS] [--tenancy] [--auth-provider] [--ticket] \\
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
  build)           cmd_build "$@" ;;
  queue-consumer)  cmd_build_queue_consumer "$@" ;;
  wrapper)         cmd_build_wrapper "$@" ;;
  wasm)            cmd_build_wasm "$@" ;;
  wasm-shim)       cmd_build_wasm_shim "$@" ;;
  supply-chain)    cmd_build_supply_chain "$@" ;;
  image-upload)    cmd_build_image_upload "$@" ;;
  build-agent)     cmd_build_agent "$@" ;;
  mirror-reqs)     cmd_mirror_reqs "$@" ;;
  mirror-bases)    cmd_mirror_bases "$@" ;;
  eol)             cmd_eol "$@" ;;
  test-image)      cmd_test_image "$@" ;;
  test)            cmd_test "$@" ;;
  test-runtimes)   cmd_test_runtimes "$@" ;;
  test-observability) cmd_test_observability "$@" ;;
  test-wasm)       cmd_test_wasm "$@" ;;
  test-sandbox-api) cmd_test_sandbox_api "$@" ;;
  test-supply-chain) cmd_test_supply_chain "$@" ;;
  test-ai)         cmd_test_ai "$@" ;;
  test-bindings)   cmd_test_bindings "$@" ;;
  test-git-deploy) cmd_test_git_deploy "$@" ;;
  test-images)     cmd_test_images "$@" ;;
  test-mcp)        cmd_test_mcp "$@" ;;
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
