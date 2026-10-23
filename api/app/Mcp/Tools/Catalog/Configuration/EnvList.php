<?php

namespace App\Mcp\Tools\Catalog\Configuration;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Concerns\ChangesEnv;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class EnvList extends Tool
{
    use ChangesEnv;

    public function name(): string
    {
        return 'env_list';
    }

    public function title(): string
    {
        return 'List environment variables';
    }

    public function description(): string
    {
        return 'Lists a project\'s environment variables: the names of plain variables (their values with include_values) and the names of secret variables, with where each secret lives (secret_details: a value RunR wrote into the region, or a reference to a secret store and its sync status). Secret values are write-only and never listed; secret_reveal reads one when the connection holds secrets.view_values.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'include_values' => Schema::boolean('Also return the values of the plain variables (default false).', false),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['env.view'];
    }

    public function group(): string
    {
        return 'configuration';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $env = $this->env($context, $context->projectId((string) $arguments['project']));
        $names = array_keys($env['plain']);
        sort($names);

        $plain = ! empty($arguments['include_values']) ? ($env['plain'] === [] ? new \stdClass : $env['plain']) : $names;

        // Where each secret lives: a value RunR wrote into the region, or a reference to a secret
        // store with what External Secrets Operator reported (never a value)
        $details = [];
        if ($env['secret'] !== []) {
            $list = $context->api()->get(InternalApi::path('projects', $context->projectId((string) $arguments['project']), 'secrets'));
            if ($list->ok()) {
                foreach ((array) $list->get('data', []) as $secret) {
                    $details[] = array_filter([
                        'name' => $secret['name'] ?? null,
                        'source' => $secret['source'] ?? 'region',
                        'version' => $secret['version'] ?? null,
                        'stored_in' => $secret['stored_in'] ?? null,
                        'reference' => $secret['reference'] ?? null,
                        'sync' => $secret['sync'] ?? null,
                    ], fn ($v) => $v !== null);
                }
            }
        }

        return ToolResult::ok([
            'plain' => $plain,
            'secret' => $env['secret'],
            'secret_details' => $details,
        ], count($names).' plain and '.count($env['secret']).' secret variable(s).');
    }
}
