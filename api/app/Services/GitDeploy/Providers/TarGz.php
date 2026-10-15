<?php

namespace App\Services\GitDeploy\Providers;

use App\Services\GitDeploy\ImportException;
use App\Services\GitDeploy\SourceTree;

/**
 * Reads a source archive (.tar.gz) of a provider (GitHub tarball, GitLab / Forgejo archive)
 * into path → content without touching the disk. The archive is inflated and parsed in chunks;
 * only entries $keep accepts are held in memory (the root directory of a monorepo, without
 * node_modules and the like), so a large repository costs no more than the files that matter.
 * ustar names with prefix, pax extended headers (path=) and GNU long names are honoured; the
 * single top-level directory every provider puts around the tree is stripped. Directories,
 * links and devices are left out; symlinks are reported as skipped.
 */
final class TarGz
{
    /**
     * @param  (callable(string): bool)|null  $keep  which paths (relative to the archive's top directory) to hold
     * @return array{files: array<string, string>, skipped: list<array{path: string, reason: string}>}
     *
     * @throws ImportException not a tar(.gz), or the kept files are far above the code limit
     */
    public static function extract(string $archive, ?callable $keep = null, ?int $maxBytes = null): array
    {
        $maxBytes ??= SourceTree::MAX_CODE_BYTES * 8;
        $keep ??= fn (string $path) => true;

        $gzip = str_starts_with($archive, "\x1f\x8b");
        $inflater = $gzip ? inflate_init(ZLIB_ENCODING_GZIP) : null;
        if ($gzip && $inflater === false) {
            throw new ImportException('The source archive cannot be decompressed');
        }

        $buffer = '';
        $files = [];
        $skipped = [];
        $kept = 0;
        $longName = null;
        $paxPath = null;
        $entry = null;   // the entry being read: path, size, type, data, remaining, pad, hold
        $done = false;
        $position = 0;
        $chunk = 65536;
        $total = strlen($archive);

        while (! $done) {
            // Feed the parser
            if ($position < $total) {
                $piece = substr($archive, $position, $chunk);
                $position += strlen($piece);
                $out = $gzip ? @inflate_add($inflater, $piece, $position >= $total ? ZLIB_FINISH : ZLIB_SYNC_FLUSH) : $piece;
                if ($out === false) {
                    throw new ImportException('The source archive is not a gzip-compressed tar file');
                }
                $buffer .= $out;
            }

            while (true) {
                if ($entry !== null) {
                    // Body of the current entry …
                    if ($entry['remaining'] > 0) {
                        $take = min($entry['remaining'], strlen($buffer));
                        if ($entry['hold']) {
                            $entry['data'] .= substr($buffer, 0, $take);
                        }
                        $buffer = substr($buffer, $take);
                        $entry['remaining'] -= $take;
                        if ($entry['remaining'] > 0) {
                            break;
                        }
                    }
                    // … and its padding to the next 512-byte block
                    if ($entry['pad'] > 0) {
                        $skip = min($entry['pad'], strlen($buffer));
                        $buffer = substr($buffer, $skip);
                        $entry['pad'] -= $skip;
                        if ($entry['pad'] > 0) {
                            break;
                        }
                    }

                    [$longName, $paxPath, $kept] = self::finish($entry, $files, $skipped, $longName, $paxPath, $kept, $maxBytes);
                    $entry = null;

                    continue;
                }

                if (strlen($buffer) < 512) {
                    break;
                }

                $header = substr($buffer, 0, 512);
                $buffer = substr($buffer, 512);

                if (trim($header, "\0") === '') {
                    $done = true; // end of archive
                    break;
                }

                if (! self::validChecksum($header)) {
                    throw new ImportException('The source archive is not a valid tar file');
                }

                $name = rtrim(substr($header, 0, 100), "\0");
                $size = (int) octdec(trim(substr($header, 124, 12), " \0"));
                $type = $header[156];
                $prefix = str_starts_with(substr($header, 257, 6), 'ustar') ? rtrim(substr($header, 345, 155), "\0") : '';
                $path = in_array($type, ['x', 'g', 'L'], true)
                    ? null
                    : self::stripTopLevel($paxPath ?? $longName ?? ($prefix !== '' ? "{$prefix}/{$name}" : $name));
                $file = $type === '0' || $type === "\0" || $type === '7';

                $entry = [
                    'path' => $path,
                    'size' => $size,
                    'type' => $type,
                    'data' => '',
                    'remaining' => $size,
                    'pad' => (512 - $size % 512) % 512,
                    'hold' => in_array($type, ['x', 'L'], true) || ($file && $path !== null && $keep($path)),
                ];
            }

            // Everything fed was consumed as far as it goes: without more input, stop
            // (a truncated archive keeps what was complete)
            if ($position >= $total) {
                break;
            }
        }

        return ['files' => $files, 'skipped' => $skipped];
    }

