<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Concerns\ReadsProjects;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

class AlertRuleUpdate extends Tool
{
    use ManagesAlerts, ReadsProjects;

    public function name(): string
    {
        return 'alert_rule_update';
    }

    public function title(): string
    {
        return 'Change an alert rule';
    }

    public function description(): string
    {
        return 'Changes an alert rule: name, scope (project, region), params, severity, cooldown, channels (the full new list) or enabled flag. The type cannot change — delete the rule and create a new one for that.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'rule' => Schema::string('The rule: id or exact name (alert_rules_list).', ['minLength' => 1, 'maxLength' => 100]),
            'name' => Schema::string('New name.', ['minLength' => 1, 'maxLength' => 100]),
            'project' => Schema::string('Only this project (id, slug or name).', ['maxLength' => 255]),
            'region' => Schema::string('Only this region (id or name).', ['maxLength' => 64]),
            'params' => self::ruleParamsSchema(),
            'severity' => Schema::enum(self::$severities, 'Severity of the alerts.'),
            'cooldown_minutes' => Schema::integer('Minutes before the same alert is sent again.', 0, 10080),
            'channels' => Schema::array(Schema::string('Channel id or name.', ['maxLength' => 100]), 'The channels the alerts go to (replaces the list; [] sends nowhere).', 20),
            'is_enabled' => Schema::boolean('Evaluate the rule.'),
        ], ['rule']);
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
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $body = array_filter([
            'name' => $arguments['name'] ?? null,
            'project_id' => isset($arguments['project']) ? $context->projectId((string) $arguments['project']) : null,
            'region_id' => isset($arguments['region']) ? $this->regionId($context, (string) $arguments['region']) : null,
            'params' => isset($arguments['params']) ? (object) $arguments['params'] : null,
            'severity' => $arguments['severity'] ?? null,
            'cooldown_minutes' => $arguments['cooldown_minutes'] ?? null,
            'is_enabled' => $arguments['is_enabled'] ?? null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('channels', $arguments)) {
            $body['channel_ids'] = $this->channelIds($context, (array) $arguments['channels']);
        }
        if ($body === []) {
            throw new ToolError('Nothing to change: give at least one field besides rule.');
        }

        $id = $this->ruleId($context, (string) $arguments['rule']);
        $rule = (array) $this->alertCall($context, 'PUT', self::rulePath($id), $body, 'The alert rule was not changed')->data();
        $context->setTarget('alert_rule', $id, (string) ($rule['name'] ?? ''));

        return ToolResult::ok(['rule' => $rule], 'Alert rule '.($rule['name'] ?? $id).' changed.');
    }
}
