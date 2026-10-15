<?php

namespace App\Services\Concerns;

use App\Services\Helpers\ContainerRuntimeHelper;
use App\Services\Helpers\KataRuntime;

/**
 * containerd's devmapper snapshotter for Kata Firecracker (Firecracker boots block devices, not
 * overlayfs / virtio-fs), set up the way the Kata "Firecracker + devmapper" how-to and
 * containerd's devmapper docs describe:
 *
 *  - a thin-pool on two sparse files (data: DEVMAPPER_POOL_DEFAULT_GB or the size requested at
 *    install, metadata: 1 GiB) under KataRuntime::DEVMAPPER_DIR, attached to loop devices; data
 *    block size 64 KiB, low water mark 32768 blocks, new blocks zeroed (skip_block_zeroing is
 *    left off: a pod must never read blocks another pod's deleted snapshot wrote);
 *  - a oneshot systemd unit (runr-devmapper.service) that re-attaches the files and re-creates
 *    the pool at boot, ordered before containerd / k3s / rke2;
 *  - [plugins."io.containerd.snapshotter.v1.devmapper"] pool_name / root_path / base_image_size
 *    10GB / discard_blocks — k3s / rke2: drop-in under config[-v3].toml.d (or their config
 *    template), plain containerd: config.toml; every change must pass `containerd config dump`.
 *
 * The runtime then has to restart to load the plugin. On an install that restart is
 * kata-deploy's own (RESTART_MODE=kata-deploy: the pool and config are in place before the
 * kata-installer rolls), so enabling fc costs no extra restart; only when kata-deploy does not
 * restart (its template unchanged) does the step restart the runtime itself (RESTART_MODE=self).
 * kata-deploy's custom runtime kata-fc-runr carries snapshotter = "devmapper".
 *
 * Cleanup (Kata uninstall, after kata-deploy's cleanup): nothing is removed while Firecracker VMs
 * run or containers still have devmapper snapshots on the node ("kept"); otherwise the plugin
 * config goes first, the runtime restarts without it, and only then the pool, its thin devices,
 * loop devices, files and the unit — containerd never points at a missing pool.
 */
trait KataDevmapperTrait
{
    private const KATA_DEVMAPPER_DS = 'kata-devmapper';

    private const KATA_DEVMAPPER_CLEANUP_DS = 'kata-devmapper-cleanup';

    /** Log lines of the node scripts RunR reads back. */
    public const DEVMAPPER_CONFIGURED_LOG = 'runr: devmapper configured';

    public const DEVMAPPER_READY_LOG = 'runr: devmapper snapshotter ready';

    public const DEVMAPPER_KEPT_LOG = 'runr: devmapper kept';

    public const DEVMAPPER_REMOVED_LOG = 'runr: devmapper removed';

    /**
     * @param  array  $runtime  ContainerRuntimeHelper::resolveCluster() of the target nodes
     * @param  string  $restartMode  kata-deploy (its runtime restart loads the plugin) | self
     */
    private function buildKataDevmapperDaemonSet(
        string $namespace,
        array $runtime,
        bool $hasDedicatedNodes,
        int $poolGb = KataRuntime::DEVMAPPER_POOL_DEFAULT_GB,
        string $restartMode = 'kata-deploy',
    ): array {
        return $this->buildDevmapperNodeDaemonSet($namespace, self::KATA_DEVMAPPER_DS, $this->devmapperInstallScript(), '/tmp/runr-devmapper-ready', $runtime, $hasDedicatedNodes, [
            'POOL_GB' => (string) $poolGb,
            'META_GB' => (string) KataRuntime::devmapperMetaGb($poolGb),
            'RESTART_MODE' => $restartMode,
        ]);
    }

    private function buildKataDevmapperCleanupDaemonSet(string $namespace, array $runtime, bool $hasDedicatedNodes): array
    {
        return $this->buildDevmapperNodeDaemonSet($namespace, self::KATA_DEVMAPPER_CLEANUP_DS, $this->devmapperCleanupScript(), '/tmp/runr-devmapper-clean', $runtime, $hasDedicatedNodes);
    }

