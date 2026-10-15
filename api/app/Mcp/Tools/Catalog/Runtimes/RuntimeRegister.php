<?php

namespace App\Mcp\Tools\Catalog\Runtimes;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class RuntimeRegister extends Tool
{
    public function name(): string
    {
        return 'runtime_register';
    }

    public function title(): string
    {
        return 'Register a runtime';
    }

    public function description(): string
    {
        return 'Registers a new runtime from a manifest (runtime.yaml, apiVersion runr.sh/v1; YAML or JSON text) with optional files (a Dockerfile and its build context for dockerfile versions). Platform runtimes need runtimes.manage; an organization\'s own runtimes need runtimes.use_custom while the platform allows custom runtimes. An invalid manifest answers with every problem and its JSON path.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'manifest' => Schema::string('The manifest text (YAML or JSON).', ['minLength' => 1, 'maxLength' => 262144]),
            'files' => Schema::map(['type' => 'string'], 'Uploaded files, file name → content (Dockerfile, build context).') + [
                'propertyNames' => ['type' => 'string', 'pattern' => '^[-._a-zA-Z0-9]+$', 'maxLength' => 253],
                'maxProperties' => 50,
            ],
            'regions' => Schema::array(['type' => 'string', 'maxLength' => 64], 'Region ids the runtime is offered in; omit for every region.', 100),
        ], ['manifest']);
    }

    public function permissions(): array
    {
        return ['runtimes.manage|runtimes.use_custom'];
    }

    public function group(): string
    {
        return 'runtimes';
    }

    public function annotations(): array
    {
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $payload = ['manifest' => (string) $arguments['manifest']];
        if (! empty($arguments['files'])) {
            $payload['files'] = (array) $arguments['files'];
        }
        if (array_key_exists('regions', $arguments)) {
            $payload['regions'] = array_values((array) $arguments['regions']);
        }

        $result = $context->api()->post('/runtimes', $payload);
        if (! $result->ok()) {
            $error = ToolError::fromApi($result, 'The runtime was not registered');
            $problems = $result->get('problems');

            return is_array($problems)
                ? ToolResult::error($error->getMessage(), array_diff_key($error->details, ['message' => true]) + ['problems' => $problems])
                : $error->toResult();
        }

        $runtime = (array) $result->data();
        $context->setTarget('runtime', (string) ($runtime['id'] ?? ''), (string) ($runtime['name'] ?? ''));

        return ToolResult::ok(['runtime' => RuntimesList::summary($runtime)], 'Runtime '.($runtime['id'] ?? '?').' registered; create projects with it through project_create.');
    }
}
