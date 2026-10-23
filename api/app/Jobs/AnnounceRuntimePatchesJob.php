<?php

namespace App\Jobs;

use App\Models\RuntimePatch;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * New runtime patches from the feed (PatchFeedSync): everyone each one concerns hears about it
 * by its level (RuntimePatchNotifier::announce()). Every notice goes out once, so a retry or the
 * enforcement job's catch-up sends nothing twice.
 */
class AnnounceRuntimePatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 2;

    /** @param list<string> $patchIds */
    public function __construct(public array $patchIds) {}

    public function handle(RuntimePatchNotifier $notifier): void
    {
        foreach (RuntimePatch::query()->whereIn('id', $this->patchIds)->where('in_feed', true)->orderBy('published_at')->get() as $patch) {
            try {
                $notifier->announce($patch);
            } catch (\Throwable $e) {
                Log::error('Runtime patch not announced', ['patch' => $patch->id, 'error' => $e->getMessage()]);
            }
        }
    }
}
