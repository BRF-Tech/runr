<?php

namespace App\Services\Wasm;

/**
 * Shell scripts of the WebAssembly node DaemonSets (WasmManifests). They run in a privileged
 * pod with the node's / at /host and hostPID — the same model as RunR's gVisor installer — on
 * containerd nodes: plain containerd (1.7 config v2, 2.x config v3) and the containerd embedded
 * in k3s / RKE2.
 *
 * Environment: NODE_NAME, RUNTIME_FLAVOR, CONTAINERD_MAJOR, CONTAINERD_CONFIG_DIR,
 * CONTAINERD_SOCKET, RESTART_UNITS (ContainerRuntimeHelper), WASM_SHIMS (the shims to install,
 * one WasmShim::scriptLine() per line) and WASM_ALL_SHIMS (every shim RunR knows). The installer
 * pod's init containers copy the /shim of image shims to SHIM_FILES/<id>.
 *
 * What RunR writes into containerd's configuration is one block between two marker comments, so
 * the cleanup removes exactly that and nothing else:
 *  - k3s / RKE2: drop-in runr-wasm.toml under config[-v3].toml.d when the generated config
 *    imports it, otherwise the block in config[-v3].toml.tmpl ({{ template "base" . }} first);
 *  - containerd with an `imports` glob in config.toml (e.g. /etc/containerd/conf.d/*.toml, the
 *    layout of containerd 2.x packages): drop-in runr-wasm.toml in that directory;
 *  - containerd otherwise: the block appended to /etc/containerd/config.toml (rewritten in place,
 *    the first change keeps config.toml.runr-pre-wasm).
 * Every config change must pass `containerd --config <file> config dump` with the node's own
 * containerd binary before anything is restarted. The runtime handlers get SystemdCgroup = true
 * when runc on the node uses the systemd cgroup driver.
 */
final class WasmNodeScripts
{
    public const READY_MARKER = '/tmp/runr-wasm-ready';

    public const CLEAN_MARKER = '/tmp/runr-wasm-clean';

    /** Where the installer pod's init containers put the files of image shims (<dir>/<shim id>/). */
    public const SHIM_FILES = '/runr-shims';

    /** Log line of a node whose shims are installed and loaded. */
    public const READY_LOG = 'runr: wasm ready:';

    /** Log line of a node the cleanup finished on. */
    public const CLEAN_LOG = 'runr: wasm removed';

    public const BEGIN_MARK = '# runr-wasm: begin (RunR WebAssembly support; removed by its uninstall)';

    public const END_MARK = '# runr-wasm: end';

