<?php

/**
 * kube-pin-proxy — the local proxy kubectl and helm reach an organization's region through
 * (App\Services\Kubeconfigs\PinProxy, KubeconfigFile; docs/security.md, "Tenant region kubeconfigs").
 *
 *   php bin/kube-pin-proxy.php HOST:PORT=ADDRESS[,ADDRESS…] [HOST:PORT=…]
 *
 * Listens on 127.0.0.1 on a free port and writes that port on a line to stdout. A client sends
 * "CONNECT host:port HTTP/1.1" (the kubeconfig's proxy-url is http://127.0.0.1:<port>): a tunnel
 * opens only for a host:port of the arguments, to its addresses — the first that accepts, in
 * order; any other target, and any other request, is answered 403. kubectl and helm send every
 * request of the kubeconfig through the proxy, a redirect of the API server included, so they reach
 * the checked addresses of the server and nothing else, whatever its name resolves to meanwhile.
 * TLS stays end to end (the tunnel carries bytes). Ends when its stdin closes: the process that
 * started it stopped it, or ended.
 *
 * RUNR_PIN_PROXY_UPSTREAM (http://[user:password@]host:port or https://…, set by PinProxy from the
 * API's HTTPS_PROXY / NO_PROXY): a tunnel goes through that proxy as a CONNECT to the checked
 * address — never to the name, so the proxy cannot resolve it again — with Basic credentials of
 * the URL. Without it, a tunnel dials the address itself.
 *
 * No framework: it starts with every kubectl / helm call of such a region.
 */
const HEAD_LIMIT = 16384;
const BUFFER_LIMIT = 1048576;
const CONNECT_TIMEOUT = 10;

$upstream = null;
$upstreamUrl = getenv('RUNR_PIN_PROXY_UPSTREAM');
if (is_string($upstreamUrl) && $upstreamUrl !== '') {
    $parts = parse_url($upstreamUrl);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($parts === false || empty($parts['host']) || ! in_array($scheme, ['http', 'https'], true)) {
        fwrite(STDERR, "kube-pin-proxy: RUNR_PIN_PROXY_UPSTREAM is not an http(s) proxy URL\n");
        exit(2);
    }
    $upstream = [
        'address' => ($scheme === 'https' ? 'tls://' : 'tcp://').(str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host']).':'.(int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)),
        'auth' => isset($parts['user']) ? 'Basic '.base64_encode(rawurldecode($parts['user']).':'.rawurldecode($parts['pass'] ?? '')) : null,
    ];
}

$targets = [];
foreach (array_slice($argv, 1) as $arg) {
    if (! preg_match('/^(\[[0-9a-fA-F:.]+\]|[A-Za-z0-9._-]+):(\d{1,5})=([0-9a-fA-F:.,]+)$/D', $arg, $m)) {
        fwrite(STDERR, "kube-pin-proxy: not HOST:PORT=ADDRESS[,ADDRESS]: {$arg}\n");
        exit(2);
    }
    $addresses = explode(',', $m[3]);
    foreach ($addresses as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            fwrite(STDERR, "kube-pin-proxy: not an address: {$ip}\n");
            exit(2);
        }
    }
    $targets[strtolower(trim($m[1], '[]')).':'.(int) $m[2]] = $addresses;
}
if ($targets === []) {
    fwrite(STDERR, "kube-pin-proxy: no target\n");
    exit(2);
}

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "kube-pin-proxy: cannot listen: {$errstr}\n");
    exit(1);
}
stream_set_blocking($server, false);
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, (int) strrpos($name, ':') + 1)."\n");
fflush(STDOUT);
stream_set_blocking(STDIN, false);

/**
 * Tunnels by id: the client socket, the upstream socket once connected, the request head read so
 * far, what waits to be written to each side, and whether the tunnel closes once that is written.
 *
 * @var array<int, array{client: resource, upstream: resource|null, head: string, toClient: string, toUpstream: string, closing: bool}> $tunnels
 */
$tunnels = [];
/** @var array<int, array{int, string}> $sides socket resource id => [tunnel id, "client"|"upstream"] */
$sides = [];
$nextId = 0;

/** The tunnel's target if a CONNECT to one of $targets, else null. */
$target = static function (string $head) use ($targets): ?array {
    $line = strtok($head, "\r\n");
    if (! is_string($line) || ! preg_match('/^CONNECT (\[[0-9a-fA-F:.]+\]|[A-Za-z0-9._-]+):(\d{1,5}) HTTP\/1\.[01]$/D', $line, $m)) {
        return null;
    }
    $key = strtolower(rtrim(trim($m[1], '[]'), '.')).':'.(int) $m[2];

    return isset($targets[$key]) ? [$targets[$key], (int) $m[2]] : null;
};

/**
 * The upstream socket of a tunnel to the first checked address that accepts it — through the
 * upstream proxy when there is one — and the bytes that came after the proxy's answer.
 *
 * @return array{resource, string}|null
 */
