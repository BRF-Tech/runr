<?php

namespace App\Console\Commands;

use App\Services\Runtimes\Patches\PatchFeedSync;
use Illuminate\Console\Command;

/**
 * The runtime patch feed (scheduled every five minutes, routes/console.php): one HEAD of the feed
 * tag, the feed itself only when its digest changed (PatchFeedSync). Platform administrators run
 * the same with "Check now" on the Patches page.
 */
class SyncRuntimePatchesCommand extends Command
{
    protected $signature = 'runtime-patches:sync {--force : Read the feed even when its digest did not change}';

    protected $description = 'Check the signed runtime patch feed and store new patches';

    public function handle(PatchFeedSync $sync): int
    {
        $feed = $sync->sync((bool) $this->option('force'));

        $this->info("Feed {$feed->reference}: {$feed->status}".($feed->manifest_digest ? " ({$feed->manifest_digest})" : ''));
        if ($feed->error) {
            $this->warn($feed->error);
        }

        return in_array($feed->status, ['ok', 'disabled'], true) ? self::SUCCESS : self::FAILURE;
    }
}