    private function buildDevmapperNodeDaemonSet(
        string $namespace,
        string $name,
        string $script,
        string $readyMarker,
        array $runtime,
        bool $hasDedicatedNodes,
        array $extraEnv = [],
    ): array {
        $flavor = $runtime['flavor'] ?? ContainerRuntimeHelper::FLAVOR_CONTAINERD;

        $env = [['name' => 'NODE_NAME', 'valueFrom' => ['fieldRef' => ['fieldPath' => 'spec.nodeName']]]];
        foreach ($extraEnv + [
            'RUNTIME_FLAVOR' => $flavor,
            'CONTAINERD_MAJOR' => (string) ($runtime['containerd_major'] ?? ''),
            'CONTAINERD_CONFIG_DIR' => ContainerRuntimeHelper::configDir($flavor),
            'CONTAINERD_SOCKET' => ContainerRuntimeHelper::socketPath($flavor),
            'RESTART_UNITS' => implode(' ', ContainerRuntimeHelper::restartUnits($flavor)),
            'DM_DIR' => KataRuntime::DEVMAPPER_DIR,
            'POOL' => KataRuntime::DEVMAPPER_POOL,
        ] as $key => $value) {
            $env[] = ['name' => $key, 'value' => $value];
        }

        return [
            'apiVersion' => 'apps/v1',
            'kind' => 'DaemonSet',
            'metadata' => [
                'name' => $name,
                'namespace' => $namespace,
                'labels' => ['app.kubernetes.io/managed-by' => 'runr', 'runr.dev/component' => 'kata'],
            ],
            'spec' => [
                'selector' => ['matchLabels' => ['runr.dev/component' => $name]],
                'template' => [
                    'metadata' => ['labels' => ['runr.dev/component' => $name]],
                    'spec' => [
                        'hostPID' => true,
                        'automountServiceAccountToken' => false,
                        // sh (PID 1) ignores SIGTERM while it sleeps: don't wait 30 s on delete
                        'terminationGracePeriodSeconds' => 5,
                        // the kata-installer's nodes
                        'nodeSelector' => $hasDedicatedNodes ? ['runr.dev/dedicated' => 'projects'] : ['kubernetes.io/os' => 'linux'],
                        'tolerations' => [[
                            'key' => 'runr.dev/dedicated',
                            'operator' => 'Equal',
                            'value' => 'projects',
                            'effect' => 'NoSchedule',
                        ]],
                        'containers' => [[
                            'name' => $name === self::KATA_DEVMAPPER_DS ? 'devmapper' : 'cleanup',
                            'image' => 'ghcr.io/brf-tech/reqs/alpine:3.21',
                            'securityContext' => ['privileged' => true],
                            'command' => ['sh', '-c', $script],
                            'env' => $env,
                            // "runr: ERROR ..." of a failed run ends up in the pod status
                            'terminationMessagePolicy' => 'FallbackToLogsOnError',
                            'readinessProbe' => [
                                'exec' => ['command' => ['cat', $readyMarker]],
                                'initialDelaySeconds' => 3,
                                'periodSeconds' => 5,
                            ],
                            'volumeMounts' => [['name' => 'host-root', 'mountPath' => '/host']],
                            'resources' => [
                                'requests' => ['cpu' => '20m', 'memory' => '16Mi'],
                                'limits' => ['cpu' => '200m', 'memory' => '64Mi'],
                            ],
                        ]],
                        'volumes' => [['name' => 'host-root', 'hostPath' => ['path' => '/']]],
                    ],
                ],
            ],
        ];
    }

    /** containerd config table of the devmapper snapshotter (same keys in config v2 and v3). */
    public static function devmapperConfigBlock(): string
    {
        return "\n[plugins.\"io.containerd.snapshotter.v1.devmapper\"]\n"
            .'  pool_name = "'.KataRuntime::DEVMAPPER_POOL."\"\n"
            .'  root_path = "'.KataRuntime::DEVMAPPER_DIR."/state\"\n"
            ."  base_image_size = \"10GB\"\n"
            ."  discard_blocks = true\n";
    }

