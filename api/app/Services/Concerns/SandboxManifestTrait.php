<?php

namespace App\Services\Concerns;

use App\Services\Helpers\ContainerRuntimeHelper;

trait SandboxManifestTrait
{
    /**
     * Pinned gVisor release (storage.googleapis.com/gvisor/releases/release/<release>/<arch>/).
     * Releases ship gvisor.tar.bz2 (runsc + containerd-shim-runsc-v1 at the tarball root, the
     * sidecar binaries runsc needs in gvisor-bin/) with gvisor.tar.bz2.sha512; the single-binary
     * URLs (<arch>/runsc) are gone.
     */
    public const GVISOR_RELEASE = '20260921.0';

    /**
     * Installer DaemonSet: installs runsc and registers it with containerd on every node.
     *
     * @param  array  $runtime  ContainerRuntimeHelper::resolveCluster() result for the target nodes
     */
    private function buildGvisorDaemonSet(string $namespace, array $runtime = []): array
    {
        return $this->buildGvisorNodeDaemonSet($namespace, 'gvisor-installer', $this->gvisorInstallScript(), '/tmp/runr-gvisor-ready', $runtime, [
            // Unpacking the ~160 MB release tarball takes ~1.5 min at this CPU limit
            // (the install wait allows 10 min)
            'requests' => ['cpu' => '100m', 'memory' => '128Mi'],
            'limits' => ['cpu' => '200m', 'memory' => '256Mi'],
        ], ['GVISOR_RELEASE' => self::GVISOR_RELEASE]);
    }

    /**
     * Cleanup DaemonSet (uninstall, rollback of a failed first install): removes the runsc
     * runtime from containerd's config, restarts the runtime when it still has runsc loaded and
     * removes the binaries — on every node the installer runs on.
     *
     * @param  array  $runtime  ContainerRuntimeHelper::resolveCluster() result for the target nodes
     */
    private function buildGvisorCleanupDaemonSet(string $namespace, array $runtime = []): array
    {
        return $this->buildGvisorNodeDaemonSet($namespace, 'gvisor-cleanup', $this->gvisorCleanupScript(), '/tmp/runr-gvisor-clean', $runtime, [
            'requests' => ['cpu' => '50m', 'memory' => '32Mi'],
            'limits' => ['cpu' => '200m', 'memory' => '128Mi'],
        ]);
    }

