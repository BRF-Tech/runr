<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * project_cost_limit_set (Billing module, docs/billing.md#monthly-cost-limit): set, change or
 * remove a pay-as-you-go project's monthly cost limit, and resume a project the limit stopped —
 * through the project billing API (PUT / DELETE …/billing/cost-limit, POST …/cost-limit/resume),
 * with its permission checks. A "stop" limit the month already passed would stop the project at
 * once: that needs stop_now.
 */
class ProjectCostLimitSet extends Tool
{
    public function name(): string
    {
        return 'project_cost_limit_set';
    }

    public function title(): string
    {
        return 'Set the monthly cost limit';
    }

    public function description(): string
    {
        return 'Sets a pay-as-you-go project\'s monthly cost limit in the billing currency (billing module): at 80 % the owner is notified, at 100 % notified again or — with action "stop" — the project is stopped until the limit is raised or removed, or the next month begins, and it is resumed. remove: true removes the limit (a stopped project stays stopped); resume: true resumes a project its limit stopped once the limit allows it. A "stop" limit below this month\'s cost so far stops the project right away and needs stop_now: true. For the project\'s owner, members with projects.billing on it and billing.manage holders.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'amount' => ['type' => 'number', 'minimum' => 0.001, 'maximum' => 1000000000, 'description' => 'The monthly limit in the billing currency (up to three decimals).'],
            'action' => Schema::enum(['notify', 'stop'], 'What happens at 100 %: notify the owner, or stop the project.', 'notify'),
            'remove' => Schema::boolean('Remove the limit instead.'),
            'resume' => Schema::boolean('Resume the project if its limit stopped it and no longer stops it (after the change, if any).'),
            'stop_now' => Schema::boolean('Confirm a "stop" limit that this month already passed: the project is stopped right away.'),
        ], ['project']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function group(): string
    {
        return 'projects';
    }

    public function annotations(): array
    {
        return self::additive(true);
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $path = InternalApi::path('projects', $projectId, 'billing', 'cost-limit');
        $remove = (bool) ($arguments['remove'] ?? false);
        $resume = (bool) ($arguments['resume'] ?? false);
        $amount = $arguments['amount'] ?? null;

        if ($remove && $amount !== null) {
            throw new ToolError('Give either amount (to set the limit) or remove: true, not both.');
        }
        if (! $remove && $amount === null && ! $resume) {
            throw new ToolError('Nothing to do: give amount (and action), remove: true, or resume: true.');
        }

        $status = null;
        $done = [];
        if ($remove) {
            $result = $context->api()->delete($path);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'The cost limit was not removed');
            }
            $status = (array) $result->data();
            $done[] = 'Limit removed.';
        } elseif ($amount !== null) {
            $action = (string) ($arguments['action'] ?? 'notify');
            if ($action === 'stop' && ! ($arguments['stop_now'] ?? false)) {
                $this->refuseImmediateStop($context, $path, (float) $amount);
            }

            $result = $context->api()->put($path, ['amount' => $amount, 'action' => $action]);
            if (! $result->ok()) {
                throw ToolError::fromApi($result, 'The cost limit was not saved');
            }
            $status = (array) $result->data();
            $done[] = 'Limit saved.';
        }

        $resumed = null;
        if ($resume) {
            $result = $context->api()->post($path.'/resume');
            if (! $result->ok()) {
                throw ToolError::fromApi($result, implode(' ', $done).($done ? ' ' : '').'The project was not resumed');
            }
            $status = (array) $result->data();
            $resumed = [
                'deployment_id' => $result->get('deployment_id'),
                'redeploy_error' => $result->get('redeploy_error'),
                'redeploy_code' => $result->get('redeploy_code'),
            ];
            $done[] = $resumed['redeploy_error'] ? 'Project resumed, not redeployed: '.$resumed['redeploy_error'] : 'Project resumed.';
        }

        return ToolResult::ok(['cost_limit' => $status] + ($resumed !== null ? ['resume' => $resumed] : []),
            implode(' ', $done).' '.ProjectCostLimitGet::summary((array) $status));
    }

    /** A "stop" limit at or below this month's cost so far stops the project at once: not without stop_now. */
    private function refuseImmediateStop(ToolContext $context, string $path, float $amount): void
    {
        $current = $context->api()->get($path);
        if (! $current->ok()) {
            throw ToolError::fromApi($current, 'Reading the cost limit');
        }

        $sofar = (float) ($current->get('data.month_to_date') ?? 0);
        if (($current->get('data.available') ?? false) && $sofar >= $amount) {
            throw new ToolError(sprintf(
                'This month\'s cost so far (%s %s) is already at or above %s: a "stop" limit stops the project right away. Pass stop_now: true to do that, or choose a higher amount or action "notify".',
                number_format($sofar, 2, '.', ''), (string) $current->get('data.currency', ''), number_format($amount, 2, '.', ''),
            ));
        }
    }
}
