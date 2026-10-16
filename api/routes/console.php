<?php

use App\Console\Commands\CheckKnativeStatusCommand;
use App\Console\Commands\CheckResourceThresholdsCommand;
use App\Console\Commands\CheckStaleBuildCommand;
use App\Console\Commands\PruneTokensCommand;
use App\Console\Commands\SyncExecutorImagesCommand;
use App\Jobs\CloseStaleSessionsJob;
use App\Jobs\DownsampleMetricsJob;
use App\Jobs\LogRetentionJob;
use App\Jobs\PruneAuditEventsJob;
use App\Jobs\PruneMetricSnapshotsJob;
use App\Jobs\PruneRegistryRepositoriesJob;
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

Schedule::call(fn () => app(DomainMigrateService::class)->autoCompleteExpiredMigrations())
    ->hourly()
    ->name('domain-migration-auto-complete')
    ->withoutOverlapping(120);

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
