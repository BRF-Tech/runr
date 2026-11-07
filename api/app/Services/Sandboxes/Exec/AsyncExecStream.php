<?php

namespace App\Services\Sandboxes\Exec;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;

/**
 * The exec stream of NativeExecTransport on an event loop (the exec WebSocket in Reverb): connect,
 * upgrade, then the command's stdout / stderr as they come, its stdin and the end of it (v5), and
 * its exit code from the status message. Callbacks:
 *
 *   onOpen()                           the server switched protocols: the command runs
 *   onRefused(NativeExecUnsupported)   nothing started (connection, upgrade refused): use kubectl
 *   onOutput('stdout'|'stderr', bytes)
 *   onEnd(?int $exitCode, ?string $error)  once, when the stream is over — the exit code from the
 *                                      status message, or the error (the cluster's, or a stream that
 *                                      ended without a status); both null after close()
 *
 * close() ends the stream (kill, client gone) and releases the connection; it is safe to call
 * at any time and more than once.
 */
final class AsyncExecStream
{
    private const UPGRADE_TIMEOUT = 15.0;

    private ?ConnectionInterface $conn = null;

    private ?TimerInterface $upgradeTimer = null;

    private string $head = '';

    private bool $open = false;

    private bool $ended = false;

    private bool $closing = false;

    private ?string $status = null;

    private WebSocketFrames $frames;

    /** Writes before the upgrade completed */
    private string $pending = '';

    private string $key = '';

    /**
     * @param  list<string>  $argv
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly ExecKubeconfig $config,
        private readonly PodTarget $target,
        private readonly array $argv,
        private readonly bool $stdin,
        private readonly \Closure $onOpen,
        private readonly \Closure $onRefused,
        private readonly \Closure $onOutput,
        private readonly \Closure $onEnd,
        private readonly float $connectTimeout = 5.0,
    ) {
        $this->frames = new WebSocketFrames;
    }

    public function start(): void
    {
        $connector = new Connector([
            'timeout' => $this->connectTimeout,
            'tls' => $this->config->sslOptions(),
            'happy_eyeballs' => false,
        ], $this->loop);
        $uri = ($this->config->scheme === 'https' ? 'tls://' : 'tcp://').$this->config->host.':'.$this->config->port;

        $this->upgradeTimer = $this->loop->addTimer(self::UPGRADE_TIMEOUT, function () {
            $this->refuse(new NativeExecUnsupported('the exec upgrade timed out'));
        });

        $connector->connect($uri)->then(function (ConnectionInterface $conn) {
            if ($this->ended) {
                $conn->close();

                return;
            }
            $this->conn = $conn;
            [$request, $this->key] = ExecProtocol::request($this->config, $this->target, $this->argv, $this->stdin);
            $conn->on('data', fn (string $data) => $this->data($data));
            $conn->on('close', fn () => $this->closed());
            $conn->write($request);
        }, function (\Throwable $e) {
            $this->refuse(new NativeExecUnsupported('cannot connect to the API server: '.$e->getMessage()));
        });
    }

    /** Input for the command (before the upgrade: held back). */
    public function stdin(string $data): void
    {
        if ($data !== '') {
            $this->write(ExecProtocol::message(ExecProtocol::STDIN, $data));
        }
    }

    /** The end of the command's stdin (v5 close signal). */
    public function closeStdin(): void
    {
        $this->write(ExecProtocol::closeStdin());
    }

    /** Ends the stream: no onEnd result (the caller decided how it ended). */
    public function close(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->cancelTimer();
        if ($this->conn) {
            if ($this->open && ! $this->closing) {
                $this->closing = true;
                $this->conn->write(WebSocketFrames::encode(WebSocketFrames::CLOSE, pack('n', 1000)));
            }
            $this->conn->close();
        }
    }

    private function write(string $bytes): void
    {
        if ($this->ended) {
            return;
        }
        if (! $this->open || ! $this->conn) {
            $this->pending .= $bytes;

            return;
        }
        $this->conn->write($bytes);
    }

    private function data(string $data): void
    {
        if ($this->ended) {
            return;
        }
        if (! $this->open) {
            $this->head .= $data;
            $end = strpos($this->head, "\r\n\r\n");
            if ($end === false) {
                if (strlen($this->head) > 65536) {
                    $this->refuse(new NativeExecUnsupported('the API server answered the exec upgrade with an oversized head'));
                }

                return;
            }
            $rest = (string) substr($this->head, $end + 4);
            try {
                ExecProtocol::accept(substr($this->head, 0, $end), $this->key, $this->stdin, $rest);
            } catch (NativeExecUnsupported $e) {
                $this->refuse($e);

                return;
            } catch (ExecTransportException $e) {
                $this->fail($e->getMessage());

                return;
            }
            $this->open = true;
            $this->head = '';
            $this->cancelTimer();
            ($this->onOpen)();
            if ($this->pending !== '' && $this->conn) {
                $this->conn->write($this->pending);
                $this->pending = '';
            }
            $data = $rest;
        }

        try {
            $messages = $this->frames->feed($data);
        } catch (ExecTransportException $e) {
            $this->fail($e->getMessage());

            return;
        }
        foreach ($messages as [$opcode, $payload]) {
            if ($this->ended) {
                return;
            }
            if ($opcode === WebSocketFrames::BINARY && $payload !== '') {
                $channel = ord($payload[0]);
                $bytes = (string) substr($payload, 1);
                if ($channel === ExecProtocol::STDOUT && $bytes !== '') {
                    ($this->onOutput)('stdout', $bytes);
                } elseif ($channel === ExecProtocol::STDERR && $bytes !== '') {
                    ($this->onOutput)('stderr', $bytes);
                } elseif ($channel === ExecProtocol::STATUS && $bytes !== '') {
                    $this->status = ($this->status ?? '').$bytes;
                }
            } elseif ($opcode === WebSocketFrames::PING && $this->conn) {
                $this->conn->write(WebSocketFrames::encode(WebSocketFrames::PONG, $payload));
            } elseif ($opcode === WebSocketFrames::CLOSE && $this->conn) {
                if (! $this->closing) {
                    $this->closing = true;
                    $this->conn->write(WebSocketFrames::encode(WebSocketFrames::CLOSE, pack('n', 1000)));
                }
                $this->conn->end();
            }
        }
    }

    /** The connection is gone: the result of the stream. */
    private function closed(): void
    {
        if ($this->ended) {
            return;
        }
        if (! $this->open) {
            $this->refuse(new NativeExecUnsupported('the API server closed the connection during the exec upgrade'));

            return;
        }
        $this->ended = true;
        $this->cancelTimer();
        if ($this->status === null) {
            ($this->onEnd)(null, 'the exec stream ended before the command reported its exit status');

            return;
        }
        try {
            $code = ExecProtocol::exitCode($this->status);
        } catch (ExecTransportException $e) {
            ($this->onEnd)(null, $e->getMessage());

            return;
        }
        ($this->onEnd)($code, null);
    }

    private function refuse(NativeExecUnsupported $e): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->cancelTimer();
        $this->conn?->close();
        ($this->onRefused)($e);
    }

    private function fail(string $message): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        $this->cancelTimer();
        $this->conn?->close();
        ($this->onEnd)(null, $message);
    }

    private function cancelTimer(): void
    {
        if ($this->upgradeTimer) {
            $this->loop->cancelTimer($this->upgradeTimer);
            $this->upgradeTimer = null;
        }
    }
}
