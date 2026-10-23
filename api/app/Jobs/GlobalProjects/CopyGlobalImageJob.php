<?php

namespace App\Jobs\GlobalProjects;

use App\Jobs\Concerns\RunsOnLongQueue;
use App\Models\Project;
use App\Models\ProjectRegion;
use App\Services\GlobalProjects\GlobalImageCopy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copies a global project's uploaded CaaS image into one region's registry (GlobalImageCopy) for
 * the rollout running there; the rollout's steps wait for the row's build_image, or fail with the
 * row's message. The token ties the copy to its rollout (a newer one starts its own).
 *
 * A large image crosses the control plane twice: up to an hour, longer than the default queue
 * allows (retry_after 1900 s) — so on the long queue (RunsOnLongQueue).
 */
class CopyGlobalImageJob implements ShouldQueue
{
    use Dispatchable, Queueable, RunsOnLongQueue;

    /** global_dns.image_copy_timeout: as long as the rollout waits for the copy */
    public int $timeout;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Project $project,
        public string $projectRegionId,
        public string $token,
    ) {
        $this->timeout = (int) config('global_dns.image_copy_timeout', 3600);
        $this->onLongQueue();
    }

    public function handle(GlobalImageCopy $copy): void
    {
        $row = ProjectRegion::query()->with('knativeRegion')->find($this->projectRegionId);
        if (! $row || $row->rollout_token !== $this->token || $row->status !== ProjectRegion::STATUS_BUILDING) {
            return;
        }

        $project = $this->project->fresh(['knativeRegion', 'containerConfig']) ?? $this->project;
        try {
            $ref = $copy->copy($project, $row->knativeRegion ?? throw new \RuntimeException(__('api.global.region_unavailable')));
            if ($row->fresh()?->rollout_token === $this->token) {
                $row->update(['build_image' => $ref, 'status_message' => null]);
            }
        } catch (Throwable $e) {
            Log::warning('Uploaded image not copied for a global project', ['project' => $project->slug, 'region' => $row->knativeRegion?->name, 'error' => $e->getMessage()]);
            if ($row->fresh()?->rollout_token === $this->token) {
                $row->update([
                    'status' => ProjectRegion::STATUS_FAILED,
                    'status_message' => mb_substr(__('api.global.image_copy_failed', ['region' => $row->knativeRegion?->name, 'reason' => $e->getMessage()]), 0, 2000),
                    'rollout_token' => null,
                ]);
            }
        }
    }
}
