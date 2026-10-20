<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class Deploy extends Tool
{
    use WaitsForDeployments;

    public function name(): string
    {
        return 'deploy';
    }

    public function title(): string
    {
        return 'Deploy a version';
    }

    public function description(): string
    {
        return 'Deploys a version of a project (default: the newest) and waits until the deployment is active or failed, reporting progress. A version that is still building is waited for first. The previous active deployment is replaced. Returns the deployment and the project URL; on failure call logs_tail for the reason.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'version' => Schema::string('Version number (3 or "v3"), version id, or "latest" (default).', ['maxLength' => 64]),
            'wait' => Schema::boolean('Wait for the build and the rollout to finish (default true).', true),
            'timeout_seconds' => Schema::integer('Longest wait in seconds (default and cap: the server maximum).', 1, 3600),
            'overrides' => Schema::object([
                'cpu' => Schema::string('CPU for this deployment, e.g. "500m".', ['maxLength' => 16]),
                'memory_mb' => Schema::integer('Memory in MiB for this deployment.', 64, 262144),
                'timeout_seconds' => Schema::integer('Request timeout for this deployment.', 1, 3600),
                'scale_to_zero' => Schema::boolean('Scale to zero when idle.'),
                'min_scale' => Schema::integer('Minimum replicas.', 0, 10),
                'max_scale' => Schema::integer('Maximum replicas.', 1, 1000),
            ]),
            'override_policy' => Schema::boolean('Deploy although the supply-chain policy gate blocks the version (needs security.override_policy and a reason).'),
            'override_reason' => Schema::string('Why the policy gate is overridden (recorded).', ['maxLength' => 500]),
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
        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);
        $wait = (bool) ($arguments['wait'] ?? true);

        $version = $this->resolveVersion($context, $projectId, $arguments['version'] ?? null);
        if ($wait) {
            $version = $this->waitForBuild($context, $projectId, $version, $deadline);
        }

        $status = $version['build_status'] ?? 'ready';
        if ($status === 'failed') {
            return ToolResult::error('The build of version '.($version['number'] ?? '?').' failed: '.($version['build_error'] ?? 'no details'), [
                'version' => self::versionSummary($version),
            ]);
        }
        if ($status !== 'ready') {
            return ToolResult::ok([
                'status' => 'building',
                'version' => self::versionSummary($version),
            ], 'Version '.($version['number'] ?? '?').' is still building ('.$status.'). Call deploy again once it is ready.');
        }

        $extra = [];
        if (! empty($arguments['overrides'])) {
            $extra['deploy_config'] = $arguments['overrides'];
        }
        if (! empty($arguments['override_policy'])) {
            $extra['override_policy'] = true;
            $extra['override_reason'] = (string) ($arguments['override_reason'] ?? '');
        }

        $context->progress(0, null, 'Deploying version '.($version['number'] ?? '?').'…');
        $deployment = $this->startDeployment($context, $projectId, (string) $version['id'], $extra, $wait ? $deadline : null);
        if (isset($deployment['queued_deploy'])) {
            return self::queuedResult($deployment, $version);
        }
        if ($wait) {
            $deployment = $this->waitForDeployment($context, $projectId, $deployment, $deadline);
        }

        $project = $context->api()->get(InternalApi::path('projects', $projectId));
        $url = $project->ok() ? $project->get('endpoint_url') : null;
        $summary = ['deployment' => self::deploymentSummary($deployment), 'version' => self::versionSummary($version), 'url' => $url];

        return match ($deployment['status'] ?? null) {
            'active' => ToolResult::ok($summary, 'Version '.($version['number'] ?? '?').' is live'.($url ? " at {$url}" : '').'.'),
            'failed' => ToolResult::error('The deployment failed'.(! empty($deployment['knative_message']) ? ': '.$deployment['knative_message'] : '').'. Call logs_tail for details.', $summary),
            default => ToolResult::ok($summary, 'The deployment is '.($deployment['status'] ?? 'pending').'; deployment_status follows it.'),
        };
    }
}
