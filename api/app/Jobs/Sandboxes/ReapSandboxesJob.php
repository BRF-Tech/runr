<?php

namespace App\Jobs\Sandboxes;

use App\Services\Sandboxes\SandboxReaper;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** TTL / idle / quota expiry, stuck starts and deletes, lost and orphan pods, retention (SandboxReaper). */
class ReapSandboxesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function handle(SandboxReaper $reaper): void
    {
        $done = $reaper->run();

        if (array_sum($done) > 0) {
            Log::info('sandbox reaper', $done);
        }
    }
}
