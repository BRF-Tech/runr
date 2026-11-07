<?php

namespace App\Services\Sandboxes\Exec;

/**
 * RFC 6455 frames for the exec client: encodes masked client frames and parses the server's
 * byte stream incrementally (frames may arrive split over reads or several in one). Fragmented
 * data messages are joined; control frames (ping, pong, close) are returned as they come.
 */
final class WebSocketFrames
{
    public const CONTINUATION = 0x0;

    public const TEXT = 0x1;

    public const BINARY = 0x2;

    public const CLOSE = 0x8;

    public const PING = 0x9;

    public const PONG = 0xA;

    /** Largest message accepted from the server (a frame of the exec stream is at most 32 KiB). */
    public const MAX_MESSAGE = 16 * 1024 * 1024;

    private string $buffer = '';

    private ?int $fragmentOpcode = null;

    private string $fragments = '';

    /** A masked frame from the client (every client frame must be masked). */
    public static function encode(int $opcode, string $payload): string
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($len < 126) {
            $head .= chr(0x80 | $len);
        } elseif ($len < 65536) {
            $head .= chr(0x80 | 126).pack('n', $len);
        } else {
            $head .= chr(0x80 | 127).pack('J', $len);
        }
        $mask = random_bytes(4);

        return $head.$mask.($payload === '' ? '' : $payload ^ str_repeat($mask, intdiv($len + 3, 4)));
    }

    /**
     * Feeds bytes from the server; returns the complete messages: [opcode, payload] with the
     * opcode of the first fragment for a fragmented message.
     *
     * @return list<array{0: int, 1: string}>
     *
     * @throws ExecTransportException on a frame no server may send (masked, oversized)
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $out = [];
        while (true) {
            $have = strlen($this->buffer);
            if ($have < 2) {
                break;
            }
            $b0 = ord($this->buffer[0]);
            $b1 = ord($this->buffer[1]);
            $fin = ($b0 & 0x80) !== 0;
            $opcode = $b0 & 0x0F;
            $masked = ($b1 & 0x80) !== 0;
            $len = $b1 & 0x7F;
            $offset = 2;
            if ($len === 126) {
                if ($have < 4) {
                    break;
                }
                $len = unpack('n', substr($this->buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($len === 127) {
                if ($have < 10) {
                    break;
                }
                $len = unpack('J', substr($this->buffer, 2, 8))[1];
                $offset = 10;
            }
            if ($masked) {
                throw new ExecTransportException('the API server sent a masked WebSocket frame');
            }
            if ($len > self::MAX_MESSAGE) {
                throw new ExecTransportException('the API server sent an oversized WebSocket frame');
            }
            if ($have < $offset + $len) {
                break;
            }
            $payload = (string) substr($this->buffer, $offset, $len);
            $this->buffer = (string) substr($this->buffer, $offset + $len);

            if ($opcode >= 0x8) {
                $out[] = [$opcode, $payload];

                continue;
            }
            if ($opcode !== self::CONTINUATION) {
                $this->fragmentOpcode = $opcode;
                $this->fragments = '';
            } elseif ($this->fragmentOpcode === null) {
                throw new ExecTransportException('the API server sent a continuation frame without a message');
            }
            $this->fragments .= $payload;
            if (strlen($this->fragments) > self::MAX_MESSAGE) {
                throw new ExecTransportException('the API server sent an oversized WebSocket message');
            }
            if ($fin) {
                $out[] = [$this->fragmentOpcode, $this->fragments];
                $this->fragmentOpcode = null;
                $this->fragments = '';
            }
        }

        return $out;
    }
}