    private function buildGvisorNodeDaemonSet(
        string $namespace,
        string $name,
        string $script,
        string $readyMarker,
        array $runtime,
        array $resources,
        array $extraEnv = [],
    ): array {
        $flavor = $runtime['flavor'] ?? ContainerRuntimeHelper::FLAVOR_CONTAINERD;
        $major = $runtime['containerd_major'] ?? null;

        $env = [['name' => 'NODE_NAME', 'valueFrom' => ['fieldRef' => ['fieldPath' => 'spec.nodeName']]]];
        foreach ($extraEnv as $key => $value) {
            $env[] = ['name' => $key, 'value' => $value];
        }
        $env = array_merge($env, [
            ['name' => 'RUNTIME_FLAVOR',   'value' => $flavor],
            ['name' => 'CONTAINERD_MAJOR', 'value' => (string) ($major ?? '')],
            ['name' => 'CONTAINERD_CONFIG_DIR', 'value' => ContainerRuntimeHelper::configDir($flavor)],
            ['name' => 'CONTAINERD_SOCKET', 'value' => ContainerRuntimeHelper::socketPath($flavor)],
            ['name' => 'RESTART_UNITS', 'value' => implode(' ', ContainerRuntimeHelper::restartUnits($flavor))],
        ]);

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'gvisor'],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['runr.dev/component' => $name]],
                'template' => [
                    'metadata' => ['labels' => ['runr.dev/component' => $name]],
                    'spec' => [
                        'hostPID' => true,
                        // sh (PID 1) ignores SIGTERM while it sleeps: don't wait 30 s on delete
                        'terminationGracePeriodSeconds' => 5,
                        'nodeSelector' => ['kubernetes.io/os' => 'linux'],
                        'tolerations' => [[
                            'key' => 'runr.dev/dedicated',
                            'operator' => 'Equal',
                            'value' => 'projects',
                            'effect' => 'NoSchedule',
                        ]],
                        'containers' => [[
                            'name' => $name === 'gvisor-installer' ? 'installer' : 'cleanup',
                            'image' => 'ghcr.io/brf-tech/reqs/alpine:3.21',
                            'securityContext' => ['privileged' => true],
                            'command' => ['sh', '-c', $script],
                            'env' => $env,
                            // "runr: ERROR ..." of a failed run ends up in the pod status → shown by the installer
                            'terminationMessagePolicy' => 'FallbackToLogsOnError',
                            // Ready only after the script finished its work on the node (it writes the
                            // marker at the end) — the service waits for DaemonSet readiness
                            'readinessProbe' => [
                                'exec' => ['command' => ['cat', $readyMarker]],
                                'initialDelaySeconds' => 5,
                                'periodSeconds' => 5,
                            ],
                            'volumeMounts' => [
                                ['name' => 'host-root', 'mountPath' => '/host'],
                            ],
                            'resources' => $resources,
                        ]],
                        'volumes' => [
                            ['name' => 'host-root', 'hostPath' => ['path' => '/']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function buildGvisorRuntimeClass(): array
    {
        return [
            'apiVersion' => 'node.k8s.io/v1',
            'kind' => 'RuntimeClass',
            'metadata' => [
                'name' => 'gvisor',
                'labels' => ['app.kubernetes.io/managed-by' => 'runr'],
            ],
            'handler' => 'runsc',
        ];
    }

    /**
     * Shell helpers shared by the install and cleanup scripts (definitions only; the scripts run
     * in a privileged pod with the node's / at /host and hostPID).
     *
     * - runsc_block / strip_runsc: add / remove the runsc runtime table(s) of a containerd config
     *   (strip_runsc(file + runsc_block) == file).
     * - validate_config: `containerd --config <file> config dump` with the node's own containerd
     *   binary (k3s/rke2: their bundled one) — every config change is checked before a restart.
     * - runtime_loaded: whether the running runtime has runsc (crictl info).
     * - restart_runtime: restart the unit that owns containerd (containerd, or k3s/rke2 whose
     *   restart also restarts kubelet — staggered per node; running pods survive) and wait until
     *   runsc is loaded / unloaded.
     */
    private function gvisorHelpersScript(): string
    {
        return <<<'BASH'
NODE="${NODE_NAME:-$(hostname)}"
HOST=/host
BIN_DIR="$HOST/usr/local/bin"
CONF_DIR="$HOST${CONTAINERD_CONFIG_DIR:-/etc/containerd}"
fail() { echo "runr: ERROR: $* (node $NODE)" | tee /dev/termination-log; exit 1; }
hostctl() { nsenter --target 1 --mount --uts --ipc --pid -- "$@"; }

RUNSC_V3="[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runsc]"
RUNSC_V2='[plugins."io.containerd.grpc.v1.cri".containerd.runtimes.runsc]'
RUNC_V3="[plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runc]"
RUNC_V2='[plugins."io.containerd.grpc.v1.cri".containerd.runtimes.runc]'

# path of a $HOST file as the node sees it
host_path() { echo "${1#"$HOST"}"; }
has_runsc() { [ -f "$1" ] && grep -Eq 'runtimes\.runsc\]|io\.containerd\.runsc\.v1' "$1"; }
config_version() { awk '/^[[:space:]]*\[/ { exit } /^[[:space:]]*version[[:space:]]*=/ { v = $0; sub(/^[^=]*=/, "", v); gsub(/[^0-9]/, "", v); print v; exit }' "$1"; }
runsc_block() { printf '\n%s\n  runtime_type = "io.containerd.runsc.v1"\n' "$1"; }
# $1 without its runsc tables ([...runtimes.runsc] and sub-tables) and the blank lines before them
strip_runsc() {
  awk '
    /^[[:space:]]*\[/ { skip = ($0 ~ /runtimes\.runsc[].]/); if (skip) held = "" }
    skip { next }
    /^[[:space:]]*$/ { held = held $0 "\n"; next }
    { printf "%s", held; held = ""; print }
    END { printf "%s", held }
  ' "$1"
}

# containerd binary of the node, able to run `config dump` (node path)
find_containerd() {
  case "$RUNTIME_FLAVOR" in
    k3s)  C=/var/lib/rancher/k3s/data/current/bin/containerd ;;
    rke2) C=/var/lib/rancher/rke2/bin/containerd ;;
    *)    C="" ;;
  esac
  if [ -n "$C" ] && hostctl test -x "$C"; then echo "$C"; return 0; fi
  P=$(pgrep -xo containerd 2>/dev/null)
  if [ -n "$P" ]; then
    C=$(readlink "/proc/$P/exe" 2>/dev/null); C="${C% (deleted)}"
    case "$C" in */containerd) if hostctl test -x "$C"; then echo "$C"; return 0; fi ;; esac
  fi
  C=$(hostctl sh -c 'command -v containerd' 2>/dev/null)
  [ -n "$C" ] && echo "$C"
}

# validate_config <node path of a config> <runsc expected: yes|no|any> — sets VALIDATION_ERROR
validate_config() {
  VALIDATION_ERROR=""
  CTRD=$(find_containerd)
  if [ -z "$CTRD" ]; then VALIDATION_ERROR="no containerd binary found on the node to validate $1 with"; return 1; fi
  if ! DUMP=$(hostctl "$CTRD" --config "$1" config dump 2>&1); then
    VALIDATION_ERROR="containerd rejects $1: $(echo "$DUMP" | tail -n 3 | tr '\n' ' ')"
    return 1
  fi
  if echo "$DUMP" | grep -Eq 'runtimes\.runsc[].]'; then R=yes; else R=no; fi
  if [ "$2" != any ] && [ "$2" != "$R" ]; then
    if [ "$2" = yes ]; then VALIDATION_ERROR="$1 is valid but does not register runsc"; else VALIDATION_ERROR="$1 still registers runsc"; fi
    return 1
  fi
  echo "containerd accepts $1 (runsc registered: $R)"
}

# 0 = runsc loaded, 1 = runtime up without runsc, 2 = unknown (no crictl on the node), 3 = runtime not answering
runtime_loaded() {
  OUT=$(hostctl sh -c '
    if command -v crictl >/dev/null 2>&1; then crictl --runtime-endpoint "unix://$1" info
    elif command -v k3s >/dev/null 2>&1; then k3s crictl info
    elif [ -x /var/lib/rancher/rke2/bin/crictl ]; then /var/lib/rancher/rke2/bin/crictl --runtime-endpoint "unix://$1" info
    else exit 42
    fi' sh "$CONTAINERD_SOCKET" 2>/dev/null)
  RC=$?
  [ "$RC" = 42 ] && return 2
  echo "$OUT" | grep -q '"runtimes"' || return 3
  echo "$OUT" | grep -q '"runsc"' && return 0
  return 1
}

# restart_runtime <loaded|unloaded>: restart the runtime's unit, wait until runsc is in that state
restart_runtime() {
  UNIT=""
  for u in $RESTART_UNITS; do
    if hostctl systemctl is-active --quiet "$u"; then UNIT="$u"; break; fi
  done
  [ -n "$UNIT" ] || fail "no active systemd unit ($RESTART_UNITS) to restart — restart the container runtime manually"
  if [ "$RUNTIME_FLAVOR" != "containerd" ]; then
    # containerd runs inside the $UNIT process: its restart also restarts kubelet (and the API
    # server on server nodes). Running pods survive. Stagger nodes so they don't restart together.
    J=$(( $(echo "$NODE" | cksum | cut -d' ' -f1) % 60 ))
    echo "restarting $UNIT in ${J}s..."
    sleep "$J"
  fi
  hostctl systemctl restart "$UNIT" || fail "systemctl restart $UNIT failed"
  echo "$UNIT restarted"
  i=0
  while :; do
    runtime_loaded; S=$?
    case "$1:$S" in
      loaded:0|unloaded:1) return 0 ;;
      *:2) echo "no crictl on the node: runsc state after the restart not checked"; return 0 ;;
    esac
    i=$((i + 1))
    if [ "$i" -gt 36 ]; then
      if [ "$1" = loaded ]; then fail "runsc not loaded 3 min after restarting $UNIT — check the $UNIT log"; fi
      fail "runsc still loaded 3 min after restarting $UNIT — check the $UNIT log"
    fi
    sleep 5
  done
}
# -- end of helpers --

