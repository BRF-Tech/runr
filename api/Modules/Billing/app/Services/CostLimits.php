<?php

namespace Modules\Billing\Services;

use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Projects\ProjectStopConflict;
use App\Services\Projects\ProjectStopService;
use App\Support\Rbac\Rbac;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\Bill;
use Modules\Billing\Models\CostLimit;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\BillingPolicy;
use Modules\Billing\Support\Money;
use Modules\Billing\Support\Period;

/**
 * The monthly cost limit of a pay-as-you-go project (docs/billing.md#monthly-cost-limit): a
 * guardrail the project's owner sets, not a billing rule — RunR still never stops a project because
 * a bill is unpaid.
 *
 *  - one amount per project in the billing currency, and what happens at 100 %: notify | stop
 *  - only in pay-as-you-go regions with pay-as-you-go prices (applies()); a fixed-price project
 *    pays its allocation and has no limit to show
 *  - month to date: the project's bills of the month (the same calculation as the bill); the
 *    forecast is month to date ÷ elapsed days × days in the month
 *  - checked every 15 minutes (CheckCostLimitsJob) and when the limit is saved: at
 *    CostLimit::WARNING_PERCENT the owner is warned, at 100 % notified again and — with "stop" —
 *    the project is stopped (ProjectStopService::stopBySystem, "system:cost_limit"); each once per
 *    month and limit: a new month, a new amount (and a switch to "stop") start them over
 *  - the owner resumes a project the limit stopped once the limit no longer stops it: raised,
 *    removed, switched to "notify", or a new month began (resume()); with the limit still set,
 *    RunR resumes it by itself once a new month begins (resumeInNewMonth())
 *
 * Every step records project.cost_limit.warning / project.cost_limit.reached (webhook) and
 * notifies the owner and whoever set the limit.
 */
class CostLimits
{
    /** What stopped a project its limit stopped (ProjectStopService::systemOf). */
    public const STOP_SYSTEM = 'cost_limit';

    /** @var array<string, bool> organization → the table exists */
    private array $tables = [];

    public function __construct(
        private readonly BillService $bills,
        private readonly RegionTypes $types,
        private readonly PriceBook $prices,
        private readonly BillingEvents $events,
        private readonly BillingNotifier $notifier,
        private readonly Presenter $presenter,
        private readonly ProjectStopService $projectStops,
    ) {}

    public function find(Project $project): ?CostLimit
    {
        if (! $this->hasTable()) {
            return null;
        }

        return CostLimit::query()->find((string) $project->id);
    }

    /**
     * A limit applies to a project in a pay-as-you-go region whose prices charge for usage; a
     * fixed-price project (or a free region) has nothing it could limit.
     */
    public function applies(Project $project): bool
    {
        if ($this->types->typeOf($project->knative_region_id) !== BillingPolicy::PAYG) {
            return false;
        }

        $price = $this->prices->current($project->knative_region_id);
        foreach (['cpu_vcpu_hour', 'memory_gb_hour', 'gpu_hour'] as $field) {
            if ($price->exact($field)->isPositive()) {
                return true;
            }
        }

        return false;
    }

    /** The project is stopped, and its cost limit stopped it. */
    public function stoppedByLimit(Project $project): bool
    {
        return ProjectStopService::systemOf($project) === self::STOP_SYSTEM;
    }

    /**
     * What the project's bills of the month come to so far: the open bill calculated now (as the
     * Billing tab shows it), and a bill of earlier days of the month that already closed (billing
     * was turned off and on again).
     */
    public function monthToDate(Project $project, ?Period $period = null): BigDecimal
    {
        $period ??= Period::current();

        // Made when missing; null when billing was off the whole month
        $this->bills->projectBill($project, $period);

        $total = Money::dec(0);
        $bills = $this->bills->query()->where('subject_key', Bill::projectKey((string) $project->id))->where('period', $period->key())->get();
        foreach ($bills as $bill) {
            $total = $total->plus(Money::dec($this->bills->values($bill)['total'] ?? $bill->total ?? 0));
        }

        return $total;
    }

