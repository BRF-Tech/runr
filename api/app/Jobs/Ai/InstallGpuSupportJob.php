<?php

namespace App\Jobs\Ai;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\Ai\Gpu\GpuInstallService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Install (or re-apply with new settings) the NVIDIA GPU support of a region: device plugin or
 * GPU Operator (GpuInstallService). $action: install | reconfigure | uninstall.
 */
class InstallGpuSupportJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, Queueable;

    // GPU Operator: driver build per node
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $regionId,
        public string $action,
        public ?string $mode = null,
        public array $options = [],
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(GpuInstallService $service): void
    {
        $region = KnativeRegion::syncedQuery()->find($this->regionId);
        if (! $region) {
            return;
        }

        match ($this->action) {
            'uninstall' => $service->uninstall($region),
            'reconfigure' => $service->reconfigure($region, $this->options),
            default => $service->install($region, (string) ($this->mode ?? 'device-plugin'), $this->options),
        };

        $this->broadcastSuccess("gpu.{$this->action}", [
            'region_id' => $region->id,
            'region_name' => $region->name,
        ], "GPU support {$this->action} on {$region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $region = KnativeRegion::syncedQuery()->find($this->regionId);
        if ($region && in_array($region->gpu_status, ['installing', 'uninstalling'], true)) {
            $region->update([
                'gpu_status' => 'error',
                'gpu_status_message' => (string) $region->gpu_status_message.'['.now()->format('H:i:s').'] ERROR: '.($e?->getMessage() ?? 'unknown error')."\n",
            ]);
        }

        if ($region) {
            $this->broadcastFailure("gpu.{$this->action}_failed", [
                'region_id' => $region->id,
                'region_name' => $region->name,
            ], $e ?? new \RuntimeException('unknown error'), "GPU support {$this->action} failed on {$region->name}");
        }
    }
}
