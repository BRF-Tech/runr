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
    /**
     * @param  bool  $shown  the message is fit for an organization's users as it is (the outbound
     *                       guard's reason), not replaced by RuntimeSourceSync::forOrganization()
     */
    public function __construct(public readonly string $status, string $message, public readonly bool $shown = false)
    {
        parent::__construct($message);
    }

    /** The outbound guard refused the source's host (an organization's source). */
    public static function refusedHost(string $reason): self
    {
        return new self(RuntimeSource::UNREACHABLE, $reason, true);
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
