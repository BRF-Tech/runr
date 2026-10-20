# Observability e2e (tracing backend, auto-instrumentation, trace search, request replay).
#
# cmd_test_observability — `ci.sh test-observability [cmd_test flags]`: Kind + Knative (cmd_test)
#                          running only the observability suite (test:e2e --suite=observability):
#                          the region's OpenTelemetry Collector + Tempo reach "ready" (installed
#                          with Knative Serving, or through the API when they are not), a Python
#                          function with tracing and replay capture on is called, its trace is
#                          found through GET /api/projects/{p}/traces with the wrapper's server
#                          span, the trace's log lines carry its trace id, and the failed call is
#                          captured with its Authorization header and token query value redacted
#                          and replayed. Last, an uptime check with location "regions": the
#                          region's agent (redeployed to call RunR at the API container's address
#                          on the Kind network) fetches and runs it, its results arrive with the
#                          region's name as location and the agent's log has no
#                          "RunR answered 400" (obs-uptime-region).
#
# KIND_LOCK              — one Kind cluster at a time on a shared host; this suite defaults it to
#                          /tmp/runr-kind.lock (the lock test-runtimes.sh takes when KIND_LOCK is set).

cmd_test_observability() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=observability "$@"
}