    /** Boot-time pool activation on the node (runr-devmapper.service → this script). */
    public static function devmapperPoolScript(): string
    {
        $dir = KataRuntime::DEVMAPPER_DIR;
        $pool = KataRuntime::DEVMAPPER_POOL;

        return <<<SH
#!/bin/sh
# RunR (Kata Firecracker): attach the sparse files of containerd's devmapper thin-pool to loop
# devices and activate the pool. Run by runr-devmapper.service at boot, before the container runtime.
set -eu
DIR={$dir}
POOL={$pool}
if dmsetup status "\$POOL" >/dev/null 2>&1; then echo "runr-devmapper: thin-pool \$POOL already active"; exit 0; fi
[ -f "\$DIR/data" ] && [ -f "\$DIR/meta" ] || { echo "runr-devmapper: \$DIR/data or \$DIR/meta missing" >&2; exit 1; }
loopdev() { d=\$(losetup -j "\$1" | head -n 1 | cut -d: -f1); [ -n "\$d" ] || d=\$(losetup --find --show "\$1"); echo "\$d"; }
DATA_DEV=\$(loopdev "\$DIR/data")
META_DEV=\$(loopdev "\$DIR/meta")
SECTORS=\$(( \$(blockdev --getsize64 "\$DATA_DEV") / 512 ))
# 64 KiB data blocks, low water mark 32768 blocks; new blocks are zeroed (no data of deleted snapshots leaks between pods)
dmsetup create "\$POOL" --table "0 \$SECTORS thin-pool \$META_DEV \$DATA_DEV 128 32768"
echo "runr-devmapper: thin-pool \$POOL active (data \$DATA_DEV, metadata \$META_DEV)"

SH;
    }

    public static function devmapperUnit(): string
    {
        return <<<'UNIT'
[Unit]
Description=RunR devmapper thin-pool for containerd (Kata Firecracker)
After=local-fs.target
Before=containerd.service k3s.service k3s-agent.service rke2-server.service rke2-agent.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/usr/local/lib/runr/runr-devmapper-pool.sh

[Install]
WantedBy=multi-user.target

UNIT;
    }

