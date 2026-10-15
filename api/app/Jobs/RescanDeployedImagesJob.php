<?php

namespace App\Jobs;

use App\Services\SupplyChain\RescanService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled (hourly): queues a re-scan of every deployed image whose last scan is older than
 * its region's re-scan interval (RescanService).
 */
class RescanDeployedImagesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function handle(RescanService $rescans): void
    {
        $count = $rescans->rescanDue();
        if ($count > 0) {
            Log::info('Deployed images queued for re-scan', ['count' => $count]);
        }
    }
}
