<?php

namespace Tests\Feature\Sandboxes;

use App\Jobs\Sandboxes\StartSandboxJob;
use App\Models\Sandbox;
use App\Services\Sandboxes\SandboxService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * Create → running was rounded up by the pod poll: 0.2 s growing ×1.5 up to 1 s, about half a
 * second on average. The poll is a constant 150 ms (bounded to 100–200 ms) for the first 30 s,
 * then 1 s; the start_timeout still ends the wait.
 */
class SandboxStartPollTest extends SandboxTestCase
{
    /** A sandbox whose pod was created and is pending (the start is left to the job). */
    private function pendingSandbox(): Sandbox
    {
        Queue::fake();
        $this->podStartState = 'pending';
        $id = $this->postJson('/api/sandboxes', ['wait' => false])->assertStatus(202)->json('data.id');

        return Sandbox::findOrFail($id);
    }

    private function podReadsOf(Sandbox $sandbox): int
    {
        return collect($this->k8sCalls)->filter(fn ($c) => $c === "GET /api/v1/namespaces/{$sandbox->namespace}/pods/{$sandbox->pod_name}")->count();
    }

    public function test_a_start_is_seen_one_short_interval_after_the_pod_runs(): void
    {
        Sleep::fake();
        $this->podStartState = 'pending';
        $this->podRunsAfterReads = 3;

        $data = $this->createSandbox();

        $this->assertSame('running', $data['status']);
        // Three reads of the pending pod, 150 ms apart, the fourth sees it run (the growing poll
        // slept 0.2 + 0.3 + 0.45 s here)
        Sleep::assertSequence([Sleep::usleep(150_000), Sleep::usleep(150_000), Sleep::usleep(150_000)]);
    }

    public function test_the_background_start_polls_the_same_way(): void
    {
        $sandbox = $this->pendingSandbox();
        Sleep::fake();
        $this->podRunsAfterReads = 2;

        (new StartSandboxJob($sandbox->id))->handle(app(SandboxService::class));

        $this->assertSame(Sandbox::STATUS_RUNNING, $sandbox->fresh()->status);
        Sleep::assertSequence([Sleep::usleep(150_000), Sleep::usleep(150_000)]);
    }

    public function test_the_interval_is_constant_and_bounded_then_one_second(): void
    {
        $this->assertSame(150_000, SandboxService::startPollDelayUs(0));
        $this->assertSame(150_000, SandboxService::startPollDelayUs(199));
        // 30 s of 150 ms reads, then every second
        $this->assertSame(1_000_000, SandboxService::startPollDelayUs(200));

        config(['sandbox.start_poll_ms' => 20]);
        $this->assertSame(100_000, SandboxService::startPollDelayUs(0));
        config(['sandbox.start_poll_ms' => 5000]);
        $this->assertSame(200_000, SandboxService::startPollDelayUs(0));
        $this->assertSame(1_000_000, SandboxService::startPollDelayUs(150));
        config(['sandbox.start_poll_fast_seconds' => 0]);
        $this->assertSame(1_000_000, SandboxService::startPollDelayUs(0));
    }

    public function test_the_wait_still_ends_at_its_timeout(): void
    {
        $sandbox = $this->pendingSandbox();
        $this->k8sCalls = [];

        $start = microtime(true);
        $state = app(SandboxService::class)->waitRunning($sandbox, $this->region, 1);
        $elapsed = microtime(true) - $start;

        $this->assertNull($state);
        $this->assertGreaterThanOrEqual(1.0, $elapsed);
        $this->assertLessThan(1.5, $elapsed);
        // Every ~150 ms within the second
        $this->assertGreaterThanOrEqual(6, $this->podReadsOf($sandbox));
        $this->assertLessThanOrEqual(9, $this->podReadsOf($sandbox));
    }
}