    /**
     * Shell helpers of the install and cleanup scripts (privileged pod, node / at /host, hostPID).
     */
    private function devmapperHelpersScript(): string
    {
        $block = self::devmapperConfigBlock();
        $script = self::devmapperPoolScript();
        $unit = self::devmapperUnit();

        return 'DEVMAPPER_BLOCK='.escapeshellarg($block)."\n"
            .'POOL_SCRIPT_CONTENT='.escapeshellarg($script)."\n"
            .'UNIT_CONTENT='.escapeshellarg($unit)."\n"
            .<<<'BASH'
NODE="${NODE_NAME:-$(hostname)}"
HOST=/host
CONF_DIR="$HOST${CONTAINERD_CONFIG_DIR:-/etc/containerd}"
UNIT=runr-devmapper.service
POOL_SCRIPT=/usr/local/lib/runr/runr-devmapper-pool.sh
DROPIN=runr-devmapper.toml
MARK="$HOST/var/lib/runr/devmapper-runtime-restarted"
# A failure is final for this run: report it (log + termination message) and stay up, not ready.
# Exiting would make the DaemonSet restart the script in a loop; RunR reads the error from the
# log, shows it in the region's Kata status and removes the DaemonSet.
fail() {
  echo "runr: ERROR: $* (node $NODE)" | tee /dev/termination-log
  date > /tmp/runr-devmapper-failed
  exec sleep infinity
}
hostctl() { nsenter --target 1 --mount --uts --ipc --pid -- "$@"; }
host_path() { echo "${1#"$HOST"}"; }
config_version() { awk '/^[[:space:]]*\[/ { exit } /^[[:space:]]*version[[:space:]]*=/ { v = $0; sub(/^[^=]*=/, "", v); gsub(/[^0-9]/, "", v); print v; exit }' "$1"; }
has_devmapper() { [ -f "$1" ] && grep -q 'io\.containerd\.snapshotter\.v1\.devmapper' "$1"; }
# $1 without its devmapper table and the blank lines before it
strip_devmapper() {
  awk '
    /^[[:space:]]*\[/ { skip = ($0 ~ /io\.containerd\.snapshotter\.v1\.devmapper/); if (skip) held = "" }
    skip { next }
    /^[[:space:]]*$/ { held = held $0 "\n"; next }
    { printf "%s", held; held = ""; print }
    END { printf "%s", held }
  ' "$1"
}

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

# validate_config <node path of a config> <devmapper expected: yes|no> — sets VALIDATION_ERROR
validate_config() {
  VALIDATION_ERROR=""
  CTRD=$(find_containerd)
  if [ -z "$CTRD" ]; then VALIDATION_ERROR="no containerd binary found on the node to validate $1 with"; return 1; fi
  if ! DUMP=$(hostctl "$CTRD" --config "$1" config dump 2>&1); then
    VALIDATION_ERROR="containerd rejects $1: $(echo "$DUMP" | tail -n 3 | tr '\n' ' ')"
    return 1
  fi
  # containerd 2.x dumps TOML strings single-quoted (pool_name = 'runr-thinpool'), 1.x double-quoted
  if echo "$DUMP" | grep -Eq "pool_name = [\"']$POOL[\"']"; then R=yes; else R=no; fi
  if [ "$2" != "$R" ]; then
    if [ "$2" = yes ]; then VALIDATION_ERROR="$1 is valid but does not configure the devmapper snapshotter"; else VALIDATION_ERROR="$1 still configures the devmapper snapshotter"; fi
    return 1
  fi
  echo "containerd accepts $1 (devmapper snapshotter configured: $R)"
}

# ctr of the node's runtime; exit 42 when the node has none
ctr_cmd() {
  hostctl sh -c '
    S="$1"; F="$2"; shift 2
    if [ "$F" = k3s ] && command -v k3s >/dev/null 2>&1; then exec k3s ctr "$@"
    elif [ "$F" = rke2 ] && [ -x /var/lib/rancher/rke2/bin/ctr ]; then exec /var/lib/rancher/rke2/bin/ctr --address "$S" "$@"
    elif command -v ctr >/dev/null 2>&1; then exec ctr --address "$S" "$@"
    else exit 42
    fi' sh "$CONTAINERD_SOCKET" "$RUNTIME_FLAVOR" "$@"
}

# status of containerd's devmapper plugin: ok | skip | error | missing | down (runtime not answering) | unknown (no ctr)
devmapper_state() {
  OUT=$(ctr_cmd plugins ls 2>/dev/null); RC=$?
  if [ "$RC" = 42 ]; then echo unknown; return; fi
  if [ "$RC" != 0 ]; then echo down; return; fi
  S=$(echo "$OUT" | awk '$1 == "io.containerd.snapshotter.v1" && $2 == "devmapper" { print $NF; exit }')
  echo "${S:-missing}"
}

# wait_state <ok|off> <tries of 5 s>
wait_state() {
  i=0
  while :; do
    S=$(devmapper_state)
    case "$1:$S" in
      ok:ok|off:skip|off:missing|off:error|*:unknown) echo "devmapper plugin: $S"; return 0 ;;
    esac
    i=$((i + 1))
    [ "$i" -gt "$2" ] && return 1
    sleep 5
  done
}

# runr-devmapper.service still known to systemd as "active (exited)" although its unit file is gone
# (RunR ≤ 0.4.2 removed the unit without stopping it): a "start" is then a no-op and the pool script
# never runs again
unit_stale() {
  case "$(hostctl systemctl show "$UNIT" -p LoadState -p ActiveState 2>/dev/null | tr '\n' ' ')" in
    *LoadState=not-found*ActiveState=active*|*ActiveState=active*LoadState=not-found*) return 0 ;;
  esac
  return 1
}
stop_unit() {
  hostctl systemctl stop "$UNIT" >/dev/null 2>&1 || true
  hostctl systemctl reset-failed "$UNIT" >/dev/null 2>&1 || true
}

restart_runtime() {
  ACTIVE=""
  for u in $RESTART_UNITS; do
    if hostctl systemctl is-active --quiet "$u"; then ACTIVE="$u"; break; fi
  done
  [ -n "$ACTIVE" ] || fail "no active systemd unit ($RESTART_UNITS) to restart — restart the container runtime manually"
  mkdir -p "$(dirname "$MARK")" && date > "$MARK"
  hostctl systemctl restart "$ACTIVE" || fail "systemctl restart $ACTIVE failed"
  echo "$ACTIVE restarted"
}
# -- end of helpers --

BASH;
    }

    /**
     * Idempotent: existing pool files are kept (their size too), an existing config is not
     * written twice, and the runtime is not restarted when the plugin is already loaded.
     */
    private function devmapperInstallScript(): string
    {
        return $this->devmapperHelpersScript().<<<'BASH'
# 1) Host tools and kernel support (containerd's devmapper snapshotter runs dmsetup and mkfs.ext4)
for t in dmsetup losetup blockdev mkfs.ext4; do
  hostctl sh -c "command -v $t" >/dev/null 2>&1 || fail "$t not found on the node — the devmapper snapshotter needs it (dmsetup, util-linux and e2fsprogs packages)"
done
hostctl modprobe dm_thin_pool >/dev/null 2>&1 || true
hostctl dmsetup targets 2>/dev/null | grep -q '^thin-pool' || fail "the kernel offers no device-mapper thin-pool target (module dm_thin_pool)"

# 2) Thin-pool files: sparse, created once, never resized
D="$HOST$DM_DIR"
if [ -f "$D/data" ] && [ -f "$D/meta" ]; then
  echo "thin-pool files kept: $DM_DIR/data ($(( $(stat -c %s "$D/data") / 1073741824 )) GiB, $(du -k "$D/data" | cut -f1) KiB in use), $DM_DIR/meta"
else
  if [ -e "$D/data" ] || [ -e "$D/meta" ]; then fail "incomplete thin-pool files in $DM_DIR — remove them, then install again"; fi
  mkdir -p "$D" || fail "cannot create $DM_DIR"
  AVAIL_KB=$(df -Pk "$D" | awk 'NR == 2 { print $4 }')
  TOTAL_KB=$(df -Pk "$D" | awk 'NR == 2 { print $2 }')
  RESERVE_KB=$(( TOTAL_KB / 10 )); [ "$RESERVE_KB" -ge 5242880 ] || RESERVE_KB=5242880
  NEED_KB=$(( (POOL_GB + META_GB) * 1048576 + RESERVE_KB ))
  if [ "$AVAIL_KB" -lt "$NEED_KB" ]; then
    rmdir "$D" 2>/dev/null
    fail "not enough free disk for a ${POOL_GB} GiB thin-pool under $DM_DIR: $(( AVAIL_KB / 1048576 )) GiB free, $(( NEED_KB / 1048576 )) GiB needed (pool + metadata + reserve of 10% of the filesystem, at least 5 GiB) — nothing changed; install with a smaller devmapper_pool_gb"
  fi
  if ! { truncate -s "$(( POOL_GB * 1073741824 ))" "$D/data.runr-tmp" && truncate -s "$(( META_GB * 1073741824 ))" "$D/meta.runr-tmp" \
         && mv "$D/meta.runr-tmp" "$D/meta" && mv "$D/data.runr-tmp" "$D/data"; }; then
    rm -f "$D/data.runr-tmp" "$D/meta.runr-tmp" "$D/data" "$D/meta"
    fail "cannot create the sparse thin-pool files in $DM_DIR"
  fi
  echo "sparse thin-pool files created: $DM_DIR/data (${POOL_GB} GiB), $DM_DIR/meta (${META_GB} GiB) — disk is used only as images and containers fill the pool"
fi
mkdir -p "$D/state" || fail "cannot create $DM_DIR/state"

# 3) Pool activation now and at every boot, before the container runtime
if unit_stale; then
  echo "$UNIT is still active (exited) from an earlier setup whose unit file is gone — stopping it so that it runs again"
  stop_unit
fi
mkdir -p "$HOST/usr/local/lib/runr" || fail "cannot create /usr/local/lib/runr"
printf '%s' "$POOL_SCRIPT_CONTENT" > "$HOST$POOL_SCRIPT.runr-tmp" && chmod 755 "$HOST$POOL_SCRIPT.runr-tmp" \
  && mv "$HOST$POOL_SCRIPT.runr-tmp" "$HOST$POOL_SCRIPT" || fail "cannot write $POOL_SCRIPT"
printf '%s' "$UNIT_CONTENT" > "$HOST/etc/systemd/system/$UNIT" || fail "cannot write /etc/systemd/system/$UNIT"
hostctl systemctl daemon-reload || fail "systemctl daemon-reload failed"
hostctl systemctl enable "$UNIT" >/dev/null 2>&1 || fail "systemctl enable $UNIT failed"
# restart, not start: an already active (exited) oneshot would not run the pool script again
# (the pool script returns at once when the pool is active)
hostctl systemctl restart "$UNIT" || fail "$UNIT failed: $(hostctl journalctl -u "$UNIT" -n 5 --no-pager 2>/dev/null | tr '\n' ' ')"
hostctl dmsetup status "$POOL" 2>/dev/null | grep -q ' thin-pool ' || fail "thin-pool $POOL is not active after $UNIT"
echo "thin-pool $POOL active; $UNIT activates it at boot"

# 4) containerd's devmapper snapshotter on that pool (checked with containerd config dump)
case "$RUNTIME_FLAVOR" in
  k3s|rke2)
    GEN="$CONF_DIR/config.toml"
    [ -f "$GEN" ] || fail "$RUNTIME_FLAVOR containerd config ${CONTAINERD_CONFIG_DIR}/config.toml not found"
    case "$(config_version "$GEN")" in
      3) DROPIN_DIR="$CONF_DIR/config-v3.toml.d"; TMPL="$CONF_DIR/config-v3.toml.tmpl" ;;
      2) DROPIN_DIR="$CONF_DIR/config.toml.d";    TMPL="$CONF_DIR/config.toml.tmpl" ;;
      *) fail "unsupported $RUNTIME_FLAVOR containerd config version in ${CONTAINERD_CONFIG_DIR}/config.toml" ;;
    esac
    if has_devmapper "$GEN" || has_devmapper "$DROPIN_DIR/$DROPIN" || has_devmapper "$TMPL"; then
      echo "devmapper snapshotter already configured for $RUNTIME_FLAVOR"
    elif grep -q "^imports.*$(basename "$DROPIN_DIR")" "$GEN"; then
      mkdir -p "$DROPIN_DIR"
      # *.runr-tmp is not matched by the imports glob until it is complete
      if ! { printf '%s' "$DEVMAPPER_BLOCK" > "$DROPIN_DIR/$DROPIN.runr-tmp" && mv "$DROPIN_DIR/$DROPIN.runr-tmp" "$DROPIN_DIR/$DROPIN"; }; then
        rm -f "$DROPIN_DIR/$DROPIN.runr-tmp"
        fail "cannot write the devmapper drop-in $(host_path "$DROPIN_DIR")/$DROPIN"
      fi
      if ! validate_config "$(host_path "$GEN")" yes; then
        rm -f "$DROPIN_DIR/$DROPIN"
        fail "$VALIDATION_ERROR — drop-in removed again, nothing restarted"
      fi
      echo "devmapper drop-in written: $(host_path "$DROPIN_DIR")/$DROPIN"
    elif [ -f "$TMPL" ]; then
      # rendered by $RUNTIME_FLAVOR at its restart (a template cannot be checked with config dump)
      printf '%s' "$DEVMAPPER_BLOCK" >> "$TMPL" || fail "cannot append the devmapper snapshotter to $(host_path "$TMPL")"
      echo "devmapper snapshotter appended to $(host_path "$TMPL")"
    else
      { echo '{{ template "base" . }}'; printf '%s' "$DEVMAPPER_BLOCK"; } > "$TMPL" || fail "cannot write $(host_path "$TMPL")"
      echo "devmapper template written: $(host_path "$TMPL")"
    fi
    ;;
  containerd)
    CFG="$CONF_DIR/config.toml"
    NEW="$CFG.runr-new"
    if has_devmapper "$CFG"; then
      echo "devmapper snapshotter already configured in containerd config.toml"
    else
      mkdir -p "$CONF_DIR"
      if [ ! -f "$CFG" ]; then
        if [ "${CONTAINERD_MAJOR:-1}" -ge 2 ] 2>/dev/null; then V=3; else V=2; fi
        { echo "version = $V"; printf '%s' "$DEVMAPPER_BLOCK"; } > "$NEW" || fail "cannot write $(host_path "$NEW")"
      else
        case "$(config_version "$CFG")" in
          2|3) ;;
          *) fail "unsupported containerd config version in /etc/containerd/config.toml (convert it with: containerd config migrate)" ;;
        esac
        { cat "$CFG"; printf '%s' "$DEVMAPPER_BLOCK"; } > "$NEW" || fail "cannot write $(host_path "$NEW")"
      fi
      if ! validate_config "$(host_path "$NEW")" yes; then
        rm -f "$NEW"
        fail "$VALIDATION_ERROR — config.toml left unchanged, nothing restarted"
      fi
      if [ -f "$CFG" ]; then
        [ -f "$CFG.runr-pre-devmapper" ] || cp -p "$CFG" "$CFG.runr-pre-devmapper"
        # rewritten in place: owner, mode and SELinux label (or a symlinked config) stay as they are
        cat "$NEW" > "$CFG" || fail "cannot write /etc/containerd/config.toml"
        rm -f "$NEW"
      else
        mv "$NEW" "$CFG" || fail "cannot write /etc/containerd/config.toml"
      fi
      echo "devmapper snapshotter added to /etc/containerd/config.toml"
    fi
    ;;
  *)
    fail "the devmapper snapshotter needs containerd (plain, k3s or rke2); runtime flavor '$RUNTIME_FLAVOR' is not supported"
    ;;
