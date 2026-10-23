<?php

namespace Tests\Feature\Sandboxes;

use App\Jobs\Sandboxes\StartSandboxJob;
use App\Models\Sandbox;
use App\Models\SandboxPool;
use App\Services\Sandboxes\SandboxPoolService;
use App\Services\Sandboxes\SandboxService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * A pod whose sandbox the container runtime cannot create (a Kata VM that does not boot, a runtime
 * handler the node lacks) stays Pending with a waiting container: the kubelet retries for ever and
 * the pod status never says why. The start reads the pod's events: after
 * SandboxService::SANDBOX_CREATE_FAILURES FailedCreatePodSandBox the sandbox fails with the
 * runtime's error instead of staying pending until the start timeout, and a start that does time
 * out names the pod's last warning.
 */
class SandboxStartFailureTest extends SandboxTestCase
{
    private const KATA_ERROR = 'Failed to create pod sandbox: rpc error: code = Unknown desc = failed to create containerd task: failed to create shim task: QEMU exited';

    protected function setUp(): void
    {
        parent::setUp();
        // the events are read on every poll here (5 s apart in a real start)
        config(['sandbox.start_events_seconds' => 0]);
    }

    private function event(string $reason, string $message, int $count = 1, string $at = '2026-10-23T12:00:00Z', string $type = 'Warning'): array
    {
        return ['type' => $type, 'reason' => $reason, 'message' => $message, 'count' => $count, 'lastTimestamp' => $at];
    }

    /** A sandbox whose pod was created and is pending (no wait: the start is left to the job), with these pod events. */
    private function pendingSandbox(array $events): Sandbox
    {
        Queue::fake();
        $this->podStartState = 'pending';
        $id = $this->postJson('/api/sandboxes', ['wait' => false])->assertStatus(202)->json('data.id');
        $sandbox = Sandbox::findOrFail($id);
        $this->k8sPodEvents[$sandbox->pod_name] = $events;

        return $sandbox;
    }

    public function test_a_runtime_that_keeps_failing_to_create_the_pod_sandbox_fails_the_start(): void
    {
        $sandbox = $this->pendingSandbox([
            $this->event('Scheduled', 'Successfully assigned runr-sbx-default/sbx to node-1', 1, '2026-10-23T11:59:00Z', 'Normal'),
            $this->event('FailedCreatePodSandBox', 'Failed to create pod sandbox: rpc error: an older error', 2, '2026-10-23T11:59:30Z'),
            $this->event('FailedCreatePodSandBox', self::KATA_ERROR, 1, '2026-10-23T12:00:10Z'),
        ]);
        Sleep::fake();

        $state = app(SandboxService::class)->waitRunning($sandbox, $this->region, 60);

        // three failures counted, the latest error
        $this->assertSame(__('sandboxes.sandbox_create_failed', ['count' => 3, 'error' => self::KATA_ERROR]), $state);
    }

    public function test_the_api_answers_502_with_the_runtime_error(): void
    {
        Sleep::fake();
        $this->podStartState = 'pending';
        $this->newPodEvents = [$this->event('FailedCreatePodSandBox', self::KATA_ERROR, 3)];

        $response = $this->postJson('/api/sandboxes', ['region' => $this->region->id])
            ->assertStatus(502)
            ->assertJsonPath('reason', 'start_failed')
            ->assertJsonPath('data.status', 'failed');

        $this->assertStringContainsString('QEMU exited', (string) $response->json('message'));
        $this->assertStringContainsString('QEMU exited', (string) $response->json('data.error'));
        // the pod is gone, the sandbox no longer counts towards the organization's quota
        $this->assertNull($this->podOf((string) $response->json('data.id')));
        $this->assertSame(0, Sandbox::query()->whereIn('status', Sandbox::ACTIVE_STATUSES)->count());
    }

    public function test_one_or_two_failures_are_retried(): void
    {
        $sandbox = $this->pendingSandbox([
            $this->event('FailedCreatePodSandBox', 'Failed to create pod sandbox: plugin type="bridge" failed (add): connection refused', 2),
        ]);

        $this->assertNull(SandboxService::sandboxCreateFailure($this->k8sPodEvents[$sandbox->pod_name]));
        $this->assertNull(app(SandboxService::class)->waitRunning($sandbox, $this->region, 1));
    }

