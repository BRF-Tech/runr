<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class AlertRuleCreate extends Tool
{
    use ManagesAlerts, ReadsProjects;

    public function name(): string
    {
        return 'alert_rule_create';
    }

    public function title(): string
    {
        return 'Create an alert rule';
    }

    public function description(): string
    {
        return 'Adds an alert rule: error_rate / cold_start_rate (params.threshold in percent), p95_latency (params.threshold_ms), uptime_down (an uptime check failing) or resource_threshold (params.resource, params.min_level), optionally limited to one project or region, with a severity, a cooldown and the channels it sends to (alert_channels_list). Fired alerts appear in alert_events_list.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'name' => Schema::string('Name of the rule.', ['minLength' => 1, 'maxLength' => 100]),
            'type' => Schema::enum(self::$ruleTypes, 'What the rule watches.'),
            'project' => Schema::string('Only this project (id, slug or name).', ['maxLength' => 255]),
            'region' => Schema::string('Only this region (id or name).', ['maxLength' => 64]),
            'params' => self::ruleParamsSchema(),
            'severity' => Schema::enum(self::$severities, 'Severity of the alerts (default warning).'),
            'cooldown_minutes' => Schema::integer('Minutes before the same alert is sent again.', 0, 10080),
            'channels' => Schema::array(Schema::string('Channel id or name.', ['maxLength' => 100]), 'Channels the alerts go to.', 20),
            'is_enabled' => Schema::boolean('Evaluate the rule (default true).', true),
        ], ['name', 'type']);
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
        return self::additive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $body = array_filter([
            'name' => (string) $arguments['name'],
            'type' => (string) $arguments['type'],
            'project_id' => isset($arguments['project']) ? $context->projectId((string) $arguments['project']) : null,
            'region_id' => isset($arguments['region']) ? $this->regionId($context, (string) $arguments['region']) : null,
            'params' => isset($arguments['params']) ? (object) $arguments['params'] : null,
            'severity' => $arguments['severity'] ?? null,
            'cooldown_minutes' => $arguments['cooldown_minutes'] ?? null,
            'channel_ids' => isset($arguments['channels']) ? $this->channelIds($context, (array) $arguments['channels']) : null,
            'is_enabled' => $arguments['is_enabled'] ?? null,
        ], fn ($v) => $v !== null);

        $rule = (array) $this->alertCall($context, 'POST', '/observability/alert-rules', $body, 'The alert rule was not created')->data();
        $context->setTarget('alert_rule', (string) ($rule['id'] ?? ''), (string) ($rule['name'] ?? ''));

        return ToolResult::ok(['rule' => $rule], 'Alert rule '.($rule['name'] ?? '').' created.');
    }
}
