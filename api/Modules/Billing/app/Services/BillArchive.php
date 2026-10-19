<?php

namespace Modules\Billing\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\ArchivedBill;
use Modules\Billing\Models\Bill;
use Modules\Billing\Support\BillingContext;
use Modules\Tenancy\Models\Tenant;
use Modules\Tenancy\Services\TenantSchema;
use RuntimeException;

/**
 * Bills of deleted tenants (docs/billing.md#bills-of-deleted-tenants). A tenant's project and
 * account bills live in its own schema, which tenant deletion drops; they are financial records, so
 * TenantDeletionService has them copied into the central billing_archived_bills first: every bill
 * with its line items, amounts, payer, paid state, payment link and closing time, and the tenant's
 * id, slug and name. A closed bill is copied as it is; an open one (the month of the deletion, or a
 * month the close job had not closed yet) with the estimate calculated at that moment, and it stays
 * open. With billing on, the month's missing bills are made first (BillService::ensureOpenBills).
 * The platform's bills to the tenant are central and stay where they are.
 *
 * Idempotent: a deletion retried after the copy replaces the tenant's archived bills by bill id.
 */
class BillArchive
{
    public const TABLE = 'billing_archived_bills';

    /**
     * Copies the tenant's bills into the archive (call before its schema is dropped). Nothing to do
     * when its schema has no bills table — the Billing module never ran in it.
     *
     * @return int bills archived
     *
     * @throws \Throwable when the bills cannot be read, calculated or written: the schema must not
     *                    be dropped then
     */
    public function archiveTenant(Tenant $tenant): int
    {
        $bills = TenantSchema::inTenant($tenant, fn () => $this->tenantBills());
        if ($bills === []) {
            return 0;
        }

        $central = BillingContext::central();
        if (! Schema::connection($central)->hasTable(self::TABLE)) {
            throw new RuntimeException('the table '.self::TABLE.' is missing (run the migrations)');
        }

        $tenantId = (string) $tenant->getKey();
        $tenantColumns = [
            'tenant_slug' => (string) $tenant->slug,
            'tenant_name' => (string) $tenant->name,
            'archived_at' => now(),
        ];

        DB::connection($central)->transaction(function () use ($central, $tenantId, $tenantColumns, $bills) {
            foreach ($bills as $bill) {
                ArchivedBill::on($central)->updateOrCreate(
                    ['tenant_id' => $tenantId, 'bill_id' => $bill['bill_id']],
                    $bill + $tenantColumns,
                );
            }
        });

        Log::info('Billing: the bills of a deleted tenant were archived', ['tenant' => $tenantId, 'bills' => count($bills)]);

        return count($bills);
    }

    /**
     * Archived bills (central).
     *
     * @return Builder<ArchivedBill>
     */
    public function query(): Builder
    {
        return ArchivedBill::on(BillingContext::central());
    }

    public function tableExists(): bool
    {
        return Schema::connection(BillingContext::central())->hasTable(self::TABLE);
    }

    /** API shape (docs/billing.md#bills-of-deleted-tenants): a bill with the tenant it belonged to. */
    public function present(ArchivedBill $bill, bool $withLines = false): array
    {
        $total = self::amount($bill->total);
        $metered = self::amount($bill->metered_cost);

        $data = [
            'id' => $bill->id,
            'bill_id' => $bill->bill_id,
            'tenant' => ['id' => $bill->tenant_id, 'slug' => $bill->tenant_slug, 'name' => $bill->tenant_name],
            'kind' => $bill->kind,
            'period' => $bill->period,
            'period_start' => $bill->period_start->toDateString(),
            'period_end' => $bill->period_end->toDateString(),
            'status' => $bill->status,
            'estimate' => $bill->status === Bill::OPEN,
            'currency' => $bill->currency,
            'source' => $bill->source,
            'project' => $bill->project_id !== null ? ['id' => $bill->project_id, 'name' => $bill->project_name] : null,
            'owner' => $bill->owner_id !== null ? ['id' => $bill->owner_id, 'name' => $bill->owner_name, 'email' => $bill->owner_email] : null,
            'billing_type' => $bill->billing_type,
            'line_items' => $withLines ? array_values($bill->line_items ?? []) : null,
            'fixed_total' => self::amount($bill->fixed_total),
            'usage_total' => self::amount($bill->usage_total),
            'total' => $total,
            'metered_cost' => $metered,
            'difference' => $total === null || $metered === null ? null : round($total - $metered, 2),
        ];
        if ($withLines) {
            $data['metered_lines'] = array_values($bill->metered_lines ?? []);
        }

        return $data + [
            'payment_url' => $bill->payment_url,
            'payment_url_set_at' => $bill->payment_url_set_at?->toIso8601String(),
            'paid' => (bool) $bill->paid,
            'paid_at' => $bill->paid_at?->toIso8601String(),
            'payment_reference' => $bill->payment_reference,
            'closed_at' => $bill->closed_at?->toIso8601String(),
            'created_at' => $bill->bill_created_at?->toIso8601String(),
            'archived_at' => $bill->archived_at->toIso8601String(),
        ];
    }

    /**
     * The organization's bills as archive rows (call in the tenant's context). The bills table is
     * looked up in the tenant's own schema only: on PostgreSQL the search path of a tenant ends in
     * the central schema, whose billing_bills are the platform's.
     *
     * @return list<array<string, mixed>>
     */
    private function tenantBills(): array
    {
        if (! Schema::hasTable('billing_bills')) {
            return [];
        }

        $service = app(BillService::class);
        // The month of the deletion: a bill for every project (and creator of usage) that has none
        // yet — the daily job or a listing creates them otherwise
        if (config('billing.enabled')) {
            $service->ensureOpenBills();
        }

        $rows = [];
        foreach (Bill::query()->orderBy('period')->orderBy('id')->get() as $bill) {
            // Closed: the stored lines and totals; open: the estimate of now. With the costs
            // (metered cost, metered lines) and the payer as the bill shows them.
            $shown = $service->present($bill, true, true);
            $owner = is_array($shown['owner'] ?? null) ? $shown['owner'] : null;

            $rows[] = [
                'bill_id' => $bill->id,
                'kind' => $bill->kind,
                'period' => $bill->period,
                'period_start' => $bill->period_start->toDateString(),
                'period_end' => $bill->period_end->toDateString(),
                'project_id' => $bill->project_id,
                'project_name' => $bill->project_name,
                'owner_id' => $bill->owner_id,
                'owner_name' => $owner['name'] ?? $bill->owner_name,
                'owner_email' => $owner['email'] ?? $bill->owner_email,
                'currency' => $bill->currency,
                'status' => $bill->status,
                'billing_type' => $shown['billing_type'] ?? $bill->billing_type,
                'line_items' => $shown['line_items'] ?? [],
                'metered_lines' => $shown['metered_lines'] ?? [],
                'fixed_total' => $shown['fixed_total'] ?? null,
                'usage_total' => $shown['usage_total'] ?? null,
                'total' => $shown['total'] ?? null,
                'metered_cost' => $shown['metered_cost'] ?? null,
                'payment_url' => $bill->payment_url,
                'payment_url_set_at' => $bill->payment_url_set_at,
                'paid' => (bool) $bill->paid,
                'paid_at' => $bill->paid_at,
                'payment_reference' => $bill->payment_reference,
                'source' => $bill->source,
                'closed_at' => $bill->closed_at,
                'bill_created_at' => $bill->created_at,
            ];
        }

        return $rows;
    }

    private static function amount(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