esac
echo "runr: devmapper configured"

# 5) Loaded by the runtime: after kata-deploy's restart, or (RESTART_MODE=self) after our own
S=$(devmapper_state)
if [ "$S" = ok ]; then
  rm -f "$MARK"
  echo "devmapper snapshotter loaded — no restart needed"
elif [ "$S" = unknown ]; then
  echo "no ctr on the node: the devmapper plugin state cannot be checked"
elif [ "$RESTART_MODE" = self ]; then
  [ -f "$MARK" ] && fail "the devmapper snapshotter is configured but still not loaded after an earlier runtime restart — check the containerd log"
  restart_runtime
  wait_state ok 36 || fail "devmapper snapshotter not loaded 3 min after the runtime restart ($S) — check the containerd log"
  rm -f "$MARK"
else
  echo "waiting for kata-deploy's runtime restart to load the devmapper snapshotter..."
  wait_state ok 360 || fail "devmapper snapshotter still not loaded ($(devmapper_state)) 30 min after it was configured — restart the container runtime on this node"
fi

date > /tmp/runr-devmapper-ready
echo "runr: devmapper snapshotter ready"
sleep infinity
BASH;
    }

    private function devmapperCleanupScript(): string
    {
        return $this->devmapperHelpersScript().<<<'BASH'
if [ ! -d "$HOST$DM_DIR" ] && ! hostctl dmsetup status "$POOL" >/dev/null 2>&1 \
   && ! has_devmapper "$CONF_DIR/config.toml" && [ ! -e "$HOST/etc/systemd/system/$UNIT" ]; then
  if unit_stale; then
    stop_unit
    echo "stopped $UNIT, still active (exited) from an earlier setup whose unit file is gone"
  fi
  echo "runr: devmapper removed: nothing was set up on this node"
  date > /tmp/runr-devmapper-clean; sleep infinity
fi

# 1) Kept while in use: running Firecracker VMs, or container snapshots on the devmapper snapshotter
N=0
for p in /proc/[0-9]*; do
  case "$(readlink "$p/exe" 2>/dev/null)" in
    */firecracker|*"/firecracker (deleted)") N=$((N + 1)) ;;
  esac
