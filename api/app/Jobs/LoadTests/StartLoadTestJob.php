<?php

namespace App\Jobs\LoadTests;

use App\Models\Observability\LoadTest;
use App\Services\Observability\LoadTest\LoadTestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Starts a load test's k6 Job in the project's region (LoadTestService::launch); CheckLoadTestJob
 * follows it from then on. A test the region refuses ends as failed with the reason, and its
 * starter gets the notification load_test.failed.
 */
class StartLoadTestJob implements ShouldQueue
{
    use Dispatchable, NotifiesLoadTestEnd, Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    /** The project (and its tests) was deleted while the start was queued: nothing to start */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public LoadTest $test, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(LoadTestService $service): void
    {
        $test = $this->test->fresh();
        if (! $test || $test->status !== LoadTest::PENDING) {
            return;
        }

        if (! $service->launch($test, $this->initiatorId) && $test->refresh()->status === LoadTest::FAILED) {
            $this->notifyEnd($test);
        }
    }

    /** The start itself crashed (e.g. the region's API did not answer in time). */
    public function failed(?\Throwable $e): void
    {
        $test = $this->test->fresh();
        if (! $test || ! $test->isActive()) {
            return;
        }

        app(LoadTestService::class)->fail($test, __('observability.load_test.start_failed', ['error' => mb_substr((string) $e?->getMessage(), 0, 500)]));
        $this->notifyEnd($test->refresh());
    }
}
