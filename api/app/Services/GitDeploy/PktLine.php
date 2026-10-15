<?php

namespace App\Services\GitDeploy;

/** git pkt-line framing (4 hex digits of length incl. themselves, then the payload). */
final class PktLine
{
    public const FLUSH = '0000';

    public static function encode(string $payload): string
    {
        return sprintf('%04x', strlen($payload) + 4).$payload;
    }

    /**
     * A ref advertisement that only carries an error: git prints "remote error: <message>"
     * (used for authenticated requests that may not read or write the repository).
     */
    public static function advertisementError(string $service, string $message): string
    {
        return self::encode("# service={$service}\n").self::FLUSH.self::encode('ERR '.$message."\n");
    }

    /**
     * Decode pkt-lines from the start of $data until the first flush packet.
     *
     * @return list<string>
     */
    public static function decodeUntilFlush(string $data): array
    {
        $lines = [];
        $offset = 0;

        while ($offset + 4 <= strlen($data)) {
            $len = hexdec(substr($data, $offset, 4));
            if ($len === 0) {
                break;
            }
            if ($len < 4) {
                break;
            }
            $lines[] = substr($data, $offset + 4, $len - 4);
            $offset += $len;
        }

        return $lines;
    }
}
