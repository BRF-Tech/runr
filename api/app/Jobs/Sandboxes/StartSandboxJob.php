<?php

namespace App\Jobs\Sandboxes;

use App\Models\Sandbox;
use App\Services\Sandboxes\SandboxContext;
use App\Services\Sandboxes\SandboxException;
use App\Services\Sandboxes\SandboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finishes the start of a sandbox in the background (create with wait: false, or a start that took
 * longer than the request's start_timeout): waits for the pod, writes env + files, marks it running —
 * or failed with the pod's reason. start_ms = the request's share ($elapsedMs) + dispatch → running.
 */
class StartSandboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public float $dispatchedAt;

    public function __construct(
        public string $sandboxId,
        public int $elapsedMs = 0,
    ) {
        $this->dispatchedAt = microtime(true);
    }

    public function handle(SandboxService $service): void
    {
        $sandbox = Sandbox::find($this->sandboxId);
        if (! $sandbox || $sandbox->status !== Sandbox::STATUS_PENDING) {
            return;
        }

        SandboxContext::run($sandbox->tenant_id, function () use ($service, $sandbox) {
            $region = $service->regionOf($sandbox);
            $state = $service->waitRunning($sandbox, $region, (int) config('sandbox.start_timeout_seconds', 120) * 3);

            if ($state !== true) {
                $service->markFailed($sandbox, $region, is_string($state) ? $state : __('sandboxes.start_timeout'));

                return;
            }

            try {
                $elapsed = $this->elapsedMs + (int) round((microtime(true) - $this->dispatchedAt) * 1000);
                $service->finishStart($sandbox, $region, null, $elapsed);
            } catch (SandboxException $e) {
                $service->markFailed($sandbox, $region, $e->getMessage());
            }
        });
    }
}
