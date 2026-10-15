<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class AlertRulesList extends Tool
{
    public function name(): string
    {
        return 'alert_rules_list';
    }

    public function title(): string
    {
        return 'List alert rules';
    }

    public function description(): string
    {
        return 'The organization\'s alert rules (error rate, p95 latency, cold start rate, uptime down, resource thresholds) with their scope, thresholds, severity, channels, current state and last value — optionally only those of one project.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::string('Only rules of this project (id, slug or name).', ['maxLength' => 255]),
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

        $result = $context->api()->get('/observability/alert-rules');
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the alert rules');
        }

        $rules = array_values(array_filter((array) $result->data(), fn ($r) => is_array($r) && ($projectId === null || ($r['project_id'] ?? null) === $projectId)));
        $firing = count(array_filter($rules, fn ($r) => ($r['state'] ?? null) === 'firing'));

        return ToolResult::ok(['rules' => $rules, 'count' => count($rules), 'firing' => $firing], count($rules)." alert rule(s), {$firing} firing.");
    }
}
