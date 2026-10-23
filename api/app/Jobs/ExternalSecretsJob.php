<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Services\Secrets\External\ExternalSecretsInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Install or remove External Secrets Operator on a region's cluster (docs/secret-stores.md,
 * ExternalSecretsInstallService). The install ends "installing"; the status sync moves it to
 * "ready" once the operator runs.
 */
class ExternalSecretsJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INSTALL = 'install';

    public const UNINSTALL = 'uninstall';

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $action = self::INSTALL,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(ExternalSecretsInstallService $service): void
    {
        if ($this->action === self::UNINSTALL) {
            $service->uninstall($this->region);
            $this->broadcastSuccess('external_secrets.uninstalled', $this->payload(), "External Secrets Operator removed from {$this->region->name}");

            return;
        }

        $service->install($this->region);
        $this->broadcastSuccess('external_secrets.installed', $this->payload(), "External Secrets Operator installed on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $settings = RegionSecretSetting::forRegion($this->region);
        if ($settings->esoBusy()) {
            $settings->fill([
                'eso_status' => RegionSecretSetting::ESO_ERROR,
                'eso_message' => mb_substr($e?->getMessage() ?? 'Unknown error', 0, 2000),
            ])->save();
        }

        $this->broadcastFailure("external_secrets.{$this->action}_failed", $this->payload(), $e ?? new \RuntimeException('Unknown error'),
            "External Secrets Operator {$this->action} failed on {$this->region->name}");
    }

    private function payload(): array
    {
        return ['region_id' => $this->region->id, 'region_name' => $this->region->name];
    }
}
