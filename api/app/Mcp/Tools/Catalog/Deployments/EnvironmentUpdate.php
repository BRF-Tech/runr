<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Catalog\Projects\ProjectCreate;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * Changes an environment of a project (docs/environments.md): its settings and its overrides,
 * merged over the ones it has — the API saves overrides as a whole, this tool reads them first.
 * scale_schedule is the environment's own timed warmer (docs/scaling.md#timed-warmer): rows, []
 * for none, null to follow the project's schedule again.
 */
class EnvironmentUpdate extends Tool
{
    /** Override fields the tool changes; the others the environment has are kept. */
    private const OVERRIDES = ['cpu', 'memory_mb', 'min_scale', 'max_scale', 'scale_to_zero', 'timeout_seconds', 'scale_schedule'];

    public function name(): string
    {
        return 'environment_update';
    }

    public function title(): string
    {
        return 'Change an environment';
    }

    public function description(): string
    {
        return 'Changes an environment of a project: git branch, approval of promotions into it, auto deploy, and overrides of the project\'s config — size, scaling, timeout, env vars (merged by name; null removes the project\'s variable there) and its own timed warmer (scale_schedule: rows replace the project\'s schedule there, [] turns the warmer off there, null follows the project\'s again). Only what is given changes; the other overrides stay. A scale_schedule change reaches the running workload within a minute; other overrides with its next deploy. production only takes requires_approval (its config is the project\'s: project_update).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'environment' => Schema::string('Environment name or id.', ['maxLength' => 64]),
            'git_branch' => Schema::string('A push to this branch deploys the environment; "" removes the branch.', ['maxLength' => 255]),
            'requires_approval' => Schema::boolean('Promotions into it wait for an approver.'),
            'auto_deploy' => Schema::boolean('A push to the branch deploys the environment.'),
            'overrides' => Schema::object([
                'env_vars' => [
                    'type' => 'object',
                    'description' => 'Env vars over the project\'s, merged by name; a null value removes the project\'s variable in this environment.',
                    'additionalProperties' => ['type' => ['string', 'null'], 'maxLength' => 32768],
                ],
                'cpu' => Schema::string('CPU, e.g. "250m".', ['maxLength' => 16]),
                'memory_mb' => Schema::integer('Memory in MiB.', 64, 262144),
                'min_scale' => Schema::integer('Minimum replicas.', 0, 10),
                'max_scale' => Schema::integer('Maximum replicas.', 1, 1000),
                'scale_to_zero' => Schema::boolean('Scale to zero when idle.'),
                'timeout_seconds' => Schema::integer('Request timeout.', 1, 3600),
                'scale_schedule' => ProjectCreate::scaleScheduleSchema('The environment\'s own schedule: the rows replace the project\'s schedule there, [] turns the warmer off there, null follows the project\'s schedule again.'),
            ]),
        ], ['project', 'environment']);
    }

    public function permissions(): array
    {
        return ['environments.manage'];
    }

    public function group(): string
    {
        return 'deployments';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $name = (string) $arguments['environment'];
        $path = InternalApi::path('projects', $projectId, 'environments', $name);

        $body = [];
        foreach (['requires_approval', 'auto_deploy'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null) {
                $body[$field] = (bool) $arguments[$field];
            }
        }
        if (array_key_exists('git_branch', $arguments)) {
            $branch = trim((string) ($arguments['git_branch'] ?? ''));
            $body['git_branch'] = $branch === '' ? null : $branch;
        }

        $changes = is_array($arguments['overrides'] ?? null) ? $arguments['overrides'] : [];
        if ($changes !== []) {
            $current = $context->api()->get($path);
            if (! $current->ok()) {
                throw ToolError::fromApi($current, 'Reading the environment');
            }
            $environment = (array) $current->data();
            if (! empty($environment['is_primary'])) {
                throw new ToolError('production is the project\'s own workload: change its config with project_update.');
            }
            $body['overrides'] = self::merged((array) ($environment['overrides'] ?? []), $changes);
        }
        if ($body === []) {
            throw new ToolError('Nothing to change: pass git_branch, requires_approval, auto_deploy or overrides.');
        }

        $result = $context->api()->patch($path, $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Changing the environment');
        }
        $data = (array) $result->data();
        $context->setTarget('project', $projectId);

        $summary = EnvironmentsList::summary($data) + [
            'overrides' => $data['overrides'] ?? (object) [],
            'scale_schedule' => $data['scale_schedule'] ?? null,
            'scale_schedule_inherited' => (bool) ($data['scale_schedule_inherited'] ?? true),
        ];
        $warmer = array_key_exists('scale_schedule', $changes)
            ? ' Its timed warmer: '.(($data['scale_schedule'] ?? null) ? 'on' : 'off').(($data['scale_schedule_inherited'] ?? true) ? ' (the project\'s schedule)' : ' (its own)').' — applied to the running workload within a minute.'
            : '';

        return ToolResult::ok(['environment' => $summary], 'Environment '.($summary['name'] ?? $name).' changed.'.$warmer);
    }

    /**
     * The environment's overrides with the changes: env vars merged by name, the other fields
     * replaced; a null scale_schedule removes the environment's own schedule. Masked env vars (no
     * env.view) are not sent back — the API keeps them.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function merged(array $current, array $changes): array
    {
        $hidden = ! empty($current['env_vars_hidden']);
        unset($current['env_vars_hidden']);
        if ($hidden || ! is_array($current['env_vars'] ?? null)) {
            unset($current['env_vars']);
        }

        foreach (self::OVERRIDES as $field) {
            if (! array_key_exists($field, $changes)) {
                continue;
            }
            if ($field === 'scale_schedule') {
                if ($changes[$field] === null) {
                    unset($current[$field]);
                } else {
                    $current[$field] = ProjectCreate::scaleScheduleRows($changes[$field]);
                }

                continue;
            }
            $current[$field] = $changes[$field];
        }
        if (is_array($changes['env_vars'] ?? null)) {
            if ($hidden) {
                throw new ToolError('The environment\'s variables are hidden from you (env.view): changing them here would replace them.');
            }
            $current['env_vars'] = array_merge((array) ($current['env_vars'] ?? []), $changes['env_vars']);
        }

        return $current;
    }
}
