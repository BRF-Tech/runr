# Region network e2e (the region's private ingress and NetworkPolicies).
#
# cmd_test_network — `ci.sh test-network [cmd_test flags]`: Kind + Knative (cmd_test) running only
#                    the network suite (test:e2e --suite=network, E2eTestNetworkTrait), on a public
#                    HTTP project (CaaS nginx) and a queue-triggered one (Python, cluster-local):
#                     - the region Traefik serves cluster-local routes on its private entry point
#                       only (Service traefik-internal), every KIngress names it, the
#                       NetworkPolicies are there
#                     - through the LoadBalancer a cluster-local host answers 404; in the cluster
#                       the cluster-local URL answers, from zero and warm
#                     - no pod reaches the revision's pods past the route (-private and revision
#                       Services, pod address on 8012 / 8080, activator-service with the Knative
#                       headers); scale to zero and from zero work, the autoscaler scrapes the pods
#                     - a queue message is delivered; a custom domain, a domain alias and a gateway
#                       route serve the public project, the alias not the cluster-local one
#                     - a region as an earlier release left it (cluster-local routes on web, no
#                       NetworkPolicy) is moved by region:reconcile's private-ingress and
#                       network-policy steps while probe pods call it; the disruption is printed
#                       and must be none
#
#                    The NetworkPolicies need a network plugin that enforces them (Kind's kindnet
#                    does).
#
# KIND_LOCK          — one Kind cluster at a time on a shared host; this suite defaults it to
#                      /tmp/runr-kind.lock (test-runtimes.sh: acquire_kind_lock).

cmd_test_network() {
  # exported, not a one-command prefix: the EXIT trap (_test_cleanup) releases the lock after
  # cmd_test has returned
  export KIND_LOCK="${KIND_LOCK:-/tmp/runr-kind.lock}"
  cmd_test --suite=network "$@"
}
