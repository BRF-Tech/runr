<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Services\KnativeStatusService;
use Illuminate\Console\Command;

class DiscoverExternalIpsCommand extends Command
{
    protected $signature = 'regions:discover-ips';

    protected $description = 'Refresh external IPs for all regions with serving ready';

    public function handle(KnativeStatusService $statusService): int
    {
        $regions = KnativeRegion::whereNotNull('kubeconfig')
            ->where('kubeconfig', '!=', '')
            ->where('is_demo', false)
            ->where('knative_serving_status', 'ready')
            ->get();

        if ($regions->isEmpty()) {
            $this->line('No regions with serving ready.');

            return self::SUCCESS;
        }

        foreach ($regions as $region) {
            $statusService->refreshExternalIp($region);
        }

        $this->line("Checked {$regions->count()} region(s).");

        return self::SUCCESS;
    }
}
