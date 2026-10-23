<?php

namespace App\Services\OrgTransfer\Package;

/**
 * Writes a POSIX tar (ustar, with a PAX extended header for names over 100 bytes and files of
 * 8 GiB and more) to a stream, one regular file at a time — files are streamed, never held in
 * memory. Only regular files: the package has no directories, links or devices.
 */
final class TarWriter
{
    private const BLOCK = 512;

    /** Largest size the 11 octal digits of a ustar header hold. */
    private const USTAR_MAX_SIZE = 8589934591;

    private int $mtime;

    /** @param resource $stream */
    public function __construct(private $stream, ?int $mtime = null)
    {
        $this->mtime = $mtime ?? time();
    }

    /** Add the file at $path as $name; returns its SHA-256. */
    public function addFile(string $name, string $path): string
    {
        $size = filesize($path);
        if ($size === false) {
            throw new PackageException("Cannot read {$path}.", 'package_write_failed');
        }

        $in = fopen($path, 'rb');
        if ($in === false) {
            throw new PackageException("Cannot open {$path}.", 'package_write_failed');
        }

        try {
            $this->header($name, $size);
            $hash = hash_init('sha256');
            $written = 0;
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new PackageException("Cannot read {$path}.", 'package_write_failed');
                }
                if ($chunk === '') {
                    continue;
                }
                hash_update($hash, $chunk);
                $this->write($chunk);
                $written += strlen($chunk);
            }
            if ($written !== $size) {
                throw new PackageException("{$path} changed while it was packed.", 'package_write_failed');
            }
            $this->pad($size);

            return hash_final($hash);
        } finally {
            fclose($in);
        }
    }

    /** Add $content as $name; returns its SHA-256. */
    public function addString(string $name, string $content): string
    {
        $this->header($name, strlen($content));
        $this->write($content);
        $this->pad(strlen($content));

        return hash('sha256', $content);
    }

    /** The end-of-archive marker: two zero blocks. */
    public function finish(): void
    {
        $this->write(str_repeat("\0", self::BLOCK * 2));
    }

    private function header(string $name, int $size): void
    {
        $pax = [];
        if (strlen($name) > 100) {
            $pax['path'] = $name;
        }
        if ($size > self::USTAR_MAX_SIZE) {
            $pax['size'] = (string) $size;
        }

        if ($pax) {
            $records = '';
            foreach ($pax as $key => $value) {
                $records .= self::paxRecord($key, $value);
            }
            $this->write(self::block('PaxHeader/'.substr(md5($name), 0, 16), strlen($records), 'x', $this->mtime));
            $this->write($records);
            $this->pad(strlen($records));
        }

        $this->write(self::block(
            strlen($name) > 100 ? substr($name, 0, 100) : $name,
            $size > self::USTAR_MAX_SIZE ? 0 : $size,
            '0',
            $this->mtime,
        ));
    }

    /** "<length> <key>=<value>\n", the length counting itself. */
    private static function paxRecord(string $key, string $value): string
    {
        $body = ' '.$key.'='.$value."\n";
        $length = strlen($body) + 1;
        while (strlen((string) $length) + strlen($body) !== $length) {
            $length = strlen((string) $length) + strlen($body);
        }

        return $length.$body;
    }

    private static function block(string $name, int $size, string $type, int $mtime): string
    {
        $header = str_pad($name, 100, "\0")
            .self::octal(0644, 8)
            .self::octal(0, 8)
            .self::octal(0, 8)
            .self::octal($size, 12)
            .self::octal($mtime, 12)
            .str_repeat(' ', 8)                 // checksum, computed below
            .$type
            .str_repeat("\0", 100)              // linkname
            ."ustar\0".'00'
            .str_pad('runr', 32, "\0")
            .str_pad('runr', 32, "\0")
            .self::octal(0, 8)
            .self::octal(0, 8)
            .str_repeat("\0", 155)              // prefix
            .str_repeat("\0", 12);

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    private static function octal(int $value, int $width): string
    {
        return str_pad(decoct($value), $width - 1, '0', STR_PAD_LEFT)."\0";
    }

    private function pad(int $size): void
    {
        $rest = $size % self::BLOCK;
        if ($rest !== 0) {
            $this->write(str_repeat("\0", self::BLOCK - $rest));
        }
    }

    private function write(string $bytes): void
    {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $n = fwrite($this->stream, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($n === false || $n === 0) {
                throw new PackageException('The package could not be written (disk full?).', 'package_write_failed');
            }
            $offset += $n;
        }
    }
}
