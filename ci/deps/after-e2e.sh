#!/usr/bin/env bash
# The dependency test bot's check after an e2e suite (TEST_AFTER_E2E of runtime/ci.sh test, which
# calls it with the API's base URL and the cluster's kubeconfig while the cluster still runs): the
# e2e region runs the version under test, so a green suite really exercised it.
#
#   DEPS_EXPECT=NAMESPACE/RELEASE=CHART-VERSION   the Helm release runs that chart
#                                                 (knative-serving/traefik=traefik-42.3.1)
#   DEPS_EXPECT_KNATIVE=MINOR                     Knative Serving runs that minor (1.25)
#
#   ci/deps/after-e2e.sh BASE_URL KUBECONFIG
#
# Needs helm, kubectl and jq.
set -euo pipefail

kubeconfig="${2:?usage: after-e2e.sh BASE_URL KUBECONFIG}"
fail=0

if [ -n "${DEPS_EXPECT:-}" ]; then
  ns="${DEPS_EXPECT%%/*}"
  rest="${DEPS_EXPECT#*/}"
  release="${rest%%=*}"
  want="${rest#*=}"
  got="$(helm --kubeconfig "$kubeconfig" list -n "$ns" -o json 2>/dev/null \
    | jq -r --arg r "$release" '[.[] | select(.name == $r)][0].chart // empty')"
  if [ "$got" = "$want" ]; then
    echo "✓ Helm release ${ns}/${release} runs ${want}"
  else
    echo "✗ Helm release ${ns}/${release} runs ${got:-nothing}, not ${want}: the e2e did not exercise the new chart"
    fail=1
  fi
fi

if [ -n "${DEPS_EXPECT_KNATIVE:-}" ]; then
  got="$(kubectl --kubeconfig "$kubeconfig" get knativeservings.operator.knative.dev -A -o json 2>/dev/null \
    | jq -r '[.items[] | (.status.version // .spec.version // empty)][0] // empty')"
  if [[ "${got#v}" == "${DEPS_EXPECT_KNATIVE}."* ]]; then
    echo "✓ Knative Serving runs ${got}"
  else
    echo "✗ Knative Serving runs ${got:-nothing}, not ${DEPS_EXPECT_KNATIVE}: the e2e did not exercise the new minor"
    fail=1
  fi
fi

exit "$fail"