BASH;
    }

    /**
     * Distro-agnostic install script using static binaries.
     * Works on Ubuntu, RHEL, Flatcar, Talos, Bottlerocket, etc.
     * Downloads the pinned gVisor release tarball (GVISOR_RELEASE), verifies its sha512 and
     * installs runsc, containerd-shim-runsc-v1 and runsc's sidecars (gvisor-bin/) from it into
     * /usr/local/bin.
     *
     * Registers the runsc runtime with the node's containerd:
     * - containerd: /etc/containerd/config.toml, section per config version
     *   (v2 = containerd 1.x: [plugins."io.containerd.grpc.v1.cri".containerd.runtimes.runsc],
     *    v3 = containerd 2.x: [plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.runsc]).
     *   The new config is built next to the old one and must pass `containerd config dump`
     *   before it replaces config.toml (rewritten in place: owner, mode, SELinux label kept; the
     *   first install keeps config.toml.runr-pre-gvisor); then the containerd unit is restarted
     *   (running containers survive).
     * - k3s / rke2: config.toml is generated at start → drop-in under config[-v3].toml.d when the
     *   generated config imports it (validated the same way, removed again if containerd rejects
     *   it), otherwise config[-v3].toml.tmpl ({{ template "base" . }} + runsc); then restart the
     *   k3s / k3s-agent / rke2-* unit that owns containerd (kubelet restarts, pods survive;
     *   staggered per node).
     * Idempotent: a config that already has the runsc runtime is left as it is (no second block),
     * and nothing is restarted when runsc is already configured and loaded. Failures exit with
     * "runr: ERROR: ..." (termination message) instead of silently writing an unused file.
     */
    private function gvisorInstallScript(): string
    {
        return $this->gvisorHelpersScript().<<<'BASH'
ARCH=$(uname -m)
case "$ARCH" in
  x86_64)  ARCH="x86_64" ;;
  aarch64) ARCH="aarch64" ;;
  *) fail "Unsupported architecture: $ARCH" ;;
