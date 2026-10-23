<?php

namespace App\Services\Rightsizing;

/**
 * The size a project's last days call for (docs/monitoring.md#rightsizing) — pure: the caller
 * passes the current size, the daily peaks of the window and the sessions.
 *
 *  - too little data: fewer days with readings than min_days, or a scale-to-zero workload with
 *    fewer sessions (or pods) than min_sessions → "collecting"
 *  - memory: under-provisioned when a day at the current size had an OOM kill or a peak at
 *    memory_near_limit of the size → the next step up (or more, when the peaks call for more);
 *    otherwise the observed peak (memory_basis: max | p95) × memory_margin rounded up to the
 *    next step, recommended only when it is smaller than the current size
 *  - CPU: the same with cpu_basis × cpu_margin; under-provisioned when a day at the current
 *    size had cpu_throttled_ratio of its CFS periods throttled
 *
 * Signals of being too small count only on days the project ran with its current size: an OOM
 * kill before a resize does not ask for another one. A resource without readings keeps its size.
 *
 * @phpstan-type Day array{day: string, cpu_m: int, memory_mb: int, memory_max_mb: float|null,
 *     memory_p95_mb: float|null, cpu_max_m: int|null, cpu_p95_m: int|null, throttled_ratio: float|null,
 *     oom_kills: int, samples: int, pods: int}
 */
