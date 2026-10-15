<?php

namespace App\Console\Commands;

use App\Jobs\CheckKnativeStatusJob;
use App\Models\KnativeRegion;
use Illuminate\Console\Command;

class CheckKnativeStatusCommand extends Command
{
    protected $signature = 'knative:check-status';

    protected $description = 'Dispatch status check jobs for all active regions';

    public function handle(): int
    {
        $regions = KnativeRegion::whereNotNull('kubeconfig')
            ->where('kubeconfig', '!=', '')
            ->whereNotIn('knative_status', ['installing', 'uninstalling'])
            ->where('is_demo', false)
            ->get();

        foreach ($regions as $region) {
            CheckKnativeStatusJob::dispatch($region);
        }

        $this->info("Dispatched status check for {$regions->count()} region(s).");

        return self::SUCCESS;
    }
}
