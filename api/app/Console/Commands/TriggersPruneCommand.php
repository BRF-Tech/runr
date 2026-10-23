<?php

namespace App\Console\Commands;

use App\Services\Observability\TenantScope;
use App\Triggers\Inbox\InboxService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * triggers:prune — deletes stored webhook / platform events past their trigger's retention
 * (trigger_events.expires_at) in the central database and every tenant schema. Scheduled daily
 * by App\Providers\TriggerServiceProvider.
 */
class TriggersPruneCommand extends Command
{
    protected $signature = 'triggers:prune';

    protected $description = 'Delete stored trigger events (webhook inbox, platform events) past their retention';

    public function handle(InboxService $inbox): int
    {
        $total = 0;
        TenantScope::each(function () use ($inbox, &$total) {
            if (Schema::hasTable('trigger_events')) {
                $total += $inbox->prune();
            }
        });

        $this->info("Pruned {$total} trigger events");

        return self::SUCCESS;
    }
}
