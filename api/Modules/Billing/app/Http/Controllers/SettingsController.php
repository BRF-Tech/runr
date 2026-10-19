<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Billing\Services\BillingEvents;
use Modules\Billing\Services\BillingSettings;
use Modules\Billing\Services\PriceBook;
use Modules\Billing\Support\BillingContext;

/**
 * Billing settings of the organization (docs/billing.md#webhook):
 *
 *  GET /api/billing/settings   currency, organization, webhook (URL, where it comes from, secret set)
 *  PUT /api/billing/settings   {webhook_url?, webhook_secret?, clear_secrets?: ["webhook_secret"]}
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly BillingSettings $settings,
        private readonly PriceBook $prices,
        private readonly BillingEvents $events,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(BillingSettings::rules());

        // No webhook to RunR's own network (OutboundUrlGuard; checked again before every delivery)
        $url = trim((string) ($data['webhook_url'] ?? ''));
        if ($url !== '' && ($problem = $this->events->urlProblem($url)) !== null) {
            throw ValidationException::withMessages(['webhook_url' => [$problem]]);
        }

        $this->settings->update($data);

        return response()->json($this->payload());
    }

    private function payload(): array
    {
        return $this->settings->payload(BillingContext::inTenant() ? round($this->prices->defaultMarkupPercent(), 3) : null);
    }
}
