<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * A scheduler pod killed during a run (rolling upgrade) leaves the task's overlap lock behind.
 * With Laravel's default 24 h lock expiry the task silently stopped for a day — the region
 * status check did so after the 0.3.4 upgrade. Frequent tasks must use a short expiry.
 */
class ScheduleOverlapLockTest extends TestCase
{
    public function test_frequent_tasks_have_short_overlap_locks(): void
    {
        $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => $e->description);

        $maxMinutes = [
            'knative-status-check' => 10,
            'stale-build-check' => 10,
            'resource-threshold-check' => 10,
            'snapshot-metrics' => 10,
            'executor-image-sync' => 60,
            'domain-migration-auto-complete' => 120,
            'downsample-metrics' => 120,
        ];

        foreach ($maxMinutes as $name => $max) {
            $this->assertTrue($events->has($name), "scheduled task {$name} missing");
            $event = $events[$name];
            $this->assertTrue($event->withoutOverlapping, "{$name} must not overlap");
            $this->assertLessThanOrEqual($max, $event->expiresAt, "{$name} overlap lock expires after {$event->expiresAt} min");
        }
    }
}
