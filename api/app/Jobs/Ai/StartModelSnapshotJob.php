<?php

namespace App\Jobs\Ai;

use App\Models\AiModelSnapshot;
use App\Services\Ai\Models\SnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** Start the mirror / import Job of a snapshot (SnapshotService::start()) or remove one ($delete). */
class StartModelSnapshotJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $snapshotId, public bool $delete = false) {}

    public function handle(SnapshotService $snapshots): void
    {
        $snapshot = AiModelSnapshot::with('model', 'region')->find($this->snapshotId);
        if (! $snapshot) {
            return;
        }

        $this->delete ? $snapshots->delete($snapshot) : $snapshots->start($snapshot);
    }
}
