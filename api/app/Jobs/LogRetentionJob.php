<?php

namespace App\Jobs;

use App\Models\KnativeRegion;
use App\Services\LogRetentionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LogRetentionJob implements ShouldQueue
{
    use Queueable;

    public function handle(LogRetentionService $service): void
    {
        $regions = KnativeRegion::where('is_active', true)
            ->whereNotNull('kubeconfig')
            ->get();

        foreach ($regions as $region) {
            $service->enforceRetention($region);
        }
    }
}
