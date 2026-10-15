<?php

namespace App\Workflows\Engine;

/**
 * Exponential backoff between attempts (step retries and function-level retries):
 * delay(n) = min(max, base × factor^(n-1)) ± jitter, n = the number of failed attempts so far.
 */
final class Backoff
{
    public function __construct(
        private readonly float $base = 2.0,
        private readonly float $factor = 2.0,
        private readonly float $max = 600.0,
        private readonly float $jitter = 0.2,
    ) {}

    public static function fromConfig(): self
    {
        $c = (array) config('workflows.backoff', []);

        return new self(
            (float) ($c['base_seconds'] ?? 2),
            (float) ($c['factor'] ?? 2),
            (float) ($c['max_seconds'] ?? 600),
            (float) ($c['jitter'] ?? 0.2),
        );
    }

    /** Milliseconds to wait after the $failedAttempts-th failure (1-based). */
    public function delayMs(int $failedAttempts): int
    {
        $n = max(1, $failedAttempts);
        $delay = min($this->max, $this->base * ($this->factor ** ($n - 1)));

        if ($this->jitter > 0) {
            $spread = $delay * $this->jitter;
            $delay += (mt_rand() / mt_getrandmax() * 2 - 1) * $spread;
        }

        return (int) max(0, round(min($delay, $this->max) * 1000));
    }
}
