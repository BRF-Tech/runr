<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Models\RegionSecretSetting;
use App\Services\Secrets\OpenBao\OpenBaoInstallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Install or remove the region's OpenBao (OpenBaoInstallService): the install waits for the server,
 * initializes, unseals and configures it, and ends "ready".
 */
class OpenBaoJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INSTALL = 'install';

    public const UNINSTALL = 'uninstall';

    // the server's start (OpenBaoInstallService::WAIT_SECONDS) and the configuration
    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(
        public KnativeRegion $region,
        public string $action = self::INSTALL,
        ?string $initiatorId = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(OpenBaoInstallService $service): void
    {
        if ($this->action === self::UNINSTALL) {
            $service->uninstall($this->region);
            $this->broadcastSuccess('openbao.uninstalled', $this->payload(), "OpenBao removed from {$this->region->name}");

            return;
        }

        $service->install($this->region);
        $this->broadcastSuccess('openbao.installed', $this->payload(), "OpenBao installed on {$this->region->name}");
    }

    public function failed(?\Throwable $e): void
    {
        $settings = RegionSecretSetting::forRegion($this->region);
        if ($settings->openbaoBusy()) {
            $settings->fill([
                'openbao_status' => RegionSecretSetting::ESO_ERROR,
                'openbao_message' => mb_substr($e?->getMessage() ?? 'Unknown error', 0, 2000),
            ])->save();
        }

        $this->broadcastFailure("openbao.{$this->action}_failed", $this->payload(), $e ?? new \RuntimeException('Unknown error'),
            "OpenBao {$this->action} failed on {$this->region->name}");
    }

    private function payload(): array
    {
        return ['region_id' => $this->region->id, 'region_name' => $this->region->name];
    }
}
