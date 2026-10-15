<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\KnativeInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InstallKnativeJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $component = 'all', // 'all' | 'operator' | 'serving'
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(KnativeInstallService $installer): void
    {
        match ($this->component) {
            'operator' => $installer->installOperator(
                $this->region,
                $this->region->knative_operator_namespace ?? 'knative-operator',
            ),
            'serving' => $installer->installServing(
                $this->region,
                $this->region->knative_serving_namespace ?? 'knative-serving',
                $this->region->knative_serving_version,
            ),
            default => $installer->install($this->region),
        };

        $this->broadcastSuccess("knative.{$this->component}.installed", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'component' => $this->component,
        ], "Knative {$this->component} installed on {$this->region->name}");
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
            $field = "knative_{$this->component}_status_message";
            $log = $this->region->$field ?? '';
            $log .= $errorLine;
            $this->region->update([
                "knative_{$this->component}_status" => 'error',
                $field => $log,
                'knative_status_checked_at' => now(),
            ]);
        }

        $this->broadcastFailure("knative.{$this->component}.install_failed", [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'component' => $this->component,
        ], $e, "Knative {$this->component} install failed on {$this->region->name}");
    }
}
