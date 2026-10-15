<?php

namespace App\Workflows\Transport;

final class TransportResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly int $durationMs,
    ) {}
}
