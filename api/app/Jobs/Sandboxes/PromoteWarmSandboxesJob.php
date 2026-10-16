<?php

namespace App\Jobs\Sandboxes;

use App\Models\SandboxPool;
use App\Services\Sandboxes\SandboxContext;
use App\Services\Sandboxes\SandboxPoolService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Warms the pods a pool refill just started (SandboxPoolService::promote): each one is claimable
 * as soon as its pod runs, instead of at the next refill up to a minute later. Waits at most
 * sandbox.pool.promote_wait_seconds; what still starts after that is left to the refill.
 */
class PromoteWarmSandboxesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * @param  list<string>  $sandboxIds
     */
    public function __construct(
        public string $poolId,
        public array $sandboxIds,
    ) {}

    public function handle(SandboxPoolService $pools): void
    {
        $pool = SandboxPool::find($this->poolId);
        if (! $pool) {
            return;
        }

        SandboxContext::run($pool->tenant_id, fn () => $pools->promote($pool, $this->sandboxIds));
    }
}