esac

RELEASE_URL="https://storage.googleapis.com/gvisor/releases/release/${GVISOR_RELEASE:-latest}/${ARCH}"

# 1) Binaries: gvisor.tar.bz2 (runsc + containerd-shim-runsc-v1 at its root, runsc's sidecars
#    in gvisor-bin/), sha512-verified. runsc starts gvisor-bin/gvisor_sentry etc. from the directory
#    next to itself and refuses to create a sandbox without them (--sidecar-usage-policy STRICT).
if [ -x "$BIN_DIR/runsc" ] && [ -x "$BIN_DIR/containerd-shim-runsc-v1" ] && [ -x "$BIN_DIR/gvisor-bin/gvisor_sentry" ]; then
  echo "gVisor binaries present: $(chroot "$HOST" /usr/local/bin/runsc --version 2>/dev/null | head -n 1)"
else
  echo "Installing gVisor ${GVISOR_RELEASE:-latest} (${ARCH}) from ${RELEASE_URL}/gvisor.tar.bz2 ..."
  TMP=$(mktemp -d /tmp/gvisor.XXXXXX) || fail "cannot create a temp dir"
  wget -q -O "$TMP/gvisor.tar.bz2" "${RELEASE_URL}/gvisor.tar.bz2" || fail "download of ${RELEASE_URL}/gvisor.tar.bz2 failed"
  wget -q -O "$TMP/gvisor.tar.bz2.sha512" "${RELEASE_URL}/gvisor.tar.bz2.sha512" || fail "download of ${RELEASE_URL}/gvisor.tar.bz2.sha512 failed"
  (cd "$TMP" && sha512sum -c gvisor.tar.bz2.sha512 >/dev/null 2>&1) || fail "gvisor.tar.bz2 does not match its sha512 checksum"
  mkdir -p "$TMP/x" && tar -xjf "$TMP/gvisor.tar.bz2" -C "$TMP/x" || fail "cannot unpack gvisor.tar.bz2"
  rm -f "$TMP/gvisor.tar.bz2"
  [ -x "$TMP/x/gvisor-bin/gvisor_sentry" ] || fail "gvisor-bin/gvisor_sentry missing in gvisor.tar.bz2"
  mkdir -p "$BIN_DIR"
  # sidecars first: a new runsc never runs without them
  rm -rf "$BIN_DIR/gvisor-bin.runr-tmp"
  if ! { cp -a "$TMP/x/gvisor-bin" "$BIN_DIR/gvisor-bin.runr-tmp" && rm -rf "$BIN_DIR/gvisor-bin" && mv "$BIN_DIR/gvisor-bin.runr-tmp" "$BIN_DIR/gvisor-bin"; }; then
    rm -rf "$BIN_DIR/gvisor-bin.runr-tmp"
    fail "cannot install gvisor-bin into /usr/local/bin"
  fi
  mv "$TMP/x/runsc" "$TMP/x/containerd-shim-runsc-v1" "$TMP/" 2>/dev/null
  for b in runsc containerd-shim-runsc-v1; do
    [ -s "$TMP/$b" ] || fail "$b missing in gvisor.tar.bz2"
    if ! { cp "$TMP/$b" "$BIN_DIR/$b.runr-tmp" && chmod +x "$BIN_DIR/$b.runr-tmp" && mv "$BIN_DIR/$b.runr-tmp" "$BIN_DIR/$b"; }; then
      rm -f "$BIN_DIR/$b.runr-tmp"
      fail "cannot install $b into /usr/local/bin"
    fi
  done
  rm -rf "$TMP"
  echo "gVisor binaries installed: $(chroot "$HOST" /usr/local/bin/runsc --version 2>/dev/null | head -n 1)"
