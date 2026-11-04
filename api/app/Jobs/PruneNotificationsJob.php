<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationRetention;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Daily notification retention (routes/console.php, notification-retention): the notifications
 * older than the retention (config notifications.retention_days) and those of deleted users go
 * for good, in the central schema and in every organization's schema (NotificationRetention).
 */
class PruneNotificationsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function handle(NotificationRetention $retention): void
    {
        $removed = $retention->prune();

        if (array_sum($removed) > 0) {
            Log::info('Notifications pruned', $removed + ['retention_days' => $retention->retentionDays()]);
        }
    }
}
