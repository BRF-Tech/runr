<?php

namespace App\Console\Commands;

use App\Jobs\ApplyWarmScalesJob;
use App\Services\Projects\WarmScaleService;
use Illuminate\Console\Command;

/**
 * Applies the timed warmers now (what the scheduler queues every minute, ApplyWarmScalesJob):
 * for an operator, and the e2e suite (test:e2e --suite=warmer).
 */
class ApplyWarmScalesCommand extends Command
{
    protected $signature = 'projects:warm';

    protected $description = "Apply the projects' timed warmers (scale schedules) to their Knative PodAutoscalers now";

    public function handle(WarmScaleService $warmer): int
    {
        $patched = (new ApplyWarmScalesJob)->handle($warmer);
        $this->info("PodAutoscalers patched: {$patched}");

        return self::SUCCESS;
    }
}
