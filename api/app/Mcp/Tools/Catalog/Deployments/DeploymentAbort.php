<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsRollouts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class DeploymentAbort extends Tool
{
    use ReadsRollouts;

    public function name(): string
    {
        return 'deployment_abort';
    }

    public function title(): string
    {
        return 'Abort or roll back a rollout';
    }

    public function description(): string
    {
        return 'Stops a blue-green or canary rollout (default: the project\'s current one; environment: that environment\'s current one). Under way: the new revision leaves the traffic split, the traffic stays on the revision that served and the new deployment is torn down. Promoted while the previous revision is still kept warm: all traffic goes back to the previous revision at once (instant rollback). Ask the user first and pass confirm true. Recorded in the audit log as deployment.rollout.abort / deployment.rollout.rollback.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'deployment' => Schema::string('Id of the rollout\'s new deployment; default the project\'s current rollout.', ['maxLength' => 64]),
            'reason' => Schema::string('Why (shown in the rollout\'s history).', ['maxLength' => 500]),
            'environment' => Schema::string('One of the project\'s environments (a name, e.g. staging); default production, its own workload.', ['maxLength' => 64]),
            'confirm' => Schema::confirm('stopping the rollout'),
        ], ['project', 'confirm']);
    }

    public function permissions(): array
    {
        return ['projects.deploy'];
    }

    public function group(): string
    {
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::destructive(false);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $rollout = $this->rollout($context, $projectId, $arguments['deployment'] ?? null, $arguments['environment'] ?? null);

        $action = match (true) {
            ! empty($rollout['can']['abort']) => 'abort',
            ! empty($rollout['can']['rollback']) => 'rollback',
            default => throw new ToolError(self::rolloutLine($rollout).' There is nothing to abort or roll back.'),
        };

        $body = isset($arguments['reason']) && trim((string) $arguments['reason']) !== '' ? ['reason' => (string) $arguments['reason']] : [];
        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'deployments', (string) $rollout['deployment_id'], 'rollout', $action), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, $action === 'abort' ? 'The rollout was not aborted' : 'The rollout was not rolled back');
        }

        $ended = (array) $result->data();

        return ToolResult::ok(['action' => $action, 'rollout' => self::rolloutSummary($ended)], self::rolloutLine($ended));
    }
}