final class SizeRecommender
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param  array<string, mixed>|null  $config  config('rightsizing') */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('rightsizing', []);
    }

    /**
     * @param  array{cpu_m: int, memory_mb: int, always_on: bool}  $current
     * @param  list<Day>  $days  the window's daily peaks
     * @param  int  $sessions  sessions of the window (scale-to-zero workloads)
     * @return array{status: string, cpu_m?: int, memory_mb?: int, direction?: string,
     *     changes?: array{cpu: string, memory: string}, reasons?: list<string>,
     *     collecting?: array<string, int>, evidence: array<string, mixed>}
     */
    public function recommend(array $current, array $days, int $sessions, string $from = '', string $to = ''): array
    {
        $withData = array_values(array_filter($days, fn (array $d) => (int) $d['samples'] > 0));
        $pods = array_sum(array_map(fn (array $d) => (int) $d['pods'], $withData));
        $sessions = max($sessions, $pods);

        $minDays = (int) ($this->config['min_days'] ?? 3);
        $minSessions = (int) ($this->config['min_sessions'] ?? 20);

        $evidence = $this->evidence($current, $withData, $sessions, $from, $to);

        if (count($withData) < $minDays || (! $current['always_on'] && $sessions < $minSessions)) {
            return [
                'status' => 'collecting',
                'collecting' => [
                    'days' => count($withData),
                    'required_days' => $minDays,
                    'sessions' => $sessions,
                    'required_sessions' => $current['always_on'] ? 0 : $minSessions,
                    'window_days' => (int) ($this->config['window_days'] ?? 14),
                ],
                'evidence' => $evidence,
            ];
        }

        $atCurrentSize = array_values(array_filter($withData, fn (array $d) => (int) $d['memory_mb'] === $current['memory_mb']));
        $atCurrentCpu = array_values(array_filter($withData, fn (array $d) => (int) $d['cpu_m'] === $current['cpu_m']));
        $reasons = [];

        // ── Memory ──────────────────────────────────────────────────────────────
        $memorySteps = $this->steps('memory_steps_mb', [128, 256, 512, 1024, 2048, 4096, 8192, 16384, 32768, 65536]);
        $memoryObserved = ($this->config['memory_basis'] ?? 'max') === 'p95' ? $evidence['memory']['p95_mb'] : $evidence['memory']['max_mb'];
        $memoryTarget = $memoryObserved === null ? null : $memoryObserved * (float) ($this->config['memory_margin'] ?? 1.3);
        $oomKills = array_sum(array_map(fn (array $d) => (int) $d['oom_kills'], $atCurrentSize));
        $nearLimit = $current['memory_mb'] > 0 && count(array_filter($atCurrentSize, fn (array $d) => $d['memory_max_mb'] !== null
            && (float) $d['memory_max_mb'] >= (float) ($this->config['memory_near_limit'] ?? 0.9) * $current['memory_mb'])) > 0;

        $memory = $current['memory_mb'];
        $memoryChange = 'keep';
        if ($oomKills > 0 || $nearLimit) {
            $reasons[] = $oomKills > 0 ? 'memory_oom_killed' : 'memory_near_limit';
            $memory = max(self::stepAbove($current['memory_mb'], $memorySteps), $memoryTarget === null ? 0 : self::ceilStep($memoryTarget, $memorySteps));
            $memoryChange = 'up';
        } elseif ($memoryTarget !== null && ($candidate = self::ceilStep($memoryTarget, $memorySteps)) < $current['memory_mb']) {
            $reasons[] = 'memory_over_provisioned';
            $memory = $candidate;
            $memoryChange = 'down';
        }

        // ── CPU ─────────────────────────────────────────────────────────────────
        $cpuSteps = $this->steps('cpu_steps_m', [125, 250, 500, 1000, 2000, 4000, 8000, 16000, 32000]);
        $cpuObserved = ($this->config['cpu_basis'] ?? 'p95') === 'max' ? $evidence['cpu']['max_m'] : $evidence['cpu']['p95_m'];
        $cpuTarget = $cpuObserved === null ? null : $cpuObserved * (float) ($this->config['cpu_margin'] ?? 1.3);
        $throttled = max([0.0, ...array_map(fn (array $d) => (float) ($d['throttled_ratio'] ?? 0.0), $atCurrentCpu)]);

        $cpu = $current['cpu_m'];
        $cpuChange = 'keep';
        if ($throttled >= (float) ($this->config['cpu_throttled_ratio'] ?? 0.25)) {
            $reasons[] = 'cpu_throttled';
            $cpu = max(self::stepAbove($current['cpu_m'], $cpuSteps), $cpuTarget === null ? 0 : self::ceilStep($cpuTarget, $cpuSteps));
            $cpuChange = 'up';
        } elseif ($cpuTarget !== null && ($candidate = self::ceilStep($cpuTarget, $cpuSteps)) < $current['cpu_m']) {
            $reasons[] = 'cpu_over_provisioned';
            $cpu = $candidate;
            $cpuChange = 'down';
        }

        $evidence['oom_kills'] = $oomKills;
        $evidence['throttled_ratio'] = round($throttled, 4);
        $evidence['memory']['near_limit'] = $nearLimit;

        if ($memoryChange === 'keep' && $cpuChange === 'keep') {
            return ['status' => 'right_sized', 'evidence' => $evidence];
        }

        $changes = array_values(array_unique(array_diff([$memoryChange, $cpuChange], ['keep'])));

        return [
            'status' => 'recommended',
            'cpu_m' => $cpu,
            'memory_mb' => $memory,
            'direction' => count($changes) === 1 ? $changes[0] : 'mixed',
            'changes' => ['cpu' => $cpuChange, 'memory' => $memoryChange],
            'reasons' => $reasons,
            'evidence' => $evidence,
        ];
    }

    /** The smallest step at least $value; above the last step, multiples of it. */
    public static function ceilStep(float $value, array $steps): int
    {
        foreach ($steps as $step) {
            if ($step >= $value) {
                return (int) $step;
            }
        }
        $top = (int) end($steps);

        return (int) (ceil($value / $top) * $top);
    }

    /** The next step above $current; above the last step, twice the size. */
    public static function stepAbove(int $current, array $steps): int
    {
        foreach ($steps as $step) {
            if ($step > $current) {
                return (int) $step;
            }
        }

        return $current * 2;
    }

    /**
     * @param  array{cpu_m: int, memory_mb: int, always_on: bool}  $current
     * @param  list<Day>  $days
     * @return array<string, mixed>
     */
    private function evidence(array $current, array $days, int $sessions, string $from, string $to): array
    {
        $max = function (string $field) use ($days): ?float {
            $values = array_filter(array_map(fn (array $d) => $d[$field], $days), fn ($v) => $v !== null);

            return $values === [] ? null : (float) max($values);
        };

        $memoryMax = $max('memory_max_mb');
        $memoryP95 = $max('memory_p95_mb');
        $cpuMax = $max('cpu_max_m');
        $cpuP95 = $max('cpu_p95_m');

        return [
            'window_days' => (int) ($this->config['window_days'] ?? 14),
            'from' => $from,
            'to' => $to,
            'days' => count($days),
            'sessions' => $sessions,
            'samples' => array_sum(array_map(fn (array $d) => (int) $d['samples'], $days)),
            'always_on' => $current['always_on'],
            'memory' => [
                'max_mb' => $memoryMax === null ? null : round($memoryMax, 1),
                'p95_mb' => $memoryP95 === null ? null : round($memoryP95, 1),
                'basis' => ($this->config['memory_basis'] ?? 'max') === 'p95' ? 'p95' : 'max',
                'margin' => (float) ($this->config['memory_margin'] ?? 1.3),
            ],
            'cpu' => [
                'max_m' => $cpuMax === null ? null : (int) $cpuMax,
                'p95_m' => $cpuP95 === null ? null : (int) $cpuP95,
                'basis' => ($this->config['cpu_basis'] ?? 'p95') === 'max' ? 'max' : 'p95',
                'margin' => (float) ($this->config['cpu_margin'] ?? 1.3),
            ],
            'oom_kills' => 0,
            'throttled_ratio' => 0.0,
        ];
    }

    /** @return list<int> */
    private function steps(string $key, array $default): array
    {
        $steps = array_values(array_unique(array_map('intval', (array) ($this->config[$key] ?? $default))));
        $steps = array_values(array_filter($steps, fn (int $s) => $s > 0));
        sort($steps);

        return $steps === [] ? $default : $steps;
    }
}
