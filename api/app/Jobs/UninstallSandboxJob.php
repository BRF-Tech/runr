<?php

namespace App\Jobs;

use App\Exceptions\SandboxInUseException;
use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\SandboxInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UninstallSandboxJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // gVisor / Kata: node cleanup waits up to 10 min (containerd / k3s restarts on the nodes)
    public int $timeout = 900;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $runtime,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(SandboxInstallService $service): void
    {
        match ($this->runtime) {
            'kata' => $service->uninstallKata($this->region),
            'gvisor' => $service->uninstallGvisor($this->region),
        };

        $this->broadcastSuccess("sandbox.{$this->runtime}.uninstalled", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'runtime' => $this->runtime,
        ], "{$this->runtime} removed from {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $field = $this->runtime === 'kata' ? 'sandbox_kata' : 'sandbox_gvisor';
        $time = now()->format('H:i:s');

        $this->region->refresh();

        // Refused before anything changed: the runtime stays installed (status set by the service)
        if (! $e instanceof SandboxInUseException) {
            $this->region->update([
                "{$field}_status" => 'error',
                "{$field}_status_message" => "[{$time}] ERROR: ".($e?->getMessage() ?? 'Unknown error'),
            ]);
        }

        $this->broadcastFailure("sandbox.{$this->runtime}.uninstall_failed", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'runtime' => $this->runtime,
        ], $e, "{$this->runtime} uninstall failed on {$this->region->name}");
    }
}
