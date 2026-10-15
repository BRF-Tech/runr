<?php

namespace App\Services\Sandboxes\Exec;

/** Outcome of one command in a sandbox. stdout / stderr are raw bytes (bounded by max_output). */
final class ExecResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout = '',
        public readonly string $stderr = '',
        public readonly bool $timedOut = false,
        public readonly int $durationMs = 0,
        public readonly bool $truncated = false,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    /**
     * API shape: text fields when the bytes are valid UTF-8, *_base64 otherwise.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            ['exit_code' => $this->exitCode],
            self::textOrBase64('stdout', $this->stdout),
            self::textOrBase64('stderr', $this->stderr),
            [
                'timed_out' => $this->timedOut,
                'duration_ms' => $this->durationMs,
                'truncated' => $this->truncated,
            ],
        );
    }

    /** ['stdout' => text] or ['stdout' => '', 'stdout_base64' => …] */
    public static function textOrBase64(string $field, string $bytes): array
    {
        if ($bytes === '' || mb_check_encoding($bytes, 'UTF-8')) {
            return [$field => $bytes];
        }

        return [$field => '', "{$field}_base64" => base64_encode($bytes)];
    }
}
