<?php

namespace App\Services\Shell;

use App\Http\Controllers\Api\Sandboxes\SandboxExecController;
use App\Models\Sandbox;
use App\Services\Sandboxes\Exec\ExecScript;
use App\Services\Sandboxes\Exec\KubectlCommand;
use App\Services\Sandboxes\Exec\MarkerFilter;
use App\Services\Sandboxes\Exec\PodTarget;
use App\Services\Sandboxes\Exec\TrailerFilter;
use App\Services\Sandboxes\SandboxLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Servers\Reverb\Connection;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Loop;

/**
 * WebSocket handler of the Sandbox API exec stream (/ws/sandbox-exec?token=…), next to the web
 * shell in Reverb. The token (POST /api/sandboxes/{id}/exec/ws, one-time, 60 s) carries the
 * prepared command; the handler runs it with `kubectl exec -i` (no TTY: stdout and stderr stay
 * apart, binary-safe) or, when the token asks for a terminal, `kubectl exec -i -t` on a
 * pseudo-terminal of the client's size, and bridges:
 *
 *   server → client  {"type":"start","exec_id","tty"} · {"type":"stdout"|"stderr","data":"<base64>"}
 *                    · {"type":"exit","exit_code","timed_out","duration_ms","killed"} · {"type":"error","message"}
 *   client → server  {"type":"stdin","data":"<base64>"} · {"type":"stdin_close"} · {"type":"kill"}
 *                    · {"type":"resize","cols","rows"} (TTY only)
 *
 * With a TTY the terminal merges the command's stdout and stderr into stdout frames; stderr frames
 * carry kubectl's own messages. stdin_close sends Ctrl+D (end of input for a program that reads
 * the terminal line by line). Resize frames are applied at most every 100 ms: stty sets the
 * pseudo-terminal's size and SIGWINCH makes kubectl pass it on to the pod.
 *
 * After the exit (or error) frame the server sends a close frame and ends the connection once the
 * client answered it (WebSocketClose). The kubeconfig goes to a 0600 temp file that is deleted
 * when the command ends.
 *
 * Without a terminal the input is framed (token "framed_argv", ExecScript::command($framedStdin)):
 * kubectl's stdin stays open until the command ended — closing it stops the command's output on
 * Kata's Go runtime — each stdin frame goes as "<length>\n<bytes>" and stdin_close as "0\n". The
 * wrapper writes an exit marker with the exit code on stderr when the command ended; the handler
 * strips it, takes the exit code from it and ends kubectl by closing its stdin. A kill (or a client
 * gone) also kills the command through its PID file (ExecScript::killExec). With "acked_input"
 * (Kata's Rust runtime) one frame of at most ExecScript::ACKED_FRAME_BYTES is on its way at a time:
 * the next goes once the reader's ExecScript::ackMarker came on stderr (taken out whole, MarkerFilter).
 */
class SandboxExecSocket
{
    private const LOCAL_GRACE_SECONDS = 15;

    /** After the exit marker: how long kubectl may take to end once its stdin is closed */
    private const MARKER_GRACE_SECONDS = 3.0;

    /** After the exit marker: how long the rest of stdout (its end marker) may take to arrive */
    private const STDOUT_END_SECONDS = 10.0;

    /** The TTY wrapper writes this to stderr once the pseudo-terminal is set up. */
    private const TTY_READY = "\x1erunr-tty-ready\n";

    /** How long the TTY wrapper may take before the stream starts anyway (seconds). */
    private const TTY_READY_TIMEOUT = 5.0;

    /**
     * Sets the pseudo-terminal raw and to the client's size before kubectl starts (raw: the line
     * discipline would echo input that arrives before kubectl made the terminal raw itself),
     * reports ready on stderr and becomes kubectl (the same PID, which resize frames signal).
     */
    private const TTY_WRAPPER = 'stty raw -echo rows "$1" cols "$2" 2>/dev/null; printf \'\036runr-tty-ready\n\' >&2; shift 2; exec "$@"';

