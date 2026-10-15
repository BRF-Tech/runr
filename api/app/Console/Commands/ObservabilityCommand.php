<?php

namespace App\Console\Commands;

use App\Models\KnativeRegion;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\RegionTracingConfig;
use App\Services\Observability\Alerts\AlertEvaluator;
use App\Services\Observability\Replay\ReplayService;
use App\Services\Observability\TenantScope;
use App\Services\Observability\TracingInstallService;
use App\Services\Observability\Uptime\UptimeService;
use Illuminate\Console\Command;

/**
 * Observability background work (scheduled by ObservabilityServiceProvider):
 *
 *   observability:run uptime          control-plane uptime checks that are due (every 30 s)
 *   observability:run alerts          error-rate / p95 / cold-start rules (every minute)
 *   observability:run tracing-status  installing → ready of region tracing backends (every minute)
 *   observability:run prune           expired replay captures, old uptime results, delivery log (daily)
 */
class ObservabilityCommand extends Command
{
    protected $signature = 'observability:run {task : uptime | alerts | tracing-status | prune}';

    protected $description = 'Run an observability background task (uptime checks, alert rules, tracing status, retention)';

    public function handle(UptimeService $uptime, AlertEvaluator $alerts, ReplayService $replay, TracingInstallService $tracing): int
    {
        switch ($this->argument('task')) {
            case 'uptime':
                $this->info('Uptime checks run: '.$uptime->runDue());
                break;
            case 'alerts':
                $this->info('Alert rule evaluations: '.$alerts->evaluateAll());
                break;
            case 'tracing-status':
                $this->refreshTracing($tracing);
                break;
            case 'prune':
                $deleted = $replay->prune() + $uptime->prune() + $this->pruneAlerts();
                $this->info("Pruned rows: {$deleted}");
                break;
            default:
                $this->error('Unknown task: '.$this->argument('task'));

                return self::INVALID;
        }

        return self::SUCCESS;
    }

    private function refreshTracing(TracingInstallService $tracing): void
    {
        $configs = RegionTracingConfig::query()->where('status', RegionTracingConfig::STATUS_INSTALLING)->get();

        foreach ($configs as $config) {
            TenantScope::run($config->tenant_id, function () use ($config, $tracing) {
                $region = app(KnativeRegion::class)->newQuery()->find($config->region_id);
                if (! $region) {
                    return;
                }
                try {
                    $status = $tracing->refreshStatus($region)->status;
                    $this->line("{$region->name}: {$status}");
                } catch (\Throwable $e) {
                    $this->warn("{$region->name}: {$e->getMessage()}");
                }
            });
        }
    }

    private function pruneAlerts(): int
    {
        $days = max(1, (int) config('observability.alerts.deliveries_retention_days', 30));
        $deleted = 0;
        TenantScope::each(function () use ($days, &$deleted) {
            $deleted += AlertDelivery::query()->where('created_at', '<', now()->subDays($days))->delete();
            $deleted += AlertEvent::query()->where('status', AlertEvent::RESOLVED)->where('resolved_at', '<', now()->subDays($days))->delete();
        });

        return $deleted;
    }
}
