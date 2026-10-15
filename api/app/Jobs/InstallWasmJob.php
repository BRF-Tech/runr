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
 * Install (or change) a region's WebAssembly support (WasmInstallService::install()).
 */
class InstallWasmJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // installer on every node (up to 15 min, runtime restarts one node at a time) + a rollback
    public int $timeout = 1700;

    public int $tries = 1;

    public int $maxExceptions = 1;

    /**
     * @param  list<string>  $shims
     * @param  list<string>|null  $nodes  null = every eligible node
     * @param  string|null  $previousStatus  the region's WebAssembly status before it was marked "installing"
     *                                       ("ready": a failed change keeps the working install)
     */
    public function __construct(
        public KnativeRegion $region,
        public array $shims = ['spin'],
        public ?array $nodes = null,
        ?string $initiatorId = null,
        public ?string $previousStatus = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(WasmInstallService $service): void
    {
        try {
            $service->install($this->region, $this->shims, $this->nodes, $this->previousStatus);
        } catch (SandboxInUseException $e) {
            // Refused before anything changed — the region's log says why
            $this->broadcastFailure('wasm.install_failed', $this->payload(), $e, "WebAssembly change refused on {$this->region->name}");

            return;
        }

        $this->broadcastSuccess('wasm.installed', $this->payload(), "WebAssembly support installed on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $this->region->refresh();
        $error = 'ERROR: '.($e?->getMessage() ?? 'Unknown error');
        $log = (string) $this->region->sandbox_wasm_status_message;

        // the service has usually written the error already — not when the job itself failed (timeout)
        if (! str_contains($log, $error)) {
            $log .= '['.now()->format('H:i:s')."] {$error}\n";
        }
        $attributes = ['sandbox_wasm_status_message' => $log];
        if ($this->region->sandbox_wasm_status === 'installing') {
            $attributes['sandbox_wasm_status'] = 'error';
        }
        $this->region->update($attributes);

        $this->broadcastFailure('wasm.install_failed', $this->payload(), $e ?? new \RuntimeException('Unknown error'),
            "WebAssembly install failed on {$this->region->name}");
    }

    private function payload(): array
    {
        return [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'shims' => $this->shims,
        ];
    }
}
