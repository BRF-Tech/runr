<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\SupplyChain\AdmissionPolicyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Installs (Helm + policies + namespace label) or removes the region's admission policy
 * (AdmissionPolicyService).
 */
class InstallAdmissionPolicyJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public KnativeRegion $region, public string $action = 'install', ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    /** install | uninstall | sync (policies of an installed controller follow changed settings) */
    public function handle(AdmissionPolicyService $admission): void
    {
        match ($this->action) {
            'uninstall' => $admission->uninstall($this->region),
            'sync' => $admission->syncPolicies($this->region),
            default => $admission->install($this->region),
        };

        $topic = match ($this->action) {
            'uninstall' => 'supply_chain.admission.removed',
            'sync' => 'supply_chain.admission.synced',
            default => 'supply_chain.admission.installed',
        };

        $this->broadcastSuccess($topic, [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $this->action === 'uninstall'
            ? "Admission policy removed from {$this->region->name}"
            : "Admission policy active on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        app(AdmissionPolicyService::class)->markFailed($this->region, $this->action, $e?->getMessage() ?? 'unknown error');

        $this->broadcastFailure('supply_chain.admission.failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'action' => $this->action,
        ], $e ?? new \RuntimeException('unknown error'), "Admission policy {$this->action} failed on {$this->region->name}");
    }
}
