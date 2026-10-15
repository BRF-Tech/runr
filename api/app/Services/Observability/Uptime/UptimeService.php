<?php

namespace App\Services\Observability\Uptime;

use App\Models\KnativeRegion;
use App\Models\Observability\UptimeCheck;
use App\Models\Observability\UptimeIncident;
use App\Models\Observability\UptimeResult;
use App\Services\Observability\Alerts\AlertEvaluator;
use App\Services\Observability\Alerts\AlertManager;
use App\Services\Observability\OutboundUrlGuard;
use App\Services\Observability\Replay\ReplayService;
use App\Services\Observability\TenantScope;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Synthetic HTTP checks (docs/observability.md#uptime-checks):
 *
 *  - runDue(): the control plane probes its due checks (every 30 s, in parallel)
 *  - agentChecks() / recordAgentResults(): region agents fetch their checks and report results
 *  - record(): the incident state machine per location — down after failure_threshold
 *    consecutive failures, up after recovery_threshold consecutive successes (the first success
 *    of an unknown check is up at once); the check is down while any location is down, up when
 *    every location is up. down opens an incident and fires the uptime_down rules, up resolves both.
 */
class UptimeService
{
    public function __construct(
        private ReplayService $replay,
        private OutboundUrlGuard $guard,
        private AlertManager $alerts,
    ) {}

    // ── Control plane runner ─────────────────────────────────────────────────────────────────

    public function runDue(): int
    {
        $ran = 0;
        TenantScope::each(function () use (&$ran) {
            $ran += $this->runDueInScope();
        });

        return $ran;
    }

    public function runDueInScope(?Carbon $now = null): int
    {
        $now ??= now();

        $checks = UptimeCheck::query()
            ->where('is_enabled', true)
            ->where('locations', UptimeCheck::LOCATION_CONTROL_PLANE)
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->with('project.knativeRegion', 'project.xaasConfig', 'project.faasConfig', 'project.containerConfig')
            ->orderBy('next_check_at')
            ->limit(500)
            ->get();

        $ran = 0;
        foreach ($checks->chunk(max(1, (int) config('observability.uptime.concurrency', 20))) as $chunk) {
            $targets = [];
            foreach ($chunk as $check) {
                $check->forceFill(['next_check_at' => $now->copy()->addSeconds(max(10, $check->interval_seconds))])->save();

                [$url, $error] = $this->resolveTarget($check);
                if ($url === null) {
                    $this->record($check, UptimeCheck::CONTROL_PLANE, false, null, null, $error, $now);
                    $ran++;

                    continue;
                }
                $targets[$check->id] = [$check, $url];
            }

            if (! $targets) {
                continue;
            }

            $started = microtime(true);
            $responses = Http::pool(function (Pool $pool) use ($targets) {
                $out = [];
                foreach ($targets as $id => [$check, $url]) {
                    $out[] = $pool->as($id)
                        ->timeout(max(1, $check->timeout_seconds))
                        ->connectTimeout(max(1, min(10, $check->timeout_seconds)))
                        ->withOptions(['allow_redirects' => false, 'http_errors' => false])
                        ->withUserAgent('runr-uptime/1 (control plane)')
                        ->withHeaders($check->headers ?? [])
                        ->send($check->method ?: 'GET', $url);
                }

                return $out;
            });
            $elapsedMs = (int) round((microtime(true) - $started) * 1000);

            foreach ($targets as $id => [$check]) {
                [$ok, $status, $error] = self::evaluate($check, $responses[$id] ?? null);
                $this->record($check, UptimeCheck::CONTROL_PLANE, $ok, $status, self::responseMs($responses[$id] ?? null, $elapsedMs), $error, $now);
                $ran++;
            }
        }

        return $ran;
    }

    /**
     * The URL a check probes: its absolute URL (SSRF-guarded) or the project's endpoint + path.
     *
     * @return array{0: string|null, 1: string|null} [url, error]
     */
    public function resolveTarget(UptimeCheck $check): array
    {
        if ($check->url) {
            $reason = $this->guard->check($check->url, (bool) config('observability.uptime.allow_private_targets', false));

            return $reason === null ? [$check->url, null] : [null, $reason];
        }

        $base = $check->project ? $this->replay->targetUrl($check->project) : null;
        if ($base === null) {
            return [null, __('observability.uptime.no_endpoint')];
        }

        return [$base.'/'.ltrim($check->path ?: '/', '/'), null];
    }

    /**
     * @return array{0: bool, 1: int|null, 2: string|null} [ok, status code, error]
     */
    public static function evaluate(UptimeCheck $check, mixed $response): array
    {
        if ($response instanceof \Throwable) {
            $message = $response->getMessage();
            if (str_contains($message, 'timed out') || str_contains($message, 'Operation timed out') || str_contains($message, 'cURL error 28')) {
                return [false, null, __('observability.uptime.timeout', ['seconds' => $check->timeout_seconds])];
            }

            return [false, null, mb_substr($message, 0, 300)];
        }
        if (! $response instanceof Response) {
            return [false, null, __('observability.uptime.no_response')];
        }

        $status = $response->status();
        if (! self::statusMatches((string) $check->expected_status, $status)) {
            return [false, $status, __('observability.uptime.unexpected_status', ['status' => $status, 'expected' => $check->expected_status ?: '200-399'])];
        }

        if ($check->body_regex) {
            $match = self::bodyMatches($check->body_regex, mb_substr($response->body(), 0, 1 << 20));
            if ($match === null) {
                return [false, $status, __('observability.uptime.invalid_regex')];
            }
            if (! $match) {
                return [false, $status, __('observability.uptime.body_mismatch')];
            }
        }

        return [true, $status, null];
    }

    private static function responseMs(mixed $response, int $fallback): ?int
    {
        if ($response instanceof Response) {
            $time = $response->transferStats?->getTransferTime();
            if ($time !== null) {
                return (int) round($time * 1000);
            }

            return $fallback;
        }

        return null;
    }

    /** "200", "200,204", "200-399", "2xx" (comma-separated); empty = 200-399. Same rules as the agent. */
    public static function statusMatches(string $expected, int $code): bool
    {
        $expected = trim($expected);
        if ($expected === '') {
            return $code >= 200 && $code < 400;
        }

        foreach (explode(',', strtolower($expected)) as $part) {
            $part = trim($part);
            if (preg_match('/^([1-5])xx$/', $part, $m)) {
                if (intdiv($code, 100) === (int) $m[1]) {
                    return true;
                }
            } elseif (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $part, $m)) {
                if ($code >= (int) $m[1] && $code <= (int) $m[2]) {
                    return true;
                }
            } elseif (preg_match('/^\d{3}$/', $part) && (int) $part === $code) {
                return true;
            }
        }

        return false;
    }

    public static function validExpectedStatus(string $expected): bool
    {
        foreach (explode(',', strtolower(trim($expected))) as $part) {
            if (! preg_match('/^\s*([1-5]xx|\d{3}(\s*-\s*\d{3})?)\s*$/', $part)) {
                return false;
            }
        }

        return true;
    }

    /** true / false, null for an invalid pattern. RE2-compatible patterns behave the same in the agent. */
    public static function bodyMatches(string $regex, string $body): ?bool
    {
        $pattern = '~'.str_replace('~', '\~', $regex).'~u';
        $result = @preg_match($pattern, $body);

        return $result === false ? null : $result === 1;
    }

    // ── State machine ────────────────────────────────────────────────────────────────────────

    /**
     * Record a probe result; returns the check's transition ('down' | 'up') or null.
     */
    public function record(UptimeCheck $check, string $location, bool $ok, ?int $status, ?int $responseMs, ?string $error, ?Carbon $at = null): ?string
    {
        $at ??= now();

        UptimeResult::query()->create([
            'uptime_check_id' => $check->id,
            'location' => mb_substr($location, 0, 64),
            'ok' => $ok,
            'status_code' => $status,
            'response_ms' => $responseMs,
            'error' => $error ? mb_substr($error, 0, 500) : null,
            'checked_at' => $at,
        ]);

        $states = $check->location_state ?? [];
        $state = $states[$location] ?? ['status' => UptimeCheck::STATUS_UNKNOWN, 'fails' => 0, 'successes' => 0];

        if ($ok) {
            $state['successes'] = ($state['successes'] ?? 0) + 1;
            $state['fails'] = 0;
            if ($state['status'] !== UptimeCheck::STATUS_UP
                && ($state['status'] === UptimeCheck::STATUS_UNKNOWN || $state['successes'] >= max(1, $check->recovery_threshold))) {
                $state['status'] = UptimeCheck::STATUS_UP;
            }
        } else {
            $state['fails'] = ($state['fails'] ?? 0) + 1;
            $state['successes'] = 0;
            if ($state['fails'] >= max(1, $check->failure_threshold)) {
                $state['status'] = UptimeCheck::STATUS_DOWN;
            }
        }
        $state['last_error'] = $ok ? null : $error;
        $state['last_at'] = $at->toIso8601String();
        $states[$location] = $state;

        $before = $check->status;
        $after = self::aggregate($states, $before);

        $check->forceFill([
            'location_state' => $states,
            'status' => $after,
            'last_checked_at' => $at,
            'last_response_ms' => $responseMs,
            'last_status_code' => $status,
            'last_error' => $ok ? null : $error,
        ])->save();

        if ($after === UptimeCheck::STATUS_DOWN && $before !== UptimeCheck::STATUS_DOWN) {
            $this->openIncident($check, $error, (int) ($state['fails'] ?? 1));

            return UptimeCheck::STATUS_DOWN;
        }
        if ($after === UptimeCheck::STATUS_UP && $before === UptimeCheck::STATUS_DOWN) {
            $this->resolveIncident($check);

            return UptimeCheck::STATUS_UP;
        }
        if ($after === UptimeCheck::STATUS_DOWN && ! $ok) {
            UptimeIncident::query()->where('uptime_check_id', $check->id)->where('status', UptimeIncident::OPEN)->increment('failed_checks');
        }

        return null;
    }

    /** Down while any location is down; up when every location is up; otherwise unchanged. */
    public static function aggregate(array $states, string $current): string
    {
        $statuses = array_column($states, 'status');
        if (in_array(UptimeCheck::STATUS_DOWN, $statuses, true)) {
            return UptimeCheck::STATUS_DOWN;
        }
        if ($statuses && count(array_filter($statuses, fn ($s) => $s === UptimeCheck::STATUS_UP)) === count($statuses)) {
            return UptimeCheck::STATUS_UP;
        }

        return $current === UptimeCheck::STATUS_DOWN ? UptimeCheck::STATUS_DOWN : UptimeCheck::STATUS_UNKNOWN;
    }

    private function openIncident(UptimeCheck $check, ?string $cause, int $fails): void
    {
        $project = $check->project;

        $incident = UptimeIncident::query()->create([
            'uptime_check_id' => $check->id,
            'project_id' => $check->project_id,
            'status' => UptimeIncident::OPEN,
            'title' => mb_substr(__('observability.uptime.incident_title', ['name' => $check->publicName()]), 0, 200),
            'message' => __('observability.uptime.incident_message'),
            'cause' => $cause,
            'started_at' => now(),
            'failed_checks' => $fails,
        ]);

        foreach ($this->alerts->rulesFor('uptime_down', $check->project_id) as $rule) {
            $this->alerts->fire(
                $rule,
                $check->id,
                __('observability.uptime.alert_title', ['name' => $check->name, 'project' => $project?->name ?? '']),
                (string) ($cause ?: __('observability.uptime.incident_message')),
                [
                    'project' => $project?->name,
                    'check' => $check->name,
                    'incident' => $incident->id,
                    'url' => $project ? AlertEvaluator::projectUrl($project, 'uptime') : null,
                ],
                $check->project_id,
            );
        }
    }

    private function resolveIncident(UptimeCheck $check): void
    {
        $incident = UptimeIncident::query()->where('uptime_check_id', $check->id)->where('status', UptimeIncident::OPEN)->latest('started_at')->first();
        if ($incident) {
            $incident->forceFill([
                'status' => UptimeIncident::RESOLVED,
                'resolved_at' => now(),
                'message' => $incident->message === __('observability.uptime.incident_message') ? __('observability.uptime.incident_resolved') : $incident->message,
            ])->save();
        }

        foreach ($this->alerts->rulesFor('uptime_down', $check->project_id) as $rule) {
            $this->alerts->resolve($rule, $check->id, __('observability.uptime.alert_resolved', ['name' => $check->name]));
        }
    }

    // ── Region agents ────────────────────────────────────────────────────────────────────────

    /**
     * Checks the region's agent runs: checks with locations = regions whose region list holds the
     * region (no list: the project's own region), from every scope.
     *
     * @return list<array>
     */
    public function agentChecks(KnativeRegion $region): array
    {
        $out = [];
        TenantScope::each(function (?string $tenantId) use ($region, &$out) {
            $checks = UptimeCheck::query()
                ->where('is_enabled', true)
                ->where('locations', UptimeCheck::LOCATION_REGIONS)
                ->with('project.knativeRegion', 'project.xaasConfig', 'project.faasConfig', 'project.containerConfig')
                ->get();

            foreach ($checks as $check) {
                if (! self::runsIn($check, $region)) {
                    continue;
                }
                [$url] = $this->resolveTarget($check);
                if ($url === null) {
                    continue;
                }
                $out[] = [
                    'key' => ($tenantId ?? '_').':'.$check->id,
                    'url' => $url,
                    'method' => $check->method ?: 'GET',
                    'headers' => (object) ($check->headers ?? []),
                    'interval_seconds' => $check->interval_seconds,
                    'timeout_seconds' => $check->timeout_seconds,
                    'expected_status' => (string) $check->expected_status,
                    'body_regex' => (string) ($check->body_regex ?? ''),
                ];
            }
        });

        return $out;
    }

    public static function runsIn(UptimeCheck $check, KnativeRegion $region): bool
    {
        $ids = $check->region_ids;
        if (is_array($ids) && $ids !== []) {
            return in_array($region->getKey(), $ids, true);
        }

        return $check->project?->knative_region_id === $region->getKey();
    }

    /**
     * Results posted by a region's agent; a result for a check not assigned to the region is ignored.
     */
    public function recordAgentResults(KnativeRegion $region, array $results): int
    {
        $byScope = [];
        foreach ($results as $r) {
            if (! is_array($r) || ! isset($r['key']) || ! is_string($r['key']) || ! str_contains($r['key'], ':')) {
                continue;
            }
            [$scope, $checkId] = explode(':', $r['key'], 2);
            $byScope[$scope][] = $r + ['check_id' => $checkId];
        }

        $recorded = 0;
        foreach ($byScope as $scope => $items) {
            TenantScope::run($scope === '_' ? null : $scope, function () use ($items, $region, &$recorded) {
                foreach ($items as $r) {
                    $check = UptimeCheck::query()->with('project')->find($r['check_id']);
                    if (! $check || $check->locations !== UptimeCheck::LOCATION_REGIONS || ! self::runsIn($check, $region)) {
                        continue;
                    }
                    try {
                        $at = isset($r['checked_at']) ? Carbon::parse((string) $r['checked_at']) : now();
                    } catch (\Throwable) {
                        $at = now();
                    }
                    if ($at->isFuture()) {
                        $at = now();
                    }
                    $this->record(
                        $check,
                        $region->name,
                        (bool) ($r['ok'] ?? false),
                        isset($r['status_code']) && (int) $r['status_code'] > 0 ? (int) $r['status_code'] : null,
                        isset($r['response_ms']) ? max(0, (int) $r['response_ms']) : null,
                        isset($r['error']) ? mb_substr((string) $r['error'], 0, 500) : null,
                        $at,
                    );
                    $recorded++;
                }
            });
        }

        return $recorded;
    }

    // ── Statistics ───────────────────────────────────────────────────────────────────────────

    /** Uptime % over the last $hours (null without results). */
    public static function uptimePercent(UptimeCheck $check, int $hours): ?float
    {
        $row = UptimeResult::query()
            ->where('uptime_check_id', $check->id)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN ok THEN 1 ELSE 0 END) as up')
            ->first();

        $total = (int) ($row->total ?? 0);

        return $total > 0 ? round(100 * (int) $row->up / $total, 3) : null;
    }

    /**
     * Daily uptime of the last $days days, oldest first: [{date, total, up, uptime}].
     *
     * @return list<array{date: string, total: int, up: int, uptime: float|null}>
     */
    public static function dailyBars(UptimeCheck $check, int $days): array
    {
        $from = now()->startOfDay()->subDays($days - 1);

        $rows = UptimeResult::query()
            ->where('uptime_check_id', $check->id)
            ->where('checked_at', '>=', $from)
            ->get(['ok', 'checked_at'])
            ->groupBy(fn (UptimeResult $r) => $r->checked_at->toDateString());

        $out = [];
        for ($d = 0; $d < $days; $d++) {
            $date = $from->copy()->addDays($d)->toDateString();
            $group = $rows->get($date);
            $total = $group ? $group->count() : 0;
            $up = $group ? $group->where('ok', true)->count() : 0;
            $out[] = ['date' => $date, 'total' => $total, 'up' => $up, 'uptime' => $total > 0 ? round(100 * $up / $total, 2) : null];
        }

        return $out;
    }

    /** Average response time of successful probes over the last $hours. */
    public static function averageResponseMs(UptimeCheck $check, int $hours): ?int
    {
        $avg = UptimeResult::query()
            ->where('uptime_check_id', $check->id)
            ->where('ok', true)
            ->where('checked_at', '>=', now()->subHours($hours))
            ->avg('response_ms');

        return $avg === null ? null : (int) round((float) $avg);
    }

    /** Results and resolved incidents older than the retention. */
    public function prune(): int
    {
        $days = max(1, (int) config('observability.uptime.results_retention_days', 90));
        $deleted = 0;
        TenantScope::each(function () use ($days, &$deleted) {
            $deleted += UptimeResult::query()->where('checked_at', '<', now()->subDays($days))->delete();
            $deleted += UptimeIncident::query()->where('status', UptimeIncident::RESOLVED)->where('resolved_at', '<', now()->subDays($days))->delete();
        });

        return $deleted;
    }
}
