<?php

/**
 * A stand-in for the Kubernetes API server's exec endpoint (tests of the native exec client):
 * GET /…/api/v1/namespaces/{ns}/pods/{pod}/exec?command=…&stdin=… upgraded to WebSocket with
 * v5.channel.k8s.io or v4.channel.k8s.io, the command run locally, its stdin / stdout / stderr on
 * channels 0 / 1 / 2 and a metav1.Status on channel 3 at its end.
 *
 *   php fake-k8s-exec-server.php PORTFILE OPTIONS_JSON
 *
 * Options: tls {cert, key, ca?} (ca: client certificates are required and verified), token
 * (required bearer token), status (an HTTP status instead of the upgrade, with body), protocols
 * (supported, in order), ping (a ping before the output), fragment (output in continuation
 * frames), empty (an empty message per channel first), end ("status" default, "none": close
 * without a status, "failure": an InternalError status), log (a file: one JSON line per request
 * and per end of a connection). Each connection runs in a child process.
 */
$portFile = $argv[1];
$opt = json_decode($argv[2], true) ?: [];
$log = $opt['log'] ?? null;
$record = static function (array $line) use ($log): void {
    if ($log) {
        file_put_contents($log, json_encode($line)."\n", FILE_APPEND | LOCK_EX);
    }
};

