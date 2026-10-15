<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\SupplyChain\AdmissionPolicyService;
use App\Services\SupplyChain\SigningKeyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generates (or rotates) a region's cosign key pair in the region (SigningKeyService), then
 * updates the admission policy's trusted keys when it is installed.
 */
class GenerateSigningKeyJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, Queueable, SerializesModels;

    public int $timeout = 360;

    public int $tries = 1;

    public function __construct(public KnativeRegion $region, public bool $rotate = false, ?string $initiatorId = null)
    {
        $this->initiatorId = $initiatorId;
    }

    public function handle(SigningKeyService $keys, AdmissionPolicyService $admission): void
    {
        $settings = $keys->runKeygen($this->region, $this->rotate);

        if ($settings->admission_status === 'ready') {
            try {
                $admission->syncPolicies($this->region);
            } catch (\Throwable $e) {
                Log::warning('Admission policy not updated after key change', ['region' => $this->region->name, 'error' => $e->getMessage()]);
            }
        }

        $this->broadcastSuccess('supply_chain.signing_key.ready', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
            'fingerprint' => $settings->signing_key_fingerprint,
        ], "Signing key ready on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        app(SigningKeyService::class)->markFailed($this->region, $e?->getMessage() ?? 'unknown error');

        $this->broadcastFailure('supply_chain.signing_key.failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $e ?? new \RuntimeException('unknown error'), "Signing key generation failed on {$this->region->name}");
    }
}
