<?php

namespace App\Services\Runtimes\Patches;

/** Why a feed was not taken: status is a RuntimePatchFeed status (unreachable, invalid_signature, invalid_schema). */
final class PatchFeedException extends \RuntimeException
{
    public function __construct(public readonly string $status, string $message)
    {
        parent::__construct($message);
    }
}
