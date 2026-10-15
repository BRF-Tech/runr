<?php

namespace App\Jobs\GitDeploy;

use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled (hourly): delete previews whose TTL passed — no new commit for preview_ttl_hours —
 * in every organization.
 */
class CleanupPreviewsJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(PreviewService $previews): void
    {
        $total = 0;

        TenantContext::each(function () use ($previews, &$total) {
            $total += $previews->cleanupExpired();
        });

        if ($total > 0) {
            Log::info('Expired previews deleted', ['count' => $total]);
        }
    }
}