    /**
     * Helpers shared by both scripts (definitions only).
     *
     * - block_body / marked_block / dropin_text: RunR's runtime tables for a config version;
     * - strip_block: a file without RunR's marked block (strip_block(file + marked_block) == file);
     * - validate_config: `containerd --config <file> config dump` with the node's containerd,
     *   checking which RunR handlers it registers;
     * - runtime_state / any_loaded: what the running runtime has loaded (crictl info);
     * - restart_runtime: restart the unit that owns containerd (k3s / RKE2: staggered per node).
     */
    public static function helpers(): string
    {
        $begin = self::BEGIN_MARK;
        $end = self::END_MARK;

        return <<<BASH
NODE="\${NODE_NAME:-\$(hostname)}"
HOST=/host
BEGIN_MARK='{$begin}'
END_MARK='{$end}'

BASH.<<<'BASH'
BIN_DIR="$HOST/usr/local/bin"
STATE_DIR="$HOST/var/lib/runr/wasm"
CONF_DIR="$HOST${CONTAINERD_CONFIG_DIR:-/etc/containerd}"
DROPIN=runr-wasm.toml
fail() { echo "runr: ERROR: $* (node $NODE)" | tee /dev/termination-log; exit 1; }
hostctl() { nsenter --target 1 --mount --uts --ipc --pid -- "$@"; }

# path of a $HOST file as the node sees it
host_path() { echo "${1#"$HOST"}"; }
config_version() { awk '/^[[:space:]]*\[/ { exit } /^[[:space:]]*version[[:space:]]*=/ { v = $0; sub(/^[^=]*=/, "", v); gsub(/[^0-9]/, "", v); print v; exit }' "$1"; }
has_block() { [ -f "$1" ] && grep -qF "$BEGIN_MARK" "$1"; }

# $1 without RunR's marked block (and the blank lines right before it)
strip_block() {
  awk -v b="$BEGIN_MARK" -v e="$END_MARK" '
    $0 == b { skip = 1; held = ""; next }
    skip { if ($0 == e) skip = 0; next }
    /^[[:space:]]*$/ { held = held $0 "\n"; next }
    { printf "%s", held; held = ""; print }
    END { printf "%s", held }
  ' "$1"
}

# handlers of a WASM_SHIMS-style list (field 3 of every line)
handlers_of() { echo "$1" | awk 'NF >= 4 { printf "%s ", $3 }'; }

# block_body <config version 2|3> <SystemdCgroup true|false>: the runtime tables of WASM_SHIMS
block_body() {
  echo "$WASM_SHIMS" | while read -r ID BIN HANDLER TYPE REST; do
    [ -n "$TYPE" ] || continue
    if [ "$1" = 3 ]; then
      T="plugins.'io.containerd.cri.v1.runtime'.containerd.runtimes.$HANDLER"
    else
      T="plugins.\"io.containerd.grpc.v1.cri\".containerd.runtimes.$HANDLER"
    fi
    printf '[%s]\n  runtime_type = "%s"\n' "$T" "$TYPE"
    if [ "$2" = true ]; then
      printf '[%s.options]\n  SystemdCgroup = true\n' "$T"
    fi
  done
}
marked_block() { printf '\n%s\n' "$BEGIN_MARK"; block_body "$1" "$2"; printf '%s\n' "$END_MARK"; }
dropin_text() { printf 'version = %s\n%s\n' "$1" "$BEGIN_MARK"; block_body "$1" "$2"; printf '%s\n' "$END_MARK"; }

# directory of the first `imports = ["<dir>/*.toml", ...]` glob of a config (node path), or nothing
imports_dir() {
  [ -f "$1" ] || return 0
  sed -n 's#^[[:space:]]*imports[[:space:]]*=[[:space:]]*\[[[:space:]]*"\([^"]*\)/\*\.toml".*#\1#p' "$1" | head -n 1
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

# true when runc uses the systemd cgroup driver in the config at node path $1
systemd_cgroup() {
  CTRD=$(find_containerd)
  if [ -z "$CTRD" ] || [ ! -f "$HOST$1" ]; then echo false; return 0; fi
  hostctl "$CTRD" --config "$1" config dump 2>/dev/null | awk '
    /^[[:space:]]*\[/ { in_runc = ($0 ~ /runtimes\.runc\.options\]/) }
    in_runc && /SystemdCgroup[[:space:]]*=[[:space:]]*true/ { found = 1 }
    END { print (found ? "true" : "false") }'
}

has_handler() { echo "$1" | grep -Eq "runtimes\.[\"']?$2[\"']?\]"; }

# validate_config <node path> <handlers it must register> <handlers it must not> — sets VALIDATION_ERROR
validate_config() {
  VALIDATION_ERROR=""
  CTRD=$(find_containerd)
  if [ -z "$CTRD" ]; then VALIDATION_ERROR="no containerd binary found on the node to validate $1 with"; return 1; fi
  if ! DUMP=$(hostctl "$CTRD" --config "$1" config dump 2>&1); then
    VALIDATION_ERROR="containerd rejects $1: $(echo "$DUMP" | tail -n 3 | tr '\n' ' ')"
    return 1
  fi
  for h in $2; do
    if ! has_handler "$DUMP" "$h"; then VALIDATION_ERROR="$1 is valid but does not register the $h runtime"; return 1; fi
  done
  for h in $3; do
    if has_handler "$DUMP" "$h"; then VALIDATION_ERROR="$1 still registers the $h runtime"; return 1; fi
  done
  echo "containerd accepts $1"
}

# crictl info of the running runtime (exit 42: no crictl on the node)
runtime_info() {
  hostctl sh -c '
    if command -v crictl >/dev/null 2>&1; then crictl --runtime-endpoint "unix://$1" info
    elif command -v k3s >/dev/null 2>&1; then k3s crictl info
    elif [ -x /var/lib/rancher/rke2/bin/crictl ]; then /var/lib/rancher/rke2/bin/crictl --runtime-endpoint "unix://$1" info
    else exit 42
    fi' sh "$CONTAINERD_SOCKET" 2>/dev/null
}

# runtime_state "<handlers>": 0 = all loaded, 1 = runtime up without some, 2 = unknown (no crictl), 3 = runtime not answering
runtime_state() {
  OUT=$(runtime_info); RC=$?
  [ "$RC" = 42 ] && return 2
  echo "$OUT" | grep -q '"runtimes"' || return 3
  for h in $1; do
    echo "$OUT" | grep -q "\"$h\"" || return 1
  done
  return 0
}

# any_loaded "<handlers>": 0 = the running runtime has one of them, 1 = none, 2 = unknown
any_loaded() {
  [ -n "$1" ] || return 1
  OUT=$(runtime_info); RC=$?
  [ "$RC" = 42 ] && return 2
  echo "$OUT" | grep -q '"runtimes"' || return 2
  for h in $1; do
    echo "$OUT" | grep -q "\"$h\"" && return 0
  done
  return 1
}

# processes of a shim binary still running on the node (their pods' sandboxes)
shim_running() {
  for p in /proc/[0-9]*; do
    case "$(readlink "$p/exe" 2>/dev/null)" in
      */"$1"|*/"$1 (deleted)") return 0 ;;
    esac
  done
  return 1
}

# restart_runtime "<handlers to be loaded>" "<handlers to be gone>": restart the runtime's unit and wait
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
    runtime_state "$1"; S=$?
    if [ "$S" = 2 ]; then echo "no crictl on the node: the runtime handlers after the restart were not checked"; return 0; fi
    if [ "$S" = 0 ]; then
      any_loaded "$2"; G=$?
      [ "$G" != 0 ] && return 0
    fi
    i=$((i + 1))
    [ "$i" -gt 36 ] && fail "the WebAssembly runtimes are not in the state wanted 3 min after restarting $UNIT (loaded: $1; removed: $2) — check the $UNIT log"
    sleep 5
  done
}
# -- end of helpers --

BASH;
    }

    /**
     * Install (and re-install with another shim selection or another release's shims): binaries
     * from the pinned release tarballs (sha256-checked) or from the shim's image (copied by the
     * init container, checked against the image's SHA256SUMS), installed atomically into
     * /usr/local/bin — a binary that differs from the installed one (/var/lib/runr/wasm/<binary>.sha256)
     * replaces it, running shims keep theirs; RunR's block with the selected shims' runtime handlers,
     * a runtime restart only when the configuration changed or a handler is not loaded (at most
     * once per change — marker), and the binaries of RunR shims no longer selected removed where
     * no process uses them. Idempotent: a node that already has everything changes nothing and
     * restarts nothing. Failures exit with "runr: ERROR: ...".
     */
    public static function install(): string
    {
        $files = self::SHIM_FILES;

        return self::helpers()."SHIM_FILES='{$files}'\n".<<<'BASH'
ARCH=$(uname -m)
case "$ARCH" in
  x86_64|aarch64) ;;
  *) fail "unsupported architecture $ARCH (the WebAssembly shims are published for x86_64 and aarch64)" ;;
