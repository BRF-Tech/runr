<?php

namespace App\Services\GitDeploy;

use App\Models\Git\ProjectGitSettings;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Services\GitDeploy\Providers\GitEvent;

/**
 * What a provider event does to a project:
 *  - push to the deploy branch                       → production (import + deploy)
 *  - push to a branch mapped to an environment       → production's path, into that environment
 *                                                      (GitDeployService, docs/environments.md#git-branches)
 *  - push to another branch, branch deletion         → nothing (a request from that branch gets a preview)
 *  - pull/merge request opened, reopened, new commits → preview, when previews are on
 *  - pull/merge request closed or merged             → preview (delete), whatever the setting
 *  - anything else (labels, reviews, pings)           → nothing
 */
final class BranchRules
{
    public const PRODUCTION = 'production';

    public const PREVIEW = 'preview';

    public const IGNORE = 'ignore';

    public static function decide(Project $project, GitEvent $event): string
    {
        $settings = ProjectGitSettings::for($project);

        if ($event->isPush()) {
            if ($event->deleted || ! $event->after) {
                return self::IGNORE;
            }

            return $event->branch === $settings->deploy_branch || self::mapsToEnvironment($project, (string) $event->branch)
                ? self::PRODUCTION
                : self::IGNORE;
        }

        if ($event->isPullRequest() && $event->number) {
            if ($event->action === GitEvent::PR_CLOSE) {
                return self::PREVIEW;
            }
            if (in_array($event->action, [GitEvent::PR_OPEN, GitEvent::PR_UPDATE], true) && $settings->previews_enabled) {
                return self::PREVIEW;
            }
        }

        return self::IGNORE;
    }

    /** Whether a push to $branch deploys one of the project's environments. */
    private static function mapsToEnvironment(Project $project, string $branch): bool
    {
        return $branch !== '' && ProjectEnvironment::tablesReady() && ProjectEnvironment::query()
            ->where('project_id', $project->id)
            ->where('is_primary', false)
            ->where('git_branch', $branch)
            ->where('auto_deploy', true)
            ->exists();
    }
}
