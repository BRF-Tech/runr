<?php

namespace App\Services\Rightsizing;

use App\Models\Project;
use App\Services\Helpers\ResourceReservation;
use App\Services\Helpers\WorkloadSizing;
use Closure;

/**
 * What a recommended size changes, without and with money:
 *
 *  - freed: the resources the change frees (positive) or takes (negative) — per pod × the pods an
 *    always-on workload keeps running (max(1, min_scale)), one pod of a scale-to-zero workload
 *  - peak: the change of the workload's worst case (WorkloadSizing::peak: every pod up to
 *    max_scale with the queue-proxy and RuntimeClass overhead), what an allocation in a
 *    fixed-price region must hold
 *  - money: the monthly difference a pricer prices — the Billing module registers one while billing
 *    is on (Modules\Billing\Services\RightsizingPricer: pay-as-you-go reservations, fixed
 *    allocations); null without billing, the resources are shown alone
 *
 * The pricer gets the project and {region_id, trigger_type, always_on, pods, hours, delta,
 * peak_delta} (delta and peak_delta {cpu_m, memory_mb}, positive = freed) and returns
 * {basis, monthly, currency, …} or null.
 */
final class Savings
{
    /** @var (Closure(Project, array<string, mixed>): (array<string, mixed>|null))|null */
    private static ?Closure $pricer = null;

    /** @param  (Closure(Project, array<string, mixed>): (array<string, mixed>|null))|null  $pricer */
    public static function priceWith(?Closure $pricer): void
    {
        self::$pricer = $pricer;
    }

    /** Test helper / Billing module unwire: no pricer. */
    public static function reset(): void
    {
        self::$pricer = null;
    }

    /**
     * @param  array{region_id: string|null, cpu_m: int, memory_mb: int, trigger_type: string,
     *     min_scale: int, max_scale: int, scale_to_zero: bool, gpu_count: int}  $current  WorkloadSizing::of()
     * @param  array{cpu_m: int, memory_mb: int}  $recommended
     * @return array{freed: array{cpu_m: int, memory_mb: int, pods: int, always_on: bool},
     *     peak: array{cpu_m: int, memory_mb: int}, money: array<string, mixed>|null}
     */
    public static function estimate(Project $project, array $current, array $recommended): array
    {
        $alwaysOn = WorkloadSizing::isAlwaysOn($current);
        $pods = $alwaysOn ? WorkloadSizing::reservedPods($current) : 1;
        $delta = [
            'cpu_m' => $current['cpu_m'] - $recommended['cpu_m'],
            'memory_mb' => $current['memory_mb'] - $recommended['memory_mb'],
        ];

        $overhead = ResourceReservation::podOverhead($project->knativeRegion, $project->service_type);
        $before = WorkloadSizing::peak($current, $overhead);
        $after = WorkloadSizing::peak(array_merge($current, $recommended), $overhead);
        $peak = ['cpu_m' => $before['cpu_m'] - $after['cpu_m'], 'memory_mb' => $before['memory_mb'] - $after['memory_mb']];

        $money = null;
        if (self::$pricer !== null) {
            try {
                $money = (self::$pricer)($project, [
                    'region_id' => $current['region_id'],
                    'trigger_type' => $current['trigger_type'],
                    'always_on' => $alwaysOn,
                    'pods' => $pods,
                    'hours' => (int) config('rightsizing.hours_per_month', 730),
                    'delta' => $delta,
                    'peak_delta' => $peak,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return [
            'freed' => [
                'cpu_m' => $delta['cpu_m'] * $pods,
                'memory_mb' => $delta['memory_mb'] * $pods,
                'pods' => $pods,
                'always_on' => $alwaysOn,
            ],
            'peak' => $peak,
            'money' => is_array($money) ? $money : null,
        ];
    }
}
