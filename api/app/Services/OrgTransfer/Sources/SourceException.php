<?php

namespace App\Services\OrgTransfer\Sources;

/**
 * Reading an import's package from its source failed (docs/org-export.md#import). $retryable:
 * a network or region hiccup the fetch job tries again (from the bytes it has); otherwise — the
 * object is not there, access is denied, the package is too large, the source is not usable —
 * the import fails at once and is resumed after the cause is fixed.
 */
class SourceException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
