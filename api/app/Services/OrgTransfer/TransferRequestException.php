<?php

namespace App\Services\OrgTransfer;

use Illuminate\Http\JsonResponse;

/** A request the transfer cannot take (a chunk off the upload, a plan that blocks, …). */
class TransferRequestException extends \RuntimeException
{
    /** @param array<string, mixed> $extra added to the answer */
    public function __construct(string $message, public readonly int $status, public readonly string $errorCode, public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode] + $this->extra, $this->status);
    }
}
