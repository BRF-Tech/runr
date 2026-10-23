<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Tools\Schema;
use App\Mcp\Tools\ToolContext;
use App\Mcp\Tools\ToolError;
use App\Mcp\Tools\ToolResult;

/**
 * billing_bills_list (docs/billing.md#bills): the organization's bills — its projects' and
 * members' (GET /api/billing/bills), what the platform bills the tenant (GET
 * /api/billing/platform-bills) or, for platform administrators, the platform's bills to its
 * tenants (GET /api/billing/tenant-bills) — with the filters of the API.
 */
class BillingBillsList extends BillingTool
{
    /** scope => API path */
    private const SCOPES = [
        'projects' => 'billing/bills',
        'from_platform' => 'billing/platform-bills',
        'to_tenants' => 'billing/tenant-bills',
    ];

    public function name(): string
    {
        return 'billing_bills_list';
    }

    public function title(): string
    {
        return 'Bills';
    }

    public function description(): string
    {
        return 'Bills (billing module), newest month first: kind (project or account), month, open (an estimate as of now) or closed, project, payer, billing type, total, currency and paid status. scope "projects" (default): the bills the organization makes for its projects and members; "from_platform": inside a tenant, the platform\'s bills to it; "to_tenants": the platform\'s bills to its tenants (platform administrators). Filters: period (YYYY-MM), status, paid, project, owner (a user id), kind, tenant (to_tenants). For one bill\'s line items use billing_bill_get. Needs billing.view.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'scope' => Schema::enum(array_keys(self::SCOPES), 'Whose bills.', 'projects'),
            'period' => self::periodSchema('The month, YYYY-MM.'),
            'status' => Schema::enum(['open', 'closed'], 'Open bills are this month\'s estimates; closed ones are final.'),
            'paid' => Schema::boolean('Only paid (true) or unpaid (false) bills.'),
            'project' => Schema::string('scope projects: the bills of this project (id, slug or exact name).'),
            'owner' => Schema::string('scope projects: the bills this user pays (user id).', ['maxLength' => 64]),
            'kind' => Schema::enum(['project', 'account'], 'scope projects: project bills, or the account bills of members.'),
            'tenant' => Schema::string('scope to_tenants: one tenant (id or slug).', ['maxLength' => 64]),
            'limit' => Schema::integer('Bills to return (default 50).', 1, 200),
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
        $scope = (string) ($arguments['scope'] ?? 'projects');
        $query = array_filter([
            'period' => $arguments['period'] ?? null,
            'status' => $arguments['status'] ?? null,
            'paid' => array_key_exists('paid', $arguments) ? ($arguments['paid'] ? 'true' : 'false') : null,
            'per_page' => (int) ($arguments['limit'] ?? 50),
        ], fn ($v) => $v !== null);

        if ($scope === 'projects') {
            if (isset($arguments['project'])) {
                $query['project'] = $context->projectId((string) $arguments['project']);
            }
            $query += array_filter(['owner' => $arguments['owner'] ?? null, 'kind' => $arguments['kind'] ?? null], fn ($v) => $v !== null);
        } else {
            foreach (['project', 'owner', 'kind'] as $key) {
                if (isset($arguments[$key])) {
                    throw new ToolError("{$key} filters scope \"projects\" only.");
                }
            }
        }
        if (isset($arguments['tenant'])) {
            if ($scope !== 'to_tenants') {
                throw new ToolError('tenant filters scope "to_tenants" only.');
            }
            $query['tenant'] = (string) $arguments['tenant'];
        }

        $result = $context->api()->get(self::SCOPES[$scope], $query);
        if (! $result->ok()) {
            throw ToolError::fromApi($result, 'Reading the bills');
        }

        $bills = array_map(fn ($bill) => self::billRow((array) $bill), (array) ($result->get('data') ?? []));
        $total = (int) ($result->get('meta.total') ?? count($bills));

        return ToolResult::ok(['scope' => $scope, 'total' => $total, 'bills' => $bills], self::summary($bills, $total));
    }

    /** @param list<array<string, mixed>> $bills */
    private static function summary(array $bills, int $total): string
    {
        if ($bills === []) {
            return 'No bills.';
        }

        $byCurrency = [];
        $open = $unpaid = 0;
        foreach ($bills as $bill) {
            $currency = (string) ($bill['currency'] ?? '');
            $byCurrency[$currency] = ($byCurrency[$currency] ?? 0.0) + (float) ($bill['total'] ?? 0);
            $open += ($bill['status'] ?? null) === 'open' ? 1 : 0;
            $unpaid += ($bill['status'] ?? null) === 'closed' && ! ($bill['paid'] ?? false) ? 1 : 0;
        }
        $sums = implode(', ', array_map(fn ($c, $sum) => self::amount($sum, $c), array_keys($byCurrency), $byCurrency));

        return sprintf('%d bill(s)%s, %s in total; %d open (estimates), %d closed and unpaid.',
            count($bills), $total > count($bills) ? " of {$total}" : '', $sums, $open, $unpaid);
    }
}
