<?php

namespace App\Providers;

use App\Jobs\GitDeploy\CleanupPreviewsJob;
use App\Jobs\GitDeploy\MirrorEditorVersionJob;
use App\Models\Deployment;
use App\Models\Git\GitRepoLink;
use App\Models\Git\GitWebhookDelivery;
use App\Models\Git\PreviewEnvironment;
use App\Models\ImageSecurityReport;
use App\Models\Project;
use App\Models\Version;
use App\Services\GitDeploy\GitDeployService;
use App\Services\GitDeploy\GitTables;
use App\Services\GitDeploy\PreviewService;
use App\Services\GitDeploy\PreviewVersionScope;
use App\Services\GitDeploy\ProjectRemote;
use App\Services\GitDeploy\RepoConnector;
use App\Services\GitDeploy\TenantContext;
use App\Services\GitDeploy\VersionImporter;
use App\Services\Helpers\ResourceReservation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Tenancy\Services\TenantQuotaService;

/**
 * Git-native deployment (docs/git-deploy.md): routes (routes/git-deploy.php), model hooks that
 * tie versions, deployments and projects to git deploys, the hourly preview cleanup and the
 * organization-quota contribution of previews. The permissions git.pull, git.push, git.manage,
 * previews.manage and git_providers.manage are catalog permissions (config/permissions.php,
 * Gate abilities through RbacServiceProvider).
 */
class GitDeployServiceProvider extends ServiceProvider
{
    private static bool $quotaHooked = false;

    public function boot(): void
    {
        // Preview versions never show up in the project's own version queries
        Version::addGlobalScope(new PreviewVersionScope);

        $this->loadRoutesFrom(base_path('routes/git-deploy.php'));

        RateLimiter::for('git-webhooks', fn (Request $request) => Limit::perMinute(600)->by('git-webhook:'.$request->ip()));

        $this->registerModelHooks();
        $this->registerQuotaContribution();

        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->job(new CleanupPreviewsJob)
                ->hourly()
                ->name('git-preview-cleanup')
                ->withoutOverlapping(60);

            $this->app->make(Schedule::class)
                ->call(fn () => GitWebhookDelivery::where('created_at', '<', now()->subDays(30))->delete())
                ->daily()
                ->name('git-webhook-delivery-prune')
                ->withoutOverlapping(60);
        });
    }

    private function registerModelHooks(): void
    {
        // A version saved in the editor goes onto the deploy branch of the project remote
        Version::created(function (Version $version) {
            if (VersionImporter::$importing || PreviewVersionScope::isPreview($version) || ! $version->commit_id) {
                return;
            }
            $project = $version->project;
            if ($project && app(ProjectRemote::class)->exists($project)) {
                MirrorEditorVersionJob::dispatch($version->id)->afterCommit();
            }
        });

        // A git deploy waits for its version's build …
        Version::updated(function (Version $version) {
            if (! $version->wasChanged('build_status') || ! GitTables::present()) {
                return;
            }
            $this->quietly(fn () => app(GitDeployService::class)->versionUpdated($version));
        });

        // … and follows its deployment
        Deployment::updated(function (Deployment $deployment) {
            if (! $deployment->wasChanged('status') || ! GitTables::present()) {
                return;
            }
            $this->quietly(fn () => app(GitDeployService::class)->deploymentUpdated($deployment));
        });

        // … and, when the supply-chain policy waits for them, the version's security checks
        // (App\Services\GitDeploy\DeployChecks)
        if (class_exists(ImageSecurityReport::class)) {
            ImageSecurityReport::updated(function ($report) {
                if (! $report->wasChanged('status') || ! in_array($report->status, ['completed', 'failed'], true)
                    || ! $report->version_id || ! GitTables::present()) {
                    return;
                }
                $this->quietly(fn () => app(GitDeployService::class)->scanFinished((string) $report->version_id));
            });
        }

        // A deleted project takes its previews, its remote and its repository link along
        Project::deleting(function (Project $project) {
            if (! GitTables::present('preview_environments')) {
                return;
            }
            $this->quietly(function () use ($project) {
                app(PreviewService::class)->teardownAll($project, 'Project deleted');
                GitRepoLink::where('project_id', $project->id)
                    ->when(TenantContext::currentId(), fn ($q, $t) => $q->where('tenant_id', $t), fn ($q) => $q->whereNull('tenant_id'))
                    ->get()
                    ->each(fn (GitRepoLink $link) => app(RepoConnector::class)->disconnect($project, $link));
                app(ProjectRemote::class)->delete($project);
            });
        });
    }

    /**
     * Previews count against the organization quota (Tenancy module): their demand (one pod of
     * the project's size that scales to zero: the quota's FaaS overcommit budget) is added to
     * the region usage.
     */
    private function registerQuotaContribution(): void
    {
        if (self::$quotaHooked
            || ! class_exists(TenantQuotaService::class)
            || ! method_exists(TenantQuotaService::class, 'addUsageContributor')) {
            return;
        }
        self::$quotaHooked = true; // a static registry: once per process (Octane workers, test runs)

        TenantQuotaService::addUsageContributor(function ($region, ?string $excludeProjectId) {
            $total = ResourceReservation::noDemand();

            // A schema that has not been migrated to git deploy yet holds no previews
            if (! GitTables::present('preview_environments')) {
                return $total;
            }

            PreviewEnvironment::query()
                ->whereIn('status', PreviewEnvironment::LIVE)
                ->whereHas('project', fn ($q) => $q->where('knative_region_id', $region->id))
                ->with('project.xaasConfig', 'project.faasConfig', 'project.knativeRegion')
                ->get()
                ->each(function (PreviewEnvironment $preview) use (&$total) {
                    $total = ResourceReservation::addDemand($total, PreviewService::demand($preview->project));
                });

            return $total;
        });
    }

    /** Git deploy bookkeeping never breaks the operation that triggered it. */
    private function quietly(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::warning('Git deploy hook failed', ['error' => $e->getMessage()]);
        }
    }
}
