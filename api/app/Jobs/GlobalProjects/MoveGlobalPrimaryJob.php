<?php

namespace App\Jobs\GlobalProjects;

use App\Models\KnativeRegion;
use App\Models\Project;
use App\Services\GlobalProjects\GlobalFailover;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A switchover or a failover of a global project whose dedicated Postgres bindings have replica
 * clusters (GlobalFailover): CloudNativePG demotes and promotes them first, which takes a while.
 * The project's move lock (GlobalFailover::lockKey) is released when it ends.
 */
class MoveGlobalPrimaryJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Project $project,
        public string $regionId,
        public string $mode,
        public bool $automatic = false,
    ) {}

    public function handle(GlobalFailover $failover): void
    {
        $project = $this->project->fresh(['knativeRegion']) ?? $this->project;
        $to = KnativeRegion::query()->find($this->regionId);

        try {
            if ($to !== null) {
                $this->mode === 'failover'
                    ? $failover->failover($project, $to, $this->automatic)
                    : $failover->switchover($project, $to);
            }
        } catch (Throwable $e) {
            Log::error('Global project primary not moved', ['project' => $project->slug, 'to' => $this->regionId, 'mode' => $this->mode, 'error' => $e->getMessage()]);
        } finally {
            Cache::forget(GlobalFailover::lockKey($project));
        }
    }
}
