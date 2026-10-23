<?php

namespace App\Services\ApiProduct;

use App\Events\ApiProduct\ApiQuotaExceeded;
use App\Models\ApiProduct\ApiConsumer;
use App\Models\ApiProduct\ApiKey;
use App\Models\ApiProduct\ApiUsageHour;
use App\Models\ApiProduct\GatewayAuthScope;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\KnativeRegion;
use App\Services\Observability\TenantScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Usage reports of a region's gateway-auth (POST /api/internal/gateway-auth/{region}/usage): the
 * calls it counted since its last report, per hour, gateway, route, consumer and key — its
 * decisions and what the functions answered (ApiResponseMetrics) — added to api_usage_hours in the
 * schema of each entry's organization. An entry for a gateway outside the
 * region, a route of another gateway or an hour too far off is dropped.
 *
 * A report is applied once per organization: the batch id of the report is remembered per scope
 * after that scope's rows are written, so a report sent again (its answer lost) adds nothing twice.
 * A refused call because of a quota fires ApiQuotaExceeded (the Billing module records
 * api.usage.quota_exceeded, once per consumer and day).
 */
class ApiUsageIngest
{
    public const MAX_ENTRIES = 5000;

    private const MAX_COUNT = 1_000_000_000;

    /** Bytes and latency sums of one entry */
    private const MAX_TOTAL = 1_000_000_000_000_000;

