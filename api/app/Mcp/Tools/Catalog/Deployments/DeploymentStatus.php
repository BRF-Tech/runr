<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsDeployments;
use App\Mcp\Tools\Concerns\ReadsRollouts;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class DeploymentStatus extends Tool
{
    use ReadsDeployments, ReadsRollouts, WaitsForDeployments;

    public function name(): string
    {
        return 'deployment_status';
    }

    public function title(): string
    {
        return 'Deployment status';
    }

    public function description(): string
    {
        return 'The status of a deployment — by id, or the current one of the project or of an environment (environment; the newest active, else the newest pending or running, else the newest) — with the Knative readiness message and the project URL. With a blue-green or canary deploy strategy also the project\'s rollout: its status, the traffic split, the canary step / health checks / metrics, the test URL and whether it can be promoted, aborted or rolled back (deployment_promote, deployment_abort). When it failed, logs_tail shows why.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'deployment' => Schema::string('Deployment id; default the current deployment.', ['maxLength' => 64]),
            'environment' => Schema::string('One of the project\'s environments (a name, e.g. staging); default production, its own workload.', ['maxLength' => 64]),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $environment = isset($arguments['environment']) && $arguments['environment'] !== '' ? (string) $arguments['environment'] : null;
        $deployment = $this->deployment($context, $projectId, $arguments['deployment'] ?? null, $environment);

        $project = $context->api()->get(InternalApi::path('projects', $projectId));
        $url = $project->ok() ? $project->get('endpoint_url') : null;

        // The deployment's own rollout, else the project's rollout while it pins the traffic
        $rollout = is_array($deployment['rollout'] ?? null) ? $deployment['rollout'] : $this->currentRollout($context, $projectId, $environment);
        if ($rollout !== null && ($rollout['deployment_id'] ?? null) !== ($deployment['id'] ?? null)
            && ! ($rollout['open'] ?? false) && empty($rollout['can']['rollback'])) {
            $rollout = null;
        }

        $message = 'Deployment '.($deployment['id'] ?? '?').' is '.($deployment['status'] ?? 'unknown').(! empty($deployment['knative_message']) ? ': '.$deployment['knative_message'] : '').'.';

        return ToolResult::ok(
            array_filter(['deployment' => self::deploymentSummary($deployment), 'url' => $url, 'rollout' => $rollout ? self::rolloutSummary($rollout) : null], fn ($v) => $v !== null),
            $rollout ? $message.' '.self::rolloutLine($rollout) : $message,
        );
    }
}
