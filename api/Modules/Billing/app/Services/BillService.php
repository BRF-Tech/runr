<?php

namespace Modules\Billing\Services;

use App\Models\Project;
use App\Support\ConvertedOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Money;
use Modules\Billing\Support\Period;

/**
 * Project and account bills of the organization the request or job runs in
 * (docs/billing.md#bills):
 *
 *  - ensureOpenBills():    an open bill for every project (and every creator of usage that
 *                          belongs to no project) for the month — daily, and before bills are listed
 *  - present():            the API shape; an open bill is calculated on request (an estimate)
 *  - closeEndedPeriods():  every open bill whose days have ended gets its final line items and
 *                          totals and the bill.closed event; a closed bill is never recalculated
 *  - setPaymentUrl() / setPaid(): what the external invoicing system reports back
 *
 * A bill covers its month (period_start … period_end), or the part of it billing was on
 * (BillingActivity): one bill per subject and run of billed days, normally the whole month.
 * Months before billing started in the organization (BillingSettings::startedOn()) are not billed.
 */
class BillService
{
    /** Months back the close job looks for usage without a bill. */
    private const CATCH_UP_MONTHS = 3;

    /** @var array<string, array<string, mixed>> bill id → calculation of this request */
    private array $computed = [];

    private ?ConvertedOrganization $converted = null;

    public function __construct(
        private readonly BillCalculator $calculator,
        private readonly Presenter $presenter,
        private readonly BillingEvents $events,
        private readonly BillingSettings $settings,
        private readonly UsageReader $usage,
        private readonly BillingActivity $activity,
        private readonly FixedRequests $requests,
    ) {}

    /**
     * The organization's project and account bills (never the platform → tenant bills).
     *
     * @return Builder<Bill>
     */
    public function query(): Builder
    {
        return Bill::query()->whereIn('kind', [Bill::PROJECT, Bill::ACCOUNT]);
    }

    public function find(string $id): Bill
    {
        return $this->query()->whereKey($id)->firstOrFail();
    }

    /** Open bills of the month for every project and every creator of organization-level usage. */
    public function ensureOpenBills(?Period $period = null): void
    {
        $period ??= Period::current();
        if ($period->endDate() < $this->settings->startedOn()) {
            return;
        }

        try {
            $this->prepare();
            $window = BillingActivity::currentWindow($this->activity->windows($period));
            if ($window === null) {
                return;
            }

            $existing = $this->query()->where('period', $period->key())->get()->groupBy('subject_key');
            foreach (Project::query()->get() as $project) {
                $projectWindow = $this->clip($window, $this->convertedUntil((string) $project->id));
                if ($projectWindow !== null) {
                    $key = Bill::projectKey((string) $project->id);
                    $this->syncProjectBill($project, $period, $projectWindow, $this->overlapping($existing->get($key), $projectWindow));
                }
            }
            foreach ($this->usage->accountOwners($period) as $owner) {
                $owner = $owner === '' ? null : (string) $owner;
                $bill = $this->overlapping($existing->get(Bill::accountKey($owner)), $window);
                if ($bill === null) {
                    $this->createBill(Bill::ACCOUNT, Bill::accountKey($owner), $period, $window, ['owner_id' => $owner]);
                } else {
                    $this->followCurrency($bill);
                }
            }
        } catch (QueryException $e) {
            Log::warning('Billing: open bills not ensured', ['tenant' => BillingContext::tenantKey(), 'error' => $e->getMessage()]);
        }
    }

    /**
     * The project's bill of the month (created when missing): the one of today's billed days, in a
     * month that has ended its last one. Null when billing was off the whole month.
     */
    public function projectBill(Project $project, ?Period $period = null): ?Bill
    {
        $period ??= Period::current();
        $this->prepare();
        $window = $this->clip(BillingActivity::currentWindow($this->activity->windows($period)), $this->convertedUntil((string) $project->id));
        if ($window === null) {
            return null;
        }

        $bills = $this->query()->where('subject_key', Bill::projectKey((string) $project->id))->where('period', $period->key())->get();

        return $this->syncProjectBill($project, $period, $window, $this->overlapping($bills, $window));
    }

