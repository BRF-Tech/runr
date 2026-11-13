#!/usr/bin/env bash
# Tests api/docker/runr-kubectl, the API image's kubectl of the server's Kubernetes minor, with
# kubectl stand-ins (no cluster): the server answers GET /version with a minor, each bundled
# "kubectl" says which minor it is and with which arguments it ran.
#   - the pick, as App\Services\Sandboxes\Exec\KubectlBinary picks (KubectlBinaryTest): the
#     server's minor, else the one below, else the one above; beyond every bundled minor's reach
#     the nearest with a warning; a server that does not answer, the newest;
#   - the call's arguments reach the bundled kubectl as they were (quotes, blanks, after --), and
#     its connection flags (--kubeconfig, --context, -s …) reach the version request;
#   - SANDBOX_KUBECTL names the binary; without a bundled kubectl the one on the PATH runs;
#   - the chart's Jobs run kubectl through it, and both API images install it.
#
#   bash runtime/test/runr_kubectl_test.sh
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
root="$(cd "$here/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
fail() { echo "FAIL: $*" >&2; exit 1; }
wrapper="$root/api/docker/runr-kubectl"
[ -f "$wrapper" ] || fail "no api/docker/runr-kubectl"

# a bundled kubectl stand-in: GET /version answers $FAKE_MINOR (none: no answer) and logs the
# version request's arguments; any other call prints its minor and arguments, one per line
bundle() {
  local dir="$1" m
  shift
  for m in "$@"; do
    mkdir -p "$dir/$m"
    cat > "$dir/$m/kubectl" <<EOF
#!/bin/sh
if [ "\$1" = get ] && [ "\$2" = --raw ] && [ "\$3" = /version ]; then
  printf '%s\n' "\$@" > "$tmp/version-args"
  [ -n "\${FAKE_MINOR:-}" ] || exit 1
  printf '{\n  "major": "1",\n  "minor": "%s",\n  "gitVersion": "v1.%s.2+k3s1"\n}\n' "\$FAKE_MINOR" "\${FAKE_MINOR%+}"
  exit 0
fi
echo "kubectl $m"
printf '%s\n' "\$@"
EOF
    chmod +x "$dir/$m/kubectl"
  done
}
bundle "$tmp/k" 1.31 1.33 1.35 1.37
export RUNR_KUBECTL_DIR="$tmp/k"
unset SANDBOX_KUBECTL
run() { sh "$wrapper" "$@" 2>"$tmp/stderr"; }

# --- the pick ---------------------------------------------------------------------------------------
for pair in 30:1.31 31:1.31 32:1.31 33:1.33 34:1.33 '34+:1.33' 35:1.35 36:1.35 37:1.37 38:1.37; do
  out="$(FAKE_MINOR="${pair%%:*}" run get pods)"
  [ "$(head -n 1 <<<"$out")" = "kubectl ${pair#*:}" ] || fail "Kubernetes 1.${pair%%:*}: $(head -n 1 <<<"$out"), not kubectl ${pair#*:}"
  [ ! -s "$tmp/stderr" ] || fail "Kubernetes 1.${pair%%:*}: a warning within reach: $(cat "$tmp/stderr")"
done
out="$(FAKE_MINOR=40 run get pods)"
[ "$(head -n 1 <<<"$out")" = "kubectl 1.37" ] && grep -qF 'no kubectl of the API image is within one minor of Kubernetes 1.40: kubectl 1.37 runs' "$tmp/stderr" \
  || fail "a newer server: $(head -n 1 <<<"$out"), $(cat "$tmp/stderr")"
out="$(FAKE_MINOR='' run get pods)"
[ "$(head -n 1 <<<"$out")" = "kubectl 1.37" ] || fail "a server that does not answer: $(head -n 1 <<<"$out")"
bundle "$tmp/gap" 1.31 1.37
out="$(RUNR_KUBECTL_DIR="$tmp/gap" FAKE_MINOR=34 run get pods)"
[ "$(head -n 1 <<<"$out")" = "kubectl 1.31" ] || fail "as near as two: the lower: $(head -n 1 <<<"$out")"
echo "ok - the pick: the server's minor, else the one below, else above; beyond reach the nearest with a warning; no answer, the newest"

