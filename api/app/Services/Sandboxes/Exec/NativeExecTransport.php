<?php

namespace App\Services\Sandboxes\Exec;

/**
 * ExecTransport over the API server's WebSocket exec stream (ExecProtocol), without a kubectl
 * process: the connection, the upgrade and the frames run inside the API worker. Same contract as
 * KubectlExecTransport — stdout and stderr apart and binary-safe, both capped at $maxOutputBytes
 * (then truncated), stdin sent and closed (v5) — in acknowledged frames with $ackId
 * (ExecTransport) —, the command's exit code from the status message,
 * $onTick about every 20 ms (false stops the command: exit 130), and a local deadline of the
 * command's timeout + 15 s (exit 137, timed out) on top of the in-sandbox `timeout -s KILL`.
 *
 * Until the server switched protocols nothing runs in the pod: every failure up to then is
 * NativeExecUnsupported, and SelectingExecTransport hands the command to kubectl. The socket is
 * closed on every way out, so an Octane worker keeps no connection and is never left waiting
 * past the deadline.
 */
final class NativeExecTransport implements ExecTransport
{
    /** After the status message: how long to wait for the server's close */
    private const CLOSE_WAIT_SECONDS = 2.0;

    public function __construct(
        private readonly float $connectTimeout = 5.0,
        private readonly float $upgradeTimeout = 15.0,
        private readonly int $localGraceSeconds = 15,
    ) {}

    /**
     * @throws NativeExecUnsupported nothing started in the pod: SelectingExecTransport uses kubectl
     * @throws ExecTransportException the cluster's error, or a stream that broke
     */
    public function exec(
        PodTarget $target,
        array $argv,
        ?string $stdin = null,
        int $timeoutSeconds = 60,
        ?callable $onOutput = null,
        int $maxOutputBytes = 10485760,
        ?callable $onTick = null,
        ?string $ackId = null,
    ): ExecResult {
        $config = ExecKubeconfig::parse($target->kubeconfig);
        $started = hrtime(true);
        $sock = $this->open($config, $target, $argv, $stdin !== null, $rest);

        $stdout = '';
        $stderr = '';
        $truncated = false;
        $append = function (string $type, string $chunk) use (&$stdout, &$stderr, &$truncated, $maxOutputBytes, $onOutput) {
            if ($chunk === '') {
                return;
            }
            $current = $type === 'stdout' ? strlen($stdout) : strlen($stderr);
            $room = max(0, $maxOutputBytes - $current);
            if (strlen($chunk) > $room) {
                $chunk = substr($chunk, 0, $room);
                $truncated = true;
            }
            if ($chunk === '') {
                return;
            }
            if ($type === 'stdout') {
                $stdout .= $chunk;
            } else {
                $stderr .= $chunk;
            }
            if ($onOutput) {
                $onOutput($type, $chunk);
            }
        };

        $frames = new WebSocketFrames;
        $status = null;
        $statusAt = null;
        $closed = false;
        $timedOut = false;
        $stopped = false;
        $deadline = microtime(true) + $timeoutSeconds + $this->localGraceSeconds;
        $out = '';
        $stdinAt = 0;
        $stdinDone = $stdin === null;
        // Acknowledged input: one frame on its way at a time (ExecScript::ackedInput)
        $acked = $ackId !== null && $stdin !== null;
        $ackReady = true;
        $acks = $acked ? new MarkerFilter([ExecScript::ackMarker((string) $ackId), ExecScript::ackEndMarker((string) $ackId)]) : null;

        try {
            $messages = $frames->feed($rest);
            while (true) {
                foreach ($messages as [$opcode, $payload]) {
                    if ($opcode === WebSocketFrames::BINARY && $payload !== '') {
                        $channel = ord($payload[0]);
                        $data = (string) substr($payload, 1);
                        if ($channel === ExecProtocol::STDOUT) {
                            $append('stdout', $data);
                        } elseif ($channel === ExecProtocol::STDERR) {
                            if ($acks !== null) {
                                [$data, $found] = $acks->push($data);
                                foreach ($found as $marker) {
                                    if ($marker === ExecScript::ackMarker((string) $ackId)) {
                                        $ackReady = true;
                                    } elseif (! $stdinDone) {
                                        // The reader ended (its script failed): no more frames
                                        $out .= ExecProtocol::closeStdin();
                                        $stdinDone = true;
                                    }
                                }
                            }
                            $append('stderr', $data);
                        } elseif ($channel === ExecProtocol::STATUS && $data !== '') {
                            $status = ($status ?? '').$data;
                            $statusAt ??= microtime(true);
                        }
                    } elseif ($opcode === WebSocketFrames::PING) {
                        $out .= WebSocketFrames::encode(WebSocketFrames::PONG, $payload);
                    } elseif ($opcode === WebSocketFrames::CLOSE) {
                        $closed = true;
                    }
                }
                $messages = [];
                if ($closed || ($statusAt !== null && microtime(true) - $statusAt > self::CLOSE_WAIT_SECONDS)) {
                    break;
                }

                if ($acked && ! $stdinDone && $ackReady) {
                    // The next frame once the last was acknowledged, then "0\n" and stdin's end
                    $piece = (string) substr((string) $stdin, $stdinAt, ExecScript::ACKED_FRAME_BYTES);
                    if ($piece !== '') {
                        $out .= ExecProtocol::message(ExecProtocol::STDIN, strlen($piece)."\n".$piece);
                        $stdinAt += strlen($piece);
                        $ackReady = false;
                    } else {
                        $out .= ExecProtocol::message(ExecProtocol::STDIN, "0\n").ExecProtocol::closeStdin();
                        $stdinDone = true;
                    }
                }
                // stdin, a chunk per message, then its end (v5)
                if (! $acked && ! $stdinDone && strlen($out) < ExecProtocol::STDIN_CHUNK) {
                    $chunk = (string) substr((string) $stdin, $stdinAt, ExecProtocol::STDIN_CHUNK);
                    if ($chunk !== '') {
                        $out .= ExecProtocol::message(ExecProtocol::STDIN, $chunk);
                        $stdinAt += strlen($chunk);
                    }
                    if ($stdinAt >= strlen((string) $stdin)) {
                        $out .= ExecProtocol::closeStdin();
                        $stdinDone = true;
                    }
                }

                $read = [$sock];
                $write = $out !== '' ? [$sock] : null;
                $except = null;
                if (@stream_select($read, $write, $except, 0, 20_000) === false) {
                    throw new ExecTransportException('the exec stream failed (select)');
                }
                if ($out !== '') {
                    $n = @fwrite($sock, $out, 65536);
                    if ($n === false) {
                        throw new ExecTransportException('the exec stream was closed by the API server');
                    }
                    $out = (string) substr($out, $n);
                }
                // TLS may hold decrypted bytes the select does not see: read until empty
                while (true) {
                    $data = @fread($sock, 65536);
                    if ($data === false || $data === '') {
                        break;
                    }
                    array_push($messages, ...$frames->feed($data));
                }
                if ($messages === [] && feof($sock)) {
                    break;
                }

                if ($onTick && $onTick() === false) {
                    $stopped = true;
                    break;
                }
                if (microtime(true) > $deadline) {
                    $timedOut = true;
                    break;
                }
            }
        } finally {
            if (is_resource($sock)) {
                // Best effort: our close (or the answer to the server's) ends the stream, and with it
                // the command's stdin and output when it still runs (a stop or the deadline)
                @fwrite($sock, WebSocketFrames::encode(WebSocketFrames::CLOSE, pack('n', 1000)));
            }
            if (is_resource($sock)) {
                @fclose($sock);
            }
        }

        if ($acks !== null) {
            // What looked like the start of a marker and was not
            $append('stderr', $acks->flush());
        }
        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);
        if ($timedOut) {
            $exitCode = 137;
        } elseif ($stopped) {
            $exitCode = 130;
        } elseif ($status !== null) {
            $exitCode = ExecProtocol::exitCode($status);
        } else {
            throw new ExecTransportException('the exec stream ended before the command reported its exit status');
        }