esac

WANT_HANDLERS=$(handlers_of "$WASM_SHIMS")
DROP_HANDLERS=""
for h in $(handlers_of "$WASM_ALL_SHIMS"); do
  case " $WANT_HANDLERS " in *" $h "*) ;; *) DROP_HANDLERS="$DROP_HANDLERS $h" ;; esac
done
[ -n "$WANT_HANDLERS" ] || fail "no WebAssembly shim selected"

# 1) Shim binaries: a release tarball (sha256-checked) or the shim's image (its /shim, copied to
#    $SHIM_FILES/<id> by the init container shim-<id>, checked against its SHA256SUMS). Installed
#    when the node has no binary with that sha256 (state: <binary>.sha256 — the tarball's digest or
#    the image binary's); replaced atomically — running shims keep theirs
mkdir -p "$BIN_DIR" "$STATE_DIR" || fail "cannot create /usr/local/bin or /var/lib/runr/wasm"
SHIMS_FILE="$STATE_DIR/.shims.runr-tmp"
echo "$WASM_SHIMS" > "$SHIMS_FILE" || fail "cannot write $(host_path "$SHIMS_FILE")"
while read -r ID BIN HANDLER TYPE SRC_X REF_X SRC_A REF_A; do
  [ -n "$REF_A" ] || continue
  if [ "$ARCH" = x86_64 ]; then SRC="$SRC_X"; REF="$REF_X"; else SRC="$SRC_A"; REF="$REF_A"; fi
  if [ "$SRC" = image ]; then
    D="$SHIM_FILES/$ID"
    { [ -f "$D/$BIN" ] && [ -f "$D/SHA256SUMS" ]; } || fail "$BIN or SHA256SUMS missing in $D (init container shim-$ID, image $REF)"
    (cd "$D" && sha256sum -c SHA256SUMS >/dev/null 2>&1) || fail "$BIN of image $REF does not match the image's SHA256SUMS"
    SHA=$(sha256sum "$D/$BIN" | cut -d' ' -f1)
    FROM="image $REF ($(cat "$D/VERSION" 2>/dev/null || echo "no VERSION"))"
  else
    SHA="$REF"
    FROM="$SRC"
  fi
  if [ -x "$BIN_DIR/$BIN" ] && [ "$(cat "$STATE_DIR/$BIN.sha256" 2>/dev/null)" = "$SHA" ]; then
    echo "$BIN present ($ID shim)"
    continue
  fi
  if [ -e "$BIN_DIR/$BIN" ]; then
    echo "Replacing the $ID shim ($ARCH, sha256 $(cat "$STATE_DIR/$BIN.sha256" 2>/dev/null || echo unknown)) with $FROM ..."
  else
    echo "Installing the $ID shim ($ARCH) from $FROM ..."
  fi
  TMP=$(mktemp -d /tmp/runr-wasm.XXXXXX) || fail "cannot create a temp dir"
  if [ "$SRC" = image ]; then
    F="$D/$BIN"
  else
    if ! wget -q -O "$TMP/shim.tar.gz" "$SRC"; then rm -rf "$TMP"; fail "download of $SRC failed"; fi
    echo "$SHA  shim.tar.gz" > "$TMP/sha256"
    if ! (cd "$TMP" && sha256sum -c sha256 >/dev/null 2>&1); then rm -rf "$TMP"; fail "$(basename "$SRC") does not match its sha256 $SHA"; fi
    mkdir -p "$TMP/x" && tar -xzf "$TMP/shim.tar.gz" -C "$TMP/x" || { rm -rf "$TMP"; fail "cannot unpack $(basename "$SRC")"; }
    F=$(find "$TMP/x" -type f -name "$BIN" | head -n 1)
    [ -n "$F" ] || { rm -rf "$TMP"; fail "$BIN missing in $(basename "$SRC")"; }
  fi
  if ! { cp "$F" "$BIN_DIR/$BIN.runr-tmp" && chmod 755 "$BIN_DIR/$BIN.runr-tmp" && mv "$BIN_DIR/$BIN.runr-tmp" "$BIN_DIR/$BIN"; }; then
    rm -f "$BIN_DIR/$BIN.runr-tmp"; rm -rf "$TMP"
    fail "cannot install $BIN into /usr/local/bin"
  fi
  echo "$SHA" > "$STATE_DIR/$BIN.sha256"
  rm -rf "$TMP"
  echo "$BIN installed ($ID shim, sha256 $SHA)"
