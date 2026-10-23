<?php

namespace App\Jobs\GlobalDns;

use App\Services\GlobalDns\GlobalZoneSync;
use App\Services\GlobalDns\PlatformContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * The global zone written to every server (GlobalZoneSync), in the central context. Scheduled
 * every minute (routes/console.php) and queued after every change of a global project or a server;
 * one at a time — a change queued while one waits rides along with it.
 */
class SyncGlobalZoneJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return 'global-zone';
    }

    public function handle(): void
    {
        $lines = PlatformContext::run(fn () => app(GlobalZoneSync::class)->sync());

        foreach ($lines as $line) {
            if (str_contains($line, 'failed') || str_contains($line, 'drift')) {
                Log::warning("Global zone sync: {$line}");
            }
        }
    }
}
