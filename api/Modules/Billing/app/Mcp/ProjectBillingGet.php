<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * project_billing_get (docs/billing.md#in-the-console): a project's Billing tab — billing type,
 * payer, fixed allocation and pending request, this month's estimate with its lines (the
 * project's own usage, its preview environments, its queue consumer), past bills and the monthly
 * cost limit — through GET /api/projects/{project}/billing.
 */
class ProjectBillingGet extends BillingTool
{
    public function name(): string
    {
        return 'project_billing_get';
    }

    public function title(): string
    {
        return 'A project\'s billing';
    }

    public function description(): string
    {
        return 'A project\'s billing (billing module), as its Billing tab shows it: the region\'s billing type (payg or fixed), the payer, the approved fixed allocation and a pending request, this month\'s bill so far with its lines and their split — the project\'s own usage or allocation, its preview environments (pull request previews, metered at the project\'s size) and its queue consumer —, the last closed bills and the monthly cost limit. For the project\'s owner, members with projects.billing on it and billing.view holders.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['project' => Schema::project()], ['project']);
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
        $result = $context->api()->get(InternalApi::path('projects', $projectId, 'billing'));
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the project\'s billing');
        }

        $billing = (array) $result->json;
        $current = is_array($billing['current'] ?? null) ? $billing['current'] : null;
        $split = $current ? self::byComponent(array_values((array) ($current['line_items'] ?? []))) : [];
        $billing['current_by_component'] = $split;
        $billing['bills'] = array_map(fn ($bill) => self::billRow((array) $bill), (array) ($billing['bills'] ?? []));

        return ToolResult::ok($billing, self::summary($billing, $current, $split));
    }

    /** @param array<string, array{total: float, lines: int}> $split */
    private static function summary(array $billing, ?array $current, array $split): string
    {
        $currency = (string) ($billing['currency'] ?? '');
        $parts = [sprintf('Billing type %s in %s.', (string) ($billing['billing_type'] ?? '—'), (string) ($billing['region']['name'] ?? 'no region'))];

        if (is_array($billing['allocation'] ?? null)) {
            $a = $billing['allocation'];
            $parts[] = sprintf('Fixed allocation %s vCPU, %s GB%s at %s a month.', $a['vcpu'] ?? '?', $a['memory_gb'] ?? '?',
                ($a['gpu_count'] ?? 0) > 0 ? ', '.$a['gpu_count'].' GPU(s)' : '', self::amount($a['monthly_price'] ?? 0, $currency));
        }
        if (is_array($billing['pending_request'] ?? null)) {
            $parts[] = 'A fixed request waits for approval ('.($billing['pending_request']['id'] ?? '').').';
        }

        if ($current !== null) {
            $text = 'This month so far '.self::amount($current['total'] ?? 0, $currency);
            if (isset($split['preview'])) {
                $text .= ', of it preview environments '.self::amount($split['preview']['total'], $currency);
            }
            if (isset($split['queue_consumer'])) {
                $text .= ', queue consumer '.self::amount($split['queue_consumer']['total'], $currency);
            }
            $parts[] = $text.'.';
            if (! isset($split['preview'])) {
                $parts[] = 'No preview environment usage this month.';
            }
        }

        $limit = $billing['cost_limit'] ?? null;
        if (is_array($limit) && ($limit['available'] ?? false)) {
            $parts[] = ProjectCostLimitGet::summary($limit);
        }
        $parts[] = count((array) ($billing['bills'] ?? [])).' closed bill(s).';

        return implode(' ', $parts);
    }
}
