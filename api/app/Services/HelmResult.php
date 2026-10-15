<?php

namespace App\Services;

class HelmResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly string $error,
        public readonly int $exitCode,
    ) {}
}
