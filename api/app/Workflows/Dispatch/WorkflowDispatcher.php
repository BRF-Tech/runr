<?php

namespace App\Workflows\Dispatch;

use DateTimeInterface;

/**
 * Schedules the next execution of a run. The queue implementation dispatches
 * ExecuteWorkflowRunJob after the surrounding transaction commits; tests record the calls.
 */
interface WorkflowDispatcher
{
    public function dispatch(string $runId, ?DateTimeInterface $at = null): void;
}
