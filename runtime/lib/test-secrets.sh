# Project secrets e2e (pass-through values, the database move, secret store references).
#
# cmd_test_secrets — `ci.sh test-secrets [cmd_test flags]`: Kind + Knative (cmd_test) running only
#                    the secrets suite (test:e2e --suite=secrets, E2eTestSecretsTrait) on one Python
#                    function that answers the SHA-256 of its secret variables:
#                     - a value set through the API is in the region's Secret <slug>-secret-env and
#                       not in RunR's database (a keyed fingerprint only); the function sees it
#                     - reading it back reads the region
#                     - `secrets:move-to-region` moves a value written the way RunR did before
#                       pass-through into the region and clears it from the database
#                     - an upgrade from 0.5.10 not finalized yet: a value set keeps an encrypted
#                       copy in the database for a rollback; `secrets:move-to-region --finalize`
#                       drops it and the region keeps the value
#                     - External Secrets Operator installed through the API (the pinned chart, its
#                       image from ghcr.io/brf-tech/runr/reqs/external-secrets), ready
#                     - a throwaway OpenBao dev server (ghcr.io/brf-tech/runr/reqs/openbao) with
#                       Kubernetes auth bound to the store's service account; the store verified
#                     - a reference to it reaches the function after a deploy, the status sync
#                       marks it synced
#                     - the region's own OpenBao installed through the API: initialized, unsealed
#                       (the unseal key in the region only), configured
#                     - a value set while OpenBao runs lives in OpenBao, not in the Kubernetes
#                       Secret; the function sees it after a deploy
#                     - RUNR_E2E_SECRETS_DB=1: the Postgres data service, a Postgres binding
#                       attached with dynamic credentials — the function runs as a user OpenBao
#                       issued (v-…), not as the binding's own
#                     - deleting the project removes its values Secret
#
#                    The Kind nodes pull the images from ghcr.io (public reqs mirror, REQS_IMAGES
#                    of lib/mirror.sh in BRF-Tech/runr-runtimes: external-secrets, openbao) and the
#                    operator's chart from charts.external-secrets.io.
#
# RUNR_E2E_SECRETS_DB  — 1: also the dynamic Postgres credentials case (installs CloudNativePG; a
#                        few more minutes and GB on the Kind node).
#
# KIND_LOCK            — one Kind cluster at a time on a shared host; this suite defaults it to
#                        /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_secrets() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  if [ "${RUNR_E2E_SECRETS_DB:-}" = "1" ]; then
    export TEST_E2E_EXTRA_ARGS="${TEST_E2E_EXTRA_ARGS:-} --secrets-db"
  fi
  cmd_test --suite=secrets "$@"
}
