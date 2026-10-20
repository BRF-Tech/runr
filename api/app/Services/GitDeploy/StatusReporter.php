<?php

namespace App\Services\GitDeploy;

use App\Models\Git\GitDeploy;
use App\Models\Git\GitProvider;
use App\Models\Git\GitRepoLink;
use App\Models\Git\PreviewEnvironment;
use App\Models\Project;
use App\Services\GitDeploy\Providers\ProviderClient;
use App\Services\GitDeploy\Providers\ProviderClients;
use Illuminate\Support\Facades\Log;

/**
 * Reports git deploys back to the provider (ReportGitStatusJob):
 *  - commit status "runr" on the commit (GitHub / GitLab / Forgejo): pending while importing,
 *    building and deploying; success when live (or nothing changed / auto-deploy is off);
 *    failure with the reason; canceled when a newer deploy replaced it (or the project was
 *    stopped) before it went live — GitLab canceled, Forgejo/Gitea warning, GitHub success with
 *    that description (its commit statuses have no canceled state);
 *  - GitHub Deployments API: a "production" deployment per production deploy, a transient
 *    "preview/pr-<n>" one per preview, with the function URL as environment URL; the deployment
 *    of a replaced deploy and a deleted preview's become inactive;
 *  - previews: one RunR comment per pull/merge request (status, commit, URL), updated on every
 *    new commit and when the preview is deleted.
 * Pushes to the RunR remote have nothing to report to.
 */
class StatusReporter
{
    public function __construct(private ProviderClients $clients) {}

    public function deploy(GitDeploy $deploy): void
    {
        if ($deploy->provider === 'runr') {
            return;
        }

        $project = $deploy->project;
        $link = $project ? GitRepoLink::forProject($project->id, TenantContext::currentId()) : null;
        if (! $project || ! $link || $link->provider_type !== $deploy->provider) {
            return;
        }

        $state = $this->commitState($deploy->status);
        if ($state === null) {
            return;
        }

        $client = $this->clients->for($link->provider_type);
        $description = $this->description($deploy);
        $target = $deploy->kind === GitDeploy::KIND_PREVIEW && $deploy->preview?->url && $deploy->status === GitDeploy::ACTIVE
            ? $deploy->preview->url
            : GitUrls::project($project);

        $key = $state.'|'.$deploy->status;
        if ($deploy->reported_state !== $key) {
            $this->attempt(fn () => $client->setCommitStatus($link, $deploy->sha, $state, $description, $target), $deploy, 'commit status');
        }

        if ($link->provider_type === GitProvider::GITHUB) {
            $this->githubDeployment($client, $link, $project, $deploy, $description);
        }

        $deploy->forceFill(['reported_state' => $key])->saveQuietly();
    }

    /** The PR comment of a preview (create or update). */
    public function previewComment(PreviewEnvironment $preview): void
    {
        $project = $preview->project;
        $link = $project ? GitRepoLink::forProject($project->id, TenantContext::currentId()) : null;
        if (! $project || ! $link || $link->provider_type !== $preview->provider) {
            return;
        }

        $client = $this->clients->for($link->provider_type);
        $body = PreviewComment::render($project, $preview);

        $this->attempt(function () use ($client, $link, $preview, $body) {
            $id = $client->upsertComment($link, $preview->number, $preview->comment_id, $body);
            if ($id && $id !== $preview->comment_id) {
                $preview->forceFill(['comment_id' => $id])->saveQuietly();
            }
        }, $preview, 'pull request comment');

        if ($preview->status === PreviewEnvironment::DELETED && $preview->provider_deployment_id && $link->provider_type === GitProvider::GITHUB) {
            $this->attempt(fn () => $client->deploymentStatus($link, $preview->provider_deployment_id, 'inactive', null, GitUrls::project($project), 'Preview deleted'), $preview, 'deployment status');
        }
    }

