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

/**
 * POST /knative-regions/{id}/upgrade-serving: Knative Serving to the next minor version
 * (KnativeInstallService::upgradeServing()).
 */
class UpgradeKnativeServingJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $version,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(KnativeInstallService $installer): void
    {
        $installer->upgradeServing($this->region, $this->version);

        $this->broadcastSuccess('knative.serving.upgraded', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'version' => $this->version,
        ], "Knative Serving upgraded to {$this->version} on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $this->region->refresh();
        if ($this->region->knative_serving_status === 'installing') {
            $time = now()->format('H:i:s');
            $this->region->update([
                'knative_serving_status' => 'error',
                'knative_serving_status_message' => ($this->region->knative_serving_status_message ?? '')
                    ."[{$time}] ERROR: ".($e?->getMessage() ?? 'Unknown error')."\n",
                'knative_status_checked_at' => now(),
            ]);
        }

        $this->broadcastFailure('knative.serving.upgrade_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'version' => $this->version,
        ], $e, "Knative Serving upgrade to {$this->version} failed on {$this->region->name}");
    }
}
