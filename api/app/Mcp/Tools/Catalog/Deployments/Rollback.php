<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Tools\Concerns\ReadsDeployments;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class Rollback extends Tool
{
    use ReadsDeployments, WaitsForDeployments;

    public function name(): string
    {
        return 'rollback';
    }

    public function title(): string
    {
        return 'Roll back to an older version';
    }

    public function description(): string
    {
        return 'Deploys an older version of a project in place of the live one and waits until it is active or failed. Name the version (number or id), or go back steps_back built versions from the one that is live now (1 = the previous one). Recorded in the audit log as deployment.rollback.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'version' => Schema::string('Version number ("3", "v3") or id to go back to.', ['maxLength' => 64]),
            'steps_back' => Schema::integer('Versions to go back from the live one (default 1 when no version is given).', 1, 100),
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

    public function annotations(): array
    {
        return self::destructive(false);
    }

    public function streams(): bool
    {
        return true;
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $deadline = time() + $context->maxWait(isset($arguments['timeout_seconds']) ? (int) $arguments['timeout_seconds'] : null);

        if (! empty($arguments['version'])) {
            $target = $this->resolveVersion($context, $projectId, (string) $arguments['version']);
        } else {
            $target = $this->stepBack($context, $projectId, (int) ($arguments['steps_back'] ?? 1));
        }

        if (($target['build_status'] ?? 'ready') !== 'ready') {
            throw new ToolError('Version '.($target['number'] ?? '?').' cannot be deployed: its build is '.($target['build_status'] ?? 'unknown').'.');
        }

        $context->progress(0, null, 'Rolling back to version '.($target['number'] ?? '?').'…');
        $wait = (bool) ($arguments['wait'] ?? true);
        $deployment = $this->startDeployment($context, $projectId, (string) $target['id'], [], $wait ? $deadline : null);
        if (isset($deployment['queued_deploy'])) {
            return self::queuedResult($deployment, $target);
        }
        if ($wait) {
            $deployment = $this->waitForDeployment($context, $projectId, $deployment, $deadline);
        }

        $summary = ['deployment' => self::deploymentSummary($deployment), 'version' => self::versionSummary($target)];

        return match ($deployment['status'] ?? null) {
            'active' => ToolResult::ok($summary, 'Rolled back: version '.($target['number'] ?? '?').' is live.'),
            'failed' => ToolResult::error('The rollback deployment failed'.(! empty($deployment['knative_message']) ? ': '.$deployment['knative_message'] : '').'. Call logs_tail for details.', $summary),
            default => ToolResult::ok($summary, 'The rollback deployment is '.($deployment['status'] ?? 'pending').'; deployment_status follows it.'),
        };
    }

    /** The built version $steps before the one deployed now. */
    private function stepBack(ToolContext $context, string $projectId, int $steps): array
    {
        $current = $this->deployment($context, $projectId);
        $live = $current['version']['id'] ?? null;
        $versions = array_values(array_filter($this->versions($context, $projectId), fn ($v) => ($v['build_status'] ?? 'ready') === 'ready'));

        $index = null;
        foreach ($versions as $i => $version) {
            if (($version['id'] ?? null) === $live) {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            throw new ToolError('The live version is not among the built versions; name the version to roll back to.');
        }

        $target = $versions[$index + $steps] ?? null;
        if ($target === null) {
            throw new ToolError("There is no built version {$steps} step(s) before version ".($versions[$index]['number'] ?? '?').'. versions_list shows the versions.');
        }

        return $target;
    }
}
