<?php

namespace App\Http\Controllers\Api;

use App\Models\Project;
use App\Rules\KubernetesCpu;
use App\Services\Helpers\InstanceSizes;
use App\Services\Helpers\ResourceReservation;
use App\Support\OrgTimezone;
use App\Support\Projects\ScaleRules;
use App\Support\Projects\ScaleSchedule;
use App\Support\Projects\WarmEstimates;
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /api/scale-schedule/preview — what a timed warmer (docs/scaling.md#timed-warmer) does before
 * it is saved, evaluated the way the warmer applies it (ScaleSchedule, the organization's zone):
 *
 *   { scale_schedule: [{cron, min_scale}], knative_region_id?, service_type?, cpu?, memory_mb?,
 *     scale_to_zero?, max_scale?, trigger_type? }
 *   → { timezone, week_start, now, peak,
 *       timeline: 7 × 24 (Monday first; the most containers kept warm during each hour of this week),
 *       estimate: null | {billing_type: "payg", days, container_hours, vcpu_hours, memory_gb_hours,
 *                         cost, currency} }
 *
 * The estimate covers the next ESTIMATE_DAYS days at the project's size (the container's
 * requests, as metering counts warm containers) and is only given in pay-as-you-go regions while
 * billing is on (WarmEstimates); the cost only to those who may see prices. Rows are validated as
 * on a project (ScaleRules), errors keyed scale_schedule.N.cron / .min_scale.
 */
class ScaleScheduleController extends Controller
{
    /** Days the estimate covers. */
    public const ESTIMATE_DAYS = 30;

    /**
     * A timed warmer schedule before it is saved: the current week, the value now and the estimate
     * of the next 30 days (a pay-as-you-go region only).
     */
    #[BodyParameter('knative_region_id', 'The region (ULID) whose prices the estimate uses', type: 'string|null')]
    #[BodyParameter('scale_schedule', 'Up to 10 rows: from the cron\'s moment on, at least min_scale containers', type: 'list<array{cron: string, min_scale: int}>|null')]
    #[OpenApiResponse(200, 'The week (Monday 00:00 in the organization\'s time zone) as 7 × 24 cells of the most containers kept warm per hour, the value now, the peak and the estimate', type: 'array{timezone: string, week_start: string, now: int, peak: int, timeline: list<list<int>>, estimate: array{billing_type: "payg", days: int, container_hours: float, vcpu_hours: float, memory_gb_hours: float, cost: float|null, currency: string|null}|null}')]
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'knative_region_id' => ['nullable', 'ulid', 'exists:knative_regions,id'],
            'service_type' => ['nullable', 'string', Rule::in(Project::SERVICE_TYPES)],
            'cpu' => ['bail', 'nullable', 'string', new KubernetesCpu],
            'memory_mb' => ['nullable', 'integer', 'min:64'],
            'scale_to_zero' => ['nullable', 'boolean'],
            'max_scale' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'trigger_type' => ['nullable', 'string', Rule::in(Project::TRIGGER_TYPES)],
        ] + ScaleRules::schedule(
            'scale_schedule',
            fn () => is_numeric($m = $request->input('max_scale')) ? (int) $m : 0,
            fn () => $request->input('trigger_type'),
        ));

        $rows = ScaleSchedule::normalize($data['scale_schedule'] ?? null) ?? [];
        $timezone = OrgTimezone::get();
        $now = now();
        $floor = ScaleSchedule::floor($data['scale_to_zero'] ?? true);
        $week = ScaleSchedule::week($rows, $now, $timezone, $floor);

        return response()->json([
            'timezone' => $timezone,
            'week_start' => $week['week_start']->toIso8601String(),
            'now' => max($floor, ScaleSchedule::valueAt($rows, $now, $timezone)),
            'peak' => max($floor, ScaleSchedule::peak($rows)),
            'timeline' => $week['timeline'],
            'estimate' => $rows === [] ? null : $this->estimate($request, $data, $rows, $timezone, $floor),
        ]);
    }

    /**
     * Warm hours of the next ESTIMATE_DAYS days and their cost, in a pay-as-you-go region only.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array{cron: string, min_scale: int}>  $rows
     * @return array<string, mixed>|null
     */
    private function estimate(Request $request, array $data, array $rows, string $timezone, int $floor): ?array
    {
        $now = now();
        $hours = ScaleSchedule::containerSeconds($rows, $now, $now->copy()->addDays(self::ESTIMATE_DAYS), $timezone, $floor) / 3600;

        $defaults = InstanceSizes::defaultFor($data['service_type'] ?? 'xaas');
        $cpuM = ResourceReservation::parseCpu($data['cpu'] ?? $defaults['cpu']);
        $memoryMb = (int) ($data['memory_mb'] ?? $defaults['memory_mb']);
        $vcpuHours = $hours * $cpuM / 1000;
        $memoryGbHours = $hours * $memoryMb / 1024;

        $price = WarmEstimates::price($data['knative_region_id'] ?? null, $vcpuHours, $memoryGbHours, (string) ($data['trigger_type'] ?? 'http'), $request->user());
        if (($price['billing_type'] ?? null) !== 'payg') {
            return null;
        }

        return [
            'billing_type' => 'payg',
            'days' => self::ESTIMATE_DAYS,
            'container_hours' => round($hours, 1),
            'vcpu_hours' => round($vcpuHours, 1),
            'memory_gb_hours' => round($memoryGbHours, 1),
            'cost' => $price['cost'],
            'currency' => $price['currency'],
        ];
    }
}