fi

# 2) Runtime registration
CHANGED=0
case "$RUNTIME_FLAVOR" in
  k3s|rke2)
    GEN="$CONF_DIR/config.toml"
    [ -f "$GEN" ] || fail "$RUNTIME_FLAVOR containerd config ${CONTAINERD_CONFIG_DIR}/config.toml not found"
    case "$(config_version "$GEN")" in
      3) BLOCK="$RUNSC_V3"; DROPIN_DIR="$CONF_DIR/config-v3.toml.d"; TMPL="$CONF_DIR/config-v3.toml.tmpl" ;;
      2) BLOCK="$RUNSC_V2"; DROPIN_DIR="$CONF_DIR/config.toml.d";    TMPL="$CONF_DIR/config.toml.tmpl" ;;
      *) fail "unsupported $RUNTIME_FLAVOR containerd config version in ${CONTAINERD_CONFIG_DIR}/config.toml" ;;
    esac
    if has_runsc "$GEN" || has_runsc "$DROPIN_DIR/runr-runsc.toml" || has_runsc "$TMPL"; then
      echo "runsc already configured for $RUNTIME_FLAVOR"
    elif grep -q "^imports.*$(basename "$DROPIN_DIR")" "$GEN"; then
      mkdir -p "$DROPIN_DIR"
      # *.toml.runr-tmp is not matched by the imports glob until it is complete
      if ! { runsc_block "$BLOCK" > "$DROPIN_DIR/runr-runsc.toml.runr-tmp" && mv "$DROPIN_DIR/runr-runsc.toml.runr-tmp" "$DROPIN_DIR/runr-runsc.toml"; }; then
        rm -f "$DROPIN_DIR/runr-runsc.toml.runr-tmp"
        fail "cannot write the runsc drop-in $(host_path "$DROPIN_DIR")/runr-runsc.toml"
      fi
      if ! validate_config "$(host_path "$GEN")" yes; then
        rm -f "$DROPIN_DIR/runr-runsc.toml"
        fail "$VALIDATION_ERROR — drop-in removed again, nothing restarted"
      fi
      echo "runsc drop-in written: $(host_path "$DROPIN_DIR")/runr-runsc.toml"
      CHANGED=1
    elif [ -f "$TMPL" ]; then
      # rendered by $RUNTIME_FLAVOR at its restart (a template cannot be checked with config dump)
      runsc_block "$BLOCK" >> "$TMPL" || fail "cannot append runsc to $(host_path "$TMPL")"
      echo "runsc appended to existing $(host_path "$TMPL")"
      CHANGED=1
    else
      { echo '{{ template "base" . }}'; runsc_block "$BLOCK"; } > "$TMPL" || fail "cannot write $(host_path "$TMPL")"
      echo "runsc template written: $(host_path "$TMPL")"
      CHANGED=1
    fi
    ;;
  containerd)
    CFG="$CONF_DIR/config.toml"
    NEW="$CFG.runr-new"
    if has_runsc "$CFG"; then
      echo "runsc already configured in containerd config.toml"
    else
      mkdir -p "$CONF_DIR"
      if [ ! -f "$CFG" ]; then
        # No config: containerd runs on defaults → new file with the matching config version
        if [ "${CONTAINERD_MAJOR:-1}" -ge 2 ] 2>/dev/null; then
          { echo 'version = 3'; printf '\n%s\n  runtime_type = "io.containerd.runc.v2"\n' "$RUNC_V3"; runsc_block "$RUNSC_V3"; } > "$NEW"
        else
          { echo 'version = 2'; printf '\n%s\n  runtime_type = "io.containerd.runc.v2"\n' "$RUNC_V2"; runsc_block "$RUNSC_V2"; } > "$NEW"
        fi
      else
        case "$(config_version "$CFG")" in
          3) BLOCK="$RUNSC_V3" ;;
          2) BLOCK="$RUNSC_V2" ;;
          *) fail "unsupported containerd config version in /etc/containerd/config.toml (convert it with: containerd config migrate)" ;;
        esac
        { cat "$CFG"; runsc_block "$BLOCK"; } > "$NEW" || fail "cannot write $(host_path "$NEW")"
      fi
      if ! validate_config "$(host_path "$NEW")" yes; then
        rm -f "$NEW"
        fail "$VALIDATION_ERROR — config.toml left unchanged, nothing restarted"
      fi
      if [ -f "$CFG" ]; then
        [ -f "$CFG.runr-pre-gvisor" ] || cp -p "$CFG" "$CFG.runr-pre-gvisor"
        # rewritten in place: owner, mode and SELinux label (or a symlinked config) stay as they are
        cat "$NEW" > "$CFG" || fail "cannot write /etc/containerd/config.toml"
        rm -f "$NEW"
        echo "runsc added to containerd config.toml"
      else
        mv "$NEW" "$CFG" || fail "cannot write /etc/containerd/config.toml"
        echo "containerd config.toml created with runsc"
      fi
      CHANGED=1
    fi
    ;;
  *)
    fail "gVisor needs containerd (plain, k3s or rke2); runtime flavor '$RUNTIME_FLAVOR' is not supported"
    ;;
