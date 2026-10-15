<?php

namespace App\Services\GitDeploy;

use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\Version;
use App\Services\Residency\RegionNotAllowedException;
use App\Services\Residency\RegionPolicy;
use App\Services\SupplyChain\DeployPolicyGate;
use App\Services\SupplyChain\SupplyChainAudit;

/**
 * The deploy-time checks of POST /projects/{project}/deploy for deploys a push starts
 * (docs/supply-chain.md): data residency (RegionPolicy::DEPLOY for production,
 * RegionPolicy::PREVIEW for preview environments) and the supply-chain policy gate of the
 * region and organization. A push cannot carry a policy override: a blocked version is deployed
 * with an override from the console. While the scan or signature of the version is still
 * running the deploy waits (DeployPendingException) and continues when the report is final
 * (GitDeployService::scanFinished).
 */
final class DeployChecks
{
    /**
     * @throws DeployPendingException the version's scan / signature is still running
     * @throws ImportException residency or policy refuses the deploy (reason in the message)
     */
    public static function assert(Project $project, Version $version, string $action = RegionPolicy::DEPLOY): void
    {
        try {
            app(RegionPolicy::class)->authorize($project->knativeRegion, $action);
        } catch (RegionNotAllowedException $e) {
            throw new ImportException($e->getMessage());
        }

        $decision = app(DeployPolicyGate::class)->evaluate($project, $version);
        $audit = [
            'project_id' => $project->getKey(),
            'project' => $project->name,
            'version' => $version->number,
            'via' => $action === RegionPolicy::PREVIEW ? 'git_preview' : 'git_push',
        ];

        if (! $decision->blocked()) {
            if ($decision->warnings()) {
                SupplyChainAudit::record('deploy.policy_warned', $audit + ['violations' => array_column($decision->warnings(), 'rule')], $project->knative_region_id);
            }

            return;
        }

        $reasons = implode('; ', array_column($decision->blocking(), 'message'));

        if ($decision->pendingOnly()) {
            throw new DeployPendingException(__('git.policy_pending', ['version' => $version->label, 'reasons' => $reasons]));
        }

        SupplyChainAudit::record('deploy.policy_blocked', $audit + ['violations' => array_column($decision->blocking(), 'rule')], $project->knative_region_id, AuditEvent::RESULT_DENIED);

        throw new ImportException(
            __('supply_chain.gate.blocked', ['region' => $project->knativeRegion?->name ?? '-', 'reasons' => $reasons])
            .' '.__('git.policy_blocked_hint')
        );
    }
}
