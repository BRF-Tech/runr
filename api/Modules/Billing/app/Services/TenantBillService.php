<?php

namespace Modules\Billing\Services;

use App\Models\AiModelService;
use App\Models\Project;
use App\Support\ConvertedOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\FixedRequest;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Period;
use Modules\Tenancy\Models\Tenant;

/**
 * Platform → tenant bills (docs/billing.md#platform-to-tenant-bills), central schema only: once a
 * month the platform bills every tenant for what its projects used or held in shared platform
 * regions, at platform prices (the tenant's markup is the tenant's business), with one line per
 * project (or binding, sandbox usage, model service) and region. Calculated from the central
 * copies of the tenant's usage and allocations.
 *
 * One bill per tenant and run of billed days (BillingActivity), normally the month; a tenant
 * converted from the single-organization install is billed from the conversion day. Converted
 * 0.5.7 charges are separate closed bills (subject tenant-legacy:<id>).
 */
class TenantBillService
{
    private const CATCH_UP_MONTHS = 3;

    /** @var array<string, array<string, mixed>> */
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
     * Platform → tenant bills (central).
     *
     * @return Builder<Bill>
     */
    public function query(): Builder
    {
        return Bill::on(BillingContext::central())->where('kind', Bill::TENANT);
    }

    public function find(string $id, ?string $tenantId = null): Bill
    {
        return $this->query()->whereKey($id)->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))->firstOrFail();
    }

    /** Tenant id or slug → tenant id (null when unknown). */
    public function resolveTenant(string $idOrSlug): ?string
    {
        try {
            $id = DB::connection(BillingContext::central())->table('tenants')
                ->where('id', $idOrSlug)->orWhere('slug', $idOrSlug)->value('id');
        } catch (QueryException) {
            return null;
        }

        return $id !== null ? (string) $id : null;
    }

    /**
     * Tenants the platform bills this month: those with an active shared region.
     *
     * @return list<string>
     */
    public function billedTenants(): array
    {
        try {
            $query = DB::connection(BillingContext::central())->table('tenant_regions');
            if (DB::connection(BillingContext::central())->getSchemaBuilder()->hasColumn('tenant_regions', 'status')) {
                $query->where('status', 'active');
            }

            return $query->distinct()->pluck('tenant_id')->map(fn ($id) => (string) $id)->all();
        } catch (QueryException) {
            return [];
        }
    }

    public function ensureOpenBills(?Period $period = null): void
    {
        if (! BillingContext::multiTenant() || BillingContext::inTenant()) {
            return;
        }

        $period ??= Period::current();
        if ($period->endDate() < $this->settings->startedOn()) {
            return;
        }

        $this->prepare();
        $tenants = array_unique(array_merge($this->billedTenants(), $this->usage->tenantsWithUsage($period)));
        foreach ($tenants as $tenantId) {
            $this->tenantBill($tenantId, $period);
        }
    }

    /**
     * The tenant's bill of the month (created when missing): the one of today's billed days, in a
     * month that has ended its last one; null when nothing of the month is billed.
     */
    public function tenantBill(string $tenantId, Period $period): ?Bill
    {
        $window = BillingActivity::currentWindow($this->windows($tenantId, $period));

        return $window === null ? null : $this->billFor($tenantId, $period, $window);
    }

    /**
     * The tenant's bill of $window: found by tenant and days, whatever its currency (an open bill
     * follows BILLING_CURRENCY), else created.
     *
     * @param  array{0: string, 1: string}  $window
     */
    private function billFor(string $tenantId, Period $period, array $window): Bill
    {
        $subject = Bill::tenantKey($tenantId);
        $bill = $this->query()->where('subject_key', $subject)->where('period', $period->key())
            ->whereDate('period_start', '<=', $window[1])->whereDate('period_end', '>=', $window[0])->first();

        if ($bill !== null) {
            if ($bill->isOpen() && $bill->currency !== BillingContext::currency()) {
                $bill->forceFill(['currency' => BillingContext::currency()])->save();
            }

            return $bill;
        }

        try {
            return DB::connection(BillingContext::central())->transaction(fn () => Bill::on(BillingContext::central())->create([
                'kind' => Bill::TENANT,
                'subject_key' => $subject,
                'tenant_id' => $tenantId,
                'period' => $period->key(),
                'period_start' => $window[0],
                'period_end' => $window[1],
                'currency' => BillingContext::currency(),
                'status' => Bill::OPEN,
                'source' => Bill::SOURCE_RUNR,
            ]));
        } catch (UniqueConstraintViolationException $e) {
            return $this->query()->where('subject_key', $subject)->where('period', $period->key())->whereDate('period_start', $window[0])->first() ?? throw $e;
        }
    }

    /**
     * The days of $period the platform bills the tenant for: the billed days of the installation,
     * for the tenant a conversion created from the conversion day on.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function windows(string $tenantId, Period $period): array
    {
        $this->converted ??= new ConvertedOrganization;

        return $this->activity->windows($period, $this->converted->tenantSince($tenantId), organization: false);
    }

    /** Today is a billed day, open bills that reach into a time billing was off end before it, copies of allocations of deleted tenants end. */
    private function prepare(): void
    {
        $this->activity->touch();
        $this->activity->clipOpenBills($this->query());
        $this->requests->endOrphanedCopies();
    }

    public function values(Bill $bill): ?array
    {
        if (! $bill->isOpen()) {
            return null;
        }

        return $this->computed[$bill->id] ??= $this->calculator->forBill($bill, $this->names((string) $bill->tenant_id));
    }

    public function present(Bill $bill, bool $withLines = false, bool $withCosts = true): array
    {
        return $this->presenter->bill($bill, $this->values($bill), $withLines, $withCosts);
    }

    /** Final line items and totals, in a transaction on the locked row (closes and records bill.closed once). */
    public function close(Bill $bill): Bill
    {
        $central = BillingContext::central();
        $names = $this->names((string) $bill->tenant_id);

        return DB::connection($central)->transaction(function () use ($bill, $central, $names) {
            $locked = Bill::on($central)->whereKey($bill->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isOpen()) {
                return $locked ?? $bill;
            }

            $result = $this->calculator->forBill($locked, $names);
            unset($this->computed[$locked->id]);

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
                'closed_at' => now(),
            ])->save();

            $this->events->record(BillingEvents::BILL_CLOSED, ['bill' => $this->presenter->bill($locked, null, true, true)], 'bill.closed:'.$locked->id);

            return $locked;
        });
    }

    public function closeEndedPeriods(): int
    {
        if (! BillingContext::multiTenant() || BillingContext::inTenant()) {
            return 0;
        }

        $this->prepare();
        $current = Period::current();
        $start = Period::of($this->settings->startedOn());

        for ($period = $current->previous(), $i = 0; $i < self::CATCH_UP_MONTHS && $period->key() >= $start->key(); $period = $period->previous(), $i++) {
            foreach ($this->tenantsWithActivity($period) as $tenantId) {
                foreach ($this->windows($tenantId, $period) as $window) {
                    if ($this->hasActivity($tenantId, $period, $window)) {
                        $this->billFor($tenantId, $period, $window);
                    }
                }
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
                Log::error('Billing: closing a tenant bill failed', ['bill' => $bill->id, 'tenant' => $bill->tenant_id, 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return $closed;
    }

    /** @return list<string> tenants with usage or allocations in the period */
    private function tenantsWithActivity(Period $period): array
    {
        $tenants = $this->usage->tenantsWithUsage($period);
        $tenants = array_merge($tenants, FixedRequest::on(BillingContext::central())->whereNotNull('tenant_id')->whereIn('status', FixedRequest::ALLOCATED)
            ->whereDate('active_from', '<=', $period->endDate())
            ->where(fn ($q) => $q->whereNull('active_until')->orWhereDate('active_until', '>=', $period->startDate()))
            ->distinct()->pluck('tenant_id')->map(fn ($id) => (string) $id)->all());

        return array_values(array_unique($tenants));
    }

    /** @param array{0: string, 1: string} $window */
    private function hasActivity(string $tenantId, Period $period, array $window): bool
    {
        [$from, $to] = $window;
        if (collect($this->usage->tenantUsage($tenantId, $period))->contains(fn (array $e) => $e['date'] >= $from && $e['date'] <= $to)) {
            return true;
        }

        return FixedRequest::on(BillingContext::central())->where('tenant_id', $tenantId)->whereIn('status', FixedRequest::ALLOCATED)
            ->whereDate('active_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('active_until')->orWhereDate('active_until', '>=', $from))
            ->exists();
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
     * Names of the tenant's projects and model services (read from its schema) for the lines.
     *
     * @return array<string, string>
     */
    private function names(string $tenantId): array
    {
        // stancl's run() leaves the tenant initialized when the callback throws: restore the context
        $previous = tenancy()->initialized ? tenancy()->tenant : null;

        try {
            $tenant = Tenant::query()->find($tenantId);
            if (! $tenant) {
                return [];
            }

            return (array) $tenant->run(function () {
                $names = Project::query()->pluck('name', 'id')->all();
                try {
                    $names += AiModelService::query()->pluck('name', 'id')->all();
                } catch (QueryException) {
                }

                return $names;
            });
        } catch (\Throwable $e) {
            if ($previous) {
                tenancy()->initialize($previous);
            } elseif (tenancy()->initialized) {
                tenancy()->end();
            }
            Log::warning('Billing: tenant project names not read', ['tenant' => $tenantId, 'error' => $e->getMessage()]);

            return [];
        }
    }
}
