# cert-manager e2e (docs/regions.md#cert-manager).
#
# cmd_test_cert_manager — `ci.sh test-cert-manager [cmd_test flags]`: Kind + Knative (cmd_test)
#                         running only the cert-manager suite (test:e2e --suite=cert-manager,
#                         E2eTestCertManagerTrait):
#                          - cm-installed     the Serving install set cert-manager up: RunR's release
#                                             of the pinned chart, every image from the reqs mirror
#                                             (preloaded into Kind, preload_kind_images)
#                          - cm-issuer-ca     the Kind region is not reachable from the internet: the
#                                             default ClusterIssuer is the region CA, Ready; the CA
#                                             download is a CA certificate without its key
#                          - cm-domain-https  a custom domain starts in TLS mode cert-manager; routed,
#                                             its Certificate is Ready and HTTPS through the region
#                                             Traefik verifies against the region CA
#                          - cm-reconcile     region:reconcile's cert-manager step changes nothing
#                          - cm-remove        refused while the domain has its certificate; then
#                                             RunR's release and issuers go, the CRDs stay
#                          - cm-reinstall     installing again through the API is ready again
#
#                         ACME (Let's Encrypt) needs a region the internet reaches: not part of
#                         the Kind run.
#
# KIND_LOCK             — one Kind cluster at a time on a shared host; this suite defaults it to
#                         /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_cert_manager() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=cert-manager "$@"
}
