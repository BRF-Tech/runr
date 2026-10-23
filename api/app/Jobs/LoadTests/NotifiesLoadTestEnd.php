<?php

namespace App\Jobs\LoadTests;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\Observability\LoadTest;

/**
 * The end of a load test for the one who started it: load_test.completed (with p95 and requests
 * per second) or load_test.failed (with the reason), broadcast and as a notification.
 */
trait NotifiesLoadTestEnd
{
    use BroadcastsJobCompletion;

    protected function notifyEnd(LoadTest $test): void
    {
        $project = $test->project;
        $payload = ['project_id' => $test->project_id, 'project_name' => $project?->name, 'load_test' => $test->toApi(false)];

        if ($test->status === LoadTest::COMPLETED) {
            $results = $test->results ?? [];
            $this->broadcastSuccess('load_test.completed', $payload, __('observability.load_test.notify_completed', ['project' => (string) $project?->name]), __('observability.load_test.notify_completed_body', [
                'p95' => $results['latency_ms']['p95'] ?? '—',
                'rps' => $results['rps'] ?? '—',
                'errors' => isset($results['error_rate']) ? round(((float) $results['error_rate']) * 100, 2) : '—',
            ]));

            return;
        }

        if ($test->status === LoadTest::FAILED) {
            $this->broadcastFailure('load_test.failed', $payload, new \RuntimeException((string) $test->error), __('observability.load_test.notify_failed', ['project' => (string) $project?->name]), $test->error);
        }
    }
}
