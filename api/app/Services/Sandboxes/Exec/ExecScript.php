<?php

namespace App\Services\Sandboxes\Exec;

use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\SandboxManifests;

/**
 * Shell wrappers of the commands the API runs in a sandbox (POSIX sh: dash, busybox ash, bash).
 *
 * Every user command runs as
 *   /bin/sh -c '<prologue>; exec timeout -s KILL <t> "$@"' runr <argv…>
 * where the prologue sources the sandbox env file, exports the request's env, changes to the
 * working directory, lowers RLIMIT_NPROC to the sandbox's PID limit (the sandbox has a UID of its
 * own, so the limit is per sandbox) and records its PID for a later kill. `timeout` (coreutils or
 * busybox) is used where the image has it; the API's own deadline covers the rest.
 */
final class ExecScript
{
    /** Exit code of the wrapper when the working directory does not exist */
    public const EXIT_NO_CWD = 125;

    /**
     * @param  string|list<string>  $cmd  a shell command line or an argv
     * @param  array<string, string>  $env
     * @return list<string>
     */
    public static function command(string|array $cmd, array $env = [], ?string $cwd = null, int $timeout = 60, int $pids = 0, ?string $execId = null): array
    {
        $argv = is_array($cmd) ? array_values(array_map('strval', $cmd)) : ['/bin/sh', '-c', $cmd];
        if ($argv === [] || $argv[0] === '') {
            throw new SandboxException(__('sandboxes.exec_cmd_required'), 422, 'invalid', ['errors' => ['cmd' => [__('sandboxes.exec_cmd_required')]]]);
        }

        $script = self::prologue($env, $cwd ?? SandboxManifests::WORKSPACE, $pids);
        if ($execId !== null) {
            $script .= 'mkdir -p /tmp/.runr/exec 2>/dev/null && echo $$ > /tmp/.runr/exec/'.self::safeName($execId).'.pid 2>/dev/null'."\n";
        }
        if ($timeout > 0) {
            $script .= 'if command -v timeout >/dev/null 2>&1; then exec timeout -s KILL '.(int) $timeout.' "$@"; fi'."\n";
        }
        $script .= 'exec "$@"';

        return array_merge(['/bin/sh', '-c', $script, 'runr'], $argv);
    }

    /** Sources the env file, exports $env, cd $cwd, PID limit. */
    public static function prologue(array $env, string $cwd, int $pids): string
    {
        $lines = ['[ -f '.self::quote(SandboxManifests::ENV_FILE).' ] && . '.self::quote(SandboxManifests::ENV_FILE)];
        foreach ($env as $key => $value) {
            self::assertEnvKey((string) $key);
            $lines[] = 'export '.$key.'='.self::quote((string) $value);
        }
        $lines[] = 'cd '.self::quote($cwd).' 2>/dev/null || { echo "runr: working directory '.str_replace(['"', '\\', '$', '`'], '', $cwd).' does not exist" >&2; exit '.self::EXIT_NO_CWD.'; }';
        if ($pids > 0) {
            $lines[] = 'ulimit -u '.(int) $pids.' 2>/dev/null || ulimit -p '.(int) $pids.' 2>/dev/null || true';
        }

        return implode("\n", $lines)."\n";
    }

    /** Content of the env file (export lines). */
    public static function envFile(array $env): string
    {
        $out = "# RunR sandbox environment\n";
        foreach ($env as $key => $value) {
            self::assertEnvKey((string) $key);
            $out .= 'export '.$key.'='.self::quote((string) $value)."\n";
        }

        return $out;
    }

