<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\KnativeUninstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class UninstallKnativeJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $component = 'all', // 'all' | 'operator' | 'serving'
        ?string $initiatorId = null,
        public bool $removeTraefik = false, // remove Traefik even if it serves other Ingresses (console)
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(KnativeUninstallService $installer): void
    {
        match ($this->component) {
            'operator' => $installer->uninstallOperator($this->region),
            'serving' => $installer->uninstallServing($this->region, $this->removeTraefik),
            default => $installer->uninstall($this->region, $this->removeTraefik),
        };

        $this->broadcastSuccess("knative.{$this->component}.uninstalled", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'component' => $this->component,
        ], "Knative {$this->component} removed from {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $this->region->refresh();
        $time = now()->format('H:i:s');
        $errorLine = "[{$time}] ERROR: ".($e?->getMessage() ?? 'Unknown error')."\n";

        if ($this->component === 'all') {
            $log = $this->region->knative_status_message ?? '';
            $log .= $errorLine;
            $this->region->update([
                'knative_status' => 'error',
                'knative_status_message' => $log,
                'knative_status_checked_at' => now(),
            ]);
        } else {
            $field = "{$this->component}_status_message";
            $log = $this->region->$field ?? '';
            $log .= $errorLine;
            $this->region->update([
                "{$this->component}_status" => 'error',
                $field => $log,
                'knative_status_checked_at' => now(),
            ]);
        }

        $this->broadcastFailure("knative.{$this->component}.uninstall_failed", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'component' => $this->component,
        ], $e, "Knative {$this->component} uninstall failed on {$this->region->name}");
    }
}
