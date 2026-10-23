<?php

use App\Console\Commands\CheckKnativeStatusCommand;
use App\Console\Commands\CheckResourceThresholdsCommand;
use App\Console\Commands\CheckStaleBuildCommand;
use App\Console\Commands\PruneTokensCommand;
use App\Console\Commands\SyncExecutorImagesCommand;
use App\Console\Commands\SyncRuntimePatchesCommand;
use App\Console\Commands\SyncSecretStatusCommand;
use App\Jobs\AdvanceRolloutsJob;
use App\Jobs\ApplyWarmScalesJob;
use App\Jobs\CloseStaleSessionsJob;
use App\Jobs\DownsampleMetricsJob;
use App\Jobs\EnforceRuntimePatchesJob;
use App\Jobs\GlobalDns\SyncGlobalZoneJob;
use App\Jobs\GlobalProjects\GlobalProjectsMaintenanceJob;
use App\Jobs\LogRetentionJob;
use App\Jobs\Metering\UsageAggregationJob;
use App\Jobs\PruneAuditEventsJob;
use App\Jobs\PruneMetricSnapshotsJob;
use App\Jobs\PruneRegistryRepositoriesJob;
use App\Jobs\Rightsizing\RightsizingJob;
use App\Jobs\RuntimeEolRemindersJob;
use App\Jobs\SnapshotMetricsJob;
use App\Services\DomainMigrateService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
| withoutOverlapping(<minutes>): the overlap lock of a run whose scheduler pod was killed
| (rolling upgrade) must expire soon — with the default 24 h lock the task silently stopped
| for a day after every upgrade (e.g. the region status check).
*/

Schedule::command(SyncExecutorImagesCommand::class)
    ->everyThirtyMinutes()
    ->name('executor-image-sync')
    ->withoutOverlapping(60);

// Runtime patches (docs/runtimes.md#runtime-patches): the signed feed (a HEAD of its tag; the feed
// itself only when its digest changed), migrations and deadlines, end-of-life reminders
Schedule::command(SyncRuntimePatchesCommand::class)
    ->everyFiveMinutes()
    ->name('runtime-patch-feed')
    ->withoutOverlapping(10);

Schedule::job(new EnforceRuntimePatchesJob)
    ->everyFiveMinutes()
    ->name('runtime-patch-deadlines')
    ->withoutOverlapping(30);

Schedule::job(new RuntimeEolRemindersJob)
    ->dailyAt('09:00')
    ->name('runtime-eol-reminders')
    ->withoutOverlapping(60);

Schedule::command(CheckKnativeStatusCommand::class)
    ->everyMinute()
    ->name('knative-status-check')
    ->withoutOverlapping(10);

Schedule::command(CheckStaleBuildCommand::class)
    ->everyMinute()
    ->name('stale-build-check')
    ->withoutOverlapping(10);

Schedule::command(CheckResourceThresholdsCommand::class)
    ->everyMinute()
    ->name('resource-threshold-check')
    ->withoutOverlapping(10);

// Secret references (docs/secret-stores.md): what External Secrets Operator reports in the
// regions, onto the references and stores (central + tenant schemas)
Schedule::command(SyncSecretStatusCommand::class)
    ->everyTwoMinutes()
    ->name('secret-status-sync')
    ->withoutOverlapping(10);

Schedule::call(fn () => app(DomainMigrateService::class)->completeResolvedMigrations())
    ->hourly()
    ->name('domain-migration-auto-complete')
    ->withoutOverlapping(120);

// Timed warmers (docs/scaling.md#timed-warmer): each project's scheduled min-scale of this minute, in
// its organization's time zone, on the PodAutoscaler of its live revision (central + tenant schemas)
Schedule::job(new ApplyWarmScalesJob)
    ->everyMinute()
    ->name('timed-warmer')
    ->withoutOverlapping(5);

