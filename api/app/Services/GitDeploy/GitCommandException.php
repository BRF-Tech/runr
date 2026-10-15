<?php

namespace App\Services\GitDeploy;

use RuntimeException;

/** A git command exited non-zero. */
class GitCommandException extends RuntimeException
{
    public function __construct(
        public readonly array $args,
        public readonly int $exitCode,
        public readonly string $output,
    ) {
        parent::__construct(sprintf('git %s failed (%d): %s', $args[0] ?? '', $exitCode, mb_substr(trim($output), 0, 500)));
    }
}
