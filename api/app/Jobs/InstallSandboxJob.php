<?php

namespace App\Jobs;

use App\Exceptions\SandboxAddonFailedException;
use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\SandboxInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InstallSandboxJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // gVisor: install wait (10 min) + node rollback of a failed first install (10 min);
    // Kata: kata-deploy on every node (up to 20 min) + RuntimeClasses
    public int $timeout = 1500;

    public int $tries = 1;

    public int $maxExceptions = 1;

    /**
     * @param  array{devmapper_pool_gb?: int}  $options  Kata install options (Firecracker thin-pool size)
     */
    public function __construct(
        public KnativeRegion $region,
        public string $runtime,
        public array $hypervisors = ['clh'],
        ?string $initiatorId = null,
        public array $options = [],
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(SandboxInstallService $service): void
    {
        try {
            match ($this->runtime) {
                'kata' => $service->installKata($this->region, $this->hypervisors, $this->options),
                'gvisor' => $service->installGvisor($this->region),
            };
        } catch (SandboxAddonFailedException $e) {
            // Kata stays installed (ready, previous hypervisors); the region's install log has the
            // reason — the job itself did what it could and is not a failure
            $this->broadcastSuccess('sandbox.kata.installed', [
                'region_id' => $this->region->id,
                'region_name' => $this->region->name,
                'runtime' => 'kata',
                'warning' => mb_substr($e->getMessage(), 0, 500),
            ], 'kata on '.$this->region->name.': '.(implode(', ', $e->notAdded) ?: 'change').' not added');

            return;
        }

        $this->broadcastSuccess("sandbox.{$this->runtime}.installed", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'runtime' => $this->runtime,
        ], "{$this->runtime} installed on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $field = $this->runtime === 'kata' ? 'sandbox_kata' : 'sandbox_gvisor';
        $time = now()->format('H:i:s');
        $error = 'ERROR: '.($e?->getMessage() ?? 'Unknown error');

        // The install's step log is kept; the error is its last line (SandboxInstallService has
        // usually written it already — not when the job itself failed, e.g. its timeout)
        $this->region->refresh();
        $log = (string) $this->region->{"{$field}_status_message"};
        if (! str_contains($log, $error)) {
            $log .= "[{$time}] {$error}\n";
        }
        $this->region->update([
            "{$field}_status" => 'error',
            "{$field}_status_message" => $log,
        ]);

        $this->broadcastFailure("sandbox.{$this->runtime}.install_failed", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'runtime' => $this->runtime,
        ], $e, "{$this->runtime} install failed on {$this->region->name}");
    }
}