    /** @param array{0: string, 1: string} $window */
    private function syncProjectBill(Project $project, Period $period, array $window, ?Bill $bill): Bill
    {
        $owner = $project->payerId();

        if (! $bill) {
            return $this->createBill(Bill::PROJECT, Bill::projectKey((string) $project->id), $period, $window, [
                'project_id' => (string) $project->id,
                'project_name' => $project->name,
                'owner_id' => $owner,
            ]);
        }

        // An open bill follows the project's name and owner (the payer is the owner at closing)
        if ($bill->isOpen() && ($bill->owner_id !== $owner || $bill->project_name !== $project->name)) {
            $bill->forceFill(['owner_id' => $owner, 'project_name' => $project->name])->save();
        }

        return $this->followCurrency($bill);
    }

    /** An open bill is in the installation's currency (BILLING_CURRENCY changed: the same bill, relabelled). */
    private function followCurrency(Bill $bill): Bill
    {
        if ($bill->isOpen() && $bill->currency !== BillingContext::currency()) {
            $bill->forceFill(['currency' => BillingContext::currency()])->save();
        }

        return $bill;
    }

    /**
     * @param  array{0: string, 1: string}  $window  the days the bill covers
     * @param  array<string, mixed>  $attributes
     */
    private function createBill(string $kind, string $subject, Period $period, array $window, array $attributes): Bill
    {
        try {
            // In a savepoint of its own: a duplicate does not abort a surrounding transaction
            return DB::transaction(fn () => Bill::query()->create($attributes + [
                'kind' => $kind,
                'subject_key' => $subject,
                'period' => $period->key(),
                'period_start' => $window[0],
                'period_end' => $window[1],
                'currency' => BillingContext::currency(),
                'status' => Bill::OPEN,
                'source' => Bill::SOURCE_RUNR,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            // Made by another request a moment ago
            return $this->query()->where('subject_key', $subject)->where('period', $period->key())
                ->whereDate('period_start', $window[0])->first() ?? throw $e;
        }
    }

    /**
     * The bill among $bills that covers days of $window (at most one: bills of a subject never
     * overlap).
     *
     * @param  Collection<int, Bill>|null  $bills
     * @param  array{0: string, 1: string}  $window
     */
    private function overlapping(?Collection $bills, array $window): ?Bill
    {
        return $bills?->first(fn (Bill $b) => $b->period_start->toDateString() <= $window[1] && $b->period_end->toDateString() >= $window[0]);
    }

    /**
     * $window ending at $until at the latest (null: unchanged); null when nothing is left.
     *
     * @param  array{0: string, 1: string}|null  $window
     * @return array{0: string, 1: string}|null
     */
    private function clip(?array $window, ?string $until): ?array
    {
        if ($window === null || $until === null) {
            return $window;
        }

        return $window[0] <= $until ? [$window[0], min($window[1], $until)] : null;
    }

    /**
     * The last day the central context bills a project the conversion copied into the tenant (the
     * day before the conversion; ConvertedOrganization); null for every other project.
     */
    private function convertedUntil(string $projectId): ?string
    {
        $this->converted ??= new ConvertedOrganization;
        $since = $this->converted->since();

        return $since !== null && $this->converted->contains($projectId) ? Carbon::parse($since)->subDay()->toDateString() : null;
    }

    /**
     * Before bills are made or closed: today is recorded as a billed day, open bills that reach
     * into a time billing was off end before it, allocations of projects that are gone end.
     */
    private function prepare(): void
    {
        $this->activity->touch();
        $this->activity->clipOpenBills($this->query());
        $this->requests->endOrphaned();
    }

    /** The calculation of a bill: stored for a closed bill, calculated now for an open one. */
    public function values(Bill $bill): ?array
    {
        if (! $bill->isOpen()) {
            return null;
        }

        return $this->computed[$bill->id] ??= $this->calculator->forBill($bill);
    }

    public function present(Bill $bill, bool $withLines = false, bool $withCosts = false): array
    {
        return $this->presenter->bill($bill, $this->values($bill), $withLines, $withCosts);
    }

    /**
     * Final line items and totals; the payer is snapshotted. In a transaction on the locked row: a
     * bill closes, and records bill.closed, once.
     */
    public function close(Bill $bill): Bill
    {
        $connection = $bill->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($bill, $connection) {
            $locked = Bill::on($connection)->whereKey($bill->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isOpen()) {
                return $locked ?? $bill;
            }

            $result = $this->calculator->forBill($locked);
            unset($this->computed[$locked->id]);

            if ($locked->kind === Bill::PROJECT) {
                $project = Project::query()->find($locked->project_id);
                if ($project) {
                    $locked->project_name = $project->name;
                    $locked->owner_id = $project->payerId();
                }
            }
            $owner = $this->presenter->user($locked->owner_id);

            $locked->forceFill([
                'status' => Bill::CLOSED,
                'currency' => BillingContext::currency(),
                'line_items' => $result['line_items'],
                'metered_lines' => $result['metered_lines'],
                'fixed_total' => $result['fixed_total'],
                'usage_total' => $result['usage_total'],
                'total' => $result['total'],
                'metered_cost' => $result['metered_cost'],
                'billing_type' => $result['billing_type'],
                'owner_name' => $owner['name'] ?? $locked->owner_name,
                'owner_email' => $owner['email'] ?? $locked->owner_email,
                'closed_at' => now(),
            ])->save();

            $this->events->record(BillingEvents::BILL_CLOSED, ['bill' => $this->presenter->bill($locked, null, true, true)], 'bill.closed:'.$locked->id);

            return $locked;
        });
    }

    /**
     * Close every open bill whose days have ended — after creating the bills of projects and
     * creators that had usage or allocations in the last months but no bill yet.
     */
    public function closeEndedPeriods(): int
    {
        $this->prepare();
        $current = Period::current();
        $start = Period::of($this->settings->startedOn());

        for ($period = $current->previous(), $i = 0; $i < self::CATCH_UP_MONTHS && $period->key() >= $start->key(); $period = $period->previous(), $i++) {
            foreach ($this->activity->windows($period) as $window) {
                $this->ensureBillsWithActivity($period, $window);
            }
        }

        $closed = 0;
        $open = $this->query()->where('status', Bill::OPEN)->whereDate('period_end', '<', Carbon::today()->toDateString())
            ->orderBy('period')->orderBy('period_start')->get();
        foreach ($open as $bill) {
            try {
                $this->close($bill);
                $closed++;
            } catch (\Throwable $e) {
                Log::error('Billing: closing a bill failed', ['bill' => $bill->id, 'tenant' => BillingContext::tenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return $closed;
    }

    /** @param array{0: string, 1: string} $window */
    private function ensureBillsWithActivity(Period $period, array $window): void
    {
        $existing = $this->query()->where('period', $period->key())->get()->groupBy('subject_key');
        [$from, $to] = $window;

        $projects = [];
        foreach ($this->usage->projectsUsage($period) as $projectId => $entries) {
            if (collect($entries)->contains(fn (array $e) => $e['date'] >= $from && $e['date'] <= $to)) {
                $projects[] = (string) $projectId;
            }
        }
        $allocated = FixedRequest::query()->own()->whereIn('status', FixedRequest::ALLOCATED)->whereNotNull('active_from')
            ->whereDate('active_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('active_until')->orWhereDate('active_until', '>=', $from))
            ->get(['project_id', 'project_name']);
        $names = $allocated->pluck('project_name', 'project_id')->all();

        foreach (array_unique(array_merge($projects, $allocated->pluck('project_id')->map(fn ($id) => (string) $id)->all())) as $projectId) {
            $projectWindow = $this->clip($window, $this->convertedUntil($projectId));
            if ($projectWindow === null || $this->overlapping($existing->get(Bill::projectKey($projectId)), $projectWindow) !== null) {
                continue;
            }
            $project = Project::query()->find($projectId);
            $this->createBill(Bill::PROJECT, Bill::projectKey($projectId), $period, $projectWindow, [
                'project_id' => $projectId,
                'project_name' => $project->name ?? ($names[$projectId] ?? null),
                'owner_id' => $project?->payerId(),
            ]);
        }

        $owners = collect($this->usage->accountUsage($period))
            ->filter(fn (array $e) => $e['date'] >= $from && $e['date'] <= $to)
            ->map(fn (array $e) => $e['owner'] ?? '')->unique();
        foreach ($owners as $owner) {
            $owner = $owner === '' ? null : (string) $owner;
            if ($this->overlapping($existing->get(Bill::accountKey($owner)), $window) === null) {
                $this->createBill(Bill::ACCOUNT, Bill::accountKey($owner), $period, $window, ['owner_id' => $owner]);
            }
        }
    }

    /** @throws BillingRefusal */
    public function setPaymentUrl(Bill $bill, ?string $url): Bill
    {
        $this->assertClosed($bill);

        $bill->forceFill(['payment_url' => $url, 'payment_url_set_at' => $url !== null ? now() : null])->save();
        $this->events->record(BillingEvents::BILL_PAYMENT_URL_SET, ['bill' => $this->presenter->bill($bill), 'payment_url' => $url]);

        return $bill;
    }

    /** @throws BillingRefusal */
    public function setPaid(Bill $bill, bool $paid, ?string $paidAt = null, ?string $reference = null, bool $referenceGiven = false): Bill
    {
        $this->assertClosed($bill);

        $changes = [
            'paid' => $paid,
            'paid_at' => $paid ? ($paidAt !== null ? Carbon::parse($paidAt) : ($bill->paid ? $bill->paid_at : now())) : null,
        ];
        if ($referenceGiven) {
            $changes['payment_reference'] = $reference;
        }
        $bill->forceFill($changes)->save();

        $this->events->record($paid ? BillingEvents::BILL_PAID : BillingEvents::BILL_UNPAID, ['bill' => $this->presenter->bill($bill)]);

        return $bill;
    }

    /** @throws BillingRefusal */
    private function assertClosed(Bill $bill): void
    {
        if ($bill->isOpen()) {
            throw new BillingRefusal('bill_open', __('billing.bill_open'), 409);
        }
    }

    /**
     * Per-user rollup of a month: every payer with the number of projects, bills and totals.
     *
     * @return list<array<string, mixed>>
     */
    public function rollup(Period $period): array
    {
        $rows = [];
        foreach ($this->query()->where('period', $period->key())->get() as $bill) {
            $key = $bill->owner_id ?? '';
            $total = Money::dec($this->values($bill)['total'] ?? $bill->total ?? 0);
            $rows[$key] ??= ['owner' => $bill->owner_id, 'projects' => [], 'bills' => 0, 'total' => Money::dec(0), 'paid_total' => Money::dec(0), 'unpaid_total' => Money::dec(0)];
            $rows[$key]['bills']++;
            if ($bill->kind === Bill::PROJECT) {
                $rows[$key]['projects'][(string) $bill->project_id] = true;
            }
            $rows[$key]['total'] = $rows[$key]['total']->plus($total);
            $column = $bill->paid ? 'paid_total' : 'unpaid_total';
            $rows[$key][$column] = $rows[$key][$column]->plus($total);
        }

        $out = [];
        foreach ($rows as $row) {
            $user = $this->presenter->user($row['owner']);
            $out[] = [
                'owner' => $row['owner'] === null ? null : ['id' => $row['owner'], 'name' => $user['name'] ?? null, 'email' => $user['email'] ?? null],
                'projects' => count($row['projects']),
                'bills' => $row['bills'],
                'total' => Money::toFloat($row['total']),
                'paid_total' => Money::toFloat($row['paid_total']),
                'unpaid_total' => Money::toFloat($row['unpaid_total']),
            ];
        }
        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $out;
    }
}
