# region:reconcile e2e (the tracing backend and registry pre-warm steps of an upgrade).
#
# cmd_test_reconcile — `ci.sh test-reconcile [cmd_test flags]`: Kind + Knative (cmd_test) running
#                      only the reconcile suite (test:e2e --suite=reconcile, E2eTestReconcileTrait):
#                       - tracing: the collector + Tempo installed with Knative Serving are what
#                         this release installs (Tempo's fix-permissions init container, 50m
#                         requests) and the step leaves them alone; a backend of an earlier release
#                         (no fix-permissions on a root-owned volume, so Tempo crash-loops; the
#                         earlier requests) is updated, its stuck Tempo pod replaced, and it is
#                         ready again
#                       - registry-prewarm: the dry run plans a Job for the build base images Zot
#                         lacks; a failed Job of that name is started again; a second run while
#                         it runs starts nothing; the Job waits while Zot does not answer (its
#                         Service without endpoints) and fetches every image once it answers
#                         (Zot's tags/list); afterwards nothing is missing
#                       - Zot with its node-bound volume on a dedicated worker (the node is
#                         selected when the suite did not): restarted as registry-sync-config /
#                         image-registry restart it, it runs there again; a Zot of an earlier
#                         release (no toleration) waits Pending on the taint, and the
#                         dedicated-worker-toleration step adds the toleration and replaces the pod
#                       - `php artisan region:reconcile --region=…`: no step fails,
#                         dedicated-worker-toleration runs first, registry-prewarm last, tracing
#                         and registry-prewarm are up to date
#
#                      The pre-warm fetches the build base images of every built-in runtime from
#                      ghcr.io through the region's Zot (a few GB on the Kind nodes' disk).
#
# KIND_LOCK            — one Kind cluster at a time on a shared host; this suite defaults it to
#                        /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_reconcile() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=reconcile "$@"
}
