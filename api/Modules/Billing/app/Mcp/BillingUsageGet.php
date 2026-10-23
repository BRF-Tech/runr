<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_usage_get (docs/billing.md#usage): the metered usage of the organization's projects in a
 * month — vCPU-hours, GB-hours, GPU-hours, sessions — and what it costs at pay-as-you-go prices,
 * through GET /api/billing/usage.
 */
class BillingUsageGet extends BillingTool
{
    public function name(): string
    {
        return 'billing_usage_get';
    }

    public function title(): string
    {
        return 'Metered usage';
    }

    public function description(): string
    {
        return 'Metered usage per project in a month (billing module): vCPU-hours, memory GB-hours, GPU-hours and sessions of every workload — the project\'s own, its preview environments and its queue consumer included —, the regions, the payer and the pay-as-you-go cost at each day\'s prices (also for fixed projects, whose bill is the fixed price). period YYYY-MM (default: this month so far), project to narrow it to one. Needs billing.view.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'period' => self::periodSchema('The month, YYYY-MM (default: this month so far).'),
            'project' => Schema::string('Only this project (id, slug or exact name).'),
        ]);
    }

    public function permissions(): array
    {
        return ['billing.view'];
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $query = array_filter([
            'period' => $arguments['period'] ?? null,
            'project' => isset($arguments['project']) ? $context->projectId((string) $arguments['project']) : null,
        ], fn ($v) => $v !== null);

        $result = $context->api()->get('billing/usage', $query);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the usage');
        }

        $usage = (array) $result->json;
        $rows = (array) ($usage['data'] ?? []);
        $currency = (string) ($usage['currency'] ?? '');
        $sum = ['vcpu_hours' => 0.0, 'memory_gb_hours' => 0.0, 'gpu_hours' => 0.0, 'cost' => 0.0];
        foreach ($rows as $row) {
            foreach (['vcpu_hours', 'memory_gb_hours', 'gpu_hours'] as $key) {
                $sum[$key] += (float) ($row['quantities'][$key] ?? 0);
            }
            $sum['cost'] += (float) ($row['cost'] ?? 0);
        }

        return ToolResult::ok($usage, sprintf('%s (%s to %s): %d project(s), %s vCPU-hours, %s GB-hours, %s GPU-hours; %s at pay-as-you-go prices.',
            (string) ($usage['period'] ?? ''), (string) ($usage['from'] ?? ''), (string) ($usage['to'] ?? ''), count($rows),
            round($sum['vcpu_hours'], 2), round($sum['memory_gb_hours'], 2), round($sum['gpu_hours'], 2), self::amount($sum['cost'], $currency)));
    }
}
