<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsRollouts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class DeploymentPromote extends Tool
{
    use ReadsRollouts;

    public function name(): string
    {
        return 'deployment_promote';
    }

    public function title(): string
    {
        return 'Promote a rollout';
    }

    public function description(): string
    {
        return 'Promotes a blue-green or canary rollout now: all traffic moves to the new revision (default: the project\'s current rollout; environment: the current rollout of that environment). A blue-green rollout waiting for its promotion, or a canary before its last step. Blue-green keeps the previous revision warm for its keep window; deployment_abort rolls back to it meanwhile. Recorded in the audit log as deployment.rollout.promote.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'deployment' => Schema::string('Id of the rollout\'s new deployment; default the project\'s current rollout.', ['maxLength' => 64]),
            'environment' => Schema::string('One of the project\'s environments (a name, e.g. staging); default production, its own workload.', ['maxLength' => 64]),
        ], ['project']);
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
        if (empty($rollout['can']['promote'])) {
            throw new ToolError(self::rolloutLine($rollout).' It cannot be promoted now.');
        }

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'deployments', (string) $rollout['deployment_id'], 'rollout', 'promote'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'The rollout was not promoted');
        }

        $promoted = (array) $result->data();

        return ToolResult::ok(['rollout' => self::rolloutSummary($promoted)], self::rolloutLine($promoted));
    }
}
