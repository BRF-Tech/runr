<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\RegionRegistrySetup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The image registry of an existing region (POST /knative-regions/{id}/registry, or a region
 * created with POST /knative-regions and registry fields): RegionRegistrySetup, then the region's
 * registry columns. registry_status: installing → ready, or error with the failed step's message.
 *
 * Encrypted queue payload: carries registry / S3 credentials (docs/security.md).
 */
class InstallRegionRegistryJob implements ShouldBeEncrypted, ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  array<string, mixed>  $config  the RegistrySetupRequest registry fields
     */
    public function __construct(
        public KnativeRegion $region,
        private array $config,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(RegionRegistrySetup $setup): void
    {
        $region = $this->region;
        $progress = function (string $phase) use ($region): void {
            $region->update(['registry_status_message' => self::PHASES[$phase] ?? $phase]);
        };

        $result = $setup->run($region->kubeconfig, $this->config, $region->selected_workers ?? [], $progress);

        if (! $result['ok']) {
            $region->update(['registry_status' => 'error', 'registry_status_message' => $result['message']]);
            $this->broadcastFailure('registry.install_failed', [
                'region_id' => $region->id,
                'region_name' => $region->name,
            ], new \RuntimeException($result['message']), "Registry of {$region->name} not set up");

            return;
        }

        $region->update(RegionRegistrySetup::regionAttributes($this->config, $result['address']));

        $this->broadcastSuccess('registry.installed', [
            'region_id' => $region->id,
            'region_name' => $region->name,
        ], "Registry of {$region->name} ready");
    }

    public function failed(?\Throwable $e): void
    {
        $this->region->refresh();
        $this->region->update([
            'registry_status' => 'error',
            'registry_status_message' => 'Registry setup did not finish: '.($e?->getMessage() ?? 'unknown error'),
        ]);

        $this->broadcastFailure('registry.install_failed', [
            'region_id' => $this->region->id,
            'region_name' => $this->region->name,
        ], $e ?? new \RuntimeException('unknown error'), "Registry of {$this->region->name} not set up");
    }

    private const PHASES = [
        'pvc_test' => 'Testing PVC provisioning...',
        's3_test' => 'Testing S3 connection...',
        'registry_test' => 'Testing registry connection...',
        'zot_install' => 'Installing Zot registry...',
    ];
}
