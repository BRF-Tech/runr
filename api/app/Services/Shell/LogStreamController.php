<?php

namespace App\Services\Shell;

use Illuminate\Support\Facades\Redis;
use Laravel\Reverb\Servers\Reverb\Connection;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\Loop;

/**
 * WebSocket handler that streams deployment logs + metrics.
 *
 * Spawns `php artisan deployment:stream` as a subprocess and pipes its
 * JSON-line stdout to the WebSocket client. The subprocess uses the full
 * Laravel service layer (Loki, kubectl, Prometheus) so we don't block
 * the Reverb event loop.
 */
class LogStreamController
{
    public function __invoke(RequestInterface $request, Connection $connection): void
    {
        $query = [];
        parse_str($request->getUri()->getQuery(), $query);
        $token = $query['token'] ?? null;

        if (! $token) {
            $connection->send(json_encode(['error' => __('api.shell.log_missing_token')]));
            $connection->close();

            return;
        }

        $redisKey = "logstream:config:{$token}";
        $configRaw = Redis::get($redisKey);

        if (! $configRaw) {
            $connection->send(json_encode(['error' => __('api.shell.log_session_expired')]));
            $connection->close();

            return;
        }

        Redis::del($redisKey);

        // Spawn the artisan subprocess — config is passed via stdin
        $cmd = sprintf('php %s deployment:stream', escapeshellarg(base_path('artisan')));
        $process = proc_open($cmd, [
            0 => ['pipe', 'r'], // stdin  — parent writes config
            1 => ['pipe', 'w'], // stdout — parent reads JSON lines
            2 => ['pipe', 'w'], // stderr — discard
        ], $pipes, base_path());

        if (! is_resource($process)) {
            $connection->send(json_encode(['error' => __('api.shell.log_start_failed')]));
            $connection->close();

            return;
        }

        // Send config and close stdin so the subprocess starts
        fwrite($pipes[0], $configRaw);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $connection->send(json_encode(['connected' => true]));

        $loop = Loop::get();
        $buffer = '';

        // stdout → WS (non-blocking, line-buffered JSON)
        $loop->addReadStream($pipes[1], function ($stream) use ($connection, &$buffer) {
            $data = @fread($stream, 65536);
            if ($data === false || $data === '') {
                return;
            }

            $buffer .= $data;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                if ($line !== '') {
                    $connection->send($line);
                }
            }
        });

        // stderr → discard
        $loop->addReadStream($pipes[2], function ($stream) {
            @fread($stream, 65536);
        });

        // Monitor subprocess — close WS if it exits
        $checkTimer = null;
        $checkTimer = $loop->addPeriodicTimer(5, function () use ($connection, $process, $loop, $pipes, &$checkTimer) {
            $status = proc_get_status($process);
            if (! $status['running']) {
                $this->cleanup($process, $pipes, $loop, $checkTimer);
                $connection->send(json_encode(['type' => 'closed']));
                $connection->close();
            }
        });

        $connection->onClose(function () use ($process, $pipes, $loop, &$checkTimer) {
            $this->cleanup($process, $pipes, $loop, $checkTimer);
        });

        $connection->openBuffer();
    }

    private function cleanup($process, array $pipes, $loop, &$timer): void
    {
        foreach ([1, 2] as $fd) {
            if (isset($pipes[$fd]) && is_resource($pipes[$fd])) {
                $loop->removeReadStream($pipes[$fd]);
            }
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        if (is_resource($process)) {
            @proc_terminate($process, 9);
            @proc_close($process);
        }
        if ($timer) {
            $loop->cancelTimer($timer);
            $timer = null;
        }
    }
}