esac

# 3) Restart the runtime only when runsc is not loaded yet — at most once per config change
#    (marker), so a failing node never ends up in a restart loop
MARK="$HOST/var/lib/runr/gvisor-runtime-restarted"
runtime_loaded; LOADED=$?
if [ "$LOADED" = 0 ]; then
  rm -f "$MARK"
  echo "runsc runtime loaded — no restart needed"
elif [ "$CHANGED" = 0 ] && [ "$LOADED" = 1 ] && [ -f "$MARK" ]; then
  fail "runsc is configured but still not loaded after an earlier runtime restart — check the containerd log"
elif [ "$CHANGED" = 1 ] || [ "$LOADED" = 1 ]; then
  mkdir -p "$(dirname "$MARK")" && date > "$MARK"
  restart_runtime loaded
  rm -f "$MARK"
fi

date > /tmp/runr-gvisor-ready
echo "gVisor installed successfully, sleeping..."
sleep infinity
BASH;
    }

    /**
     * Node cleanup (uninstall, or rollback of a failed first install) — the inverse of the
     * install script:
     * 1. refuses to run while gVisor sandboxes still run on the node (their shims would lose
     *    their runtime);
     * 2. removes the runsc runtime RunR added: plain containerd — the runsc table(s) from
     *    /etc/containerd/config.toml (the result must pass `containerd config dump` before it
     *    replaces the file; rewritten in place); k3s/rke2 — the runr-runsc.toml drop-in, or runsc
     *    from the config template (a template that only held runsc is removed);
     * 3. restarts containerd (or the k3s/rke2 unit) the same way the installer does — only when
     *    the running runtime still has runsc loaded — and waits until it is gone;
     * 4. checks the config the runtime now runs with (valid, no runsc), then removes runsc,
     *    containerd-shim-runsc-v1, gvisor-bin/ and RunR's backups / markers.
     * Idempotent: a node without gVisor passes without changes or restarts.
     */
    private function gvisorCleanupScript(): string
    {
        return $this->gvisorHelpersScript().<<<'BASH'
# 1) Never take runsc away from running sandboxes
N=0
for p in /proc/[0-9]*; do
  case "$(readlink "$p/exe" 2>/dev/null)" in
    *containerd-shim-runsc-v1*|*/gvisor-bin/*|*/runsc|*"/runsc (deleted)") N=$((N + 1)) ;;
  esac