$open = static function (array $addresses, int $port) use ($upstream): ?array {
    foreach ($addresses as $ip) {
        $authority = (str_contains($ip, ':') ? '['.$ip.']' : $ip).':'.$port;
        if ($upstream === null) {
            $socket = @stream_socket_client("tcp://{$authority}", $errno, $errstr, CONNECT_TIMEOUT);
            if ($socket !== false) {
                stream_set_blocking($socket, false);

                return [$socket, ''];
            }

            continue;
        }

        $socket = @stream_socket_client($upstream['address'], $errno, $errstr, CONNECT_TIMEOUT);
        if ($socket === false) {
            continue;
        }
        stream_set_timeout($socket, CONNECT_TIMEOUT);
        $request = "CONNECT {$authority} HTTP/1.1\r\nHost: {$authority}\r\n";
        if ($upstream['auth'] !== null) {
            $request .= "Proxy-Authorization: {$upstream['auth']}\r\n";
        }
        @fwrite($socket, $request."\r\n");
        $head = '';
        while (! str_contains($head, "\r\n\r\n") && strlen($head) < HEAD_LIMIT) {
            $chunk = @fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $head .= $chunk;
        }
        $end = strpos($head, "\r\n\r\n");
        if ($end === false || ! preg_match('#^HTTP/1\.[01] 200#', $head)) {
            @fclose($socket);

            continue;
        }
        stream_set_blocking($socket, false);

        return [$socket, (string) substr($head, $end + 4)];
    }

    return null;
};

while (true) {
    $read = [$server, STDIN];
    $write = [];
    foreach ($tunnels as $tunnel) {
        if (! $tunnel['closing'] && strlen($tunnel['toUpstream']) < BUFFER_LIMIT) {
            $read[] = $tunnel['client'];
        }
        if ($tunnel['upstream'] !== null && ! $tunnel['closing'] && strlen($tunnel['toClient']) < BUFFER_LIMIT) {
            $read[] = $tunnel['upstream'];
        }
        if ($tunnel['toClient'] !== '') {
            $write[] = $tunnel['client'];
        }
        if ($tunnel['upstream'] !== null && $tunnel['toUpstream'] !== '') {
            $write[] = $tunnel['upstream'];
        }
    }
    $except = null;
    if (@stream_select($read, $write, $except, 30) === false) {
        exit(1);
    }

    foreach ($read as $stream) {
        if ($stream === STDIN) {
            $data = @fread(STDIN, 8192);
            if (($data === '' || $data === false) && feof(STDIN)) {
                exit(0);
            }

            continue;
        }
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 0);
            if ($client !== false) {
                stream_set_blocking($client, false);
                $id = $nextId++;
                $tunnels[$id] = ['client' => $client, 'upstream' => null, 'head' => '', 'toClient' => '', 'toUpstream' => '', 'closing' => false];
                $sides[get_resource_id($client)] = [$id, 'client'];
            }

            continue;
        }

        [$id, $side] = $sides[get_resource_id($stream)] ?? [null, null];
        if ($id === null || ! isset($tunnels[$id])) {
            continue;
        }
        $data = @fread($stream, 65536);
        if ($data === false || ($data === '' && feof($stream))) {
            $tunnels[$id]['closing'] = true;

            continue;
        }
        if ($data === '') {
            continue;
        }

        if ($side === 'upstream') {
            $tunnels[$id]['toClient'] .= $data;
        } elseif ($tunnels[$id]['upstream'] !== null) {
            $tunnels[$id]['toUpstream'] .= $data;
        } else {
            $tunnels[$id]['head'] .= $data;
            $end = strpos($tunnels[$id]['head'], "\r\n\r\n");
            if ($end === false) {
                if (strlen($tunnels[$id]['head']) > HEAD_LIMIT) {
                    $tunnels[$id]['toClient'] = "HTTP/1.1 431 Request Header Fields Too Large\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                    $tunnels[$id]['closing'] = true;
                }

                continue;
            }
            $found = $target(substr($tunnels[$id]['head'], 0, $end));
            $opened = $found === null ? null : $open($found[0], $found[1]);
            if ($opened === null) {
                $tunnels[$id]['toClient'] = $found === null
                    ? "HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\nConnection: close\r\n\r\n"
                    : "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
                $tunnels[$id]['closing'] = true;

                continue;
            }
            [$socket, $early] = $opened;
            $tunnels[$id]['upstream'] = $socket;
            $sides[get_resource_id($socket)] = [$id, 'upstream'];
            $tunnels[$id]['toClient'] = "HTTP/1.1 200 Connection established\r\n\r\n".$early;
            $tunnels[$id]['toUpstream'] = (string) substr($tunnels[$id]['head'], $end + 4);
            $tunnels[$id]['head'] = '';
        }
    }

    foreach ($write as $stream) {
        [$id, $side] = $sides[get_resource_id($stream)] ?? [null, null];
        if ($id === null || ! isset($tunnels[$id])) {
            continue;
        }
        $buffer = $side === 'client' ? 'toClient' : 'toUpstream';
        $written = @fwrite($stream, $tunnels[$id][$buffer]);
        if ($written === false) {
            $tunnels[$id][$buffer] = '';
            $tunnels[$id]['closing'] = true;

            continue;
        }
        $tunnels[$id][$buffer] = (string) substr($tunnels[$id][$buffer], $written);
    }

    // A closing tunnel goes once what it still has to write is written
    foreach ($tunnels as $id => $tunnel) {
        if ($tunnel['closing'] && $tunnel['toClient'] === '' && ($tunnel['upstream'] === null || $tunnel['toUpstream'] === '')) {
            foreach ([$tunnel['client'], $tunnel['upstream']] as $socket) {
                if ($socket !== null) {
                    unset($sides[get_resource_id($socket)]);
                    @fclose($socket);
                }
            }
            unset($tunnels[$id]);
        }
    }
}
