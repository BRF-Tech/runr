# Triggers e2e (trigger lists, event gateway, webhook inbox, platform events, topics).
#
# cmd_test_triggers — `ci.sh test-triggers [cmd_test flags]`: Kind + Knative (cmd_test) running only
#                     the triggers suite (test:e2e --suite=triggers, docs/triggers.md): a Python
#                     function with an HTTP trigger gets a webhook inbox (generic HMAC), platform
#                     events about itself, a schedule and — with the region's Redis — a topic
#                     subscription, and is deployed once more. Verified: the service stays public
#                     and no CronJob runs, the event gateway mounts <slug>-qc-config; an unsigned
#                     webhook is refused, a signed one is stored and delivered by the gateway, its
#                     delivery id again is a duplicate; a body the function answers 500 ends
#                     failed after the retries and a replay delivers it again; the deploy's
#                     deployment.finished is delivered; the gateway delivers a schedule tick and a
#                     topic event published through the API (gateway /stats via the API proxy).
#
# KIND_LOCK         — one Kind cluster at a time on a shared host; this suite defaults it to
#                     /tmp/runr-kind.lock (the lock test-runtimes.sh takes when KIND_LOCK is set).

cmd_test_triggers() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=triggers "$@"
}