done
[ "$N" = 0 ] || fail "$N gVisor sandbox process(es) still run on this node — delete or redeploy the sandboxed workloads with runc, then uninstall again"

# 2) Remove the runsc runtime from containerd's config (checked before any restart)
CHANGED=0
case "$RUNTIME_FLAVOR" in
  k3s|rke2)
    GEN="$CONF_DIR/config.toml"
    for f in "$CONF_DIR/config-v3.toml.d/runr-runsc.toml" "$CONF_DIR/config.toml.d/runr-runsc.toml"; do
      [ -e "$f" ] || continue
      rm -f "$f" || fail "cannot remove $(host_path "$f")"
      echo "removed $(host_path "$f")"
      CHANGED=1
    done
    for t in "$CONF_DIR/config-v3.toml.tmpl" "$CONF_DIR/config.toml.tmpl"; do
      has_runsc "$t" || continue
      strip_runsc "$t" > "$t.runr-new" || fail "cannot rewrite $(host_path "$t")"
      if has_runsc "$t.runr-new"; then
        rm -f "$t.runr-new"
        fail "cannot remove runsc from $(host_path "$t") (unexpected layout) — left unchanged"
      fi
      if grep -v '^[[:space:]]*$' "$t.runr-new" | grep -qv '^{{ template "base" \. }}$'; then
        cat "$t.runr-new" > "$t" || fail "cannot write $(host_path "$t")"
        rm -f "$t.runr-new"
        echo "runsc removed from $(host_path "$t")"
      else
        # only the base template and runsc: the file RunR wrote
        rm -f "$t" "$t.runr-new"
        echo "removed $(host_path "$t")"
      fi
      CHANGED=1
    done
    # a template change only shows in config.toml after the restart (rendered at start)
    if [ -f "$GEN" ] && ! has_runsc "$GEN"; then
      validate_config "$(host_path "$GEN")" no || fail "$VALIDATION_ERROR"
    fi
    ;;
  containerd)
    CFG="$CONF_DIR/config.toml"
    NEW="$CFG.runr-new"
    if has_runsc "$CFG"; then
      strip_runsc "$CFG" > "$NEW" || fail "cannot write $(host_path "$NEW")"
      if has_runsc "$NEW"; then
        rm -f "$NEW"
        fail "cannot remove runsc from /etc/containerd/config.toml (unexpected layout) — left unchanged"
      fi
      if ! validate_config "$(host_path "$NEW")" no; then
        rm -f "$NEW"
        fail "$VALIDATION_ERROR — config.toml left unchanged, nothing restarted"
      fi
      cp -p "$CFG" "$CFG.runr-pre-uninstall" || fail "cannot back up /etc/containerd/config.toml"
      # rewritten in place: owner, mode and SELinux label (or a symlinked config) stay as they are
      if ! cat "$NEW" > "$CFG"; then
        cat "$CFG.runr-pre-uninstall" > "$CFG"
        rm -f "$NEW"
        fail "cannot write /etc/containerd/config.toml — restored"
      fi
      rm -f "$NEW"
      echo "runsc removed from /etc/containerd/config.toml"
      CHANGED=1
    else
      echo "containerd config.toml has no runsc runtime"
    fi
    ;;
  *)
    fail "runtime flavor '$RUNTIME_FLAVOR' is not supported"
    ;;
