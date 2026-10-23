<?php

namespace App\Jobs;

use App\Models\DeploymentRollout;
use App\Services\Rollouts\RolloutService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The next look at a blue-green / canary rollout (RolloutService::advance): a health check, a
 * canary step's thresholds or its next step, the release of a kept previous revision. Queued for
 * the rollout's next_check_at; a job whose time the rollout no longer has (a later change set a
 * new one) does nothing — the newer job does it. AdvanceRolloutsJob catches a job that was lost.
 */
class AdvanceRolloutJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /** @param  int|null  $due  the next_check_at (Unix time) the job was queued for */
    public function __construct(
        public string $rolloutId,
        public ?int $due = null,
    ) {}

    /** Queues the look at the rollout's next_check_at. */
    public static function schedule(DeploymentRollout $rollout): void
    {
        if ($rollout->next_check_at === null) {
            return;
        }

        $delay = $rollout->next_check_at->isFuture() ? $rollout->next_check_at : now();
        self::dispatch((string) $rollout->getKey(), $rollout->next_check_at->getTimestamp())->delay($delay);
    }

    public function handle(RolloutService $rollouts): void
    {
        if (! DeploymentRollout::available()) {
            return;
        }

        $rollout = DeploymentRollout::query()->find($this->rolloutId);
        // the stored time has whole seconds: a second apart is the same check
        if (! $rollout || ($this->due !== null && ($rollout->next_check_at === null || abs($rollout->next_check_at->getTimestamp() - $this->due) > 1))) {
            return;
        }

        $rollouts->advance($rollout);
    }
}
