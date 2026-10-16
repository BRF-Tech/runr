#!/usr/bin/env bash
# Tests that a Kind e2e run (cmd_test, lib/test.sh) removes what it created when it is stopped
# from outside, without Docker or Kind: kind, docker, kubectl, curl and cloud-provider-kind are
# scripts that record their calls, and the fake e2e step (`docker compose exec … test:e2e`) runs
# until `docker compose down`, as the real one ends when the API container stops. Each run is a
# job of its own (set -m: its own process group, INT not ignored) and is stopped the ways a run
# gets stopped: TERM to the run (a cancelled CI job, timeout), INT to its process group (Ctrl-C),
# INT to the run alone, TERM to its process group after the reader of its output pipe has gone (a
# supervisor that stops the run's whole session), and a stop while it waits for another run's Kind
# lock or builds its Compose stack. Afterwards the run's Kind cluster, Compose project, Kind lock
# and Cloud Provider KIND are gone, and nothing of another run is touched.
#
#   bash runtime/test/kind_stop_test.sh
set -euo pipefail
set -m

here="$(cd "$(dirname "$0")/.." && pwd)"
tmp="$(mktemp -d)"
cleanup() {
  local p
  while read -r p; do kill -KILL -- "-$p" 2>/dev/null || true; done < "$tmp/jobs" 2>/dev/null || true
  rm -rf -- "$tmp"
}
trap cleanup EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }

mkdir -p "$tmp/bin" "$tmp/repo/runtime"
: > "$tmp/jobs"

# ── fakes: state and calls in $FAKE_DIR, one directory per run ───────────────────────────────────
cat > "$tmp/bin/kind" <<'EOF'
#!/usr/bin/env bash
echo "kind $*" >> "$FAKE_DIR/calls"
name="" prev=""
for a in "$@"; do [ "$prev" = "--name" ] && name="$a"; prev="$a"; done
case "$1 $2" in
  "create cluster") echo "$name" >> "$FAKE_DIR/clusters" ;;
  "delete cluster") { grep -vx -- "$name" "$FAKE_DIR/clusters" || true; } > "$FAKE_DIR/clusters.new"
                    mv "$FAKE_DIR/clusters.new" "$FAKE_DIR/clusters" ;;
  "get clusters") if [ -s "$FAKE_DIR/clusters" ]; then cat "$FAKE_DIR/clusters"; else echo "No kind clusters found." >&2; fi ;;
  "get kubeconfig") printf 'apiVersion: v1\nkind: Config\nclusters:\n- cluster:\n    server: https://127.0.0.1:6443\n' ;;
esac
exit 0
EOF
cat > "$tmp/bin/docker" <<'EOF'
#!/usr/bin/env bash
echo "docker $* project=${COMPOSE_PROJECT_NAME:-} dir=$(basename "$PWD")" >> "$FAKE_DIR/calls"
# until `docker compose down`, at most $1 s
until_down() { local i; for i in $(seq 1 $(($1 * 10))); do [ -e "$FAKE_DIR/down" ] && return 0; sleep 0.1; done; }
case "$1 $2" in
  "compose up") touch "$FAKE_DIR/up"; if [ -e "$FAKE_DIR/block-up" ]; then until_down 30; fi ;;
  "compose down") touch "$FAKE_DIR/down" ;;
  "compose exec") case "$*" in *test:e2e*) touch "$FAKE_DIR/e2e"; until_down "${FAKE_E2E_SECONDS:-30}" ;; esac ;;
esac
exit 0
EOF
cat > "$tmp/bin/kubectl" <<'EOF'
#!/usr/bin/env bash
echo "kubectl $*" >> "$FAKE_DIR/calls"
EOF
printf '#!/usr/bin/env bash\nexit 0\n' > "$tmp/bin/curl"
cat > "$tmp/bin/cloud-provider-kind" <<'EOF'
#!/usr/bin/env bash
trap 'echo "$$" >> "$FAKE_DIR/cpk-stopped"; exit 0' TERM
echo "$$" >> "$FAKE_DIR/cpk-started"
while :; do sleep 0.1; done
EOF
chmod +x "$tmp/bin/"*
export PATH="$tmp/bin:$PATH" KIND_PRELOAD=0 E2E_API_WAIT=5 COMPOSE_PROJECT_NAME=stoptest