done < "$SHIMS_FILE"
rm -f "$SHIMS_FILE"

# 2) Runtime handlers in containerd's configuration (checked with config dump before any restart)
CHANGED=0

# write_dropin <dir> <config version> <SystemdCgroup> <node path of the config containerd loads>
write_dropin() {
  F="$1/$DROPIN"
  mkdir -p "$1" || fail "cannot create $(host_path "$1")"
  dropin_text "$2" "$3" > "$F.runr-tmp" || fail "cannot write $(host_path "$F").runr-tmp"
  if [ -f "$F" ] && cmp -s "$F.runr-tmp" "$F"; then
    rm -f "$F.runr-tmp"
    echo "drop-in $(host_path "$F") unchanged"
    return 0
  fi
  rm -f "$F.runr-prev"
  [ -f "$F" ] && cp -p "$F" "$F.runr-prev"
  # *.runr-tmp is not matched by the imports glob until it is complete
  mv "$F.runr-tmp" "$F" || fail "cannot write $(host_path "$F")"
  if ! validate_config "$4" "$WANT_HANDLERS" "$DROP_HANDLERS"; then
    if [ -f "$F.runr-prev" ]; then mv "$F.runr-prev" "$F"; else rm -f "$F"; fi
    fail "$VALIDATION_ERROR — drop-in restored, nothing restarted"
  fi
  rm -f "$F.runr-prev"
  echo "drop-in written: $(host_path "$F")"
  CHANGED=1
}