    public function commitState(string $status): ?string
    {
        return match ($status) {
            GitDeploy::QUEUED, GitDeploy::IMPORTING, GitDeploy::BUILDING, GitDeploy::DEPLOYING => ProviderClient::PENDING,
            GitDeploy::ACTIVE, GitDeploy::UNCHANGED, GitDeploy::IMPORTED => ProviderClient::SUCCESS,
            GitDeploy::FAILED => ProviderClient::FAILURE,
            GitDeploy::SKIPPED => ProviderClient::CANCELED,
            default => null,
        };
    }

    public function description(GitDeploy $deploy): string
    {
        $what = $deploy->kind === GitDeploy::KIND_PREVIEW ? "Preview #{$deploy->pr_number}" : 'Deploy';

        return match ($deploy->status) {
            GitDeploy::QUEUED, GitDeploy::IMPORTING => "{$what}: creating the version",
            GitDeploy::BUILDING => "{$what}: building",
            GitDeploy::DEPLOYING => "{$what}: deploying",
            GitDeploy::ACTIVE => "{$what}: live",
            GitDeploy::UNCHANGED => 'No changes to deploy',
            GitDeploy::IMPORTED => 'Version created; auto-deploy is off',
            GitDeploy::FAILED => "{$what} failed: ".mb_substr((string) $deploy->status_message, 0, 100),
            GitDeploy::SKIPPED => match (true) {
                str_starts_with((string) $deploy->status_message, GitDeployService::BUILD_CANCELLED) => 'Build cancelled by a region migration rollback',
                $this->stopped($deploy) => 'Stopped before it went live',
                default => 'Replaced by a newer deploy',
            },
            default => $what,
        };
    }

    /**
     * A skipped deploy of a project that is stopped: the stop took its deployment down while it
     * started (ProjectStopService writes stopped_at before it does). Otherwise a newer deploy
     * replaced it.
     */
    private function stopped(GitDeploy $deploy): bool
    {
        return Project::query()->whereKey($deploy->project_id)->whereNotNull('stopped_at')->exists();
    }

    private function githubDeployment(ProviderClient $client, GitRepoLink $link, Project $project, GitDeploy $deploy, string $description): void
    {
        $preview = $deploy->kind === GitDeploy::KIND_PREVIEW ? $deploy->preview : null;

        // Only a deploy that actually goes live gets a deployment record; one replaced before it
        // went live ends the record it started (inactive: GitHub's state of a superseded deployment)
        if (! in_array($deploy->status, [GitDeploy::BUILDING, GitDeploy::DEPLOYING, GitDeploy::ACTIVE, GitDeploy::FAILED, GitDeploy::SKIPPED], true)) {
            return;
        }

        $id = $deploy->provider_deployment_id;
        if (! $id && $deploy->status === GitDeploy::SKIPPED) {
            return;
        }
        if (! $id) {
            $environment = $preview ? "preview/pr-{$deploy->pr_number}" : 'production';
            $id = $this->attempt(fn () => $client->startDeployment($link, $deploy->sha, $environment, $preview !== null, $preview === null), $deploy, 'deployment');
            if (! $id) {
                return;
            }
            $deploy->forceFill(['provider_deployment_id' => $id])->saveQuietly();
            $preview?->forceFill(['provider_deployment_id' => $id])->saveQuietly();
        }

        $state = match ($deploy->status) {
            GitDeploy::ACTIVE => 'success',
            GitDeploy::FAILED => 'failure',
            GitDeploy::SKIPPED => 'inactive',
            default => 'in_progress',
        };
        $url = $deploy->status === GitDeploy::ACTIVE
            ? ($preview?->url ?? GitUrls::function($project, $project->slug))
            : null;

        $this->attempt(fn () => $client->deploymentStatus($link, $id, $state, $url, GitUrls::project($project), $description), $deploy, 'deployment status');
    }

    /**
     * Provider calls never fail the deploy: errors are logged.
     */
    private function attempt(\Closure $call, object $subject, string $what): mixed
    {
        try {
            return $call();
        } catch (\Throwable $e) {
            Log::warning("Git deploy: reporting the {$what} failed", [
                'subject' => $subject->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
