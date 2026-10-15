<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class ObservabilityGet extends Tool
{
    public function name(): string
    {
        return 'observability_get';
    }

    public function title(): string
    {
        return 'Tracing and replay settings';
    }

    public function description(): string
    {
        return 'A project\'s observability switches: OpenTelemetry tracing (on/off, sample ratio, whether the region\'s tracing backend is ready and searchable) and request replay (capture of failed requests: on/off, body limit, retention, redaction lists). Changes take effect with the next deploy.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'observability';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'observability'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the observability settings');
        }

        $data = self::shape((array) $result->data());

        return ToolResult::ok($data, sprintf(
            'Tracing %s, replay capture %s.',
            $data['tracing']['enabled'] ? 'on' : 'off',
            $data['replay']['enabled'] ? 'on' : 'off',
        ));
    }

    /** The settings payload of the API, grouped. */
    public static function shape(array $data): array
    {
        return [
            'tracing' => [
                'enabled' => (bool) ($data['tracing_enabled'] ?? false),
                'sample_ratio' => $data['tracing_sample_ratio'] ?? null,
                'region' => $data['region_tracing'] ?? null,
            ],
            'replay' => [
                'enabled' => (bool) ($data['replay_enabled'] ?? false),
                'available' => (bool) ($data['replay_available'] ?? false),
                'target' => $data['replay_target'] ?? null,
                'max_body_bytes' => $data['replay_max_body_bytes'] ?? null,
                'retention_days' => $data['replay_retention_days'] ?? null,
                'redact_headers' => $data['replay_redact_headers'] ?? [],
                'redact_query' => $data['replay_redact_query'] ?? [],
            ],
        ];
    }
}
