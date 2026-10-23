<?php

namespace App\Services\Metering;

use App\Models\DeploymentSession;
use Illuminate\Support\Carbon;

/**
 * What a deployment session uses (docs/billing.md#metering-and-the-fixed-bill): its pods hold the
 * size their deployment was applied with — CPU, memory and GPUs — from the session's start until
 * it ended; a session still running counts until now. Metering books it per day by overlap
 * (UsageAggregator), and the metrics summary reports the same figures
 * (SessionTrackingService::getStats).
 */
final class SessionUsage
{
    /** When the session's pods stopped; null while it runs. duration_ms is exact to the ms, ended_at to the second. */
    public static function endOf(DeploymentSession $session): ?Carbon
    {
        if ($session->ended_at === null) {
            return null;
        }

        return $session->duration_ms !== null
            ? $session->started_at->copy()->addMilliseconds(max(0, (int) $session->duration_ms))
            : $session->ended_at->copy();
    }

    /**
     * Milliseconds the session ran within [$from, $to): an ended session from its start to its
     * end, a running one until $runningUntil (now; default $to).
     */
    public static function msWithin(DeploymentSession $session, Carbon $from, Carbon $to, ?Carbon $runningUntil = null): int
    {
        $start = $session->started_at;
        $end = self::endOf($session) ?? ($runningUntil !== null && $runningUntil->lt($to) ? $runningUntil : $to);

        $lo = $start->gt($from) ? $start : $from;
        $hi = $end->lt($to) ? $end : $to;

        return $hi->gt($lo) ? (int) $lo->diffInMilliseconds($hi) : 0;
    }

    /** The session's pods (one per session; pod_count for sessions recorded with more). */
    public static function pods(DeploymentSession $session): int
    {
        return max(1, (int) ($session->pod_count ?? 1));
    }

    /**
     * What pod time at a size uses: CPU millicore·ms, memory MiB·ms and GPU-unit·ms.
     *
     * @param  array{cpu_m: int, memory_mb: int, gpu_count: int}  $sizing
     * @return array{cpu_mc_ms: int, memory_mib_ms: int, gpu_unit_ms: int}
     */
    public static function at(int $podMs, array $sizing): array
    {
        return [
            'cpu_mc_ms' => $podMs * (int) $sizing['cpu_m'],
            'memory_mib_ms' => $podMs * (int) $sizing['memory_mb'],
            'gpu_unit_ms' => $podMs * (int) $sizing['gpu_count'],
        ];
    }
}
