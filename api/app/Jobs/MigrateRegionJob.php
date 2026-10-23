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

/**
 * The first part of a region move (RegionMigrateService::migrateInfrastructure(): the region on
 * the new cluster and everything it runs there besides the projects), then the second part as its
 * own job (MigrateRegionProjectsJob): each part has the queue's longest timeout (30 minutes, under
 * retry_after), which the whole move on a slow cluster can outgrow — Knative, the registry with its
 * image pre-pull, External Secrets Operator and OpenBao install and wait for their pods.
 *
 * Encrypted queue payload: carries the new kubeconfig (docs/security.md)
 */
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
        $service->migrateInfrastructure($this->region, $this->newKubeconfig);

        MigrateRegionProjectsJob::dispatch($this->region, $this->initiatorId);
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
