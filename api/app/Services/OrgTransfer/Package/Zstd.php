<?php

namespace App\Services\OrgTransfer\Package;

/**
 * zstd streams for the package: the PHP zstd extension's stream wrapper when it is loaded, else
 * the zstd binary (config org_transfer.zstd_binary, in the API image) through a pipe — either way
 * the package is compressed and decompressed as a stream, never in memory.
 *
 * Packages start with the zstd frame magic; a plain tar (compression "none", for debugging) is
 * read as it is.
 */
final class Zstd
{
    /** First bytes of a zstd frame (0xFD2FB528, little endian). */
    public const MAGIC = "\x28\xB5\x2F\xFD";

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stderr = null;

    /** @param resource $stream */
    private function __construct(private $stream, private readonly string $what) {}

    /** The stream to write to / read from. */
    public function stream()
    {
        return $this->stream;
    }

    public static function available(): bool
    {
        return extension_loaded('zstd') || self::binary() !== null;
    }

    public static function isCompressed(string $path): bool
    {
        $in = @fopen($path, 'rb');
        if ($in === false) {
            return false;
        }
        $head = (string) fread($in, 4);
        fclose($in);

        return $head === self::MAGIC;
    }

    /**
     * A stream that writes $path; compressed unless $compress is false.
     *
     * @throws PackageException
     */
    public static function writer(string $path, bool $compress = true, int $level = 3): self
    {
        if (! $compress) {
            return new self(self::open($path, 'wb'), $path);
        }

        if (extension_loaded('zstd')) {
            $context = stream_context_create(['zstd' => ['level' => $level]]);
            $stream = @fopen('compress.zstd://'.$path, 'wb', false, $context);
            if ($stream === false) {
                throw new PackageException("Cannot write {$path}.", 'package_write_failed');
            }

            return new self($stream, $path);
        }

        $binary = self::binary() ?? throw new PackageException(__('org_transfer.package.zstd_missing'), 'zstd_missing');

        return self::pipe([$binary, '-q', '-f', '-'.$level, '-T0', '-o', $path], 0, $path);
    }

    /**
     * A stream that reads $path, decompressed when it is a zstd file.
     *
     * @throws PackageException
     */
    public static function reader(string $path): self
    {
        if (! self::isCompressed($path)) {
            return new self(self::open($path, 'rb'), $path);
        }

        if (extension_loaded('zstd')) {
            $stream = @fopen('compress.zstd://'.$path, 'rb');
            if ($stream === false) {
                throw new PackageException("Cannot read {$path}.", 'package_invalid');
            }

            return new self($stream, $path);
        }

        $binary = self::binary() ?? throw new PackageException(__('org_transfer.package.zstd_missing'), 'zstd_missing');

        return self::pipe([$binary, '-q', '-d', '-c', $path], 1, $path);
    }

    /**
     * Close the stream; a failed zstd process is an error (a corrupt or truncated package when
     * reading, a full disk when writing) unless $ignoreErrors (a reader left early).
     *
     * @throws PackageException
     */
    public function close(bool $ignoreErrors = false): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        if ($this->process === null) {
            return;
        }

        $error = is_resource($this->stderr) ? trim((string) stream_get_contents($this->stderr)) : '';
        if (is_resource($this->stderr)) {
            fclose($this->stderr);
        }
        $code = proc_close($this->process);
        $this->process = null;

        if ($code !== 0 && ! $ignoreErrors) {
            throw new PackageException(__('org_transfer.package.zstd_failed', ['file' => basename($this->what), 'error' => mb_substr($error, 0, 300)]), 'package_invalid');
        }
    }

    private static function binary(): ?string
    {
        $binary = (string) config('org_transfer.zstd_binary', 'zstd');
        if ($binary === '') {
            return null;
        }
        if (str_contains($binary, '/')) {
            return is_executable($binary) ? $binary : null;
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir.'/'.$binary)) {
                return $dir.'/'.$binary;
            }
        }

        return null;
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        $stream = @fopen($path, $mode);
        if ($stream === false) {
            throw new PackageException("Cannot open {$path}.", $mode === 'rb' ? 'package_invalid' : 'package_write_failed');
        }

        return $stream;
    }

    /**
     * zstd as a child process; $end is the pipe this side uses (0: its stdin, 1: its stdout).
     *
     * @param  list<string>  $command
     */
    private static function pipe(array $command, int $end, string $path): self
    {
        $spec = $end === 0
            ? [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']]
            : [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open($command, $spec, $pipes);
        if (! is_resource($process)) {
            throw new PackageException(__('org_transfer.package.zstd_missing'), 'zstd_missing');
        }

        $zstd = new self($pipes[$end], $path);
        $zstd->process = $process;
        $zstd->stderr = $pipes[2];

        return $zstd;
    }
}
