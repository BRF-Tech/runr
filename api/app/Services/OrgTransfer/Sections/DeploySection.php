<?php

namespace App\Services\OrgTransfer\Sections;

use App\Models\Project;
use App\Models\Version;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\Projects\QueuedDeploys;
use App\Services\Projects\VersionRebuilds;

/**
 * The last step of an import that asked to deploy (docs/org-export.md#deploy): every imported
 * project gets a deploy of the version it ran in the source (else its latest ready version),
 * through the deploy path of every deploy — queued until the version is built for the region's
 * registry when its image is another registry's (VersionRebuilds), started at once otherwise
 * (QueuedDeploys), with the deploy checks (residency, supply chain, admission) on the way.
 * Projects that were stopped in the source stay undeployed. Nothing to export.
 */
class DeploySection implements TransferSection
{
    public function __construct(private QueuedDeploys $deploys, private VersionRebuilds $rebuilds) {}

    public function key(): string
    {
        return 'deploy';
    }

    public function export(ExportContext $context): void
    {
        // the deployment history summary is the projects section's
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        if (! $context->choices->deploy) {
            return;
        }
        foreach ($plan->projects as &$row) {
            if (($row['status'] ?? null) === 'create' && ! empty($row['domains'])) {
                $plan->issue($row, ImportPlan::WARNING, 'dns', __('org_transfer.plan.deploy_before_dns'));
            }
        }
        unset($row);
    }

    public function import(ImportContext $context): void
    {
        if (! $context->choices->deploy) {
            return;
        }

        $projects = $context->projects();
        foreach ($projects as $i => $source) {
            $sourceId = (string) $source['id'];
            $projectId = $context->mapped('project', $sourceId);
            if ($projectId === null || $context->settled('deploy', $sourceId)) {
                continue;
            }
            $project = Project::query()->with('knativeRegion')->find($projectId);
            if (! $project) {
                continue;
            }
            if (! empty($source['stopped'])) {
                $context->record('deploy', $sourceId, $project->name, 'skipped', null, __('org_transfer.plan.stopped'));

                continue;
            }

            $context->progress('deploy', 92, 99, $i, count($projects), __('org_transfer.progress.deploy', ['name' => $project->name]));
            $version = $this->versionToDeploy($project, $source);
            if (! $version) {
                $context->record('deploy', $sourceId, $project->name, 'skipped', null, __('org_transfer.import.nothing_to_deploy'));

                continue;
            }

            try {
                $by = (string) ($context->user->username ?? 'org-import');
                $options = ['initiator_id' => $context->userId(), 'message' => __('org_transfer.import.deploy_queued', ['version' => $version->number])];
                if (VersionRebuilds::needed($project, $version)) {
                    $queued = $this->rebuilds->queue($project, $version, $by, $options);
                } else {
                    $queued = $this->deploys->queue($project, $version, $by, $options);
                    $this->deploys->versionBuilt($version->fresh() ?? $version);
                }
                $context->record('deploy', $sourceId, "{$project->name} v{$version->number}", 'created', (string) $queued->id);
            } catch (\Throwable $e) {
                $context->record('deploy', $sourceId, "{$project->name} v{$version->number}", 'failed', null, $e->getMessage());
            }
        }
    }

    /** The version the project ran in the source, else its latest ready one. */
    private function versionToDeploy(Project $project, array $source): ?Version
    {
        $active = $source['deployments']['active_version'] ?? null;
        $query = Version::query()->where('project_id', $project->id)->where('build_status', 'ready');

        if ($active !== null && ($version = (clone $query)->where('number', (int) $active)->first())) {
            return $version;
        }

        return $query->orderByDesc('number')->first();
    }
}
