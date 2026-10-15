<?php

namespace App\Workflows\Engine;

use DateTimeInterface;

/** Result of trying to take a run's lease for one invocation. */
final class Claim
{
    private function __construct(
        public readonly ?string $token,
        public readonly ?DateTimeInterface $retryAt,
        public readonly string $reason,
    ) {}

    public static function claimed(string $token): self
    {
        return new self($token, null, 'claimed');
    }

    /** Not runnable before $at (backoff), or — $at null — waiting for a concurrency slot. */
    public static function deferred(?DateTimeInterface $at, string $reason): self
    {
        return new self(null, $at, $reason);
    }

    public static function none(string $reason = 'not_queued'): self
    {
        return new self(null, null, $reason);
    }
}
