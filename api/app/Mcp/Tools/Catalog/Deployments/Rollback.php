<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ReadsDeployments;
use App\Mcp\Tools\Concerns\ReadsRollouts;
use App\Mcp\Tools\Concerns\WaitsForDeployments;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class Rollback extends Tool
{
    use ReadsDeployments, ReadsRollouts, WaitsForDeployments;

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
        return 'Deploys an older version of a project in place of the live one and waits until it is active or failed. Name the version (number or id), or go back steps_back built versions from the one that is live now (1 = the previous one). The configuration the version ran with (scale, env vars, resources, trigger, a CaaS image) comes back with it, unless keep_current_config. environment rolls back one of the project\'s environments. Recorded in the audit log as deployment.rollback. With a blue-green or canary deploy strategy the older version rolls out like any deploy unless strategy is direct; right after a promotion, while the previous revision is still kept warm, deployment_abort switches back to it at once instead.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'version' => Schema::string('Version number ("3", "v3") or id to go back to.', ['maxLength' => 64]),
            'steps_back' => Schema::integer('Versions to go back from the live one (default 1 when no version is given).', 1, 100),
            'strategy' => Schema::enum(['direct', 'blue_green', 'canary'], 'How the rollback rolls out; default the project\'s deploy strategy. direct switches as soon as the older version is ready.'),
            'environment' => Schema::string('Environment name or id (default: production, the project\'s own workload).', ['maxLength' => 64]),
            'keep_current_config' => Schema::boolean('Deploy the older code with the configuration of now instead of the one it ran with (default false).'),
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

        $environment = isset($arguments['environment']) && $arguments['environment'] !== '' ? (string) $arguments['environment'] : null;
        if (! empty($arguments['version'])) {
            $target = $this->resolveVersion($context, $projectId, (string) $arguments['version']);
        } else {
            $target = $this->stepBack($context, $projectId, (int) ($arguments['steps_back'] ?? 1), $environment);
        }

        if (($target['build_status'] ?? 'ready') !== 'ready') {
            throw new ToolError('Version '.($target['number'] ?? '?').' cannot be deployed: its build is '.($target['build_status'] ?? 'unknown').'.');
        }

        $context->progress(0, null, 'Rolling back to version '.($target['number'] ?? '?').'…');
        $wait = (bool) ($arguments['wait'] ?? true);
        // The config the version ran with comes back too (docs/versioning.md#rollback)
        $extra = array_filter([
            'environment' => $environment,
            'restore_config' => empty($arguments['keep_current_config']) ? true : null,
            'strategy' => ! empty($arguments['strategy']) ? (string) $arguments['strategy'] : null,
        ], fn ($v) => $v !== null);
        $deployment = $this->startDeployment($context, $projectId, (string) $target['id'], $extra, $wait ? $deadline : null);
        if (isset($deployment['queued_deploy'])) {
            return self::queuedResult($deployment, $target);
        }
        if ($wait) {
            $deployment = $this->waitForDeployment($context, $projectId, $deployment, $deadline);
        }

        $summary = ['deployment' => self::deploymentSummary($deployment), 'version' => self::versionSummary($target)];

        if (is_array($deployment['rollout'] ?? null) && ! in_array($deployment['status'] ?? null, ['active', 'failed'], true)) {
            return ToolResult::ok($summary, 'Rolling back to version '.($target['number'] ?? '?').': '.self::rolloutLine($deployment['rollout']));
        }

        return match ($deployment['status'] ?? null) {
            'active' => ToolResult::ok($summary, 'Rolled back: version '.($target['number'] ?? '?').' is live.'),
            'failed' => ToolResult::error('The rollback deployment failed'.(! empty($deployment['knative_message']) ? ': '.$deployment['knative_message'] : '').'. Call logs_tail for details.', $summary),
            default => ToolResult::ok($summary, 'The rollback deployment is '.($deployment['status'] ?? 'pending').'; deployment_status follows it.'),
        };
    }

    /** The built version $steps before the one deployed now (in the environment). */
    private function stepBack(ToolContext $context, string $projectId, int $steps, ?string $environment = null): array
    {
        if ($environment !== null) {
            $env = $context->api()->get(InternalApi::path('projects', $projectId, 'environments', $environment));
            if (! $env->ok()) {
                throw ToolError::fromApi($env, 'Reading the environment');
            }
            $live = $env->get('data.deployment.version.id');
        } else {
            $current = $this->deployment($context, $projectId);
            $live = $current['version']['id'] ?? null;
        }
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
