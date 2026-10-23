# Runtime patches e2e (signed patch feed, workloads by digest, deadline migration and rollback).
#
# cmd_test_runtime_patches — `ci.sh test-runtime-patches [cmd_test flags]`: Kind + Knative
#                      (cmd_test), running only the runtime patch suite (test:e2e
#                      --suite=runtime-patches, E2eTestRuntimePatchesTrait) on one Python function:
#                       - a feed signed with a throwaway cosign key is taken, one signed with another
#                         key refused (the patches stay)
#                       - the function runs exec-python by digest (the image index of 3.14)
#                       - a zero-day patch past its deadline whose digest does not exist: RunR
#                         migrates the function, the revision never becomes ready, RunR rolls back
#                         to the previous digest, pins it there and notifies (critical)
#                       - apply moves it onto a newer patch (the linux/amd64 manifest of the same
#                         image), pin moves it back, unpin keeps it running
#                      The region pulls exec-python by digest from ghcr.io: the host needs a way out.
#
#                      The stack's scheduler is stopped while the suite runs: every five minutes it
#                      reads the published feed (config defaults) into the same database and would
#                      replace the suite's feed in the middle of a check.
#
# KIND_LOCK          — one Kind cluster at a time on a shared host; this suite defaults it to
#                      /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

# TEST_BEFORE_E2E of cmd_test (the API stack runs, the current directory is the checkout)
runtime_patches_stop_scheduler() {
  echo "  stopping the stack's scheduler: the suite drives the feed and the deadline job itself"
  docker compose stop scheduler >/dev/null 2>&1 || echo -e "${YELLOW}  the scheduler could not be stopped: its feed sync may replace the suite's feed${NC}"
}

cmd_test_runtime_patches() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  export TEST_BEFORE_E2E=runtime_patches_stop_scheduler
  cmd_test --suite=runtime-patches "$@"
}