    /** Single-quoted shell word. */
    public static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    public static function assertEnvKey(string $key): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/', $key)) {
            throw new SandboxException(__('sandboxes.env_key_invalid', ['key' => $key]), 422, 'invalid', ['errors' => ['env' => [__('sandboxes.env_key_invalid', ['key' => $key])]]]);
        }
    }

    /** A name safe inside a path (ids are ULIDs). */
    public static function safeName(string $value): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9_-]/', '', $value));
    }

    // ── Internal scripts (no user command; argv passed as $1 …) ──────────────────────────────

    /** Extract a tar from stdin into / after creating $dirs: argv. */
    public static function extractTarAt(string $dir, array $mkdirs = []): array
    {
        $script = 'set -e'."\n";
        foreach (array_unique($mkdirs) as $d) {
            $script .= 'mkdir -p '.self::quote($d)."\n";
        }
        $script .= 'mkdir -p '.self::quote($dir)."\n".'exec tar -x -m -o -f - -C '.self::quote($dir);

        return ['/bin/sh', '-c', $script];
    }

    /** Tar of a directory's contents (entries relative to it) on stdout. */
    public static function createTar(string $dir): array
    {
        return ['/bin/sh', '-c', '[ -d "$1" ] || { echo "runr: not a directory: $1" >&2; exit 2; }; cd "$1" && exec tar -c -f - .', 'runr', $dir];
    }

    /** List a directory (or describe one file): "type|size|mtime|mode|path" lines. */
    public static function listDir(string $path, int $max): array
    {
        $script = <<<'SH'
p="$1"; max="$2"
if [ ! -e "$p" ] && [ ! -L "$p" ]; then echo "runr: no such file or directory: $p" >&2; exit 2; fi
if [ -d "$p" ]; then
  echo "dir"
  find "$p" -mindepth 1 -maxdepth 1 -exec stat -c '%F|%s|%Y|%a|%n' {} + 2>/dev/null | head -n "$max"
else
  echo "file"
  stat -c '%F|%s|%Y|%a|%n' "$p"
fi
SH;

        return ['/bin/sh', '-c', $script, 'runr', $path, (string) ($max + 1)];
    }

    /** Print a file when it is a regular file of at most $max bytes (exit 3: too large, 2: not a file). */
    public static function readFile(string $path, int $max): array
    {
        $script = <<<'SH'
p="$1"; max="$2"
[ -f "$p" ] || { echo "runr: not a regular file: $p" >&2; exit 2; }
s=$(wc -c < "$p" | tr -d ' ')
[ "$s" -le "$max" ] || { echo "runr: file too large: $s bytes" >&2; exit 3; }
exec cat -- "$p"
SH;

        return ['/bin/sh', '-c', $script, 'runr', $path, (string) $max];
    }

    /** Write stdin to a file (parents created), then set its mode. */
    public static function writeFile(string $path, ?string $mode = null): array
    {
        $script = 'set -e'."\n".'d=$(dirname -- "$1"); mkdir -p -- "$d"; cat > "$1"';
        if ($mode !== null) {
            $script .= "\n".'chmod '.self::quote($mode).' -- "$1"';
        }

        return ['/bin/sh', '-c', $script, 'runr', $path];
    }

    public static function remove(string $path, bool $recursive): array
    {
        $script = $recursive
            ? '[ -e "$1" ] || [ -L "$1" ] || { echo "runr: no such file or directory: $1" >&2; exit 2; }; exec rm -rf -- "$1"'
            : '[ -e "$1" ] || [ -L "$1" ] || { echo "runr: no such file or directory: $1" >&2; exit 2; }; if [ -d "$1" ] && [ ! -L "$1" ]; then exec rmdir -- "$1"; else exec rm -f -- "$1"; fi';

        return ['/bin/sh', '-c', $script, 'runr', $path];
    }

    public static function mkdir(string $path): array
    {
        return ['/bin/sh', '-c', 'exec mkdir -p -- "$1"', 'runr', $path];
    }

    /** Freeze / thaw every process of the sandbox except PID 1 (kill -1 skips init and the caller). */
    public static function signalAll(string $signal): array
    {
        return ['/bin/sh', '-c', 'kill -'.strtoupper(preg_replace('/[^A-Z0-9]/i', '', $signal)).' -1 2>/dev/null; exit 0'];
    }

    /** Kill the exec whose PID file is $execId (its process group where it leads one). */
    public static function killExec(string $execId): array
    {
        $file = '/tmp/.runr/exec/'.self::safeName($execId).'.pid';

        return ['/bin/sh', '-c', 'p=$(cat '.self::quote($file).' 2>/dev/null) || exit 0; kill -KILL -"$p" 2>/dev/null || kill -KILL "$p" 2>/dev/null; rm -f '.self::quote($file).'; exit 0'];
    }

    // ── Background processes ─────────────────────────────────────────────────────────────────

    /**
     * Start a detached process with its output in $dir/{out,err} and its exit code in $dir/exit;
     * prints the PID. setsid (where the image has it) makes it a process group leader, so a kill
     * reaches its children too.
     */
    public static function startProcess(string|array $cmd, string $dir, array $env, string $cwd, int $pids): array
    {
        $argv = is_array($cmd) ? array_values(array_map('strval', $cmd)) : ['/bin/sh', '-c', $cmd];

        $script = self::prologue($env, $cwd, $pids);
        $script .= 'd='.self::quote($dir)."\n";
        $script .= 'mkdir -p "$d" || exit 1'."\n";
        $script .= 'export RUNR_PROC_DIR="$d"'."\n";
        $script .= 'inner=\'"$@"; echo $? > "$RUNR_PROC_DIR/exit"\''."\n";
        $script .= 'if command -v setsid >/dev/null 2>&1; then'."\n";
        $script .= '  nohup setsid /bin/sh -c "$inner" runr-proc "$@" > "$d/out" 2> "$d/err" < /dev/null &'."\n";
        $script .= 'else'."\n";
        $script .= '  nohup /bin/sh -c "$inner" runr-proc "$@" > "$d/out" 2> "$d/err" < /dev/null &'."\n";
        $script .= 'fi'."\n";
        $script .= 'echo $!';

        return array_merge(['/bin/sh', '-c', $script, 'runr'], $argv);
    }

    /**
     * State of processes: one "id|state|exit" line per "id:pid:dir" argument — state running or
     * ended (zombies count as ended), exit the recorded exit code or empty.
     */
    public static function processStates(array $items): array
    {
        $script = <<<'SH'
for item in "$@"; do
  id=${item%%:*}; rest=${item#*:}; pid=${rest%%:*}; dir=${rest#*:}
  state=ended
  if [ -r "/proc/$pid/stat" ]; then
    s=$(sed 's/^.*) //' "/proc/$pid/stat" 2>/dev/null | cut -d' ' -f1)
    [ "$s" = "Z" ] || state=running
  fi
  code=$(cat "$dir/exit" 2>/dev/null | tr -d '\n ')
  echo "$id|$state|$code"
done
SH;
        $args = [];
        foreach ($items as $item) {
            $args[] = $item['id'].':'.(int) $item['pid'].':'.$item['dir'];
        }

        return array_merge(['/bin/sh', '-c', $script, 'runr'], $args);
    }

    /** Last $bytes of a process's stdout and stderr, separated by a marker line. */
    public static function processLogs(string $dir, int $bytes, string $marker): array
    {
        $script = 'tail -c '.(int) $bytes.' "$1/out" 2>/dev/null; printf "\n%s\n" '.self::quote($marker).'; tail -c '.(int) $bytes.' "$1/err" 2>/dev/null; exit 0';

        return ['/bin/sh', '-c', $script, 'runr', $dir];
    }

    /** Follow a process's output (stdout → stdout, stderr → stderr) until it ends. */
    public static function followProcess(string $dir, int $pid): array
    {
        $script = <<<'SH'
d="$1"; pid="$2"
tail -c +1 -f "$d/out" 2>/dev/null & t1=$!
tail -c +1 -f "$d/err" >&2 2>/dev/null & t2=$!
while [ -r "/proc/$pid/stat" ] && [ "$(sed 's/^.*) //' "/proc/$pid/stat" | cut -d' ' -f1)" != "Z" ]; do sleep 1; done
sleep 1
kill "$t1" "$t2" 2>/dev/null
code=$(cat "$d/exit" 2>/dev/null | tr -d '\n ')
exit "${code:-0}"
SH;

        return ['/bin/sh', '-c', $script, 'runr', $dir, (string) $pid];
    }

    /** Send $signal to a process group (or the process). */
    public static function killProcess(int $pid, string $signal): array
    {
        $signal = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $signal)) ?: 'TERM';

        return ['/bin/sh', '-c', 'kill -'.$signal.' -'.$pid.' 2>/dev/null || kill -'.$signal.' '.$pid.' 2>/dev/null; exit 0'];
    }

    // ── Code interpreter ─────────────────────────────────────────────────────────────────────

    /**
     * Run code: stdin is the code, written to $file (under /tmp/.runr/code, outside the listed
     * directories). A marker file is touched, the first interpreter candidate the image has runs
     * the file, then — after a "<marker>" line on stdout — every file under /workspace and /output
     * newer than the marker is printed as "size|path".
     *
     * @param  list<list<string>>  $candidates  interpreter argv with {file}
     */
    public static function runCode(string $file, array $candidates, array $env, string $cwd, int $timeout, int $pids, string $marker, int $maxFiles): array
    {
        $stamp = '/tmp/.runr/code/'.self::safeName(basename($file)).'.marker';

        $script = self::prologue($env, $cwd, $pids);
        $script .= 'mkdir -p /tmp/.runr/code || exit 1'."\n";
        $script .= 'cat > '.self::quote($file).' || exit 1'."\n";
        $script .= ': > '.self::quote($stamp)."\n";
        // busybox find compares whole seconds: start the run in the next second
        $script .= 'if ! find --version >/dev/null 2>&1; then sleep 1; fi'."\n";
        $script .= 'run=""; rc=127'."\n";
        foreach ($candidates as $argv) {
            $words = implode(' ', array_map(fn ($a) => self::quote(str_replace('{file}', $file, (string) $a)), $argv));
            $script .= 'if [ -z "$run" ] && command -v '.self::quote((string) $argv[0]).' >/dev/null 2>&1; then run=1; ';
            $script .= $timeout > 0
                ? 'if command -v timeout >/dev/null 2>&1; then timeout -s KILL '.(int) $timeout.' '.$words.' < /dev/null; else '.$words.' < /dev/null; fi; rc=$?; fi'."\n"
                : $words.' < /dev/null; rc=$?; fi'."\n";
        }
        $names = implode(', ', array_map(fn ($c) => preg_replace('/[^A-Za-z0-9._-]/', '', (string) $c[0]), $candidates));
        $script .= 'if [ -z "$run" ]; then echo "runr: no interpreter found in the image ('.$names.')" >&2; fi'."\n";
        $script .= 'printf "\n%s\n" '.self::quote($marker)."\n";
        $script .= 'find '.self::quote(SandboxManifests::WORKSPACE).' '.self::quote(SandboxManifests::OUTPUT)
            .' -type f -newer '.self::quote($stamp).' 2>/dev/null | head -n '.(int) $maxFiles
            .' | while IFS= read -r f; do s=$(wc -c < "$f" 2>/dev/null | tr -d " "); echo "${s:-0}|$f"; done'."\n";
        $script .= 'exit $rc';

        return ['/bin/sh', '-c', $script];
    }

    /** Tar (stdout) of the given absolute files, entries relative to /. */
    public static function tarFiles(array $paths): array
    {
        $rel = array_map(fn ($p) => ltrim($p, '/'), $paths);

        return array_merge(['/bin/sh', '-c', 'cd / && exec tar -c -f - -- "$@"', 'runr'], $rel);
    }
}