    /** Month to date ÷ elapsed days × days in the month, rounded to the minor unit. */
    public function forecast(BigDecimal $monthToDate, ?Period $period = null): BigDecimal
    {
        $period ??= Period::current();
        $elapsed = $this->elapsedDays($period);

        return Money::round($monthToDate->toBigRational()->dividedBy($elapsed)->multipliedBy($period->days()));
    }

    /**
     * The limit and this month's figures as the Billing tab, the API and the MCP tools show them.
     *
     * @return array<string, mixed>
     */
    public function status(Project $project, bool $canManage, ?CostLimit $limit = null): array
    {
        $limit ??= $this->find($project);
        $period = Period::current();
        $stopped = $this->stoppedByLimit($project);
        $base = [
            'available' => false,
            'currency' => BillingContext::currency(),
            'period' => $period->key(),
            'limit' => $limit ? $this->presentLimit($limit) : null,
            'month_to_date' => null,
            'forecast' => null,
            'days_elapsed' => $this->elapsedDays($period),
            'days_in_month' => $period->days(),
            'percent' => null,
            'warning_percent' => CostLimit::WARNING_PERCENT,
            'state' => 'none',
            'warned_at' => null,
            'reached_at' => null,
            'stopped' => $stopped,
            'can_manage' => $canManage,
            // A project stopped by its limit that moved to a fixed region: nothing stops it any more
            'can_resume' => $stopped && $canManage,
            'checked_at' => $limit?->checked_at?->toIso8601String(),
        ];

        if (! $this->applies($project)) {
            return $base;
        }

        $cost = $this->monthToDate($project, $period);
        $current = $limit !== null && $limit->period === $period->key();
        $percent = $limit !== null ? $this->percent($cost, Money::dec($limit->amount)) : null;

        return array_replace($base, [
            'available' => true,
            'month_to_date' => Money::toFloat($cost),
            'forecast' => Money::toFloat($this->forecast($cost, $period)),
            'percent' => $percent,
            'state' => $this->state($cost, $limit),
            'warned_at' => $current ? $limit->warned_at?->toIso8601String() : null,
            'reached_at' => $current ? $limit->reached_at?->toIso8601String() : null,
            'can_resume' => $stopped && $canManage && ! $this->stops($limit, $cost),
        ]);
    }

    /**
     * Set (or change) the project's limit. A new amount starts this month's warning and notice
     * over, a switch to "stop" the notice: the limit is checked right away, so one that the month
     * already passed warns — or stops the project — now.
     *
     * @throws BillingRefusal cost_limit_not_available: a fixed-price project or a free region
     */
    public function set(Project $project, string $amount, string $action, ?string $actorId): CostLimit
    {
        if (! $this->applies($project)) {
            throw new BillingRefusal('cost_limit_not_available', __('billing.cost_limit.not_available'), 422);
        }

        $period = Period::current()->key();
        $limit = $this->find($project) ?? new CostLimit(['project_id' => (string) $project->id, 'period' => $period]);
        $newAmount = ! $limit->exists || ! Money::dec($limit->amount)->isEqualTo(Money::dec($amount));
        $toStop = $limit->exists && $limit->action !== CostLimit::STOP && $action === CostLimit::STOP;

        $limit->forceFill([
            'amount' => (string) Money::round(Money::dec($amount), 3),
            'currency' => BillingContext::currency(),
            'action' => $action,
            'updated_by' => $actorId,
        ]);
        if ($newAmount) {
            $limit->forceFill(['warned_at' => null, 'reached_at' => null]);
        } elseif ($toStop) {
            $limit->forceFill(['reached_at' => null]);
        }
        $limit->save();

        $this->evaluateSafely($project, $limit);

        return $limit->refresh();
    }

    /** Remove the limit. A project it stopped stays stopped until it is resumed. */
    public function clear(Project $project): void
    {
        $this->find($project)?->delete();
    }

