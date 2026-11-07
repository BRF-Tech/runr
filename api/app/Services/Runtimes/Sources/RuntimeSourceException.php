<?php

namespace App\Services\Runtimes\Sources;

use App\Models\RuntimeSource;

/**
 * A source could not be read, or what it serves cannot be trusted: $status is the source status
 * the read ends in (RuntimeSource::UNREACHABLE, NOT_FOUND, AUTH_FAILED, INVALID_SIGNATURE,
 * INVALID_CATALOG).
 * The message never carries a credential (SourceCredentials::mask()).
 */
class RuntimeSourceException extends \RuntimeException
{
    public function __construct(public readonly string $status, string $message)
    {
        parent::__construct($message);
    }

    public static function unreachable(string $message): self
    {
        return new self(RuntimeSource::UNREACHABLE, $message);
    }

    public static function invalid(string $message): self
    {
        return new self(RuntimeSource::INVALID_CATALOG, $message);
    }
}
