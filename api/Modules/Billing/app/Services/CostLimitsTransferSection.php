<?php

namespace Modules\Billing\Services;

use App\Models\Project;
use App\Services\OrgTransfer\ExportContext;
use App\Services\OrgTransfer\ImportContext;
use App\Services\OrgTransfer\ImportPlan;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Sections\TransferSection;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\CostLimit;
use Modules\Billing\Support\BillingContext;

/**
 * extensions/cost_limits/cost_limits.json (docs/billing.md#monthly-cost-limit, docs/org-export.md):
 * the monthly cost limit of every exported project — amount, currency, action. The month's state
 * (warning, notice, month-to-date cost) is the source's and does not travel.
 *
 * Import: CostLimits::set() on the imported project, in the billing currency of the target. A
 * limit in another currency than the target's, or on a project the target bills at a fixed price
 * (no limit there), is listed as a manual step instead.
 */
class CostLimitsTransferSection implements TransferSection
{
    public const FILE = 'cost_limits.json';

    public function __construct(private CostLimits $limits) {}

    public function key(): string
    {
        return 'cost_limits';
    }

    private static function entry(): string
    {
        return PackageFormat::extensionDir('cost_limits').'/'.self::FILE;
    }

    private static function hasTable(): bool
    {
        return Schema::connection((new CostLimit)->getConnectionName())->hasTable((new CostLimit)->getTable());
    }

    public function export(ExportContext $context): void
    {
        $rows = [];
        if (self::hasTable()) {
            $ids = $context->projects()->pluck('id')->map(fn ($id) => (string) $id)->all();
            foreach (CostLimit::query()->whereIn('project_id', $ids)->get() as $limit) {
                $rows[(string) $limit->project_id] = [
                    'amount' => (string) $limit->amount,
                    'currency' => $limit->currency,
                    'action' => $limit->action,
                ];
            }
        }

        $context->putJson(self::entry(), ['projects' => $rows]);
        $context->count('cost_limits', count($rows));
    }

    public function plan(ImportContext $context, ImportPlan $plan): void
    {
        $rows = (array) (((array) $context->json(self::entry(), []))['projects'] ?? []);
        if ($rows === [] || ! config('billing.enabled')) {
            return;
        }
        $currency = BillingContext::currency();
        $other = array_filter($rows, fn ($row) => ($row['currency'] ?? $currency) !== $currency);
        if ($other !== []) {
            $plan->manual('cost_limits', __('org_transfer.manual.cost_limits'), array_values(array_map(
                fn ($row) => ($row['amount'] ?? '?').' '.($row['currency'] ?? '?'),
                $other,
            )));
        }
    }

    public function import(ImportContext $context): void
    {
        $rows = (array) (((array) $context->json(self::entry(), []))['projects'] ?? []);
        if ($rows === [] || ! config('billing.enabled') || ! self::hasTable()) {
            return;
        }
        $currency = BillingContext::currency();

        foreach ($rows as $sourceId => $row) {
            $key = (string) $sourceId;
            if ($context->settled('cost_limit', $key)) {
                continue;
            }
            $targetId = $context->mapped('project', $key);
            $project = $targetId ? Project::query()->find($targetId) : null;
            if (! $project) {
                continue;
            }
            $label = "{$project->name}: ".($row['amount'] ?? '?').' '.($row['currency'] ?? '?');
            if (($row['currency'] ?? $currency) !== $currency) {
                $context->manual('cost_limits', __('org_transfer.manual.cost_limits'), [$label]);
                $context->record('cost_limit', $key, $label, 'manual', null, __('org_transfer.manual.cost_limits'));

                continue;
            }

            try {
                $limit = $this->limits->set($project, (string) $row['amount'], (string) ($row['action'] ?? CostLimit::NOTIFY), $context->userId());
                $context->record('cost_limit', $key, $label, 'created', (string) $limit->project_id);
            } catch (\Throwable $e) {
                $context->manual('cost_limits', __('org_transfer.manual.cost_limits'), [$label]);
                $context->record('cost_limit', $key, $label, 'manual', null, $e->getMessage());
            }
        }
    }
}
