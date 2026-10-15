<?php

namespace Modules\Tenancy\Http\Controllers\Billing;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Tenancy\Http\Controllers\Billing\Concerns\ResolvesBillingTenant;
use Modules\Tenancy\Services\Billing\ChargeLineItemBuilder;
use Modules\Tenancy\Services\Billing\UsageRating;

/**
 * Billing API — a tenant's rated usage in any period (docs/billing.md):
 *
 *  GET /api/billing/tenants/{tenant}/usage?period=YYYY-MM | ?from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Without a period: the current month so far. Line items per currency, priced with the cost
 * configuration at metering time — the same lines the tenant's usage charge closes with.
 */
class UsageController extends Controller
{
    use ResolvesBillingTenant;

    /** Longest range one request rates */
    private const MAX_DAYS = 366;

    public function __construct(private readonly UsageRating $rating) {}

    public function show(Request $request, string $tenant): JsonResponse
    {
        $model = $this->billingTenant($tenant);
        $request->validate([
            'from' => ['required_with:to', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        [$from, $to] = $this->periodRange($request)
            ?? ($request->query('from') ? [(string) $request->query('from'), (string) $request->query('to')] : [now()->startOfMonth()->toDateString(), now()->toDateString()]);

        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('api.billing.range_too_long', ['days' => self::MAX_DAYS])]);
        }

        $rated = $this->rating->rate((string) $model->id, $from, $to);
        $currencies = [];
        foreach ($rated as $currency => $items) {
            $currencies[$currency] = ['line_items' => $items, 'total' => ChargeLineItemBuilder::total($items)];
        }

        return response()->json([
            'tenant_id' => (string) $model->id,
            'from' => $from,
            'to' => $to,
            'sessions' => $this->rating->sessions((string) $model->id, $from, $to),
            'currencies' => (object) $currencies,
        ]);
    }
}
