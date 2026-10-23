# Deploy strategies e2e (blue-green and canary rollouts, docs/deploy-strategies.md).
#
# cmd_test_deploy_strategies — `ci.sh test-deploy-strategies [cmd_test flags]`: Kind + Knative
#                              (cmd_test), running only the deploy-strategies suite (test:e2e
#                              --suite=deploy-strategies, E2eTestDeployStrategiesTrait) on one Python
#                              function whose answer names its version:
#                               - blue-green: the new revision waits for its promotion behind the test
#                                 URL green-<slug> while the host keeps the old one; promote moves all
#                                 traffic, previous-<slug> keeps the old revision warm; rollback
#                                 switches back at once
#                               - canary: a 25 % step splits the host's traffic (spec.traffic and a
#                                 burst of requests), the rollout promotes itself after its step
#                                 (AdvanceRolloutJob on the queue worker), an aborted canary leaves the
#                                 traffic where it was
#                               - direct again: the next deploy takes all traffic at once
#
# KIND_LOCK          — one Kind cluster at a time on a shared host; this suite defaults it to
#                      /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_deploy_strategies() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=deploy-strategies "$@"
}