    public function test_repeats_of_one_event_count(): void
    {
        $this->assertNull(SandboxService::sandboxCreateFailure([]));
        $this->assertNull(SandboxService::sandboxCreateFailure([$this->event('FailedScheduling', '0/1 nodes are available', 9)]));
        $this->assertSame(
            __('sandboxes.sandbox_create_failed', ['count' => 4, 'error' => 'no runtime for "kata-qemu-runr" is configured']),
            SandboxService::sandboxCreateFailure([$this->event('FailedCreatePodSandBox', 'no runtime for "kata-qemu-runr" is configured', 4)]),
        );
        // events.k8s.io: the repeats are in series.count
        $this->assertNotNull(SandboxService::sandboxCreateFailure([['reason' => 'FailedCreatePodSandBox', 'message' => 'x', 'series' => ['count' => 3]]]));
    }

    public function test_the_background_start_fails_with_the_runtime_error(): void
    {
        $sandbox = $this->pendingSandbox([$this->event('FailedCreatePodSandBox', self::KATA_ERROR, 5)]);
        Sleep::fake();

        (new StartSandboxJob($sandbox->id))->handle(app(SandboxService::class));

        $sandbox->refresh();
        $this->assertSame(Sandbox::STATUS_FAILED, $sandbox->status);
        $this->assertSame(__('sandboxes.sandbox_create_failed', ['count' => 5, 'error' => self::KATA_ERROR]), $sandbox->error);
        $this->assertNull($this->podOf($sandbox->id));
    }

    public function test_a_start_that_times_out_names_the_last_pod_warning(): void
    {
        $sandbox = $this->pendingSandbox([
            $this->event('FailedScheduling', '0/1 nodes are available: 1 Insufficient memory.', 1, '2026-10-23T12:00:00Z'),
            $this->event('Scheduled', 'assigned', 1, '2026-10-23T12:00:05Z', 'Normal'),
        ]);
        config(['sandbox.start_timeout_seconds' => 0]);

        (new StartSandboxJob($sandbox->id))->handle(app(SandboxService::class));

        $sandbox->refresh();
        $this->assertSame(Sandbox::STATUS_FAILED, $sandbox->status);
        $this->assertSame(
            __('sandboxes.start_timeout').' '.__('sandboxes.start_timeout_event', ['reason' => 'FailedScheduling', 'message' => '0/1 nodes are available: 1 Insufficient memory.']),
            $sandbox->error,
        );
    }

    public function test_a_start_that_times_out_without_events_keeps_the_plain_error(): void
    {
        $sandbox = $this->pendingSandbox([]);
        config(['sandbox.start_timeout_seconds' => 0]);

        (new StartSandboxJob($sandbox->id))->handle(app(SandboxService::class));

        $this->assertSame(__('sandboxes.start_timeout'), $sandbox->fresh()->error);
    }

    public function test_a_warm_pod_the_runtime_cannot_create_is_replaced_with_the_reason(): void
    {
        Queue::fake();
        $this->podStartState = 'pending';
        $pool = SandboxPool::create([
            'tenant_id' => null,
            'knative_region_id' => $this->region->id,
            'runtime' => 'python',
            'runtime_version' => '3.14',
            'cpu_m' => 500,
            'memory_mb' => 512,
            'disk_mb' => 1024,
            'size' => 1,
            'is_enabled' => true,
        ]);
        $pools = app(SandboxPoolService::class);
        $pools->refill($pool->id);
        $member = Sandbox::where('pool_id', $pool->id)->firstOrFail();
        $this->assertSame(Sandbox::STATUS_WARMING, $member->status);

        // Next run: still pending, but the runtime failed to create its sandbox three times
        $this->k8sPodEvents[$member->pod_name] = [$this->event('FailedCreatePodSandBox', self::KATA_ERROR, 3)];
        $pools->refill($pool->id);

        $member->refresh();
        $this->assertSame(Sandbox::STATUS_FAILED, $member->status);
        $this->assertStringContainsString('QEMU exited', (string) $pool->fresh()->last_error);
        // a replacement is started right away
        $this->assertSame(1, Sandbox::where('pool_id', $pool->id)->where('status', Sandbox::STATUS_WARMING)->count());
    }
}