    /**
     * Resume a project its limit stopped (ProjectStopService::resume: the version that ran is
     * deployed again) — once the limit no longer stops it.
     *
     * @return Deployment|null the redeploy
     *
     * @throws BillingRefusal cost_limit_not_stopped, cost_limit_still_reached
     * @throws ProjectStopConflict
     */
    public function resume(Project $project, Authenticatable $actor, ?string &$refusal = null, ?string &$refusalCode = null): ?Deployment
    {
        if (! $this->stoppedByLimit($project)) {
            throw new BillingRefusal('cost_limit_not_stopped', __('billing.cost_limit.not_stopped'), 409);
        }

        $limit = $this->find($project);
        if ($limit !== null && $this->applies($project)) {
            $cost = $this->monthToDate($project);
            if ($this->stops($limit, $cost)) {
                throw new BillingRefusal('cost_limit_still_reached', __('billing.cost_limit.still_reached', [
                    'cost' => $this->money($cost),
                    'limit' => $this->money(Money::dec($limit->amount)),
                ]), 409);
            }
        }

        return $this->projectStops->resume($project, $actor, $refusal, $refusalCode);
    }

    /** Check every limit of the organization (CheckCostLimitsJob). */
    public function evaluateAll(): int
    {
        if (! $this->hasTable()) {
            return 0;
        }

        try {
            $limits = CostLimit::query()->orderBy('project_id')->get();
        } catch (QueryException $e) {
            Log::warning('Billing: cost limits not read', ['tenant' => BillingContext::tenantKey(), 'error' => $e->getMessage()]);

            return 0;
        }

        $projects = Project::query()->whereIn('id', $limits->pluck('project_id')->all())->get()->keyBy(fn (Project $p) => (string) $p->id);
        $checked = 0;
        foreach ($limits as $limit) {
            $project = $projects->get($limit->project_id);
            if ($project === null) {
                // Deleted without the hook (a raw delete, an earlier release)
                $limit->delete();

                continue;
            }

            try {
                $this->evaluate($project, $limit);
                $checked++;
            } catch (\Throwable $e) {
                Log::error('Billing: cost limit not checked', ['project' => $limit->project_id, 'tenant' => BillingContext::tenantKey(), 'error' => $e->getMessage()]);
                report($e);
            }
        }

        return $checked;
    }

    /**
     * Compare the month so far with the limit: at WARNING_PERCENT the warning, at 100 % the notice
     * and the action — each once per month and limit. Claimed with a conditional update, so two
     * checks at the same moment (the job and a save) send them once.
     */
    public function evaluate(Project $project, CostLimit $limit): void
    {
        $period = Period::current();
        $this->rollOver($limit, $period);
        $this->resumeInNewMonth($project, $limit, $period);

        if (! $this->applies($project)) {
            return;
        }

        $cost = $this->monthToDate($project, $period);
        $amount = Money::dec($limit->amount);
        $limit->forceFill(['month_cost' => (string) $cost, 'checked_at' => now()])->save();

        if ($cost->isGreaterThanOrEqualTo($amount)) {
            if ($this->claim($limit, 'reached_at', $period)) {
                // Reached at once: no warning after the notice
                $this->claim($limit, 'warned_at', $period);
                $this->reached($project, $limit, $cost, $period);
            }

            return;
        }

        if ($cost->multipliedBy(100)->isGreaterThanOrEqualTo($amount->multipliedBy(CostLimit::WARNING_PERCENT)) && $this->claim($limit, 'warned_at', $period)) {
            $this->warned($project, $limit, $cost, $period);
        }
    }

    /**
     * The project is gone: so is its limit — whether billing is on or not, and without a query
     * that could fail inside the transaction the project is deleted in where the table is missing.
     */
    public static function forget(string $projectId): void
    {
        try {
            if (Schema::hasTable('billing_cost_limits')) {
                CostLimit::query()->whereKey($projectId)->delete();
            }
        } catch (QueryException $e) {
            Log::warning('Billing: cost limit of a deleted project not removed', ['project' => $projectId, 'error' => $e->getMessage()]);
        }
    }

    // ── Steps ───────────────────────────────────────────────────────────────

    private function warned(Project $project, CostLimit $limit, BigDecimal $cost, Period $period): void
    {
        $data = $this->eventData($project, $limit, $cost, $period);
        $this->events->record(BillingEvents::PROJECT_COST_LIMIT_WARNING, $data,
            'project.cost_limit.warning:'.$project->id.':'.$period->key().':'.$this->amountKey($limit));

        $this->notifyOwners($project, $limit, BillingNotifier::COST_LIMIT_WARNING,
            __('billing.notify.cost_limit_warning_title', ['project' => $project->name, 'percent' => CostLimit::WARNING_PERCENT]),
            __('billing.notify.cost_limit_warning_body', [
                'project' => $project->name, 'cost' => $this->money($cost), 'limit' => $this->money(Money::dec($limit->amount)),
                'forecast' => $this->money($this->forecast($cost, $period)),
            ]));
    }

