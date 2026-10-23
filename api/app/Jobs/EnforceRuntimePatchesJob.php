<?php

namespace App\Jobs;

use App\Models\RuntimePatch;
use App\Services\Observability\TenantScope;
use App\Services\Runtimes\Patches\RuntimePatches;
use App\Services\Runtimes\Patches\RuntimePatchMigrations;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Runtime patch deadlines (scheduled every five minutes, docs/runtimes.md#deadlines), in the
 * central schema and every tenant's:
 *
 *  1. started migrations one step further — succeeded, or failed and rolled back
 *     (RuntimePatchMigrations::watch(); a deployment's status change also checks right away)
 *  2. critical / zero-day patches past their deadline: the projects still behind are moved onto
 *     the newest patch, while the platform policy allows it
 *  3. patches not announced yet (a failed announcement) are announced
 *  4. zero-day alerts of organizations that no longer run behind are resolved on their channels
 */
class EnforceRuntimePatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function handle(RuntimePatchMigrations $migrations, RuntimePatchNotifier $notifier): void
    {
        // What the feed and the deployments say now, not what an earlier step of this worker read
        app(RuntimePatches::class)->flush();

        TenantScope::each(function () use ($migrations): void {
            $migrations->watch();
        });

        $started = $migrations->enforceDeadlines();
        if ($started > 0) {
            Log::info('Runtime patch deadlines: projects migrated', ['started' => $started]);
        }

        foreach (RuntimePatch::query()->where('in_feed', true)->whereNull('announced_at')->orderBy('published_at')->get() as $patch) {
            try {
                $notifier->announce($patch);
            } catch (\Throwable $e) {
                Log::error('Runtime patch not announced', ['patch' => $patch->id, 'error' => $e->getMessage()]);
            }
        }

        foreach (RuntimePatch::query()->where('level', RuntimePatch::ZERO_DAY)->where('updated_at', '>=', now()->subDays(90))->get() as $patch) {
            $notifier->resolveAlerts($patch);
        }
    }
}
