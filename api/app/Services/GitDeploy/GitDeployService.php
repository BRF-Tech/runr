<?php

namespace App\Services\GitDeploy;

use App\Jobs\BuildVersionJob;
use App\Jobs\GitDeploy\DeployPreviewJob;
use App\Jobs\GitDeploy\ReportGitStatusJob;
use App\Models\Deployment;
use App\Models\Git\GitDeploy;
use App\Models\Git\GitRepoLink;
use App\Models\Git\ProjectGitSettings;
use App\Models\Project;
use App\Models\User;
use App\Models\Version;
use App\Services\GitDeploy\Providers\GitEvent;
use App\Services\GitDeploy\Providers\ProviderClients;
use App\Services\GitDeploy\Providers\TarGz;
use App\Services\Projects\VersionRebuilds;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Production deploys from git: a push to the deploy branch — of the RunR remote or of the
 * connected provider repository — becomes a version (VersionImporter) and, with auto-deploy on,
 * a deployment once the version is built. Each run is a GitDeploy row (the Git tab's history)
 * that follows the version's build and the deployment's status (model observers in
 * GitDeployServiceProvider) and is reported back to the provider (StatusReporter).
 */
class GitDeployService
{
    /** status_message of a deploy whose version's build RunR cancelled (Version::STATUS_CANCELLED) */
    public const BUILD_CANCELLED = 'Build cancelled: ';

    public function __construct(
        private ProjectRemote $remote,
        private VersionImporter $importer,
        private DeploymentStarter $starter,
        private ProviderClients $clients,
    ) {}

    /** A push to the RunR remote updated $ref from $before to $after (ProcessGitPushJob). */
    public function remotePush(Project $project, string $ref, string $before, string $after, ?User $pusher = null): ?GitDeploy
    {
        $settings = ProjectGitSettings::for($project);

        if ($ref !== $settings->deployRef() || self::isZero($after)) {
            return null; // other branches are stored, not deployed; deletions are refused by the hook
        }

        $commit = $this->remote->commit($project, $after);
        $meta = new CommitMeta(
            provider: 'runr',
            sha: $after,
            branch: $settings->deploy_branch,
            authorName: $commit['author_name'] ?: null,
            authorEmail: $commit['author_email'] ?: null,
            message: $commit['message'] ?: $commit['subject'],
            pushedBy: $pusher?->getKey() !== null ? (string) $pusher->getKey() : null,
        );

        return $this->production(
            $project,
            $settings,
            $meta,
            fn () => $this->remote->tree($project, $after, $settings->rootDir()),
            'push',
            $pusher?->username ?? 'git',
        );
    }

    /** A push to the connected repository (ProcessGitEventJob). */
    public function providerPush(Project $project, GitRepoLink $link, GitEvent $event): ?GitDeploy
    {
        $settings = ProjectGitSettings::for($project);

        if (! $event->isPush() || $event->deleted || ! $event->after || $event->branch !== $settings->deploy_branch) {
            return null;
        }

        $meta = new CommitMeta(
            provider: $link->provider_type,
            sha: $event->after,
            branch: $event->branch,
            authorName: $event->authorName,
            authorEmail: $event->authorEmail,
            message: $event->message,
            url: $event->commitUrl,
        );

        return $this->production(
            $project,
            $settings,
            $meta,
            fn () => $this->providerTree($link, $event->after, $settings->rootDir()),
            'push',
            $event->authorName ?? $link->provider_type,
        );
    }

    /** Deploy the current head of the deploy branch again (Git tab "Redeploy"). */
    public function redeployHead(Project $project, ?User $user = null): ?GitDeploy
    {
        $settings = ProjectGitSettings::for($project);
        $link = GitRepoLink::forProject($project->id, TenantContext::currentId());

        if ($link) {
            return null; // provider-connected projects deploy from their pushes
        }

        $head = $this->remote->resolve($project, $settings->deployRef());

        return $head ? $this->remotePush($project, $settings->deployRef(), ProjectRemote::ZERO, $head, $user) : null;
    }

    /**
     * The tree of a provider commit (source archive), filtered to the root directory.
     *
     * @throws ImportException
     */
    public function providerTree(GitRepoLink $link, string $sha, string $rootDir): SourceTree
    {
        $archive = $this->clients->for($link->provider_type)->archive($link, $sha);
        $prefix = trim($rootDir, '/') === '' ? '' : trim($rootDir, '/').'/';

        // Hold only the root directory (and the repository-root project file), never what the
        // default ignore list drops (node_modules, vendor, …)
        $keep = function (string $path) use ($prefix): bool {
            if (in_array($path, [SourceTree::RUNR_YAML, SourceTree::RUNR_YML], true)) {
                return true;
            }
            if ($prefix !== '' && ! str_starts_with($path, $prefix)) {
                return false;
            }
            $rel = substr($path, strlen($prefix));

            return in_array($rel, [SourceTree::RUNR_YAML, SourceTree::RUNR_YML, SourceTree::RUNR_IGNORE], true)
                || ! SourceTree::ignored($rel, SourceTree::DEFAULT_IGNORE);
        };

        $content = TarGz::extract($archive, $keep);

        // The project-type rules (flat set, text, size) apply when the version is built from it
        return SourceTree::fromRepository($content['files'], $rootDir, $content['skipped']);
    }

    /**
     * Continue a deploy with its version: wait for the build, start the deployment, or fail. A
     * version whose image is in a registry the region's cluster does not pull from (an earlier
     * version the commit's tree equals, built before the region moved to another cluster) is
     * built again into the region's first, and the deploy waits for that build like for any other
     * (VersionRebuilds).
     */
    public function continue(GitDeploy $deploy, Version $version, string $deployedBy = 'git'): void
    {
        $project = $deploy->project;

        if ($version->build_status === 'ready' && VersionRebuilds::needed($project, $version)) {
            $this->rebuild($deploy, $project, $version);
            $this->report($deploy);

            return;
        }

        match ($version->build_status) {
            // A deploy that waits already keeps what it says (a version built again: why)
            'pending', 'building' => $deploy->status === GitDeploy::BUILDING ? $deploy : $deploy->mark(GitDeploy::BUILDING),
            'failed' => $deploy->mark(GitDeploy::FAILED, 'Build failed: '.($version->build_error ?? 'unknown error')),
            // Stopped by RunR (a region migration rollback), not a failure of the commit
            Version::STATUS_CANCELLED => $deploy->mark(GitDeploy::SKIPPED, self::BUILD_CANCELLED.($version->build_error ?? 'the build was stopped')),
            'ready' => $this->startDeployment($deploy, $project, $version, $deployedBy),
            default => $deploy->mark(GitDeploy::FAILED, "Version {$version->label} cannot be deployed ({$version->build_status})"),
        };

        $this->report($deploy);
    }

    /** Version observer: a production version a git deploy waits for finished building. */
    public function versionUpdated(Version $version): void
    {
        if (! $version->wasChanged('build_status') || PreviewVersionScope::isPreview($version)) {
            return;
        }

        GitDeploy::query()
            ->where('version_id', $version->id)
            ->where('kind', GitDeploy::KIND_PRODUCTION)
            ->where('status', GitDeploy::BUILDING)
            ->get()
            ->each(fn (GitDeploy $deploy) => $this->continue($deploy, $version, $deploy->author_name ?? 'git'));
    }

    /** Deployment observer: the deployment of a git deploy went live or failed. */
    public function deploymentUpdated(Deployment $deployment): void
    {
        if (! $deployment->wasChanged('status')) {
            return;
        }

        $deploy = GitDeploy::query()
            ->where('deployment_id', $deployment->id)
            ->where('kind', GitDeploy::KIND_PRODUCTION)
            ->whereNotIn('status', GitDeploy::FINAL)
            ->first();

        if (! $deploy) {
            return;
        }

        match ($deployment->status) {
            'active' => $deploy->mark(GitDeploy::ACTIVE),
            'failed' => $deploy->mark(GitDeploy::FAILED, $deployment->knative_message ?: 'The deployment failed'),
            // Replaced by a newer deploy (or the project was stopped) before it went live
            'shutting_down', 'shut_down' => $deploy->mark(GitDeploy::SKIPPED, 'Replaced by a newer deploy or stopped before it went live'),
            default => null,
        };

        $this->report($deploy);
    }

    public function report(GitDeploy $deploy): void
    {
        if ($deploy->provider !== 'runr') {
            ReportGitStatusJob::dispatch($deploy->id)->afterCommit();
        }
    }

    /**
     * @param  Closure(): SourceTree  $loadTree
     */
    private function production(Project $project, ProjectGitSettings $settings, CommitMeta $meta, Closure $loadTree, string $trigger, string $deployedBy): GitDeploy
    {
        $deploy = GitDeploy::create([
            'project_id' => $project->id,
            'kind' => GitDeploy::KIND_PRODUCTION,
            'trigger' => $trigger,
            'provider' => $meta->provider,
            'sha' => $meta->sha,
            'branch' => $meta->branch,
            'author_name' => $meta->authorName ? mb_substr($meta->authorName, 0, 250) : null,
            'message' => $meta->message,
            'url' => $meta->url,
            'status' => GitDeploy::IMPORTING,
        ]);
        $this->report($deploy);

        try {
            $result = $this->importer->import($project, $loadTree(), $meta);
        } catch (ImportException|GitCommandException $e) {
            $deploy->mark(GitDeploy::FAILED, $e->getMessage());
            $this->report($deploy);

            return $deploy;
        } catch (\Throwable $e) {
            Log::error('Git deploy import failed', ['project' => $project->id, 'sha' => $meta->sha, 'error' => $e->getMessage()]);
            $deploy->mark(GitDeploy::FAILED, 'The commit could not be imported: '.$e->getMessage());
            $this->report($deploy);

            return $deploy;
        }

        /** @var Version|null $version */
        $version = $result['version'];
        $deploy->update([
            'version_id' => $version?->id,
            'details' => [
                'warnings' => $result['warnings'],
                'skipped' => array_slice($result['skipped'], 0, 50),
                'applied' => $result['applied'],
            ],
        ]);

        if (! $version) {
            $deploy->mark(GitDeploy::UNCHANGED, 'Nothing to deploy');
            $this->report($deploy);

            return $deploy;
        }

        if (! $result['created']) {
            $live = $project->deployments()->where('status', 'active')->value('version_id');
            if (! $settings->auto_deploy || $live === $version->id) {
                $deploy->mark(GitDeploy::UNCHANGED, "The tree equals {$version->label}");
                $this->report($deploy);

                return $deploy;
            }
        }

        if (! $settings->auto_deploy) {
            $deploy->mark(GitDeploy::IMPORTED, "Auto-deploy is off: {$version->label} waits for a deploy");
            $this->report($deploy);

            return $deploy;
        }

        $this->continue($deploy, $version->fresh() ?? $version, $deployedBy);

        return $deploy;
    }

    /**
     * Build the version again into the region's registry (BuildVersionJob): the deploy waits for
     * it (BUILDING, versionUpdated() goes on when the build ends). Of two deploys at once one
     * submits the build.
     */
    private function rebuild(GitDeploy $deploy, Project $project, Version $version): void
    {
        $claimed = Version::query()->whereKey($version->getKey())
            ->where('build_status', 'ready')
            ->where('build_image', $version->build_image)
            ->update(['build_status' => 'pending', 'build_error' => null, 'build_token' => null, 'updated_at' => now()]);
        $version->refresh();
        if ($claimed === 1) {
            BuildVersionJob::dispatch($version);
            Log::info("Git deploy of {$project->slug}: {$version->label} is built again for the registry of its region", ['git_deploy' => $deploy->id]);
        }

        $deploy->mark(GitDeploy::BUILDING, VersionRebuilds::message($version));
    }

    private function startDeployment(GitDeploy $deploy, Project $project, Version $version, string $deployedBy): void
    {
        try {
            $deployment = $this->starter->start($project->loadMissing('knativeRegion'), $version, $deployedBy);
            $deploy->update([
                'deployment_id' => $deployment->id,
                'status' => GitDeploy::DEPLOYING,
                'status_message' => null,
            ]);

            // A queue-less run (or a demo region) may already have finished the deployment
            $deployment->refresh();
            if ($deployment->status === 'active') {
                $deploy->mark(GitDeploy::ACTIVE);
            } elseif ($deployment->status === 'failed') {
                $deploy->mark(GitDeploy::FAILED, $deployment->knative_message ?: 'The deployment failed');
            }
        } catch (DeployPendingException $e) {
            // Continues when the version's supply-chain report is final (scanFinished)
            $deploy->mark(GitDeploy::BUILDING, $e->getMessage());
        } catch (ImportException $e) {
            $deploy->mark(GitDeploy::FAILED, $e->getMessage());
        }
    }

    /**
     * Supply-chain report observer: the scan / signature of a version is final. Deploys waiting
     * for it (DeployChecks) continue — production with the deployment, previews with their
     * deploy job.
     */
    public function scanFinished(string $versionId): void
    {
        GitDeploy::query()
            ->where('version_id', $versionId)
            ->where('status', GitDeploy::BUILDING)
            ->whereNull('deployment_id')
            ->get()
            ->each(function (GitDeploy $deploy) use ($versionId) {
                if ($deploy->kind === GitDeploy::KIND_PREVIEW) {
                    if ($deploy->preview_environment_id) {
                        DeployPreviewJob::dispatch($deploy->preview_environment_id);
                    }

                    return;
                }

                $version = Version::find($versionId);
                if ($version && $version->build_status === 'ready') {
                    $this->continue($deploy, $version, $deploy->author_name ?? 'git');
                }
            });
    }

    public static function isZero(?string $sha): bool
    {
        return $sha === null || $sha === '' || trim($sha, '0') === '';
    }
}
