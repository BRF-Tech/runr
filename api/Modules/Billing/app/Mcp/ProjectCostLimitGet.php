<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\Tool;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * project_cost_limit_get (Billing module, docs/billing.md#monthly-cost-limit): the monthly cost
 * limit of a pay-as-you-go project with this month so far and the month-end forecast — through
 * GET /api/projects/{project}/billing/cost-limit, so the caller sees what the Billing tab shows.
 */
class ProjectCostLimitGet extends Tool
{
    public function name(): string
    {
        return 'project_cost_limit_get';
    }

    public function title(): string
    {
        return 'Monthly cost limit';
    }

    public function description(): string
    {
        return 'A pay-as-you-go project\'s monthly cost limit (billing module): the amount in the billing currency, what happens at 100 % (notify, or stop the project), this month\'s cost so far, the month-end forecast (so far ÷ elapsed days × days in the month), the share of the limit used, and whether the limit stopped the project. Fixed-price projects have no limit (available: false). Visible to the project\'s owner, members with projects.billing on it and billing.view holders.';
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
        return 'projects';
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);

        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'billing', 'cost-limit'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the cost limit');
        }

        $status = (array) $result->data();

        return ToolResult::ok($status, self::summary($status));
    }

    /** One line about a cost limit status of the API. */
    public static function summary(array $status): string
    {
        if (! ($status['available'] ?? false)) {
            return ($status['stopped'] ?? false)
                ? 'The project is stopped by its cost limit; it is not billed pay-as-you-go now, so nothing keeps it stopped.'
                : 'No cost limit: the project is not billed pay-as-you-go (fixed price) or its region charges nothing for usage.';
        }

        $currency = (string) ($status['currency'] ?? '');
        $sofar = sprintf('This month so far %s %s, forecast %s %s.', self::money($status['month_to_date'] ?? 0), $currency, self::money($status['forecast'] ?? 0), $currency);
        $limit = $status['limit'] ?? null;
        if (! is_array($limit)) {
            return 'No cost limit set. '.$sofar;
        }

        return sprintf('Limit %s %s (%s at 100 %%), %s %% used. %s%s',
            self::money($limit['amount'] ?? 0), $currency, ($limit['action'] ?? '') === 'stop' ? 'stop' : 'notify',
            (string) ($status['percent'] ?? 0), $sofar,
            ($status['stopped'] ?? false) ? ' The limit stopped the project'.(($status['can_resume'] ?? false) ? '; it can be resumed (project_cost_limit_set with resume: true).' : '.') : '');
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