case "$RUNTIME_FLAVOR" in
  k3s|rke2)
    GEN="$CONF_DIR/config.toml"
    [ -f "$GEN" ] || fail "$RUNTIME_FLAVOR containerd config ${CONTAINERD_CONFIG_DIR}/config.toml not found"
    V=$(config_version "$GEN")
    case "$V" in
      3) DROPIN_DIR="$CONF_DIR/config-v3.toml.d"; TMPL="$CONF_DIR/config-v3.toml.tmpl" ;;
      2) DROPIN_DIR="$CONF_DIR/config.toml.d";    TMPL="$CONF_DIR/config.toml.tmpl" ;;
      *) fail "unsupported $RUNTIME_FLAVOR containerd config version in ${CONTAINERD_CONFIG_DIR}/config.toml" ;;
    esac
    SC=$(systemd_cgroup "$(host_path "$GEN")")
    if grep -q "^imports.*$(basename "$DROPIN_DIR")" "$GEN"; then
      write_dropin "$DROPIN_DIR" "$V" "$SC" "$(host_path "$GEN")"
    else
      # rendered by $RUNTIME_FLAVOR at its restart (a template cannot be checked with config dump)
      if [ -f "$TMPL" ]; then strip_block "$TMPL" > "$TMPL.runr-new"; else echo '{{ template "base" . }}' > "$TMPL.runr-new"; fi
      marked_block "$V" "$SC" >> "$TMPL.runr-new" || fail "cannot write $(host_path "$TMPL").runr-new"
      if [ -f "$TMPL" ] && cmp -s "$TMPL.runr-new" "$TMPL"; then
        rm -f "$TMPL.runr-new"
        echo "$(host_path "$TMPL") unchanged"
      else
        cat "$TMPL.runr-new" > "$TMPL" || fail "cannot write $(host_path "$TMPL")"
        rm -f "$TMPL.runr-new"
        echo "WebAssembly runtimes written to $(host_path "$TMPL")"
        CHANGED=1
      fi
    fi
    ;;
  containerd)
    CFG="$CONF_DIR/config.toml"
    IMPORTS=$(imports_dir "$CFG")
    if [ -n "$IMPORTS" ]; then
      V=$(config_version "$CFG")
      case "$V" in 2|3) ;; *) fail "unsupported containerd config version in /etc/containerd/config.toml (convert it with: containerd config migrate)" ;; esac
      write_dropin "$HOST$IMPORTS" "$V" "$(systemd_cgroup /etc/containerd/config.toml)" /etc/containerd/config.toml
    else
      NEW="$CFG.runr-new"
      if [ -f "$CFG" ]; then
        V=$(config_version "$CFG")
        case "$V" in 2|3) ;; *) fail "unsupported containerd config version in /etc/containerd/config.toml (convert it with: containerd config migrate)" ;; esac
        SC=$(systemd_cgroup /etc/containerd/config.toml)
        strip_block "$CFG" > "$NEW" || fail "cannot write $(host_path "$NEW")"
      else
        # no config: containerd runs on defaults → a new file with the matching config version
        if [ "${CONTAINERD_MAJOR:-1}" -ge 2 ] 2>/dev/null; then V=3; else V=2; fi
        SC=false
        echo "version = $V" > "$NEW" || fail "cannot write $(host_path "$NEW")"
      fi
      marked_block "$V" "$SC" >> "$NEW" || fail "cannot write $(host_path "$NEW")"
      if [ -f "$CFG" ] && cmp -s "$NEW" "$CFG"; then
        rm -f "$NEW"
        echo "WebAssembly runtimes already in /etc/containerd/config.toml"
      else
        if ! validate_config "$(host_path "$NEW")" "$WANT_HANDLERS" "$DROP_HANDLERS"; then
          rm -f "$NEW"
          fail "$VALIDATION_ERROR — config.toml left unchanged, nothing restarted"
        fi
        if [ -f "$CFG" ]; then
          [ -f "$CFG.runr-pre-wasm" ] || cp -p "$CFG" "$CFG.runr-pre-wasm"
          # rewritten in place: owner, mode and SELinux label (or a symlinked config) stay as they are
          cat "$NEW" > "$CFG" || fail "cannot write /etc/containerd/config.toml"
          rm -f "$NEW"
        else
          mv "$NEW" "$CFG" || fail "cannot write /etc/containerd/config.toml"
        fi
        echo "WebAssembly runtimes written to /etc/containerd/config.toml"
        CHANGED=1
      fi
    fi
    ;;
  *)
    fail "WebAssembly support needs containerd (plain, k3s or rke2); runtime flavor '$RUNTIME_FLAVOR' is not supported"
    ;;