    /**
     * Build a .tar.gz from path → content (tests and fixtures; ustar, one top-level directory).
     *
     * @param  array<string, string>  $files
     */
    public static function create(array $files, string $top = 'repo-0000000/'): string
    {
        $tar = '';
        foreach ($files as $path => $content) {
            $name = $top.$path;
            $header = str_pad(substr($name, 0, 100), 100, "\0")
                ."0000644\0"."0000000\0"."0000000\0"
                .str_pad(decoct(strlen($content)), 11, '0', STR_PAD_LEFT)."\0"
                .str_pad(decoct(1760000000), 11, '0', STR_PAD_LEFT)."\0"
                .'        '
                .'0'
                .str_repeat("\0", 100)
                ."ustar\0".'00'
                .str_repeat("\0", 32).str_repeat("\0", 32)
                .str_repeat("\0", 8).str_repeat("\0", 8)
                .str_repeat("\0", 155);
            $header = str_pad($header, 512, "\0");
            $checksum = array_sum(array_map('ord', str_split($header)));
            $header = substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);

            $tar .= $header.$content.str_repeat("\0", (512 - strlen($content) % 512) % 512);
        }
        $tar .= str_repeat("\0", 1024);

        return gzencode($tar);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: int} long name, pax path (for the next entry), kept bytes
     */
    private static function finish(array $entry, array &$files, array &$skipped, ?string $longName, ?string $paxPath, int $kept, int $maxBytes): array
    {
        $type = $entry['type'];

        if ($type === 'x') {
            return [$longName, self::paxValue($entry['data'], 'path') ?? $paxPath, $kept];
        }
        if ($type === 'g') {
            return [$longName, $paxPath, $kept];
        }
        if ($type === 'L') {
            return [rtrim($entry['data'], "\0"), $paxPath, $kept];
        }

        $path = $entry['path'];
        if ($path === null) {
            return [null, null, $kept];
        }

        if ($type === '2') {
            $skipped[] = ['path' => $path, 'reason' => 'symlink'];
        } elseif ($entry['hold']) {
            if (str_contains($path, '../') || str_starts_with($path, '/')) {
                $skipped[] = ['path' => $path, 'reason' => 'unsafe path'];
            } else {
                $kept += $entry['size'];
                if ($kept > $maxBytes) {
                    throw new ImportException(sprintf('The source holds more than %d bytes of code files; a version holds at most %d', $maxBytes, SourceTree::MAX_CODE_BYTES));
                }
                $files[$path] = $entry['data'];
            }
        }

        return [null, null, $kept];
    }

    private static function validChecksum(string $header): bool
    {
        $stored = trim(substr($header, 148, 8), " \0");
        if ($stored === '' || ! ctype_digit(strtr($stored, '01234567', '00000000'))) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($header[$i]);
        }

        return $sum === octdec($stored);
    }

    private static function stripTopLevel(string $path): ?string
    {
        $path = preg_replace('#^\./#', '', $path) ?? $path;
        $pos = strpos($path, '/');

        if ($pos === false) {
            return null; // the top-level directory itself (or a stray root file)
        }

        $rest = substr($path, $pos + 1);

        return $rest === '' ? null : $rest;
    }

    private static function paxValue(string $data, string $key): ?string
    {
        // "<len> <key>=<value>\n" records
        $offset = 0;
        while ($offset < strlen($data)) {
            $space = strpos($data, ' ', $offset);
            if ($space === false) {
                break;
            }
            $len = (int) substr($data, $offset, $space - $offset);
            if ($len <= 0) {
                break;
            }
            $record = substr($data, $space + 1, $len - ($space - $offset) - 2);
            $offset += $len;

            if (str_starts_with($record, $key.'=')) {
                return substr($record, strlen($key) + 1);
            }
        }

        return null;
    }
}
