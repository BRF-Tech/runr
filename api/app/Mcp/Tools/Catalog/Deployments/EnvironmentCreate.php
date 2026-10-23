<?php

namespace App\Mcp\Tools\Catalog\Deployments;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Catalog\Projects\ProjectCreate;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class EnvironmentCreate extends Tool
{
    public function name(): string
    {
        return 'environment_create';
    }

    public function title(): string
    {
        return 'Create an environment';
    }

    public function description(): string
    {
        return 'Adds an environment (e.g. dev, staging) to a project: its own workload with the project\'s config and these overrides, in the project\'s region or another one. It must fit the region capacity and the organization quota. Nothing runs in it until a version is deployed or promoted there.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'name' => Schema::string('Environment name: a lowercase DNS label, e.g. "staging".', ['maxLength' => 20]),
            'region' => Schema::string('Region id; default: the project\'s region.', ['maxLength' => 26]),
            'git_branch' => Schema::string('A push to this branch deploys the environment, e.g. "develop".', ['maxLength' => 255]),
            'requires_approval' => Schema::boolean('Promotions into it wait for an approver (default false).'),
            'overrides' => Schema::object([
                'env_vars' => Schema::map(['type' => 'string', 'maxLength' => 32768], 'Env vars over the project\'s.'),
                'cpu' => Schema::string('CPU, e.g. "250m".', ['maxLength' => 16]),
                'memory_mb' => Schema::integer('Memory in MiB.', 64, 262144),
                'min_scale' => Schema::integer('Minimum replicas.', 0, 10),
                'max_scale' => Schema::integer('Maximum replicas.', 1, 1000),
                'scale_to_zero' => Schema::boolean('Scale to zero when idle.'),
                'timeout_seconds' => Schema::integer('Request timeout.', 1, 3600),
                'scale_schedule' => ProjectCreate::scaleScheduleSchema('The environment\'s own schedule (default: the project\'s); [] keeps no containers warm there.'),
            ]),
        ], ['project', 'name']);
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
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $body = array_filter([
            'name' => (string) $arguments['name'],
            'knative_region_id' => $arguments['region'] ?? null,
            'git_branch' => $arguments['git_branch'] ?? null,
            'requires_approval' => $arguments['requires_approval'] ?? null,
            'overrides' => $arguments['overrides'] ?? null,
        ], fn ($v) => $v !== null);
        if (is_array($body['overrides'] ?? null) && array_key_exists('scale_schedule', $body['overrides'])) {
            if ($body['overrides']['scale_schedule'] === null) {
                unset($body['overrides']['scale_schedule']); // the project's schedule
            } else {
                $body['overrides']['scale_schedule'] = ProjectCreate::scaleScheduleRows($body['overrides']['scale_schedule']);
            }
        }

        $result = $context->api()->post(InternalApi::path('projects', $projectId, 'environments'), $body);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Creating the environment');
        }

        $environment = EnvironmentsList::summary((array) $result->data());
        $context->setTarget('project', $projectId);

        return ToolResult::ok(['environment' => $environment], 'Environment '.$environment['name'].' created'.($environment['url'] ? ' ('.$environment['url'].')' : '').'; deploy or promote a version to it.');
    }
}
