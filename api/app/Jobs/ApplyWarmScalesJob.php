<?php

namespace App\Jobs;

use App\Services\GitDeploy\TenantContext;
use App\Services\Projects\WarmScaleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled (every minute): the timed warmers (docs/scaling.md#timed-warmer) — each project's
 * schedule value of this minute, in its organization's time zone, on the PodAutoscaler of its
 * latest ready revision (WarmScaleService) — in the central schema and, in a multi-tenant install,
 * in every tenant's schema, where tenant projects live. `php artisan projects:warm` runs it at once.
 */
class ApplyWarmScalesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function handle(WarmScaleService $warmer): int
    {
        $patched = $warmer->run();

        if (TenantContext::enabled()) {
            TenantContext::each(function () use ($warmer, &$patched) {
                $patched += $warmer->run();
            });
        }

        if ($patched > 0) {
            Log::info('Timed warmers applied', ['patched' => $patched]);
        }

        return $patched;
    }
}
