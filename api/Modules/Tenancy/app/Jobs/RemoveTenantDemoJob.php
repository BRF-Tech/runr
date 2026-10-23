<?php

namespace Modules\Tenancy\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Tenancy\Models\Tenant;

class RemoveTenantDemoJob implements ShouldQueue
{
    use BroadcastsJobCompletion;
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public Tenant $tenant, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(): void
    {
        // Demo regions + projects live in tenant schema
        $this->tenant->run(function () {
            $demoRegions = KnativeRegion::where('is_demo', true)
                ->where('is_synced', false)
                ->get();

            foreach ($demoRegions as $region) {
                foreach ($region->projects as $project) {
                    $project->allDeployments()->delete();
                    $project->versions()->delete();
                    $project->xaasConfig()?->delete();
                    $project->faasConfig()?->delete();
                    $project->containerConfig()?->delete();
                    $project->delete();
                }

                $region->delete();
            }
        });

        $this->tenant->update(['data' => array_merge($this->tenant->data ?? [], ['demo_status' => 'idle'])]);

        $this->broadcastSuccess('demo.removed', [
            'tenant_id' => $this->tenant->id,
        ], "Demo data removed for {$this->tenant->slug}");
    }

    public function failed(?\Throwable $e): void
    {
        $this->tenant->update(['data' => array_merge($this->tenant->data ?? [], ['demo_status' => 'error'])]);
        \Log::error("RemoveTenantDemoJob failed for {$this->tenant->slug}: ".$e?->getMessage());

        if ($e) {
            $this->broadcastFailure('demo.remove_failed', [
                'tenant_id' => $this->tenant->id,
            ], $e, "Demo removal failed for {$this->tenant->slug}");
        }
    }
}
