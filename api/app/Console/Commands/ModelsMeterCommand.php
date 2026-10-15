<?php

namespace App\Console\Commands;

use App\Services\Ai\Models\ModelUsageMeter;
use Illuminate\Console\Command;

/** Samples the running pods of model services into their daily usage (scheduled every minute). */
class ModelsMeterCommand extends Command
{
    protected $signature = 'models:meter';

    protected $description = 'Meter replica, CPU, memory and GPU time of model services';

    public function handle(ModelUsageMeter $meter): int
    {
        $count = $meter->meterAll();
        $this->info("Metered {$count} model service(s).");

        return self::SUCCESS;
    }
}
