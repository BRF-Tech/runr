<?php

/*
|--------------------------------------------------------------------------
| Rightsizing recommendations (docs/monitoring.md#rightsizing)
|--------------------------------------------------------------------------
|
| Every night RunR keeps the day's peaks of every running project (resource_peaks: the highest
| and the 95th percentile working-set memory and CPU of one pod, OOM kills, CPU throttling) from
| the region's Prometheus, then compares the last window_days with the project's size: a size
| clearly larger than what the pods used is recommended smaller, a project killed for memory,
| throttled or working close to its memory limit larger. Recommended sizes are steps of the
| ladders below (the instance size presets and the powers of two between them).
|
*/

return [
    // Off: no peaks are collected and no recommendation is made (existing ones stay readable)
    'enabled' => env('RIGHTSIZING_ENABLED', true),

    // The days a recommendation looks back on (at most what the region's Prometheus keeps: 15 days)
    'window_days' => (int) env('RIGHTSIZING_WINDOW_DAYS', 14),

    // Too little data: fewer days with readings, or a scale-to-zero project with fewer sessions
    'min_days' => (int) env('RIGHTSIZING_MIN_DAYS', 3),
    'min_sessions' => (int) env('RIGHTSIZING_MIN_SESSIONS', 20),

    // Recommended size = observed peak × margin, rounded up to the next step. Memory follows the
    // highest working set (an OOM kill ends the request), CPU its 95th percentile (above the
    // limit a pod is throttled, not killed): 'max' or 'p95'.
    'memory_margin' => (float) env('RIGHTSIZING_MEMORY_MARGIN', 1.3),
    'cpu_margin' => (float) env('RIGHTSIZING_CPU_MARGIN', 1.3),
    'memory_basis' => env('RIGHTSIZING_MEMORY_BASIS', 'max'),
    'cpu_basis' => env('RIGHTSIZING_CPU_BASIS', 'p95'),

    // Under-provisioned: a peak at this share of the memory size, or this share of the CPU
    // periods throttled, on a day the project ran with its current size
    'memory_near_limit' => (float) env('RIGHTSIZING_MEMORY_NEAR_LIMIT', 0.9),
    'cpu_throttled_ratio' => (float) env('RIGHTSIZING_CPU_THROTTLED_RATIO', 0.25),

    // The sizes a recommendation picks from (MiB, millicores), smallest first
    'memory_steps_mb' => [128, 256, 512, 1024, 2048, 4096, 8192, 16384, 32768, 65536],
    'cpu_steps_m' => [125, 250, 500, 1000, 2000, 4000, 8000, 16000, 32000],

    // Days a recommendation stays hidden when it is snoozed without a number of days
    'snooze_days' => (int) env('RIGHTSIZING_SNOOZE_DAYS', 30),

    // Days of resource_peaks kept (at least window_days)
    'retention_days' => (int) env('RIGHTSIZING_RETENTION_DAYS', 30),

    // Hours of a month in the savings estimate of an always-on workload (365 × 24 / 12)
    'hours_per_month' => 730,
];
