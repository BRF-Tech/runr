<?php

namespace App\Services\ApiProduct;

use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiUsageHour;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Reads api_usage_hours (docs/api-product.md#usage): totals over a time range grouped by time
 * (hour, day, month — UTC) or by consumer, key or route, and the per-consumer totals of a closed
 * period the usage events carry (periodTotals). Every row and total has the gateway's decisions
 * (requests let through, refusals) and what the functions answered to the calls let through
 * (ApiResponseMetrics::FIELDS: status classes, errors, latency average and percentiles, bytes) —
 * the columns are summed, the percentiles read from the summed histogram.
 */
class ApiUsageReport
{
    public const GROUPS = ['hour', 'day', 'month', 'consumer', 'key', 'route'];

    /**
     * @param  list<string>|null  $gatewayIds  null: every gateway
     * @param  array{gateway_id?: string|null, consumer_id?: string|null, key_id?: string|null, route_id?: string|null}  $filters
     * @return Builder<ApiUsageHour>
     */
    public function query(?array $gatewayIds, Carbon $from, Carbon $to, array $filters = []): Builder
    {
        $query = ApiUsageHour::query()->where('hour', '>=', $from)->where('hour', '<', $to);

        if ($gatewayIds !== null) {
            $query->whereIn('gateway_id', $gatewayIds);
        }
        foreach (['gateway_id' => 'gateway_id', 'consumer_id' => 'consumer_id', 'key_id' => 'api_key_id', 'route_id' => 'route_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query;
    }

    /**
     * @param  Builder<ApiUsageHour>  $query
     * @return array{totals: array<string, int|float|null>, data: list<array<string, mixed>>}
     */
    public function report(Builder $query, string $groupBy): array
    {
        $totals = $this->present($this->raw((clone $query)->toBase()->selectRaw($this->sumColumns())->first()));

        $data = match ($groupBy) {
            'consumer' => $this->byConsumer($query),
            'key' => $this->byKey($query),
            'route' => $this->byRoute($query),
            default => $this->byTime($query, $groupBy),
        };

        return ['totals' => $totals, 'data' => $data];
    }

    /**
     * Totals of one gateway's period per consumer (and per route inside), for the usage events.
     *
     * @return array{consumers: list<array<string, mixed>>, totals: array<string, int|float|null>}
     */
    public function periodTotals(Gateway $gateway, Carbon $from, Carbon $to): array
    {
        $query = $this->query([$gateway->id], $from, $to);
        $rows = (clone $query)->toBase()
            ->select(['consumer_id', 'subject', 'route_id'])
            ->selectRaw($this->sumColumns())
            ->groupBy('consumer_id', 'subject', 'route_id')
            ->get();

        $consumers = ApiConsumer::query()->whereIn('id', $rows->pluck('consumer_id')->filter()->unique()->all())->get()->keyBy('id');
        $paths = GatewayRoute::query()->whereIn('id', $rows->pluck('route_id')->filter()->unique()->all())->pluck('path', 'id');

        $out = [];
        $raw = [];
        $all = [];
        foreach ($rows as $row) {
            $key = ($row->consumer_id ?? '-').'|'.($row->subject ?? '-');
            $sums = $this->raw($row);
            if (! isset($out[$key])) {
                $consumer = $row->consumer_id ? $consumers->get($row->consumer_id) : null;
                $out[$key] = [
                    'consumer' => $row->consumer_id ? [
                        'id' => $row->consumer_id,
                        'name' => $consumer?->name,
                        'external_id' => $consumer?->external_id,
                        'plan_id' => $consumer?->plan_id,
                    ] : null,
                    'subject' => $row->subject,
                    'routes' => [],
                ];
            }
            $raw[$key] = $this->add($raw[$key] ?? [], $sums);
            $all = $this->add($all, $sums);
            $out[$key]['routes'][] = ['route_id' => $row->route_id, 'path' => $row->route_id ? ($paths[$row->route_id] ?? null) : null] + $this->present($sums);
        }

        foreach ($out as $key => $entry) {
            $routes = $entry['routes'];
            unset($entry['routes']);
            $out[$key] = $entry + $this->present($raw[$key]) + ['routes' => $routes];
        }

        return ['consumers' => array_values($out), 'totals' => $this->present($all)];
    }

    private function sumColumns(): string
    {
        return implode(', ', array_map(fn ($c) => "COALESCE(SUM({$c}), 0) as {$c}", ApiUsageHour::additive()));
    }

    /**
     * Every summed column of a result row.
     *
     * @return array<string, int>
     */
    private function raw(mixed $row): array
    {
        $out = [];
        foreach (ApiUsageHour::additive() as $column) {
            $out[$column] = (int) ($row->{$column} ?? 0);
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $into
     * @param  array<string, int>  $sums
     * @return array<string, int>
     */
    private function add(array $into, array $sums): array
    {
        foreach ($sums as $column => $n) {
            $into[$column] = ($into[$column] ?? 0) + $n;
        }

        return $into;
    }

    /**
     * What a report shows of summed columns: the decisions and the answers.
     *
     * @param  array<string, int>  $raw
     * @return array<string, int|float|null>
     */
    private function present(array $raw): array
    {
        $out = [];
        foreach (ApiUsageHour::COUNTERS as $counter) {
            $out[$counter] = (int) ($raw[$counter] ?? 0);
        }

        return $out + ApiResponseMetrics::present($raw);
    }

    /** @return list<array<string, mixed>> */
    private function byTime(Builder $query, string $unit): array
    {
        $rows = (clone $query)->toBase()->select('hour')->selectRaw($this->sumColumns())->groupBy('hour')->orderBy('hour')->get();

        /** @var array<string, array<string, int>> $raw */
        $raw = [];
        foreach ($rows as $row) {
            $hour = Carbon::parse($row->hour)->utc();
            $period = match ($unit) {
                'month' => $hour->format('Y-m'),
                'day' => $hour->format('Y-m-d'),
                default => $hour->format('Y-m-d\TH:00:00\Z'),
            };
            $raw[$period] = $this->add($raw[$period] ?? [], $this->raw($row));
        }

        $out = [];
        foreach ($raw as $period => $sums) {
            $out[] = ['period' => $period] + $this->present($sums);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function byConsumer(Builder $query): array
    {
        $rows = (clone $query)->toBase()->select(['consumer_id', 'subject'])->selectRaw($this->sumColumns())
            ->groupBy('consumer_id', 'subject')->orderByDesc('requests')->get();
        $consumers = ApiConsumer::query()->whereIn('id', $rows->pluck('consumer_id')->filter()->unique()->all())->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'consumer_id' => $row->consumer_id,
            'consumer' => $row->consumer_id && ($c = $consumers->get($row->consumer_id))
                ? ['id' => $c->id, 'name' => $c->name, 'external_id' => $c->external_id] : null,
            'subject' => $row->subject,
        ] + $this->present($this->raw($row)))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function byKey(Builder $query): array
    {
        $rows = (clone $query)->toBase()->select(['api_key_id', 'consumer_id'])->selectRaw($this->sumColumns())
            ->groupBy('api_key_id', 'consumer_id')->orderByDesc('requests')->get();
        $keys = ApiKey::query()->whereIn('id', $rows->pluck('api_key_id')->filter()->unique()->all())->get()->keyBy('id');

        return $rows->map(fn ($row) => [
            'key_id' => $row->api_key_id,
            'key' => $row->api_key_id && ($k = $keys->get($row->api_key_id))
                ? ['id' => $k->id, 'name' => $k->name, 'prefix' => $k->prefix] : null,
            'consumer_id' => $row->consumer_id,
        ] + $this->present($this->raw($row)))->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function byRoute(Builder $query): array
    {
        $rows = (clone $query)->toBase()->select('route_id')->selectRaw($this->sumColumns())
            ->groupBy('route_id')->orderByDesc('requests')->get();
        $paths = GatewayRoute::query()->whereIn('id', $rows->pluck('route_id')->filter()->unique()->all())->pluck('path', 'id');

        return $rows->map(fn ($row) => [
            'route_id' => $row->route_id,
            'path' => $row->route_id ? ($paths[$row->route_id] ?? null) : null,
        ] + $this->present($this->raw($row)))->values()->all();
    }

    /**
     * The developer page's view of the gateway's routes (docs/api-product.md#developer-page): per
     * route path its success rate and latency percentiles over the last $days days — no counts, no
     * consumers. Routes without a measured answer are left out.
     *
     * @return array<string, array{success_rate: float, latency_p50_ms: float|null, latency_p95_ms: float|null, latency_p99_ms: float|null}>
     */
    public function routeHealth(Gateway $gateway, int $days = 7): array
    {
        $to = now()->utc()->addHour()->startOfHour();
        $rows = $this->query([$gateway->id], $to->copy()->subDays($days), $to)->toBase()
            ->select('route_id')->selectRaw($this->sumColumns())->groupBy('route_id')->get();
        $paths = GatewayRoute::query()->where('gateway_id', $gateway->id)->pluck('path', 'id');

        $out = [];
        foreach ($rows as $row) {
            $path = $row->route_id ? ($paths[$row->route_id] ?? null) : null;
            $metrics = ApiResponseMetrics::present($this->raw($row));
            if ($path === null || $metrics['responses'] === 0) {
                continue;
            }
            $out[$path] = [
                'success_rate' => round(100 * ($metrics['responses'] - $metrics['errors']) / $metrics['responses'], 2),
                'latency_p50_ms' => $metrics['latency_p50_ms'],
                'latency_p95_ms' => $metrics['latency_p95_ms'],
                'latency_p99_ms' => $metrics['latency_p99_ms'],
            ];
        }

        return $out;
    }
}
