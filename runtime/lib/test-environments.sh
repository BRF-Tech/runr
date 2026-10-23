# Project environments e2e (staging next to production, promotion, rollback restoring the config).
#
# cmd_test_environments — `ci.sh test-environments [cmd_test flags]`: Kind + Knative (cmd_test),
#                         running only the environments suite (test:e2e --suite=environments,
#                         E2eTestEnvironmentsTrait) on one Python function:
#                          - an environment "staging" with an overridden env var: its own Knative
#                            Service <slug>-staging answers with RUNR_ENVIRONMENT=staging, production
#                            keeps answering with its own config; staging's pods carry
#                            runr/environment-id instead of runr/project-id
#                          - a new version on staging promoted to production: the same version, not
#                            built again, with production's own config
#                          - a config change of production rolled back to the earlier deployment:
#                            the earlier config comes back with the code; keeping the current config
#                            keeps the new one
#                          - deleting the environment removes its Knative Service
#
# KIND_LOCK            — one Kind cluster at a time on a shared host; this suite defaults it to
#                        /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_environments() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=environments "$@"
}
