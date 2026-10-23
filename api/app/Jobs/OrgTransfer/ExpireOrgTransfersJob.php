<?php

namespace App\Jobs\OrgTransfer;

use App\Services\OrgTransfer\OrgTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Hourly: abandoned uploads expire, packages past their retention are removed (OrgTransferService::expire()). */
class ExpireOrgTransfersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function handle(OrgTransferService $transfers): void
    {
        $transfers->expire();
    }
}
