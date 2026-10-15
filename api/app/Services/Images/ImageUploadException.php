<?php

namespace App\Services\Images;

use Illuminate\Http\JsonResponse;

/**
 * An upload request RunR refuses: HTTP status, a message and a machine-readable code
 * (registry_unavailable, quota_exceeded, upload_open, upload_incomplete, upload_not_ready,
 * receiver_failed, image_in_use, …).
 */
class ImageUploadException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $reason = 'invalid', public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->reason] + $this->details, $this->status);
    }
}
