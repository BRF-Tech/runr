<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Models\RegionSupplyChainSetting;
use App\Services\SupplyChain\ScannerDbMirrorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;

/**
 * Copies the Trivy DB into a region's registry (ScannerDbMirrorService): the first run creates
 * the Job, the following ones (every POLL_SECONDS) check it until it finished.
 */
class RefreshScannerDbJob implements ShouldQueue
{
    use Dispatchable, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public const POLL_SECONDS = 10;

    public const MAX_CHECKS = 150;

    public function __construct(public KnativeRegion $region, public int $check = 0) {}

    public function handle(ScannerDbMirrorService $mirror): void
    {
        if ($this->check === 0) {
            $mirror->submit($this->region);
        } elseif ($mirror->collect($this->region)) {
            return;
        }

        if ($this->check >= self::MAX_CHECKS) {
            $mirror->finish(RegionSupplyChainSetting::firstOrCreateFor($this->region), false, 'Scanner DB refresh timed out');

            return;
        }

        self::dispatch($this->region, $this->check + 1)->delay(now()->addSeconds(self::POLL_SECONDS));
    }

    public function failed(?\Throwable $e): void
    {
        app(ScannerDbMirrorService::class)->finish(
            RegionSupplyChainSetting::firstOrCreateFor($this->region),
            false,
            'Scanner DB refresh failed: '.($e?->getMessage() ?? 'unknown error'),
        );
    }
}
