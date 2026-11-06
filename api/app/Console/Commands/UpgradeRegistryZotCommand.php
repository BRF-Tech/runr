<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Services\ZotRegistryService;
use Illuminate\Console\Command;

/**
 * Moves one region's internal registry to the Zot chart this release pins
 * (ZotRegistryService::upgrade()). Only by hand: it restarts the registry the region's nodes and
 * builds pull through, so region:reconcile leaves the version alone.
 */
class UpgradeRegistryZotCommand extends Command
{
    protected $signature = 'registry:upgrade-zot {regionId} {--dry-run : Show what would change and change nothing}';

    protected $description = "Upgrade one region's internal registry to the pinned Zot chart (restarts the registry)";

    public function handle(ZotRegistryService $zot): int
    {
        $region = KnativeRegion::syncedQuery()->whereKey($this->argument('regionId'))->first();
        if (! $region instanceof KnativeRegion) {
            $this->error('Region not found');

            return 1;
        }

        $this->info("Region: {$region->name}");

        try {
            $this->info('  '.$zot->upgrade($region, (bool) $this->option('dry-run')));
        } catch (\Throwable $e) {
            $this->error("  {$e->getMessage()}");

            return 1;
        }

        return 0;
    }
}
