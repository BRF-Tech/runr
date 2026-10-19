<?php

namespace App\Services\GitDeploy;

use RuntimeException;

/**
 * A pushed commit cannot become a version (limits, missing handler file, EOL runtime, ...), or a
 * version cannot be deployed; $errorCode carries the code of a ProjectAdmission refusal
 * (project_stopped, fixed_allocation_exceeded, ...) when that was the reason.
 */
class ImportException extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?string $errorCode = null)
    {
        parent::__construct($message);
    }
}