# --- the arguments ----------------------------------------------------------------------------------
out="$(FAKE_MINOR=34 run -n runr --kubeconfig "/tmp/k c" --context=prod -s https://x:6443 get secret "a b" -o "jsonpath={.data.p}" -- sh -c "echo 'q'")"
expected="kubectl 1.33
-n
runr
--kubeconfig
/tmp/k c
--context=prod
-s
https://x:6443
get
secret
a b
-o
jsonpath={.data.p}
--
sh
-c
echo 'q'"
[ "$out" = "$expected" ] || fail "the call's arguments: ${out}"
[ "$(cat "$tmp/version-args")" = "get
--raw
/version
--request-timeout=10s
--kubeconfig
/tmp/k c
--context=prod
-s
https://x:6443" ] || fail "the version request's connection flags: $(cat "$tmp/version-args")"
out="$(FAKE_MINOR=34 run exec pod -- kubectl --context=other get x)"
grep -qxF -- '--context=other' "$tmp/version-args" && fail "a flag after -- reached the version request"
[ "$(tail -n 2 <<<"$out" | head -n 1)" = get ] || fail "the arguments after --: ${out}"
echo "ok - the call's arguments as they were; its connection flags (not those after --) on the version request"

# --- the override and the fallback ------------------------------------------------------------------
printf '#!/bin/sh\necho "override $*"\n' > "$tmp/override"
chmod +x "$tmp/override"
[ "$(SANDBOX_KUBECTL="$tmp/override" FAKE_MINOR=34 run get pods)" = "override get pods" ] || fail "SANDBOX_KUBECTL does not name the binary"
out="$(SANDBOX_KUBECTL=/usr/local/bin/runr-kubectl FAKE_MINOR=34 run get pods)"
[ "$(head -n 1 <<<"$out")" = "kubectl 1.33" ] || fail "SANDBOX_KUBECTL=runr-kubectl: ${out}"
mkdir -p "$tmp/path"
printf '#!/bin/sh\necho "path kubectl $*"\n' > "$tmp/path/kubectl"
chmod +x "$tmp/path/kubectl"
[ "$(RUNR_KUBECTL_DIR="$tmp/none" PATH="$tmp/path:$PATH" run get pods)" = "path kubectl get pods" ] || fail "no bundled kubectl: not the one on the PATH"
echo "ok - SANDBOX_KUBECTL names the binary (not itself); without a bundled kubectl the one on the PATH"

# --- where it is used -------------------------------------------------------------------------------
bare="$(grep -rnE '(^|[^-[:alnum:]_])kubectl( |$)' "$root/helm/templates" --include='*.yaml' --include='*.tpl' | grep -vE '^[^:]+:[0-9]+:[[:space:]]*#' || true)"
[ -z "$bare" ] || fail "a chart template runs kubectl without runr-kubectl: ${bare}"
grep -qF 'runr-kubectl -n {{ include "runr.namespace" . }} get secret runr-admin' "$root/helm/templates/bootstrap-job.yaml" || fail "the bootstrap Job reads runr-admin without runr-kubectl"
for f in api/Dockerfile api/Dockerfile.prod; do
  grep -qxF 'COPY docker/runr-kubectl /usr/local/bin/runr-kubectl' "$root/$f" || fail "${f} does not install runr-kubectl"
  grep -qF 'ln -s "/usr/local/lib/runr/kubectl/${newest}/kubectl" /usr/local/bin/kubectl' "$root/$f" || fail "${f}: kubectl on the PATH is not the newest of the set"
done
grep -qF "'kubectl_dir' => '/usr/local/lib/runr/kubectl'" "$root/api/config/sandbox.php" && grep -qF 'RUNR_KUBECTL_DIR:-/usr/local/lib/runr/kubectl' "$wrapper" \
  || fail "runr-kubectl and the API read different kubectl directories"
echo "ok - the chart's Jobs run kubectl through runr-kubectl; both API images install it next to the kubectl set"
