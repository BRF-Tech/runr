#!/usr/bin/env bash
# Tests the Cloud Provider KIND that the Kind suites share (lib/test-runtimes.sh) without Docker:
# cloud-provider-kind is a script that records when it starts and when it is stopped (the moment
# the real one deletes the load balancer containers of every Kind cluster of the host), kind is a
# shell function that lists the clusters in $tmp/clusters.
#
#   bash runtime/test/cloud_provider_kind_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
cleanup() {
  local pid
  while read -r pid; do kill "$pid" 2>/dev/null || true; done < "$tmp/started" 2>/dev/null || true
  rm -rf -- "$tmp"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

export CPK_PIDFILE="$tmp/cpk.pid"
# shellcheck source=../lib/common.sh
source "$here/lib/common.sh"
# shellcheck source=../lib/test-runtimes.sh
source "$here/lib/test-runtimes.sh"

mkdir -p "$tmp/bin"
cat > "$tmp/bin/cloud-provider-kind" <<EOF
#!/usr/bin/env bash
trap 'echo "\$\$" >> "$tmp/stopped"; exit 0' TERM
echo "\$\$" >> "$tmp/started"
while :; do sleep 0.1; done
EOF
chmod +x "$tmp/bin/cloud-provider-kind"
PATH="$tmp/bin:$PATH"

kind() {
  [ "$1 $2" = "get clusters" ] || return 0
  if [ -s "$tmp/clusters" ]; then cat "$tmp/clusters"; else echo "No kind clusters found." >&2; fi
}
clusters() { if [ "$#" -gt 0 ]; then printf '%s\n' "$@" > "$tmp/clusters"; else : > "$tmp/clusters"; fi; }
fresh() { : > "$tmp/started"; : > "$tmp/stopped"; }
started() { grep -c . "$tmp/started" || true; }
stopped() { grep -c . "$tmp/stopped" || true; }
# the fake writes its PID once it runs
wait_started() {
  for _ in $(seq 1 50); do [ "$(started)" -ge "$1" ] && return 0; sleep 0.1; done
  fail "cloud-provider-kind #$1 did not start"
}

# One run (CI): its own process, stopped at its end
fresh; clusters a
start_cloud_provider_kind >/dev/null
wait_started 1
pid=$(cat "$CPK_PIDFILE")
[ "$pid" = "$(sed -n 1p "$tmp/started")" ] || fail "the PID file ($pid) does not name the process that started"
stop_cloud_provider_kind a >/dev/null
[ "$(stopped)" = 1 ] || fail "the run's cloud-provider-kind was not stopped at its end"
! _cpk_alive "$pid" || fail "cloud-provider-kind $pid still runs after stop"
[ ! -e "$CPK_PIDFILE" ] || fail "PID file left behind"
echo "ok - one run starts cloud-provider-kind and stops it at its end"

# Two runs whose clusters overlap: the second joins the first one's process, the first leaves it
# running for the second, the second stops it
fresh; clusters a
start_cloud_provider_kind >/dev/null
wait_started 1
clusters a b
out=$(start_cloud_provider_kind)
[[ "$out" == *"already running"* ]] || fail "the second run did not join the running process: $out"
sleep 0.3
[ "$(started)" = 1 ] || fail "the second run started a second cloud-provider-kind"
out=$(stop_cloud_provider_kind a)
[ "$(stopped)" = 0 ] || fail "the first run stopped cloud-provider-kind while cluster b was up (b's load balancers deleted)"
[[ "$out" == *"Kind cluster b uses it"* ]] || fail "no word about the cluster that keeps it running: $out"
clusters b
stop_cloud_provider_kind b >/dev/null
[ "$(stopped)" = 1 ] || fail "the last run did not stop cloud-provider-kind"
[ ! -e "$CPK_PIDFILE" ] || fail "PID file left behind"
echo "ok - overlapping runs share one cloud-provider-kind and the last one stops it"

# A PID file of a process that is gone: a new process
fresh; clusters a
( exit 0 ) & gone=$!
wait "$gone" || true
echo "$gone" > "$CPK_PIDFILE"
start_cloud_provider_kind >/dev/null
wait_started 1
[ "$(cat "$CPK_PIDFILE")" != "$gone" ] || fail "a PID file of a process that is gone was taken as running"
stop_cloud_provider_kind a >/dev/null
[ "$(stopped)" = 1 ] || fail "the process started over a stale PID file was not stopped"
echo "ok - a stale PID file starts a new cloud-provider-kind"

# A PID file whose PID belongs to another process now (Linux, /proc): never taken for
# cloud-provider-kind, never stopped
if [ -r /proc/self/cmdline ]; then
  fresh; clusters a
  sleep 30 & other=$!
  echo "$other" > "$CPK_PIDFILE"
  start_cloud_provider_kind >/dev/null
  wait_started 1
  [ "$(cat "$CPK_PIDFILE")" != "$other" ] || fail "another process's PID was taken for cloud-provider-kind"
  stop_cloud_provider_kind a >/dev/null
  kill -0 "$other" 2>/dev/null || fail "stop killed a process that is not cloud-provider-kind"
  kill "$other" 2>/dev/null || true
  wait "$other" 2>/dev/null || true
  echo "ok - another process's PID in the PID file is left alone"
fi

# A run that ends with no cloud-provider-kind running: nothing to stop
fresh; clusters
stop_cloud_provider_kind a >/dev/null || fail "stop without a running cloud-provider-kind failed"
[ "$(stopped)" = 0 ] || fail "stop without a running cloud-provider-kind stopped something"
echo "ok - stop without a running cloud-provider-kind does nothing"
