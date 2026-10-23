<?php

namespace App\Http\Controllers\Api\ApiProduct;

use App\Http\Controllers\Api\Controller;
use App\Models\Gateway;
use App\Services\ApiProduct\ApiProductAccess;
use App\Services\ApiProduct\ApiUsageReport;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * API usage (docs/api-product.md#usage): requests let through and refused (401 / 403 / rate
 * limit / quota) per hour, day or month, or per consumer, key or route — what an invoicing system
 * bills from. Hours are UTC; the current hour grows until it ends.
 *
 * @tags API product
 */
class ApiUsageController extends Controller
{
    /** The longest range of one report. */
    private const MAX_DAYS = 400;

    /** The API document's fields of a usage row and the totals (ApiUsageReport::present()): the gateway's decisions and the functions' answers */
    private const USAGE_FIELDS = 'requests: int, unauthorized: int, forbidden: int, rate_limited: int, quota_exceeded: int, responses: int, status_2xx: int, status_3xx: int, status_4xx: int, status_5xx: int, upstream_errors: int, errors: int, latency_avg_ms: float|null, latency_p50_ms: float|null, latency_p95_ms: float|null, latency_p99_ms: float|null, bytes_in: int, bytes_out: int';

    public function __construct(
        private readonly ApiUsageReport $report,
        private readonly ApiProductAccess $access,
    ) {}

    /**
     * Without gateway_id: every gateway the caller sees — every gateway with api_products.manage
     * or billing.view (the invoicing system's token).
     */
    #[OpenApiResponse(200, 'Totals of the range and its rows: per period (hour, day, month — UTC) or per consumer, key or route', type: 'array{from: string, to: string, group_by: "hour"|"day"|"month"|"consumer"|"key"|"route", totals: array{'.self::USAGE_FIELDS.'}, data: list<array{period: string, '.self::USAGE_FIELDS.'}|array{consumer_id: string|null, consumer: array{id: string, name: string, external_id: string|null}|null, subject: string|null, '.self::USAGE_FIELDS.'}|array{key_id: string|null, key: array{id: string, name: string, prefix: string}|null, consumer_id: string|null, '.self::USAGE_FIELDS.'}|array{route_id: string|null, path: string|null, '.self::USAGE_FIELDS.'}>}')]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'gateway_id' => 'nullable|string|size:26',
            'consumer_id' => 'nullable|string|size:26',
            'key_id' => 'nullable|string|size:26',
            'route_id' => 'nullable|string|size:26',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'group_by' => 'nullable|string|in:'.implode(',', ApiUsageReport::GROUPS),
        ]);

        $to = isset($data['to']) ? Carbon::parse($data['to'])->utc() : now()->utc()->addHour()->startOfHour();
        $from = isset($data['from']) ? Carbon::parse($data['from'])->utc() : $to->copy()->subDays(7);
        if ($from->greaterThanOrEqualTo($to) || $from->diffInDays($to) > self::MAX_DAYS) {
            return response()->json(['message' => __('api_product.usage.range', ['days' => self::MAX_DAYS])], 422);
        }

        $all = $this->access->readsAllUsage($request->user());
        if (! empty($data['gateway_id'])) {
            $gateway = Gateway::query()->findOrFail($data['gateway_id']);
            if (! $all) {
                Gate::authorize('view', $gateway);
            }
            $gatewayIds = [$gateway->id];
        } else {
            $gatewayIds = $all ? null : $this->access->visibleGatewayIds($request->user());
        }

        $groupBy = $data['group_by'] ?? 'day';
        $result = $this->report->report($this->report->query($gatewayIds, $from, $to, $data), $groupBy);

        return response()->json([
            'from' => $from->toIso8601ZuluString(),
            'to' => $to->toIso8601ZuluString(),
            'group_by' => $groupBy,
            'totals' => $result['totals'],
            'data' => $result['data'],
        ]);
    }
}
