# Timed warmer e2e (docs/scaling.md#timed-warmer).
#
# cmd_test_warmer — `ci.sh test-warmer [cmd_test flags]`: Kind + Knative (cmd_test), running only
#                   the warmer suite (test:e2e --suite=warmer, E2eTestWarmerTrait) on one Python
#                   function in the organization zone Europe/Istanbul:
#                    - the schedule preview: week timeline, zone, @daily refused
#                    - a deploy renders the schedule's value into the revision template, the
#                      PodAutoscaler gets it after the revision is ready, that many pods run
#                    - a saved schedule change is patched onto the PodAutoscaler (projects:warm):
#                      no new revision, Knative scales down to a pod that ran before, not restarted
#                    - the row that fired last decides; turning the warmer off gives min_scale
#                      back; a redeploy with a schedule starts the new revision at its value
#
# KIND_LOCK          — one Kind cluster at a time on a shared host; this suite defaults it to
#                      /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_warmer() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=warmer "$@"
}
