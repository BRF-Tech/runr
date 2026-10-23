<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsJobCompletion;
use App\Models\KnativeRegion;
use App\Services\CertManager\CertManagerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * cert-manager of a region in the background (CertManagerService):
 *  - install    install or reuse cert-manager, then the default issuer (POST …/cert-manager)
 *  - refresh    an "installing" region whose cert-manager runs now gets its issuer (queueRefresh())
 *  - issuer     choose the issuer again after its settings changed (PUT …/settings)
 *  - uninstall  stop using it; RunR's own parts go where nothing else uses them (DELETE …/cert-manager)
 */
class InstallCertManagerJob implements ShouldQueue
{
    use BroadcastsJobCompletion, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INSTALL = 'install';

    public const ISSUER = 'issuer';

    public const REFRESH = 'refresh';

    public const UNINSTALL = 'uninstall';

    // the wait for the Deployments (CertManagerService::WAIT_SECONDS) and the issuer, with room
    public int $timeout = 600;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  string|null  $previousStatus  the region's cert-manager status before an uninstall
     *                                       marked it "uninstalling" (a failed removal keeps it)
     */
    public function __construct(
        public KnativeRegion $region,
        public string $action = self::INSTALL,
        ?string $initiatorId = null,
        public ?string $previousStatus = null,
    ) {
        $this->initiatorId = $initiatorId;
    }

    public function handle(CertManagerService $service): void
    {
        // one cert-manager job per region at a time: a second install queued meanwhile does nothing
        $lock = Cache::lock('cert-manager:'.$this->region->id, $this->timeout);
        if (! $lock->get()) {
            Log::info('cert-manager job skipped: another one runs for the region', ['region' => $this->region->name, 'action' => $this->action]);

            return;
        }

        try {
            $this->run($service);
        } finally {
            $lock->release();
        }
    }

    private function run(CertManagerService $service): void
    {
        $region = $this->region->fresh() ?? $this->region;

        if ($this->action === self::UNINSTALL) {
            $lines = $service->uninstall($region);
            $this->broadcastSuccess('cert_manager.uninstalled', $this->payload(), "cert-manager is no longer used by {$region->name}", implode(' ', $lines));

            return;
        }

        if ($this->action === self::ISSUER) {
            $service->applyIssuer($region);

            return;
        }

        if ($this->action === self::REFRESH) {
            $service->refresh($region);

            return;
        }

        $region = $service->install($region);
        $this->broadcastSuccess('cert_manager.installed', $this->payload() + ['status' => $region->cert_manager_status],
            "cert-manager on {$region->name}: {$region->cert_manager_status}");
    }

    public function failed(?\Throwable $e): void
    {
        $region = $this->region->fresh() ?? $this->region;
        $error = 'ERROR: '.($e?->getMessage() ?? 'Unknown error');
        $log = (string) $region->cert_manager_status_message;

        // the service has usually written the error already — not when the job itself failed (timeout)
        if (! str_contains($log, $error)) {
            $region->update(['cert_manager_status_message' => $log.'['.now()->format('H:i:s')."] {$error}\n"]);
        }
        if ($this->action === self::INSTALL && $region->cert_manager_status === CertManagerService::STATUS_INSTALLING) {
            $region->update(['cert_manager_status' => CertManagerService::STATUS_ERROR]);
        }
        if ($this->action === self::UNINSTALL && $region->cert_manager_status === CertManagerService::STATUS_UNINSTALLING) {
            $region->update(['cert_manager_status' => $this->previousStatus ?? CertManagerService::STATUS_ERROR]);
        }

        Log::warning('cert-manager job failed', ['region' => $region->name, 'action' => $this->action, 'error' => $e?->getMessage()]);
        $this->broadcastFailure("cert_manager.{$this->action}_failed", $this->payload(), $e ?? new \RuntimeException('Unknown error'),
            "cert-manager ({$this->action}) failed on {$region->name}");
    }

    private function payload(): array
    {
        return ['region_id' => $this->region->id, 'region_name' => $this->region->name, 'action' => $this->action];
    }
}
