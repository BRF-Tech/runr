<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Models\Observability\RegionTracingConfig;
use App\Services\Observability\TracingInstallService;
use Illuminate\Console\Command;

/**
 * Install or reconfigure the tracing backend of existing regions (regions installed before
 * observability v2, or after a mode change):
 *
 *   php artisan cluster:install-tracing                 every active region with Knative Serving
 *   php artisan cluster:install-tracing <regionId>      one region
 *   php artisan cluster:install-tracing <id> --mode=off switch a region's tracing off
 */
class InstallTracingCommand extends Command
{
    protected $signature = 'cluster:install-tracing {regionId? : one region (default: every active region with Serving)}
        {--mode= : store this mode first (managed | reuse | external | off)}';

    protected $description = 'Install the OpenTelemetry Collector + Tempo tracing backend on regions';

    public function handle(TracingInstallService $service): int
    {
        $mode = $this->option('mode');
        if ($mode !== null && ! in_array($mode, RegionTracingConfig::MODES, true)) {
            $this->error('--mode must be one of '.implode(', ', RegionTracingConfig::MODES));

            return self::INVALID;
        }

        $query = KnativeRegion::syncedQuery()->where('is_demo', false)->whereNotNull('kubeconfig');
        $regions = $this->argument('regionId')
            ? $query->whereKey($this->argument('regionId'))->get()
            : $query->where('is_active', true)->where('knative_serving_status', 'ready')->get();

        if ($regions->isEmpty()) {
            $this->warn('No region to install.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($regions as $region) {
            if ($mode !== null) {
                $config = RegionTracingConfig::forRegion($region);
                $config->mode = $mode;
                $config->save();
            }

            $this->info("── {$region->name}");
            try {
                $config = $service->install($region, fn (string $m) => $this->line("   {$m}"));
                $this->line('   status: '.($config->status ?? 'off'));
            } catch (\Throwable $e) {
                $failed++;
                $this->error('   '.$e->getMessage());
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
