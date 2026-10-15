<?php

namespace App\Console\Commands;

use App\Services\Ai\Models\ModelUsageMeter;
use App\Services\Ai\Models\UploadService;
use Illuminate\Console\Command;

/**
 * Housekeeping of model files (scheduled hourly): uploads past their TTL are aborted and their
 * receiver pods removed — in every organization (tenant).
 */
class ModelsGcCommand extends Command
{
    protected $signature = 'models:gc';

    protected $description = 'Abort expired model uploads and remove their receivers';

    public function handle(ModelUsageMeter $organizations): int
    {
        $count = 0;
        $organizations->eachOrganization(function () use (&$count) {
            $count += app(UploadService::class)->expire();
        });

        $this->info("Expired {$count} upload(s).");

        return self::SUCCESS;
    }
}
