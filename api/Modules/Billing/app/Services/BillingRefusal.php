<?php

namespace Modules\Billing\Services;

use Illuminate\Http\JsonResponse;

/**
 * A billing action that is not possible in the current state: answered with its HTTP status,
 * a machine-readable code and a message (docs/billing.md#errors).
 */
class BillingRefusal extends \RuntimeException
{
    /** @param array<string, mixed> $extra */
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
