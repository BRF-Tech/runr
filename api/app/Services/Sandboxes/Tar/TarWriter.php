<?php

namespace App\Services\Sandboxes\Tar;

/**
 * Minimal ustar writer for files handed to a sandbox (create `files`, the env file). Names longer
 * than 100 bytes use the ustar prefix field, longer ones a GNU long-name entry — both understood by
 * GNU tar and busybox tar.
 */
final class TarWriter
{
    private string $data = '';

    public function addFile(string $name, string $content, int $mode = 0644, ?int $mtime = null): self
    {
        $name = ltrim($name, '/');
        $this->header($name, strlen($content), $mode, $mtime ?? time(), '0');
        $this->data .= $content.self::padding(strlen($content));

        return $this;
    }

    public function addDirectory(string $name, int $mode = 0755, ?int $mtime = null): self
    {
        $name = rtrim(ltrim($name, '/'), '/').'/';
        $this->header($name, 0, $mode, $mtime ?? time(), '5');

        return $this;
    }

    /** The archive (two zero blocks at the end). */
    public function bytes(): string
    {
        return $this->data.str_repeat("\0", 1024);
    }

    private function header(string $name, int $size, int $mode, int $mtime, string $type): void
    {
        [$prefix, $short] = self::split($name);

        if ($short === null) {
            // GNU long name: a pseudo entry carrying the full name, then a header with its first 100 bytes
            $long = $name."\0";
            $this->data .= self::block('././@LongLink', strlen($long), 0644, 0, 'L', '');
            $this->data .= $long.self::padding(strlen($long));
            $prefix = '';
            $short = substr($name, 0, 100);
        }

        $this->data .= self::block($short, $size, $mode, $mtime, $type, $prefix);
    }

    /** [prefix, name] for the ustar fields, [.., null] when the name does not fit. */
    private static function split(string $name): array
    {
        if (strlen($name) <= 100) {
            return ['', $name];
        }

        $cut = strlen($name);
        while (($cut = strrpos(substr($name, 0, $cut), '/')) !== false) {
            $prefix = substr($name, 0, $cut);
            $rest = substr($name, $cut + 1);
            if (strlen($prefix) <= 155 && strlen($rest) <= 100 && $rest !== '') {
                return [$prefix, $rest];
            }
            if ($cut === 0) {
                break;
            }
        }

        return ['', null];
    }

    private static function block(string $name, int $size, int $mode, int $mtime, string $type, string $prefix): string
    {
        $header = str_pad($name, 100, "\0")
            .self::octal($mode & 07777, 8)
            .self::octal(0, 8)          // uid
            .self::octal(0, 8)          // gid
            .self::octal($size, 12)
            .self::octal($mtime, 12)
            .'        '                 // checksum placeholder
            .$type
            .str_repeat("\0", 100)      // linkname
            ."ustar\0".'00'
            .str_pad('', 32, "\0")      // uname
            .str_pad('', 32, "\0")      // gname
            .self::octal(0, 8)
            .self::octal(0, 8)
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);

        $sum = 0;
        for ($i = 0; $i < 512; $i++) {
            $sum += ord($header[$i]);
        }

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    private static function octal(int $value, int $width): string
    {
        return str_pad(decoct($value), $width - 1, '0', STR_PAD_LEFT)."\0";
    }

    private static function padding(int $length): string
    {
        $rest = $length % 512;

        return $rest === 0 ? '' : str_repeat("\0", 512 - $rest);
    }
}
