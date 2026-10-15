<?php

namespace App\Services\Shell;

use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Servers\Reverb\Connection;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Loop;

/**
 * WebSocket handler for interactive shell sessions.
 *
 * Two-phase approach:
 *  1. Wake phase — spawns `php artisan shell:wake` subprocess to find/wake a pod.
 *     Status frames (searching, waking_up, waiting, ready, failed) are forwarded
 *     to the WS client in real-time.
 *  2. PTY phase — once a pod is found, spawns kubectl exec PTY and bridges I/O.
 */
class ShellController
{
    private const IDLE_TIMEOUT = 300; // 5 minutes

    private const KEEP_ALIVE_INTERVAL = 20;  // well within Knative stable-window (60s default)

    public function __invoke(RequestInterface $request, Connection $connection): void
    {
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $token = $query['token'] ?? null;

        if (! $token) {
            $connection->send(json_encode(['error' => __('api.shell.missing_token')]));
            $this->refuse($connection);

            return;
        }

        $redisKey = "exec:config:{$token}";
        $configRaw = Redis::get($redisKey);

        if (! $configRaw) {
            $connection->send(json_encode(['error' => __('api.shell.session_expired')]));
            $this->refuse($connection);

            return;
        }

        Redis::del($redisKey);

        // ExecController stores the session config encrypted (it carries the kubeconfig)
        try {
            $config = json_decode(Crypt::decryptString($configRaw), true);
        } catch (DecryptException) {
            $config = null;
        }
        if (! $config) {
            $connection->send(json_encode(['error' => __('api.shell.invalid_config')]));
            $this->refuse($connection);

            return;
        }

        $loop = Loop::get();
        $ctx = (object) [
            'config' => $config,
            'wakeProc' => null,
            'wakePipes' => [],
            'pty' => null,
            'idleTimer' => null,
            'keepAliveTimer' => null,
            'lastActivity' => time(),
            'openedAt' => null,   // audit: shell.session.open … shell.session.close
            'closeReason' => null,
        ];

        $connection->onClose(fn () => $this->cleanupAll($ctx, $loop));
        $connection->onMessage(fn ($m) => $this->handleMessage($m, $ctx));

        $this->startWakePhase($connection, $ctx, $loop);
        $connection->openBuffer();
    }

    // ── Wake Phase ───────────────────────────────────────────────────────────────

    private function startWakePhase(Connection $conn, object $ctx, $loop): void
    {
        $cmd = sprintf('php %s shell:wake', escapeshellarg(base_path('artisan')));
        $ctx->wakeProc = proc_open($cmd, [
            ['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w'],
        ], $ctx->wakePipes, base_path());

        if (! is_resource($ctx->wakeProc)) {
            $conn->send(json_encode(['error' => __('api.shell.wake_failed')]));
            WebSocketClose::graceful($conn);

            return;
        }

        fwrite($ctx->wakePipes[0], json_encode($ctx->config));
        fclose($ctx->wakePipes[0]);
        stream_set_blocking($ctx->wakePipes[1], false);
        stream_set_blocking($ctx->wakePipes[2], false);

        $buffer = '';

        $loop->addReadStream($ctx->wakePipes[1], function ($stream) use ($conn, $ctx, $loop, &$buffer) {
            $data = @fread($stream, 65536);
            if ($data === false || $data === '') {
                if (! $ctx->pty) {
                    $conn->send(json_encode(['error' => __('api.shell.wake_ended')]));
                    $this->cleanupWake($ctx, $loop);
                    WebSocketClose::graceful($conn);
                }

                return;
            }

            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                $msg = json_decode($line, true);
                if (! $msg) {
                    continue;
                }
                $status = $msg['status'] ?? '';

                if ($status === 'ready') {
                    $this->cleanupWake($ctx, $loop);
                    $this->startPtyPhase(
                        $conn, $ctx, $msg['pod'], $msg['container'] ?? null,
                        $msg['revision'] ?? null, $loop
                    );

                    return;
                }

                if ($status === 'failed') {
                    $conn->send(json_encode(['error' => $msg['error'] ?? __('api.shell.pod_not_found')]));
                    $this->cleanupWake($ctx, $loop);
                    WebSocketClose::graceful($conn);

                    return;
                }

                $conn->send(json_encode(['status' => $status]));
            }
        });

