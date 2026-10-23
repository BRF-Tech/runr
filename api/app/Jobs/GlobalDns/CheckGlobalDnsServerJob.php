<?php

namespace App\Jobs\GlobalDns;

use App\Models\KnativeRegion;
use App\Models\RegionGlobalDns;
use App\Services\GlobalDns\GlobalDnsServerService;
use App\Services\GlobalDns\PlatformContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Follows a region's PowerDNS server until it is ready (GlobalDnsServerService::check): every
 * POLL_SECONDS, at most MAX_ATTEMPTS times (10 minutes — a load balancer that gives the DNS
 * Service no address by then never will: no public :53).
 */
class CheckGlobalDnsServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const POLL_SECONDS = 10;

    public const MAX_ATTEMPTS = 60;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct(public string $regionId, public int $attempt = 1) {}

    public function handle(GlobalDnsServerService $servers): void
    {
        PlatformContext::run(function () use ($servers) {
            $region = KnativeRegion::query()->find($this->regionId);
            $server = RegionGlobalDns::forRegion($this->regionId);
            if (! $region || ! $server || ! in_array($server->status, [RegionGlobalDns::STATUS_INSTALLING, RegionGlobalDns::STATUS_READY], true)) {
                return;
            }

            if (! $servers->check($region, $server, $this->attempt >= self::MAX_ATTEMPTS)) {
                self::dispatch($this->regionId, $this->attempt + 1)->delay(now()->addSeconds(self::POLL_SECONDS));
            }
        });
    }
}