done
ACTIVE=0
if [ "$(devmapper_state)" = ok ]; then
  ACTIVE=$(ctr_cmd -n k8s.io snapshots --snapshotter devmapper ls 2>/dev/null | awk 'NR > 1 && $NF == "Active"' | wc -l)
fi
if [ "$N" -gt 0 ] || [ "$ACTIVE" -gt 0 ]; then
  echo "runr: devmapper kept: $N Firecracker VM(s) and $ACTIVE container snapshot(s) still use it on this node"
  date > /tmp/runr-devmapper-clean; sleep infinity
fi

# 2) containerd config without the devmapper snapshotter (checked before any restart)
CHANGED=0
case "$RUNTIME_FLAVOR" in
  k3s|rke2)
    for f in "$CONF_DIR/config-v3.toml.d/$DROPIN" "$CONF_DIR/config.toml.d/$DROPIN"; do
      [ -e "$f" ] || continue
      rm -f "$f" || fail "cannot remove $(host_path "$f")"
      echo "removed $(host_path "$f")"
      CHANGED=1
    done
    for t in "$CONF_DIR/config-v3.toml.tmpl" "$CONF_DIR/config.toml.tmpl"; do
      has_devmapper "$t" || continue
      strip_devmapper "$t" > "$t.runr-new" || fail "cannot rewrite $(host_path "$t")"
      if has_devmapper "$t.runr-new"; then rm -f "$t.runr-new"; fail "cannot remove the devmapper snapshotter from $(host_path "$t") — left unchanged"; fi
      if grep -v '^[[:space:]]*$' "$t.runr-new" | grep -qv '^{{ template "base" \. }}$'; then
        cat "$t.runr-new" > "$t" || fail "cannot write $(host_path "$t")"
        rm -f "$t.runr-new"
        echo "devmapper snapshotter removed from $(host_path "$t")"
      else
        rm -f "$t" "$t.runr-new"
        echo "removed $(host_path "$t")"
      fi
      CHANGED=1
    done
    ;;
  containerd)
    CFG="$CONF_DIR/config.toml"
    NEW="$CFG.runr-new"
    if has_devmapper "$CFG"; then
      strip_devmapper "$CFG" > "$NEW" || fail "cannot write $(host_path "$NEW")"
      if has_devmapper "$NEW"; then rm -f "$NEW"; fail "cannot remove the devmapper snapshotter from /etc/containerd/config.toml — left unchanged"; fi
      if ! validate_config "$(host_path "$NEW")" no; then rm -f "$NEW"; fail "$VALIDATION_ERROR — config.toml left unchanged, nothing restarted"; fi
      cp -p "$CFG" "$CFG.runr-pre-devmapper-removal" || fail "cannot back up /etc/containerd/config.toml"
      if ! cat "$NEW" > "$CFG"; then cat "$CFG.runr-pre-devmapper-removal" > "$CFG"; rm -f "$NEW"; fail "cannot write /etc/containerd/config.toml — restored"; fi
      rm -f "$NEW" "$CFG.runr-pre-devmapper-removal" "$CFG.runr-pre-devmapper"
      echo "devmapper snapshotter removed from /etc/containerd/config.toml"
      CHANGED=1
    fi
    ;;
  *)
    fail "runtime flavor '$RUNTIME_FLAVOR' is not supported"
    ;;