    private function reached(Project $project, CostLimit $limit, BigDecimal $cost, Period $period): void
    {
        $stopped = false;
        if ($limit->action === CostLimit::STOP && ! $project->isStopped()) {
            try {
                $this->projectStops->stopBySystem($project, self::STOP_SYSTEM, __('billing.cost_limit.stop_reason', [
                    'cost' => $this->money($cost), 'limit' => $this->money(Money::dec($limit->amount)), 'period' => $period->key(),
                ]));
                $stopped = true;
            } catch (ProjectStopConflict) {
                // Stopped a moment ago by someone else: it is not running either way
            }
        }

        $data = $this->eventData($project, $limit, $cost, $period) + ['stopped' => $stopped];
        $this->events->record(BillingEvents::PROJECT_COST_LIMIT_REACHED, $data,
            'project.cost_limit.reached:'.$project->id.':'.$period->key().':'.$this->amountKey($limit).':'.$limit->action);

        app(AuditLogger::class)->record('billing.cost_limit.reached', [
            'actor' => 'system',
            'target' => $project,
            'metadata' => ['period' => $period->key(), 'amount' => $limit->amount, 'currency' => BillingContext::currency(),
                'month_to_date' => Money::toFloat($cost), 'action' => $limit->action, 'stopped' => $stopped],
        ]);

        $body = match (true) {
            $stopped => 'billing.notify.cost_limit_stopped_body',
            $limit->action === CostLimit::STOP => 'billing.notify.cost_limit_reached_already_stopped_body',
            default => 'billing.notify.cost_limit_reached_body',
        };
        $this->notifyOwners($project, $limit, BillingNotifier::COST_LIMIT_REACHED,
            __($stopped ? 'billing.notify.cost_limit_stopped_title' : 'billing.notify.cost_limit_reached_title', ['project' => $project->name]),
            __($body, ['project' => $project->name, 'cost' => $this->money($cost), 'limit' => $this->money(Money::dec($limit->amount))]));
    }

    /**
     * A project its limit stopped in an earlier month runs again once a new month begins — unless
     * the new month already reached the limit: the version that ran is deployed again
     * (ProjectStopService::resumeBySystem) and the owner and whoever set the limit are told.
     * Tried on every check until it succeeds; a stop someone else took over stays.
     */
    private function resumeInNewMonth(Project $project, CostLimit $limit, Period $period): void
    {
        if (! $this->stoppedByLimit($project) || $project->stopped_at === null || $project->stopped_at->greaterThanOrEqualTo($period->start)) {
            return;
        }
        if ($this->applies($project) && $this->stops($limit, $this->monthToDate($project, $period))) {
            return;
        }

        try {
            $this->projectStops->resumeBySystem($project, self::STOP_SYSTEM, $refusal);
        } catch (ProjectStopConflict) {
            // Resumed (or taken over) a moment ago: nothing left to do here
            return;
        }
        $project->refresh();

        $this->notifyOwners($project, $limit, BillingNotifier::COST_LIMIT_RESUMED,
            __('billing.notify.cost_limit_resumed_title', ['project' => $project->name]),
            $refusal === null
                ? __('billing.notify.cost_limit_resumed_body', ['project' => $project->name, 'period' => $period->key()])
                : __('billing.notify.cost_limit_resumed_not_deployed_body', ['project' => $project->name, 'period' => $period->key(), 'reason' => $refusal]));
    }

    /** A new month: the warning and the notice start over. */
    private function rollOver(CostLimit $limit, Period $period): void
    {
        if ($limit->period === $period->key()) {
            return;
        }

        CostLimit::query()->whereKey($limit->project_id)
            ->where(fn ($q) => $q->whereNull('period')->orWhere('period', '!=', $period->key()))
            ->update(['period' => $period->key(), 'warned_at' => null, 'reached_at' => null, 'month_cost' => null]);
        $limit->refresh();
    }

