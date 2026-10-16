<?php

namespace Modules\Tenancy\Http\Controllers\Billing;

use App\Http\Controllers\Api\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Tenancy\Http\Controllers\Billing\Concerns\ResolvesBillingTenant;
use Modules\Tenancy\Models\BillingEvent;
use Modules\Tenancy\Services\Billing\BillingEvents;
use Modules\Tenancy\Services\Billing\BillingSettings;

/**
 * Billing API — billing events (docs/billing.md), the same payloads the billing webhook receives:
 *
 *  GET  /api/billing/events                     ?order=asc|desc &after= &before=<event id> &type= &tenant= &limit=
 *  POST /api/billing/events/{event}/redeliver   queue a delivery to the webhook again
 *  POST /api/billing/events/test                record and deliver a billing.test event
 */
class BillingEventController extends Controller
{
    use ResolvesBillingTenant;

    public function __construct(
        private readonly BillingEvents $events,
        private readonly BillingSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'order' => ['sometimes', Rule::in(['asc', 'desc'])],
            'after' => ['sometimes', 'string', 'max:26'],
            'before' => ['sometimes', 'string', 'max:26'],
            'type' => ['sometimes', Rule::in(BillingEvents::TYPES)],
            'tenant' => ['sometimes', 'string', 'max:64'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        // Event ids are ULIDs: their order is the order the events were recorded in. A page
        // continues with ?after=<next_after> oldest first, or ?before=<next_before> newest first.
        $desc = $request->query('order') === 'desc';
        $limit = (int) $request->query('limit', 100);
        $query = BillingEvent::query()->orderBy('id', $desc ? 'desc' : 'asc');

        if ($after = $request->query('after')) {
            $query->where('id', '>', (string) $after);
        }
        if ($before = $request->query('before')) {
            $query->where('id', '<', (string) $before);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($tenant = $request->query('tenant')) {
            $query->where('tenant_id', (string) $this->billingTenant((string) $tenant)->id);
        }

        $events = $query->limit($limit + 1)->get();
        $more = $events->count() > $limit;
        $events = $events->take($limit);

        return response()->json([
            'data' => $events->map(fn (BillingEvent $e) => $e->toApiArray())->values(),
            'meta' => [
                'next_after' => $more && ! $desc ? $events->last()?->id : null,
                'next_before' => $more && $desc ? $events->last()?->id : null,
                'webhook_configured' => $this->settings->webhookUrl() !== '',
            ],
        ]);
    }

    public function redeliver(string $event): JsonResponse
    {
        if ($this->settings->webhookUrl() === '') {
            return response()->json(['message' => __('api.billing.webhook_not_configured')], 409);
        }

        $model = $this->events->redeliver(BillingEvent::query()->findOrFail($event));

        return response()->json(['event' => $model->fresh()?->toApiArray()]);
    }

    public function test(): JsonResponse
    {
        if ($this->settings->webhookUrl() === '') {
            return response()->json(['message' => __('api.billing.webhook_not_configured')], 409);
        }

        $event = $this->events->record(BillingEvents::TEST, null, ['message' => 'Test event from RunR']);
        // Delivered right away (unless the queue already did) — the answer shows the delivery result
        $event = $event?->fresh();
        if ($event && $event->delivery_status === BillingEvent::PENDING) {
            $this->events->deliver($event);
        }

        return response()->json(['event' => $event?->fresh()?->toApiArray()]);
    }
}
