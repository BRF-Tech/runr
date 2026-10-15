<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ObservabilityUpdate extends Tool
{
    public function name(): string
    {
        return 'observability_update';
    }

    public function title(): string
    {
        return 'Switch tracing or replay';
    }

    public function description(): string
    {
        return 'Switches OpenTelemetry tracing (needs projects.update; the region\'s tracing backend must be ready) and / or the capture of failed requests for replay (needs replay.manage; code runtimes only) of a project. Captured requests can hold personal data: keep the retention short. Both take effect with the next deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'tracing' => Schema::object([
                'enabled' => Schema::boolean('Trace the project\'s requests.'),
                'sample_ratio' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1, 'description' => 'Share of requests traced, 0–1.'],
            ], ['enabled']),
            'replay' => Schema::object([
                'enabled' => Schema::boolean('Capture failed requests (status ≥ 500, timeouts).'),
                'max_body_bytes' => Schema::integer('Largest captured body in bytes.', 0, 1048576),
                'retention_days' => Schema::integer('Days a capture is kept.', 1, 30),
                'redact_headers' => Schema::array(Schema::string('Header name.', ['maxLength' => 100, 'pattern' => '^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$']), 'Extra headers never captured.', 50),
                'redact_query' => Schema::array(Schema::string('Query parameter name.', ['maxLength' => 100]), 'Extra query parameters whose values are replaced.', 50),
            ], ['enabled']),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.update|replay.manage'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        if (! isset($arguments['tracing']) && ! isset($arguments['replay'])) {
            throw new ToolError('Give tracing and / or replay settings to change.');
        }

        $projectId = $context->projectId((string) $arguments['project']);
        $last = null;
        $redeploy = false;

        foreach (['tracing', 'replay'] as $part) {
            if (! isset($arguments[$part])) {
                continue;
            }
            $result = $context->api()->put(InternalApi::path('projects', $projectId, 'observability', $part), (array) $arguments[$part]);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, ucfirst($part).' was not changed');
            }
            $last = $result;
            $redeploy = $redeploy || (bool) $result->get('requires_redeploy', false);
        }

        $data = ObservabilityGet::shape((array) $last->data()) + ['requires_redeploy' => $redeploy];

        return ToolResult::ok($data, 'Saved.'.($redeploy ? ' Deploy the project to apply it.' : ''));
    }
}
