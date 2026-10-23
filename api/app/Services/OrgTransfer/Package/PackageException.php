<?php

namespace App\Services\OrgTransfer\Package;

/**
 * A package that cannot be written or read: not a package, a newer schema version, a checksum
 * that does not match, a wrong passphrase. $errorCode is what the API answers with (code).
 */
class PackageException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'package_invalid', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
