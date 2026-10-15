<?php

namespace App\Events;

use App\Models\KnativeRegion;
use App\Services\KubernetesClient;
use Illuminate\Foundation\Events\Dispatchable;

class RegionDomainsConfigured
{
    use Dispatchable;

    public function __construct(
        public readonly KnativeRegion $region,
        public readonly KubernetesClient $k8s,
        public readonly string $servingNs,
    ) {}
}
