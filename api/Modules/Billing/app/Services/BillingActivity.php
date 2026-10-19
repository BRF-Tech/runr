<?php

namespace Modules\Billing\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Billing\Models\Bill;
use Modules\Billing\Support\BillingContext;
use Modules\Billing\Support\Period;

/**
 * The days billing was on (docs/billing.md#turning-billing-off-and-on). No money is charged for a
 * day billing was off, although metering keeps running and fixed allocations stay approved:
 *
 *  - while billing is on, touch() records the day (active_on) — every hour (billing-heartbeat)
 *    and whenever bills are worked on;
 *  - while it is off, the scheduler records the day it ran (off_seen_on, markOff(): the
 *    billing-off-marker task of BillingServiceProvider);
 *  - the first touch() after billing was seen off records the days in between as an off period:
 *    the day after the last active day … the day before billing came back.
 *
 * BILLING_ENABLED is one switch for the whole installation, so these three values are kept
 * centrally (the install's or the platform's billing_settings) and hold for every organization.
 * windows() gives the days of a month that are billed: the open bills from before an off period
 * end with its last active day (clipOpenBills()), a month inside it gets no bill, and the bills
 * of the month billing came back in start on that day. An outage of the whole installation (no
 * scheduler at all) records nothing and is no off period: the close job catches up as before.
 *
 * A tenant converted from a single-organization install bills from the conversion day (bills_from
 * in its own billing_settings, docs/tenancy.md#billing-after-the-conversion).
 */
class BillingActivity
{
    public const ACTIVE_ON = 'active_on';

    public const OFF_SEEN_ON = 'off_seen_on';

    public const OFF_PERIODS = 'off_periods';

    public const BILLS_FROM = 'bills_from';

    private ?string $touched = null;

    /** @var list<array{from: string, to: string}>|null */
    private ?array $offPeriods = null;

    public function __construct(private readonly BillingSettings $settings) {}

    /**
     * Billing runs today: record it, and when it was seen off since its last active day, the days
     * in between as an off period.
     */
    public function touch(): void
    {
        $today = Carbon::today()->toDateString();
        if ($this->touched === $today) {
            return;
        }

        try {
            $db = DB::connection(BillingContext::central());
            $values = $db->table('billing_settings')->whereIn('key', [self::ACTIVE_ON, self::OFF_SEEN_ON, self::OFF_PERIODS])->pluck('value', 'key');
            $active = $this->date($values[self::ACTIVE_ON] ?? null);
            $off = $this->date($values[self::OFF_SEEN_ON] ?? null);

            if ($active !== null && $active < $today && $off !== null && $off > $active) {
                $from = Carbon::parse($active)->addDay()->toDateString();
                $to = Carbon::parse($today)->subDay()->toDateString();
                if ($from <= $to) {
                    $periods = $this->decode($values[self::OFF_PERIODS] ?? null);
                    $periods[] = ['from' => $from, 'to' => $to];
                    $this->put($db, self::OFF_PERIODS, (string) json_encode($this->normalize($periods)));
                }
            }
            if ($active !== $today) {
                $this->put($db, self::ACTIVE_ON, $today);
            }
        } catch (QueryException) {
            return; // billing tables not migrated yet
        }

        $this->touched = $today;
        $this->offPeriods = null;
        $this->settings->flush();
    }

    /** Billing is off today (the scheduler while BILLING_ENABLED is false): record the day. */
    public static function markOff(): void
    {
        $connection = BillingContext::central();
        try {
            if (! Schema::connection($connection)->hasTable('billing_settings')) {
                return; // billing never ran here
            }
            $db = DB::connection($connection);
            $today = Carbon::today()->toDateString();
            if ($db->table('billing_settings')->where('key', self::OFF_SEEN_ON)->value('value') !== $today) {
                $db->table('billing_settings')->updateOrInsert(['key' => self::OFF_SEEN_ON], ['value' => $today, 'updated_at' => now(), 'created_at' => now()]);
            }
        } catch (QueryException) {
            // no database yet
        }
    }

    /**
     * The recorded off periods, oldest first.
     *
     * @return list<array{from: string, to: string}>
     */
    public function offPeriods(): array
    {
        if ($this->offPeriods === null) {
            try {
                $this->offPeriods = $this->normalize($this->decode(DB::connection(BillingContext::central())->table('billing_settings')->where('key', self::OFF_PERIODS)->value('value')));
            } catch (QueryException) {
                $this->offPeriods = [];
            }
        }

        return $this->offPeriods;
    }