# The run: ci.sh's cmd_test without the steps that need the real images
cat > "$tmp/run.sh" <<EOF
#!/usr/bin/env bash
set -euo pipefail
SCRIPT_DIR="$tmp/repo/runtime"
source "$here/lib/common.sh"
source "$here/lib/test-runtimes.sh"
source "$here/lib/test.sh"
prepare_runtime_e2e() { :; }
ensure_compose_app_key() { :; }
after_e2e() { touch "\$FAKE_DIR/after-e2e"; }
cmd_test "\$@"
EOF

# start NAME [VAR=VALUE…] — a run named stop-NAME as a job of its own; $pid, $d (its fakes' state)
start() {
  d="$tmp/$1"
  mkdir -p "$d"
  : > "$d/calls"
  env FAKE_DIR="$d" KIND_LOCK="$d/lock" KIND_LOCK_WAIT=60 CPK_PIDFILE="$d/cpk.pid" TMPDIR="$d" "${@:2}" \
    bash "$tmp/run.sh" --name="stop-$1" > "$d/out.log" 2>&1 &
  pid=$!
  echo "$pid" >> "$tmp/jobs"
}

# reached FILE WHAT — FILE appears within 10 s
reached() {
  local _
  for _ in $(seq 1 100); do [ -e "$1" ] && return 0; sleep 0.1; done
  fail "$2 not reached: $(cat "$d/out.log")"
}

# ended NAME — the run has ended within 20 s; its exit status in $status
ended() {
  local killer
  ( sleep 20; kill -KILL -- "-$pid" 2>/dev/null ) &
  killer=$!
  disown "$killer"
  status=0
  { wait "$pid"; } 2>/dev/null || status=$?
  kill -- "-$killer" 2>/dev/null || true
  [ "$status" != 137 ] || fail "$1: the run did not end within 20 s: $(cat "$d/out.log")"
}

# cleaned NAME — the run's Kind cluster, Compose project, Kind lock and Cloud Provider KIND are gone
cleaned() {
  local cpk
  grep -qx "kind delete cluster --name stop-$1" "$d/calls" || fail "$1: cluster not deleted: $(cat "$d/calls")"
  ! grep -qx "stop-$1" "$d/clusters" || fail "$1: cluster stop-$1 still there"
  grep -qx "docker compose down -v project=stoptest dir=repo" "$d/calls" || fail "$1: Compose project not stopped: $(cat "$d/calls")"
  [ ! -e "$d/lock" ] || fail "$1: Kind lock left behind"
  cpk=$(cat "$d/cpk-started")
  grep -qx "$cpk" "$d/cpk-stopped" 2>/dev/null || fail "$1: cloud-provider-kind $cpk not stopped"
  [ ! -e "$d/cpk.pid" ] || fail "$1: cloud-provider-kind's PID file left behind"
}

# ── the run ends by itself ───────────────────────────────────────────────────────────────────────
start ends FAKE_E2E_SECONDS=0 TEST_AFTER_E2E=after_e2e
ended ends
[ "$status" = 0 ] || fail "ends: exit $status: $(cat "$d/out.log")"
[ -e "$d/after-e2e" ] || fail "ends: the step after the e2e did not run"
cleaned ends
echo "ok - a run that ends removes its cluster, Compose project, lock and cloud-provider-kind"

# ── TERM to the run during its e2e step: cleaned up right away, not after the step ───────────────
start term
reached "$d/e2e" "term: the e2e step"
SECONDS=0
kill -TERM "$pid"
ended term
[ "$status" = 143 ] || fail "term: exit $status"
[ "$SECONDS" -lt 10 ] || fail "term: the cleanup waited for the e2e step (${SECONDS} s)"
cleaned term
grep -q '^Cleanup\.\.\.' "$d/out.log" || fail "term: no cleanup output: $(cat "$d/out.log")"
echo "ok - TERM: cleaned up at once, while the e2e step still ran"

# ── Ctrl-C: INT to the run's process group ───────────────────────────────────────────────────────
start ctrl-c
reached "$d/e2e" "ctrl-c: the e2e step"
kill -INT -- "-$pid"
ended ctrl-c
[ "$status" = 130 ] || fail "ctrl-c: exit $status"
cleaned ctrl-c
echo "ok - INT to the process group (Ctrl-C): cleaned up"

# ── INT to the run alone: it ends once its foreground step has, instead of going on ──────────────
start int FAKE_E2E_SECONDS=2 TEST_AFTER_E2E=after_e2e
reached "$d/e2e" "int: the e2e step"
kill -INT "$pid"
ended int
[ "$status" = 130 ] || fail "int: exit $status"
[ ! -e "$d/after-e2e" ] || fail "int: the run went on after the INT"
cleaned int
echo "ok - INT to the run alone: it stops after the running step and cleans up"

