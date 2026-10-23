<?php

namespace App\Providers;

use App\Models\Deployment;
use App\Models\ProjectEnvironment;
use App\Services\Helpers\ResourceReservation;
use App\Workflows\Console\WorkflowsPruneCommand;
use App\Workflows\Console\WorkflowsTickCommand;
use App\Workflows\Dispatch\QueueWorkflowDispatcher;
use App\Workflows\Dispatch\WorkflowDispatcher;
use App\Workflows\Engine\WorkflowException;
use App\Workflows\Jobs\SyncWorkflowsJob;
use App\Workflows\Support\WorkflowEnvironments;
use App\Workflows\Transport\HttpWorkflowTransport;
use App\Workflows\Transport\WorkflowTransport;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Durable workflows (docs/workflows.md): engine bindings, API routes, scheduled engine work and
 * the post-deploy definition sync. Authorization: the RBAC catalog (config/permissions.php).
 */
class WorkflowServiceProvider extends ServiceProvider
{
    /**
     * Permissions of the workflow API (config/permissions.php, group "workflows"; route rules in
     * config/route_permissions.php): workflows.view, workflows.run, workflows.manage, events.send.
     */
    public const PERMISSIONS = [
        'workflows.view',
        'workflows.run',
        'workflows.manage',
        'events.send',
    ];

    public function register(): void
    {
        $this->app->bind(WorkflowTransport::class, HttpWorkflowTransport::class);
        $this->app->bind(WorkflowDispatcher::class, QueueWorkflowDispatcher::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();
        $this->registerErrorRendering();
        $this->registerSchedule();
        $this->registerDeploySync();
        $this->registerEnvironmentCleanup();

        if ($this->app->runningInConsole()) {
            $this->commands([WorkflowsTickCommand::class, WorkflowsPruneCommand::class]);
        }
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('routes/workflows.php'));
    }

    /** WorkflowException → JSON {message, code} with its HTTP status. */
    protected function registerErrorRendering(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (method_exists($handler, 'renderable')) {
            $handler->renderable(fn (WorkflowException $e) => response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->status));
        }
    }

    protected function registerSchedule(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command(WorkflowsTickCommand::class)
                ->everyMinute()
                ->name('workflows-tick')
                ->withoutOverlapping(5);

            $schedule->command(WorkflowsPruneCommand::class)
                ->dailyAt('03:40')
                ->name('workflows-prune')
                ->withoutOverlapping(120);
        });
    }

    /**
     * A workflow-trigger project's deployment became active: sync the definitions of its
     * environment — production's for the project's own workload, an environment's for its
     * deployment (docs/workflows.md#environments).
     */
    protected function registerDeploySync(): void
    {
        Deployment::updated(function (Deployment $deployment) {
            if (! $deployment->wasChanged('status') || $deployment->status !== 'active') {
                return;
            }

            $project = $deployment->project;
            if (! $project || (ResourceReservation::configOf($project)?->trigger_type ?? null) !== 'workflow') {
                return;
            }

            SyncWorkflowsJob::dispatch($project->id, $deployment->isPrimary() ? null : (string) $deployment->environment_id)->delay(now()->addSeconds(5));
        });
    }

    /** An environment was deleted: its workflow app, definitions and runs go with it. */
    protected function registerEnvironmentCleanup(): void
    {
        ProjectEnvironment::deleted(function (ProjectEnvironment $environment) {
            try {
                WorkflowEnvironments::forget($environment);
            } catch (\Throwable $e) {
                Log::warning('Workflows of a deleted environment not removed', ['environment' => $environment->id, 'error' => $e->getMessage()]);
            }
        });
    }
}