esac

# 3) Restart the runtime when the configuration changed or a handler is not loaded — at most once
#    per change (marker), so a failing node never ends up in a restart loop
MARK="$STATE_DIR/runtime-restarted"
runtime_state "$WANT_HANDLERS"; S=$?
any_loaded "$DROP_HANDLERS"; D=$?
if [ "$CHANGED" = 1 ]; then
  date > "$MARK"
  restart_runtime "$WANT_HANDLERS" "$DROP_HANDLERS"
  rm -f "$MARK"
elif [ "$S" = 0 ] && [ "$D" != 0 ]; then
  rm -f "$MARK"
  echo "WebAssembly runtimes loaded — no restart needed"
elif [ "$S" = 2 ]; then
  echo "no crictl on the node: the loaded runtimes were not checked; configuration unchanged — no restart"
elif [ -f "$MARK" ]; then
  fail "the WebAssembly runtimes are configured but not loaded after an earlier runtime restart — check the containerd log"
else
  date > "$MARK"
  restart_runtime "$WANT_HANDLERS" "$DROP_HANDLERS"
  rm -f "$MARK"
fi

# 4) Binaries of RunR shims no longer selected (kept while a process of theirs runs)
echo "$WASM_ALL_SHIMS" > "$SHIMS_FILE" || fail "cannot write $(host_path "$SHIMS_FILE")"
while read -r ID BIN HANDLER REST; do
  [ -n "$HANDLER" ] || continue
  case " $WANT_HANDLERS " in *" $HANDLER "*) continue ;; esac
  [ -e "$BIN_DIR/$BIN" ] || continue
  if shim_running "$BIN"; then
    echo "$BIN kept: processes of the $ID shim still run on this node"
    continue
  fi
  rm -f "$BIN_DIR/$BIN" "$STATE_DIR/$BIN.sha256" || fail "cannot remove /usr/local/bin/$BIN"
  echo "$BIN removed (the $ID shim is no longer selected)"
done < "$SHIMS_FILE"
rm -f "$SHIMS_FILE"

