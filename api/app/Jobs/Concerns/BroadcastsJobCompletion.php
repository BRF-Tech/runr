<?php

namespace App\Jobs\Concerns;

use App\Events\NotificationCreated;
use App\Events\PlatformEvent;
use App\Models\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Adds broadcast + notification creation to queue jobs.
 *
 * Usage:
 *   1. `use BroadcastsJobCompletion;`
 *   2. Add `?string $initiatorId = null` to constructor, assign `$this->initiatorId = $initiatorId`
 *   3. Call `$this->broadcastSuccess(topic, payload, title)` at end of handle()
 *   4. Call `$this->broadcastFailure(topic, payload, $e, title)` in failed()
 */
trait BroadcastsJobCompletion
{
    public ?string $initiatorId = null;

    /**
     * Broadcast a success event and create a notification for the initiator.
     */
    protected function broadcastSuccess(string $topic, array $payload, string $title): void
    {
        $this->broadcastSafely($topic, $payload);
        $this->createJobNotification($topic, 'info', $title, $payload);
    }

    /**
     * Broadcast a failure event and create a critical notification for the initiator.
     */
    protected function broadcastFailure(string $topic, array $payload, \Throwable $e, string $title): void
    {
        $payload['error'] = mb_substr($e->getMessage(), 0, 500);
        $this->broadcastSafely($topic, $payload);
        $this->createJobNotification($topic, 'critical', $title, $payload);
    }

    /**
     * The job's work is done (or its failure recorded) either way: a broadcast that cannot reach
     * Reverb (e.g. restarting during a RunR upgrade) must not turn it into a failed job.
     */
    private function broadcastSafely(string $topic, array $payload): void
    {
        try {
            event(new PlatformEvent($topic, $payload));
        } catch (\Throwable $e) {
            Log::warning('Job completion not broadcast', ['topic' => $topic, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Create a Notification record and broadcast it if we have an initiator.
     */
    private function createJobNotification(string $type, string $level, string $title, array $data): void
    {
        if (! $this->initiatorId) {
            return;
        }

        $notification = Notification::create([
            'user_id' => $this->initiatorId,
            'type' => $type,
            'level' => $level,
            'title' => $title,
            'data' => $data,
        ]);

        // stored above; the live push is best effort (see broadcastSafely())
        try {
            NotificationCreated::dispatch($notification);
        } catch (\Throwable $e) {
            Log::warning('Job notification not broadcast', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }
}
