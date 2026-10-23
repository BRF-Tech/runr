<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RuntimePatchApply extends Tool
{
    use WaitsForDeployments;

    public function name(): string
    {
        return 'runtime_patch_apply';
    }

    public function title(): string
    {
        return 'Apply the newest runtime patch';
    }

    public function description(): string
    {
        return 'Redeploys the live version of a project onto the newest runtime patch of its runtime version (a pin is removed) and waits until the new deployment is active or failed. A version built into an image (dependencies, compiled runtimes) is built again on the patch first. runtime_patches_get shows why the patch came.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'wait' => Schema::boolean('Wait for the rollout (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait in seconds.', 1, 3600),
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

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $context->setTarget('project', $projectId);
        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'runtime-patches', 'apply'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Applying the runtime patch refused');
        }

        $migration = (array) $result->data();
        $message = (string) ($result->get('message') ?? 'Redeploying onto the newest runtime patch.');
        if (empty($migration['deployment_id']) || ! ($arguments['wait'] ?? true)) {
            return ToolResult::ok(['migration' => $migration], $message.(empty($migration['deployment_id']) ? ' The version is built again first; runtime_patches_get follows the migration.' : ''));
        }

        $context->progress(0, null, $message);
        $deployment = $context->api()->get(InternalApi::path('projects', $projectId, 'deployments', (string) $migration['deployment_id']));
        if (! $deployment->ok()) {
            throw ToolError::fromApi($deployment, 'Reading the deployment');
        }
        $deployment = $this->waitForDeployment($context, $projectId, (array) $deployment->data(), $deadline);
        $summary = ['migration' => $migration, 'deployment' => self::deploymentSummary($deployment)];

        return match ($deployment['status'] ?? null) {
            'active' => ToolResult::ok($summary, 'The project runs the newest runtime patch.'),
            'failed' => ToolResult::error('The deployment onto the patch failed'.(! empty($deployment['knative_message']) ? ': '.$deployment['knative_message'] : '').'. The previous revision keeps serving; call logs_tail for details.', $summary),
            default => ToolResult::ok($summary, 'The deployment onto the patch is '.($deployment['status'] ?? 'pending').'; deployment_status follows it.'),
        };
    }
}
