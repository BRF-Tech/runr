<?php

namespace App\Services\Sandboxes;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refused or failed Sandbox API call: HTTP status, machine-readable `reason` (docs/sandbox-api.md
 * → Errors) and extra response fields. Rendered as {message, reason, ...extra}.
 */
class SandboxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?string $reason = null,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function notRunning(string $status): self
    {
        return new self(__('sandboxes.not_running', ['status' => $status]), 409, 'not_running', ['status' => $status]);
    }

    public static function cluster(string $detail): self
    {
        return new self(__('sandboxes.cluster_error', ['detail' => $detail]), 502, 'cluster_error');
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
        ], fn ($v) => $v !== null) + $this->extra, $this->status);
    }
}
