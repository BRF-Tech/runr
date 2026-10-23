<?php

namespace App\Services\Runtimes\Patches;

/**
 * A runtime patch action that cannot be done: code (not_applicable, no_patch, not_pinnable,
 * not_deployed, project_stopped, already_running, migration_running, deploy_refused) and the HTTP
 * status the API answers with.
 */
final class RuntimePatchException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