    /**
     * @param  array<int, mixed>  $entries  as the region sent them (checked here)
     * @return int entries recorded
     */
    public function ingest(KnativeRegion $region, string $batch, array $entries): int
    {
        $byScope = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['scope'] ?? null) && is_string($entry['gateway_id'] ?? null)) {
                $byScope[$entry['scope']][] = $entry;
            }
        }

        $recorded = 0;
        foreach ($byScope as $scope => $items) {
            $marker = 'gateway-auth:batch:'.$region->id.':'.$scope.':'.$batch;
            if (Cache::has($marker)) {
                continue;
            }

            $count = TenantScope::run($scope === GatewayAuthScope::CENTRAL ? null : (string) $scope, fn () => $this->record($region, $items));
            Cache::put($marker, 1, now()->addDay());
            $recorded += (int) $count;
        }

        return $recorded;
    }

    /** @param  list<array<string, mixed>>  $items */
    private function record(KnativeRegion $region, array $items): int
    {
        $gatewayIds = Gateway::query()
            ->where('knative_region_id', $region->id)
            ->whereIn('id', array_values(array_unique(array_column($items, 'gateway_id'))))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($gatewayIds === []) {
            return 0;
        }
        $routeGateway = GatewayRoute::query()->whereIn('gateway_id', $gatewayIds)->pluck('gateway_id', 'id')
            ->map(fn ($id) => (string) $id)->all();

        $now = now();
        $recorded = 0;
        $usedKeys = [];
        $quota = [];

        DB::transaction(function () use ($items, $gatewayIds, $routeGateway, $now, &$recorded, &$usedKeys, &$quota) {
            foreach ($items as $item) {
                $gatewayId = (string) $item['gateway_id'];
                $routeId = self::id($item['route_id'] ?? null);
                if (! in_array($gatewayId, $gatewayIds, true) || ($routeId !== null && ($routeGateway[$routeId] ?? null) !== $gatewayId)) {
                    continue;
                }

                try {
                    $hour = Carbon::parse((string) ($item['hour'] ?? ''))->utc()->startOfHour();
                } catch (\Throwable) {
                    continue;
                }
                if ($hour->greaterThan($now->copy()->addHour()) || $hour->lessThan($now->copy()->subDays(35))) {
                    continue;
                }

                $counts = self::counts($item);
                if (array_sum($counts) === 0) {
                    continue;
                }

                $consumerId = self::id($item['consumer_id'] ?? null);
                $keyId = self::id($item['key_id'] ?? null);
                $subject = isset($item['subject']) && is_string($item['subject']) && $item['subject'] !== ''
                    ? mb_substr($item['subject'], 0, 191) : null;

                $this->add($hour, $gatewayId, $routeId, $consumerId, $keyId, $subject, $counts);
                $recorded++;

                if ($keyId !== null && $counts['requests'] > 0) {
                    $usedKeys[$keyId] = true;
                }
                if ($consumerId !== null && $counts['quota_exceeded'] > 0) {
                    $quota[] = ['gateway_id' => $gatewayId, 'route_id' => $routeId, 'consumer_id' => $consumerId, 'key_id' => $keyId, 'hour' => $hour, 'count' => $counts['quota_exceeded']];
                }
            }
        });

        if ($usedKeys !== []) {
            // query builder: no model events, the gateway-auth revision does not move
            ApiKey::query()->whereIn('id', array_keys($usedKeys))->toBase()->update(['last_used_at' => $now]);
        }

        foreach ($quota as $q) {
            $consumer = ApiConsumer::query()->with('plan')->find($q['consumer_id']);
            $gateway = Gateway::query()->find($q['gateway_id']);
            if ($consumer && $gateway) {
                ApiQuotaExceeded::dispatch($gateway, $consumer, $q['key_id'], $q['route_id'], $q['hour'], $q['count']);
            }
        }

        return $recorded;
    }

    /** @param  array<string, int>  $counts */
    private function add(Carbon $hour, string $gatewayId, ?string $routeId, ?string $consumerId, ?string $keyId, ?string $subject, array $counts): void
    {
        $bucket = ApiUsageHour::bucket($hour, $gatewayId, $routeId, $consumerId, $keyId, $subject);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $row = ApiUsageHour::query()->where('bucket', $bucket)->lockForUpdate()->first();
            if ($row) {
                foreach ($counts as $counter => $n) {
                    $row->{$counter} = (int) $row->{$counter} + $n;
                }
                $row->save();

                return;
            }

            try {
                // a savepoint: a row another report created meanwhile does not abort the transaction
                DB::transaction(fn () => ApiUsageHour::query()->create([
                    'bucket' => $bucket,
                    'hour' => $hour,
                    'gateway_id' => $gatewayId,
                    'route_id' => $routeId,
                    'consumer_id' => $consumerId,
                    'api_key_id' => $keyId,
                    'subject' => $subject,
                ] + $counts));

                return;
            } catch (QueryException) {
                // unique bucket taken by a concurrent report: add to it
            }
        }
    }

    /**
     * The counters of one entry: the gateway's decisions, and what the function answered (the
     * metering proxy) — its latency histogram as latency_buckets, one count per bucket of
     * ApiResponseMetrics::BOUNDS_MS (an entry with another number of buckets brings none).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, int>
     */
    private static function counts(array $item): array
    {
        $counts = [];
        foreach (ApiUsageHour::COUNTERS as $counter) {
            $counts[$counter] = max(0, min(self::MAX_COUNT, (int) ($item[$counter] ?? 0)));
        }
        foreach (ApiResponseMetrics::COUNTERS as $counter) {
            // bytes and the latency sum (µs) grow faster than call counts
            $max = in_array($counter, ['bytes_in', 'bytes_out', 'latency_us_sum'], true) ? self::MAX_TOTAL : self::MAX_COUNT;
            $counts[$counter] = max(0, min($max, (int) ($item[$counter] ?? 0)));
        }

        $buckets = $item['latency_buckets'] ?? null;
        $columns = ApiResponseMetrics::bucketColumns();
        $valid = is_array($buckets) && array_is_list($buckets) && count($buckets) === count($columns);
        foreach ($columns as $i => $column) {
            $counts[$column] = $valid ? max(0, min(self::MAX_COUNT, (int) $buckets[$i])) : 0;
        }
        if (! $valid) {
            // no histogram, no latency sum: the averages stay true to the answers measured
            $counts['latency_us_sum'] = 0;
        }

        return $counts;
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) ? $value : null;
    }
}