        // The in-sandbox timeout kills with SIGKILL: exit 137 (128 + 9) at or after the limit
        if (! $timedOut && in_array($exitCode, [124, 137], true) && $durationMs >= $timeoutSeconds * 1000) {
            $timedOut = true;
        }

        return new ExecResult($exitCode, $stdout, $stderr, $timedOut, $durationMs, $truncated);
    }

    /**
     * Connects and upgrades. Returns the socket, $rest gets bytes that came after the upgrade's
     * head (the first frames).
     *
     * @param  list<string>  $argv
     *
     * @param-out string  $rest
     *
     * @return resource
     *
     * @throws NativeExecUnsupported nothing started in the pod
     */
    private function open(ExecKubeconfig $config, PodTarget $target, array $argv, bool $stdin, ?string &$rest)
    {
        $context = stream_context_create(['ssl' => $config->sslOptions()]);
        $errno = 0;
        $errstr = '';
        $sock = @stream_socket_client($config->address(), $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if (! is_resource($sock)) {
            throw new NativeExecUnsupported('cannot connect to the API server: '.($errstr ?: 'error '.$errno));
        }

        try {
            [$request, $key] = ExecProtocol::request($config, $target, $argv, $stdin);
            $deadline = microtime(true) + $this->upgradeTimeout;
            stream_set_blocking($sock, false);
            while ($request !== '') {
                $write = [$sock];
                $read = $except = null;
                if (microtime(true) > $deadline || @stream_select($read, $write, $except, 0, 50_000) === false) {
                    throw new NativeExecUnsupported('the exec upgrade timed out');
                }
                $n = @fwrite($sock, $request);
                if ($n === false) {
                    throw new NativeExecUnsupported('the API server closed the connection during the exec upgrade');
                }
                $request = (string) substr($request, $n);
            }

            $head = '';
            while (($end = strpos($head, "\r\n\r\n")) === false) {
                if (strlen($head) > 65536) {
                    throw new NativeExecUnsupported('the API server answered the exec upgrade with an oversized head');
                }
                $read = [$sock];
                $write = $except = null;
                if (microtime(true) > $deadline) {
                    throw new NativeExecUnsupported('the exec upgrade timed out');
                }
                @stream_select($read, $write, $except, 0, 50_000);
                $data = @fread($sock, 65536);
                if ($data === false || ($data === '' && feof($sock))) {
                    throw new NativeExecUnsupported('the API server closed the connection during the exec upgrade');
                }
                $head .= $data;
            }
            $rest = (string) substr($head, $end + 4);
            ExecProtocol::accept(substr($head, 0, $end), $key, $stdin, $rest);
        } catch (\Throwable $e) {
            @fclose($sock);
            throw $e;
        }

        return $sock;
    }
}
