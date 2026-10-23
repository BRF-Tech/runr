<?php

namespace App\Services\GlobalDns;

/**
 * A minimal DNS client that asks one given server (PHP's dns_get_record() always asks the system
 * resolver): whether a region's server answers the global zone on its public address, and what it
 * answers. One question, UDP (TCP on request or when the answer is truncated), no recursion
 * desired, no EDNS. Resolved from the container, so tests bind a fake.
 *
 *   app(DnsWire::class)->query('203.0.113.53', 'g.example.com', 'SOA')
 *     → ['rcode' => 0, 'authoritative' => true, 'answers' => [['name' => …, 'type' => 'SOA', 'ttl' => …, 'data' => …]]]
 *     | null (no answer: timeout, unreachable, malformed)
 */
class DnsWire
{
    private const TYPES = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'SOA' => 6, 'TXT' => 16, 'AAAA' => 28];

    /**
     * @return array{rcode: int, authoritative: bool, answers: list<array{name: string, type: string, ttl: int, data: string}>}|null
     */
    public function query(string $server, string $name, string $type = 'A', bool $tcp = false, int $port = 53, float $timeout = 3.0): ?array
    {
        $qtype = self::TYPES[strtoupper($type)] ?? null;
        if ($qtype === null) {
            return null;
        }

        $id = random_int(0, 0xFFFF);
        $packet = self::encodeQuery($id, $name, $qtype);

        $response = $tcp ? $this->exchangeTcp($server, $port, $packet, $timeout) : $this->exchangeUdp($server, $port, $packet, $timeout);
        if ($response === null) {
            return null;
        }

        $parsed = self::decodeResponse($response, $id);
        if ($parsed !== null && $parsed['truncated'] && ! $tcp) {
            return $this->query($server, $name, $type, true, $port, $timeout);
        }

        if ($parsed === null) {
            return null;
        }
        unset($parsed['truncated']);

        return $parsed;
    }

    /** The query packet: header (RD off) and one question of class IN. */
    public static function encodeQuery(int $id, string $name, int $qtype): string
    {
        $header = pack('nnnnnn', $id, 0x0000, 1, 0, 0, 0);
        $qname = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            if ($label === '') {
                continue;
            }
            $qname .= chr(strlen($label)).$label;
        }

        return $header.$qname."\0".pack('nn', $qtype, 1);
    }

    /**
     * @return array{rcode: int, authoritative: bool, truncated: bool, answers: list<array{name: string, type: string, ttl: int, data: string}>}|null
     */
    public static function decodeResponse(string $packet, ?int $expectedId = null): ?array
    {
        if (strlen($packet) < 12) {
            return null;
        }

        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($packet, 0, 12));
        if ($header === false || ($expectedId !== null && $header['id'] !== $expectedId) || ($header['flags'] & 0x8000) === 0) {
            return null;
        }

        $offset = 12;
        for ($i = 0; $i < $header['qd']; $i++) {
            if (self::readName($packet, $offset) === null) {
                return null;
            }
            $offset += 4;
        }

        $names = array_flip(self::TYPES);
        $answers = [];
        for ($i = 0; $i < $header['an']; $i++) {
            $owner = self::readName($packet, $offset);
            if ($owner === null || strlen($packet) < $offset + 10) {
                return null;
            }
            $rr = unpack('ntype/nclass/Nttl/nlength', substr($packet, $offset, 10));
            $offset += 10;
            $rdataOffset = $offset;
            $offset += $rr['length'];
            if (strlen($packet) < $offset) {
                return null;
            }

            $type = $names[$rr['type']] ?? (string) $rr['type'];
            $answers[] = [
                'name' => $owner,
                'type' => $type,
                'ttl' => $rr['ttl'],
                'data' => self::readData($packet, $rdataOffset, $rr['length'], $type),
            ];
        }

        return [
            'rcode' => $header['flags'] & 0x000F,
            'authoritative' => ($header['flags'] & 0x0400) !== 0,
            'truncated' => ($header['flags'] & 0x0200) !== 0,
            'answers' => $answers,
        ];
    }

    private static function readData(string $packet, int $offset, int $length, string $type): string
    {
        $rdata = substr($packet, $offset, $length);

        switch ($type) {
            case 'A':
            case 'AAAA':
                $ip = @inet_ntop($rdata);

                return $ip === false ? '' : $ip;
            case 'NS':
            case 'CNAME':
                $at = $offset;

                return (string) self::readName($packet, $at);
            case 'SOA':
                $at = $offset;
                $mname = (string) self::readName($packet, $at);
                $rname = (string) self::readName($packet, $at);
                $serial = strlen($packet) >= $at + 4 ? unpack('N', substr($packet, $at, 4))[1] : 0;

                return "{$mname} {$rname} {$serial}";
            case 'TXT':
                $strings = [];
                $at = 0;
                while ($at < strlen($rdata)) {
                    $len = ord($rdata[$at]);
                    $strings[] = substr($rdata, $at + 1, $len);
                    $at += $len + 1;
                }

                return implode('', $strings);
            default:
                return bin2hex($rdata);
        }
    }

    /** A (possibly compressed) name at $offset; $offset moves past it. Null when malformed. */
    private static function readName(string $packet, int &$offset): ?string
    {
        $labels = [];
        $jumped = false;
        $at = $offset;
        $hops = 0;

        while (true) {
            if ($at >= strlen($packet) || $hops++ > 64) {
                return null;
            }
            $len = ord($packet[$at]);
            if ($len === 0) {
                $at++;
                break;
            }
            if (($len & 0xC0) === 0xC0) {
                if ($at + 1 >= strlen($packet)) {
                    return null;
                }
                $pointer = (($len & 0x3F) << 8) | ord($packet[$at + 1]);
                if (! $jumped) {
                    $offset = $at + 2;
                }
                $jumped = true;
                $at = $pointer;

                continue;
            }
            $labels[] = strtolower(substr($packet, $at + 1, $len));
            $at += $len + 1;
        }

        if (! $jumped) {
            $offset = $at;
        }

        return implode('.', $labels);
    }

    private function exchangeUdp(string $server, int $port, string $packet, float $timeout): ?string
    {
        $socket = @stream_socket_client('udp://'.self::hostPort($server, $port), $errno, $error, $timeout);
        if ($socket === false) {
            return null;
        }

        try {
            stream_set_timeout($socket, (int) $timeout, (int) (($timeout - floor($timeout)) * 1e6));
            if (@fwrite($socket, $packet) === false) {
                return null;
            }
            $response = @fread($socket, 65535);

            return is_string($response) && $response !== '' ? $response : null;
        } finally {
            fclose($socket);
        }
    }

    private function exchangeTcp(string $server, int $port, string $packet, float $timeout): ?string
    {
        $socket = @stream_socket_client('tcp://'.self::hostPort($server, $port), $errno, $error, $timeout);
        if ($socket === false) {
            return null;
        }

        try {
            stream_set_timeout($socket, (int) $timeout, (int) (($timeout - floor($timeout)) * 1e6));
            if (@fwrite($socket, pack('n', strlen($packet)).$packet) === false) {
                return null;
            }
            $prefix = self::readExactly($socket, 2);
            if ($prefix === null) {
                return null;
            }

            return self::readExactly($socket, unpack('n', $prefix)[1]);
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private static function readExactly($socket, int $length): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $chunk = @fread($socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') {
                return null;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private static function hostPort(string $server, int $port): string
    {
        return (str_contains($server, ':') ? "[{$server}]" : $server).":{$port}";
    }
}