// Blue-green / canary rollouts whose next check was lost, or whose deployments ended
// (RolloutService::advanceOverdue; the checks themselves run as AdvanceRolloutJob at their time)
Schedule::job(new AdvanceRolloutsJob)
    ->everyMinute()
    ->name('deploy-rollouts')
    ->withoutOverlapping(10);

// Global DNS (docs/global-projects.md): the global zone written to every region's PowerDNS
// server, the DNS-01 challenges copied from the update server, drift repaired
Schedule::job(new SyncGlobalZoneJob)
    ->everyMinute()
    ->name('global-zone-sync')
    ->withoutOverlapping(10);

// Global projects: the regions' health and the automatic failover of a primary, the rejoin of a
// fenced region; the bindings' replica clusters and data links (docs/global-projects.md)
Schedule::job(new GlobalProjectsMaintenanceJob('failover'))
    ->everyMinute()
    ->name('global-projects-failover')
    ->withoutOverlapping(10);
Schedule::job(new GlobalProjectsMaintenanceJob('data'))
    ->everyMinute()
    ->name('global-projects-data')
    ->withoutOverlapping(10);

// Metrics persistence & downsampling
Schedule::job(new SnapshotMetricsJob)
    ->everyMinute()
    ->name('snapshot-metrics')
    ->withoutOverlapping(10);

Schedule::job(new DownsampleMetricsJob)
    ->hourly()
    ->name('downsample-metrics')
    ->withoutOverlapping(120);

// Metric snapshots past the 90-day retention, daily ones kept (central + tenant schemas)
Schedule::job(new PruneMetricSnapshotsJob)
    ->dailyAt('04:15')
    ->name('metric-snapshot-retention')
    ->withoutOverlapping(120);

// Metering (docs/monitoring.md): the day's sessions and reservations into usage_records, in the
// central context and in every tenant's — with or without billing (UsageAggregator)
Schedule::job(new UsageAggregationJob)
    ->everyFiveMinutes()
    ->name('usage-aggregation')
    ->withoutOverlapping(30);

// Rightsizing (docs/monitoring.md#rightsizing): yesterday's peaks of every running project from
// the regions' Prometheus, then the recommendations — after the first-hour metering of yesterday
Schedule::job(new RightsizingJob)
    ->dailyAt('02:10')
    ->name('rightsizing')
    ->withoutOverlapping(60);

// Deployment sessions whose end the region agent never reported (central + tenant schemas)
Schedule::job(new CloseStaleSessionsJob)
    ->everyTenMinutes()
    ->name('close-stale-sessions')
    ->withoutOverlapping(30);

Schedule::job(new LogRetentionJob)
    ->daily()
    ->name('log-retention-cleanup')
    ->withoutOverlapping();

// Build repositories without a tag in the regions' internal registries go to Zot's garbage collection
Schedule::job(new PruneRegistryRepositoriesJob)
    ->dailyAt('04:45')
    ->name('registry-empty-repositories')
    ->withoutOverlapping(120);

// Ended sign-in sessions and long-expired API tokens (config/auth.php login_token_ttl_days)
Schedule::command(PruneTokensCommand::class)
    ->dailyAt('03:15')
    ->name('token-prune')
    ->withoutOverlapping(60);

// Audit log retention (config/audit.php, setting audit_retention_days)
Schedule::job(new PruneAuditEventsJob)
    ->dailyAt('03:30')
    ->name('audit-retention-prune')
    ->withoutOverlapping(120);

// AI: GPU inventory of the regions, model service metering, expired model uploads (docs/ai.md)
Schedule::command('gpu:refresh')
    ->everyTwoMinutes()
    ->name('gpu-refresh')
    ->withoutOverlapping(10);

Schedule::command('models:meter')
    ->everyMinute()
    ->name('models-meter')
    ->withoutOverlapping(10);

Schedule::command('models:gc')
    ->hourly()
    ->name('models-gc')
    ->withoutOverlapping(60);