        // Log stderr for debugging
        $loop->addReadStream($ctx->wakePipes[2], function ($s) {
            $data = @fread($s, 65536);
            if ($data !== false && $data !== '') {
                logger()->warning('[shell:wake stderr] '.trim($data));
            }
        });
    }

    // ── PTY Phase ────────────────────────────────────────────────────────────────

    private function startPtyPhase(Connection $conn, object $ctx, string $pod, ?string $container, ?string $revision, $loop): void
    {
        $ctx->pty = new ShellPtyProcess($loop);

        if (! $ctx->pty->spawn([
            'kubeconfig' => $ctx->config['kubeconfig'],
            'pod' => $pod,
            'namespace' => $ctx->config['namespace'],
            'container' => $container,
            'cols' => $ctx->config['cols'] ?? 80,
            'rows' => $ctx->config['rows'] ?? 24,
        ])) {
            $conn->send(json_encode(['error' => __('api.shell.start_failed')]));
            WebSocketClose::graceful($conn);

            return;
        }

        $conn->send(json_encode(['connected' => true, 'pod' => $pod]));

        $ctx->openedAt = time();
        $this->audit($ctx, 'shell.session.open', ['pod' => $pod, 'container' => $container]);

        // PTY → WebSocket
        $ctx->pty->onOutput(function (string $data) use ($conn, $ctx) {
            $ctx->lastActivity = time();
            $conn->send(json_encode(['output' => base64_encode($data)]));
        });

        // Keep-alive: prevent Knative scale-to-zero while shell is active.
        // Target the revision's private service (ClusterIP with queue-proxy),
        // NOT the main service (ExternalName → can't be proxied by K8s API).
        $ka = $ctx->config['keep_alive'] ?? [];
        $kcfg = $ctx->pty->getKubeconfigPath();
        if ($kcfg && $revision && ! empty($ka['namespace'])) {
            $proxyPath = sprintf(
                '/api/v1/namespaces/%s/services/http:%s-private:80/proxy/',
                $ka['namespace'], $revision
            );
            $kaCmd = sprintf(
                'kubectl --kubeconfig=%s get --raw %s > /dev/null 2>&1 &',
                escapeshellarg($kcfg), escapeshellarg($proxyPath)
            );
            // Fire immediately, then repeat every 20s
            @pclose(@popen($kaCmd, 'r'));
            $ctx->keepAliveTimer = $loop->addPeriodicTimer(self::KEEP_ALIVE_INTERVAL, function () use ($ctx, $kaCmd) {
                if ($ctx->pty && $ctx->pty->isRunning()) {
                    @pclose(@popen($kaCmd, 'r'));
                }
            });
        }

        // Idle timeout
        $ctx->lastActivity = time();
        $ctx->idleTimer = $loop->addPeriodicTimer(15, function () use ($conn, $ctx, $loop) {
            if (! $ctx->pty || ! $ctx->pty->isRunning()) {
                $ctx->closeReason ??= 'process_exited';
                $conn->send(json_encode([
                    'output' => base64_encode("\r\n\033[90m[Process exited]\033[0m\r\n"),
                ]));
                $this->cleanupPty($ctx, $loop);
                WebSocketClose::graceful($conn);

                return;
            }

            if (time() - $ctx->lastActivity > self::IDLE_TIMEOUT) {
                $ctx->closeReason ??= 'idle_timeout';
                $conn->send(json_encode([
                    'output' => base64_encode("\r\n\r\n\033[90m[Session closed due to inactivity]\033[0m\r\n"),
                ]));
                $this->cleanupPty($ctx, $loop);
                WebSocketClose::graceful($conn);
            }
        });
    }

    // ── Message Handler ──────────────────────────────────────────────────────────

    private function handleMessage($message, object $ctx): void
    {
        if (! $ctx->pty) {
            return;
        }

        $msg = json_decode((string) $message, true);
        if (! $msg) {
            return;
        }

        if (isset($msg['input'])) {
            $ctx->lastActivity = time();
            $ctx->pty->write(base64_decode($msg['input']));
        } elseif (isset($msg['resize'])) {
            $ctx->lastActivity = time();
            $ctx->pty->resize(
                max(1, (int) ($msg['resize']['cols'] ?? 80)),
                max(1, (int) ($msg['resize']['rows'] ?? 24)),
            );
        }
    }

    /** Refuses a session before its handlers run: the client's close frame is read, then the handshake. */
    private function refuse(Connection $connection): void
    {
        $connection->openBuffer();
        WebSocketClose::graceful($connection);
    }

    // ── Cleanup ──────────────────────────────────────────────────────────────────

    private function cleanupAll(object $ctx, $loop): void
    {
        $this->cleanupWake($ctx, $loop);
        $this->cleanupPty($ctx, $loop);

        if ($ctx->openedAt !== null) {
            $this->audit($ctx, 'shell.session.close', [
                'duration_seconds' => time() - $ctx->openedAt,
                'reason' => $ctx->closeReason ?? 'client_closed',
            ]);
            $ctx->openedAt = null;
        }
    }

    // ── Audit ────────────────────────────────────────────────────────────────────

    /**
     * Shell session events under the identity that requested the session (ExecController put
     * it into the session config): there is no HTTP request on the WebSocket side.
     */
    private function audit(object $ctx, string $action, array $metadata): void
    {
        $audit = $ctx->config['audit'] ?? null;
        if (! is_array($audit)) {
            return;
        }

        try {
            app(AuditLogger::class)->record($action, [
                'actor' => Arr::only($audit, ['actor_type', 'actor_id', 'actor_name', 'actor_email', 'auth_method', 'impersonator_id', 'impersonator_name']),
                'tenant_id' => $audit['tenant_id'] ?? null,
                'target' => $audit['target'] ?? null,
                'context' => Arr::only($audit, ['ip_address', 'user_agent', 'request_id']),
                'metadata' => $metadata + [
                    'session_id' => $audit['session_id'] ?? null,
                    'namespace' => $ctx->config['namespace'] ?? null,
                ],
            ]);
        } catch (\Throwable) {
            // auditing never breaks the session
        }
    }

    private function cleanupWake(object $ctx, $loop): void
    {
        foreach ([1, 2] as $fd) {
            if (isset($ctx->wakePipes[$fd]) && is_resource($ctx->wakePipes[$fd])) {
                $loop->removeReadStream($ctx->wakePipes[$fd]);
            }
        }
        foreach ($ctx->wakePipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if ($ctx->wakeProc && is_resource($ctx->wakeProc)) {
            @proc_terminate($ctx->wakeProc, 9);
            @proc_close($ctx->wakeProc);
        }
        $ctx->wakeProc = null;
        $ctx->wakePipes = [];
    }

    private function cleanupPty(object $ctx, $loop): void
    {
        if ($ctx->pty) {
            $ctx->pty->close();
            $ctx->pty = null;
        }
        if ($ctx->idleTimer) {
            $loop->cancelTimer($ctx->idleTimer);
            $ctx->idleTimer = null;
        }
        if ($ctx->keepAliveTimer) {
            $loop->cancelTimer($ctx->keepAliveTimer);
            $ctx->keepAliveTimer = null;
        }
    }
}
