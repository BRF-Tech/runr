<?php

namespace App\Services\Shell;

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
 * apart, binary-safe) and bridges:
 *
 *   server → client  {"type":"start","exec_id"} · {"type":"stdout"|"stderr","data":"<base64>"}
 *                    · {"type":"exit","exit_code","timed_out","duration_ms"} · {"type":"error","message"}
 *   client → server  {"type":"stdin","data":"<base64>"} · {"type":"stdin_close"} · {"type":"kill"}
 *
 * After the exit (or error) frame the server sends a close frame and ends the connection once the
 * client answered it (WebSocketClose). The kubeconfig goes to a 0600 temp file that is deleted
 * when the command ends.
 */
class SandboxExecSocket
{
    private const LOCAL_GRACE_SECONDS = 15;

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
        $target = new PodTarget($config['kubeconfig'], $config['namespace'], $config['pod'], $config['container'] ?? 'sandbox', $config['sandbox_id'] ?? null);
        $kcfg = KubectlCommand::writeKubeconfig($target->kubeconfig);
        // argv form: no shell in between, so a kill reaches kubectl itself
        $cmd = KubectlCommand::argv($target, $kcfg, $config['argv'], true);

        $pipes = [];
        $process = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, sys_get_temp_dir(), [
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

        $ctx = (object) [
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
        ];

        // Initial stdin from the request
        if (! empty($config['stdin'])) {
            @fwrite($pipes[0], (string) base64_decode($config['stdin']));
        }

        $connection->send(json_encode(['type' => 'start', 'exec_id' => $config['exec_id'] ?? null]));

        $loop->addReadStream($pipes[1], function ($stream) use ($connection, $ctx) {
            $data = @fread($stream, 65536);
            if ($data !== false && $data !== '') {
                $this->output($connection, $ctx, 'stdout', $data);
            }
        });
        $loop->addReadStream($pipes[2], function ($stream) use ($connection, $ctx) {
            $data = @fread($stream, 65536);
            if ($data !== false && $data !== '') {
                $this->output($connection, $ctx, 'stderr', $ctx->filter->push($data));
            }
        });

        $connection->onMessage(fn ($message) => $this->message((string) $message, $ctx));
        $connection->onClose(function () use ($ctx, $loop) {
            if (! $ctx->done) {
                $ctx->killed = true;
                $this->finish(null, $ctx, $loop);
            }
        });

        // Exit / timeout watch
        $ctx->timer = $loop->addPeriodicTimer(0.1, function () use ($connection, $ctx, $loop) {
            $status = proc_get_status($ctx->process);
            if (! $status['running']) {
                $this->finish($connection, $ctx, $loop, (int) $status['exitcode']);

                return;
            }
            if (microtime(true) - $ctx->started > $ctx->timeout + self::LOCAL_GRACE_SECONDS) {
                $ctx->killed = true;
                @proc_terminate($ctx->process, 9);
                $this->finish($connection, $ctx, $loop, 137, true);
            }
        });

        $connection->openBuffer();
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
                    @fwrite($ctx->pipes[0], (string) base64_decode((string) $msg['data']));
                }
                break;
            case 'stdin_close':
                if ($ctx->stdinOpen) {
                    @fclose($ctx->pipes[0]);
                    $ctx->stdinOpen = false;
                }
                break;
            case 'kill':
                $ctx->killed = true;
                @proc_terminate($ctx->process, 9);
                break;
        }
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

        // Drain what is left
        foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $type) {
            if (isset($ctx->pipes[$fd]) && is_resource($ctx->pipes[$fd])) {
                $rest = @stream_get_contents($ctx->pipes[$fd]);
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
