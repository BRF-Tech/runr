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
 *
 * A command never gets its input over the exec's stdin: on Kata's Go runtime (4.3.1: clh, qemu, fc)
 * the command's stdout stops once the exec's stdin is closed — at most 32 KiB more come through,
 * then the command blocks writing until its timeout, and what it writes right after its input
 * ended can be lost. Keeping that stdin open does not help either: kubectl returns only once its own
 * stdin ends. So the input goes first, by an exec of its own that only stores it (storeInput: no
 * output to lose), and the command's exec reads that file and has no stdin (command($stdinFile),
 * runCode). Uploads (writeFile, extractTarAt) produce no output and stay one exec. Every input is
 * taken as exactly its bytes and its size checked once written (writeExact): input that arrives
 * short fails with a message, on every class.
 *
 * Kata's Rust runtime (4.3.1: dragonball) ends an exec's stdin early when more input reaches it
 * than the sandbox has read yet: a burst over 64 KiB, or 32 KiB pieces 50 ms apart, end at 64 KiB.
 * There the input goes in acknowledged frames (ackedInput): the API sends the next frame only once
 * the sandbox read the last one.
 *
 * gVisor (20260921.0) returns short writes over 64 KiB on an exec's stdout and stderr, and some
 * programs drop the rest (Python's print with PYTHONUNBUFFERED). There a user command writes
 * through pipes inside the sandbox, whose `cat` writes everything on (relayed output:
 * command($relayOutput), runCode, runAndWait()).
 */
final class ExecScript
{
    /** Exit code of the wrapper when the working directory does not exist */
    public const EXIT_NO_CWD = 125;

    /** Exit code of the wrapper when the command's stored input is gone */
    public const EXIT_NO_INPUT = 126;

    /** A stored input no command took (its exec never started) is removed after this long */
    public const STALE_INPUT_SECONDS = 3600;

    /**
     * An image without head (a custom image needs only /bin/sh and tar) reads an upload to the end
     * of stdin, as before; `command -v` is the shell's own, so nothing runs when head is there.
     */
    private const HEAD_OR_CAT = 'command -v head >/dev/null 2>&1 || head() { cat; }';

    /**
     * Input bytes per acknowledged frame (ackedInput): a frame and its header stay well below the
     * guest pipe's 64 KiB, which the guest's 8 KiB writes may not fill to the last byte (on the test
     * VM 60 KiB frames once lost the last 6 bytes of one frame in 20 transfers of 10 MiB)
     */
    public const ACKED_FRAME_BYTES = 49152;

    /**
     * On stderr: the reader of input $id read one frame (ackedInput; framed input with $ackedStdin,
     * where $id is the exec's). A marker with the id, as the command shares stderr: its own bytes
     * are never taken for one (MarkerFilter).
     */
    public static function ackMarker(string $id): string
    {
        return "\x1erunr-ack:".self::safeName($id)."\n";
    }

    /** On stderr: the reader of input $id ended, no more input is read (ackedInput). */
    public static function ackEndMarker(string $id): string
    {
        return "\x1erunr-ack-end:".self::safeName($id)."\n";
    }

    /**
     * With $tty the command runs on a terminal (kubectl exec -t): TERM defaults to
     * xterm-256color, and GNU timeout runs with --foreground — without it, timeout moves the
     * command into a process group of its own, which may not read the terminal (SIGTTIN) and
     * gets none of its signals (Ctrl+C). busybox timeout keeps the process group.
     *
     * $stdinFile: the command's input, stored by storeInput() before: it becomes the command's
     * stdin and is removed at once (the open descriptor keeps it).
     *
     * $framedStdin (no terminal, with an $execId): input streamed while the command runs (the exec
     * WebSocket), whose end must not close the exec's stdin — see framedTail(). With $ackedStdin
     * the reader acknowledges every frame (Kata's Rust runtime, class doc).
     *
     * $relayOutput (no terminal; gVisor): the command's stdout and stderr go through pipes inside
     * the sandbox (relayedTail()).
     *
     * @param  string|list<string>  $cmd  a shell command line or an argv
     * @param  array<string, string>  $env
     * @return list<string>
     */
    public static function command(string|array $cmd, array $env = [], ?string $cwd = null, int $timeout = 60, int $pids = 0, ?string $execId = null, bool $tty = false, ?string $stdinFile = null, bool $framedStdin = false, bool $relayOutput = false, bool $ackedStdin = false): array
    {
        $argv = is_array($cmd) ? array_values(array_map('strval', $cmd)) : ['/bin/sh', '-c', $cmd];
        if ($argv === [] || $argv[0] === '') {
            throw new SandboxException(__('sandboxes.exec_cmd_required'), 422, 'invalid', ['errors' => ['cmd' => [__('sandboxes.exec_cmd_required')]]]);
        }

        $script = self::prologue($env, $cwd ?? SandboxManifests::WORKSPACE, $pids);
        if ($tty) {
            // The sandbox env file and the request's env may set another TERM
            $script .= 'export TERM="${TERM:-xterm-256color}"'."\n";
        }
        if ($framedStdin && ! $tty && $execId !== null) {
            return array_merge(['/bin/sh', '-c', self::framedTrap($execId).$script.self::framedTail($execId, $timeout, $relayOutput, $ackedStdin), 'runr'], $argv);
        }
        $relay = $relayOutput && ! $tty;
        if ($execId !== null) {
            if (! $relay) {
                $script .= self::writePid(self::pidFile($execId))."\n";
            }
            $script .= self::removeStaleInputs(time() - self::STALE_INPUT_SECONDS)."\n";
        }
        if ($stdinFile !== null) {
            $file = self::quote($stdinFile);
            $script .= '[ -f '.$file.' ] || { echo "runr: the command input is missing" >&2; exit '.self::EXIT_NO_INPUT.'; }'."\n";
            $script .= 'exec 0< '.$file.'; rm -f '.$file."\n";
        }
        if ($relay) {
            return array_merge(['/bin/sh', '-c', $script.self::relayedTail($execId, $timeout, $stdinFile !== null), 'runr'], $argv);
        }
        if ($timeout > 0) {
            if ($tty) {
                $script .= 'if timeout --foreground 1 true >/dev/null 2>&1; then exec timeout --foreground -s KILL '.(int) $timeout.' "$@"; fi'."\n";
            }
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

    /** The PID file of the command $execId. */
    public static function pidFile(string $execId): string
    {
        return SandboxManifests::EXEC_DIR.'/'.self::safeName($execId).'.pid';
    }

    /**
     * Shell line that records the shell's PID in $file. The pod creates EXEC_DIR when it starts
     * (SandboxManifests::KEEPALIVE), so the write needs no process: echo and the redirection are
     * the shell's own. A pod started by RunR 0.6.6 or earlier has no such directory until its
     * first command: then, and only then, mkdir runs. Errors are silent, as the PID file only
     * serves a later kill.
     */
    public static function writePid(string $file, string $pid = '$$'): string
    {
        return '{ echo '.$pid.' > '.$file.'; } 2>/dev/null || { mkdir -p '.dirname($file).' && echo '.$pid.' > '.$file.'; } 2>/dev/null';
    }

    /**
     * Shell condition that $dir exists, creating it only when it does not: `[` is the shell's
     * own, so a pod whose KEEPALIVE created $dir starts no mkdir process.
     */
    public static function ensureDir(string $dir): string
    {
        return '{ [ -d '.self::quote($dir).' ] || mkdir -p -- '.self::quote($dir).'; }';
    }

    /** The start of command $execId's exit marker on stderr: "<this><exit code>\n" (framedTrap). */
    public static function exitMarker(string $execId): string
    {
        return "\x1erunr-exit:".self::safeName($execId).':';
    }

    /** The marker that ends command $execId's stdout (framedTrap). */
    public static function endMarker(string $execId): string
    {
        return "\x1erunr-end:".self::safeName($execId)."\n";
    }

    /**
     * The first line of a framed wrapper: whenever the shell exits (the command's end, a missing
     * working directory, a failed mkfifo), it ends stdout with endMarker() and writes the exit
     * marker with its exit code on stderr. kubectl's stdin stays open in a framed session, so the API
     * ends kubectl once both came: stdout may still be on its way when the stderr marker arrives,
     * and closing stdin stops it on Kata's Go runtime. An exit without the markers would leave the
     * session open until its timeout. Subshells (the reader, the command's job) start without the trap.
     */
    private static function framedTrap(string $execId): string
    {
        $id = self::safeName($execId);

        return 'trap \'runr_x=$?; printf "\\036runr-end:%s\\n" '.$id.'; printf "\\036runr-exit:%s:%d\\n" '.$id.' "$runr_x" >&2; exit $runr_x\' EXIT'."\n";
    }

    /**
     * The end of the wrapper for input streamed while the command runs (the exec WebSocket without
     * a terminal). The API sends each piece of input as "<length>\n<bytes>" and "0\n" for its end,
     * and keeps the exec's stdin open: closing it stops the command's output on Kata (class doc). A
     * reader loop turns the pieces back into the command's stdin, which ends at "0". The command
     * runs in the background, so the shell sees it end: the PID file records `timeout` (it leads
     * the command's process group, which a kill reaches as before), and the shell exits with its
     * exit code, which framedTrap() writes as the exit marker on stderr. The API strips the marker
     * and ends kubectl, whose stdin it kept open.
     */
    private static function framedTail(string $execId, int $timeout, bool $relay = false, bool $acked = false): string
    {
        $fifo = SandboxManifests::EXEC_DIR.'/'.self::safeName($execId).'.fifo';

        return implode("\n", array_filter([
            // An asynchronous list gets /dev/null as its stdin: the reader takes the exec's stdin from fd 3
            'exec 3<&0',
            $acked
                // The API sends a frame only once the last was read: nothing follows it to read ahead
                ? 'if command -v head >/dev/null 2>&1; then runr_take() { head -c "$1"; }; else runr_take() { dd bs=1 count="$1" 2>/dev/null; }; fi'
                // Exactly n bytes: GNU head reads no more than it copies; busybox head reads ahead (stdio), dd bs=1 does not
                : 'if head --version >/dev/null 2>&1; then runr_take() { head -c "$1"; }; else runr_take() { dd bs=1 count="$1" 2>/dev/null; }; fi',
            'runr_rd() { while IFS= read -r runr_n; do [ "$runr_n" = 0 ] && break; runr_take "$runr_n"'.($acked ? '; printf \'\036runr-ack:%s\n\' '.self::safeName($execId).' >&2' : '').'; done; }',
            // The reader and the command are jobs of their own (a pipeline is one job, and wait waits for all of it)
            'runr_fifo='.$fifo,
            self::ensureDir(SandboxManifests::EXEC_DIR).' 2>/dev/null',
            '[ ! -e "$runr_fifo" ] || rm -f "$runr_fifo"; mkfifo "$runr_fifo" || { echo "runr: streamed input needs mkfifo in the sandbox" >&2; exit 1; }',
            'runr_rd <&3 > "$runr_fifo" 3<&- &',
            'runr_r=$!',
            self::removeStaleInputs(time() - self::STALE_INPUT_SECONDS),
            self::runAndWait('< "$runr_fifo"', $timeout, self::pidFile($execId), $relay),
            // The input that is left is not the command's any more
            'kill $runr_r 2>/dev/null; rm -f "$runr_fifo"; exec 3<&-',
            'exit $runr_rc',
        ]));
    }

    /**
     * The end of the wrapper whose command writes through pipes inside the sandbox (gVisor: class
     * doc). The command runs in the background, so the shell sees it end and then waits until the
     * relays wrote all it wrote; the PID file records `timeout` (it leads the command's process
     * group, which a kill reaches as before). The exit code is the command's.
     */
    private static function relayedTail(?string $execId, int $timeout, bool $stdinFile): string
    {
        return implode("\n", [
            // An asynchronous list gets /dev/null as its stdin: the command takes the stored input from fd 3
            $stdinFile ? 'exec 3<&0' : 'exec 3</dev/null',
            self::runAndWait('<&3', $timeout, $execId !== null ? self::pidFile($execId) : null, true),
            'exec 3<&-',
            'exit ${runr_rc:-1}',
        ]);
    }

    /**
     * Shell lines that run "$@" with $stdin (a redirection) under `timeout` where the image has it,
     * in the background: the PID file records it (timeout leads the command's process group, which
     * a kill reaches), and $runr_rc gets its exit code once it ended.
     *
     * With $relay (gVisor, class doc) its stdout and stderr go through pipes inside the sandbox, each
     * read by a `cat` that repeats a short write until all is written; anonymous pipes cost no
     * mkfifo and no rm. Where the shell has pipefail (dash, busybox ash, bash), the group that runs
     * the command reads its own PID from /proc/self/stat for the PID file and execs `timeout`, and
     * the pipelines' status is the exit code: two forks fewer. Otherwise the command runs in the
     * background of that group and its exit code comes back over a command substitution's pipe
     * (fd 8). Either way the shell goes on once both cats wrote all. Without cat the command writes
     * directly, as before.
     */
    private static function runAndWait(string $stdin, int $timeout, ?string $pidFile, bool $relay): string
    {
        $start = function (string $close) use ($stdin, $timeout): string {
            $cmd = '"$@" '.$stdin.' 3<&-'.$close.' &';

            return $timeout > 0
                ? 'if command -v timeout >/dev/null 2>&1; then timeout -s KILL '.$timeout.' '.$cmd.' else '.$cmd.' fi;'
                : $cmd;
        };
        $pid = $pidFile !== null ? ' '.self::writePid($pidFile, '$!').';' : '';
        $plain = $start('').$pid.' wait $! 2>/dev/null; runr_rc=$?';
        if (! $relay) {
            return $plain;
        }

        $cmd = '"$@" '.$stdin.' 3<&- 6>&- 7>&-';
        $exec = $timeout > 0
            ? 'if command -v timeout >/dev/null 2>&1; then exec timeout -s KILL '.$timeout.' '.$cmd.'; else exec '.$cmd.'; fi'
            : 'exec '.$cmd;
        $own = $pidFile !== null ? 'read -r runr_me _ < /proc/self/stat; '.self::writePid($pidFile, '$runr_me').'; ' : '';

        return 'if command -v cat >/dev/null 2>&1; then exec 6>&1 7>&2; '
            .'if command set -o pipefail 2>/dev/null; then '
            .'{ { { '.$own.$exec.'; } | cat >&6 6>&- 7>&-; } 2>&1 | cat >&7 6>&- 7>&-; }; runr_rc=$?; '
            .'else runr_rc=$( { { { '.$start(' 6>&- 7>&- 8>&-').$pid.' wait $! 2>/dev/null; echo $? >&8; } | cat >&6 6>&- 7>&- 8>&-; } 2>&1 | cat >&7 6>&- 7>&- 8>&-; } 8>&1 ); fi; '
            .'exec 6>&- 7>&-; '
            .'else '.$plain.'; fi';
    }

    /**
     * Shell lines that write exactly $bytes of stdin to $target (a shell word) and check the size
     * that arrived: the shell's fd 5 shares head's file offset, which /proc/self/fdinfo shows, so
     * the check runs no process (wc only where fdinfo is missing). Input that arrived short is
     * removed and fails with a message.
     */
    private static function writeExact(string $target, int $bytes, string $what): string
    {
        $n = max(0, $bytes);

        return implode("\n", [
            'exec 5> '.$target,
            'head -c '.$n.' >&5',
            'runr_pos=-1; [ ! -r /proc/self/fdinfo/5 ] || while IFS=: read -r runr_k runr_v; do [ "$runr_k" != pos ] || runr_pos=$runr_v; done < /proc/self/fdinfo/5',
            'exec 5>&-',
            '[ $runr_pos -ne -1 ] || runr_pos=$(wc -c < '.$target.')',
            '[ $runr_pos -eq '.$n.' ] || { echo "runr: '.$what.' ended after $(($runr_pos)) of '.$n.' bytes" >&2; rm -f '.$target.'; exit 1; }',
        ]);
    }

    /**
     * Wraps $argv for input in acknowledged frames (Kata's Rust runtime, class doc): the API sends
     * "<length>\n<bytes>" frames of at most ACKED_FRAME_BYTES and "0\n" for the end, each once the
     * reader wrote ackMarker($id) on stderr for the last; the reader passes the bytes on to $argv's
     * stdin and writes ackEndMarker($id) when it ended. The exit code is $argv's.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public static function ackedInput(array $argv, string $id): array
    {
        return array_merge(['/bin/sh', '-c', implode("\n", [
            // Nothing follows a frame before it was acknowledged, so head cannot read ahead
            'if command -v head >/dev/null 2>&1; then runr_take() { head -c "$1"; }; else runr_take() { dd bs=1 count="$1" 2>/dev/null; }; fi',
            // A script that ended early leaves the reader's head a closed pipe: it stops, quietly
            'runr_rd() { while IFS= read -r runr_n; do [ "$runr_n" = 0 ] && break; runr_take "$runr_n" 2>/dev/null || exit 1; printf \'\036runr-ack:%s\n\' '.self::safeName($id).' >&2; done; }',
            'runr_rd | "$@"; runr_rc=$?',
            'printf \'\036runr-ack-end:%s\n\' '.self::safeName($id).' >&2',
            'exit $runr_rc',
        ]), 'runr'], $argv);
    }

    /**
     * Where the input of command $id waits for it (storeInput, command($stdinFile)): the name starts
     * with the time it was stored, so a later command can tell a stale one (removeStaleInputs).
     */
    public static function inputFile(string $id, ?int $now = null): string
    {
        return SandboxManifests::EXEC_DIR.'/'.($now ?? time()).'-'.self::safeName($id).'.in';
    }

    /**
     * Shell line that removes stored inputs from before $cutoff (unix seconds): inputs whose command
     * never started. The shell's glob, parameter expansion and test find them, so only a stale file
     * costs a process (rm).
     */
    public static function removeStaleInputs(int $cutoff): string
    {
        return 'for runr_f in '.SandboxManifests::EXEC_DIR.'/*.in; do runr_t=${runr_f##*/}; runr_t=${runr_t%%-*}; '
            .'case $runr_t in \'\'|*[!0-9]*) continue ;; esac; [ "$runr_t" -lt '.$cutoff.' ] && rm -f "$runr_f"; done';
    }

    /**
     * Stores $bytes of stdin in $file (its directory created when missing): the exec that carries
     * a command's input or a code run's code, before the exec that uses it (class doc).
     */
    public static function storeInput(string $file, int $bytes): array
    {
        return ['/bin/sh', '-c', self::ensureDir(dirname($file)).' || exit 1'."\n".self::HEAD_OR_CAT."\n".self::writeExact('"$1"', $bytes, 'the input'), 'runr', $file];
    }

    // ── Internal scripts (no user command; argv passed as $1 …) ──────────────────────────────

    /**
     * Extract a tar from stdin into $dir after creating $dir and $mkdirs: argv. One mkdir process
     * creates whichever of them are missing, and none runs when they all exist (`[` is the shell's
     * own): the env file of a claimed warm pod goes to a directory its KEEPALIVE created.
     */
    public static function extractTarAt(string $dir, int $bytes, array $mkdirs = []): array
    {
        $words = implode(' ', array_map(self::quote(...), array_values(array_unique([...$mkdirs, $dir]))));
        $script = 'set -e'."\n";
        $script .= 'for d in '.$words.'; do [ -d "$d" ] || { mkdir -p -- '.$words.'; break; }; done'."\n";
        $script .= self::HEAD_OR_CAT."\n";
        // Exactly the archive's bytes, whether or not the exec's stdin ends after them. tar takes an
        // archive cut at a member's end without a word: the archive is written and its size checked first
        $script .= self::ensureDir(SandboxManifests::EXEC_DIR)."\n";
        $script .= 'runr_t='.self::quote(self::inputFile(bin2hex(random_bytes(8))))."\n";
        $script .= self::writeExact('"$runr_t"', $bytes, 'the archive')."\n";
        $script .= 'tar -x -m -o -f "$runr_t" -C '.self::quote($dir).' || { runr_rc=$?; rm -f "$runr_t"; exit $runr_rc; }'."\n";
        $script .= 'rm -f "$runr_t"';

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

    /** Write $bytes of stdin to a file (parents created), then set its mode. */
    public static function writeFile(string $path, int $bytes, ?string $mode = null): array
    {
        $script = 'set -e'."\n".self::HEAD_OR_CAT."\n".'d=$(dirname -- "$1"); mkdir -p -- "$d"'."\n".self::writeExact('"$1"', $bytes, 'the upload');
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
        $file = self::pidFile($execId);

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
     * Run code: $file (under /tmp/.runr/code, outside the listed directories) holds the code,
     * stored by storeInput() before; this exec has no stdin (class doc). A marker file is touched, the first interpreter candidate the image has runs
     * the file, then — after a "<marker>" line on stdout — every file under /workspace and /output
     * newer than the marker is printed as "size|path".
     *
     * @param  list<list<string>>  $candidates  interpreter argv with {file}
     */
    public static function runCode(string $file, array $candidates, array $env, string $cwd, int $timeout, int $pids, string $marker, int $maxFiles, bool $relayOutput = false): array
    {
        $stamp = SandboxManifests::CODE_DIR.'/'.self::safeName(basename($file)).'.marker';

        $script = self::prologue($env, $cwd, $pids);
        $script .= self::ensureDir(SandboxManifests::CODE_DIR).' || exit 1'."\n";
        $script .= '[ -f '.self::quote($file).' ] || { echo "runr: the code file is missing" >&2; exit 1; }'."\n";
        $script .= ': > '.self::quote($stamp)."\n";
        // busybox find compares whole seconds: start the run in the next second
        $script .= 'if ! find --version >/dev/null 2>&1; then sleep 1; fi'."\n";
        $script .= 'run=""; rc=127'."\n";
        foreach ($candidates as $argv) {
            $words = implode(' ', array_map(fn ($a) => self::quote(str_replace('{file}', $file, (string) $a)), $argv));
            $script .= 'if [ -z "$run" ] && command -v '.self::quote((string) $argv[0]).' >/dev/null 2>&1; then run=1; ';
            if ($relayOutput) {
                // gVisor: the interpreter writes through pipes inside the sandbox (class doc)
                $script .= 'set -- '.$words."\n".self::runAndWait('< /dev/null', $timeout, null, true)."\n".'rc=${runr_rc:-1}; fi'."\n";

                continue;
            }
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