esac

# 3) The runtime must drop the plugin before the pool goes
S=$(devmapper_state)
if [ "$S" = ok ] || { [ "$CHANGED" = 1 ] && [ "$S" = unknown ]; }; then
  restart_runtime
  wait_state off 36 || fail "the devmapper snapshotter is still loaded 3 min after the runtime restart — check ${CONTAINERD_CONFIG_DIR}"
  rm -f "$MARK"
else
  echo "devmapper snapshotter not loaded ($S) — no restart needed"
fi
if [ "$RUNTIME_FLAVOR" != containerd ] && has_devmapper "$CONF_DIR/config.toml"; then
  fail "the devmapper snapshotter is still in the generated ${CONTAINERD_CONFIG_DIR}/config.toml after the restart — check ${CONTAINERD_CONFIG_DIR}/config*.tmpl"
fi

# 4) Pool, thin devices, loop devices, files, unit. The unit is stopped before its file goes:
#    otherwise systemd keeps it "active (exited)" and the next setup's start would be a no-op.
hostctl systemctl stop "$UNIT" >/dev/null 2>&1 || true
hostctl systemctl disable "$UNIT" >/dev/null 2>&1 || true
for d in $(hostctl dmsetup ls 2>/dev/null | awk -v p="$POOL-snap-" 'index($1, p) == 1 { print $1 }'); do
  hostctl dmsetup remove "$d" || fail "cannot remove the thin device $d (still mounted?)"
done
if hostctl dmsetup status "$POOL" >/dev/null 2>&1; then
  hostctl dmsetup remove "$POOL" || fail "cannot remove the thin-pool $POOL (still open)"
fi
for f in data meta; do
  for l in $(hostctl losetup -j "$DM_DIR/$f" 2>/dev/null | cut -d: -f1); do hostctl losetup -d "$l" || true; done
done
rm -rf "$HOST$DM_DIR" "$HOST$POOL_SCRIPT" "$HOST/etc/systemd/system/$UNIT" "$MARK" || fail "cannot remove the thin-pool files"
hostctl systemctl daemon-reload || true
hostctl systemctl reset-failed "$UNIT" >/dev/null 2>&1 || true
echo "runr: devmapper removed: thin-pool $POOL, $DM_DIR and $UNIT are gone"

date > /tmp/runr-devmapper-clean
echo "cleanup done, sleeping..."
sleep infinity
BASH;
    }
}
