<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class AlertEventsList extends Tool
{
    public function name(): string
    {
        return 'alert_events_list';
    }

    public function title(): string
    {
        return 'List fired alerts';
    }

    public function description(): string
    {
        return 'Alerts that fired in the organization, newest first: type, severity, firing or resolved, title, message, the measured data and when they fired and resolved — optionally only firing ones or only those of one project.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'status' => Schema::enum(['firing', 'resolved'], 'Only firing or only resolved alerts.'),
            'project' => Schema::string('Only alerts of this project (id, slug or name).', ['maxLength' => 255]),
            'limit' => Schema::integer('At most this many alerts (default 50).', 1, 200),
        ]);
    }

    public function permissions(): array
    {
        return ['alerts.manage'];
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
        $projectId = isset($arguments['project']) ? $context->projectId((string) $arguments['project']) : null;

        $result = $context->api()->get('/observability/alert-events', ['status' => $arguments['status'] ?? null]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the alerts');
        }

        $events = array_values(array_filter((array) $result->data(), fn ($e) => is_array($e) && ($projectId === null || ($e['project_id'] ?? null) === $projectId)));
        $events = array_slice($events, 0, (int) ($arguments['limit'] ?? 50));
        $firing = count(array_filter($events, fn ($e) => ($e['status'] ?? null) === 'firing'));

        return ToolResult::ok(['alerts' => $events, 'count' => count($events), 'firing' => $firing], count($events)." alert(s), {$firing} firing.");
    }
}