date > /tmp/runr-wasm-ready
echo "runr: wasm ready: $WANT_HANDLERS"
sleep infinity
BASH;
    }

    /**
     * Node cleanup (uninstall, nodes taken out of the selection) — the inverse of the install:
     * refuses while a RunR shim process runs on the node, removes RunR's drop-ins / marked blocks
     * (config.toml checked with config dump before it is rewritten), restarts the runtime only when
     * it still has a RunR handler loaded, checks the configuration it runs with now, then removes
     * the binaries and /var/lib/runr/wasm. Idempotent: a clean node passes without changes or
     * restarts.
     */
    public static function cleanup(): string
    {
        return self::helpers().<<<'BASH'
ALL_HANDLERS=$(handlers_of "$WASM_ALL_SHIMS")
SHIMS_FILE="/tmp/runr-wasm-shims"
echo "$WASM_ALL_SHIMS" > "$SHIMS_FILE" || fail "cannot write $SHIMS_FILE"

# 1) Never take a shim away from running WebAssembly pods
while read -r ID BIN HANDLER REST; do
  [ -n "$HANDLER" ] || continue
  if shim_running "$BIN"; then
    fail "processes of the $ID shim ($BIN) still run on this node — delete or redeploy the WebAssembly workloads first, then uninstall again"
  fi
done < "$SHIMS_FILE"

# 2) RunR's runtime handlers out of containerd's configuration
CHANGED=0
for f in "$CONF_DIR/config-v3.toml.d/$DROPIN" "$CONF_DIR/config.toml.d/$DROPIN" "$CONF_DIR/conf.d/$DROPIN"; do
  [ -e "$f" ] || continue
  rm -f "$f" || fail "cannot remove $(host_path "$f")"
  echo "removed $(host_path "$f")"
  CHANGED=1
done
case "$RUNTIME_FLAVOR" in
  k3s|rke2)
    for t in "$CONF_DIR/config-v3.toml.tmpl" "$CONF_DIR/config.toml.tmpl"; do
      has_block "$t" || continue
      strip_block "$t" > "$t.runr-new" || fail "cannot rewrite $(host_path "$t")"
      if grep -v '^[[:space:]]*$' "$t.runr-new" | grep -qv '^{{ template "base" \. }}$'; then
        cat "$t.runr-new" > "$t" || fail "cannot write $(host_path "$t")"
        rm -f "$t.runr-new"
        echo "WebAssembly runtimes removed from $(host_path "$t")"
      else
        # only the base template and RunR's block: the file RunR wrote
        rm -f "$t" "$t.runr-new"
        echo "removed $(host_path "$t")"
      fi
      CHANGED=1
    done
    ;;
  containerd)
    CFG="$CONF_DIR/config.toml"
    IMPORTS=$(imports_dir "$CFG")
    if [ -n "$IMPORTS" ] && [ -e "$HOST$IMPORTS/$DROPIN" ]; then
      rm -f "$HOST$IMPORTS/$DROPIN" || fail "cannot remove $IMPORTS/$DROPIN"
      echo "removed $IMPORTS/$DROPIN"
      CHANGED=1
    fi
    if has_block "$CFG"; then
      NEW="$CFG.runr-new"
      strip_block "$CFG" > "$NEW" || fail "cannot write $(host_path "$NEW")"
      if ! validate_config "$(host_path "$NEW")" "" "$ALL_HANDLERS"; then
        rm -f "$NEW"
        fail "$VALIDATION_ERROR — config.toml left unchanged, nothing restarted"
      fi
      cp -p "$CFG" "$CFG.runr-pre-uninstall" || fail "cannot back up /etc/containerd/config.toml"
      if ! cat "$NEW" > "$CFG"; then
        cat "$CFG.runr-pre-uninstall" > "$CFG"
        rm -f "$NEW"
        fail "cannot write /etc/containerd/config.toml — restored"
      fi
      rm -f "$NEW" "$CFG.runr-pre-uninstall"
      echo "WebAssembly runtimes removed from /etc/containerd/config.toml"
      CHANGED=1
    fi
    ;;
  *)
    fail "runtime flavor '$RUNTIME_FLAVOR' is not supported"
    ;;
esac

# 3) Restart the runtime so it drops the handlers — only when it (may) still have them loaded, or
#    k3s / RKE2 still has them in the config it rendered from its template
any_loaded "$ALL_HANDLERS"; L=$?
if [ "$L" = 0 ] || { [ "$CHANGED" = 1 ] && [ "$L" = 2 ]; } \
  || { [ "$RUNTIME_FLAVOR" != containerd ] && has_block "$CONF_DIR/config.toml"; }; then
  restart_runtime "" "$ALL_HANDLERS"
else
  echo "no WebAssembly runtime loaded — no restart needed"
fi

# 4) The configuration the runtime runs with is valid and without RunR's handlers; then the binaries go
if [ "$RUNTIME_FLAVOR" != "containerd" ] && has_block "$CONF_DIR/config.toml"; then
  fail "RunR's WebAssembly block is still in the generated ${CONTAINERD_CONFIG_DIR}/config.toml after the restart — check ${CONTAINERD_CONFIG_DIR}/config*.tmpl"
fi
if [ -f "$CONF_DIR/config.toml" ]; then
  validate_config "$(host_path "$CONF_DIR/config.toml")" "" "$ALL_HANDLERS" || fail "$VALIDATION_ERROR"
fi
while read -r ID BIN HANDLER REST; do
  [ -n "$BIN" ] || continue
  rm -f "$BIN_DIR/$BIN" "$BIN_DIR/$BIN.runr-tmp" || fail "cannot remove /usr/local/bin/$BIN"
done < "$SHIMS_FILE"
rm -f "$SHIMS_FILE"
rm -rf "$STATE_DIR" || fail "cannot remove /var/lib/runr/wasm"
rm -f "$CONF_DIR/config.toml.runr-pre-wasm"
echo "runr: wasm removed"

date > /tmp/runr-wasm-clean
echo "cleanup done, sleeping..."
sleep infinity
BASH;
    }
}
