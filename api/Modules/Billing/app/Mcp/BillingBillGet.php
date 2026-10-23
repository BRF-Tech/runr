<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Server\InternalApi;
use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_bill_get (docs/billing.md#the-bill): one bill with its line items — through the
 * project's billing route when `project` is given (its owner and projects.billing holders see it
 * there), else the organization's (billing.view), the platform's bill to the tenant or a bill of
 * the platform to a tenant.
 */
class BillingBillGet extends BillingTool
{
    /** scope => API path of a bill */
    private const SCOPES = [
        'projects' => 'billing/bills',
        'from_platform' => 'billing/platform-bills',
        'to_tenants' => 'billing/tenant-bills',
    ];

    public function name(): string
    {
        return 'billing_bill_get';
    }

    public function title(): string
    {
        return 'One bill';
    }

    public function description(): string
    {
        return 'One bill with its line items (billing module): each line\'s description, quantity, unit, unit price, multiplier, markup and total, and what it is for — the project\'s own usage or fixed allocation, its preview environments (component "preview"), its queue consumer (component "queue_consumer"), bindings, model services. An open bill is this month\'s estimate as of now. Give project to read a bill of that project as its owner or a projects.billing holder; without it the organization\'s bills need billing.view. scope "from_platform" / "to_tenants" as in billing_bills_list.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'bill' => Schema::string('The bill id (billing_bills_list, project_billing_get).', ['minLength' => 1, 'maxLength' => 64]),
            'project' => Schema::string('The project the bill belongs to (id, slug or exact name): reads it through the project\'s billing.'),
            'scope' => Schema::enum(array_keys(self::SCOPES), 'Whose bill, without project.', 'projects'),
        ], ['bill']);
    }

    public function permissions(): array
    {
        return ['billing.view|projects.view'];
    }

    public function annotations(): array
    {
        return self::readOnly();
    }

    public function handle(array $arguments, ToolContext $context): ToolResult
    {
        $bill = (string) $arguments['bill'];
        if (isset($arguments['project'])) {
            if (($arguments['scope'] ?? 'projects') !== 'projects') {
                throw new ToolError('project reads a project bill: leave scope out.');
            }
            $path = InternalApi::path('projects', $context->projectId((string) $arguments['project']), 'billing', 'bills', $bill);
        } else {
            $path = self::SCOPES[(string) ($arguments['scope'] ?? 'projects')].'/'.rawurlencode($bill);
        }

        $result = $context->api()->get($path);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the bill');
        }

        $data = (array) $result->data();
        $lines = array_values((array) ($data['line_items'] ?? []));
        $data['by_component'] = self::byComponent($lines);

        return ToolResult::ok($data, self::summary($data, $lines));
    }

    /** @param list<array<string, mixed>> $lines */
    private static function summary(array $bill, array $lines): string
    {
        $currency = (string) ($bill['currency'] ?? '');
        $head = sprintf('%s bill %s%s: %s%s, %d line(s).',
            ucfirst((string) ($bill['kind'] ?? '')), (string) ($bill['period'] ?? ''),
            isset($bill['project']['name']) ? ' of '.$bill['project']['name'] : '',
            self::amount($bill['total'] ?? 0, $currency),
            ($bill['status'] ?? null) === 'open' ? ' (estimate so far)' : (($bill['paid'] ?? false) ? ' (paid)' : ' (unpaid)'),
            count($lines));

        $parts = [];
        foreach ((array) ($bill['by_component'] ?? []) as $component => $sum) {
            $label = match ($component) {
                'preview' => 'preview environments',
                'queue_consumer' => 'queue consumer',
                'other' => 'other resources',
                default => 'the project',
            };
            $parts[] = $label.' '.self::amount($sum['total'], $currency);
        }

        return $head.($parts !== [] ? ' '.ucfirst(implode(', ', $parts)).'.' : '');
    }
}
