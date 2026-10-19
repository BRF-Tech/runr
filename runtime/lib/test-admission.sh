# Project admission e2e (stop / resume, region status, fixed-region allocations).
#
# cmd_test_admission — `ci.sh test-admission [cmd_test flags]`: Kind + Knative (cmd_test) with the
#                      Billing module on (BILLING_ENABLED=true), running only the admission suite
#                      (test:e2e --suite=admission, E2eTestAdmissionTrait) on one Python function:
#                       - stop: the function stops serving, deploys answer 409 project_stopped;
#                         resume: the redeploy becomes active and the function answers again
#                       - region status maintenance / inactive: the function's host answers 503 / 404
#                         from the region's status responder and deploys are refused; active again:
#                         it answers 200
#                       - billing type fixed: a deploy without an approved allocation and one whose
#                         worst case (max_scale) is above the allocation are refused (422
#                         fixed_allocation_required / _exceeded), a request below what runs too
#                         (422 scale_down_first),
#                         one within a new allocation is admitted and serves; deleting the project
#                         ends the allocation, so the region's type changes back to payg
#
# KIND_LOCK          — one Kind cluster at a time on a shared host; this suite defaults it to
#                      /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_admission() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned; BILLING_ENABLED reaches the compose services (docker-compose.yml)
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  export BILLING_ENABLED=true
  cmd_test --suite=admission "$@"
}