    /** Set $column of this month's state unless it is set; true for the one check that set it. */
    private function claim(CostLimit $limit, string $column, Period $period): bool
    {
        $now = Carbon::now();
        $claimed = CostLimit::query()->whereKey($limit->project_id)->where('period', $period->key())->whereNull($column)
            ->update([$column => $now, 'updated_at' => $now]) > 0;
        if ($claimed) {
            $limit->setAttribute($column, $now);
            $limit->syncOriginalAttribute($column);
        }

        return $claimed;
    }

    /** The limit stops the project now: "stop", and the month so far at or above it. */
    private function stops(?CostLimit $limit, BigDecimal $cost): bool
    {
        return $limit !== null && $limit->action === CostLimit::STOP && $cost->isGreaterThanOrEqualTo(Money::dec($limit->amount));
    }

    private function state(BigDecimal $cost, ?CostLimit $limit): string
    {
        if ($limit === null) {
            return 'none';
        }
        $amount = Money::dec($limit->amount);

        return match (true) {
            $cost->isGreaterThanOrEqualTo($amount) => 'reached',
            $cost->multipliedBy(100)->isGreaterThanOrEqualTo($amount->multipliedBy(CostLimit::WARNING_PERCENT)) => 'warning',
            default => 'ok',
        };
    }

    private function percent(BigDecimal $cost, BigDecimal $amount): ?float
    {
        if (! $amount->isPositive()) {
            return null;
        }

        return Money::roundedFloat($cost->toBigRational()->multipliedBy(100)->dividedBy($amount), 1);
    }

    /** Days of the month up to today, today included (at least 1). */
    private function elapsedDays(Period $period): int
    {
        if (! $period->isCurrent()) {
            return $period->days();
        }

        return max(1, min($period->days(), Carbon::today()->day));
    }

    /** @return array<string, mixed> */
    private function presentLimit(CostLimit $limit): array
    {
        $by = $this->presenter->user($limit->updated_by);

        return [
            'amount' => $limit->amount,
            'action' => $limit->action,
            'updated_by' => $by ? ['id' => $by['id'], 'name' => $by['name']] : null,
            'updated_at' => $limit->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function eventData(Project $project, CostLimit $limit, BigDecimal $cost, Period $period): array
    {
        return [
            'project' => ['id' => (string) $project->id, 'name' => $project->name],
            'owner_id' => $project->payerId(),
            'period' => $period->key(),
            'currency' => BillingContext::currency(),
            'limit' => $limit->amount,
            'action' => $limit->action,
            'month_to_date' => Money::toFloat($cost),
            'forecast' => Money::toFloat($this->forecast($cost, $period)),
            'percent' => $this->percent($cost, Money::dec($limit->amount)),
        ];
    }

    /** The owner (payer) and whoever set the limit, if that is someone else. */
    private function notifyOwners(Project $project, CostLimit $limit, string $type, string $title, string $body): void
    {
        $ids = array_values(array_unique(array_filter([$project->payerId(), $limit->updated_by])));
        if ($ids === []) {
            return;
        }

        try {
            $users = User::on(app(Rbac::class)->organization()->userConnection())->whereIn('id', $ids)->get();
            $this->notifier->notify($users, $type, $title, $body,
                ['project_id' => (string) $project->id, 'project_name' => $project->name],
                '/projects/'.$project->id.'#billing');
        } catch (\Throwable $e) {
            Log::warning('Billing: cost limit notification not sent', ['project' => $project->id, 'type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** Checked when the limit is saved; a failure leaves it to the next scheduled check. */
    private function evaluateSafely(Project $project, CostLimit $limit): void
    {
        try {
            $this->evaluate($project, $limit);
        } catch (\Throwable $e) {
            Log::warning('Billing: cost limit not checked after saving', ['project' => $project->id, 'error' => $e->getMessage()]);
            report($e);
        }
    }

    private function amountKey(CostLimit $limit): string
    {
        return (string) Money::round(Money::dec($limit->amount), 3);
    }

    private function money(BigDecimal $amount): string
    {
        return number_format(Money::toFloat($amount), Money::minorUnit(), '.', '').' '.BillingContext::currency();
    }

    private function hasTable(): bool
    {
        return $this->tables[BillingContext::key()] ??= Schema::hasTable('billing_cost_limits');
    }
}
