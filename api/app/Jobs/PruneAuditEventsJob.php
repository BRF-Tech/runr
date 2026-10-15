<?php

namespace App\Jobs;

use App\Services\Audit\AuditRetention;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Daily audit log retention (routes/console.php): deletes events older than the retention period. */
class PruneAuditEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function handle(AuditRetention $retention): void
    {
        $retention->prune();
    }
}
