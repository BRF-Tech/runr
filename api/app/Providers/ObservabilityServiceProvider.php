<?php

namespace App\Providers;

use App\Events\NotificationCreated;
use App\Models\Observability\AlertEvent;
use App\Models\Observability\AlertRule;
use App\Models\Observability\ProjectObservabilitySetting;
use App\Models\Observability\ReplayCapture;
use App\Models\Observability\UptimeCheck;
use App\Models\Observability\UptimeIncident;
use App\Models\Observability\UptimeResult;
use App\Models\Project;
use App\Services\Observability\Alerts\AlertEvaluator;
use App\Services\Observability\Alerts\Channels\ChannelRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Observability v2 (docs/observability.md): routes (routes/observability.php), the ingest rate
 * limiter, the schedule of the background tasks (observability:run), the resource-threshold →
 * alert channel bridge and the cleanup of a deleted project's rows.
 *
 * Permissions are catalog entries (config/permissions.php): traces.view, replay.manage,
 * uptime.manage (project scope), alerts.manage, status_page.manage, regions.observability.manage
 * (organization scope) — config/route_permissions.php maps every route.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelRegistry::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/observability.php'));

        RateLimiter::for('observability-ingest', fn (Request $request) => Limit::perMinute(1200)->by($request->ip()));

        $this->registerSchedule();
        $this->registerResourceThresholdBridge();
        $this->registerProjectCleanup();
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('observability:run uptime')
                ->everyThirtySeconds()
                ->name('observability-uptime')
                ->withoutOverlapping(5);

            $schedule->command('observability:run alerts')
                ->everyMinute()
                ->name('observability-alerts')
                ->withoutOverlapping(10);

            $schedule->command('observability:run tracing-status')
                ->everyMinute()
                ->name('observability-tracing-status')
                ->withoutOverlapping(10);

            $schedule->command('observability:run prune')
                ->daily()
                ->name('observability-prune')
                ->withoutOverlapping(120);
        });
    }

    /** ResourceThresholdService's region CPU / memory notifications → resource_threshold rules. */
    private function registerResourceThresholdBridge(): void
    {
        Event::listen(NotificationCreated::class, function (NotificationCreated $event) {
            if ($event->notification->type !== 'resource_threshold') {
                return;
            }
            try {
                app(AlertEvaluator::class)->resourceThreshold($event->notification);
            } catch (\Throwable $e) {
                Log::warning('Resource threshold alert routing failed', ['error' => $e->getMessage()]);
            }
        });
    }

    /** A deleted project takes its settings, captures, checks, results, incidents and rules along. */
    private function registerProjectCleanup(): void
    {
        Project::deleted(function (Project $project) {
            try {
                $id = $project->getKey();
                $checkIds = UptimeCheck::query()->where('project_id', $id)->pluck('id');

                UptimeResult::query()->whereIn('uptime_check_id', $checkIds)->delete();
                UptimeIncident::query()->where('project_id', $id)->delete();
                UptimeCheck::query()->where('project_id', $id)->delete();
                ReplayCapture::query()->where('project_id', $id)->delete();
                ProjectObservabilitySetting::query()->where('project_id', $id)->delete();

                $ruleIds = AlertRule::query()->where('project_id', $id)->pluck('id');
                AlertEvent::query()->whereIn('alert_rule_id', $ruleIds)->where('status', AlertEvent::FIRING)
                    ->update(['status' => AlertEvent::RESOLVED, 'resolved_at' => now()]);
                AlertRule::query()->whereIn('id', $ruleIds)->delete();
            } catch (\Throwable $e) {
                Log::warning('Observability cleanup of a deleted project failed', ['project' => $project->getKey(), 'error' => $e->getMessage()]);
            }
        });
    }
}
