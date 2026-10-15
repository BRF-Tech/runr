<?php

namespace App\Jobs\Sandboxes;

use App\Services\Sandboxes\SandboxPoolService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Warm pool refill (SandboxPoolService::refill): every minute for all pools, and right after a
 * claim / miss for that pool. Unique per pool while queued, so a burst of claims queues one refill.
 */
class RefillSandboxPoolsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    public function __construct(public ?string $poolId = null) {}

    public function uniqueId(): string
    {
        return $this->poolId ?? 'all';
    }

    public function handle(SandboxPoolService $pools): void
    {
        $pools->refill($this->poolId);
    }
}