    public function __invoke(RequestInterface $request, Connection $connection): void
    {
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $token = $query['token'] ?? null;

        if (! $token || ! is_string($token)) {
            $this->fail($connection, __('sandboxes.ws_missing_token'));

            return;
        }

        $key = "sandbox:exec:{$token}";
        $raw = Redis::get($key);
        Redis::del($key);
        // Stored encrypted (it carries the region kubeconfig), like the web shell's session config
        try {
            $config = $raw ? json_decode(Crypt::decryptString($raw), true) : null;
        } catch (DecryptException) {
            $config = null;
        }

        if (! is_array($config) || empty($config['argv']) || empty($config['kubeconfig'])) {
            $this->fail($connection, __('sandboxes.ws_session_expired'));

            return;
        }

        $loop = Loop::get();
        $tty = ! empty($config['tty']);
        $target = new PodTarget($config['kubeconfig'], $config['namespace'], $config['pod'], $config['container'] ?? 'sandbox', $config['sandbox_id'] ?? null);
        $kcfg = KubectlCommand::writeKubeconfig($target->kubeconfig);
        // argv form: no shell in between, so a kill reaches kubectl itself
        $framed = ! $tty && ! empty($config['framed_argv']) && ! empty($config['exec_id']);
        $cmd = KubectlCommand::argv($target, $kcfg, $framed ? $config['framed_argv'] : $config['argv'], true, $tty);
        $size = [self::dimension($config['cols'] ?? null, 80), self::dimension($config['rows'] ?? null, 24)];
        if ($tty) {
            // kubectl exec -t needs a terminal as its stdin; stderr stays a pipe (kubectl's own
            // messages and its exit trailer)
            $cmd = array_merge(['/bin/sh', '-c', self::TTY_WRAPPER, 'runr-tty', (string) $size[1], (string) $size[0]], $cmd);
            $spec = [['pty'], ['pty'], ['pipe', 'w']];
        } else {
            $spec = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        }

        $pipes = [];
        $process = proc_open($cmd, $spec, $pipes, sys_get_temp_dir(), [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => sys_get_temp_dir(),
        ]);

        if (! is_resource($process)) {
            KubectlCommand::removeKubeconfig($kcfg);
            $this->fail($connection, __('sandboxes.ws_start_failed'));

            return;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        /** @var array<string, mixed> $session the state of the session, which its handlers change */
        $session = [
            'process' => $process,
            'pipes' => $pipes,
            'kcfg' => $kcfg,
            'started' => microtime(true),
            'timeout' => (int) ($config['timeout'] ?? 60),
            'max' => (int) ($config['max_output'] ?? 10485760),
            'sent' => 0,
            'filter' => new TrailerFilter,
            'timer' => null,
            'done' => false,
            'killed' => false,
            'stdinOpen' => true,
            'config' => $config,
            'tty' => $tty,
            'pid' => ($started = proc_get_status($process))['pid'],
            // PHP 8.2 reports an exit code once: a command that ended before this read keeps it here
            'exitCode' => $started['running'] ? null : (int) $started['exitcode'],
            // TTY: input waits until the wrapper reported the terminal ready
            'ready' => false,
            'markerSeen' => false,
            'startSent' => false,
            'queued' => '',
            'stderrHead' => '',
            'size' => $size,
            'pendingSize' => null,
            // Framed input and the exit marker (no terminal)
            'framed' => $framed,
            'marker' => ExecScript::exitMarker((string) ($config['exec_id'] ?? '')),
            'stderrTail' => '',
            'markerExit' => null,
            'endAt' => null,
            // stdout's end marker (framed): kubectl's stdin closes once it and the exit marker came
            'endMarker' => ExecScript::endMarker((string) ($config['exec_id'] ?? '')),
            'stdoutTail' => '',
            'stdoutDone' => false,
            'markerAt' => null,
            'target' => $target,
            'killProc' => null,
            'queuedClose' => false,
            // Acknowledged frames: input not sent yet, a frame on its way, the end to send after it
            'acked' => $framed && ! empty($config['acked_input']),
            'ackFilter' => new MarkerFilter([ExecScript::ackMarker((string) ($config['exec_id'] ?? ''))]),
            'ackPending' => '',
            'ackInFlight' => false,
            'ackEnd' => false,
        ];
        $ctx = (object) $session;

        if (! $tty) {
            $this->begin($connection, $ctx);
        }

        $loop->addReadStream($pipes[1], function ($stream) use ($connection, $ctx, $loop) {
            $data = @fread($stream, 65536);
            if ($data !== false && $data !== '') {
                if (! $ctx->ready) {
                    // Output on the terminal: the wrapper reported ready before kubectl started,
                    // the marker waits in the stderr pipe — the start frame goes first
                    $head = @fread($ctx->pipes[2], 65536);
                    if (is_string($head) && $head !== '') {
                        $this->stderr($connection, $ctx, $head);
                    }
                    $this->begin($connection, $ctx);
                }
                $this->output($connection, $ctx, 'stdout', $ctx->framed ? $this->endOfStdout($ctx, $data) : $data);
            } elseif ($ctx->tty && ($data === false || feof($stream))) {
                // The pseudo-terminal reports an error once kubectl is gone: stop polling it
                $loop->removeReadStream($stream);
            }
        });
        $loop->addReadStream($pipes[2], function ($stream) use ($connection, $ctx) {
            $data = @fread($stream, 65536);
            if ($data !== false && $data !== '') {
                $this->stderr($connection, $ctx, $data);
            }
        });

        $connection->onMessage(fn ($message) => $this->message((string) $message, $ctx));
        $connection->onClose(function () use ($ctx, $loop) {
            if (! $ctx->done) {
                $ctx->killed = true;
                $this->finish(null, $ctx, $loop);
            }
        });

        // Exit / timeout watch; TTY: the ready fallback and the window size
        $ctx->timer = $loop->addPeriodicTimer(0.1, function () use ($connection, $ctx, $loop) {
            $status = proc_get_status($ctx->process);
            if (! $status['running']) {
                $code = (int) $status['exitcode'];
                $this->finish($connection, $ctx, $loop, $code === -1 && $ctx->exitCode !== null ? $ctx->exitCode : $code);

                return;
            }
            if (microtime(true) - $ctx->started > $ctx->timeout + self::LOCAL_GRACE_SECONDS) {
                $ctx->killed = true;
                @proc_terminate($ctx->process, 9);
                $this->finish($connection, $ctx, $loop, 137, true);

                return;
            }
            // The exit marker came, stdout's end does not: kubectl's stdin closes all the same
            if ($ctx->markerAt !== null && $ctx->endAt === null && microtime(true) - $ctx->markerAt > self::STDOUT_END_SECONDS) {
                $this->closeInput($ctx);
            }
            // The command ended (exit marker) and kubectl's stdin is closed: kubectl ends by itself
            if ($ctx->endAt !== null && microtime(true) - $ctx->endAt > self::MARKER_GRACE_SECONDS) {
                @proc_terminate($ctx->process, 9);
            }
            if (! $ctx->ready && microtime(true) - $ctx->started > self::TTY_READY_TIMEOUT) {
                $this->begin($connection, $ctx);
            }
            if ($ctx->ready && $ctx->pendingSize !== null) {
                $this->applySize($ctx);
            }
        });

        $connection->openBuffer();
    }

    /**
     * The command takes input: the start frame, the session's initial stdin and what the client
     * sent meanwhile (TTY).
     */
    private function begin(Connection $connection, object $ctx): void
    {
        if ($ctx->ready) {
            return;
        }
        $ctx->ready = true;
        $this->sendStart($connection, $ctx);

        if (! empty($ctx->config['stdin'])) {
            $this->write($ctx, (string) base64_decode($ctx->config['stdin']));
        }
        if ($ctx->queued !== '') {
            $this->write($ctx, $ctx->queued);
            $ctx->queued = '';
        }
        if (! empty($ctx->queuedClose)) {
            $this->endInput($ctx);
        }
    }

    /** Input to kubectl's stdin: framed ("<length>\n<bytes>") for the framed wrapper. */
    private function write(object $ctx, string $data): void
    {
        if ($data === '' || ! is_resource($ctx->pipes[0] ?? null)) {
            return;
        }
        if ($ctx->acked) {
            $ctx->ackPending .= $data;
            $this->pump($ctx);

            return;
        }
        @fwrite($ctx->pipes[0], $ctx->framed ? strlen($data)."\n".$data : $data);
    }

    /** The end of framed input ("0\n"): after the frames still waiting, when acknowledged. */
    private function endInput(object $ctx): void
    {
        if ($ctx->acked) {
            $ctx->ackEnd = true;
            $this->pump($ctx);
        } elseif (is_resource($ctx->pipes[0] ?? null)) {
            @fwrite($ctx->pipes[0], "0\n");
        }
    }

    /** Acknowledged frames: the next one when none is on its way. */
    private function pump(object $ctx): void
    {
        if ($ctx->ackInFlight || ! is_resource($ctx->pipes[0] ?? null)) {
            return;
        }
        if ($ctx->ackPending !== '') {
            $piece = substr($ctx->ackPending, 0, ExecScript::ACKED_FRAME_BYTES);
            $ctx->ackPending = (string) substr($ctx->ackPending, strlen($piece));
            $ctx->ackInFlight = true;
            @fwrite($ctx->pipes[0], strlen($piece)."\n".$piece);
        } elseif ($ctx->ackEnd) {
            $ctx->ackEnd = false;
            @fwrite($ctx->pipes[0], "0\n");
        }
    }

    /** Takes the reader's acknowledgements out of stderr, whole, and sends the next frame. */
    private function acks(object $ctx, string $data): string
    {
        if (! $ctx->acked) {
            return $data;
        }
        [$data, $found] = $ctx->ackFilter->push($data);
        if ($found !== []) {
            $ctx->ackInFlight = false;
            $this->pump($ctx);
        }

        return $data;
    }

    private function sendStart(Connection $connection, object $ctx): void
    {
        if (! $ctx->startSent) {
            $ctx->startSent = true;
            $connection->send(json_encode(['type' => 'start', 'exec_id' => $ctx->config['exec_id'] ?? null, 'tty' => $ctx->tty]));
        }
    }

    /**
     * kubectl's stderr without its exit trailer. TTY: the wrapper's ready marker comes first (it
     * is written before kubectl starts) and is held back with what precedes it.
     */
    private function stderr(Connection $connection, object $ctx, string $data): void
    {
        if ($ctx->tty && ! $ctx->markerSeen) {
            $ctx->stderrHead .= $data;
            $at = strpos($ctx->stderrHead, self::TTY_READY);
            if ($at === false) {
                return;
            }
            $data = substr($ctx->stderrHead, 0, $at).substr($ctx->stderrHead, $at + strlen(self::TTY_READY));
            $ctx->stderrHead = '';
            $ctx->markerSeen = true;
            $this->begin($connection, $ctx);
        }
        if ($ctx->framed) {
            $data = $this->exitMarker($ctx, $this->acks($ctx, $data));
        }
        $this->output($connection, $ctx, 'stderr', $ctx->filter->push($data));
    }

    /**
     * Takes the wrapper's exit marker out of stderr: on the marker, the exit code is kept and
     * kubectl's stdin closed (the command has ended, nothing more to lose). The bytes that may be
     * the start of a marker are held back until the next chunk.
     */
    private function exitMarker(object $ctx, string $data): string
    {
        $data = $ctx->stderrTail.$data;
        $ctx->stderrTail = '';
        $at = strpos($data, $ctx->marker);
        if ($at !== false) {
            $end = strpos($data, "\n", $at);
            if ($end === false) {
                $ctx->stderrTail = substr($data, $at);

                return substr($data, 0, $at);
            }
            $code = substr($data, $at + strlen($ctx->marker), $end - $at - strlen($ctx->marker));
            if (ctype_digit($code)) {
                $ctx->markerExit = (int) $code;
                $ctx->markerAt = microtime(true);
                if ($ctx->stdoutDone) {
                    $this->closeInput($ctx);
                }
            }

            return substr($data, 0, $at).substr($data, $end + 1);
        }
        // Hold back a suffix that could start the marker
        for ($keep = min(strlen($data), strlen($ctx->marker) - 1); $keep > 0; $keep--) {
            if (str_starts_with($ctx->marker, substr($data, -$keep))) {
                $ctx->stderrTail = substr($data, -$keep);

                return substr($data, 0, -$keep);
            }
        }

        return $data;
    }

    /**
     * Takes stdout's end marker out of the command's stdout (framed): once it came and the exit
     * marker did, kubectl's stdin closes. The bytes that may be the start of the marker are held back.
     */
    private function endOfStdout(object $ctx, string $data): string
    {
        $data = $ctx->stdoutTail.$data;
        $ctx->stdoutTail = '';
        if ($ctx->stdoutDone) {
            return $data;
        }
        $at = strpos($data, $ctx->endMarker);
        if ($at !== false) {
            $ctx->stdoutDone = true;
            if ($ctx->markerExit !== null) {
                $this->closeInput($ctx);
            }

            return substr($data, 0, $at).substr($data, $at + strlen($ctx->endMarker));
        }
        for ($keep = min(strlen($data), strlen($ctx->endMarker) - 1); $keep > 0; $keep--) {
            if (str_starts_with($ctx->endMarker, substr($data, -$keep))) {
                $ctx->stdoutTail = substr($data, -$keep);

                return substr($data, 0, -$keep);
            }
        }

        return $data;
    }

    /** The command and its output ended: kubectl's stdin closes, and kubectl ends (MARKER_GRACE_SECONDS). */
    private function closeInput(object $ctx): void
    {
        if ($ctx->endAt !== null) {
            return;
        }
        $ctx->endAt = microtime(true);
        if (is_resource($ctx->pipes[0] ?? null)) {
            @fclose($ctx->pipes[0]);
        }
    }

    private function message(string $message, object $ctx): void
    {
        $msg = json_decode($message, true);
        if (! is_array($msg) || $ctx->done) {
            return;
        }

        switch ($msg['type'] ?? null) {
            case 'stdin':
                if ($ctx->stdinOpen && isset($msg['data'])) {
                    $this->input($ctx, (string) base64_decode((string) $msg['data']));
                }
                break;
            case 'stdin_close':
                if ($ctx->stdinOpen) {
                    $ctx->stdinOpen = false;
                    if ($ctx->tty) {
                        // A terminal has no end of file: Ctrl+D ends the input of a line reader
                        $this->input($ctx, "\x04");
                    } elseif ($ctx->framed) {
                        // The end of the input, in-band: kubectl's stdin stays open (class doc)
                        if ($ctx->ready) {
                            $this->endInput($ctx);
                        } else {
                            $ctx->queuedClose = true;
                        }
                    } else {
                        @fclose($ctx->pipes[0]);
                    }
                }
                break;
            case 'kill':
                $ctx->killed = true;
                $this->killCommand($ctx);
                @proc_terminate($ctx->process, 9);
                break;
            case 'resize':
                if ($ctx->tty) {
                    $ctx->pendingSize = [
                        self::dimension($msg['cols'] ?? null, $ctx->size[0]),
                        self::dimension($msg['rows'] ?? null, $ctx->size[1]),
                    ];
                }
                break;
        }
    }

    /** Input for the command; TTY: held back until the terminal is ready. */
    private function input(object $ctx, string $data): void
    {
        if ($ctx->ready) {
            $this->write($ctx, $data);
        } else {
            $ctx->queued .= $data;
        }
    }

    /**
     * Framed sessions: ending kubectl does not end the command (it runs in the background of its
     * wrapper), so a kill reaches it through its PID file, by a kubectl exec of its own that runs
     * next to the session and is reaped by the loop.
     */
    private function killCommand(object $ctx): void
    {
        if (! $ctx->framed || $ctx->killProc !== null || empty($ctx->config['exec_id'])) {
            return;
        }
        $kcfg = KubectlCommand::writeKubeconfig($ctx->target->kubeconfig);
        $pipes = [];
        $proc = @proc_open(KubectlCommand::argv($ctx->target, $kcfg, ExecScript::killExec((string) $ctx->config['exec_id']), false),
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, sys_get_temp_dir(), [
                'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
                'HOME' => sys_get_temp_dir(),
            ]);
        if (! is_resource($proc)) {
            KubectlCommand::removeKubeconfig($kcfg);

            return;
        }
        $ctx->killProc = $proc;
        $started = microtime(true);
        $loop = Loop::get();
        $loop->addPeriodicTimer(0.2, function ($timer) use ($proc, $kcfg, $started, $loop) {
            $status = proc_get_status($proc);
            if ($status['running'] && microtime(true) - $started < 20) {
                return;
            }
            if ($status['running']) {
                @proc_terminate($proc, 9);
            }
            @proc_close($proc);
            KubectlCommand::removeKubeconfig($kcfg);
            $loop->cancelTimer($timer);
        });
    }

    /** The pseudo-terminal's new size (stty), then SIGWINCH: kubectl sends it to the pod. */
    private function applySize(object $ctx): void
    {
        [$cols, $rows] = $ctx->pendingSize;
        $ctx->pendingSize = null;
        if ([$cols, $rows] === $ctx->size || $ctx->pid <= 0) {
            return;
        }
        $ctx->size = [$cols, $rows];
        @exec(sprintf('stty -F %s rows %d cols %d 2>/dev/null', escapeshellarg("/proc/{$ctx->pid}/fd/0"), $rows, $cols));
        if (function_exists('posix_kill')) {
            @posix_kill($ctx->pid, defined('SIGWINCH') ? SIGWINCH : 28);
        } else {
            @exec('kill -WINCH '.$ctx->pid.' 2>/dev/null');
        }
    }

    /** A terminal dimension of a token or a resize frame: 1 to MAX_TERMINAL_SIZE. */
    private static function dimension(mixed $value, int $default): int
    {
        if (! is_numeric($value)) {
            return $default;
        }

        return max(1, min(SandboxExecController::MAX_TERMINAL_SIZE, (int) $value));
    }

    private function output(Connection $connection, object $ctx, string $type, string $data): void
    {
        if ($data === '' || $ctx->sent >= $ctx->max) {
            return;
        }
        $data = substr($data, 0, $ctx->max - $ctx->sent);
        $ctx->sent += strlen($data);
        $connection->send(json_encode(['type' => $type, 'data' => base64_encode($data)]));
    }

    private function finish(?Connection $connection, object $ctx, $loop, ?int $exitCode = null, bool $timedOut = false): void
    {
        if ($ctx->done) {
            return;
        }
        $ctx->done = true;

        if ($ctx->timer) {
            $loop->cancelTimer($ctx->timer);
        }
        if ($connection) {
            // A TTY command that ended before its terminal was ready still starts its stream
            $this->sendStart($connection, $ctx);
        }

        // Drain what is left
        foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $type) {
            if (isset($ctx->pipes[$fd]) && is_resource($ctx->pipes[$fd])) {
                $rest = @stream_get_contents($ctx->pipes[$fd]);
                if ($fd === 2 && $ctx->stderrHead !== '') {
                    $rest = str_replace(self::TTY_READY, '', $ctx->stderrHead).($rest === false ? '' : $rest);
                    $ctx->stderrHead = '';
                }
                if ($fd === 2 && $ctx->framed && is_string($rest)) {
                    // kubectl can end before the read callback saw the exit marker
                    $rest = $this->exitMarker($ctx, $ctx->acked ? $this->acks($ctx, $rest).$ctx->ackFilter->flush() : $rest);
                }
                if ($fd === 1 && $ctx->framed && is_string($rest)) {
                    $rest = $this->endOfStdout($ctx, $rest).$ctx->stdoutTail;
                    $ctx->stdoutTail = '';
                }
                if ($connection && $rest !== false && $rest !== '') {
                    $this->output($connection, $ctx, $type, $fd === 2 ? $ctx->filter->push($rest) : $rest);
                }
                $loop->removeReadStream($ctx->pipes[$fd]);
            }
        }
        if ($connection && $ctx->stderrTail !== '') {
            $this->output($connection, $ctx, 'stderr', $ctx->filter->push($ctx->stderrTail));
            $ctx->stderrTail = '';
        }
        if ($connection) {
            $this->output($connection, $ctx, 'stderr', $ctx->filter->finish());
        }
        if ($ctx->killed && ! $timedOut && $ctx->markerExit === null) {
            // The client is gone or asked for a kill: the command goes too (framed sessions)
            $this->killCommand($ctx);
        }
        if ($ctx->markerExit !== null) {
            $exitCode = $ctx->markerExit;
        }

        foreach ($ctx->pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($ctx->process)) {
            if (! $timedOut && $ctx->killed) {
                @proc_terminate($ctx->process, 9);
            }
            @proc_close($ctx->process);
        }
        KubectlCommand::removeKubeconfig($ctx->kcfg);

        $durationMs = (int) ((microtime(true) - $ctx->started) * 1000);
        $exitCode ??= 137;
        if (! $timedOut && in_array($exitCode, [124, 137], true) && $durationMs >= $ctx->timeout * 1000) {
            $timedOut = true;
        }

        if ($connection) {
            $connection->send(json_encode([
                'type' => 'exit',
                'exit_code' => $exitCode,
                'timed_out' => $timedOut,
                'duration_ms' => $durationMs,
                'killed' => $ctx->killed,
            ]));
            // Closing handshake: the client may still be sending stdin
            WebSocketClose::graceful($connection, $loop);
        }

        $this->record($ctx, $exitCode, $durationMs, $timedOut);
    }

    /** Idle timer reset + activity log (best effort: Reverb must not die on a DB hiccup). */
    private function record(object $ctx, int $exitCode, int $durationMs, bool $timedOut): void
    {
        $id = $ctx->config['sandbox_id'] ?? null;
        if (! $id) {
            return;
        }

        try {
            $sandbox = Sandbox::find($id);
            if (! $sandbox) {
                return;
            }
            $sandbox->touchActivity();
            SandboxLog::record($sandbox, SandboxLog::EXEC, (string) ($ctx->config['command'] ?? ''), [
                'exit_code' => $exitCode,
                'duration_ms' => $durationMs,
                'timed_out' => $timedOut,
                'via' => 'websocket',
                'tty' => $ctx->tty,
            ], $ctx->config['user_id'] ?? null);
        } catch (\Throwable $e) {
            logger()->warning('sandbox exec log failed', ['sandbox' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** Refuses a session before it started: the error, then the closing handshake. */
    private function fail(Connection $connection, string $message): void
    {
        $connection->send(json_encode(['type' => 'error', 'message' => $message]));
        $connection->openBuffer();   // reads the client's close frame
        WebSocketClose::graceful($connection);
    }
}