    /** The first day billed in this organization, null: no limit (a converted tenant's conversion day). */
    public function billsFrom(): ?string
    {
        return $this->date($this->settings->get(self::BILLS_FROM));
    }

    /**
     * The days of $period that are billed, as [first, last] runs: from $from (and the
     * organization's first billed day, unless $organization is false: the platform's bills to a
     * tenant) to $until, without the off periods.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function windows(Period $period, ?string $from = null, ?string $until = null, bool $organization = true): array
    {
        $start = max(array_filter([$period->startDate(), $from, $organization ? $this->billsFrom() : null]));
        $end = min($period->endDate(), $until ?? $period->endDate());
        if ($start > $end) {
            return [];
        }

        $windows = [[$start, $end]];
        foreach ($this->offPeriods() as $off) {
            $next = [];
            foreach ($windows as [$a, $b]) {
                if ($off['to'] < $a || $off['from'] > $b) {
                    $next[] = [$a, $b];

                    continue;
                }
                if ($off['from'] > $a) {
                    $next[] = [$a, Carbon::parse($off['from'])->subDay()->toDateString()];
                }
                if ($off['to'] < $b) {
                    $next[] = [Carbon::parse($off['to'])->addDay()->toDateString(), $b];
                }
            }
            $windows = $next;
        }

        return $windows;
    }

    /**
     * The window of $period a bill made today covers: the one with today, else the last one that
     * has started (a month that has ended: its last window).
     *
     * @param  list<array{0: string, 1: string}>  $windows
     * @return array{0: string, 1: string}|null
     */
    public static function currentWindow(array $windows): ?array
    {
        $today = Carbon::today()->toDateString();
        $current = null;
        foreach ($windows as $window) {
            if ($window[0] <= $today) {
                $current = $window;
            }
        }

        return $current;
    }

    /**
     * Open bills that reach into an off period end with the day before it (they close with the
     * next run of the close job); an open bill inside one has nothing to bill and goes.
     *
     * @param  Builder<Bill>  $openBills
     */
    public function clipOpenBills(Builder $openBills): void
    {
        foreach ($this->offPeriods() as $off) {
            $bills = (clone $openBills)->where('status', Bill::OPEN)
                ->whereDate('period_start', '<=', $off['to'])->whereDate('period_end', '>=', $off['from'])->get();
            foreach ($bills as $bill) {
                $start = $bill->period_start->toDateString();
                $end = $bill->period_end->toDateString();
                if ($start < $off['from']) {
                    $bill->forceFill(['period_end' => Carbon::parse($off['from'])->subDay()->toDateString()])->save();
                } elseif ($end > $off['to']) {
                    $bill->forceFill(['period_start' => Carbon::parse($off['to'])->addDay()->toDateString()])->save();
                } else {
                    $bill->delete();
                }
            }
        }
    }

    private function put(Connection $db, string $key, string $value): void
    {
        $db->table('billing_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    /** @return list<array{from: string, to: string}> */
    private function decode(mixed $value): array
    {
        $rows = is_string($value) ? json_decode($value, true) : null;
        $periods = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $from = $this->date($row['from'] ?? null);
            $to = $this->date($row['to'] ?? null);
            if ($from !== null && $to !== null && $from <= $to) {
                $periods[] = ['from' => $from, 'to' => $to];
            }
        }

        return $periods;
    }

    /**
     * Sorted, overlapping and adjacent periods merged.
     *
     * @param  list<array{from: string, to: string}>  $periods
     * @return list<array{from: string, to: string}>
     */
    private function normalize(array $periods): array
    {
        usort($periods, fn ($a, $b) => [$a['from'], $a['to']] <=> [$b['from'], $b['to']]);
        $merged = [];
        foreach ($periods as $period) {
            $last = count($merged) - 1;
            if ($last >= 0 && $period['from'] <= Carbon::parse($merged[$last]['to'])->addDay()->toDateString()) {
                $merged[$last]['to'] = max($merged[$last]['to'], $period['to']);

                continue;
            }
            $merged[] = $period;
        }

        return $merged;
    }
}
