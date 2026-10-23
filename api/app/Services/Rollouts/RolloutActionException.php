<?php

namespace App\Services\Rollouts;

/**
 * A promote / abort / rollback that cannot be done now: the API answers $status with
 * {"message", "code"} — 409 rollout_not_promotable / _not_abortable / _not_rollbackable, 502
 * rollout_traffic_failed when the cluster did not take the new traffic split.
 */
class RolloutActionException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409)
    {
        parent::__construct($message);
    }
}