$context = stream_context_create();
if (! empty($opt['tls'])) {
    // TLS is set up in the child, after the fork: an SSL stream the parent closed would send its
    // close_notify on the child's connection
    $ssl = ['local_cert' => $opt['tls']['cert'], 'local_pk' => $opt['tls']['key'], 'allow_self_signed' => true];
    if (! empty($opt['tls']['ca'])) {
        $ssl += ['verify_peer' => true, 'verify_peer_name' => false, 'cafile' => $opt['tls']['ca'], 'capture_peer_cert' => true];
    } else {
        $ssl += ['verify_peer' => false];
    }
    stream_context_set_option($context, ['ssl' => $ssl]);
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (! $server) {
    fwrite(STDERR, "listen: {$errstr}\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
file_put_contents($portFile, substr($name, strrpos($name, ':') + 1));

pcntl_signal(SIGCHLD, SIG_IGN);
while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (! $conn) {
        continue;
    }
    $pid = pcntl_fork();
    if ($pid === 0) {
        // The child waits for its command: exit codes need SIGCHLD back
        pcntl_signal(SIGCHLD, SIG_DFL);
        fclose($server);
        handle($conn, $opt, $record);
        exit(0);
    }
    fclose($conn);
}

function frame(int $opcode, string $payload, bool $fin = true): string
{
    $len = strlen($payload);
    $head = chr(($fin ? 0x80 : 0) | $opcode);
    if ($len < 126) {
        $head .= chr($len);
    } elseif ($len < 65536) {
        $head .= chr(126).pack('n', $len);
    } else {
        $head .= chr(127).pack('J', $len);
    }

    return $head.$payload;
}

function handle($conn, array $opt, callable $record): void
{
    if (! empty($opt['tls'])) {
        stream_set_timeout($conn, 5);
        if (! @stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER)) {
            $record(['event' => 'tls_failed', 'pid' => getmypid()]);

            return;
        }
    }
    $head = '';
    while (($end = strpos($head, "\r\n\r\n")) === false) {
        $data = fread($conn, 8192);
        if ($data === false || $data === '') {
            return;
        }
        $head .= $data;
    }
    $buffer = (string) substr($head, $end + 4);
    $lines = explode("\r\n", substr($head, 0, $end));
    [, $target] = explode(' ', $lines[0]);
    $headers = [];
    foreach (array_slice($lines, 1) as $line) {
        [$k, $v] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
        $headers[strtolower($k)] = $v;
    }
    $path = parse_url($target, PHP_URL_PATH);
    $argv = [];
    $stdin = false;
    foreach (explode('&', (string) parse_url($target, PHP_URL_QUERY)) as $pair) {
        [$k, $v] = explode('=', $pair, 2) + [1 => ''];
        if ($k === 'command') {
            $argv[] = rawurldecode($v);
        } elseif ($k === 'stdin') {
            $stdin = $v === 'true';
        }
    }
    $cn = null;
    $params = stream_context_get_params($conn)['options']['ssl']['peer_certificate'] ?? null;
    if ($params) {
        $cn = openssl_x509_parse($params)['subject']['CN'] ?? null;
    }
    $offered = array_map('trim', explode(',', $headers['sec-websocket-protocol'] ?? ''));
    $record(['event' => 'request', 'path' => $path, 'argv' => $argv, 'stdin' => $stdin, 'host' => $headers['host'] ?? null,
        'authorization' => $headers['authorization'] ?? null, 'client_cn' => $cn, 'protocols' => $offered, 'pid' => getmypid()]);

    $refuse = static function (int $code, string $body) use ($conn) {
        fwrite($conn, "HTTP/1.1 {$code} Refused\r\nContent-Type: text/plain\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
        fclose($conn);
    };
    if (isset($opt['token']) && ($headers['authorization'] ?? '') !== 'Bearer '.$opt['token']) {
        $refuse(401, 'Unauthorized');

        return;
    }
    if (isset($opt['status'])) {
        $refuse((int) $opt['status'], (string) ($opt['body'] ?? 'refused'));

        return;
    }
    $supported = $opt['protocols'] ?? ['v5.channel.k8s.io', 'v4.channel.k8s.io'];
    $protocol = null;
    foreach ($offered as $p) {
        if (in_array($p, $supported, true)) {
            $protocol = $p;
            break;
        }
    }
    if ($protocol === null) {
        $refuse(400, 'unable to upgrade: requested protocol(s) are not supported: '.implode(', ', $offered));

        return;
    }
    $accept = base64_encode(sha1(($headers['sec-websocket-key'] ?? '').'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    fwrite($conn, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\nSec-WebSocket-Protocol: {$protocol}\r\n\r\n");

    $send = static function (int $channel, string $data) use ($conn, $opt) {
        $payload = chr($channel).$data;
        if (! empty($opt['fragment']) && strlen($payload) > 3) {
            $cut = intdiv(strlen($payload), 2);
            fwrite($conn, frame(0x2, substr($payload, 0, $cut), false).frame(0x0, substr($payload, $cut)));
        } else {
            fwrite($conn, frame(0x2, $payload));
        }
    };
    if (! empty($opt['empty'])) {
        foreach ([1, 2, 3] as $channel) {
            fwrite($conn, frame(0x2, chr($channel)));
        }
    }
    if (! empty($opt['ping'])) {
        fwrite($conn, frame(0x9, 'hello'));
    }

    $proc = proc_open($argv, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (! $stdin) {
        fclose($pipes[0]);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    stream_set_blocking($conn, false);
    $open = [1 => true, 2 => true];
    $clientGone = false;
    $pong = null;
    $stdinOpen = $stdin;
    while ($open[1] || $open[2]) {
        $read = [$conn];
        foreach ([1, 2] as $fd) {
            if ($open[$fd]) {
                $read[] = $pipes[$fd];
            }
        }
        $w = $e = null;
        if (stream_select($read, $w, $e, 0, 200000) === false) {
            break;
        }
        foreach ([1, 2] as $fd) {
            if ($open[$fd] && in_array($pipes[$fd], $read, true)) {
                $data = fread($pipes[$fd], 65536);
                if ($data === '' || $data === false) {
                    if (feof($pipes[$fd])) {
                        $open[$fd] = false;
                    }
                } else {
                    $send($fd, $data);
                }
            }
        }
        $data = fread($conn, 65536);
        if ($data !== false && $data !== '') {
            $buffer .= $data;
        } elseif (feof($conn)) {
            $clientGone = true;
            break;
        }
        // client frames (masked)
        while (strlen($buffer) >= 6) {
            $op = ord($buffer[0]) & 0x0F;
            $len = ord($buffer[1]) & 0x7F;
            $off = 2;
            if ($len === 126) {
                $len = unpack('n', substr($buffer, 2, 2))[1];
                $off = 4;
            } elseif ($len === 127) {
                $len = unpack('J', substr($buffer, 2, 8))[1];
                $off = 10;
            }
            if (strlen($buffer) < $off + 4 + $len) {
                break;
            }
            $mask = substr($buffer, $off, 4);
            $payload = substr($buffer, $off + 4, $len);
            $payload = $payload === '' ? '' : $payload ^ str_repeat($mask, intdiv($len + 3, 4));
            $buffer = (string) substr($buffer, $off + 4 + $len);
            if ($op === 0x8) {
                $clientGone = true;
                break 2;
            }
            if ($op === 0xA) {
                $pong = $payload;
            } elseif ($op === 0x2 && $payload !== '') {
                $ch = ord($payload[0]);
                if ($ch === 0 && $stdinOpen) {
                    fwrite($pipes[0], substr($payload, 1));
                } elseif ($ch === 255 && $protocol === 'v5.channel.k8s.io' && ($payload[1] ?? null) === "\0" && $stdinOpen) {
                    fclose($pipes[0]);
                    $stdinOpen = false;
                }
            }
        }
    }

    if ($clientGone) {
        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc, 9);
        }
        proc_close($proc);
        $record(['event' => 'end', 'client_gone' => true, 'pong' => $pong, 'pid' => getmypid()]);
        fclose($conn);

        return;
    }
    if ($stdinOpen) {
        fclose($pipes[0]);
    }
    $code = proc_close($proc);
    $end = $opt['end'] ?? 'status';
    if ($end === 'status') {
        $status = $code === 0
            ? ['metadata' => (object) [], 'status' => 'Success']
            : ['metadata' => (object) [], 'status' => 'Failure', 'message' => "command terminated with non-zero exit code: exit code {$code}",
                'reason' => 'NonZeroExitCode', 'details' => ['causes' => [['reason' => 'ExitCode', 'message' => (string) $code]]]];
        $send(3, json_encode($status));
    } elseif ($end === 'failure') {
        $send(3, json_encode(['metadata' => (object) [], 'status' => 'Failure', 'message' => 'Internal error occurred: error executing command in container: container not running', 'reason' => 'InternalError']));
    }
    stream_set_blocking($conn, true);
    fwrite($conn, frame(0x8, pack('n', 1000)));
    stream_set_timeout($conn, 2);
    $tail = fread($conn, 65536);
    $record(['event' => 'end', 'client_gone' => false, 'exit' => $code, 'pong' => $pong, 'close_answered' => is_string($tail) && $tail !== '' && (ord($tail[0]) & 0x0F) === 0x8, 'pid' => getmypid()]);
    fclose($conn);
}
