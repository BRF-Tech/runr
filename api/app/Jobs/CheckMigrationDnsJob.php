<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\RegionMigrateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckMigrationDnsJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 1;

    public function __construct(
        public KnativeRegion $region,
    ) {}

    public function handle(RegionMigrateService $service): void
    {
        if ($this->region->migration_status !== 'dns_check') {
            return;
        }

        $completed = false;

        if ($service->checkMigrationDns($this->region)) {
            $service->completeMigration($this->region);
            Log::info("Migration DNS resolved for region {$this->region->name}");
            $completed = true;
        } elseif ($this->region->migration_started_at?->diffInHours(now()) >= 48) {
            // Auto-complete after 48 hours
            $service->completeMigration($this->region);
            Log::info("Migration auto-completed (48h) for region {$this->region->name}");
            $completed = true;
        }

        if ($completed) {
            $this->broadcastSuccess('region.migration_completed', [
                'region_id' => $this->region->id,
                'region_name' => $this->region->name,
            ], "Migration completed for {$this->region->name}");
        }
    }
}
