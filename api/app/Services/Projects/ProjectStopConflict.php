<?php

namespace App\Services\Projects;

use Illuminate\Http\JsonResponse;

/**
 * A stop or resume that the project's state does not allow (ProjectStopService): already stopped
 * (also by a concurrent stop), not stopped (any more), or stopped by the platform and resumed by an
 * organization. Answered with its HTTP status, a code and a message.
 */
class ProjectStopConflict extends \RuntimeException
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
