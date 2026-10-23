<?php

namespace App\Jobs\GlobalDns;

use App\Models\KnativeRegion;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\PlatformContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Removes a region's PowerDNS server (DELETE /knative-regions/{id}/global-dns →
 * GlobalDnsServerService::remove). A failure leaves the server in status error with the reason;
 * disabling again retries.
 */
class RemoveGlobalDnsServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public string $regionId) {}

    public function handle(GlobalDnsServerService $servers): void
    {
        PlatformContext::run(function () use ($servers) {
            $region = KnativeRegion::query()->find($this->regionId);
            if ($region) {
                $servers->remove($region);
            }
        });
    }
}
