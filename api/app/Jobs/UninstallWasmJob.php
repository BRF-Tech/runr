<?php

namespace App\Jobs;

use App\Exceptions\SandboxInUseException;
use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\Wasm\WasmInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Remove a region's WebAssembly support (WasmInstallService::uninstall()).
 */
class UninstallWasmJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // node cleanup waits up to 10 min (containerd / k3s restarts on the nodes)
    public int $timeout = 900;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(public KnativeRegion $region, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(WasmInstallService $service): void
    {
        $service->uninstall($this->region);

        $this->broadcastSuccess('wasm.uninstalled', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], "WebAssembly support removed from {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $this->region->refresh();

        // Refused before anything changed: the support stays installed (status set by the service)
        if (! $e instanceof SandboxInUseException && $this->region->sandbox_wasm_status === 'uninstalling') {
            $this->region->update([
                'sandbox_wasm_status' => 'error',
                'sandbox_wasm_status_message' => (string) $this->region->sandbox_wasm_status_message
                    .'['.now()->format('H:i:s').'] ERROR: '.($e?->getMessage() ?? 'Unknown error')."\n",
            ]);
        }

        $this->broadcastFailure('wasm.uninstall_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $e ?? new \RuntimeException('Unknown error'), "WebAssembly uninstall failed on {$this->region->name}");
    }
}
