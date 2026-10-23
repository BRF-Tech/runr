<?php

namespace App\Models\ApiProduct;

use App\Services\ApiProduct\ApiResponseMetrics;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One hour of API usage (docs/api-product.md#usage) of a gateway, per route, consumer and key, as
 * the region's gateway-auth reports it: requests let through and requests refused (401 / 403 /
 * 429 rate limit / 429 quota), and what the function answered to the calls let through (status
 * classes, upstream errors, latency sum and histogram, bytes: ApiResponseMetrics). Callers of a
 * JWT route that are no consumer are counted under their subject. Rows are only ever incremented
 * (ApiUsageIngest).
 *
 * @property int $id
 * @property string $bucket
 * @property Carbon $hour
 * @property string $gateway_id
 * @property string|null $route_id
 * @property string|null $consumer_id
 * @property string|null $api_key_id
 * @property string|null $subject
 * @property int $requests
 * @property int $unauthorized
 * @property int $forbidden
 * @property int $rate_limited
 * @property int $quota_exceeded
 */
class ApiUsageHour extends Model
{
    protected $table = 'api_usage_hours';

    /** The gateway's decisions a report sums. */
    public const COUNTERS = ['requests', 'unauthorized', 'forbidden', 'rate_limited', 'quota_exceeded'];

    protected $fillable = ['bucket', 'hour', 'gateway_id', 'route_id', 'consumer_id', 'api_key_id', 'subject'];

    /** @return list<string> every column a report adds up: the decisions and the answers */
    public static function additive(): array
    {
        return [...self::COUNTERS, ...ApiResponseMetrics::columns()];
    }

    /** The row's keys and every counter. */
    public function getFillable(): array
    {
        return [...$this->fillable, ...self::additive()];
    }

    protected $casts = [
        'hour' => 'datetime',
        'requests' => 'integer',
        'unauthorized' => 'integer',
        'forbidden' => 'integer',
        'rate_limited' => 'integer',
        'quota_exceeded' => 'integer',
    ];

    /** The unique key of one hour of one gateway / route / consumer / key / subject. */
    public static function bucket(Carbon $hour, string $gatewayId, ?string $routeId, ?string $consumerId, ?string $keyId, ?string $subject): string
    {
        return implode('|', [
            $hour->copy()->utc()->format('YmdH'),
            $gatewayId,
            $routeId ?? '-',
            $consumerId ?? '-',
            $keyId ?? '-',
            $subject === null ? '-' : sha1($subject),
        ]);
    }
}
