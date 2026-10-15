<?php

namespace App\Exceptions;

/**
 * A Kata install that adds hypervisors to a working install failed before kata-deploy changed
 * anything: the region keeps its ready Kata with the previous hypervisors; only the additions
 * ($notAdded) were not installed.
 */
class SandboxAddonFailedException extends \RuntimeException
{
    /**
     * @param  list<string>  $notAdded
     * @param  list<string>  $kept
     */
    public function __construct(string $message, public readonly array $notAdded = [], public readonly array $kept = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