esac

# 3) Restart the runtime so it drops runsc — only when it (may) still have it loaded
runtime_loaded; LOADED=$?
if [ "$LOADED" = 0 ] || { [ "$CHANGED" = 1 ] && [ "$LOADED" -ge 2 ]; }; then
  restart_runtime unloaded
else
  echo "runsc not loaded by the runtime — no restart needed"
fi

# 4) The config the runtime runs with is valid and runsc-free; then the binaries go
if [ "$RUNTIME_FLAVOR" != "containerd" ] && has_runsc "$CONF_DIR/config.toml"; then
  fail "runsc is still in the generated ${CONTAINERD_CONFIG_DIR}/config.toml after the restart — check ${CONTAINERD_CONFIG_DIR}/config*.tmpl"
fi
if [ -f "$CONF_DIR/config.toml" ]; then
  validate_config "$(host_path "$CONF_DIR/config.toml")" no || fail "$VALIDATION_ERROR"
fi
rm -rf "$BIN_DIR/gvisor-bin" "$BIN_DIR/gvisor-bin.runr-tmp" || fail "cannot remove /usr/local/bin/gvisor-bin"
for b in runsc containerd-shim-runsc-v1; do
  rm -f "$BIN_DIR/$b" "$BIN_DIR/$b.runr-tmp" || fail "cannot remove /usr/local/bin/$b"
done
rm -f "$HOST/var/lib/runr/gvisor-runtime-restarted" "$CONF_DIR/config.toml.runr-pre-uninstall" "$CONF_DIR"/config.toml.runr-pre-gvisor*
echo "gVisor removed from this node"

date > /tmp/runr-gvisor-clean
echo "cleanup done, sleeping..."
sleep infinity
BASH;
    }
}
