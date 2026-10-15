<?php

namespace App\Workflows\Engine;

use App\Workflows\Models\WorkflowRun;

final class StartResult
{
    public function __construct(
        public readonly WorkflowRun $run,
        /** false: an existing run with the same idempotency key was returned */
        public readonly bool $created,
    ) {}
}