# ── TERM to the process group after the reader of the run's output has gone ──────────────────────
d="$tmp/pipe"
mkdir -p "$d"
: > "$d/calls"
# shellcheck disable=SC2016 # expanded by the run's bash
env FAKE_DIR="$d" KIND_LOCK="$d/lock" KIND_LOCK_WAIT=60 CPK_PIDFILE="$d/cpk.pid" TMPDIR="$d" \
  bash -c 'exec > >(cat >> "$1") 2>&1; echo "$!" > "$2"; exec bash "$3" --name=stop-pipe' _ \
  "$d/out.log" "$d/reader.pid" "$tmp/run.sh" &
pid=$!
echo "$pid" >> "$tmp/jobs"
reached "$d/e2e" "pipe: the e2e step"
kill "$(cat "$d/reader.pid")"
sleep 0.5
kill -TERM -- "-$pid"
ended pipe
[ "$status" = 143 ] || fail "pipe: exit $status"
cleaned pipe
grep -q '^Cleanup\.\.\.' "$d/stop-pipe-cleanup.log" 2>/dev/null || fail "pipe: the cleanup's output is not in its log: $(ls "$d")"
grep -q '^Deleting Kind cluster: stop-pipe' "$d/stop-pipe-cleanup.log" || fail "pipe: $(cat "$d/stop-pipe-cleanup.log")"
echo "ok - the whole process group stopped, output pipe gone: cleaned up, output in <cluster>-cleanup.log"

# ── stopped while it builds its Compose stack ───────────────────────────────────────────────────
d="$tmp/compose-up"
mkdir -p "$d"
touch "$d/block-up"
start compose-up
reached "$d/up" "compose-up: docker compose up"
kill -TERM "$pid"
ended compose-up
[ "$status" = 143 ] || fail "compose-up: exit $status"
cleaned compose-up
echo "ok - stopped during docker compose up: its Compose project is stopped too"

# ── stopped while another run holds the Kind lock: nothing of that run is touched ────────────────
d="$tmp/lock-wait"
mkdir -p "$d/lock"
echo "other-run 4242 2026-10-16T00:00:00Z" > "$d/lock/owner"
echo "stop-lock-wait" > "$d/clusters"
start lock-wait
for _ in $(seq 1 100); do grep -q 'busy' "$d/out.log" && break; sleep 0.1; done
grep -q "Kind lock $d/lock busy (other-run 4242" "$d/out.log" || fail "lock-wait: not waiting: $(cat "$d/out.log")"
kill -TERM "$pid"
ended lock-wait
[ "$status" = 143 ] || fail "lock-wait: exit $status"
[ "$(cat "$d/lock/owner")" = "other-run 4242 2026-10-16T00:00:00Z" ] || fail "lock-wait: the other run's lock was touched"
grep -qx "stop-lock-wait" "$d/clusters" || fail "lock-wait: the other run's cluster of the same name was deleted"
! grep -q '^kind delete\|^kind create\|^docker compose' "$d/calls" || fail "lock-wait: $(cat "$d/calls")"
[ ! -e "$d/cpk-started" ] || fail "lock-wait: cloud-provider-kind started"
echo "ok - stopped while waiting for the Kind lock: the lock holder's lock and cluster stay"

# finish_kind_cluster without the lock (test-helm-upgrade names its cluster before it waits)
d="$tmp/finish"
mkdir -p "$d/lock"
echo "other-run 4243 2026-10-16T00:00:00Z" > "$d/lock/owner"
echo "runr-upgrade-helm" > "$d/clusters"
: > "$d/calls"
(
  export FAKE_DIR="$d" KIND_LOCK="$d/lock" CPK_PIDFILE="$d/cpk.pid"
  # shellcheck source=../lib/common.sh
  source "$here/lib/common.sh"
  # shellcheck source=../lib/test-runtimes.sh
  source "$here/lib/test-runtimes.sh"
  finish_kind_cluster runr-upgrade-helm 1
) > "$d/out.log" 2>&1 || fail "finish_kind_cluster without the lock: $(cat "$d/out.log")"
! grep -q '^kind delete' "$d/calls" || fail "finish_kind_cluster deleted a cluster of a run that never held the lock"
[ -e "$d/lock/owner" ] || fail "finish_kind_cluster released a lock it did not hold"
echo "ok - finish_kind_cluster: no lock, no delete"

echo "ok: kind-stop"
