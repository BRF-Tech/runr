<?php

namespace App\Services\Sandboxes\Exec;

/**
 * The Kubernetes exec stream over WebSocket (the protocol kubectl ≥ 1.30 speaks): an upgrade of
 * GET /api/v1/namespaces/{ns}/pods/{pod}/exec?command=…&container=…&stdin=…&stdout=1&stderr=1, then
 * binary messages whose first byte is the channel — 0 stdin, 1 stdout, 2 stderr, 3 status (a
 * metav1.Status as JSON when the command ended), 4 resize. v5.channel.k8s.io adds the close
 * signal [255, channel], the only way to end the command's stdin; v4.channel.k8s.io has none.
 */
final class ExecProtocol
{
    public const V5 = 'v5.channel.k8s.io';

    public const V4 = 'v4.channel.k8s.io';

    public const STDIN = 0;

    public const STDOUT = 1;

    public const STDERR = 2;

    public const STATUS = 3;

    public const CLOSE_SIGNAL = 255;

    /** Largest stdin payload per message */
    public const STDIN_CHUNK = 32768;

    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * The upgrade request. A command that gets stdin needs v5 (stdin must end); one without
     * stdin takes v4 as well.
     *
     * @param  list<string>  $argv
     * @return array{0: string, 1: string} request bytes, the Sec-WebSocket-Key
     */
    public static function request(ExecKubeconfig $config, PodTarget $target, array $argv, bool $stdin): array
    {
        $query = ['container='.rawurlencode($target->container)];
        foreach ($argv as $arg) {
            $query[] = 'command='.rawurlencode($arg);
        }
        $query[] = 'stdin='.($stdin ? 'true' : 'false');
        $query[] = 'stdout=true';
        $query[] = 'stderr=true';
        $path = $config->pathPrefix.'/api/v1/namespaces/'.rawurlencode($target->namespace).'/pods/'.rawurlencode($target->pod).'/exec?'.implode('&', $query);

        $key = base64_encode(random_bytes(16));
        $headers = [
            "GET {$path} HTTP/1.1",
            'Host: '.$config->hostHeader(),
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: '.$key,
            'Sec-WebSocket-Version: 13',
            'Sec-WebSocket-Protocol: '.($stdin ? self::V5 : self::V5.', '.self::V4),
            'User-Agent: runr-api/exec',
        ];
        if ($config->token !== null) {
            $headers[] = 'Authorization: Bearer '.$config->token;
        }

        return [implode("\r\n", $headers)."\r\n\r\n", $key];
    }

    /**
     * Checks the server's answer to the upgrade. Anything but a switch to an offered protocol
     * means the command has not started: NativeExecUnsupported, and kubectl runs it (and reports
     * a real error — a pod that is gone, a refused credential — in its own words).
     *
     * @return string the negotiated protocol
     *
     * @throws NativeExecUnsupported
     */
    public static function accept(string $head, string $key, bool $stdin, string $body = ''): string
    {
        $lines = explode("\r\n", $head);
        if (! preg_match('#^HTTP/1\.[01] (\d{3})#', $lines[0], $m)) {
            throw new NativeExecUnsupported('the API server did not answer the exec upgrade with HTTP', true);
        }
        $code = (int) $m[1];
        if ($code !== 101) {
            // A server (or a proxy on the way) without the WebSocket exec or v5: remembered. Other
            // refusals (404 pod gone, 401 / 403 credentials) concern this command: kubectl reports them.
            $protocol = $code === 400 || ($code === 403 && stripos($body, 'protocol') !== false) || in_array($code, [200, 426, 501], true);
            throw new NativeExecUnsupported('the API server answered the exec upgrade with HTTP '.$code, $protocol);
        }
        $headers = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        $expected = base64_encode(sha1($key.self::GUID, true));
        if (strtolower($headers['upgrade'] ?? '') !== 'websocket' || ($headers['sec-websocket-accept'] ?? '') !== $expected) {
            throw new NativeExecUnsupported('the API server did not switch to WebSocket', true);
        }
        $protocol = $headers['sec-websocket-protocol'] ?? '';
        $offered = $stdin ? [self::V5] : [self::V5, self::V4];
        if (! in_array($protocol, $offered, true)) {
            // Switched without one of ours: the command may run already — not a case for kubectl
            throw new ExecTransportException('the API server switched the exec stream to an unknown protocol ('.($protocol ?: 'none').')');
        }

        return $protocol;
    }

    /** A message on a channel. */
    public static function message(int $channel, string $data): string
    {
        return WebSocketFrames::encode(WebSocketFrames::BINARY, chr($channel).$data);
    }

    /** v5: the end of the command's stdin. */
    public static function closeStdin(): string
    {
        return WebSocketFrames::encode(WebSocketFrames::BINARY, chr(self::CLOSE_SIGNAL).chr(self::STDIN));
    }

    /**
     * The command's exit code from the status message: 0 for Success, the ExitCode cause of
     * NonZeroExitCode. Any other failure is the cluster's (the exec could not run):
     * ExecTransportException with its message, as kubectl reports it.
     *
     * @throws ExecTransportException
     */
    public static function exitCode(string $status): int
    {
        $s = json_decode($status, true);
        if (! is_array($s)) {
            throw new ExecTransportException('error: '.trim($status));
        }
        if (($s['status'] ?? null) === 'Success') {
            return 0;
        }
        if (($s['reason'] ?? null) === 'NonZeroExitCode') {
            foreach ($s['details']['causes'] ?? [] as $cause) {
                if (($cause['reason'] ?? null) === 'ExitCode' && is_numeric($cause['message'] ?? null)) {
                    return (int) $cause['message'];
                }
            }
        }

        throw new ExecTransportException('error: '.trim((string) ($s['message'] ?? 'the exec failed')));
    }
}
