<?php

namespace App\Services\OrgTransfer\Package;

/**
 * Reads a tar entry by entry from a stream that need not be seekable (a zstd decompression
 * pipe): next() moves to the next entry, skipping what was not read of the current one. Regular
 * files only — any other entry type (links, devices, directories) is refused, PAX extended
 * headers ("x") and GNU long names ("L") are applied to the following entry. A reader of a code
 * archive (git archive writes a directory entry before its files) passes $skipDirectories.
 */
final class TarReader
{
    private const BLOCK = 512;

    /** Bytes of the current entry not read yet, plus its padding. */
    private int $remaining = 0;

    private int $padding = 0;

    /** @param resource $stream */
    public function __construct(private $stream, private bool $skipDirectories = false) {}

    /**
     * The next regular file entry, null at the end of the archive.
     *
     * @return array{name: string, size: int}|null
     */
    public function next(): ?array
    {
        $this->skipRest();

        $pax = [];
        $longName = null;
        while (true) {
            $block = $this->readExact(self::BLOCK, true);
            if ($block === null || trim($block, "\0") === '') {
                return null;
            }

            $name = rtrim(substr($block, 0, 100), "\0");
            $size = self::number(substr($block, 124, 12));
            $type = $block[156];
            $magic = substr($block, 257, 5);
            if ($magic === 'ustar') {
                $prefix = rtrim(substr($block, 345, 155), "\0");
                if ($prefix !== '') {
                    $name = $prefix.'/'.$name;
                }
            }
            $this->verifyChecksum($block);

            if ($type === 'x' || $type === 'L' || $type === 'g') {
                $data = $this->readExact($size) ?? '';
                $this->skip((self::BLOCK - $size % self::BLOCK) % self::BLOCK);
                if ($type === 'x') {
                    $pax = self::parsePax($data);
                } elseif ($type === 'L') {
                    $longName = rtrim($data, "\0");
                }

                continue;
            }

            if ($type === '5' && $this->skipDirectories) {
                $this->skip($size + (self::BLOCK - $size % self::BLOCK) % self::BLOCK);
                $pax = [];
                $longName = null;

                continue;
            }

            if ($type !== '0' && $type !== "\0") {
                throw new PackageException(__('org_transfer.package.bad_entry', ['name' => mb_substr($name, 0, 100)]), 'package_invalid');
            }

            $name = $pax['path'] ?? $longName ?? $name;
            if (isset($pax['size']) && ctype_digit($pax['size'])) {
                $size = (int) $pax['size'];
            }

            $this->remaining = $size;
            $this->padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;

            return ['name' => $name, 'size' => $size];
        }
    }

    /**
     * Copy the rest of the current entry to $out; returns its SHA-256.
     *
     * @param  resource  $out
     */
    public function copyTo($out): string
    {
        $hash = hash_init('sha256');
        while ($this->remaining > 0) {
            $chunk = $this->readExact(min(1024 * 1024, $this->remaining));
            if ($chunk === null) {
                throw new PackageException(__('org_transfer.package.truncated'), 'package_invalid');
            }
            $this->remaining -= strlen($chunk);
            hash_update($hash, $chunk);
            if (fwrite($out, $chunk) !== strlen($chunk)) {
                throw new PackageException(__('org_transfer.package.extract_failed'), 'package_extract_failed');
            }
        }

        return hash_final($hash);
    }

    /** The rest of the current entry, at most $max bytes (larger entries are refused). */
    public function contents(int $max): string
    {
        if ($this->remaining > $max) {
            throw new PackageException(__('org_transfer.package.entry_too_large'), 'package_invalid');
        }
        $data = $this->remaining > 0 ? ($this->readExact($this->remaining) ?? '') : '';
        if (strlen($data) !== $this->remaining) {
            throw new PackageException(__('org_transfer.package.truncated'), 'package_invalid');
        }
        $this->remaining = 0;

        return $data;
    }

    private function skipRest(): void
    {
        $this->skip($this->remaining + $this->padding);
        $this->remaining = 0;
        $this->padding = 0;
    }

    private function skip(int $bytes): void
    {
        while ($bytes > 0) {
            $chunk = $this->readExact(min(1024 * 1024, $bytes));
            if ($chunk === null) {
                throw new PackageException(__('org_transfer.package.truncated'), 'package_invalid');
            }
            $bytes -= strlen($chunk);
        }
    }

    /** Exactly $length bytes; null at the end of the stream (an error unless $eofOk at a boundary). */
    private function readExact(int $length, bool $eofOk = false): ?string
    {
        if ($length === 0) {
            return '';
        }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));
            if ($chunk === false || ($chunk === '' && feof($this->stream))) {
                if ($data === '' && $eofOk) {
                    return null;
                }
                throw new PackageException(__('org_transfer.package.truncated'), 'package_invalid');
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function verifyChecksum(string $block): void
    {
        $stored = self::number(substr($block, 148, 8));
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ($i >= 148 && $i < 156) ? 32 : ord($block[$i]);
        }
        if ($stored !== $sum) {
            throw new PackageException(__('org_transfer.package.not_a_package'), 'not_a_package');
        }
    }

    /** An octal header field (NUL / space terminated); base-256 for GNU large numbers. */
    private static function number(string $field): int
    {
        if ($field !== '' && (ord($field[0]) & 0x80)) {
            $value = ord($field[0]) & 0x7F;
            for ($i = 1, $n = strlen($field); $i < $n; $i++) {
                $value = ($value << 8) | ord($field[$i]);
            }

            return $value;
        }
        $digits = trim($field, " \0");

        return $digits === '' ? 0 : (int) octdec($digits);
    }

    /** @return array<string, string> */
    private static function parsePax(string $data): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $space = strpos($data, ' ', $offset);
            if ($space === false) {
                break;
            }
            $recordLength = (int) substr($data, $offset, $space - $offset);
            if ($recordLength <= 0) {
                break;
            }
            $record = substr($data, $space + 1, $recordLength - ($space - $offset) - 2);
            $eq = strpos($record, '=');
            if ($eq !== false) {
                $out[substr($record, 0, $eq)] = substr($record, $eq + 1);
            }
            $offset += $recordLength;
        }

        return $out;
    }
}
