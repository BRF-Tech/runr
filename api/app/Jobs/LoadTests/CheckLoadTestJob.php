<?php

namespace App\Jobs\LoadTests;

use App\Models\Observability\LoadTest;
use App\Services\Observability\LoadTest\LoadTestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Follows a running load test every LoadTestService::POLL_SECONDS until its k6 Job is done
 * (LoadTestService::poll collects the results) or past its deadline. The end goes to the starter
 * as load_test.completed / load_test.failed.
 */
class CheckLoadTestJob implements ShouldQueue
{
    use Dispatchable, NotifiesLoadTestEnd, Queueable;

    public int $timeout = 60;

    public int $tries = 1;

    /** The project (and its tests) was deleted while the check was queued: nothing to check */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public LoadTest $test, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(LoadTestService $service): void
    {
        $test = $this->test->fresh();
        if (! $test || $test->status !== LoadTest::RUNNING) {
            return;
        }

        if ($service->poll($test)) {
            $this->notifyEnd($test->refresh());

            return;
        }

        self::dispatch($test, $this->initiatorId)->delay(now()->addSeconds(LoadTestService::POLL_SECONDS));
    }

    /**
     * The check itself crashed (a region API that answered too slowly): checked again while the
     * test's deadline allows, failed after it — a test never stays running.
     */
    public function failed(?\Throwable $e): void
    {
        $test = $this->test->fresh();
        if (! $test || $test->status !== LoadTest::RUNNING) {
            return;
        }

        if (now()->lessThan($test->deadline()->addSeconds(60))) {
            Log::warning('Load test check did not finish, checking again', ['load_test' => $test->id, 'error' => $e?->getMessage()]);
            self::dispatch($test, $this->initiatorId)->delay(now()->addSeconds(LoadTestService::POLL_SECONDS));

            return;
        }

        app(LoadTestService::class)->fail($test, __('observability.load_test.check_failed', ['error' => mb_substr((string) $e?->getMessage(), 0, 500)]));
        $this->notifyEnd($test->refresh());
    }
}
