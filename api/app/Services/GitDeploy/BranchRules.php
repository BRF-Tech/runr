<?php

namespace App\Services\GitDeploy;

use App\Models\Git\ProjectGitSettings;
use App\Models\Project;
use App\Services\GitDeploy\Providers\GitEvent;

/**
 * What a provider event does to a project:
 *  - push to the deploy branch                       → production (import + deploy)
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
            return ! $event->deleted && $event->after && $event->branch === $settings->deploy_branch
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
}
