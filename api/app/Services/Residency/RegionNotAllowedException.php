<?php

namespace App\Services\Residency;

use Illuminate\Http\JsonResponse;

/**
 * A request the tenant's data residency refuses (RegionPolicy): HTTP 422 with a field error on
 * knative_region_id, so forms show it next to the region picker.
 */
class RegionNotAllowedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $action,
        public readonly ?string $regionId,
        public readonly string $field = 'knative_region_id',
        public readonly string $errorCode = 'region_not_allowed',
    ) {
        parent::__construct($message);
    }

    /** An expected refusal, audited by RegionPolicy — not an application error */
    public function report(): void {}

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'action' => $this->action,
            'errors' => [$this->field => [$this->getMessage()]],
        ], 422);
    }
}
