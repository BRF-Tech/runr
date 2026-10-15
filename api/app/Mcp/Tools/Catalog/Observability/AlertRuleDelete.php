<?php

namespace App\Mcp\Tools\Catalog\Observability;

use App\Mcp\Tools\Concerns\ManagesAlerts;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolResult;

class AlertRuleDelete extends Tool
{
    use ManagesAlerts;

    public function name(): string
    {
        return 'alert_rule_delete';
    }

    public function title(): string
    {
        return 'Delete an alert rule';
    }

    public function description(): string
    {
        return 'Deletes an alert rule; its firing alerts are closed without a resolved message. Ask the user before confirming.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'rule' => Schema::string('The rule: id or exact name (alert_rules_list).', ['minLength' => 1, 'maxLength' => 100]),
            'confirm' => Schema::confirm('deleting the alert rule'),
        ], ['rule', 'confirm']);
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
        return self::destructive();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $id = $this->ruleId($context, (string) $arguments['rule']);
        $context->setTarget('alert_rule', $id);

        $this->alertCall($context, 'DELETE', self::rulePath($id), [], 'The alert rule was not deleted');

        return ToolResult::ok(['deleted' => true, 'rule_id' => $id], 'Alert rule deleted.');
    }
}
