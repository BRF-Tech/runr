<?php

namespace App\Services\OrgTransfer;

use Illuminate\Http\JsonResponse;

/** Too many exports / imports running: the organization's one of a kind, or the installation's few. */
class TransferLimitReached extends \RuntimeException
{
    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'too_many_transfers'], 429);
    }
}
