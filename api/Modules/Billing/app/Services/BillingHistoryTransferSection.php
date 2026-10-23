<?php

namespace Modules\Billing\Services;

use App\Models\ImportedHistoryRecord;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Sections\HistorySection;
use App\Services\OrgTransfer\Sections\TransferSection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Billing\Models\Bill;

/**
 * extensions/billing_history/bills.jsonl (docs/billing.md, docs/org-export.md#run-history): the
 * organization's own bills with the run history (export option include_history) — project and
 * account bills of its context, open ones as the estimate of the export's moment, closed ones with
 * their final line items, totals and payment state. The platform's bills to tenants (kind tenant)
 * are the platform's books and stay.
 *
 * Import (choice history): only where billing is on (the Billing module and billing.enabled) —
 * an installation without it reports the section as not imported. The bills go to
 * imported_history (kind bill), read-only: never into billing_bills, so they are never recalculated,
 * closed, charged or paid again here; the target's own bills start with its own usage.
 */
class BillingHistoryTransferSection implements TransferSection
{
    public const KEY = 'billing_history';

    public function key(): string
    {
        return self::KEY;
    }

    public static function entry(): string
    {
        return PackageFormat::extensionDir(self::KEY).'/bills.jsonl';
    }

    private static function hasTable(): bool
    {
        return Schema::connection((new Bill)->getConnectionName())->hasTable((new Bill)->getTable());
    }

    public function export(ExportContext $context): void
    {
        if (empty($context->options['include_history']) || ! self::hasTable()) {
            return;
        }

        $all = ! is_array($context->options['projects'] ?? null);
        $projectIds = $context->projects()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $out = fopen($context->path(self::entry()), 'wb');
        if ($out === false) {
            throw new \RuntimeException('Cannot write the bills');
        }
        $n = 0;
        try {
            Bill::query()->whereIn('kind', [Bill::PROJECT, Bill::ACCOUNT])
                // a selection of projects carries their bills; the whole organization every bill
                ->when(! $all, fn ($q) => $q->where('kind', Bill::PROJECT)->whereIn('project_id', $projectIds))
                ->orderBy('period_start')->orderBy('id')
                ->chunk(200, function ($bills) use ($out, &$n) {
                    foreach ($bills as $bill) {
                        /** @var Bill $bill */
                        fwrite($out, json_encode([
                            'id' => (string) $bill->id,
                            'kind' => $bill->kind,
                            'project_id' => $bill->project_id,
                            'project_name' => $bill->project_name,
                            'owner_name' => $bill->owner_name,
                            'owner_email' => $bill->owner_email,
                            'period' => $bill->period,
                            'period_start' => $bill->period_start->toDateString(),
                            'period_end' => $bill->period_end->toDateString(),
                            'currency' => $bill->currency,
                            'status' => $bill->status,
                            'billing_type' => $bill->billing_type,
                            'line_items' => $bill->line_items,
                            'metered_lines' => $bill->metered_lines,
                            'fixed_total' => $bill->fixed_total,
                            'usage_total' => $bill->usage_total,
                            'total' => $bill->total,
                            'metered_cost' => $bill->metered_cost,
                            'paid' => (bool) $bill->paid,
                            'paid_at' => $bill->paid_at?->toIso8601String(),
                            'payment_reference' => $bill->payment_reference,
                            'closed_at' => $bill->closed_at?->toIso8601String(),
                            'created_at' => $bill->created_at?->toIso8601String(),
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                        $n++;
                    }
                });
        } finally {
            fclose($out);
        }

        $context->count('history_bills', $n);
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $path = $context->file(self::entry());
        if ($path === null) {
            return;
        }
        $n = 0;
        foreach (HistorySection::lines($path) as $bill) {
            $n++;
        }
        $plan->history = array_merge($plan->history ?? ['import' => $context->choices->history], [
            'bills' => $n,
            'bills_imported' => $context->choices->history && config('billing.enabled') && $n > 0,
        ]);
        if ($n > 0 && $context->choices->history && ! config('billing.enabled')) {
            $plan->generalIssue(ImportPlan::WARNING, 'billing_off', __('org_transfer.import.billing_off'));
        }
    }

    public function import(ImportContext $context): void
    {
        $path = $context->file(self::entry());
        if ($path === null || ! $context->choices->history || $context->settled(HistorySection::KEY, 'bills')) {
            return;
        }
        if (! config('billing.enabled') || ! Schema::hasTable('imported_history')) {
            $context->record(HistorySection::KEY, 'bills', __('org_transfer.import.history_bills'), 'skipped', null, __('org_transfer.import.billing_off'));

            return;
        }

        try {
            $n = 0;
            $connection = (new ImportedHistoryRecord)->getConnection();
            $connection->transaction(function () use ($context, $path, $connection, &$n) {
                foreach (HistorySection::lines($path) as $bill) {
                    $project = ! empty($bill['project_id']) ? $context->mapped('project', (string) $bill['project_id']) : null;
                    $connection->table('imported_history')->insert([
                        'id' => strtolower((string) Str::ulid()),
                        'org_transfer_id' => $context->transfer->id,
                        'kind' => ImportedHistoryRecord::KIND_BILL,
                        'source_table' => 'billing_bills',
                        'project_id' => $project,
                        'source_project_id' => $bill['project_id'] ?? null,
                        'subject' => isset($bill['project_name']) ? mb_substr((string) $bill['project_name'], 0, 250) : (isset($bill['owner_name']) ? mb_substr((string) $bill['owner_name'], 0, 250) : null),
                        'period_date' => isset($bill['period_start']) ? Carbon::parse((string) $bill['period_start'])->toDateString() : null,
                        'data' => json_encode($bill, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'created_at' => now()->format('Y-m-d H:i:s'),
                    ]);
                    $n++;
                }
            });
            $context->record(HistorySection::KEY, 'bills', __('org_transfer.import.history_bills'), 'created', null, __('org_transfer.import.history_counts', ['imported' => $n, 'skipped' => 0]));
        } catch (\Throwable $e) {
            $context->record(HistorySection::KEY, 'bills', __('org_transfer.import.history_bills'), 'failed', null, $e->getMessage());
        }
    }
}
