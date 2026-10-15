<?php

namespace App\Jobs\Ai;

use App\Events\PlatformEvent;
use App\Models\AiModelSnapshot;
use App\Services\Ai\Models\ModelServiceDeployer;
use App\Services\Ai\Models\SnapshotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Poll a mirror / import Job until it completes (every 15 s, up to the Job's deadline + 10 min);
 * a ready snapshot deploys the offline services that waited for it. A Job waiting for the model
 * volume keeps the snapshot pending (SnapshotService::check) and is polled on. $startedAt ties the
 * poll to one start of the snapshot: after a restart only the new start's poll goes on.
 */
class CheckModelSnapshotJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const POLL_SECONDS = 15;

    public int $timeout = 60;

    public int $tries = 1;

    /** The start this poll belongs to (a property default: polls queued before it existed carry none). */
    public ?string $startedAt = null;

    public function __construct(public string $snapshotId, public int $attempt = 1, ?string $startedAt = null)
    {
        $this->startedAt = $startedAt;
    }

    public function handle(SnapshotService $snapshots, ModelServiceDeployer $deployer): void
    {
        $snapshot = AiModelSnapshot::with('model', 'region')->find($this->snapshotId);
        if (! $snapshot || ! self::polled($snapshot)) {
            return;
        }
        if ($this->startedAt !== null && self::startToken($snapshot) !== $this->startedAt) {
            return; // restarted: the new start polls on its own
        }

        try {
            $done = $snapshots->check($snapshot);
        } catch (\Throwable) {
            $done = false;
        }

        if (! $done && $this->attempt >= self::maxAttempts()) {
            $snapshot->refresh();
            // A Job that waited for the model volume all along: the reason stays in the message
            $waited = $snapshot->status === AiModelSnapshot::STATUS_PENDING && $snapshot->message ? ' '.$snapshot->message : '';
            $snapshot->update(['status' => AiModelSnapshot::STATUS_FAILED, 'message' => mb_substr(__('ai.models.job_timeout').$waited, 0, 2000), 'completed_at' => now()]);
            $done = true;
        }

        if ($done) {
            $snapshot->refresh();
            $deployer->deployWaitingFor($snapshot);
            try {
                event(new PlatformEvent('model_snapshot.'.$snapshot->status, ['snapshot_id' => $snapshot->id, 'ai_model_id' => $snapshot->ai_model_id]));
            } catch (\Throwable) {
                // live update only
            }

            return;
        }

        self::dispatch($this->snapshotId, $this->attempt + 1, $this->startedAt)->delay(now()->addSeconds(self::POLL_SECONDS));
    }

    /** Running, or pending while its Job waits for the model volume (a queued start has no Job yet). */
    public static function polled(AiModelSnapshot $snapshot): bool
    {
        return $snapshot->status === AiModelSnapshot::STATUS_RUNNING
            || ($snapshot->status === AiModelSnapshot::STATUS_PENDING && $snapshot->job_name && $snapshot->started_at && ! $snapshot->completed_at);
    }

    /** The start a poll belongs to. */
    public static function startToken(AiModelSnapshot $snapshot): ?string
    {
        return $snapshot->started_at?->toIso8601String();
    }

    public static function maxAttempts(): int
    {
        return intdiv((int) config('ai.models.mirror_deadline_seconds', 7200) + 600, self::POLL_SECONDS);
    }
}
