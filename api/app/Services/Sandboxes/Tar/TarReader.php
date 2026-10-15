<?php

namespace App\Services\Sandboxes\Tar;

/**
 * Minimal tar reader (ustar, GNU long names, PAX path records) for archives that come out of a
 * sandbox — the rich outputs of a code run — and for tests.
 */
final class TarReader
{
    /**
     * @return list<array{name: string, type: string, size: int, mode: int, mtime: int, content: string}>
     *                                                                                                    type: file | dir | symlink | other
     *
     * @throws \InvalidArgumentException malformed archive
     */
    public static function entries(string $data): array
    {
        $entries = [];
        $offset = 0;
        $length = strlen($data);
        $longName = null;
        $paxPath = null;

        while ($offset + 512 <= $length) {
            $header = substr($data, $offset, 512);
            if (trim($header, "\0") === '') {
                break;
            }

            if (! self::checksumOk($header)) {
                throw new \InvalidArgumentException("tar: bad header checksum at offset {$offset}");
            }

            $name = rtrim(substr($header, 0, 100), "\0");
            $mode = (int) octdec(trim(substr($header, 100, 8), "\0 "));
            $size = (int) octdec(trim(substr($header, 124, 12), "\0 "));
            $mtime = (int) octdec(trim(substr($header, 136, 12), "\0 "));
            $type = $header[156];
            $magic = substr($header, 257, 5);
            $prefix = $magic === 'ustar' ? rtrim(substr($header, 345, 155), "\0") : '';

            $offset += 512;
            $content = substr($data, $offset, $size);
            if (strlen($content) !== $size) {
                throw new \InvalidArgumentException('tar: truncated entry '.$name);
            }
            $offset += $size + (($size % 512) === 0 ? 0 : 512 - ($size % 512));

            if ($type === 'L') {
                $longName = rtrim($content, "\0");

                continue;
            }
            if ($type === 'x') {
                $paxPath = self::paxPath($content) ?? $paxPath;

                continue;
            }
            if ($type === 'g') {
                continue;
            }

            $full = $paxPath ?? $longName ?? ($prefix !== '' ? $prefix.'/'.$name : $name);
            $longName = $paxPath = null;

            $entries[] = [
                'name' => $full,
                'type' => match ($type) {
                    '0', "\0", '7' => 'file',
                    '5' => 'dir',
                    '2' => 'symlink',
                    default => 'other',
                },
                'size' => $size,
                'mode' => $mode,
                'mtime' => $mtime,
                'content' => $content,
            ];
        }

        return $entries;
    }

    private static function checksumOk(string $header): bool
    {
        $stored = (int) octdec(trim(substr($header, 148, 8), "\0 "));
        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
        }

        return $sum === $stored;
    }

    private static function paxPath(string $records): ?string
    {
        $path = null;
        $pos = 0;
        while ($pos < strlen($records)) {
            $space = strpos($records, ' ', $pos);
            if ($space === false) {
                break;
            }
            $len = (int) substr($records, $pos, $space - $pos);
            if ($len <= 0) {
                break;
            }
            $record = substr($records, $space + 1, $len - ($space - $pos) - 2);
            if (str_starts_with($record, 'path=')) {
                $path = substr($record, 5);
            }
            $pos += $len;
        }

        return $path;
    }
}
