<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * project_billing_quote (docs/billing.md#quote): the monthly price of fixed resources for a project
 * in its region and what an approval today would bill this month, through GET
 * /api/projects/{project}/billing/quote.
 */
class ProjectBillingQuote extends BillingTool
{
    public function name(): string
    {
        return 'project_billing_quote';
    }

    public function title(): string
    {
        return 'Quote fixed resources';
    }

    public function description(): string
    {
        return 'What fixed resources would cost a project (billing module): the monthly price of cpu_m millicores, memory_mb MiB and gpu_count GPUs at its region\'s prices (with the markup of a shared region), the breakdown, and what an approval today bills this month (prorated by day). Use it before project_fixed_request. Visible to the project\'s owner, projects.billing and billing.view holders.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'project' => Schema::project(),
            'cpu_m' => Schema::integer('CPU in millicores (1000 = 1 vCPU).', 100, 1000000),
            'memory_mb' => Schema::integer('Memory in MiB.', 128, 10000000),
            'gpu_count' => Schema::integer('GPUs.', 0, 64),
        ], ['project', 'cpu_m', 'memory_mb']);
    }

    public function permissions(): array
    {
        return ['projects.view'];
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $projectId = $context->projectId((string) $arguments['project']);
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'billing', 'quote'), [
            'cpu_m' => (int) $arguments['cpu_m'],
            'memory_mb' => (int) $arguments['memory_mb'],
            'gpu_count' => (int) ($arguments['gpu_count'] ?? 0),
        ]);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Quoting the resources');
        }

        $quote = (array) $result->json;
        $currency = (string) ($quote['currency'] ?? '');
        $first = $quote['first_month'] ?? null;
        $firstAmount = is_array($first) ? ($first['amount'] ?? $first['total'] ?? null) : $first;

        return ToolResult::ok($quote, sprintf('%s m CPU, %s MiB%s in %s (%s): %s a month%s.',
            $quote['cpu_m'] ?? '?', $quote['memory_mb'] ?? '?', ($quote['gpu_count'] ?? 0) > 0 ? ', '.$quote['gpu_count'].' GPU(s)' : '',
            (string) ($quote['region']['name'] ?? ''), (string) ($quote['billing_type'] ?? ''),
            self::amount($quote['monthly_price'] ?? 0, $currency),
            $firstAmount !== null ? '; approved today, this month bills '.self::amount($firstAmount, $currency) : ''));
    }
}
