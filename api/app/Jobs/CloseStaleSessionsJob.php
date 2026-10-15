<?php

namespace App\Jobs;

use App\Services\GitDeploy\TenantContext;
use App\Services\SessionTrackingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled (every ten minutes): end deployment sessions whose end the region agent never
 * reported (SessionTrackingService::closeStale) — in the central schema and, in a multi-tenant
 * install, in every tenant's schema, where tenant projects keep their sessions.
 */
class CloseStaleSessionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(SessionTrackingService $sessions): void
    {
        $closed = $sessions->closeStale();

        if (TenantContext::enabled()) {
            TenantContext::each(function () use ($sessions, &$closed) {
                $closed += $sessions->closeStale();
            });
        }

        if ($closed > 0) {
            Log::info('Stale deployment sessions closed', ['count' => $closed]);
        }
    }
}
