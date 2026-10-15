<?php

namespace App\Console\Commands;

use App\Services\RegionReconcile\RegionReconciler;
use Illuminate\Console\Command;

/**
 * Applies a release's region-side changes to regions that already exist (they are otherwise only
 * applied at region install). Idempotent — safe on every upgrade; the Helm bootstrap job runs it
 * after `agent:redeploy --missing`. Steps: RegionReconciler::steps().
 */
class ReconcileRegionsCommand extends Command
{
    protected $signature = 'region:reconcile
        {--region= : Only this region ID}
        {--dry-run : Show what would change without changing anything}';

    protected $description = 'Bring existing regions up to date with this release (KnativeServing config, Prometheus exposure, namespace quota, registry sync upstreams, ingress trust, Traefik image, Loki deletion)';

    public function handle(RegionReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->info('Dry run — nothing is changed.');
        }

        $failures = $reconciler->reconcile(
            $this->option('region') ?: null,
            $dryRun,
            function (string $level, string $message) {
                match ($level) {
                    'error' => $this->error($message),
                    'warn' => $this->warn($message),
                    default => $this->line($message),
                };
            },
        );

        if ($failures > 0) {
            $this->warn("{$failures} step(s) failed — see above; re-run: php artisan region:reconcile");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
