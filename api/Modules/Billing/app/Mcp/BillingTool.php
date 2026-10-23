<?php

namespace Modules\Billing\Mcp;

use App\Mcp\Tools\Tool;

/**
 * Base of the Billing module's MCP tools (docs/mcp.md#tools, docs/billing.md#api): thin wrappers
 * over the billing API, listed in the catalog group "billing" while billing is on
 * (BillingServiceProvider). What a call may do is decided by the API routes; permissions()
 * mirrors their rule (config/route_permissions.php) so a token sees the tools it can use.
 */
abstract class BillingTool extends Tool
{
    /** Bills of a month: YYYY-MM */
    protected const PERIOD_PATTERN = '^\d{4}-(0[1-9]|1[0-2])$';

    public function group(): string
    {
        return 'billing';
    }

    protected static function periodSchema(string $description): array
    {
        return ['type' => 'string', 'pattern' => self::PERIOD_PATTERN, 'description' => $description];
    }

    protected static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** "12.50 EUR" */
    protected static function amount(mixed $value, ?string $currency): string
    {
        return trim(self::money($value).' '.(string) $currency);
    }

    /**
     * A bill as tools show it in lists: who, when, how much, paid or not.
     *
     * @param  array<string, mixed>  $bill  a bill of the API
     * @return array<string, mixed>
     */
    protected static function billRow(array $bill): array
    {
        return array_filter([
            'id' => $bill['id'] ?? null,
            'kind' => $bill['kind'] ?? null,
            'period' => $bill['period'] ?? null,
            'status' => $bill['status'] ?? null,
            'estimate' => $bill['estimate'] ?? null,
            'project' => $bill['project'] ?? null,
            'owner' => $bill['owner'] ?? null,
            'tenant' => $bill['tenant'] ?? null,
            'billing_type' => $bill['billing_type'] ?? null,
            'total' => $bill['total'] ?? null,
            'currency' => $bill['currency'] ?? null,
            'paid' => $bill['paid'] ?? null,
            'payment_url' => $bill['payment_url'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The lines of a bill by what they are for: the project's own usage or allocation, its
     * preview environments, its queue consumer (component preview / queue_consumer), or other
     * resources (bindings, model services, sandboxes).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, array{total: float, lines: int}>
     */
    protected static function byComponent(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $component = (string) ($line['component'] ?? '');
            $type = $line['resource_type'] ?? null;
            $key = match (true) {
                $component !== '' => $component,
                $type !== null && $type !== 'project' => 'other',
                default => 'project',
            };
            $out[$key] ??= ['total' => 0.0, 'lines' => 0];
            $out[$key]['total'] = round($out[$key]['total'] + (float) ($line['total'] ?? 0), 2);
            $out[$key]['lines']++;
        }

        return $out;
    }
}
