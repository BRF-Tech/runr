<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\RegionMigrateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Encrypted queue payload: carries the new kubeconfig (docs/security.md) */
class MigrateRegionJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $newKubeconfig,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(RegionMigrateService $service): void
    {
        $service->migrate($this->region, $this->newKubeconfig);

        $this->broadcastSuccess('region.migrated', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], "Migration completed for {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        Log::error("MigrateRegionJob failed for region {$this->region->name}: ".$e?->getMessage());

        $this->region->update([
            'migration_status' => 'failed',
        ]);

        $this->broadcastFailure('region.migrate_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $e, "Migration failed for {$this->region->name}");
    }
}
