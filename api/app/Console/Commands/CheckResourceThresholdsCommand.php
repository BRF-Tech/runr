<?php

namespace App\Console\Commands;

use App\Services\ResourceThresholdService;
use Illuminate\Console\Command;

class CheckResourceThresholdsCommand extends Command
{
    protected $signature = 'resources:check-thresholds';

    protected $description = 'Check resource usage thresholds for all active regions';

    public function handle(ResourceThresholdService $service): int
    {
        $service->checkAllRegions();

        $this->info('Resource threshold check completed.');

        return self::SUCCESS;
    }
}
