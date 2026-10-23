<?php

namespace App\Services\Shell;

use App\Http\Controllers\Api\Sandboxes\SandboxExecController;
use App\Models\Sandbox;
use App\Services\Sandboxes\Exec\KubectlCommand;
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
 */
class SandboxExecSocket
{
    private const LOCAL_GRACE_SECONDS = 15;

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
        $cmd = KubectlCommand::argv($target, $kcfg, $config['argv'], true, $tty);
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
                $this->output($connection, $ctx, 'stdout', $data);
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
            @fwrite($ctx->pipes[0], (string) base64_decode($ctx->config['stdin']));
        }
        if ($ctx->queued !== '') {
            @fwrite($ctx->pipes[0], $ctx->queued);
            $ctx->queued = '';
        }
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
        $this->output($connection, $ctx, 'stderr', $ctx->filter->push($data));
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
                    } else {
                        @fclose($ctx->pipes[0]);
                    }
                }
                break;
            case 'kill':
                $ctx->killed = true;
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
            @fwrite($ctx->pipes[0], $data);
        } else {
            $ctx->queued .= $data;
        }
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
                if ($connection && $rest !== false && $rest !== '') {
                    $this->output($connection, $ctx, $type, $fd === 2 ? $ctx->filter->push($rest) : $rest);
                }
                $loop->removeReadStream($ctx->pipes[$fd]);
            }
        }
        if ($connection) {
            $this->output($connection, $ctx, 'stderr', $ctx->filter->finish());
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
