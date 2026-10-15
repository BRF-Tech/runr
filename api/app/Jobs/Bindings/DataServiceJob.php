<?php

namespace App\Jobs\Bindings;

use App\Models\KnativeRegion;
use App\Services\Bindings\DataServiceManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Install / uninstall a region data service (DataServiceManager job bodies; failures end in the
 * service row's status "error" with the message).
 */
class DataServiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Operator chart (helm --wait 10m) + shared cluster start */
    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $operation,
        public string $regionId,
        public string $service,
        public array $options = [],
        public ?string $initiatorId = null,
    ) {}

    public function handle(DataServiceManager $manager): void
    {
        $region = app(KnativeRegion::class)->newQuery()->find($this->regionId);
        if (! $region) {
            return;
        }

        $this->operation === 'uninstall'
            ? $manager->uninstall($region, $this->service)
            : $manager->install($region, $this->service, $this->options);
    }
}
